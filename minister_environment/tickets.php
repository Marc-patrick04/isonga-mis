<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Environment & Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_environment') {
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
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Handle form submissions (PostgreSQL uses CURRENT_TIMESTAMP)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_comment'])) {
        $ticket_id = $_POST['ticket_id'];
        $comment = $_POST['comment'];
        $is_internal = isset($_POST['is_internal']) ? true : false;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
            $success_message = "Comment added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding comment: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_status'])) {
        $ticket_id = $_POST['ticket_id'];
        $status = $_POST['status'];
        $resolution_notes = $_POST['resolution_notes'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE tickets 
                SET status = ?, resolution_notes = ?, resolved_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND assigned_to = ?
            ");
            $stmt->execute([$status, $resolution_notes, $ticket_id, $user_id]);
            $success_message = "Ticket status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating ticket: " . $e->getMessage();
        }
    } elseif (isset($_POST['escalate_ticket'])) {
        $ticket_id = $_POST['ticket_id'];
        $escalate_to = $_POST['escalate_to'];
        $reason = $_POST['escalation_reason'];
        
        try {
            // Update ticket assignment
            $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
            $stmt->execute([$escalate_to, $ticket_id]);
            
            // Record escalation (PostgreSQL uses CURRENT_TIMESTAMP)
            $stmt = $pdo->prepare("
                INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_to, reason, escalated_at, previous_assignee) 
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
            ");
            $stmt->execute([$ticket_id, $user_id, $escalate_to, $reason, $user_id]);
            
            $success_message = "Ticket escalated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error escalating ticket: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for tickets assigned to this minister (PostgreSQL uses ILIKE for case-insensitive search)
$query = "
    SELECT t.*, 
           d.name as department_name,
           p.name as program_name,
           ic.name as category_name,
           u_assigned.full_name as assigned_to_name
    FROM tickets t
    LEFT JOIN departments d ON t.department_id = d.id
    LEFT JOIN programs p ON t.program_id = p.id
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    WHERE t.assigned_to = ?
";

$params = [$user_id];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority_filter;
}

if ($category_filter !== 'all') {
    $query .= " AND t.category_id = ?";
    $params[] = $category_filter;
}

if (!empty($search_term)) {
    $query .= " AND (t.subject ILIKE ? OR t.description ILIKE ? OR t.reg_number ILIKE ? OR t.name ILIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY t.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Tickets query error: " . $e->getMessage());
    $tickets = [];
}

// Get ticket comments for display
$ticket_comments = [];
if (isset($_GET['view_ticket'])) {
    $view_ticket_id = $_GET['view_ticket'];
    try {
        $stmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.role 
            FROM ticket_comments tc 
            JOIN users u ON tc.user_id = u.id 
            WHERE tc.ticket_id = ? 
            ORDER BY tc.created_at ASC
        ");
        $stmt->execute([$view_ticket_id]);
        $ticket_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ticket comments error: " . $e->getMessage());
    }
}

// Get statistics (PostgreSQL uses INTERVAL)
try {
    // Total assigned tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Tickets by status
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM tickets 
        WHERE assigned_to = ? 
        GROUP BY status
    ");
    $stmt->execute([$user_id]);
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tickets by priority
    $stmt = $pdo->prepare("
        SELECT priority, COUNT(*) as count 
        FROM tickets 
        WHERE assigned_to = ? 
        GROUP BY priority
    ");
    $stmt->execute([$user_id]);
    $priority_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent tickets (last 7 days) - PostgreSQL uses INTERVAL
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as recent 
        FROM tickets 
        WHERE assigned_to = ? AND created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $stmt->execute([$user_id]);
    $recent_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['recent'] ?? 0;
    
} catch (PDOException $e) {
    $total_tickets = 0;
    $status_counts = [];
    $priority_counts = [];
    $recent_tickets = 0;
    error_log("Statistics error: " . $e->getMessage());
}

// Get categories for filter
try {
    $stmt = $pdo->query("SELECT id, name FROM issue_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    error_log("Categories query error: " . $e->getMessage());
}

// Get users for escalation
try {
    $stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE role IN ('guild_president', 'vice_guild_academic', 'vice_guild_finance', 'minister_environment')
        AND status = 'active'
        ORDER BY role, full_name
    ");
    $escalation_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $escalation_users = [];
    error_log("Escalation users query error: " . $e->getMessage());
}

// Get unread messages count (PostgreSQL compatible)
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
    error_log("Unread messages error: " . $e->getMessage());
}

// Get pending tickets count for this minister for sidebar badge
$pending_tickets = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE assigned_to = ? AND status IN ('open', 'in_progress')
    ");
    $stmt->execute([$user_id]);
    $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
} catch (PDOException $e) {
    $pending_tickets = 0;
    error_log("Pending tickets error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_projects FROM innovation_projects WHERE category_id = 2");
    $total_projects = $stmt->fetch(PDO::FETCH_ASSOC)['total_projects'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as active_clubs FROM clubs WHERE category = 'environment' AND status = 'active'");
    $active_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['active_clubs'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_maintenance FROM facility_bookings WHERE status = 'pending' AND purpose ILIKE '%maintenance%'");
    $pending_maintenance = $stmt->fetch(PDO::FETCH_ASSOC)['pending_maintenance'] ?? 0;
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as security_incidents 
        FROM tickets 
        WHERE category_id = 5 
        AND created_at >= CURRENT_DATE - INTERVAL '30 days'
    ");
    $security_incidents = $stmt->fetch(PDO::FETCH_ASSOC)['security_incidents'] ?? 0;
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_events 
        FROM events 
        WHERE category_id = 5 
        AND event_date >= CURRENT_DATE
        AND status = 'published'
    ");
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_events'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Sidebar stats error: " . $e->getMessage());
    $total_projects = $active_clubs = $pending_maintenance = $security_incidents = $upcoming_events = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student Tickets - Minister of Environment & Security</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #4caf50;
            --accent-green: #2e7d32;
            --light-green: #e8f5e9;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --purple: #6f42c1;
            --teal: #20c997;
            --indigo: #6610f2;
            --orange: #fd7e14;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            --primary-green: #4caf50;
            --secondary-green: #66bb6a;
            --accent-green: #388e3c;
            --light-green: #1b5e20;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
            --purple: #9c27b0;
            --teal: #009688;
            --indigo: #3f51b5;
            --orange: #ff9800;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            color: var(--primary-green);
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
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
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
            border-left: 4px solid var(--primary-green);
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
            background: var(--light-green);
            color: var(--primary-green);
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

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        /* Table */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-green);
        }

        .table-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .table-wrapper {
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
            background: var(--light-green);
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
            color: #856404;
        }

        .status-in_progress {
            background: #cce7ff;
            color: #004085;
        }

        .status-resolved {
            background: #d4edda;
            color: #155724;
        }

        .status-closed {
            background: #e2e3e5;
            color: #383d41;
        }

        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
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

        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.7rem;
            border-radius: 4px;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        /* Ticket Details */
        .ticket-details {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .ticket-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .ticket-body {
            padding: 1.25rem;
        }

        .ticket-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        /* Comments Section */
        .comments-section {
            margin-top: 2rem;
        }

        .comments-section h3 {
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .comment {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .comment:last-child {
            border-bottom: none;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            color: var(--text-dark);
        }

        .comment-role {
            color: var(--dark-gray);
            font-size: 0.75rem;
        }

        .comment-time {
            color: var(--dark-gray);
            font-size: 0.75rem;
        }

        .comment-internal {
            background: var(--light-green);
            border-left: 3px solid var(--primary-green);
        }

        .comment-text {
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .comment-form {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
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
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
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

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            font-size: 1rem;
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
                background: var(--primary-green);
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
                grid-template-columns: 1fr 1fr;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .ticket-info-grid {
                grid-template-columns: 1fr;
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

            .page-title h1 {
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
                    <h1>Isonga - Environment & Security</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
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
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Environment & Security</div>
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
                    <a href="tickets.php" class="active">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Student Tickets</span>
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
                        <?php if ($total_projects > 0): ?>
                            <span class="menu-badge"><?php echo $total_projects; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="security.php">
                        <i class="fas fa-shield-alt"></i>
                        <span>Security</span>
                        <?php if ($security_incidents > 0): ?>
                            <span class="menu-badge"><?php echo $security_incidents; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                        <?php if ($pending_maintenance > 0): ?>
                            <span class="menu-badge"><?php echo $pending_maintenance; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Environmental Clubs</span>
                        <?php if ($active_clubs > 0): ?>
                            <span class="menu-badge"><?php echo $active_clubs; ?></span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Student Tickets Management</h1>
                    <p>Handle student issues and concerns related to campus facilities and environment</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_tickets); ?></div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                </div>
                <?php 
                $open_count = 0;
                $progress_count = 0;
                foreach ($status_counts as $status) {
                    switch ($status['status']) {
                        case 'open':
                            $open_count = $status['count'];
                            break;
                        case 'in_progress':
                            $progress_count = $status['count'];
                            break;
                    }
                }
                ?>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($open_count); ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($progress_count); ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($recent_tickets); ?></div>
                        <div class="stat-label">This Week</div>
                    </div>
                </div>
            </div>

            <!-- Ticket Details View -->
            <?php if (isset($_GET['view_ticket']) && !empty($ticket_comments)): ?>
                <?php 
                $view_ticket = null;
                foreach ($tickets as $ticket) {
                    if ($ticket['id'] == $_GET['view_ticket']) {
                        $view_ticket = $ticket;
                        break;
                    }
                }
                if ($view_ticket): ?>
                <div class="ticket-details">
                    <div class="ticket-header">
                        <h2><?php echo htmlspecialchars($view_ticket['subject']); ?></h2>
                        <div style="display: flex; gap: 1rem; margin-top: 0.5rem; flex-wrap: wrap;">
                            <span class="status-badge status-<?php echo $view_ticket['status']; ?>">
                                <?php echo ucfirst($view_ticket['status']); ?>
                            </span>
                            <span class="priority-badge priority-<?php echo $view_ticket['priority']; ?>">
                                <?php echo ucfirst($view_ticket['priority']); ?> Priority
                            </span>
                            <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($view_ticket['name']); ?>
                            </span>
                            <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                <i class="fas fa-calendar"></i> <?php echo date('M j, Y g:i A', strtotime($view_ticket['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="ticket-body">
                        <div class="ticket-info-grid">
                            <div class="info-item">
                                <span class="info-label">Student</span>
                                <span class="info-value"><?php echo htmlspecialchars($view_ticket['name']); ?> (<?php echo htmlspecialchars($view_ticket['reg_number']); ?>)</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Contact</span>
                                <span class="info-value"><?php echo htmlspecialchars($view_ticket['email']); ?> | <?php echo htmlspecialchars($view_ticket['phone']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($view_ticket['department_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Program</span>
                                <span class="info-value"><?php echo htmlspecialchars($view_ticket['program_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Category</span>
                                <span class="info-value"><?php echo htmlspecialchars($view_ticket['category_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Preferred Contact</span>
                                <span class="info-value"><?php echo ucfirst($view_ticket['preferred_contact']); ?></span>
                            </div>
                        </div>

                        <div class="info-item" style="margin-bottom: 1.5rem;">
                            <span class="info-label">Issue Description</span>
                            <div class="info-value" style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius);">
                                <?php echo nl2br(htmlspecialchars($view_ticket['description'])); ?>
                            </div>
                        </div>

                        <!-- Comments Section -->
                        <div class="comments-section">
                            <h3>Communication History</h3>
                            
                            <?php foreach ($ticket_comments as $comment): ?>
                                <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                    <div class="comment-header">
                                        <div>
                                            <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                                            <span class="comment-role">(<?php echo htmlspecialchars($comment['role']); ?>)</span>
                                            <?php if ($comment['is_internal']): ?>
                                                <span style="color: var(--primary-green); font-size: 0.7rem; margin-left: 0.5rem;">
                                                    <i class="fas fa-lock"></i> Internal Note
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="comment-time">
                                            <?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="comment-text">
                                        <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add Comment Form -->
                            <div class="comment-form">
                                <form method="POST">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    <div class="form-group">
                                        <label class="form-label">Add Comment</label>
                                        <textarea name="comment" class="form-control" required placeholder="Add your response or update..." rows="3"></textarea>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="is_internal" id="is_internal">
                                        <label for="is_internal" style="font-size: 0.8rem;">Internal note (not visible to student)</label>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap;">
                                        <button type="submit" name="add_comment" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane"></i> Add Comment
                                        </button>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="openUpdateStatusModal(<?php echo $view_ticket['id']; ?>)">
                                            <i class="fas fa-edit"></i> Update Status
                                        </button>
                                        <button type="button" class="btn btn-info btn-sm" onclick="openEscalateModal(<?php echo $view_ticket['id']; ?>)">
                                            <i class="fas fa-level-up-alt"></i> Escalate
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Search Tickets</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by subject, student, or description..." value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priority</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tickets Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Assigned Tickets (<?php echo number_format(count($tickets)); ?>)</h3>
                </div>
                <?php if (empty($tickets)): ?>
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <h3>No Tickets Assigned</h3>
                        <p>You don't have any tickets assigned to you matching the current filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Ticket Subject</th>
                                    <th>Student</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($ticket['subject']); ?></strong>
                                            <?php if (strlen($ticket['description']) > 100): ?>
                                                <br><small><?php echo htmlspecialchars(substr($ticket['description'], 0, 100)) . '...'; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($ticket['name']); ?>
                                            <br><small><?php echo htmlspecialchars($ticket['reg_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($ticket['category_name']); ?></td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                                <?php echo ucfirst($ticket['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                                <?php echo str_replace('_', ' ', ucfirst($ticket['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($ticket['created_at'])); ?>
                                            <br><small><?php echo date('g:i A', strtotime($ticket['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?view_ticket=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <button class="btn btn-sm btn-warning" onclick="openUpdateStatusModal(<?php echo $ticket['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Status
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="openEscalateModal(<?php echo $ticket['id']; ?>)">
                                                    <i class="fas fa-level-up-alt"></i> Escalate
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Update Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Ticket Status</h3>
                <button class="modal-close" onclick="closeUpdateStatusModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="ticket_id" id="update_ticket_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control" required>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" placeholder="Add any notes about the resolution..." rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUpdateStatusModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Escalate Ticket Modal -->
    <div id="escalateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Escalate Ticket</h3>
                <button class="modal-close" onclick="closeEscalateModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="ticket_id" id="escalate_ticket_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Escalate To</label>
                        <select name="escalate_to" class="form-control" required>
                            <?php foreach ($escalation_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reason for Escalation</label>
                        <textarea name="escalation_reason" class="form-control" required placeholder="Explain why you are escalating this ticket..." rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEscalateModal()">Cancel</button>
                    <button type="submit" name="escalate_ticket" class="btn btn-info">Escalate Ticket</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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

        // Modal Functions
        function openUpdateStatusModal(ticketId) {
            document.getElementById('update_ticket_id').value = ticketId;
            document.getElementById('updateStatusModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeUpdateStatusModal() {
            document.getElementById('updateStatusModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function openEscalateModal(ticketId) {
            document.getElementById('escalate_ticket_id').value = ticketId;
            document.getElementById('escalateModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEscalateModal() {
            document.getElementById('escalateModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['updateStatusModal', 'escalateModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'updateStatusModal') closeUpdateStatusModal();
                    if (modalId === 'escalateModal') closeEscalateModal();
                }
            });
        }

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .table-container, .filters-card');
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