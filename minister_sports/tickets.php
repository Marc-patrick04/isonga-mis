<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Sports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_sports') {
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_ticket_status'])) {
        $ticket_id = $_POST['ticket_id'];
        $status = $_POST['status'];
        $resolution_notes = $_POST['resolution_notes'] ?? '';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE tickets 
                SET status = ?, resolution_notes = ?, resolved_at = NOW() 
                WHERE id = ? AND assigned_to = ?
            ");
            $stmt->execute([$status, $resolution_notes, $ticket_id, $user_id]);
            
            $_SESSION['success_message'] = "Ticket status updated successfully!";
            header('Location: tickets.php?view_ticket=' . $ticket_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating ticket: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_comment'])) {
        $ticket_id = $_POST['ticket_id'];
        $comment = $_POST['comment'];
        $is_internal = isset($_POST['is_internal']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
            
            $_SESSION['success_message'] = "Comment added successfully!";
            header('Location: tickets.php?view_ticket=' . $ticket_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding comment: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['escalate_ticket'])) {
        $ticket_id = $_POST['ticket_id'];
        $escalate_to = $_POST['escalate_to'];
        $reason = $_POST['escalation_reason'];
        
        try {
            // Get current assignee
            $stmt = $pdo->prepare("SELECT assigned_to FROM tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $current_assignee = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_to'];
            
            // Update ticket assignment
            $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
            $stmt->execute([$escalate_to, $ticket_id]);
            
            // Record escalation
            $stmt = $pdo->prepare("
                INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_to, reason, escalated_at, previous_assignee)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$ticket_id, $user_id, $escalate_to, $reason, $current_assignee]);
            
            $_SESSION['success_message'] = "Ticket escalated successfully!";
            header('Location: tickets.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error escalating ticket: " . $e->getMessage();
        }
    }
}

// Get ticket statistics
try {
    // Total tickets assigned to this minister
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_tickets FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as open_tickets FROM tickets WHERE assigned_to = ? AND status IN ('open', 'in_progress')");
    $stmt->execute([$user_id]);
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Resolved tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as resolved_tickets FROM tickets WHERE assigned_to = ? AND status = 'resolved'");
    $stmt->execute([$user_id]);
    $resolved_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['resolved_tickets'] ?? 0;
    
    // Closed tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as closed_tickets FROM tickets WHERE assigned_to = ? AND status = 'closed'");
    $stmt->execute([$user_id]);
    $closed_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['closed_tickets'] ?? 0;
    
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $resolved_tickets = $closed_tickets = 0;
    error_log("Error fetching ticket statistics: " . $e->getMessage());
}

// Get tickets based on filter
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $query = "
        SELECT t.*, 
               d.name as department_name,
               p.name as program_name,
               ic.name as category_name,
               u_assigned.full_name as assigned_to_name,
               u_student.full_name as student_name
        FROM tickets t
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        LEFT JOIN users u_student ON t.reg_number = u_student.reg_number
        WHERE t.assigned_to = ?
    ";
    
    $params = [$user_id];
    
    // Apply filters
    if ($filter === 'open') {
        $query .= " AND t.status IN ('open', 'in_progress')";
    } elseif ($filter === 'resolved') {
        $query .= " AND t.status = 'resolved'";
    } elseif ($filter === 'closed') {
        $query .= " AND t.status = 'closed'";
    }
    
    // Apply search
    if (!empty($search)) {
        $query .= " AND (t.subject LIKE ? OR t.name LIKE ? OR t.reg_number LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $query .= " ORDER BY 
        CASE 
            WHEN t.status IN ('open', 'in_progress') THEN 1
            WHEN t.status = 'resolved' THEN 2
            ELSE 3
        END,
        t.priority DESC,
        t.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $tickets = [];
    error_log("Error fetching tickets: " . $e->getMessage());
}

// Get ticket details if viewing a specific ticket
$ticket_details = null;
$ticket_comments = [];
$ticket_escalations = [];

if (isset($_GET['view_ticket']) && is_numeric($_GET['view_ticket'])) {
    $ticket_id = $_GET['view_ticket'];
    
    try {
        // Get ticket details
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   d.name as department_name,
                   p.name as program_name,
                   ic.name as category_name,
                   u_assigned.full_name as assigned_to_name,
                   u_student.full_name as student_name
            FROM tickets t
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN programs p ON t.program_id = p.id
            LEFT JOIN issue_categories ic ON t.category_id = ic.id
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            LEFT JOIN users u_student ON t.reg_number = u_student.reg_number
            WHERE t.id = ? AND t.assigned_to = ?
        ");
        $stmt->execute([$ticket_id, $user_id]);
        $ticket_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ticket_details) {
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
            
            // Get ticket escalations
            $stmt = $pdo->prepare("
                SELECT te.*, 
                       u_escalated_by.full_name as escalated_by_name,
                       u_escalated_to.full_name as escalated_to_name
                FROM ticket_escalations te
                LEFT JOIN users u_escalated_by ON te.escalated_by = u_escalated_by.id
                LEFT JOIN users u_escalated_to ON te.escalated_to = u_escalated_to.id
                WHERE te.ticket_id = ?
                ORDER BY te.escalated_at DESC
            ");
            $stmt->execute([$ticket_id]);
            $ticket_escalations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching ticket details: " . $e->getMessage());
    }
}

// Get committee members for escalation
$committee_members = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role
        FROM users u
        WHERE u.status = 'active' 
        AND u.role IN ('guild_president', 'vice_guild_academic', 'vice_guild_finance', 'general_secretary')
        AND u.id != ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$user_id]);
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching committee members: " . $e->getMessage());
}

// Unread messages count
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - Isonga RPSU</title>
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
            top: 80px;
            height: calc(100vh - 80px);
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

        .page-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
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
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
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

        .table tr:hover {
            background: var(--light-gray);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open {
            background: #d4edda;
            color: var(--success);
        }

        .status-in_progress {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-resolved {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-closed {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
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

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.25rem 0.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .action-btn.view {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .action-btn.approve {
            background: #d4edda;
            color: var(--success);
        }

        .action-btn.reject {
            background: #f8d7da;
            color: var(--danger);
        }

        .action-btn.cancel {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--dark-gray);
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
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
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        /* Search and Filter */
        .search-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            padding-left: 2.5rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }

        .filter-select {
            min-width: 150px;
        }

        /* Ticket Details */
        .ticket-header {
            background: var(--light-blue);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .comments-section {
            margin-top: 2rem;
        }

        .comment {
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            background: var(--white);
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            color: var(--text-dark);
        }

        .comment-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-left: 0.5rem;
        }

        .comment-time {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .comment-internal {
            border-left: 4px solid var(--warning);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .form-row {
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
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .search-filter {
                flex-direction: column;
                align-items: stretch;
            }
            
            .ticket-meta {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
        
        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
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
                    <h1>Isonga - Minister of Sports</h1>
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
                        <div class="user-role">Minister of Sports</div>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="teams.php">
                        <i class="fas fa-users"></i>
                        <span>Sports Teams</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php">
                        <i class="fas fa-building"></i>
                        <span>Sports Facilities</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-music"></i>
                        <span>Entertainment Clubs</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php" class="active">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="competitions.php">
                        <i class="fas fa-trophy"></i>
                        <span>Competitions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="equipment.php">
                        <i class="fas fa-baseball-ball"></i>
                        <span>Equipment</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Funding & Budget</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="training.php">
                        <i class="fas fa-running"></i>
                        <span>Training</span>
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
        
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Support Tickets Management 🎫</h1>
                    <p>Manage and resolve student support tickets related to sports</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="window.location.href='dashboard.php'">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
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
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolved_tickets; ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $closed_tickets; ?></div>
                        <div class="stat-label">Closed</div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="search-filter">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control" placeholder="Search tickets..." 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           onkeypress="if(event.keyCode==13) searchTickets()">
                </div>
                <select class="form-control filter-select" onchange="filterTickets(this.value)">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Tickets</option>
                    <option value="open" <?php echo $filter === 'open' ? 'selected' : ''; ?>>Open Tickets</option>
                    <option value="resolved" <?php echo $filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?php echo $filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('tickets-tab')">All Tickets</button>
                <?php if (isset($_GET['view_ticket'])): ?>
                    <button class="tab" onclick="openTab('ticket-details-tab')">Ticket Details</button>
                <?php endif; ?>
            </div>

            <!-- Tickets List Tab -->
            <div id="tickets-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Sports-Related Support Tickets</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($tickets)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-ticket-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No tickets found matching your criteria.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Subject</th>
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
                                            <td>#<?php echo $ticket['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ticket['subject']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars(substr($ticket['description'], 0, 100)); ?>...
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($ticket['name']); ?>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($ticket['reg_number']); ?>
                                                </div>
                                            </td>
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
                                                <?php echo date('M j, Y', strtotime($ticket['created_at'])); ?>
                                                <br>
                                                <small style="color: var(--dark-gray);">
                                                    <?php echo date('g:i A', strtotime($ticket['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="?view_ticket=<?php echo $ticket['id']; ?>" class="btn btn-primary btn-sm">
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
            </div>

            <!-- Ticket Details Tab -->
            <?php if (isset($_GET['view_ticket']) && $ticket_details): ?>
                <div id="ticket-details-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Ticket #<?php echo $ticket_details['id']; ?> Details</h3>
                            <a href="tickets.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Tickets
                            </a>
                        </div>
                        <div class="card-body">
                            <!-- Ticket Header -->
                            <div class="ticket-header">
                                <h2><?php echo htmlspecialchars($ticket_details['subject']); ?></h2>
                                <div class="ticket-meta">
                                    <div class="meta-item">
                                        <span class="meta-label">Student</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($ticket_details['name']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Registration Number</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($ticket_details['reg_number']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Category</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($ticket_details['category_name']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Priority</span>
                                        <span class="meta-value">
                                            <span class="priority-badge priority-<?php echo $ticket_details['priority']; ?>">
                                                <?php echo ucfirst($ticket_details['priority']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Status</span>
                                        <span class="meta-value">
                                            <span class="status-badge status-<?php echo $ticket_details['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $ticket_details['status'])); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Created</span>
                                        <span class="meta-value"><?php echo date('M j, Y g:i A', strtotime($ticket_details['created_at'])); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Ticket Description -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Issue Description</h3>
                                </div>
                                <div class="card-body">
                                    <p><?php echo nl2br(htmlspecialchars($ticket_details['description'])); ?></p>
                                </div>
                            </div>

                            <!-- Update Ticket Status -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Update Ticket Status</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_details['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-control" required>
                                                    <option value="open" <?php echo $ticket_details['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                                    <option value="in_progress" <?php echo $ticket_details['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                    <option value="resolved" <?php echo $ticket_details['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                    <option value="closed" <?php echo $ticket_details['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Resolution Notes</label>
                                                <textarea name="resolution_notes" class="form-control" rows="3" placeholder="Add resolution notes..."><?php echo htmlspecialchars($ticket_details['resolution_notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="form-group" style="text-align: right;">
                                            <button type="submit" name="update_ticket_status" class="btn btn-primary">Update Status</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Add Comment -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Add Comment</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_details['id']; ?>">
                                        <div class="form-group">
                                            <textarea name="comment" class="form-control" rows="3" placeholder="Add your comment..." required></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                                <input type="checkbox" name="is_internal" value="1">
                                                <span>Internal comment (not visible to student)</span>
                                            </label>
                                        </div>
                                        <div class="form-group" style="text-align: right;">
                                            <button type="submit" name="add_comment" class="btn btn-primary">Add Comment</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Ticket Comments -->
                            <?php if (!empty($ticket_comments)): ?>
                                <div class="comments-section">
                                    <h3 style="margin-bottom: 1rem;">Comments</h3>
                                    <?php foreach ($ticket_comments as $comment): ?>
                                        <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                            <div class="comment-header">
                                                <div>
                                                    <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                                                    <span class="comment-role">(<?php echo htmlspecialchars($comment['role']); ?>)</span>
                                                    <?php if ($comment['is_internal']): ?>
                                                        <span class="status-badge" style="background: #fff3cd; color: #856404; margin-left: 0.5rem;">Internal</span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="comment-time"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                                            </div>
                                            <p><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Escalate Ticket -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Escalate Ticket</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_details['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Escalate To</label>
                                                <select name="escalate_to" class="form-control" required>
                                                    <option value="">Select Committee Member</option>
                                                    <?php foreach ($committee_members as $member): ?>
                                                        <option value="<?php echo $member['id']; ?>">
                                                            <?php echo htmlspecialchars($member['full_name']); ?> - <?php echo htmlspecialchars($member['role']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Reason for Escalation</label>
                                                <textarea name="escalation_reason" class="form-control" rows="3" placeholder="Explain why you're escalating this ticket..." required></textarea>
                                            </div>
                                        </div>
                                        <div class="form-group" style="text-align: right;">
                                            <button type="submit" name="escalate_ticket" class="btn btn-warning">Escalate Ticket</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Escalation History -->
                            <?php if (!empty($ticket_escalations)): ?>
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Escalation History</h3>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($ticket_escalations as $escalation): ?>
                                            <div style="padding: 1rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); margin-bottom: 1rem;">
                                                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 0.5rem;">
                                                    <div>
                                                        <strong>Escalated by: <?php echo htmlspecialchars($escalation['escalated_by_name']); ?></strong>
                                                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                                            To: <?php echo htmlspecialchars($escalation['escalated_to_name']); ?>
                                                        </div>
                                                    </div>
                                                    <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                                        <?php echo date('M j, Y g:i A', strtotime($escalation['escalated_at'])); ?>
                                                    </div>
                                                </div>
                                                <p style="margin: 0; font-size: 0.9rem;"><?php echo nl2br(htmlspecialchars($escalation['reason'])); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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

        // Tab Functions
        function openTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Search and Filter Functions
        function searchTickets() {
            const search = document.querySelector('.search-box input').value;
            const filter = document.querySelector('.filter-select').value;
            let url = 'tickets.php?';
            
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (filter !== 'all') url += `filter=${filter}`;
            
            window.location.href = url;
        }

        function filterTickets(filter) {
            const search = document.querySelector('.search-box input').value;
            let url = 'tickets.php?';
            
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (filter !== 'all') url += `filter=${filter}`;
            
            window.location.href = url;
        }

        function clearFilters() {
            window.location.href = 'tickets.php';
        }

        // Auto-close alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>