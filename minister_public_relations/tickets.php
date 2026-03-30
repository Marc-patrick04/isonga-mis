<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Get unread messages count for badge
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $ticket_id = $_POST['ticket_id'] ?? null;
        
        switch ($_POST['action']) {
            case 'update_status':
                $status = $_POST['status'];
                $resolution_notes = $_POST['resolution_notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE tickets 
                        SET status = ?, resolution_notes = ?, resolved_at = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND assigned_to = ?
                    ");
                    
                    $resolved_at = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;
                    
                    $stmt->execute([
                        $status, 
                        $resolution_notes, 
                        $resolved_at,
                        $ticket_id, 
                        $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Ticket status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating ticket: " . $e->getMessage();
                }
                break;
                
            case 'add_comment':
                $comment = $_POST['comment'];
                $is_internal = $_POST['is_internal'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at)
                        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
                    
                    $_SESSION['success_message'] = "Comment added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding comment: " . $e->getMessage();
                }
                break;
                
            case 'escalate_ticket':
                $escalate_to = $_POST['escalate_to'];
                $reason = $_POST['escalation_reason'];
                
                try {
                    // Get current assignee
                    $stmt = $pdo->prepare("SELECT assigned_to FROM tickets WHERE id = ?");
                    $stmt->execute([$ticket_id]);
                    $current_assignee = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_to'];
                    
                    // Update ticket assignment
                    $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$escalate_to, $ticket_id]);
                    
                    // Log escalation
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_to, reason, escalated_at, previous_assignee)
                        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
                    ");
                    $stmt->execute([$ticket_id, $user_id, $escalate_to, $reason, $current_assignee]);
                    
                    $_SESSION['success_message'] = "Ticket escalated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error escalating ticket: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: tickets.php" . ($ticket_id ? "?view=" . $ticket_id : ""));
        exit();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query for tickets
$query = "
    SELECT t.*, 
           d.name as department_name,
           p.name as program_name,
           ic.name as category_name,
           u_assigned.full_name as assigned_to_name,
           u_assigned.role as assigned_to_role
    FROM tickets t
    LEFT JOIN departments d ON t.department_id = d.id
    LEFT JOIN programs p ON t.program_id = p.id
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($category_filter !== 'all') {
    $query .= " AND t.category_id = ?";
    $params[] = $category_filter;
}

if ($priority_filter !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority_filter;
}

if (!empty($search)) {
    $query .= " AND (t.reg_number ILIKE ? OR t.name ILIKE ? OR t.subject ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// For Public Relations minister, show tickets assigned to them and public relations category tickets
$query .= " AND (t.assigned_to = ? OR t.category_id = 9)";
$params[] = $user_id;

$query .= " ORDER BY 
    CASE 
        WHEN t.status = 'open' THEN 1
        WHEN t.status = 'in_progress' THEN 2
        WHEN t.status = 'resolved' THEN 3
        ELSE 4
    END,
    CASE 
        WHEN t.priority = 'high' THEN 1
        WHEN t.priority = 'medium' THEN 2
        ELSE 3
    END,
    t.created_at DESC";

// Get tickets
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Tickets query error: " . $e->getMessage());
    $tickets = [];
}

// Get ticket details if viewing specific ticket
$view_ticket = null;
$ticket_comments = [];
$ticket_assignments = [];
$ticket_escalations = [];

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $ticket_id = $_GET['view'];
    
    try {
        // Get ticket details
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   d.name as department_name,
                   p.name as program_name,
                   ic.name as category_name,
                   u_assigned.full_name as assigned_to_name,
                   u_assigned.role as assigned_to_role,
                   ic.assigned_role as category_assigned_role
            FROM tickets t
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN programs p ON t.program_id = p.id
            LEFT JOIN issue_categories ic ON t.category_id = ic.id
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticket_id]);
        $view_ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($view_ticket) {
            // Get ticket comments
            $stmt = $pdo->prepare("
                SELECT tc.*, u.full_name, u.role
                FROM ticket_comments tc
                JOIN users u ON tc.user_id = u.id
                WHERE tc.ticket_id = ?
                ORDER BY tc.created_at ASC
            ");
            $stmt->execute([$ticket_id]);
            $ticket_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get ticket assignment history
            $stmt = $pdo->prepare("
                SELECT ta.*, u_assigned.full_name as assigned_to_name, u_assigned_by.full_name as assigned_by_name
                FROM ticket_assignments ta
                JOIN users u_assigned ON ta.assigned_to = u_assigned.id
                JOIN users u_assigned_by ON ta.assigned_by = u_assigned_by.id
                WHERE ta.ticket_id = ?
                ORDER BY ta.assigned_at DESC
            ");
            $stmt->execute([$ticket_id]);
            $ticket_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get ticket escalations
            $stmt = $pdo->prepare("
                SELECT te.*, 
                       u_escalated_by.full_name as escalated_by_name,
                       u_escalated_to.full_name as escalated_to_name,
                       u_previous.full_name as previous_assignee_name
                FROM ticket_escalations te
                JOIN users u_escalated_by ON te.escalated_by = u_escalated_by.id
                JOIN users u_escalated_to ON te.escalated_to = u_escalated_to.id
                LEFT JOIN users u_previous ON te.previous_assignee = u_previous.id
                WHERE te.ticket_id = ?
                ORDER BY te.escalated_at DESC
            ");
            $stmt->execute([$ticket_id]);
            $ticket_escalations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        error_log("Ticket details error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading ticket details: " . $e->getMessage();
    }
}

// Get issue categories for filter
try {
    $stmt = $pdo->query("SELECT * FROM issue_categories ORDER BY name");
    $issue_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $issue_categories = [];
}

// Get committee members for escalation
try {
    $stmt = $pdo->query("
        SELECT u.id, u.full_name, u.role, cm.role as committee_role
        FROM users u
        LEFT JOIN committee_members cm ON u.id = cm.user_id
        WHERE u.role != 'student' AND u.status = 'active'
        ORDER BY u.role, u.full_name
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
}

// Get statistics for dashboard
try {
    // Total tickets assigned to this minister
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as open FROM tickets WHERE assigned_to = ? AND status IN ('open', 'in_progress')");
    $stmt->execute([$user_id]);
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open'] ?? 0;
    
    // Resolved tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as resolved FROM tickets WHERE assigned_to = ? AND status = 'resolved'");
    $stmt->execute([$user_id]);
    $resolved_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['resolved'] ?? 0;
    
    // High priority tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as high_priority FROM tickets WHERE assigned_to = ? AND priority = 'high' AND status IN ('open', 'in_progress')");
    $stmt->execute([$user_id]);
    $high_priority_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['high_priority'] ?? 0;
    
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $resolved_tickets = $high_priority_tickets = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student Tickets - Minister of Public Relations</title>
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
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
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

        .dashboard-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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

        .header-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
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
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
            transform: translateY(-1px);
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

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
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
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-select, .form-input {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
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
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open {
            background: #d4edda;
            color: #155724;
        }

        .status-in_progress {
            background: #fff3cd;
            color: #856404;
        }

        .status-resolved {
            background: #cce7ff;
            color: #004085;
        }

        .status-closed {
            background: #e2e3e5;
            color: #383d41;
        }

        .priority-badge {
            padding: 0.2rem 0.5rem;
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

        .action-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .action-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .action-btn.view {
            color: var(--primary-blue);
        }

        .action-btn.view:hover {
            background: var(--light-blue);
        }

        /* Ticket Details */
        .ticket-details {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .ticket-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .ticket-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .ticket-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .ticket-body {
            padding: 1.25rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .ticket-main {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .ticket-description h3 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: var(--text-dark);
        }

        .description-content {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            line-height: 1.6;
        }

        .comment-form {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
        }

        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 0.75rem;
        }

        .form-checkbox {
            margin-right: 0.5rem;
        }

        .comments-list {
            max-height: 400px;
            overflow-y: auto;
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
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-left: 0.5rem;
        }

        .comment-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .comment-content {
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .comment-internal {
            background: #fff3cd;
            border-left: 3px solid var(--warning);
        }

        .dark-mode .comment-internal {
            background: #2d2d2d;
            border-left-color: var(--warning);
        }

        .ticket-sidebar {
            background: var(--light-gray);
            padding: 1.25rem;
            border-radius: var(--border-radius);
        }

        .sidebar-section {
            margin-bottom: 1.5rem;
        }

        .sidebar-section:last-child {
            margin-bottom: 0;
        }

        .sidebar-section h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--text-dark);
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .info-label {
            color: var(--dark-gray);
        }

        .info-value {
            font-weight: 500;
            color: var(--text-dark);
        }

        .history-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .history-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
            font-size: 0.75rem;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-action {
            font-weight: 600;
            color: var(--text-dark);
        }

        .history-details {
            color: var(--dark-gray);
        }

        .history-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Alert Messages */
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
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

            .ticket-body {
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
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

            .table {
                font-size: 0.7rem;
            }

            .table th, .table td {
                padding: 0.5rem;
            }

            .ticket-meta {
                flex-direction: column;
                gap: 0.25rem;
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
                    <h1>Isonga - Student Tickets</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
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
                        <div class="user-role">Minister of Public Relations</div>
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
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
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
                    <h1>Student Tickets Management</h1>
                    <p>Manage and resolve student issues and concerns</p>
                </div>
                <div class="header-actions">
                    <a href="tickets.php" class="btn btn-secondary">
                        <i class="fas fa-list"></i> All Tickets
                    </a>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
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
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($open_tickets); ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($resolved_tickets); ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($high_priority_tickets); ?></div>
                        <div class="stat-label">High Priority</div>
                    </div>
                </div>
            </div>

            <?php if (!$view_ticket): ?>
                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($issue_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-input" placeholder="Search tickets..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="tickets.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Tickets Table -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-ticket-alt"></i> Tickets List</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($tickets)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No tickets found</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Ticket ID</th>
                                            <th>Student</th>
                                            <th>Subject</th>
                                            <th>Category</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Assigned To</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tickets as $ticket): ?>
                                            <tr>
                                                <td>#<?php echo $ticket['id']; ?></td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($ticket['name']); ?></div>
                                                    <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($ticket['reg_number']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                                <td><?php echo htmlspecialchars($ticket['category_name']); ?></td>
                                                <td>
                                                    <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                                        <?php echo ucfirst($ticket['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($ticket['assigned_to_name']): ?>
                                                        <div><?php echo htmlspecialchars($ticket['assigned_to_name']); ?></div>
                                                        <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($ticket['assigned_to_role']); ?></small>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                                <td>
                                                    <a href="?view=<?php echo $ticket['id']; ?>" class="action-btn view" title="View Ticket">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- Ticket Details View -->
                <div class="ticket-details">
                    <div class="ticket-header">
                        <div class="ticket-title"><?php echo htmlspecialchars($view_ticket['subject']); ?></div>
                        <div class="ticket-meta">
                            <span><strong>Ticket ID:</strong> #<?php echo $view_ticket['id']; ?></span>
                            <span><strong>Student:</strong> <?php echo htmlspecialchars($view_ticket['name']); ?> (<?php echo htmlspecialchars($view_ticket['reg_number']); ?>)</span>
                            <span><strong>Category:</strong> <?php echo htmlspecialchars($view_ticket['category_name']); ?></span>
                            <span><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($view_ticket['created_at'])); ?></span>
                        </div>
                    </div>

                    <div class="ticket-body">
                        <div class="ticket-main">
                            <div class="ticket-description">
                                <h3>Issue Description</h3>
                                <div class="description-content">
                                    <?php echo nl2br(htmlspecialchars($view_ticket['description'])); ?>
                                </div>
                            </div>

                            <!-- Status Update Form -->
                            <form method="POST" class="comment-form">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                    <div class="form-group">
                                        <label class="form-label">Update Status</label>
                                        <select name="status" class="form-select" required>
                                            <option value="open" <?php echo $view_ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="in_progress" <?php echo $view_ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="resolved" <?php echo $view_ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="closed" <?php echo $view_ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Priority</label>
                                        <div style="padding: 0.6rem 0.75rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <span class="priority-badge priority-<?php echo $view_ticket['priority']; ?>">
                                                <?php echo ucfirst($view_ticket['priority']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Resolution Notes</label>
                                    <textarea name="resolution_notes" class="form-textarea" placeholder="Add resolution notes or updates..."><?php echo htmlspecialchars($view_ticket['resolution_notes'] ?? ''); ?></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                            </form>

                            <!-- Comments Section -->
                            <div class="comments-section">
                                <h3>Conversation</h3>
                                
                                <!-- Add Comment Form -->
                                <form method="POST" class="comment-form">
                                    <input type="hidden" name="action" value="add_comment">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Add Comment</label>
                                        <textarea name="comment" class="form-textarea" placeholder="Type your comment here..." required></textarea>
                                    </div>
                                    
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                        <label style="font-size: 0.8rem;">
                                            <input type="checkbox" name="is_internal" value="1" class="form-checkbox">
                                            Internal comment (not visible to student)
                                        </label>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Post Comment
                                        </button>
                                    </div>
                                </form>

                                <!-- Comments List -->
                                <div class="comments-list">
                                    <?php if (empty($ticket_comments)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-comment-dots"></i>
                                            <p>No comments yet</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($ticket_comments as $comment): ?>
                                            <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                                <div class="comment-header">
                                                    <div>
                                                        <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                                                        <span class="comment-role">(<?php echo htmlspecialchars($comment['role']); ?>)</span>
                                                        <?php if ($comment['is_internal']): ?>
                                                            <span style="background: var(--warning); color: white; padding: 0.1rem 0.3rem; border-radius: 3px; font-size: 0.6rem; margin-left: 0.5rem;">INTERNAL</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="comment-time"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                                                </div>
                                                <div class="comment-content">
                                                    <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="ticket-sidebar">
                            <!-- Ticket Information -->
                            <div class="sidebar-section">
                                <h4>Ticket Information</h4>
                                <div class="info-item">
                                    <span class="info-label">Status:</span>
                                    <span class="info-value">
                                        <span class="status-badge status-<?php echo $view_ticket['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $view_ticket['status'])); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Priority:</span>
                                    <span class="info-value">
                                        <span class="priority-badge priority-<?php echo $view_ticket['priority']; ?>">
                                            <?php echo ucfirst($view_ticket['priority']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Category:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['category_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Preferred Contact:</span>
                                    <span class="info-value"><?php echo ucfirst($view_ticket['preferred_contact']); ?></span>
                                </div>
                                <?php if ($view_ticket['due_date']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Due Date:</span>
                                        <span class="info-value"><?php echo date('M j, Y', strtotime($view_ticket['due_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Student Information -->
                            <div class="sidebar-section">
                                <h4>Student Information</h4>
                                <div class="info-item">
                                    <span class="info-label">Name:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Reg Number:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['reg_number']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Email:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['email']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['phone']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Academic Year:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['academic_year']); ?></span>
                                </div>
                                <?php if ($view_ticket['department_name']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Department:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_ticket['department_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($view_ticket['program_name']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Program:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_ticket['program_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Assignment Information -->
                            <div class="sidebar-section">
                                <h4>Assignment</h4>
                                <div class="info-item">
                                    <span class="info-label">Assigned To:</span>
                                    <span class="info-value">
                                        <?php if ($view_ticket['assigned_to_name']): ?>
                                            <?php echo htmlspecialchars($view_ticket['assigned_to_name']); ?>
                                            <small style="display: block; color: var(--dark-gray);"><?php echo htmlspecialchars($view_ticket['assigned_to_role']); ?></small>
                                        <?php else: ?>
                                            <span style="color: var(--dark-gray);">Unassigned</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <!-- Escalation Form -->
                                <form method="POST" style="margin-top: 1rem;">
                                    <input type="hidden" name="action" value="escalate_ticket">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Escalate To</label>
                                        <select name="escalate_to" class="form-select" required>
                                            <option value="">Select Committee Member</option>
                                            <?php foreach ($committee_members as $member): ?>
                                                <?php if ($member['id'] != $user_id): ?>
                                                    <option value="<?php echo $member['id']; ?>">
                                                        <?php echo htmlspecialchars($member['full_name']); ?> (<?php echo htmlspecialchars($member['role']); ?>)
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Reason for Escalation</label>
                                        <textarea name="escalation_reason" class="form-textarea" placeholder="Explain why you're escalating this ticket..." required style="min-height: 80px;"></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                                        <i class="fas fa-level-up-alt"></i> Escalate Ticket
                                    </button>
                                </form>
                            </div>

                            <!-- Ticket History -->
                            <div class="sidebar-section">
                                <h4>Ticket History</h4>
                                <div class="history-list">
                                    <?php if (empty($ticket_assignments) && empty($ticket_escalations)): ?>
                                        <div class="empty-state" style="padding: 0.5rem;">
                                            <p>No history available</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($ticket_assignments as $assignment): ?>
                                            <div class="history-item">
                                                <div class="history-action">Assigned to <?php echo htmlspecialchars($assignment['assigned_to_name']); ?></div>
                                                <div class="history-details">by <?php echo htmlspecialchars($assignment['assigned_by_name']); ?></div>
                                                <div class="history-time"><?php echo date('M j, g:i A', strtotime($assignment['assigned_at'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php foreach ($ticket_escalations as $escalation): ?>
                                            <div class="history-item">
                                                <div class="history-action">Escalated to <?php echo htmlspecialchars($escalation['escalated_to_name']); ?></div>
                                                <div class="history-details">by <?php echo htmlspecialchars($escalation['escalated_by_name']); ?></div>
                                                <div class="history-time"><?php echo date('M j, g:i A', strtotime($escalation['escalated_at'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <a href="tickets.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Tickets List
                    </a>
                </div>
            <?php endif; ?>
        </main>
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

        // Confirm before escalating ticket
        document.addEventListener('DOMContentLoaded', function() {
            const escalateForm = document.querySelector('form[action="escalate_ticket"]');
            if (escalateForm) {
                escalateForm.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to escalate this ticket? This will reassign it to another committee member.')) {
                        e.preventDefault();
                    }
                });
            }

            // Add loading animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });

            const ticketDetails = document.querySelector('.ticket-details');
            if (ticketDetails) {
                ticketDetails.style.opacity = '1';
            }
        });

        // Auto-refresh tickets every 2 minutes (only when not viewing a specific ticket)
        setInterval(() => {
            if (!window.location.search.includes('view=')) {
                window.location.reload();
            }
        }, 120000);
    </script>
</body>
</html>