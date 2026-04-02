
<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_committee'])) {
        // Create new election committee
        $academic_year = $_POST['academic_year'];
        $committee_name = $_POST['committee_name'];
        $description = $_POST['description'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO election_committees 
                (academic_year, committee_name, description, status, created_by)
                VALUES (?, ?, ?, 'forming', ?)
            ");
            $stmt->execute([$academic_year, $committee_name, $description, $user_id]);
            
            $committee_id = $pdo->lastInsertId();
            $_SESSION['success_message'] = "Election committee created successfully!";
            header('Location: election_committee.php?action=manage&id=' . $committee_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error creating committee: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_member'])) {
        // Add member to committee
        $committee_id = $_POST['committee_id'];
        $user_id_to_add = $_POST['user_id'];
        $role = $_POST['role'];
        
        try {
            // Check if user is already in committee
            $stmt = $pdo->prepare("
                SELECT id FROM election_committee_members 
                WHERE election_committee_id = ? AND user_id = ?
            ");
            $stmt->execute([$committee_id, $user_id_to_add]);
            
            if ($stmt->fetch()) {
                $_SESSION['error_message'] = "User is already a member of this committee!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO election_committee_members 
                    (election_committee_id, user_id, role, assigned_date, status)
                    VALUES (?, ?, ?, CURDATE(), 'active')
                ");
                $stmt->execute([$committee_id, $user_id_to_add, $role]);
                
                $_SESSION['success_message'] = "Committee member added successfully!";
            }
            
            header('Location: election_committee.php?action=manage&id=' . $committee_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding committee member: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_member_role'])) {
        // Update member role
        $member_id = $_POST['member_id'];
        $role = $_POST['role'];
        $committee_id = $_POST['committee_id'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE election_committee_members 
                SET role = ? 
                WHERE id = ?
            ");
            $stmt->execute([$role, $member_id]);
            
            $_SESSION['success_message'] = "Member role updated successfully!";
            header('Location: election_committee.php?action=manage&id=' . $committee_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating member role: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_committee_status'])) {
        // Update committee status
        $committee_id = $_POST['committee_id'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE election_committees SET status = ? WHERE id = ?");
            $stmt->execute([$status, $committee_id]);
            
            $_SESSION['success_message'] = "Committee status updated to " . ucfirst($status) . "!";
            header('Location: election_committee.php?action=manage&id=' . $committee_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating committee status: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['remove_member'])) {
        // Remove member from committee
        $member_id = $_POST['member_id'];
        $committee_id = $_POST['committee_id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM election_committee_members WHERE id = ?");
            $stmt->execute([$member_id]);
            
            $_SESSION['success_message'] = "Committee member removed successfully!";
            header('Location: election_committee.php?action=manage&id=' . $committee_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error removing committee member: " . $e->getMessage();
        }
    }
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$committee_id = $_GET['id'] ?? 0;

// Get committees list
try {
    $stmt = $pdo->query("
        SELECT ec.*, 
               COUNT(ecm.id) as member_count,
               u.full_name as created_by_name
        FROM election_committees ec
        LEFT JOIN election_committee_members ecm ON ec.id = ecm.election_committee_id AND ecm.status = 'active'
        LEFT JOIN users u ON ec.created_by = u.id
        GROUP BY ec.id
        ORDER BY ec.created_at DESC
    ");
    $committees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committees = [];
}

// Get specific committee data if managing
$committee = null;
$committee_members = [];
$available_users = [];

if ($committee_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT ec.*, u.full_name as created_by_name 
            FROM election_committees ec 
            LEFT JOIN users u ON ec.created_by = u.id 
            WHERE ec.id = ?
        ");
        $stmt->execute([$committee_id]);
        $committee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get committee members
        $stmt = $pdo->prepare("
            SELECT ecm.*, u.full_name, u.role as user_role, u.email, u.phone
            FROM election_committee_members ecm 
            JOIN users u ON ecm.user_id = u.id 
            WHERE ecm.election_committee_id = ? 
            ORDER BY 
                CASE ecm.role 
                    WHEN 'chairperson' THEN 1
                    WHEN 'secretary' THEN 2
                    WHEN 'member' THEN 3
                    ELSE 4
                END,
                u.full_name
        ");
        $stmt->execute([$committee_id]);
        $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error fetching committee data: " . $e->getMessage());
    }
}

// Get available users for adding to committee (exclude current members)
try {
    $exclude_users = array_column($committee_members, 'user_id');
    $exclude_condition = $exclude_users ? "AND u.id NOT IN (" . implode(',', $exclude_users) . ")" : "";
    
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.email, u.reg_number
        FROM users u 
        WHERE u.status = 'active' 
        AND u.role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        $exclude_condition
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $available_users = [];
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM election_committees");
    $total_committees = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM election_committees WHERE status = 'active'");
    $active_committees = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_members FROM election_committee_members WHERE status = 'active'");
    $total_members = $stmt->fetch(PDO::FETCH_ASSOC)['total_members'];
    
    $stmt = $pdo->query("
        SELECT ec.academic_year, COUNT(*) as committee_count 
        FROM election_committees ec 
        GROUP BY ec.academic_year 
        ORDER BY ec.academic_year DESC
    ");
    $committees_by_year = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $total_committees = $active_committees = $total_members = 0;
    $committees_by_year = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Committees - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
            height: 80px;
            display: flex;
            align-items: center;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--primary-blue);
            transform: scale(1.05);
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1.1rem;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar */
        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 60px;
            height: calc(100vh - 60px);
            overflow-y: auto;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item {
            margin-bottom: 0.25rem;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--primary-blue);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card .stat-icon {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-forming {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .role-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .role-chairperson {
            background: gold;
            color: black;
        }

        .role-secretary {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .role-member {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .role-observer {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        /* Forms */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .form-group label {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        /* Member Cards */
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .member-card {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            transition: var(--transition);
        }

        .member-card:hover {
            border-color: var(--primary-blue);
            box-shadow: var(--shadow-sm);
        }

        .member-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .member-info h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .member-role {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .member-details {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .member-actions {
            display: flex;
            gap: 0.25rem;
            margin-top: 1rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Overlay for mobile */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(2px);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .members-grid {
                grid-template-columns: 1fr;
            }

            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .page-title {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration President</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>Arbitration Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="election_committee.php" class="active">
                        <i class="fas fa-users-cog"></i>
                        <span>Election Committee</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Election Committees</h1>
                <?php if ($action === 'list'): ?>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i> New Committee
                    </button>
                <?php else: ?>
                    <a href="election_committee.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Committees
                    </a>
                <?php endif; ?>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <?php if ($action === 'list'): ?>
                <!-- Committees List View -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $total_committees; ?></div>
                            <div class="stat-label">Total Committees</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $active_committees; ?></div>
                            <div class="stat-label">Active Committees</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $total_members; ?></div>
                            <div class="stat-label">Total Members</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($committees_by_year); ?></div>
                            <div class="stat-label">Academic Years</div>
                        </div>
                    </div>
                </div>

                <!-- Committees by Academic Year -->
                <?php if (!empty($committees_by_year)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>Committees by Academic Year</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                <?php foreach ($committees_by_year as $year_data): ?>
                                    <div style="text-align: center; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-blue);">
                                            <?php echo $year_data['committee_count']; ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                            <?php echo htmlspecialchars($year_data['academic_year']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- Committees Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Election Committees</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($committees)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users-cog"></i>
                            <h3>No committees found</h3>
                            <p>Get started by creating your first election committee.</p>
                            <button class="btn btn-primary" onclick="openCreateModal()">
                                <i class="fas fa-plus"></i> Create Committee
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Committee Name</th>
                                    <th>Academic Year</th>
                                    <th>Status</th>
                                    <th>Members</th>
                                    <th>Created By</th>
                                    <th>Created Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($committees as $committee): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($committee['committee_name']); ?></strong>
                                            <?php if ($committee['description']): ?>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($committee['description']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($committee['academic_year']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $committee['status']; ?>">
                                                <?php echo ucfirst($committee['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="stat-number" style="font-size: 1rem;"><?php echo $committee['member_count']; ?></span>
                                            <span class="stat-label">members</span>
                                        </td>
                                        <td><?php echo htmlspecialchars($committee['created_by_name']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($committee['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="election_committee.php?action=manage&id=<?php echo $committee['id']; ?>" 
                                                   class="btn btn-outline btn-sm" title="Manage Committee">
                                                    <i class="fas fa-cog"></i> Manage
                                                </a>
                                                <?php if ($committee['status'] === 'forming'): ?>
                                                    <button class="btn btn-success btn-sm" 
                                                            onclick="updateCommitteeStatus(<?php echo $committee['id']; ?>, 'active')"
                                                            title="Activate Committee">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($action === 'manage' && $committee): ?>
                <!-- Committee Management View -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($committee_members); ?></div>
                            <div class="stat-label">Committee Members</div>
                        </div>
                    </div>
                    <div class="stat-card <?php echo $committee['status'] === 'active' ? 'success' : 'warning'; ?>">
                        <div class="stat-icon">
                            <i class="fas fa-<?php echo $committee['status'] === 'active' ? 'check-circle' : 'clock'; ?>"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number" style="text-transform: capitalize;"><?php echo $committee['status']; ?></div>
                            <div class="stat-label">Committee Status</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php echo count(array_filter($committee_members, function($member) { 
                                    return $member['role'] === 'chairperson'; 
                                })); ?>
                            </div>
                            <div class="stat-label">Chairpersons</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo htmlspecialchars($committee['academic_year']); ?></div>
                            <div class="stat-label">Academic Year</div>
                        </div>
                    </div>
                </div>

                <!-- Committee Details -->
                <div class="card">
                    <div class="card-header">
                        <h3><?php echo htmlspecialchars($committee['committee_name']); ?></h3>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-outline btn-sm" onclick="openAddMemberModal()">
                                <i class="fas fa-user-plus"></i> Add Member
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="committee_id" value="<?php echo $committee['id']; ?>">
                                <select name="status" onchange="this.form.submit()" class="form-control" style="width: auto; display: inline-block;" name="update_committee_status">
                                    <option value="forming" <?php echo $committee['status'] === 'forming' ? 'selected' : ''; ?>>Forming</option>
                                    <option value="active" <?php echo $committee['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $committee['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($committee['description']): ?>
                            <p style="margin-bottom: 1rem; color: var(--dark-gray);">
                                <?php echo htmlspecialchars($committee['description']); ?>
                            </p>
                        <?php endif; ?>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <span style="font-size: 0.8rem; color: var(--dark-gray);">
                                Created by <?php echo htmlspecialchars($committee['created_by_name']); ?> on 
                                <?php echo date('F j, Y', strtotime($committee['created_at'])); ?>
                            </span>
                        </div>

                        <!-- Committee Members -->
                        <h4 style="margin-bottom: 1rem;">Committee Members</h4>
                        
                        <?php if (empty($committee_members)): ?>
                            <div class="empty-state" style="padding: 2rem;">
                                <i class="fas fa-users"></i>
                                <h4>No members added</h4>
                                <p>Start by adding members to this election committee.</p>
                                <button class="btn btn-primary" onclick="openAddMemberModal()">
                                    <i class="fas fa-user-plus"></i> Add First Member
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="members-grid">
                                <?php foreach ($committee_members as $member): ?>
                                    <div class="member-card">
                                        <div class="member-header">
                                            <div class="member-info">
                                                <h4><?php echo htmlspecialchars($member['full_name']); ?></h4>
                                                <div class="member-role">
                                                    <span class="role-badge role-<?php echo $member['role']; ?>">
                                                        <?php echo ucfirst($member['role']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="member-details">
                                            <div><strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $member['user_role'])); ?></div>
                                            <div><strong>Email:</strong> <?php echo htmlspecialchars($member['email']); ?></div>
                                            <?php if ($member['phone']): ?>
                                                <div><strong>Phone:</strong> <?php echo htmlspecialchars($member['phone']); ?></div>
                                            <?php endif; ?>
                                            <div><strong>Joined:</strong> <?php echo date('M j, Y', strtotime($member['assigned_date'])); ?></div>
                                        </div>
                                        
                                        <div class="member-actions">
                                            <button class="btn btn-outline btn-sm" 
                                                    onclick="openEditRoleModal(<?php echo $member['id']; ?>, '<?php echo $member['role']; ?>')"
                                                    title="Change Role">
                                                <i class="fas fa-edit"></i> Role
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                <input type="hidden" name="committee_id" value="<?php echo $committee['id']; ?>">
                                                <button type="submit" name="remove_member" class="btn btn-danger btn-sm" 
                                                        onclick="return confirm('Are you sure you want to remove <?php echo htmlspecialchars($member['full_name']); ?> from the committee?')"
                                                        title="Remove Member">
                                                    <i class="fas fa-trash"></i> Remove
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Available Elections for this Committee -->
                <?php
                try {
                    $stmt = $pdo->prepare("
                        SELECT e.* 
                        FROM elections e 
                        WHERE e.election_committee_id = ? 
                        ORDER BY e.created_at DESC
                    ");
                    $stmt->execute([$committee['id']]);
                    $committee_elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $committee_elections = [];
                }
                ?>

                <?php if (!empty($committee_elections)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>Assigned Elections</h3>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Election Title</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Voting Period</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($committee_elections as $election): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($election['title']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo htmlspecialchars($election['academic_year']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo ucfirst(str_replace('_', ' ', $election['election_type'])); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $election['status']; ?>">
                                                    <?php echo ucfirst($election['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo date('M j', strtotime($election['voting_start_date'])); ?> - 
                                                    <?php echo date('M j, Y', strtotime($election['voting_end_date'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <a href="elections.php?action=manage&id=<?php echo $election['id']; ?>" 
                                                   class="btn btn-outline btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- Create Committee Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Election Committee</h3>
                <button class="close-btn" onclick="closeCreateModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createCommitteeForm">
                    <div class="form-group">
                        <label for="committee_name">Committee Name *</label>
                        <input type="text" class="form-control" id="committee_name" name="committee_name" required 
                               placeholder="e.g., RPSU Election Committee 2024-2025">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <select class="form-control" id="academic_year" name="academic_year" required>
                                <option value="">Select Academic Year</option>
                                <option value="2023-2024">2023-2024</option>
                                <option value="2024-2025" selected>2024-2025</option>
                                <option value="2025-2026">2025-2026</option>
                                <option value="2026-2027">2026-2027</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Committee Description</label>
                        <textarea class="form-control" id="description" name="description" 
                                  placeholder="Brief description of the committee's purpose and responsibilities..."></textarea>
                    </div>

                    <div class="form-group" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" name="create_committee">Create Committee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Committee Member</h3>
                <button class="close-btn" onclick="closeAddMemberModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addMemberForm">
                    <input type="hidden" name="committee_id" value="<?php echo $committee['id']; ?>">
                    
                    <div class="form-group">
                        <label for="user_id">Select Member *</label>
                        <select class="form-control" id="user_id" name="user_id" required>
                            <option value="">Select a committee member</option>
                            <?php foreach ($available_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name']); ?> 
                                    (<?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>)
                                    <?php if ($user['reg_number']): ?>
                                        - <?php echo htmlspecialchars($user['reg_number']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="role">Member Role *</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="member">Member</option>
                            <option value="chairperson">Chairperson</option>
                            <option value="secretary">Secretary</option>
                            <option value="observer">Observer</option>
                        </select>
                    </div>

                    <div class="form-group" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closeAddMemberModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" name="add_member">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Role Modal -->
    <div id="editRoleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change Member Role</h3>
                <button class="close-btn" onclick="closeEditRoleModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editRoleForm">
                    <input type="hidden" id="edit_member_id" name="member_id">
                    <input type="hidden" name="committee_id" value="<?php echo $committee['id']; ?>">
                    
                    <div class="form-group">
                        <label for="edit_role">New Role *</label>
                        <select class="form-control" id="edit_role" name="role" required>
                            <option value="member">Member</option>
                            <option value="chairperson">Chairperson</option>
                            <option value="secretary">Secretary</option>
                            <option value="observer">Observer</option>
                        </select>
                    </div>

                    <div class="form-group" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closeEditRoleModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" name="update_member_role">Update Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
        }

        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }

        function openAddMemberModal() {
            document.getElementById('addMemberModal').style.display = 'flex';
        }

        function closeAddMemberModal() {
            document.getElementById('addMemberModal').style.display = 'none';
        }

        function openEditRoleModal(memberId, currentRole) {
            document.getElementById('edit_member_id').value = memberId;
            document.getElementById('edit_role').value = currentRole;
            document.getElementById('editRoleModal').style.display = 'flex';
        }

        function closeEditRoleModal() {
            document.getElementById('editRoleModal').style.display = 'none';
        }

        function updateCommitteeStatus(committeeId, status) {
            if (confirm('Are you sure you want to ' + status + ' this committee?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const committeeIdInput = document.createElement('input');
                committeeIdInput.name = 'committee_id';
                committeeIdInput.value = committeeId;
                form.appendChild(committeeIdInput);
                
                const statusInput = document.createElement('input');
                statusInput.name = 'status';
                statusInput.value = status;
                form.appendChild(statusInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['createModal', 'addMemberModal', 'editRoleModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'createModal') closeCreateModal();
                    if (modalId === 'addMemberModal') closeAddMemberModal();
                    if (modalId === 'editRoleModal') closeEditRoleModal();
                }
            });
        }

        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const sidebar = document.getElementById('sidebar');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>
                                


