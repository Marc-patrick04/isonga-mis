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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                SET status = ?, resolution_notes = ?, resolved_at = NOW() 
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
            
            // Record escalation
            $stmt = $pdo->prepare("
                INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_to, reason, escalated_at, previous_assignee) 
                VALUES (?, ?, ?, ?, NOW(), ?)
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

// Build query for tickets assigned to this minister
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
    $query .= " AND (t.subject LIKE ? OR t.description LIKE ? OR t.reg_number LIKE ? OR t.name LIKE ?)";
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

// Get statistics
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
    
    // Recent tickets (last 7 days)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as recent 
        FROM tickets 
        WHERE assigned_to = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$user_id]);
    $recent_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['recent'] ?? 0;
    
} catch (PDOException $e) {
    $total_tickets = 0;
    $status_counts = [];
    $priority_counts = [];
    $recent_tickets = 0;
}

// Get categories for filter
try {
    $stmt = $pdo->query("SELECT id, name FROM issue_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
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
}

// Get unread messages count
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
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
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
            color: var(--primary-green);
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
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
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
            border-left: 3px solid var(--primary-green);
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
            background: var(--light-green);
            color: var(--primary-green);
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
            align-items: end;
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
            padding: 0.6rem 0.75rem;
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
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
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
        }

        .table-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
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

        .status-in_progress {
            background: #cce7ff;
            color: var(--primary-green);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-closed {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
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

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 4px;
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
            background: #17a2b8;
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
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .ticket-body {
            padding: 1.5rem;
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

        .comments-section {
            margin-top: 2rem;
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
            font-size: 1.1rem;
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
        }

        /* Alert */
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 1024px) {
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
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Environment & Security</h1>
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
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
    <a href="tickets.php" class="active">
        <i class="fas fa-ticket-alt"></i>
        <span>Student Tickets</span>
        <?php 
        // Get pending tickets count for this minister
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_tickets 
                FROM tickets 
                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
            ");
            $stmt->execute([$user_id]);
            $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
            
            if ($pending_tickets > 0): ?>
                <span class="menu-badge"><?php echo $pending_tickets; ?></span>
            <?php endif;
        } catch (PDOException $e) {
            // Skip badge if error
        }
        ?>
    </a>
</li>
                <li class="menu-item">
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Environmental Clubs</span>
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
        <main class="main-content">
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
                        <div class="stat-number"><?php echo $total_tickets; ?></div>
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
                        <div class="stat-number"><?php echo $open_count; ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $progress_count; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $recent_tickets; ?></div>
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
                            <h3 style="margin-bottom: 1rem;">Communication History</h3>
                            
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
                                    <div class="checkbox-group" style="margin: 0.5rem 0;">
                                        <input type="checkbox" name="is_internal" id="is_internal">
                                        <label for="is_internal" style="font-size: 0.8rem;">Internal note (not visible to student)</label>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                        <button type="submit" name="add_comment" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane"></i> Add Comment
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openUpdateStatusModal(<?php echo $view_ticket['id']; ?>)">
                                            <i class="fas fa-edit"></i> Update Status
                                        </button>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="openEscalateModal(<?php echo $view_ticket['id']; ?>)">
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
                    <h3>Assigned Tickets (<?php echo count($tickets); ?>)</h3>
                </div>
                <?php if (empty($tickets)): ?>
                    <div class="empty-state">
                        <i class="fas fa-ticket-alt"></i>
                        <h3>No Tickets Assigned</h3>
                        <p>You don't have any tickets assigned to you matching the current filters.</p>
                    </div>
                <?php else: ?>
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
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button class="btn btn-sm btn-warning" onclick="openUpdateStatusModal(<?php echo $ticket['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick="openEscalateModal(<?php echo $ticket['id']; ?>)">
                                                <i class="fas fa-level-up-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                    <button type="submit" name="escalate_ticket" class="btn btn-warning">Escalate Ticket</button>
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

        // Modal Functions
        function openUpdateStatusModal(ticketId) {
            document.getElementById('update_ticket_id').value = ticketId;
            document.getElementById('updateStatusModal').style.display = 'flex';
        }

        function closeUpdateStatusModal() {
            document.getElementById('updateStatusModal').style.display = 'none';
        }

        function openEscalateModal(ticketId) {
            document.getElementById('escalate_ticket_id').value = ticketId;
            document.getElementById('escalateModal').style.display = 'flex';
        }

        function closeEscalateModal() {
            document.getElementById('escalateModal').style.display = 'none';
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

        // Auto-refresh tickets every 2 minutes
        setInterval(() => {
            if (!document.querySelector('.modal[style*="display: flex"]')) {
                window.location.reload();
            }
        }, 120000);
    </script>
</body>
</html>