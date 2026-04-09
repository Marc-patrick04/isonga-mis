<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_representative_board') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle form actions
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Add Class Representative
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class_rep'])) {
    try {
        $student_id = $_POST['student_id'];
        
        // Check if student exists and is active
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception("Student not found or inactive.");
        }
        
        // Check if already a class representative
        if ($student['is_class_rep']) {
            throw new Exception("This student is already a class representative.");
        }
        
        // Update student to class representative (PostgreSQL uses true/false for boolean)
        $stmt = $pdo->prepare("UPDATE users SET is_class_rep = true, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$student_id]);
        
        $message = "Class representative added successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Remove Class Representative
if ($action === 'remove' && isset($_GET['id'])) {
    try {
        $rep_id = $_GET['id'];
        
        // Update user to remove class representative status (PostgreSQL uses true/false for boolean)
        $stmt = $pdo->prepare("UPDATE users SET is_class_rep = false, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$rep_id]);
        
        $message = "Class representative removed successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Get all class representatives with their department and program info (PostgreSQL compatible)
try {
    $stmt = $pdo->query("
        SELECT 
            u.*,
            d.name as department_name,
            p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.is_class_rep = true AND u.status = 'active'
        ORDER BY u.full_name
    ");
    $class_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $total_reps = count($class_reps);
    
    // Get total students count for statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    // Get active students who are not class representatives for the add form (PostgreSQL uses false for boolean)
    $stmt = $pdo->query("
        SELECT u.id, u.reg_number, u.full_name, u.email, d.name as department_name, p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.role = 'student' AND u.status = 'active' AND u.is_class_rep = false
        ORDER BY u.full_name
    ");
    $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Department-wise statistics (PostgreSQL compatible)
    $stmt = $pdo->query("
        SELECT 
            d.name as department_name,
            COUNT(u.id) as rep_count
        FROM users u
        JOIN departments d ON u.department_id = d.id
        WHERE u.is_class_rep = true AND u.status = 'active'
        GROUP BY d.id, d.name
        ORDER BY rep_count DESC
    ");
    $dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $class_reps = [];
    $available_students = [];
    $total_reps = 0;
    $total_students = 0;
    $dept_stats = [];
    error_log("Class representatives data error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar (PostgreSQL compatible)
try {
    // Total reps count
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    // Pending minutes - check using rep_meetings table (PostgreSQL)
    $pending_minutes = 0;
    try {
        // Check if rep_meetings table exists via information_schema (PostgreSQL)
        $stmt = $pdo->query("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_name = 'rep_meetings'
            ) as table_exists
        ");
        $table_exists = $stmt->fetch(PDO::FETCH_ASSOC)['table_exists'] ?? false;
        
        if ($table_exists) {
            // Count meetings without minutes that are completed
            $stmt = $pdo->query("
                SELECT COUNT(*) as pending_minutes 
                FROM rep_meetings 
                WHERE (minutes IS NULL OR minutes = '') AND status = 'completed'
            ");
            $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'] ?? 0;
        } else {
            // Fallback: try meeting_minutes table
            $stmt = $pdo->query("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables 
                    WHERE table_name = 'meeting_minutes'
                ) as minutes_table_exists
            ");
            $minutes_table_exists = $stmt->fetch(PDO::FETCH_ASSOC)['minutes_table_exists'] ?? false;
            
            if ($minutes_table_exists) {
                $stmt = $pdo->query("SELECT COUNT(*) as pending_minutes FROM meeting_minutes WHERE approval_status = 'draft'");
                $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'] ?? 0;
            }
        }
    } catch (Exception $e) {
        error_log("Pending minutes query error: " . $e->getMessage());
    }
    
    // Upcoming meetings (PostgreSQL uses CURRENT_DATE instead of CURDATE())
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_meetings 
        FROM rep_meetings 
        WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
    ");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    // Unread messages (PostgreSQL compatible)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    
} catch (PDOException $e) {
    $sidebar_reps_count = 0;
    $pending_minutes = 0;
    $upcoming_meetings = 0;
    $unread_messages = 0;
    error_log("Sidebar stats error: " . $e->getMessage());
}

// Use sidebar reps count for total_reps if not already set
if ($total_reps == 0) {
    $total_reps = $sidebar_reps_count;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Manage Class Representatives - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #007bff;
            --secondary-blue: #0056b3;
            --accent-blue: #0069d9;
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        .dark-mode {
            --primary-blue: #4dabf7;
            --secondary-blue: #339af0;
            --accent-blue: #228be6;
            --light-blue: #1a365d;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
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
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
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

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
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
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
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

        .menu-divider {
            height: 1px;
            background: var(--medium-gray);
            margin: 1rem 1.5rem;
        }

        .menu-section {
            padding: 0.75rem 1.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Main Content */
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

        /* Dashboard Header */
        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
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
            flex-shrink: 0;
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
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

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
            background: var(--light-blue);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.7rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Tables */
        .table-container {
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

        .table tbody tr:hover {
            background: var(--light-blue);
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        /* Department Stats */
        .department-stats {
            display: grid;
            gap: 0.75rem;
        }

        .department-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .department-name {
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .department-count {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            margin-bottom: 0.5rem;
            color: var(--text-dark);
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

            .welcome-section h1 {
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
                    <h1>Isonga - Representative Board Secretary</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                   
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Secretary - Representative Board</div>
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_reps.php" class="active">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Management</span>
                        <?php if ($total_reps > 0): ?>
                            <span class="menu-badge"><?php echo $total_reps; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                    </a>
                </li>
              
                
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-handshake"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
        <main class="main-content" id="mainContent">
            

            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i> 
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_reps); ?></div>
                        <div class="stat-label">Class Representatives</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_students); ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $coverage = $total_students > 0 ? round(($total_reps / $total_students) * 100, 1) : 0;
                            echo number_format($coverage, 1); 
                            ?>%
                        </div>
                        <div class="stat-label">Representative Coverage</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format(count($dept_stats)); ?></div>
                        <div class="stat-label">Departments Covered</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Add Class Representative Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Add Class Representative</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="form-label" for="student_id">Select Student</label>
                                    <select class="form-control" id="student_id" name="student_id" required>
                                        <option value="">Choose a student...</option>
                                        <?php foreach ($available_students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['full_name']); ?> 
                                                (<?php echo htmlspecialchars($student['reg_number']); ?>)
                                                - <?php echo htmlspecialchars($student['program_name'] ?? 'No Program'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($available_students)): ?>
                                        <small style="color: var(--dark-gray); margin-top: 0.5rem; display: block;">
                                            <i class="fas fa-info-circle"></i> No available students to assign as class representatives.
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" name="add_class_rep" class="btn btn-primary" <?php echo empty($available_students) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-user-plus"></i> Add as Class Representative
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Class Representatives List -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Class Representatives (<?php echo $total_reps; ?>)</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-container">
                                <?php if (empty($class_reps)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-users-slash"></i>
                                        <h4>No Class Representatives</h4>
                                        <p>No class representatives have been assigned yet. Use the form above to add class representatives.</p>
                                    </div>
                                <?php else: ?>
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Registration Number</th>
                                                <th>Program & Year</th>
                                                <th>Department</th>
                                                <th>Last Login</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($class_reps as $rep): ?>
                                                <tr>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                            <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.7rem;">
                                                                <?php if (!empty($rep['avatar_url'])): ?>
                                                                    <img src="../<?php echo htmlspecialchars($rep['avatar_url']); ?>" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                                                <?php else: ?>
                                                                    <?php echo strtoupper(substr($rep['full_name'], 0, 1)); ?>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                                <br>
                                                                <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($rep['email']); ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($rep['reg_number']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($rep['program_name'] ?? 'N/A'); ?>
                                                        <br>
                                                        <small style="color: var(--dark-gray);">Year <?php echo htmlspecialchars($rep['academic_year'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php if ($rep['last_login']): ?>
                                                            <?php echo date('M j, Y', strtotime($rep['last_login'])); ?>
                                                            <br>
                                                            <small style="color: var(--dark-gray);">
                                                                <?php echo date('g:i A', strtotime($rep['last_login'])); ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span style="color: var(--dark-gray);">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="?action=remove&id=<?php echo $rep['id']; ?>" 
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to remove <?php echo htmlspecialchars($rep['full_name']); ?> as class representative?')">
                                                            <i class="fas fa-user-minus"></i> Remove
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Department Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Representatives by Department</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($dept_stats)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-pie"></i>
                                    <p>No department statistics available</p>
                                </div>
                            <?php else: ?>
                                <div class="department-stats">
                                    <?php foreach ($dept_stats as $dept): ?>
                                        <div class="department-stat">
                                            <span class="department-name"><?php echo htmlspecialchars($dept['department_name']); ?></span>
                                            <span class="department-count"><?php echo $dept['rep_count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <a href="class_rep_meetings.php" class="btn btn-primary">
                                    <i class="fas fa-calendar-alt"></i> Schedule Meeting
                                </a>
                                <a href="meeting_minutes.php" class="btn btn-success">
                                    <i class="fas fa-file-alt"></i> Manage Minutes
                                </a>
                                <a href="messages.php" class="btn btn-info">
                                    <i class="fas fa-envelope"></i> Send Message
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Information Card -->
                    
                </div>
            </div>
        </main>
    </div>

    <script>
        
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

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animation = 'fadeInUp 0.4s ease forwards';
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 500);
        });
    </script>
</body>
</html>