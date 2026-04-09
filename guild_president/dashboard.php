<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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

// Get dashboard statistics with proper error handling (PostgreSQL syntax)
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_tickets = $result['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $open_tickets = $result['open_tickets'] ?? 0;
    
    // Resolved tickets
    $stmt = $pdo->query("SELECT COUNT(*) as resolved_tickets FROM tickets WHERE status = 'resolved'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $resolved_tickets = $result['resolved_tickets'] ?? 0;
    
    // Total students count
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $result['total_students'] ?? 0;
    
    // Committee members count
    $stmt = $pdo->query("SELECT COUNT(*) as active_members FROM users WHERE status = 'active' AND role != 'student' AND role != 'admin'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $active_members = $result['active_members'] ?? 0;
    
    // Pending reports - check if table exists first
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pending_reports = $result['pending_reports'] ?? 0;
    } catch (PDOException $e) {
        error_log("Reports table might not exist: " . $e->getMessage());
        $pending_reports = 0;
    }
    
    // Recent tickets - simplified query with PostgreSQL ILIKE
    try {
        $stmt = $pdo->query("
            SELECT t.*, ic.name as category_name, u.full_name as assigned_name 
            FROM tickets t 
            LEFT JOIN issue_categories ic ON t.category_id = ic.id 
            LEFT JOIN users u ON t.assigned_to = u.id 
            ORDER BY t.created_at DESC 
            LIMIT 5
        ");
        $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Recent tickets query error: " . $e->getMessage());
        $recent_tickets = [];
        // Try simpler query as fallback
        try {
            $stmt = $pdo->query("SELECT * FROM tickets ORDER BY created_at DESC LIMIT 5");
            $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $recent_tickets = [];
        }
    }
    
    // Pending reports for review - check if table exists
    $pending_reports_list = [];
    try {
        $stmt = $pdo->query("
            SELECT r.*, u.full_name, u.role 
            FROM reports r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.status = 'submitted' 
            ORDER BY r.submitted_at DESC 
            LIMIT 5
        ");
        $pending_reports_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Pending reports query error: " . $e->getMessage());
        $pending_reports_list = [];
    }
    
    // Recent activities - check if table exists
    $recent_activities = [];
    try {
        $stmt = $pdo->query("
            SELECT la.*, u.full_name, u.role 
            FROM login_activities la 
            JOIN users u ON la.user_id = u.id 
            ORDER BY la.login_time DESC 
            LIMIT 8
        ");
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Login activities query error: " . $e->getMessage());
        $recent_activities = [];
    }
    
    // Unread messages
    $unread_messages = 0;
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
        error_log("Messages query error: " . $e->getMessage());
        $unread_messages = 0;
    }
    
    // Pending documents for approval - check if table exists
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pending_docs = $result['pending_docs'] ?? 0;
    } catch (PDOException $e) {
        error_log("Documents table might not exist: " . $e->getMessage());
        $pending_docs = 0;
    }
    
    // Additional statistics for better dashboard
    try {
        // Tickets by priority
        $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM tickets WHERE status = 'open' GROUP BY priority");
        $priority_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Tickets by category
        $stmt = $pdo->query("
            SELECT ic.name, COUNT(*) as count 
            FROM tickets t 
            JOIN issue_categories ic ON t.category_id = ic.id 
            WHERE t.status = 'open' 
            GROUP BY ic.name 
            ORDER BY count DESC 
            LIMIT 5
        ");
        $category_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Additional stats error: " . $e->getMessage());
        $priority_stats = $category_stats = [];
    }
    
    // Get trend data for graphs (PostgreSQL syntax)
    // Ticket trends (last 7 days) - PostgreSQL uses INTERVAL
    try {
        $stmt = $pdo->query("
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM tickets 
            WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
            GROUP BY DATE(created_at) 
            ORDER BY date
        ");
        $ticket_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ticket trends query error: " . $e->getMessage());
        $ticket_trends = [];
    }
    
    // Committee performance trends
    try {
        $stmt = $pdo->query("
            SELECT u.full_name, COUNT(t.id) as resolved_tickets
            FROM users u 
            LEFT JOIN tickets t ON u.id = t.assigned_to AND t.status = 'resolved'
            WHERE u.role != 'student' AND u.role != 'admin'
            GROUP BY u.id
            ORDER BY resolved_tickets DESC
            LIMIT 5
        ");
        $committee_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Committee performance query error: " . $e->getMessage());
        $committee_performance = [];
    }
    
    // Department-wise student distribution
    try {
        $stmt = $pdo->query("
            SELECT d.name, COUNT(u.id) as student_count
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.role = 'student' AND u.status = 'active'
            GROUP BY d.id, d.name
            ORDER BY student_count DESC
        ");
        $department_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Department distribution query error: " . $e->getMessage());
        $department_distribution = [];
    }
    
    // Event participation trends - PostgreSQL uses INTERVAL
    try {
        $stmt = $pdo->query("
            SELECT e.title, COUNT(er.id) as participants
            FROM events e
            LEFT JOIN event_registrations er ON e.id = er.event_id
            WHERE e.event_date >= CURRENT_DATE - INTERVAL '30 days'
            GROUP BY e.id, e.title
            ORDER BY participants DESC
            LIMIT 5
        ");
        $event_participation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Event participation query error: " . $e->getMessage());
        $event_participation = [];
    }
    
    // Financial overview
    try {
        $stmt = $pdo->query("
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as total_income,
                SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as total_expenses,
                COUNT(*) as total_transactions
            FROM financial_transactions 
            WHERE status = 'completed'
        ");
        $financial_overview = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$financial_overview) {
            $financial_overview = ['total_income' => 0, 'total_expenses' => 0, 'total_transactions' => 0];
        }
    } catch (PDOException $e) {
        error_log("Financial overview query error: " . $e->getMessage());
        $financial_overview = ['total_income' => 0, 'total_expenses' => 0, 'total_transactions' => 0];
    }
    
    // Get new student registrations count (last 7 days)
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= CURRENT_DATE - INTERVAL '7 days'
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_students = $result['new_students'] ?? 0;
    } catch (PDOException $e) {
        error_log("New students query error: " . $e->getMessage());
        $new_students = 0;
    }
    
} catch (PDOException $e) {
    // Handle general error
    error_log("Dashboard statistics error: " . $e->getMessage());
    $total_tickets = $open_tickets = $resolved_tickets = $total_students = $pending_reports = $unread_messages = $pending_docs = 0;
    $recent_tickets = $pending_reports_list = $recent_activities = [];
    $priority_stats = $category_stats = [];
    $active_members = 17;
    $ticket_trends = $committee_performance = $department_distribution = $event_participation = [];
    $financial_overview = ['total_income' => 0, 'total_expenses' => 0, 'total_transactions' => 0];
    $new_students = 0;
}

// Calculate additional metrics
$resolution_rate = $total_tickets > 0 ? round(($resolved_tickets / $total_tickets) * 100) : 0;

// Get high priority tickets count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as high_priority FROM tickets WHERE priority = 'high' AND status = 'open'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $high_priority_tickets = $result['high_priority'] ?? 0;
} catch (PDOException $e) {
    $high_priority_tickets = 0;
}

// Get overdue tickets (past due date) - PostgreSQL uses CURRENT_DATE
try {
    $stmt = $pdo->query("SELECT COUNT(*) as overdue_tickets FROM tickets WHERE due_date < CURRENT_DATE AND status NOT IN ('resolved', 'closed')");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $overdue_tickets = $result['overdue_tickets'] ?? 0;
} catch (PDOException $e) {
    $overdue_tickets = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Guild President Dashboard - Isonga RPSU</title>
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

        .status-open {
            background: #fff3cd;
            color: #856404;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-in-progress {
            background: #cce7ff;
            color: #004085;
        }

        .status-resolved {
            background: #d4edda;
            color: #155724;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
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
                    <h1>Isonga - President</h1>
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
                   
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Guild President</div>
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
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
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
                    </a>
                </li>
                 <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Reports</span>
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
                    <h1>Welcome, President <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
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
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_students); ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_tickets); ?></div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($open_tickets); ?></div>
                        <div class="stat-label">Pending Tickets</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($resolved_tickets); ?></div>
                        <div class="stat-label">Resolved Tickets</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($pending_reports); ?></div>
                        <div class="stat-label">Reports to Review</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($high_priority_tickets); ?></div>
                        <div class="stat-label">High Priority</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($overdue_tickets); ?></div>
                        <div class="stat-label">Overdue</div>
                    </div>
                </div>
                
            </div>

            <!-- Charts Grid -->
            <div class="charts-grid">
                <!-- Ticket Trends Chart -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3>Ticket Trends (Last 7 Days)</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="ticketTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Committee Performance Chart -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3>Committee Performance</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="committeePerformanceChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Event Participation Chart -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3>Event Participation</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="eventParticipationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Pending Reports -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Reports Awaiting Approval</h3>
                            <div class="card-header-actions">
                                <a href="reports.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_reports_list)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No pending reports</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Committee Member</th>
                                                <th>Role</th>
                                                <th>Title</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_reports_list as $report): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($report['full_name']); ?></td>
                                                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $report['role'])); ?></td>
                                                    <td><?php echo htmlspecialchars($report['title']); ?></td>
                                                    <td>
                                                        <span class="status-badge status-pending">Pending Review</span>
                                                    </td>
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
                        <a href="students.php" class="action-btn">
                            <i class="fas fa-user-graduate"></i>
                            <span class="action-label">Manage Students</span>
                        </a>
                        <a href="messages.php" class="action-btn">
                            <i class="fas fa-bullhorn"></i>
                            <span class="action-label">Create Announcement</span>
                        </a>
                        <a href="meetings.php" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Schedule Meeting</span>
                        </a>
                        <a href="documents.php" class="action-btn">
                            <i class="fas fa-file-signature"></i>
                            <span class="action-label">Review Documents</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    
                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Total Students</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($total_students); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($unread_messages); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Documents</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($pending_docs); ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Committee</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($active_members); ?> members</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Financial Balance</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format(($financial_overview['total_income'] ?? 0) - ($financial_overview['total_expenses'] ?? 0)); ?> RWF</strong>
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
            // Ticket Trends Chart
            const ticketTrendsCtx = document.getElementById('ticketTrendsChart')?.getContext('2d');
            if (ticketTrendsCtx) {
                new Chart(ticketTrendsCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php 
                            $dates = [];
                            foreach ($ticket_trends as $trend) {
                                $dates[] = "'" . date('M j', strtotime($trend['date'])) . "'";
                            }
                            echo implode(', ', $dates);
                            ?>
                        ],
                        datasets: [{
                            label: 'Tickets Created',
                            data: [
                                <?php 
                                $counts = [];
                                foreach ($ticket_trends as $trend) {
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

            // Department Distribution Chart
            const departmentCtx = document.getElementById('departmentDistributionChart')?.getContext('2d');
            if (departmentCtx) {
                new Chart(departmentCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            <?php 
                            $departments = [];
                            foreach ($department_distribution as $dept) {
                                $departments[] = "'" . addslashes($dept['name']) . "'";
                            }
                            echo implode(', ', $departments);
                            ?>
                        ],
                        datasets: [{
                            data: [
                                <?php 
                                $studentCounts = [];
                                foreach ($department_distribution as $dept) {
                                    $studentCounts[] = $dept['student_count'];
                                }
                                echo implode(', ', $studentCounts);
                                ?>
                            ],
                            backgroundColor: [
                                '#0056b3', '#1e88e5', '#0d47a1', '#64b5f6', '#1976d2',
                                '#2196f3', '#42a5f5', '#90caf9', '#bbdefb', '#e3f2fd'
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

            // Committee Performance Chart
            const committeeCtx = document.getElementById('committeePerformanceChart')?.getContext('2d');
            if (committeeCtx) {
                new Chart(committeeCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php 
                            $committeeNames = [];
                            foreach ($committee_performance as $member) {
                                $committeeNames[] = "'" . addslashes($member['full_name']) . "'";
                            }
                            echo implode(', ', $committeeNames);
                            ?>
                        ],
                        datasets: [{
                            label: 'Resolved Tickets',
                            data: [
                                <?php 
                                $resolvedCounts = [];
                                foreach ($committee_performance as $member) {
                                    $resolvedCounts[] = $member['resolved_tickets'];
                                }
                                echo implode(', ', $resolvedCounts);
                                ?>
                            ],
                            backgroundColor: '#28a745'
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

            // Event Participation Chart
            const eventCtx = document.getElementById('eventParticipationChart')?.getContext('2d');
            if (eventCtx) {
                new Chart(eventCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php 
                            $eventTitles = [];
                            foreach ($event_participation as $event) {
                                $title = strlen($event['title']) > 20 ? substr($event['title'], 0, 20) . '...' : $event['title'];
                                $eventTitles[] = "'" . addslashes($title) . "'";
                            }
                            echo implode(', ', $eventTitles);
                            ?>
                        ],
                        datasets: [{
                            label: 'Participants',
                            data: [
                                <?php 
                                $participantCounts = [];
                                foreach ($event_participation as $event) {
                                    $participantCounts[] = $event['participants'];
                                }
                                echo implode(', ', $participantCounts);
                                ?>
                            ],
                            backgroundColor: '#ffc107'
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
                                    stepSize: 5
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