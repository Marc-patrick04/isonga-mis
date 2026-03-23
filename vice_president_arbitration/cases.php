<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];



// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_case'])) {
        // Create new case
        $case_number = 'ARB-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $title = $_POST['title'];
        $description = $_POST['description'];
        $case_type = $_POST['case_type'];
        $complainant_name = $_POST['complainant_name'];
        $respondent_name = $_POST['respondent_name'];
        $complainant_contact = $_POST['complainant_contact'];
        $respondent_contact = $_POST['respondent_contact'];
        $priority = $_POST['priority'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO arbitration_cases 
                (case_number, title, description, case_type, complainant_name, respondent_name, 
                 complainant_contact, respondent_contact, priority, filing_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
            ");
            $stmt->execute([
                $case_number, $title, $description, $case_type, $complainant_name, $respondent_name,
                $complainant_contact, $respondent_contact, $priority, $user_id
            ]);
            
            $_SESSION['success_message'] = "Case created successfully! Case Number: $case_number";
            header('Location: cases.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error creating case: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_case'])) {
        // Update case
        $case_id = $_POST['case_id'];
        $status = $_POST['status'];
        $assigned_to = $_POST['assigned_to'];
        $hearing_date = $_POST['hearing_date'];
        $resolution_details = $_POST['resolution_details'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE arbitration_cases 
                SET status = ?, assigned_to = ?, hearing_date = ?, resolution_details = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $assigned_to, $hearing_date, $resolution_details, $case_id]);
            
            $_SESSION['success_message'] = "Case updated successfully!";
            header('Location: cases.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating case: " . $e->getMessage();
        }
    }
}

// Handle case deletion
if (isset($_GET['delete_case'])) {
    $case_id = $_GET['delete_case'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM arbitration_cases WHERE id = ?");
        $stmt->execute([$case_id]);
        
        $_SESSION['success_message'] = "Case deleted successfully!";
        header('Location: cases.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting case: " . $e->getMessage();
    }
}

// Handle case assignment
if (isset($_POST['assign_case'])) {
    $case_id = $_POST['case_id'];
    $assigned_to = $_POST['assigned_to'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE arbitration_cases 
            SET assigned_to = ?, assigned_by = ?, assigned_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$assigned_to, $user_id, $case_id]);
        
        $_SESSION['success_message'] = "Case assigned successfully!";
        header('Location: cases.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error assigning case: " . $e->getMessage();
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_priority = $_GET['priority'] ?? '';
$filter_type = $_GET['case_type'] ?? '';
$search = $_GET['search'] ?? '';

// Build query for cases
$query = "SELECT ac.*, 
                 u1.full_name as assigned_name,
                 u2.full_name as creator_name
          FROM arbitration_cases ac
          LEFT JOIN users u1 ON ac.assigned_to = u1.id
          LEFT JOIN users u2 ON ac.created_by = u2.id
          WHERE 1=1";

$params = [];

if (!empty($filter_status)) {
    $query .= " AND ac.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_priority)) {
    $query .= " AND ac.priority = ?";
    $params[] = $filter_priority;
}

if (!empty($filter_type)) {
    $query .= " AND ac.case_type = ?";
    $params[] = $filter_type;
}

if (!empty($search)) {
    $query .= " AND (ac.case_number LIKE ? OR ac.title LIKE ? OR ac.complainant_name LIKE ? OR ac.respondent_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY ac.created_at DESC";

// Get cases
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cases = [];
    error_log("Error fetching cases: " . $e->getMessage());
}

// Get arbitration committee members for assignment
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name 
        FROM users u 
        WHERE u.role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        AND u.status = 'active'
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases");
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM arbitration_cases WHERE status IN ('filed', 'under_review')");
    $pending_cases = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as resolved FROM arbitration_cases WHERE status = 'resolved'");
    $resolved_cases = $stmt->fetch(PDO::FETCH_ASSOC)['resolved'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as hearings FROM arbitration_cases WHERE status = 'hearing_scheduled'");
    $hearing_cases = $stmt->fetch(PDO::FETCH_ASSOC)['hearings'];
} catch (PDOException $e) {
    $total_cases = $pending_cases = $resolved_cases = $hearing_cases = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arbitration Cases - Isonga RPSU</title>
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

        .dark-mode {
            --primary-blue: #1e88e5;
            --secondary-blue: #64b5f6;
            --accent-blue: #1565c0;
            --light-blue: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
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
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
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

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
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

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
        }

        .menu-badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: between;
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

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            padding: 1.25rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
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

        /* Table */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
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
            overflow-x: auto;
        }

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

        .status-filed {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-under_review {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-hearing_scheduled {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-mediation {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-dismissed {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-appealed {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-high {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .priority-low {
            background: #d4edda;
            color: var(--success);
        }

        .case-type-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            background: #e3f2fd;
            color: var(--primary-blue);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: between;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
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

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
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
                        <div class="user-role">Arbitration Vice President</div>
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
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php" class="active">
                        <i class="fas fa-balance-scale"></i>
                        <span>Arbitration Cases</span>
                        <?php if ($pending_cases > 0): ?>
                            <span class="menu-badge"><?php echo $pending_cases; ?></span>
                        <?php endif; ?>
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
                    <a href="election_committee.php">
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
                <h1 class="page-title">Arbitration Cases</h1>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> New Case
                </button>
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

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_cases; ?></div>
                        <div class="stat-label">Total Cases</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_cases; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolved_cases; ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $hearing_cases; ?></div>
                        <div class="stat-label">Hearings Scheduled</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="search">Search Cases</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by case number, title, or parties...">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="filed" <?php echo $filter_status === 'filed' ? 'selected' : ''; ?>>Filed</option>
                            <option value="under_review" <?php echo $filter_status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="hearing_scheduled" <?php echo $filter_status === 'hearing_scheduled' ? 'selected' : ''; ?>>Hearing Scheduled</option>
                            <option value="mediation" <?php echo $filter_status === 'mediation' ? 'selected' : ''; ?>>Mediation</option>
                            <option value="resolved" <?php echo $filter_status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="dismissed" <?php echo $filter_status === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select class="form-control" id="priority" name="priority">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="case_type">Case Type</label>
                        <select class="form-control" id="case_type" name="case_type">
                            <option value="">All Types</option>
                            <option value="student_dispute" <?php echo $filter_type === 'student_dispute' ? 'selected' : ''; ?>>Student Dispute</option>
                            <option value="committee_conflict" <?php echo $filter_type === 'committee_conflict' ? 'selected' : ''; ?>>Committee Conflict</option>
                            <option value="election_dispute" <?php echo $filter_type === 'election_dispute' ? 'selected' : ''; ?>>Election Dispute</option>
                            <option value="disciplinary" <?php echo $filter_type === 'disciplinary' ? 'selected' : ''; ?>>Disciplinary</option>
                            <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="cases.php" class="btn btn-outline">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Cases Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Arbitration Cases (<?php echo count($cases); ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($cases)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No cases found</h3>
                            <p>No arbitration cases match your current filters.</p>
                            <button class="btn btn-primary" onclick="openCreateModal()">
                                <i class="fas fa-plus"></i> Create First Case
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Case #</th>
                                    <th>Title</th>
                                    <th>Parties</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Filed Date</th>
                                    <th>Assigned To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases as $case): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($case['case_number']); ?></strong>
                                        </td>
                                        <td style="max-width: 200px;">
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($case['title']); ?></div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo strlen($case['description']) > 100 ? 
                                                    htmlspecialchars(substr($case['description'], 0, 100)) . '...' : 
                                                    htmlspecialchars($case['description']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <strong>C:</strong> <?php echo htmlspecialchars($case['complainant_name']); ?><br>
                                                <strong>R:</strong> <?php echo htmlspecialchars($case['respondent_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="case-type-badge">
                                                <?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $case['priority']; ?>">
                                                <?php echo ucfirst($case['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $case['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($case['filing_date'])); ?></td>
<td>
    <?php if ($case['assigned_name']): ?>
        <div style="font-size: 0.8rem;">
            <strong><?php echo htmlspecialchars($case['assigned_name']); ?></strong>
            <?php if ($case['assigned_at']): ?>
                <br><small style="color: var(--dark-gray);">
                    <?php echo date('M j', strtotime($case['assigned_at'])); ?>
                </small>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <span style="color: var(--dark-gray); font-size: 0.8rem;">Not assigned</span>
        <br>

    <?php endif; ?>
</td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline btn-sm" 
                                                        onclick="openEditModal(<?php echo htmlspecialchars(json_encode($case)); ?>)"
                                                        title="Edit Case">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="case_details.php?id=<?php echo $case['id']; ?>" 
                                                   class="btn btn-outline btn-sm" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="confirmDelete(<?php echo $case['id']; ?>, '<?php echo htmlspecialchars($case['case_number']); ?>')"
                                                        title="Delete Case">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Create Case Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Arbitration Case</h3>
                <button class="close-btn" onclick="closeCreateModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createCaseForm">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="title">Case Title *</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="case_type">Case Type *</label>
                            <select class="form-control" id="case_type" name="case_type" required>
                                <option value="">Select Type</option>
                                <option value="student_dispute">Student Dispute</option>
                                <option value="committee_conflict">Committee Conflict</option>
                                <option value="election_dispute">Election Dispute</option>
                                <option value="disciplinary">Disciplinary</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="priority">Priority *</label>
                            <select class="form-control" id="priority" name="priority" required>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="complainant_name">Complainant Name *</label>
                            <input type="text" class="form-control" id="complainant_name" name="complainant_name" required>
                        </div>
                        <div class="form-group">
                            <label for="complainant_contact">Complainant Contact</label>
                            <input type="text" class="form-control" id="complainant_contact" name="complainant_contact">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="respondent_name">Respondent Name *</label>
                            <input type="text" class="form-control" id="respondent_name" name="respondent_name" required>
                        </div>
                        <div class="form-group">
                            <label for="respondent_contact">Respondent Contact</label>
                            <input type="text" class="form-control" id="respondent_contact" name="respondent_contact">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="description">Case Description *</label>
                            <textarea class="form-control" id="description" name="description" required></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="create_case">Create Case</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Case Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Arbitration Case</h3>
                <button class="close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editCaseForm">
                    <input type="hidden" id="edit_case_id" name="case_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_status">Status *</label>
                            <select class="form-control" id="edit_status" name="status" required>
                                <option value="filed">Filed</option>
                                <option value="under_review">Under Review</option>
                                <option value="hearing_scheduled">Hearing Scheduled</option>
                                <option value="mediation">Mediation</option>
                                <option value="resolved">Resolved</option>
                                <option value="dismissed">Dismissed</option>
                                <option value="appealed">Appealed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_assigned_to">Assign To</label>
                            <select class="form-control" id="edit_assigned_to" name="assigned_to">
                                <option value="">Not Assigned</option>
                                <?php foreach ($committee_members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo htmlspecialchars($member['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_hearing_date">Hearing Date</label>
                            <input type="datetime-local" class="form-control" id="edit_hearing_date" name="hearing_date">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="edit_resolution_details">Resolution Details</label>
                            <textarea class="form-control" id="edit_resolution_details" name="resolution_details" rows="4"></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="update_case">Update Case</button>
                        </div>
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

        function openEditModal(caseData) {
            document.getElementById('edit_case_id').value = caseData.id;
            document.getElementById('edit_status').value = caseData.status;
            document.getElementById('edit_assigned_to').value = caseData.assigned_to || '';
            document.getElementById('edit_hearing_date').value = caseData.hearing_date ? 
                caseData.hearing_date.replace(' ', 'T').substr(0, 16) : '';
            document.getElementById('edit_resolution_details').value = caseData.resolution_details || '';
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function confirmDelete(caseId, caseNumber) {
            if (confirm(`Are you sure you want to delete case ${caseNumber}? This action cannot be undone.`)) {
                window.location.href = `cases.php?delete_case=${caseId}`;
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const createModal = document.getElementById('createModal');
            const editModal = document.getElementById('editModal');
            
            if (event.target === createModal) {
                closeCreateModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
        }

        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
    </script>
</body>
</html>