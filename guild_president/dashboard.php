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

// Get dashboard statistics with proper error handling
try {
    // Total tickets - FIXED: Using correct column name
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];
    
    // Open tickets - FIXED: Using correct column name
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];
    
    // Resolved tickets - FIXED: Using correct column name
    $stmt = $pdo->query("SELECT COUNT(*) as resolved_tickets FROM tickets WHERE status = 'resolved'");
    $resolved_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['resolved_tickets'];
    
    // Total students count - NEW: Get number of students in system
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'];
    
    // Committee members count - FIXED: Using correct role filter
    $stmt = $pdo->query("SELECT COUNT(*) as active_members FROM users WHERE status = 'active' AND role != 'student' AND role != 'admin'");
    $active_members = $stmt->fetch(PDO::FETCH_ASSOC)['active_members'];
    
    // Pending reports - check if table exists first
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'];
    } catch (PDOException $e) {
        error_log("Reports table might not exist: " . $e->getMessage());
        $pending_reports = 0;
    }
    
    // Recent tickets - simplified query
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
        // Create some dummy activities if table doesn't exist
        $recent_activities = [
            ['full_name' => 'System', 'role' => 'system', 'login_time' => date('Y-m-d H:i:s'), 'ip_address' => '127.0.0.1']
        ];
    }
    
    // Unread messages - check if conversation_messages exists
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
    
    // Pending documents for approval - check if table exists
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];
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
    
    // NEW: Get trend data for graphs
    // Ticket trends (last 7 days)
    try {
        $stmt = $pdo->query("
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM tickets 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
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
    
    // Event participation trends
    try {
        $stmt = $pdo->query("
            SELECT e.title, COUNT(er.id) as participants
            FROM events e
            LEFT JOIN event_registrations er ON e.id = er.event_id
            WHERE e.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
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
    } catch (PDOException $e) {
        error_log("Financial overview query error: " . $e->getMessage());
        $financial_overview = ['total_income' => 0, 'total_expenses' => 0, 'total_transactions' => 0];
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
}

// Calculate additional metrics
$resolution_rate = $total_tickets > 0 ? round(($resolved_tickets / $total_tickets) * 100) : 0;

// Get high priority tickets count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as high_priority FROM tickets WHERE priority = 'high' AND status = 'open'");
    $high_priority_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['high_priority'];
} catch (PDOException $e) {
    $high_priority_tickets = 0;
}

// Get overdue tickets (past due date)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as overdue_tickets FROM tickets WHERE due_date < CURDATE() AND status NOT IN ('resolved', 'closed')");
    $overdue_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['overdue_tickets'];
} catch (PDOException $e) {
    $overdue_tickets = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            background-color: var(--primary-blue);
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        /* Chart Containers */
        .chart-container {
            height: 250px;
            margin-bottom: 1rem;
            position: relative;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
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
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - President</h1>
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
        <nav class="sidebar">
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
                        <?php 
                        // Get new student registrations count (last 7 days)
                        try {
                            $new_students_stmt = $pdo->prepare("
                                SELECT COUNT(*) as new_students 
                                FROM users 
                                WHERE role = 'student' 
                                AND status = 'active' 
                                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            ");
                            $new_students_stmt->execute();
                            $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'];
                        } catch (PDOException $e) {
                            $new_students = 0;
                        }
                        ?>
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
                    <h1>Welcome, President <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 👑</h1>
                    <p>Complete oversight of all RPSU activities and committee performance</p>
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
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_tickets; ?></div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_tickets; ?></div>
                        <div class="stat-label">Pending Tickets</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolved_tickets; ?></div>
                        <div class="stat-label">Resolved Tickets</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_reports; ?></div>
                        <div class="stat-label">Reports to Review</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $high_priority_tickets; ?></div>
                        <div class="stat-label">High Priority</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $overdue_tickets; ?></div>
                        <div class="stat-label">Overdue</div>
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

                <!-- Department Distribution Chart -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3>Student Distribution by Department</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="departmentDistributionChart"></canvas>
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
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No pending reports</p>
                                </div>
                            <?php else: ?>
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
                    <!-- Recent Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Committee Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent activities</li>
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
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Total Students</span>
                                    <strong style="color: var(--text-dark);"><?php echo $total_students; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo $unread_messages; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Documents</span>
                                    <strong style="color: var(--text-dark);"><?php echo $pending_docs; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Committee</span>
                                    <strong style="color: var(--text-dark);"><?php echo $active_members; ?> members</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Financial Balance</span>
                                    <strong style="color: var(--text-dark);"><?php echo number_format($financial_overview['total_income'] - $financial_overview['total_expenses']); ?> RWF</strong>
                                </div>
                            </div>
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
            // You can add auto-refresh logic here
            console.log('Dashboard auto-refresh triggered');
        }, 180000);

        // Charts initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Ticket Trends Chart
            const ticketTrendsCtx = document.getElementById('ticketTrendsChart').getContext('2d');
            const ticketTrendsChart = new Chart(ticketTrendsCtx, {
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

            // Department Distribution Chart
            const departmentDistributionCtx = document.getElementById('departmentDistributionChart').getContext('2d');
            const departmentDistributionChart = new Chart(departmentDistributionCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php 
                        $departments = [];
                        foreach ($department_distribution as $dept) {
                            $departments[] = "'" . $dept['name'] . "'";
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

            // Committee Performance Chart
            const committeePerformanceCtx = document.getElementById('committeePerformanceChart').getContext('2d');
            const committeePerformanceChart = new Chart(committeePerformanceCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php 
                        $committeeNames = [];
                        foreach ($committee_performance as $member) {
                            $committeeNames[] = "'" . $member['full_name'] . "'";
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

            // Event Participation Chart
            const eventParticipationCtx = document.getElementById('eventParticipationChart').getContext('2d');
            const eventParticipationChart = new Chart(eventParticipationCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php 
                        $eventTitles = [];
                        foreach ($event_participation as $event) {
                            // Shorten long event titles
                            $title = strlen($event['title']) > 20 ? substr($event['title'], 0, 20) . '...' : $event['title'];
                            $eventTitles[] = "'" . $title . "'";
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
        });
    </script>
</body>
</html>