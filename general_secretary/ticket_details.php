<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$ticket_id = $_GET['id'] ?? 0;

if (!$ticket_id) {
    header('Location: tickets.php');
    exit();
}

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Get ticket details (PostgreSQL syntax)
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               ic.name as category_name, 
               u.full_name as assigned_name, 
               u.role as assigned_role,
               u.email as assigned_email,
               d.name as department_name,
               p.name as program_name
        FROM tickets t 
        LEFT JOIN issue_categories ic ON t.category_id = ic.id 
        LEFT JOIN users u ON t.assigned_to = u.id 
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        header('Location: tickets.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Ticket details error: " . $e->getMessage());
    $ticket = null;
}

// Get ticket comments
try {
    $stmt = $pdo->prepare("
        SELECT tc.*, u.full_name, u.role, u.avatar_url
        FROM ticket_comments tc
        LEFT JOIN users u ON tc.user_id = u.id
        WHERE tc.ticket_id = ?
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$ticket_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Comments error: " . $e->getMessage());
    $comments = [];
}

// Get assignment history
try {
    $stmt = $pdo->prepare("
        SELECT ta.*, u.full_name as assignee_name, u2.full_name as assigned_by_name
        FROM ticket_assignments ta
        LEFT JOIN users u ON ta.assigned_to = u.id
        LEFT JOIN users u2 ON ta.assigned_by = u2.id
        WHERE ta.ticket_id = ?
        ORDER BY ta.assigned_at DESC
    ");
    $stmt->execute([$ticket_id]);
    $assignment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Assignment history error: " . $e->getMessage());
    $assignment_history = [];
}

// Get committee members for reassignment
try {
    $stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE role != 'student' AND role != 'admin' AND status = 'active'
        ORDER BY full_name
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Committee members query error: " . $e->getMessage());
    $committee_members = [];
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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reassign_ticket'])) {
        $new_assignee = $_POST['new_assignee'];
        $reason = $_POST['reason'] ?? "Reassigned by General Secretary";
        
        try {
            // Update ticket assignment
            $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
            $stmt->execute([$new_assignee, $ticket_id]);
            
            // Log the reassignment
            $stmt = $pdo->prepare("
                INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason) 
                VALUES (?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$ticket_id, $new_assignee, $user_id, $reason]);
            
            // Add comment
            $assignee_name = "";
            foreach ($committee_members as $member) {
                if ($member['id'] == $new_assignee) {
                    $assignee_name = $member['full_name'];
                    break;
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, "Ticket reassigned to $assignee_name by General Secretary. Reason: $reason"]);
            
            $_SESSION['success_message'] = "Ticket successfully reassigned";
            header("Location: ticket_details.php?id=$ticket_id");
            exit();
            
        } catch (PDOException $e) {
            error_log("Reassignment error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to reassign ticket";
        }
    }
    
    if (isset($_POST['update_status'])) {
        $new_status = $_POST['new_status'];
        $resolution_notes = $_POST['resolution_notes'] ?? '';
        
        try {
            $update_data = [$new_status, $ticket_id];
            $query = "UPDATE tickets SET status = ?";
            
            if ($new_status === 'resolved') {
                $query .= ", resolved_at = NOW(), resolution_notes = ?";
                $update_data = [$new_status, $resolution_notes, $ticket_id];
            }
            
            $query .= " WHERE id = ?";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($update_data);
            
            // Add comment
            $status_text = ucfirst(str_replace('_', ' ', $new_status));
            $comment = "Status changed to $status_text by General Secretary";
            if ($resolution_notes && $new_status === 'resolved') {
                $comment .= ". Resolution notes: $resolution_notes";
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, $comment]);
            
            $_SESSION['success_message'] = "Ticket status updated successfully";
            header("Location: ticket_details.php?id=$ticket_id");
            exit();
            
        } catch (PDOException $e) {
            error_log("Status update error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to update ticket status";
        }
    }
    
    if (isset($_POST['add_comment'])) {
        $comment = $_POST['comment'];
        $is_internal = isset($_POST['is_internal']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
            
            $_SESSION['success_message'] = "Comment added successfully";
            header("Location: ticket_details.php?id=$ticket_id");
            exit();
            
        } catch (PDOException $e) {
            error_log("Comment error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to add comment";
        }
    }
    
    if (isset($_POST['update_priority'])) {
        $new_priority = $_POST['new_priority'];
        
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET priority = ? WHERE id = ?");
            $stmt->execute([$new_priority, $ticket_id]);
            
            // Add comment
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, "Priority changed to " . ucfirst($new_priority) . " by General Secretary"]);
            
            $_SESSION['success_message'] = "Ticket priority updated successfully";
            header("Location: ticket_details.php?id=$ticket_id");
            exit();
            
        } catch (PDOException $e) {
            error_log("Priority update error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to update ticket priority";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Ticket Details - Isonga RPSU</title>
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

        /* Ticket Header */
        .ticket-header {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .ticket-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .ticket-header h2 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .ticket-badges {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
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

        /* Status & Priority Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
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
            padding: 0.25rem 0.75rem;
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

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Buttons */
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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

        .card-body {
            padding: 1.25rem;
        }

        /* Comments */
        .comment {
            border-bottom: 1px solid var(--medium-gray);
            padding: 1rem 0;
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
            font-size: 0.85rem;
        }

        .comment-role {
            color: var(--dark-gray);
            font-size: 0.7rem;
        }

        .comment-time {
            color: var(--dark-gray);
            font-size: 0.7rem;
        }

        .comment-content {
            color: var(--text-dark);
            line-height: 1.5;
            font-size: 0.85rem;
        }

        .comment-internal {
            background: var(--light-blue);
            border-left: 3px solid var(--primary-blue);
            padding-left: 1rem;
            margin-left: -1rem;
        }

        /* Form */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input {
            width: auto;
        }

        /* History Item */
        .history-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .history-item:last-child {
            border-bottom: none;
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
            border-radius: var(--border-radius-lg);
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
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--danger);
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

        /* Alerts */
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

            .ticket-header-top {
                flex-direction: column;
            }

            .ticket-meta {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                width: 100%;
            }

            .action-buttons .btn {
                flex: 1;
                justify-content: center;
            }

            .comment-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .ticket-header {
                padding: 1rem;
            }

            .ticket-header h2 {
                font-size: 1rem;
            }

            .card-header, .card-body {
                padding: 1rem;
            }

            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
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
                    <h1>Isonga - General Secretary</h1>
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
                        <div class="user-role">General Secretary</div>
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Student Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
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
                    <h1>Ticket #<?php echo $ticket_id; ?> Details</h1>
                    <p>
                        <a href="tickets.php" style="color: var(--primary-blue); text-decoration: none;">
                            <i class="fas fa-arrow-left"></i> Back to Student Tickets
                        </a>
                    </p>
                </div>
            </div>

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

            <?php if ($ticket): ?>
                <!-- Ticket Header -->
                <div class="ticket-header">
                    <div class="ticket-header-top">
                        <div>
                            <h2><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                            <div class="ticket-badges">
                                <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                                <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                    <?php echo ucfirst($ticket['priority']); ?> Priority
                                </span>
                                <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($ticket['category_name']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button class="btn btn-outline btn-sm" onclick="reassignTicket()">
                                <i class="fas fa-user-edit"></i> Reassign
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="updateStatus()">
                                <i class="fas fa-edit"></i> Update Status
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="updatePriority()">
                                <i class="fas fa-flag"></i> Change Priority
                            </button>
                        </div>
                    </div>
                    
                    <div class="ticket-meta">
                        <div class="meta-item">
                            <span class="meta-label">Student</span>
                            <span class="meta-value"><?php echo htmlspecialchars($ticket['name']); ?> (<?php echo htmlspecialchars($ticket['reg_number']); ?>)</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Contact</span>
                            <span class="meta-value"><?php echo htmlspecialchars($ticket['email']); ?> | <?php echo htmlspecialchars($ticket['phone']); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Academic Info</span>
                            <span class="meta-value">
                                <?php echo htmlspecialchars($ticket['academic_year']); ?>
                                <?php if ($ticket['department_name']): ?>
                                    | <?php echo htmlspecialchars($ticket['department_name']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Assigned To</span>
                            <span class="meta-value">
                                <?php if ($ticket['assigned_name']): ?>
                                    <?php echo htmlspecialchars($ticket['assigned_name']); ?> (<?php echo str_replace('_', ' ', $ticket['assigned_role']); ?>)
                                <?php else: ?>
                                    <span style="color: var(--dark-gray); font-style: italic;">Unassigned</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Created</span>
                            <span class="meta-value"><?php echo date('M j, Y g:i A', strtotime($ticket['created_at'])); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Due Date</span>
                            <span class="meta-value">
                                <?php if ($ticket['due_date']): ?>
                                    <?php 
                                    $due_date = new DateTime($ticket['due_date']);
                                    $today = new DateTime();
                                    $is_overdue = $due_date < $today && !in_array($ticket['status'], ['resolved', 'closed']);
                                    ?>
                                    <span style="color: <?php echo $is_overdue ? 'var(--danger)' : 'var(--text-dark)'; ?>;">
                                        <?php echo $due_date->format('M j, Y'); ?>
                                        <?php if ($is_overdue): ?>
                                            (Overdue)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--dark-gray);">Not set</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="content-grid">
                    <!-- Left Column -->
                    <div class="left-column">
                        <!-- Ticket Description -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Issue Description</h3>
                            </div>
                            <div class="card-body">
                                <p style="white-space: pre-wrap; line-height: 1.6;"><?php echo htmlspecialchars($ticket['description']); ?></p>
                            </div>
                        </div>

                        <!-- Comments -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Comments & Activity</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($comments)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-comment"></i>
                                        <p>No comments yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($comments as $comment): ?>
                                        <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                            <div class="comment-header">
                                                <div>
                                                    <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                                                    <span class="comment-role">(<?php echo str_replace('_', ' ', $comment['role']); ?>)</span>
                                                    <?php if ($comment['is_internal']): ?>
                                                        <span style="background: var(--primary-blue); color: white; padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.7rem; margin-left: 0.5rem;">Internal</span>
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
                                
                                <!-- Add Comment Form -->
                                <form method="POST" style="margin-top: 1.5rem;">
                                    <div class="form-group">
                                        <label class="form-label">Add Comment</label>
                                        <textarea name="comment" class="form-textarea" placeholder="Enter your comment..." required></textarea>
                                    </div>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" name="is_internal" value="1" id="is_internal">
                                            <label for="is_internal">Internal comment (visible only to committee members)</label>
                                        </div>
                                    </div>
                                    <button type="submit" name="add_comment" class="btn btn-primary">
                                        <i class="fas fa-comment"></i> Add Comment
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="right-column">
                        <!-- Assignment History -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Assignment History</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($assignment_history)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-history"></i>
                                        <p>No assignment history</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($assignment_history as $assignment): ?>
                                        <div class="history-item">
                                            <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($assignment['assignee_name']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--dark-gray); margin-bottom: 0.25rem;">
                                                Assigned by <?php echo htmlspecialchars($assignment['assigned_by_name']); ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                <?php echo date('M j, Y g:i A', strtotime($assignment['assigned_at'])); ?>
                                                <?php if ($assignment['reason']): ?>
                                                    <br>Reason: <?php echo htmlspecialchars($assignment['reason']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Resolution Notes -->
                        <?php if ($ticket['resolution_notes']): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Resolution Notes</h3>
                                </div>
                                <div class="card-body">
                                    <p style="white-space: pre-wrap;"><?php echo htmlspecialchars($ticket['resolution_notes']); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Modals -->
                <!-- Reassign Modal -->
                <div id="reassignModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Reassign Ticket</h3>
                            <button class="modal-close" onclick="closeModal('reassignModal')">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label class="form-label">Current Assignee</label>
                                    <input type="text" class="form-input" value="<?php echo $ticket['assigned_name'] ? htmlspecialchars($ticket['assigned_name']) : 'Unassigned'; ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">New Assignee</label>
                                    <select name="new_assignee" class="form-select" required>
                                        <option value="">Select Committee Member</option>
                                        <?php foreach ($committee_members as $member): ?>
                                            <option value="<?php echo $member['id']; ?>">
                                                <?php echo htmlspecialchars($member['full_name'] . ' (' . str_replace('_', ' ', $member['role']) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Reason for Reassignment</label>
                                    <input type="text" name="reason" class="form-input" placeholder="Enter reason for reassignment..." required>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline" onclick="closeModal('reassignModal')">Cancel</button>
                                <button type="submit" name="reassign_ticket" class="btn btn-primary">Reassign</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Update Status Modal -->
                <div id="statusModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Update Ticket Status</h3>
                            <button class="modal-close" onclick="closeModal('statusModal')">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label class="form-label">Current Status</label>
                                    <input type="text" class="form-input" value="<?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">New Status</label>
                                    <select name="new_status" class="form-select" id="statusSelect" required>
                                        <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div class="form-group" id="resolutionNotesGroup" style="display: none;">
                                    <label class="form-label">Resolution Notes</label>
                                    <textarea name="resolution_notes" class="form-textarea" placeholder="Enter resolution details..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
                                <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Update Priority Modal -->
                <div id="priorityModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Change Ticket Priority</h3>
                            <button class="modal-close" onclick="closeModal('priorityModal')">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label class="form-label">Current Priority</label>
                                    <input type="text" class="form-input" value="<?php echo ucfirst($ticket['priority']); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">New Priority</label>
                                    <select name="new_priority" class="form-select" required>
                                        <option value="low" <?php echo $ticket['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo $ticket['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo $ticket['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline" onclick="closeModal('priorityModal')">Cancel</button>
                                <button type="submit" name="update_priority" class="btn btn-primary">Update Priority</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> Ticket not found
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
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function reassignTicket() {
            openModal('reassignModal');
        }

        function updateStatus() {
            openModal('statusModal');
        }

        function updatePriority() {
            openModal('priorityModal');
        }

        // Show/hide resolution notes based on status
        const statusSelect = document.getElementById('statusSelect');
        const resolutionNotesGroup = document.getElementById('resolutionNotesGroup');
        
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                if (this.value === 'resolved') {
                    resolutionNotesGroup.style.display = 'block';
                } else {
                    resolutionNotesGroup.style.display = 'none';
                }
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.ticket-header, .card');
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