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
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['ticket_id'])) {
        $ticket_id = intval($_POST['ticket_id']);
        $action = $_POST['action'];
        
        try {
            switch ($action) {
                case 'update_status':
                    $new_status = $_POST['status'];
                    $resolution_notes = $_POST['resolution_notes'] ?? '';
                    
                    $stmt = $pdo->prepare("
                        UPDATE tickets 
                        SET status = ?, resolution_notes = ?, resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END 
                        WHERE id = ? AND category_id = 1
                    ");
                    $stmt->execute([$new_status, $resolution_notes, $new_status, $ticket_id]);
                    
                    $_SESSION['success_message'] = "Ticket status updated successfully!";
                    break;
                    
                case 'add_comment':
                    $comment = $_POST['comment'];
                    $is_internal = isset($_POST['is_internal']) ? 1 : 0;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
                    
                    $_SESSION['success_message'] = "Comment added successfully!";
                    break;
                    
                case 'escalate':
                    $reason = $_POST['escalation_reason'];
                    $escalate_to = $_POST['escalate_to'];
                    
                    // Get current assignee
                    $stmt = $pdo->prepare("SELECT assigned_to FROM tickets WHERE id = ?");
                    $stmt->execute([$ticket_id]);
                    $current_assignee = $stmt->fetchColumn();
                    
                    // Update ticket assignment
                    $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
                    $stmt->execute([$escalate_to, $ticket_id]);
                    
                    // Log escalation
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_to, reason, escalated_at, previous_assignee) 
                        VALUES (?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$ticket_id, $user_id, $escalate_to, $reason, $current_assignee]);
                    
                    $_SESSION['success_message'] = "Ticket escalated successfully!";
                    break;
            }
            
            header("Location: academic_tickets.php?id=" . $ticket_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error processing request: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$department_filter = $_GET['department'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for academic tickets
$query = "
    SELECT t.*, 
           c.name as category_name, 
           u.full_name as assigned_name,
           u.role as assigned_role,
           d.name as department_name, 
           p.name as program_name,
           ic.name as issue_category_name
    FROM tickets t 
    LEFT JOIN issue_categories c ON t.category_id = c.id 
    LEFT JOIN users u ON t.assigned_to = u.id 
    LEFT JOIN departments d ON t.department_id = d.id
    LEFT JOIN programs p ON t.program_id = p.id
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    WHERE t.category_id = 1
";

$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority_filter;
}

if ($department_filter !== 'all') {
    $query .= " AND t.department_id = ?";
    $params[] = $department_filter;
}

if (!empty($search_query)) {
    $query .= " AND (t.subject LIKE ? OR t.description LIKE ? OR t.name LIKE ? OR t.reg_number LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY 
    CASE 
        WHEN t.status = 'open' AND t.priority = 'high' THEN 1
        WHEN t.status = 'open' AND t.priority = 'medium' THEN 2
        WHEN t.status = 'open' AND t.priority = 'low' THEN 3
        WHEN t.status = 'in_progress' THEN 4
        ELSE 5
    END, t.created_at DESC";

// Count total academic tickets
$count_query = "SELECT COUNT(*) FROM tickets WHERE category_id = 1";
$total_academic_tickets = $pdo->query($count_query)->fetchColumn();

// Count open academic tickets
$open_count_query = "SELECT COUNT(*) FROM tickets WHERE category_id = 1 AND status = 'open'";
$open_academic_tickets = $pdo->query($open_count_query)->fetchColumn();

// Get filtered tickets
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$departments = $pdo->query("SELECT id, name FROM departments WHERE is_active = '1'")->fetchAll(PDO::FETCH_ASSOC);

// Get specific ticket details if ID is provided
$current_ticket = null;
$ticket_comments = [];
if (isset($_GET['id'])) {
    $ticket_id = intval($_GET['id']);
    
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.name as category_name, 
               u.full_name as assigned_name,
               u.role as assigned_role,
               u.email as assigned_email,
               d.name as department_name, 
               p.name as program_name,
               ic.name as issue_category_name
        FROM tickets t 
        LEFT JOIN issue_categories c ON t.category_id = c.id 
        LEFT JOIN users u ON t.assigned_to = u.id 
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $current_ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get ticket comments
    if ($current_ticket) {
        $stmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.role, u.avatar_url
            FROM ticket_comments tc
            JOIN users u ON tc.user_id = u.id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ");
        $stmt->execute([$ticket_id]);
        $ticket_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get committee members for escalation
$committee_members = $pdo->query("
    SELECT id, full_name, role 
    FROM users 
    WHERE role IN ('guild_president', 'vice_guild_finance', 'general_secretary')
    AND status = 'active'
    ORDER BY 
        CASE role
            WHEN 'guild_president' THEN 1
            WHEN 'vice_guild_finance' THEN 2
            WHEN 'general_secretary' THEN 3
            ELSE 4
        END
")->fetchAll(PDO::FETCH_ASSOC);

// Get unread messages count
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
    $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Tickets - Isonga RPSU</title>
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

        /* Page Header */
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
        }

        .page-title p {
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

        .form-select, .form-input {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.1);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
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

        /* Tickets Table */
        .tickets-table-container {
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

        .table-actions {
            display: flex;
            gap: 0.5rem;
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

        .table tbody tr {
            transition: var(--transition);
            cursor: pointer;
        }

        .table tbody tr:hover {
            background: var(--academic-light);
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

        .status-closed {
            background: #e2e3e5;
            color: var(--dark-gray);
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

        /* Ticket Detail View */
        .ticket-detail-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .ticket-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .ticket-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .ticket-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .ticket-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .ticket-body {
            padding: 1.25rem;
        }

        .ticket-section {
            margin-bottom: 1.5rem;
        }

        .ticket-section-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .ticket-description {
            line-height: 1.6;
            color: var(--text-dark);
        }

        /* Comments Section */
        .comments-section {
            margin-top: 2rem;
        }

        .comment-list {
            list-style: none;
            margin-bottom: 1.5rem;
        }

        .comment-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .comment-item:last-child {
            border-bottom: none;
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
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

        .comment-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .comment-content {
            flex: 1;
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
            font-size: 0.85rem;
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

        .comment-text {
            color: var(--text-dark);
            line-height: 1.5;
        }

        .comment-internal {
            background: #fff3cd;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            border-left: 3px solid var(--warning);
        }

        /* Forms */
        .form-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            resize: vertical;
            min-height: 100px;
            transition: var(--transition);
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.1);
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .form-checkbox input {
            width: 16px;
            height: 16px;
        }

        .form-checkbox label {
            font-size: 0.8rem;
            color: var(--text-dark);
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
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
            
            .filters-form {
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
            
            .table-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .table-actions {
                width: 100%;
                justify-content: space-between;
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
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
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
                    <a href="academic_tickets.php" class="active">
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Academic Tickets Management</h1>
                    <p>Manage and resolve academic issues raised by students</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
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
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_academic_tickets; ?></div>
                        <div class="stat-label">Total Academic Tickets</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_academic_tickets; ?></div>
                        <div class="stat-label">Open Academic Tickets</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_academic_tickets - $open_academic_tickets; ?></div>
                        <div class="stat-label">Resolved Academic Tickets</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_academic_tickets > 0 ? round((($total_academic_tickets - $open_academic_tickets) / $total_academic_tickets) * 100) : 0; ?>%</div>
                        <div class="stat-label">Resolution Rate</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
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
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priority</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="all" <?php echo $department_filter === 'all' ? 'selected' : ''; ?>>All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-input" placeholder="Search tickets..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <?php if (isset($_GET['id']) && $current_ticket): ?>
                <!-- Ticket Detail View -->
                <div class="ticket-detail-container">
                    <div class="ticket-header">
                        <div class="ticket-title"><?php echo htmlspecialchars($current_ticket['subject']); ?></div>
                        <div class="ticket-meta">
                            <div class="ticket-meta-item">
                                <i class="fas fa-hashtag"></i>
                                <span>Ticket #<?php echo $current_ticket['id']; ?></span>
                            </div>
                            <div class="ticket-meta-item">
                                <i class="fas fa-user"></i>
                                <span><?php echo htmlspecialchars($current_ticket['name']); ?> (<?php echo htmlspecialchars($current_ticket['reg_number']); ?>)</span>
                            </div>
                            <div class="ticket-meta-item">
                                <i class="fas fa-building"></i>
                                <span><?php echo htmlspecialchars($current_ticket['department_name']); ?> - <?php echo htmlspecialchars($current_ticket['program_name']); ?></span>
                            </div>
                            <div class="ticket-meta-item">
                                <i class="fas fa-calendar"></i>
                                <span><?php echo date('M j, Y g:i A', strtotime($current_ticket['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="ticket-body">
                        <!-- Ticket Information -->
                        <div class="ticket-section">
                            <div class="ticket-section-title">Ticket Information</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <span class="status-badge status-<?php echo $current_ticket['status']; ?>">
                                        <?php echo ucfirst($current_ticket['status']); ?>
                                    </span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Priority</label>
                                    <span class="priority-badge priority-<?php echo $current_ticket['priority']; ?>">
                                        <?php echo ucfirst($current_ticket['priority']); ?>
                                    </span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Assigned To</label>
                                    <span><?php echo $current_ticket['assigned_name'] ? htmlspecialchars($current_ticket['assigned_name']) : 'Not Assigned'; ?></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Due Date</label>
                                    <span><?php echo $current_ticket['due_date'] ? date('M j, Y', strtotime($current_ticket['due_date'])) : 'Not Set'; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Issue Description -->
                        <div class="ticket-section">
                            <div class="ticket-section-title">Issue Description</div>
                            <div class="ticket-description">
                                <?php echo nl2br(htmlspecialchars($current_ticket['description'])); ?>
                            </div>
                        </div>

                        <!-- Student Contact Information -->
                        <div class="ticket-section">
                            <div class="ticket-section-title">Student Contact Information</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Email</label>
                                    <span><?php echo htmlspecialchars($current_ticket['email']); ?></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <span><?php echo htmlspecialchars($current_ticket['phone']); ?></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Preferred Contact</label>
                                    <span><?php echo ucfirst($current_ticket['preferred_contact']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Update Status Form -->
                        <div class="form-card">
                            <div class="form-title">Update Ticket Status</div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="ticket_id" value="<?php echo $current_ticket['id']; ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select" required>
                                            <option value="open" <?php echo $current_ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="in_progress" <?php echo $current_ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="resolved" <?php echo $current_ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="closed" <?php echo $current_ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row form-full">
                                    <div class="form-group">
                                        <label class="form-label">Resolution Notes</label>
                                        <textarea name="resolution_notes" class="form-textarea" placeholder="Add resolution notes or updates..."><?php echo htmlspecialchars($current_ticket['resolution_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                            </form>
                        </div>

                        <!-- Escalate Ticket Form -->
                        <div class="form-card">
                            <div class="form-title">Escalate Ticket</div>
                            <form method="POST">
                                <input type="hidden" name="action" value="escalate">
                                <input type="hidden" name="ticket_id" value="<?php echo $current_ticket['id']; ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Escalate To</label>
                                        <select name="escalate_to" class="form-select" required>
                                            <option value="">Select Committee Member</option>
                                            <?php foreach ($committee_members as $member): ?>
                                                <option value="<?php echo $member['id']; ?>">
                                                    <?php echo htmlspecialchars($member['full_name']); ?> (<?php echo str_replace('_', ' ', $member['role']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row form-full">
                                    <div class="form-group">
                                        <label class="form-label">Reason for Escalation</label>
                                        <textarea name="escalation_reason" class="form-textarea" placeholder="Explain why you're escalating this ticket..." required></textarea>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-level-up-alt"></i> Escalate Ticket
                                </button>
                            </form>
                        </div>

                        <!-- Comments Section -->
                        <div class="comments-section">
                            <div class="ticket-section-title">Comments & Updates</div>
                            
                            <!-- Comments List -->
                            <ul class="comment-list">
                                <?php if (empty($ticket_comments)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No comments yet. Be the first to add a comment.
                                    </li>
                                <?php else: ?>
                                    <?php foreach ($ticket_comments as $comment): ?>
                                        <li class="comment-item <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                            <div class="comment-avatar">
                                                <?php if (!empty($comment['avatar_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($comment['avatar_url']); ?>" alt="Avatar">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($comment['full_name'], 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="comment-content">
                                                <div class="comment-header">
                                                    <div>
                                                        <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                                                        <span class="comment-role">(<?php echo str_replace('_', ' ', $comment['role']); ?>)</span>
                                                        <?php if ($comment['is_internal']): ?>
                                                            <span style="color: var(--warning); font-size: 0.7rem; margin-left: 0.5rem;">
                                                                <i class="fas fa-eye-slash"></i> Internal Note
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="comment-time">
                                                        <?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?>
                                                    </div>
                                                </div>
                                                <div class="comment-text">
                                                    <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>

                            <!-- Add Comment Form -->
                            <div class="form-card">
                                <div class="form-title">Add Comment</div>
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_comment">
                                    <input type="hidden" name="ticket_id" value="<?php echo $current_ticket['id']; ?>">
                                    <div class="form-row form-full">
                                        <div class="form-group">
                                            <textarea name="comment" class="form-textarea" placeholder="Add your comment or update..." required></textarea>
                                        </div>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="is_internal" id="is_internal" value="1">
                                        <label for="is_internal">This is an internal note (visible only to committee members)</label>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">
                                        <i class="fas fa-comment"></i> Add Comment
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Tickets List View -->
                <div class="tickets-table-container">
                    <div class="table-header">
                        <h3>Academic Tickets (<?php echo count($tickets); ?> found)</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Student</th>
                                <th>Department</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No academic tickets found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr onclick="window.location.href='academic_tickets.php?id=<?php echo $ticket['id']; ?>&<?php echo http_build_query($_GET); ?>'">
                                        <td>#<?php echo $ticket['id']; ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($ticket['subject']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($ticket['name']); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['department_name']); ?></td>
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
                                        <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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

        // Auto-refresh page every 5 minutes if not viewing a specific ticket
        if (!window.location.search.includes('id=')) {
            setInterval(() => {
                window.location.reload();
            }, 300000);
        }
    </script>
</body>
</html>