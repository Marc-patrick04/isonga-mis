<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Sports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_sports') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Initialize variables with default values
$unread_messages = 0;
$pending_tickets = 0;

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user needs to change password (first login)
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// Initialize variables with default values
$total_teams = $total_members = $upcoming_competitions = $total_clubs = 0;
$recent_competitions = $active_teams = $upcoming_trainings = $recent_activities = [];
$equipment_status = $team_performance = $recent_events = [];
$total_students = 0;
$total_facilities = 0;
$active_trainings = 0;

// Get dashboard statistics for Minister of Sports and Entertainment
try {
    // Total sports teams
    $stmt = $pdo->query("SELECT COUNT(*) as total_teams FROM sports_teams WHERE status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_teams = $result['total_teams'] ?? 0;
    
    // Total team members (sum of members_count from active teams)
    $stmt = $pdo->query("SELECT COALESCE(SUM(members_count), 0) as total_members FROM sports_teams WHERE status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_members = $result['total_members'] ?? 0;
    
    // Upcoming competitions
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_competitions FROM sports_competitions WHERE status = 'upcoming' AND start_date >= CURRENT_DATE");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $upcoming_competitions = $result['upcoming_competitions'] ?? 0;
    
    // Total entertainment clubs - Check if table exists first
    $stmt = $pdo->query("SELECT COUNT(*) as total_clubs FROM entertainment_clubs WHERE status = 'active'");
    if ($stmt) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_clubs = $result['total_clubs'] ?? 0;
    } else {
        $total_clubs = 0;
    }
    
    // Total students (for participation rate)
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $result['total_students'] ?? 0;
    
    // Active training sessions this week
    $stmt = $pdo->query("
        SELECT COUNT(*) as active_trainings 
        FROM training_sessions 
        WHERE session_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '7 days')
        AND status = 'scheduled'
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $active_trainings = $result['active_trainings'] ?? 0;
    
    // Recent competitions
    $stmt = $pdo->prepare("
        SELECT * FROM sports_competitions 
        WHERE start_date >= (CURRENT_DATE - INTERVAL '30 days')
        ORDER BY start_date DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active teams with member counts
    $stmt = $pdo->query("
        SELECT 
            st.*,
            st.members_count as member_count,
            u1.full_name as coach_name,
            u2.full_name as captain_name
        FROM sports_teams st 
        LEFT JOIN users u1 ON st.coach_id = u1.id
        LEFT JOIN users u2 ON st.captain_id = u2.id
        WHERE st.status = 'active'
        ORDER BY st.sport_type, st.team_name
        LIMIT 6
    ");
    $active_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming training sessions
    $stmt = $pdo->prepare("
        SELECT ts.*, st.team_name
        FROM training_sessions ts
        LEFT JOIN sports_teams st ON ts.team_id = st.id
        WHERE ts.session_date >= CURRENT_DATE
        AND ts.status = 'scheduled'
        ORDER BY ts.session_date ASC, ts.start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activities (login activities) - Check if table exists first
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        ORDER BY la.login_time DESC 
        LIMIT 8
    ");
    if ($stmt) {
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Unread messages
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $unread_messages = $result['unread_messages'] ?? 0;
    } catch (PDOException $e) {
        $unread_messages = 0;
        error_log("Unread messages query error: " . $e->getMessage());
    }
    
    // Sports equipment status
    $stmt = $pdo->query("
        SELECT 
            equipment_condition,
            COUNT(*) as equipment_count
        FROM sports_equipment 
        GROUP BY equipment_condition
        ORDER BY equipment_count DESC
    ");
    $equipment_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Team performance statistics (top teams by members_count)
    $stmt = $pdo->query("
        SELECT 
            st.team_name,
            st.sport_type,
            st.members_count as member_count
        FROM sports_teams st
        WHERE st.status = 'active'
        ORDER BY st.members_count DESC
        LIMIT 5
    ");
    $team_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent entertainment events - Check if table exists first
    $stmt = $pdo->query("SELECT COUNT(*) FROM entertainment_events");
    if ($stmt && $stmt->fetchColumn() !== false) {
        $stmt = $pdo->prepare("
            SELECT * FROM entertainment_events 
            WHERE event_date >= (CURRENT_DATE - INTERVAL '30 days')
            ORDER BY event_date DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $recent_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Pending tickets count for sports-related issues
    $category_id = 6; // Assuming 6 is the ID for Sports & Entertainment category
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE category_id = ? 
        AND status IN ('open', 'in_progress')
    ");
    $stmt->execute([$category_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending_tickets = $result['pending_tickets'] ?? 0;
    
    // Total sports facilities
    $stmt = $pdo->query("SELECT COUNT(*) as total_facilities FROM sports_facilities WHERE status = 'available'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_facilities = $result['total_facilities'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Minister of Sports and Entertainment dashboard statistics error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Minister of Sports & Entertainment Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #3B82F6;
            --secondary-blue: #60A5FA;
            --accent-blue: #1D4ED8;
            --light-blue: #EFF6FF;
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
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
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
        .sidebar.collapsed .menu-badge,
        .sidebar.collapsed .member-count {
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

        .member-count {
            background: var(--primary-blue);
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

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
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

        /* Tables */
        .table-responsive {
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
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-upcoming {
            background: #cce7ff;
            color: #004085;
        }

        .status-ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
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
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.7rem;
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
            margin-top: 1rem;
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
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.75rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.8rem;
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

        /* Team Stats */
        .team-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .team-stat {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .team-name {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .team-count {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Progress Bars */
        .progress-bar {
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Animations */
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

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 4rem;
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

            .content-grid {
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

            .quick-actions {
                grid-template-columns: 1fr;
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
                <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Minister of Sports & Entertainment</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Sports & Entertainment</div>
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
                    <a href="teams.php">
                        <i class="fas fa-users"></i>
                        <span>Sports Teams</span>
                        <?php if ($total_teams > 0): ?>
                            <span class="member-count"><?php echo $total_teams; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php">
                        <i class="fas fa-building"></i>
                        <span>Sports Facilities</span>
                        <?php if ($total_facilities > 0): ?>
                            <span class="member-count"><?php echo $total_facilities; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-music"></i>
                        <span>Entertainment Clubs</span>
                        <?php if ($total_clubs > 0): ?>
                            <span class="member-count"><?php echo $total_clubs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="competitions.php">
                        <i class="fas fa-trophy"></i>
                        <span>Competitions</span>
                        <?php if ($upcoming_competitions > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_competitions; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="equipment.php">
                        <i class="fas fa-baseball-ball"></i>
                        <span>Equipment</span>
                        <?php 
                        $maintenance_count = 0;
                        foreach ($equipment_status as $equipment) {
                            if ($equipment['equipment_condition'] === 'poor' || $equipment['equipment_condition'] === 'needs_replacement') {
                                $maintenance_count += $equipment['equipment_count'];
                            }
                        }
                        if ($maintenance_count > 0): ?>
                            <span class="menu-badge"><?php echo $maintenance_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Funding & Budget</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="training.php">
                        <i class="fas fa-running"></i>
                        <span>Training</span>
                        <?php if ($active_trainings > 0): ?>
                            <span class="member-count"><?php echo $active_trainings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
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
                    <h1>Welcome, Minister <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
                   
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
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_teams); ?></div>
                        <div class="stat-label">Active Sports Teams</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_members); ?></div>
                        <div class="stat-label">Team Members</div>
                    </div>
                </div>
                <!-- <div class="stat-card warning">
                     <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div> -->
                    <!-- <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_facilities); ?></div>
                        <div class="stat-label">Sports Facilities</div>
                    </div> 
                </div> -->
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 0;">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-music"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_clubs); ?></div>
                        <div class="stat-label">Entertainment Clubs</div>
                    </div>
                </div>
                <!-- <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-running"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($active_trainings); ?></div>
                        <div class="stat-label">Training Sessions (Week)</div>
                    </div>
                </div> -->
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $maintenance_count = 0;
                            foreach ($equipment_status as $equipment) {
                                if ($equipment['equipment_condition'] === 'poor' || $equipment['equipment_condition'] === 'needs_replacement') {
                                    $maintenance_count += $equipment['equipment_count'];
                                }
                            }
                            echo number_format($maintenance_count);
                            ?>
                        </div>
                        <div class="stat-label">Equipment Needs Maintenance</div>
                    </div>
                </div>
                <!-- <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($upcoming_competitions); ?></div>
                        <div class="stat-label">Upcoming Competitions</div>
                    </div>
                </div> -->
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Active Sports Teams -->
                    <div class="card">
                        <!-- <div class="card-header">
                            <h3><i class="fas fa-users"></i> Active Sports Teams</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="teams.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div> -->
                        <!-- <div class="card-body">
                            <?php if (empty($active_teams)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No active sports teams found</p>
                                    <a href="teams.php?action=add" style="color: var(--primary-blue); text-decoration: none; font-weight: 600;">
                                        <i class="fas fa-plus"></i> Add your first team
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Team Name</th>
                                                <th>Sport Type</th>
                                                <th>Category</th>
                                                <th>Members</th>
                                                <th>Coach</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_teams as $team): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($team['team_name']); ?></strong>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                            Captain: <?php echo htmlspecialchars($team['captain_name'] ?? 'Not assigned'); ?>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($team['sport_type']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars(ucfirst($team['team_gender'])); ?><br>
                                                        <small style="color: var(--dark-gray); font-size: 0.7rem;">
                                                            <?php echo htmlspecialchars(ucfirst($team['category'] ?? 'General')); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo $team['member_count'] ?? 0; ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($team['coach_name'] ?? 'Not assigned'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div> -->

                    <!-- Upcoming Training Sessions -->
                    <!-- <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-running"></i> Upcoming Training Sessions</h3>
                            <div class="card-header-actions">
                                <a href="training.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_trainings)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-running"></i>
                                    <p>No upcoming training sessions</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Training Session</th>
                                                <th>Team</th>
                                                <th>Date & Time</th>
                                                <th>Duration</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($upcoming_trainings as $training): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($training['title']); ?></td>
                                                    <td><?php echo htmlspecialchars($training['team_name'] ?? 'General'); ?></td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($training['session_date'])); ?><br>
                                                        <small><?php echo date('g:i A', strtotime($training['start_time'])); ?></small>
                                                    </td>
                                                    <td>2 hours</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div> -->

                    <!-- Recent Events & Competitions -->
                    <!-- <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-trophy"></i> Recent Events & Competitions</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_competitions) && empty($recent_events)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-alt"></i>
                                    <p>No recent events or competitions</p>
                                </div>
                            <?php else: ?>
                                <div class="team-stats">
                                    <?php foreach ($recent_competitions as $competition): ?>
                                        <div class="team-stat">
                                            <div class="team-name"><?php echo htmlspecialchars($competition['title']); ?></div>
                                            <div class="team-count">🏆 <?php echo htmlspecialchars($competition['sport_type']); ?></div>
                                            <div style="font-size: 0.6rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo date('M j', strtotime($competition['start_date'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php foreach ($recent_events as $event): ?>
                                        <div class="team-stat">
                                            <div class="team-name"><?php echo htmlspecialchars($event['event_name'] ?? 'Event'); ?></div>
                                            <div class="team-count">🎭 <?php echo htmlspecialchars($event['event_type'] ?? 'Entertainment'); ?></div>
                                            <div style="font-size: 0.6rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo date('M j', strtotime($event['event_date'] ?? date('Y-m-d'))); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div> -->

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="teams.php?action=add" class="action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span class="action-label">Add Team</span>
                        </a>
                        <a href="facilities.php?action=add" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span class="action-label">Add Facility</span>
                        </a>
                        <a href="funding.php?action=request" class="action-btn">
                            <i class="fas fa-money-bill-wave"></i>
                            <span class="action-label">Request Funding</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Generate Report</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Activities -->
                    <!-- <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Recent System Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li class="empty-state" style="list-style: none; padding: 1rem;">No recent activities</li>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> logged in
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, g:i A', strtotime($activity['login_time'])); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div> -->

                    <!-- Team Performance Leaders -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-line"></i> Top Teams by Membership</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($team_performance)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-line"></i>
                                    <p>No team data available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($team_performance as $team): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($team['team_name']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($team['sport_type']); ?></div>
                                            </div>
                                            <div style="text-align: right;">
                                                <div style="font-size: 0.8rem; font-weight: 600;">
                                                    <?php echo $team['member_count']; ?> members
                                                </div>
                                            </div>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min(100, ($team['member_count'] / 30) * 100); ?>%"></div>
                                        </div>
                                        <div class="progress-text">
                                            <span>Capacity</span>
                                            <span><?php echo min(100, round(($team['member_count'] / 30) * 100)); ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-simple"></i> Sports & Entertainment Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Sports Teams</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($total_teams); ?> teams</strong>
                                </div>
                                <!-- <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Sports Facilities</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($total_facilities); ?> facilities</strong>
                                </div> -->
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Total Team Members</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($total_members); ?> members</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Tickets</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($pending_tickets); ?> tickets</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Actions Alert -->
                    <?php if ($pending_tickets > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-ticket-alt"></i> 
                            <strong>Action Required:</strong> You have 
                            <a href="tickets.php"><?php echo $pending_tickets; ?> support tickets pending</a>.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
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
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });
    </script>
</body>
</html>