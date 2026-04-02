<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_member'])) {
        // Add member to committee (vice president can add members)
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
                    (election_committee_id, user_id, role, assigned_date, assigned_by, status)
                    VALUES (?, ?, ?, CURDATE(), ?, 'active')
                ");
                $stmt->execute([$committee_id, $user_id_to_add, $role, $user_id]);
                
                $_SESSION['success_message'] = "Committee member added successfully!";
            }
            
            header('Location: election_committee.php?action=manage&id=' . $committee_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding committee member: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_member_role'])) {
        // Update member role (vice president can update roles)
        $member_id = $_POST['member_id'];
        $role = $_POST['role'];
        $committee_id = $_POST['committee_id'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE election_committee_members 
                SET role = ?, updated_at = NOW()
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
    
    if (isset($_POST['remove_member'])) {
        // Remove member from committee (with restriction: can't remove president)
        $member_id = $_POST['member_id'];
        $committee_id = $_POST['committee_id'];
        
        try {
            // Check if member is president
            $stmt = $pdo->prepare("
                SELECT u.role as user_role 
                FROM election_committee_members ecm
                JOIN users u ON ecm.user_id = u.id
                WHERE ecm.id = ?
            ");
            $stmt->execute([$member_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($member && $member['user_role'] === 'president_arbitration') {
                $_SESSION['error_message'] = "Cannot remove the Arbitration President from the committee!";
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM election_committee_members 
                    WHERE id = ? AND user_id != ?
                ");
                $stmt->execute([$member_id, $user_id]); // Can't remove self
                
                if ($stmt->rowCount() > 0) {
                    $_SESSION['success_message'] = "Committee member removed successfully!";
                } else {
                    $_SESSION['error_message'] = "Cannot remove yourself from the committee!";
                }
            }
            
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

// Get committees list - vice president can see all committees
try {
    $stmt = $pdo->query("
        SELECT ec.*, 
               COUNT(ecm.id) as member_count,
               u.full_name as created_by_name,
               EXISTS(
                   SELECT 1 FROM election_committee_members ecm2 
                   WHERE ecm2.election_committee_id = ec.id 
                   AND ecm2.user_id = $user_id
               ) as is_member
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
$is_committee_member = false;

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
        
        // Get committee members with assignment info
        $stmt = $pdo->prepare("
            SELECT ecm.*, 
                   u.full_name, u.role as user_role, u.email, u.phone,
                   u2.full_name as assigned_by_name,
                   CASE WHEN ecm.user_id = ? THEN 1 ELSE 0 END as is_current_user
            FROM election_committee_members ecm 
            JOIN users u ON ecm.user_id = u.id 
            LEFT JOIN users u2 ON ecm.assigned_by = u2.id
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
        $stmt->execute([$user_id, $committee_id]);
        $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if current user is a member of this committee
        $is_committee_member = in_array($user_id, array_column($committee_members, 'user_id'));
        
    } catch (PDOException $e) {
        error_log("Error fetching committee data: " . $e->getMessage());
    }
}

// Get available users for adding to committee (exclude current members)
try {
    $exclude_users = $committee_id ? array_column($committee_members, 'user_id') : [];
    $exclude_condition = $exclude_users ? "AND u.id NOT IN (" . implode(',', $exclude_users) . ")" : "";
    
    // Vice president can add arbitration committee members and election officials
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.email, u.reg_number, u.phone
        FROM users u 
        WHERE u.status = 'active' 
        AND (
            u.role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
            OR u.role LIKE '%election%'
            OR u.role LIKE '%committee%'
        )
        $exclude_condition
        ORDER BY 
            CASE u.role
                WHEN 'president_arbitration' THEN 1
                WHEN 'vice_president_arbitration' THEN 2
                WHEN 'secretary_arbitration' THEN 3
                WHEN 'advisor_arbitration' THEN 4
                ELSE 5
            END,
            u.full_name
    ");
    $stmt->execute();
    $available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $available_users = [];
}

// Get statistics for vice president
try {
    // Committees where vice president is a member
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ec.id) as my_committees
        FROM election_committees ec
        JOIN election_committee_members ecm ON ec.id = ecm.election_committee_id
        WHERE ecm.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $my_committees = $stmt->fetch(PDO::FETCH_ASSOC)['my_committees'] ?? 0;
    
    // Members added by vice president
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as members_added 
        FROM election_committee_members 
        WHERE assigned_by = ?
    ");
    $stmt->execute([$user_id]);
    $members_added = $stmt->fetch(PDO::FETCH_ASSOC)['members_added'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM election_committees WHERE status = 'active'");
    $active_committees = $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_members FROM election_committee_members WHERE status = 'active'");
    $total_members = $stmt->fetch(PDO::FETCH_ASSOC)['total_members'] ?? 0;
    
    // Committees by status
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM election_committees 
        GROUP BY status 
        ORDER BY status
    ");
    $committees_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $my_committees = $members_added = $active_committees = $total_members = 0;
    $committees_by_status = [];
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
            --info: #17a2b8;
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
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
            gap: 0.5rem;
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
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
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            transform: translateY(-1px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }



        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
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
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
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

        .btn-info {
            background: var(--info);
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
            border-left: 4px solid var(--primary-blue);
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

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
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

        .stat-card.info .stat-icon {
            background: #d1ecf1;
            color: var(--info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
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

        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 900px;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
            white-space: nowrap;
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
            position: relative;
        }

        .member-card.current-user {
            border-color: var(--primary-blue);
            background: rgba(227, 242, 253, 0.3);
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

        .member-details div {
            margin-bottom: 0.25rem;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: var(--info);
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        /* User badge */
        .user-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--primary-blue);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        /* Committee status */
        .committee-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        /* Responsive */
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

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .main-content.sidebar-collapsed {
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .members-grid {
                grid-template-columns: 1fr;
            }

            .table {
                display: none;
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

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .page-title {
                font-size: 1.2rem;
            }

            .action-buttons {
                flex-direction: column;
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
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        if (!empty($_SESSION['avatar_url'])): 
                            echo '<img src="../' . htmlspecialchars($_SESSION['avatar_url']) . '" alt="Profile">';
                        else:
                            echo strtoupper(substr($_SESSION['full_name'], 0, 1)); 
                        endif;
                        ?>
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
          <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
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
                    <div style="display: flex; gap: 0.5rem;">
                        <a href="elections.php" class="btn btn-outline">
                            <i class="fas fa-vote-yea"></i> Elections
                        </a>
                    </div>
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
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $my_committees; ?></div>
                            <div class="stat-label">My Committees</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-users-cog"></i>
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
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $members_added; ?></div>
                            <div class="stat-label">Members I Added</div>
                        </div>
                    </div>
                </div>

                <!-- Committees Status Overview -->
                <?php if (!empty($committees_by_status)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>Committees Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                <?php foreach ($committees_by_status as $status_data): 
                                    $status_class = '';
                                    if ($status_data['status'] === 'active') $status_class = 'success';
                                    if ($status_data['status'] === 'forming') $status_class = 'warning';
                                    if ($status_data['status'] === 'inactive') $status_class = 'danger';
                                ?>
                                    <div style="text-align: center; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius); border-left: 4px solid var(--<?php echo $status_class; ?>);">
                                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-dark);">
                                            <?php echo $status_data['count']; ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray); text-transform: capitalize;">
                                            <?php echo htmlspecialchars($status_data['status']); ?> Committees
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
                        <small>Total: <?php echo count($committees); ?> committees</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($committees)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users-cog"></i>
                                <h3>No committees found</h3>
                                <p>There are no election committees in the system yet.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Committee Name</th>
                                        <th>Academic Year</th>
                                        <th>Status</th>
                                        <th>Members</th>
                                        <th>My Role</th>
                                        <th>Created By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($committees as $committee_item): ?>
                                        <tr <?php echo $committee_item['is_member'] ? 'style="background-color: rgba(227, 242, 253, 0.3);"' : ''; ?>>
                                            <td>
                                                <strong><?php echo htmlspecialchars($committee_item['committee_name']); ?></strong>
                                                <?php if ($committee_item['is_member']): ?>
                                                    <i class="fas fa-user-check" style="color: var(--success); margin-left: 5px;" title="You are a member"></i>
                                                <?php endif; ?>
                                                <?php if ($committee_item['description']): ?>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                        <?php echo htmlspecialchars($committee_item['description']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($committee_item['academic_year']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $committee_item['status']; ?>">
                                                    <?php echo ucfirst($committee_item['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-weight: 500;"><?php echo $committee_item['member_count']; ?></span>
                                                <span style="color: var(--dark-gray); font-size: 0.8rem;">members</span>
                                            </td>
                                            <td>
                                                <?php if ($committee_item['is_member']): ?>
                                                    <span class="role-badge role-member">
                                                        <i class="fas fa-user"></i> Member
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Not a member</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($committee_item['created_by_name']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="election_committee.php?action=manage&id=<?php echo $committee_item['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="View Committee">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
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
                <!-- Access Control Alert -->
                <?php if (!$is_committee_member): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> You are viewing this committee as an administrator. You can manage members but some actions may be restricted.
                    </div>
                <?php endif; ?>

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
                    <div class="stat-card <?php echo $committee['status'] === 'active' ? 'success' : ($committee['status'] === 'forming' ? 'warning' : 'danger'); ?>">
                        <div class="stat-icon">
                            <i class="fas fa-<?php echo $committee['status'] === 'active' ? 'check-circle' : ($committee['status'] === 'forming' ? 'clock' : 'ban'); ?>"></i>
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
                                <?php 
                                $chairpersons = array_filter($committee_members, function($member) { 
                                    return $member['role'] === 'chairperson'; 
                                });
                                echo count($chairpersons); 
                                ?>
                            </div>
                            <div class="stat-label">Chairpersons</div>
                        </div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="stat-content">
                            <?php
                            $members_i_added = array_filter($committee_members, function($member) use ($user_id) {
                                return $member['assigned_by'] == $user_id;
                            });
                            ?>
                            <div class="stat-number"><?php echo count($members_i_added); ?></div>
                            <div class="stat-label">Added by Me</div>
                        </div>
                    </div>
                </div>

                <!-- Committee Details -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3><?php echo htmlspecialchars($committee['committee_name']); ?></h3>
                            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                Academic Year: <?php echo htmlspecialchars($committee['academic_year']); ?>
                            </div>
                        </div>
                        <div class="committee-status">
                            <span class="status-badge status-<?php echo $committee['status']; ?>">
                                <?php echo ucfirst($committee['status']); ?>
                            </span>
                            <button class="btn btn-outline btn-sm" onclick="openAddMemberModal()">
                                <i class="fas fa-user-plus"></i> Add Member
                            </button>
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
                        <h4 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-users"></i> Committee Members (<?php echo count($committee_members); ?>)
                        </h4>
                        
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
                                    <div class="member-card <?php echo $member['is_current_user'] ? 'current-user' : ''; ?>">
                                        <?php if ($member['is_current_user']): ?>
                                            <div class="user-badge" title="You">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="member-header">
                                            <div class="member-info">
                                                <h4>
                                                    <?php echo htmlspecialchars($member['full_name']); ?>
                                                    <?php if ($member['user_role'] === 'president_arbitration'): ?>
                                                        <i class="fas fa-crown" style="color: gold; margin-left: 5px;" title="Arbitration President"></i>
                                                    <?php endif; ?>
                                                </h4>
                                                <div class="member-role">
                                                    <span class="role-badge role-<?php echo $member['role']; ?>">
                                                        <?php echo ucfirst($member['role']); ?>
                                                    </span>
                                                    <span style="margin-left: 5px; font-size: 0.7rem; color: var(--dark-gray);">
                                                        <?php echo ucfirst(str_replace('_', ' ', $member['user_role'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="member-details">
                                            <?php if ($member['email']): ?>
                                                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($member['email']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($member['phone']): ?>
                                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($member['phone']); ?></div>
                                            <?php endif; ?>
                                            <div><i class="fas fa-calendar"></i> Joined: <?php echo date('M j, Y', strtotime($member['assigned_date'])); ?></div>
                                            <?php if ($member['assigned_by_name']): ?>
                                                <div><i class="fas fa-user-plus"></i> Added by: <?php echo htmlspecialchars($member['assigned_by_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="member-actions">
                                            <?php if (!$member['is_current_user'] && $member['user_role'] !== 'president_arbitration'): ?>
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
                                            <?php elseif ($member['user_role'] === 'president_arbitration'): ?>
                                                <span style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <i class="fas fa-shield-alt"></i> President - Cannot be removed
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <i class="fas fa-user"></i> You
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Committee Tasks/Responsibilities -->
                <?php if ($is_committee_member): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-tasks"></i> Committee Responsibilities</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <h4 style="margin-bottom: 0.5rem; color: var(--primary-blue);">
                                        <i class="fas fa-vote-yea"></i> Election Oversight
                                    </h4>
                                    <ul style="margin-left: 1rem; color: var(--dark-gray); font-size: 0.85rem;">
                                        <li>Monitor election proceedings</li>
                                        <li>Ensure compliance with election rules</li>
                                        <li>Address election-related complaints</li>
                                        <li>Verify candidate eligibility</li>
                                    </ul>
                                </div>
                                
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <h4 style="margin-bottom: 0.5rem; color: var(--primary-blue);">
                                        <i class="fas fa-balance-scale"></i> Arbitration Role
                                    </h4>
                                    <ul style="margin-left: 1rem; color: var(--dark-gray); font-size: 0.85rem;">
                                        <li>Resolve election disputes</li>
                                        <li>Conduct fair hearings</li>
                                        <li>Make impartial decisions</li>
                                        <li>Maintain election integrity</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Committee Meeting Notes (if any) -->
                <?php
                try {
                    $stmt = $pdo->prepare("
                        SELECT mn.*, u.full_name as created_by_name
                        FROM committee_meeting_notes mn
                        LEFT JOIN users u ON mn.created_by = u.id
                        WHERE mn.election_committee_id = ?
                        ORDER BY mn.meeting_date DESC
                        LIMIT 3
                    ");
                    $stmt->execute([$committee['id']]);
                    $meeting_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $meeting_notes = [];
                }
                ?>

                <?php if (!empty($meeting_notes)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-clipboard-list"></i> Recent Meeting Notes</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($meeting_notes as $note): ?>
                                    <div style="padding: 1rem; background: var(--white); border: 1px solid var(--medium-gray); border-radius: var(--border-radius);">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                            <strong><?php echo htmlspecialchars($note['title']); ?></strong>
                                            <small style="color: var(--dark-gray);">
                                                <?php echo date('M j, Y', strtotime($note['meeting_date'])); ?>
                                            </small>
                                        </div>
                                        <p style="font-size: 0.85rem; color: var(--dark-gray); margin-bottom: 0.5rem;">
                                            <?php echo htmlspecialchars(substr($note['notes'], 0, 150)) . (strlen($note['notes']) > 150 ? '...' : ''); ?>
                                        </p>
                                        <div style="font-size: 0.75rem; color: var(--dark-gray);">
                                            Recorded by: <?php echo htmlspecialchars($note['created_by_name']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
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
                        <small style="color: var(--dark-gray); font-size: 0.75rem;">
                            Only active arbitration committee members and election officials can be added.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="role">Member Role *</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="member">Member</option>
                            <option value="chairperson">Chairperson</option>
                            <option value="secretary">Secretary</option>
                            <option value="observer">Observer</option>
                        </select>
                        <small style="color: var(--dark-gray); font-size: 0.75rem;">
                            Note: Only one member should be assigned as Chairperson.
                        </small>
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

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addMemberModal = document.getElementById('addMemberModal');
            const editRoleModal = document.getElementById('editRoleModal');
            
            if (event.target === addMemberModal) {
                closeAddMemberModal();
            }
            if (event.target === editRoleModal) {
                closeEditRoleModal();
            }
        }

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
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