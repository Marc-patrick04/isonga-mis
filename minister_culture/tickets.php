<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
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

// Get sidebar statistics
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets_all = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets_all = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (PDOException $e) {
        $pending_reports = 0;
    }
    
    // Unread messages
    $unread_messages = 0;
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
    }
    
    // New students count
    $new_students = 0;
    try {
        $new_students_stmt = $pdo->prepare("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= NOW() - INTERVAL '7 days'
        ");
        $new_students_stmt->execute();
        $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    } catch (PDOException $e) {
        $new_students = 0;
    }
    
    // Upcoming meetings count
    $upcoming_meetings = 0;
    try {
        $upcoming_meetings = $pdo->query("
            SELECT COUNT(*) as count FROM meetings 
            WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $upcoming_meetings = 0;
    }
    
    // Pending minutes count
    $pending_minutes = 0;
    try {
        $pending_minutes = $pdo->query("
            SELECT COUNT(*) as count FROM meeting_minutes 
            WHERE approval_status = 'submitted'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $pending_minutes = 0;
    }
    
    // Pending tickets for badge
    $pending_tickets_badge = 0;
    try {
        $ticketStmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE status IN ('open', 'in_progress') 
            AND (assigned_to = ? OR assigned_to IS NULL)
        ");
        $ticketStmt->execute([$user_id]);
        $pending_tickets_badge = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets_badge = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (PDOException $e) {
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    $total_tickets_all = $open_tickets_all = $pending_reports = $unread_messages = 0;
    $new_students = $upcoming_meetings = $pending_minutes = $pending_tickets_badge = $pending_docs = 0;
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
            header("Location: tickets.php?view_ticket=" . $ticket_id);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating ticket: " . $e->getMessage();
            header("Location: tickets.php?view_ticket=" . $ticket_id);
            exit();
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
            header("Location: tickets.php?view_ticket=" . $ticket_id);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding comment: " . $e->getMessage();
            header("Location: tickets.php?view_ticket=" . $ticket_id);
            exit();
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
            header("Location: tickets.php?view_ticket=" . $ticket_id);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error escalating ticket: " . $e->getMessage();
            header("Location: tickets.php?view_ticket=" . $ticket_id);
            exit();
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
        $query .= " AND (t.subject ILIKE ? OR t.name ILIKE ? OR t.reg_number ILIKE ?)";
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
        CASE t.priority
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
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
    $ticket_id = (int)$_GET['view_ticket'];
    
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
        SELECT u.id, u.full_name, u.role, cm.role as committee_role
        FROM users u
        JOIN committee_members cm ON u.id = cm.user_id
        WHERE u.status = 'active' AND u.id != ? AND cm.status = 'active'
        ORDER BY u.full_name
    ");
    $stmt->execute([$user_id]);
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching committee members: " . $e->getMessage());
}

// Success/Error messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Support Tickets - Minister of Culture</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #A78BFA;
            --accent-purple: #7C3AED;
            --light-purple: #f3f0ff;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            --primary-purple: #A78BFA;
            --secondary-purple: #C4B5FD;
            --accent-purple: #8B5CF6;
            --light-purple: #1f1a2e;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #4dd0e1;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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
            font-size: 0.85rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.7rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
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
            border-left: 4px solid var(--primary-purple);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.success {
            border-left-color: var(--success);
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
            background: var(--light-purple);
            color: var(--primary-purple);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
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
            background: var(--light-purple);
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .tab:hover {
            color: var(--primary-purple);
        }

        .tab.active {
            color: var(--primary-purple);
            border-bottom-color: var(--primary-purple);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Table */
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
            background: var(--light-purple);
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

        /* Priority Badges */
        .priority-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-urgent {
            background: #dc3545;
            color: white;
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

        /* Search and Filter */
        .search-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-box input {
            padding-left: 2.2rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .filter-select {
            min-width: 150px;
        }

        /* Ticket Details */
        .ticket-header {
            background: var(--light-purple);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        /* Comments Section */
        .comments-section {
            margin-top: 1.5rem;
        }

        .comment {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            background: var(--white);
        }

        .comment-internal {
            border-left: 4px solid var(--warning);
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
            font-size: 0.8rem;
        }

        .comment-role {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .comment-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Escalation History */
        .escalation-item {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
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
            font-size: 0.75rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
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
                background: var(--primary-purple);
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

            #sidebarToggleBtn {
                display: none;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .ticket-meta {
                grid-template-columns: repeat(2, 1fr);
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .search-filter {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .filter-select {
                width: 100%;
            }

            .ticket-meta {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
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

            .page-title h1 {
                font-size: 1.2rem;
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Support Tickets</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                   
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
                    
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Culture & Civic Education</div>
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
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php" class="active">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                        <?php if ($pending_tickets_badge > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets_badge; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php">
                        <i class="fas fa-palette"></i>
                        <span>Cultural Resources</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="troupe.php">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
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
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            
            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Ticket Statistics -->
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
                    <input type="text" class="form-control" id="searchInput" placeholder="Search tickets..." 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           onkeypress="if(event.keyCode==13) searchTickets()">
                </div>
                <select class="form-control filter-select" id="filterSelect" onchange="filterTickets()">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Tickets</option>
                    <option value="open" <?php echo $filter === 'open' ? 'selected' : ''; ?>>Open Tickets</option>
                    <option value="resolved" <?php echo $filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?php echo $filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <button class="btn btn-outline" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?php echo !isset($_GET['view_ticket']) ? 'active' : ''; ?>" onclick="switchTab(event, 'tickets-tab')">All Tickets</button>
                <?php if (isset($_GET['view_ticket'])): ?>
                    <button class="tab active" onclick="switchTab(event, 'ticket-details-tab')">Ticket Details</button>
                <?php endif; ?>
            </div>

            <!-- Tickets List Tab -->
            <div id="tickets-tab" class="tab-content <?php echo !isset($_GET['view_ticket']) ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>Assigned Tickets</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($tickets)): ?>
                            <div class="empty-state">
                                <i class="fas fa-ticket-alt"></i>
                                <p>No tickets found matching your criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
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
                                                    <?php if (!empty($ticket['description'])): ?>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($ticket['description'], 0, 80)) . (strlen($ticket['description']) > 80 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
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
                                                    <span class="status-badge status-<?php echo str_replace('_', '', $ticket['status']); ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
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
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Ticket Details Tab -->
            <?php if (isset($_GET['view_ticket']) && $ticket_details): ?>
                <div id="ticket-details-tab" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3>Ticket #<?php echo $ticket_details['id']; ?> Details</h3>
                            <a href="tickets.php" class="btn btn-outline btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Tickets
                            </a>
                        </div>
                        <div class="card-body">
                            <!-- Ticket Header -->
                            <div class="ticket-header">
                                <h2 style="font-size: 1.1rem;"><?php echo htmlspecialchars($ticket_details['subject']); ?></h2>
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
                                            <span class="status-badge status-<?php echo str_replace('_', '', $ticket_details['status']); ?>">
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
                            <div class="card" style="margin-bottom: 1rem;">
                                <div class="card-header">
                                    <h3>Issue Description</h3>
                                </div>
                                <div class="card-body">
                                    <p><?php echo nl2br(htmlspecialchars($ticket_details['description'])); ?></p>
                                </div>
                            </div>

                            <!-- Update Ticket Status -->
                            <div class="card" style="margin-bottom: 1rem;">
                                <div class="card-header">
                                    <h3>Update Ticket Status</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_details['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-select" required>
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
                                        <div style="display: flex; justify-content: flex-end;">
                                            <button type="submit" name="update_ticket_status" class="btn btn-primary">Update Status</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Add Comment -->
                            <div class="card" style="margin-bottom: 1rem;">
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
                                            <div class="checkbox-group">
                                                <input type="checkbox" name="is_internal" value="1" id="is_internal">
                                                <label for="is_internal">Internal comment (not visible to student)</label>
                                            </div>
                                        </div>
                                        <div style="display: flex; justify-content: flex-end;">
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
                            <div class="card" style="margin-bottom: 1rem;">
                                <div class="card-header">
                                    <h3>Escalate Ticket</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_details['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Escalate To</label>
                                                <select name="escalate_to" class="form-select" required>
                                                    <option value="">Select Committee Member</option>
                                                    <?php foreach ($committee_members as $member): ?>
                                                        <option value="<?php echo $member['id']; ?>">
                                                            <?php echo htmlspecialchars($member['full_name']); ?> - <?php echo htmlspecialchars($member['committee_role']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Reason for Escalation</label>
                                                <textarea name="escalation_reason" class="form-control" rows="3" placeholder="Explain why you're escalating this ticket..." required></textarea>
                                            </div>
                                        </div>
                                        <div style="display: flex; justify-content: flex-end;">
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
                                            <div class="escalation-item">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                                                    <div>
                                                        <strong>Escalated by: <?php echo htmlspecialchars($escalation['escalated_by_name']); ?></strong>
                                                        <div style="font-size: 0.75rem; color: var(--dark-gray);">
                                                            To: <?php echo htmlspecialchars($escalation['escalated_to_name']); ?>
                                                        </div>
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: var(--dark-gray);">
                                                        <?php echo date('M j, Y g:i A', strtotime($escalation['escalated_at'])); ?>
                                                    </div>
                                                </div>
                                                <p style="margin: 0; font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($escalation['reason'])); ?></p>
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
                    : '<i class="fas fa-bars</i>';
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

        // Tab Functions
        function switchTab(event, tabId) {
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
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }
        }

        // Search and Filter Functions
        function searchTickets() {
            const search = document.getElementById('searchInput').value;
            const filter = document.getElementById('filterSelect').value;
            let url = 'tickets.php?';
            
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (filter !== 'all') url += `filter=${filter}`;
            
            window.location.href = url;
        }

        function filterTickets() {
            const search = document.getElementById('searchInput').value;
            const filter = document.getElementById('filterSelect').value;
            let url = 'tickets.php?';
            
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (filter !== 'all') url += `filter=${filter}`;
            
            window.location.href = url;
        }

        function clearFilters() {
            window.location.href = 'tickets.php';
        }
    </script>
</body>
</html>