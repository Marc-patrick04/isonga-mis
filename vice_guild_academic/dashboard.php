<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

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

// Get dashboard statistics with proper error handling
try {
    // Academic tickets (category_id = 1 for Academic Issues)
    $stmt = $pdo->query("SELECT COUNT(*) as academic_tickets FROM tickets WHERE category_id = 1");
    $academic_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['academic_tickets'];
    
    // Open academic tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_academic_tickets FROM tickets WHERE category_id = 1 AND status = 'open'");
    $open_academic_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_academic_tickets'];
    
    // Resolved academic tickets
    $stmt = $pdo->query("SELECT COUNT(*) as resolved_academic_tickets FROM tickets WHERE category_id = 1 AND status = 'resolved'");
    $resolved_academic_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['resolved_academic_tickets'];
    
    // Academic reports - using the correct report_type and user role
    $stmt = $pdo->query("SELECT COUNT(*) as academic_reports FROM reports WHERE report_type = 'academic' OR user_id IN (SELECT id FROM users WHERE role = 'vice_guild_academic')");
    $academic_reports = $stmt->fetch(PDO::FETCH_ASSOC)['academic_reports'];
    
    // Recent academic tickets
    try {
        $stmt = $pdo->query("
            SELECT t.*, c.name as category_name, u.full_name as assigned_name,
                   d.name as department_name, p.name as program_name
            FROM tickets t 
            LEFT JOIN issue_categories c ON t.category_id = c.id 
            LEFT JOIN users u ON t.assigned_to = u.id 
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN programs p ON t.program_id = p.id
            WHERE t.category_id = 1
            ORDER BY t.created_at DESC 
            LIMIT 5
        ");
        $recent_academic_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Recent academic tickets query error: " . $e->getMessage());
        $recent_academic_tickets = [];
    }
    
    // Academic clubs and activities - using the clubs table
    try {
        $stmt = $pdo->query("
            SELECT c.*, u.full_name as created_by_name, d.name as department_name
            FROM clubs c 
            LEFT JOIN users u ON c.created_by = u.id 
            LEFT JOIN departments d ON c.department = d.code
            WHERE c.category = 'academic'
            ORDER BY c.created_at DESC 
            LIMIT 5
        ");
        $academic_clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Academic clubs query error: " . $e->getMessage());
        $academic_clubs = [];
    }
    
    // Academic reports for activities
    try {
        $stmt = $pdo->query("
            SELECT r.*, u.full_name, rt.name as template_name
            FROM reports r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN report_templates rt ON r.template_id = rt.id
            WHERE r.report_type = 'academic' OR r.user_id = $user_id
            ORDER BY r.created_at DESC 
            LIMIT 5
        ");
        $academic_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Academic activities query error: " . $e->getMessage());
        $academic_activities = [];
    }

    // Innovation projects - using innovation_projects table
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as innovation_projects FROM innovation_projects WHERE status IN ('approved', 'in_progress')");
        $innovation_projects = $stmt->fetch(PDO::FETCH_ASSOC)['innovation_projects'];
    } catch (PDOException $e) {
        error_log("Innovation projects query error: " . $e->getMessage());
        $innovation_projects = 0;
    }
    
    // Recent activities - using login_activities and focusing on academic committee
    $recent_activities = [];
    try {
        $stmt = $pdo->query("
            SELECT la.*, u.full_name, u.role, u.department_id, d.name as department_name
            FROM login_activities la 
            JOIN users u ON la.user_id = u.id 
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.role LIKE '%academic%' OR u.role = 'vice_guild_academic'
            ORDER BY la.login_time DESC 
            LIMIT 8
        ");
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Login activities query error: " . $e->getMessage());
        $recent_activities = [];
    }
    
    // Unread messages - using conversation_messages and conversation_participants
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    } catch (PDOException $e) {
        error_log("Messages query error: " . $e->getMessage());
        $unread_messages = 0;
    }
    
    // Additional statistics for better dashboard
    try {
        // Academic tickets by priority
        $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM tickets WHERE category_id = 1 AND status = 'open' GROUP BY priority");
        $priority_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Academic tickets by department
        $stmt = $pdo->query("
            SELECT d.name as department, COUNT(*) as count 
            FROM tickets t 
            JOIN departments d ON t.department_id = d.id
            WHERE t.category_id = 1 AND t.status = 'open' 
            GROUP BY t.department_id, d.name
            ORDER BY count DESC 
            LIMIT 5
        ");
        $department_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // High priority academic tickets
        $stmt = $pdo->query("SELECT COUNT(*) as high_priority FROM tickets WHERE category_id = 1 AND priority = 'high' AND status = 'open'");
        $high_priority_academic = $stmt->fetch(PDO::FETCH_ASSOC)['high_priority'];
        
        // Overdue academic tickets
        $stmt = $pdo->query("SELECT COUNT(*) as overdue_academic FROM tickets WHERE category_id = 1 AND due_date < CURDATE() AND status NOT IN ('resolved', 'closed')");
        $overdue_academic = $stmt->fetch(PDO::FETCH_ASSOC)['overdue_academic'];
        
    } catch (PDOException $e) {
        error_log("Additional stats error: " . $e->getMessage());
        $priority_stats = $department_stats = [];
        $high_priority_academic = $overdue_academic = 0;
    }
    
    // Get committee members for academic roles
    try {
        $stmt = $pdo->query("
            SELECT cm.*, d.name as department_name, p.name as program_name
            FROM committee_members cm
            LEFT JOIN departments d ON cm.department_id = d.id
            LEFT JOIN programs p ON cm.program_id = p.id
            WHERE cm.role LIKE '%academic%' AND cm.status = 'active'
            ORDER BY cm.role_order
        ");
        $academic_committee = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Committee members query error: " . $e->getMessage());
        $academic_committee = [];
    }
    
} catch (PDOException $e) {
    // Handle general error
    error_log("Dashboard statistics error: " . $e->getMessage());
    $academic_tickets = $open_academic_tickets = $resolved_academic_tickets = $academic_reports = $unread_messages = $innovation_projects = 0;
    $recent_academic_tickets = $academic_clubs = $academic_activities = $recent_activities = $academic_committee = [];
    $priority_stats = $department_stats = [];
    $high_priority_academic = $overdue_academic = 0;
}

// Calculate additional metrics
$academic_resolution_rate = $academic_tickets > 0 ? round(($resolved_academic_tickets / $academic_tickets) * 100) : 0;

// Calculate average performance from reports (simplified)
$avg_performance = 4.2; // Default value - you can calculate this from actual data
$total_students = 1500; // Default value

// Get today's academic events
try {
    $stmt = $pdo->query("
        SELECT ae.*, u.full_name as created_by_name
        FROM academic_events ae
        LEFT JOIN users u ON ae.created_by = u.id
        WHERE ae.event_date = CURDATE() AND ae.event_type IN ('exam', 'workshop', 'deadline')
        ORDER BY ae.start_time
        LIMIT 3
    ");
    $todays_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Today's events query error: " . $e->getMessage());
    $todays_events = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vice Guild Academic Dashboard - Isonga RPSU</title>
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
            --academic-primary: #4CAF50;
            --academic-secondary: #66BB6A;
            --academic-accent: #2E7D32;
            --academic-light: #1B3E1B;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
            color: var(--academic-primary);
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
            border-color: var(--academic-primary);
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
            background: var(--academic-primary);
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

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
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
            background: var(--academic-light);
            border-left-color: var(--academic-primary);
            color: var(--academic-primary);
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
            border-left: 3px solid var(--academic-primary);
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
            background: var(--academic-light);
            color: var(--academic-primary);
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

        .status-open {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-pending {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-in-progress {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-approved {
            background: #d4edda;
            color: var(--success);
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
            border-color: var(--academic-primary);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--academic-primary);
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

        /* Dark Mode Toggle */
        .theme-toggle {
            position: relative;
            width: 50px;
            height: 26px;
        }

        .theme-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--medium-gray);
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--academic-primary);
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        /* Performance Meter */
        .performance-meter {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .performance-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--academic-primary);
        }

        .performance-label {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Event List */
        .event-list {
            list-style: none;
        }

        .event-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .event-item:last-child {
            border-bottom: none;
        }

        .event-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--academic-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--academic-primary);
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .event-content {
            flex: 1;
        }

        .event-title {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
            font-weight: 500;
        }

        .event-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Committee List */
        .committee-list {
            list-style: none;
        }

        .committee-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .committee-item:last-child {
            border-bottom: none;
        }

        .committee-avatar {
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

        .committee-content {
            flex: 1;
        }

        .committee-name {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.1rem;
            font-weight: 500;
        }

        .committee-role {
            font-size: 0.7rem;
            color: var(--dark-gray);
            text-transform: capitalize;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

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
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
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
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Affairs</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
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
                        <div class="user-role">Vice Guild Academic</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
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
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <!-- In the sidebar section, add this menu item -->
<li class="menu-item">
    <a href="academic_meetings.php">
        <i class="fas fa-calendar-check"></i>
        <span>Meetings</span>
        <?php
        // Count upcoming meetings where user is invited
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as upcoming_meetings 
                FROM meeting_attendees ma 
                JOIN meetings m ON ma.meeting_id = m.id 
                WHERE ma.user_id = ? 
                AND m.meeting_date >= CURDATE() 
                AND m.status = 'scheduled'
                AND ma.attendance_status = 'invited'
            ");
            $stmt->execute([$user_id]);
            $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'];
        } catch (PDOException $e) {
            $upcoming_meetings = 0;
        }
        ?>
        <?php if ($upcoming_meetings > 0): ?>
            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
        <?php endif; ?>
    </a>
</li>
                <li class="menu-item">
                    <a href="academic_tickets.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academic Tickets</span>
                        <?php if ($open_academic_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_academic_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Academic Reports</span>
                        <?php if ($academic_reports > 0): ?>
                            <span class="menu-badge"><?php echo $academic_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
                        <span class="menu-badge"><?php echo count($academic_clubs); ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="performance_tracking.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Performance Tracking</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="innovation_projects.php">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
                        <?php if ($innovation_projects > 0): ?>
                            <span class="menu-badge"><?php echo $innovation_projects; ?></span>
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
                    <a href="academic_calendar.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Welcome, Vice Guild Academic <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 📚</h1>
                    <p>Overseeing academic innovation, performance tracking, and academic issues resolution</p>
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
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $academic_tickets; ?></div>
                        <div class="stat-label">Academic Tickets</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_academic_tickets; ?></div>
                        <div class="stat-label">Pending Academic</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolved_academic_tickets; ?></div>
                        <div class="stat-label">Resolved Academic</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $academic_reports; ?></div>
                        <div class="stat-label">Academic Reports</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $high_priority_academic; ?></div>
                        <div class="stat-label">High Priority</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $overdue_academic; ?></div>
                        <div class="stat-label">Overdue Academic</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $academic_resolution_rate; ?>%</div>
                        <div class="stat-label">Resolution Rate</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $innovation_projects; ?></div>
                        <div class="stat-label">Innovation Projects</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Academic Tickets -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Academic Issues</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="academic_tickets.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Subject</th>
                                        <th>Student</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_academic_tickets)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent academic tickets</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_academic_tickets as $ticket): ?>
                                            <tr>
                                                <td>#<?php echo $ticket['id']; ?></td>
                                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($ticket['subject']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($ticket['name']); ?></td>
                                                <td>
                                                    <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                                        <?php echo ucfirst($ticket['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo str_replace(' ', '_', $ticket['status']); ?>">
                                                        <?php echo ucfirst($ticket['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Academic Clubs -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Academic Clubs</h3>
                            <div class="card-header-actions">
                                <a href="academic_clubs.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($academic_clubs)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No academic clubs found</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Club Name</th>
                                            <th>Department</th>
                                            <th>Members</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($academic_clubs as $club): ?>
                                            <tr>
                                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($club['name']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($club['department_name'] ?? $club['department']); ?></td>
                                                <td><?php echo $club['members_count']; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $club['status']; ?>">
                                                        <?php echo ucfirst($club['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <!-- In the quick actions section, add this button -->
<a href="academic_meetings.php" class="action-btn">
    <i class="fas fa-calendar-check"></i>
    <span class="action-label">Meetings</span>
</a>
                        <a href="academic_tickets.php?new=true" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span class="action-label">New Academic Issue</span>
                        </a>
                        <a href="academic_reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Academic Reports</span>
                        </a>
                        <a href="academic_clubs.php" class="action-btn">
                            <i class="fas fa-user-graduate"></i>
                            <span class="action-label">Club Visits</span>
                        </a>
                        <a href="performance_tracking.php" class="action-btn">
                            <i class="fas fa-trophy"></i>
                            <span class="action-label">Performance Review</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Today's Academic Events -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Today's Academic Events</h3>
                            <a href="academic_calendar.php" class="card-header-btn" title="View Calendar">
                                <i class="fas fa-calendar-alt"></i>
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($todays_events)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No academic events today</p>
                                </div>
                            <?php else: ?>
                                <ul class="event-list">
                                    <?php foreach ($todays_events as $event): ?>
                                        <li class="event-item">
                                            <div class="event-icon">
                                                <i class="fas fa-calendar-check"></i>
                                            </div>
                                            <div class="event-content">
                                                <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                                <div class="event-time">
                                                    <?php echo date('g:i A', strtotime($event['start_time'])); ?> 
                                                    • <?php echo htmlspecialchars($event['location']); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Add this card in the right column after the "Today's Academic Events" card -->
<div class="card">
    <div class="card-header">
        <h3>Upcoming Meetings</h3>
        <a href="academic_meetings.php" class="card-header-btn" title="View All Meetings">
            <i class="fas fa-external-link-alt"></i>
        </a>
    </div>
    <div class="card-body">
        <?php
        // Get upcoming meetings for the user
        try {
            $stmt = $pdo->prepare("
                SELECT m.*, ma.attendance_status, u.full_name as chairperson_name
                FROM meeting_attendees ma 
                JOIN meetings m ON ma.meeting_id = m.id 
                JOIN users u ON m.chairperson_id = u.id
                WHERE ma.user_id = ? 
                AND m.meeting_date >= CURDATE() 
                AND m.status = 'scheduled'
                ORDER BY m.meeting_date, m.start_time 
                LIMIT 3
            ");
            $stmt->execute([$user_id]);
            $upcoming_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $upcoming_meetings = [];
        }
        ?>
        
        <?php if (empty($upcoming_meetings)): ?>
            <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                <p>No upcoming meetings</p>
            </div>
        <?php else: ?>
            <ul class="event-list">
                <?php foreach ($upcoming_meetings as $meeting): ?>
                    <li class="event-item">
                        <div class="event-icon" style="background: <?php 
                            switch($meeting['attendance_status']) {
                                case 'confirmed': echo '#d4edda'; break;
                                case 'declined': echo '#f8d7da'; break;
                                default: echo '#fff3cd'; break;
                            }
                        ?>; color: <?php 
                            switch($meeting['attendance_status']) {
                                case 'confirmed': echo '#28a745'; break;
                                case 'declined': echo '#dc3545'; break;
                                default: echo '#ffc107'; break;
                            }
                        ?>;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="event-content">
                            <div class="event-title"><?php echo htmlspecialchars($meeting['title']); ?></div>
                            <div class="event-time">
                                <?php echo date('M j, g:i A', strtotime($meeting['meeting_date'] . ' ' . $meeting['start_time'])); ?>
                                <br>
                                <small>
                                    Status: 
                                    <span style="color: <?php 
                                        switch($meeting['attendance_status']) {
                                            case 'confirmed': echo '#28a745'; break;
                                            case 'declined': echo '#dc3545'; break;
                                            default: echo '#ffc107'; break;
                                        }
                                    ?>; font-weight: 600;">
                                        <?php echo ucfirst($meeting['attendance_status']); ?>
                                    </span>
                                </small>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

                    <!-- Academic Committee -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Academic Committee</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($academic_committee)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No committee members found</p>
                                </div>
                            <?php else: ?>
                                <ul class="committee-list">
                                    <?php foreach ($academic_committee as $member): ?>
                                        <li class="committee-item">
                                            <div class="committee-avatar">
                                                <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                            </div>
                                            <div class="committee-content">
                                                <div class="committee-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                                <div class="committee-role"><?php echo str_replace('_', ' ', $member['role']); ?></div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 1rem;">No recent activities</li>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> 
                                                    logged in
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
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        // Check for saved theme preference or respect OS preference
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

        // Auto-refresh dashboard every 3 minutes
        setInterval(() => {
            window.location.reload();
        }, 180000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>