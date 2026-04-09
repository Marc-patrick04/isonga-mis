<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Advisor Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'advisor_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// Get dashboard statistics for advisor (PostgreSQL syntax)
try {
    // Total assigned arbitration cases
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_cases FROM arbitration_cases WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'] ?? 0;
    
    // Pending assigned cases
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_cases FROM arbitration_cases WHERE assigned_to = ? AND status IN ('filed', 'under_review')");
    $stmt->execute([$user_id]);
    $pending_cases = $stmt->fetch(PDO::FETCH_ASSOC)['pending_cases'] ?? 0;
    
    // Resolved assigned cases
    $stmt = $pdo->prepare("SELECT COUNT(*) as resolved_cases FROM arbitration_cases WHERE assigned_to = ? AND status = 'resolved'");
    $stmt->execute([$user_id]);
    $resolved_cases = $stmt->fetch(PDO::FETCH_ASSOC)['resolved_cases'] ?? 0;
    
    // Upcoming hearings for assigned cases - PostgreSQL uses CURRENT_DATE
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_hearings 
        FROM arbitration_hearings ah 
        JOIN arbitration_cases ac ON ah.case_id = ac.id 
        WHERE ah.hearing_date >= CURRENT_DATE AND ah.status = 'scheduled' AND ac.assigned_to = ?
    ");
    $stmt->execute([$user_id]);
    $upcoming_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_hearings'] ?? 0;
    
    // Active elections (advisor can view all)
    $stmt = $pdo->query("SELECT COUNT(*) as active_elections FROM elections WHERE status IN ('nomination', 'campaign', 'voting')");
    $active_elections = $stmt->fetch(PDO::FETCH_ASSOC)['active_elections'] ?? 0;
    
    // Recent assigned cases
    $stmt = $pdo->prepare("SELECT * FROM arbitration_cases WHERE assigned_to = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $recent_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming hearings for assigned cases - PostgreSQL syntax
    $stmt = $pdo->prepare("
        SELECT ah.*, ac.case_number, ac.title as case_title 
        FROM arbitration_hearings ah 
        JOIN arbitration_cases ac ON ah.case_id = ac.id 
        WHERE ah.hearing_date >= CURRENT_DATE AND ah.status = 'scheduled' AND ac.assigned_to = ?
        ORDER BY ah.hearing_date ASC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $upcoming_hearings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active elections list
    $stmt = $pdo->query("SELECT * FROM elections WHERE status IN ('nomination', 'campaign', 'voting') ORDER BY voting_start_date ASC LIMIT 3");
    $active_elections_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Unread messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
    // Hearings scheduled for this week - PostgreSQL uses INTERVAL
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as weekly_hearings
        FROM arbitration_hearings ah
        JOIN arbitration_cases ac ON ah.case_id = ac.id
        WHERE ah.hearing_date >= CURRENT_DATE 
        AND ah.hearing_date <= CURRENT_DATE + INTERVAL '7 days'
        AND ah.status = 'scheduled'
        AND ac.assigned_to = ?
    ");
    $stmt->execute([$user_id]);
    $weekly_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['weekly_hearings'] ?? 0;
    
    // Case trend data for chart (last 7 days) - PostgreSQL syntax
    $case_trends = [];
    try {
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM arbitration_cases 
            WHERE assigned_to = ? 
            AND created_at >= CURRENT_DATE - INTERVAL '7 days'
            GROUP BY DATE(created_at) 
            ORDER BY date
        ");
        $stmt->execute([$user_id]);
        $case_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Case trends query error: " . $e->getMessage());
        $case_trends = [];
    }
    
    // Case status distribution
    $status_distribution = [];
    try {
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count 
            FROM arbitration_cases 
            WHERE assigned_to = ?
            GROUP BY status
        ");
        $stmt->execute([$user_id]);
        $status_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Status distribution query error: " . $e->getMessage());
        $status_distribution = [];
    }
    
    // Case priority distribution
    $priority_distribution = [];
    try {
        $stmt = $pdo->prepare("
            SELECT priority, COUNT(*) as count 
            FROM arbitration_cases 
            WHERE assigned_to = ? AND status != 'resolved'
            GROUP BY priority
        ");
        $stmt->execute([$user_id]);
        $priority_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Priority distribution query error: " . $e->getMessage());
        $priority_distribution = [];
    }
    
} catch (PDOException $e) {
    error_log("Advisor arbitration dashboard statistics error: " . $e->getMessage());
    $total_cases = $pending_cases = $resolved_cases = $upcoming_hearings = $active_elections = $unread_messages = $weekly_hearings = 0;
    $recent_cases = $upcoming_hearings_list = $active_elections_list = $case_trends = $status_distribution = $priority_distribution = [];
}

// Calculate resolution rate
$resolution_rate = $total_cases > 0 ? round(($resolved_cases / $total_cases) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Arbitration Advisor Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            font-size: 0.9rem;
            color: var(--text-dark);
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
            text-align: center;
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

        /* Card */
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
            flex-wrap: wrap;
            gap: 0.75rem;
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

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-filed {
            background: #fff3cd;
            color: #856404;
        }

        .status-under_review {
            background: #cce7ff;
            color: #004085;
        }

        .status-hearing_scheduled {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-resolved {
            background: #d4edda;
            color: #155724;
        }

        .status-dismissed {
            background: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .priority-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-high {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }

        .priority-low {
            background: #d4edda;
            color: #155724;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.7rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert a {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* Chart Containers */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .chart-container {
            height: 250px;
            position: relative;
            padding: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.75rem;
            opacity: 0.5;
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
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

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .table th, .table td {
                padding: 0.5rem;
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

            .chart-container {
                height: 200px;
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
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration Advisor</div>
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
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>My Cases</span>
                        <?php if ($pending_cases > 0): ?>
                            <span class="menu-badge"><?php echo $pending_cases; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                        <?php if ($upcoming_hearings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_hearings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                        <?php if ($active_elections > 0): ?>
                            <span class="menu-badge"><?php echo $active_elections; ?></span>
                        <?php endif; ?>
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
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Welcome, Arbitration Advisor <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
                  
                </div>
            </div>

            <?php if ($password_change_required): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Action Required:</strong> Please <a href="profile.php?tab=security">change your password</a> for security reasons.
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_cases); ?></div>
                        <div class="stat-label">Assigned Cases</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($pending_cases); ?></div>
                        <div class="stat-label">Pending Cases</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($resolved_cases); ?></div>
                        <div class="stat-label">Resolved Cases</div>
                    </div>
                </div>
               
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-vote-yea"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($active_elections); ?></div>
                        <div class="stat-label">Active Elections</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolution_rate; ?>%</div>
                        <div class="stat-label">Resolution Rate</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($weekly_hearings); ?></div>
                        <div class="stat-label">This Week</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($unread_messages); ?></div>
                        <div class="stat-label">Unread Messages</div>
                    </div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="charts-grid">
                <!-- Case Trends Chart -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3>Case Trends (Last 7 Days)</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="caseTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Assigned Cases -->
                    <div class="card">
                        <div class="card-header">
                            <h3>My Recent Cases</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="cases.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_cases)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-balance-scale"></i>
                                    <p>No assigned cases</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Case #</th>
                                                <th>Title</th>
                                                <th>Parties</th>
                                                <th>Status</th>
                                                <th>Filed</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_cases as $case): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($case['case_number']); ?></td>
                                                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                        <?php echo htmlspecialchars($case['title']); ?>
                                                    </td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($case['complainant_name'] ?? 'N/A'); ?> vs <?php echo htmlspecialchars($case['respondent_name'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $case['status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j', strtotime($case['filing_date'] ?? $case['created_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="cases.php" class="action-btn">
                            <i class="fas fa-list"></i>
                            <span class="action-label">View All Cases</span>
                        </a>
                        <a href="hearings.php" class="action-btn">
                            <i class="fas fa-gavel"></i>
                            <span class="action-label">Manage Hearings</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Case Reports</span>
                        </a>
                        <a href="messages.php" class="action-btn">
                            <i class="fas fa-comments"></i>
                            <span class="action-label">Messages</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    

                    <!-- Active Elections -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Active Elections</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($active_elections_list)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <p>No active elections</p>
                                </div>
                            <?php else: ?>
                                <div style="display: grid; gap: 1rem;">
                                    <?php foreach ($active_elections_list as $election): ?>
                                        <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <div style="font-weight: 600; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($election['title']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0.5rem;">
                                                Status: <span class="status-badge status-<?php echo $election['status']; ?>">
                                                    <?php echo ucfirst($election['status']); ?>
                                                </span>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--dark-gray);">
                                                Voting: <?php echo date('M j', strtotime($election['voting_start_date'])); ?> - 
                                                <?php echo date('M j', strtotime($election['voting_end_date'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Performance Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Total Cases</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($total_cases); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Cases</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($pending_cases); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Resolved Cases</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($resolved_cases); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Resolution Rate</span>
                                    <strong style="color: var(--success);"><?php echo $resolution_rate; ?>%</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Upcoming Hearings</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($upcoming_hearings); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
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

        // Charts initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Case Trends Chart
            const caseTrendsCtx = document.getElementById('caseTrendsChart')?.getContext('2d');
            if (caseTrendsCtx) {
                new Chart(caseTrendsCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php 
                            $dates = [];
                            foreach ($case_trends as $trend) {
                                $dates[] = "'" . date('M j', strtotime($trend['date'])) . "'";
                            }
                            echo implode(', ', $dates);
                            ?>
                        ],
                        datasets: [{
                            label: 'Cases Filed',
                            data: [
                                <?php 
                                $counts = [];
                                foreach ($case_trends as $trend) {
                                    $counts[] = $trend['count'];
                                }
                                echo implode(', ', $counts);
                                ?>
                            ],
                            borderColor: '#0056b3',
                            backgroundColor: 'rgba(0, 86, 179, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }

            // Case Status Distribution Chart
            const caseStatusCtx = document.getElementById('caseStatusChart')?.getContext('2d');
            if (caseStatusCtx) {
                new Chart(caseStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            <?php 
                            $statuses = [];
                            foreach ($status_distribution as $status) {
                                $statuses[] = "'" . ucfirst(str_replace('_', ' ', $status['status'])) . "'";
                            }
                            echo implode(', ', $statuses);
                            ?>
                        ],
                        datasets: [{
                            data: [
                                <?php 
                                $statusCounts = [];
                                foreach ($status_distribution as $status) {
                                    $statusCounts[] = $status['count'];
                                }
                                echo implode(', ', $statusCounts);
                                ?>
                            ],
                            backgroundColor: [
                                '#fff3cd', '#cce7ff', '#e2e3ff', '#d4edda', '#f8d7da'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    font: {
                                        size: 10
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .card, .chart-card');
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