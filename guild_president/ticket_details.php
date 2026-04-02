<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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

// Get ticket details
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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reassign_ticket'])) {
        $new_assignee = $_POST['new_assignee'];
        $reason = $_POST['reason'] ?? "Reassigned by Guild President";
        
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
            $stmt->execute([$ticket_id, $user_id, "Ticket reassigned to $assignee_name by Guild President. Reason: $reason"]);
            
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
            $comment = "Status changed to $status_text by Guild President";
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
            $stmt->execute([$ticket_id, $user_id, "Priority changed to " . ucfirst($new_priority) . " by Guild President"]);
            
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        }

        [data-theme="dark"] {
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
            --info: #4fc3f7;
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
            font-size: 1.25rem;
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
            width: 70px;
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: 70px;
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

        /* Overlay for mobile */
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
        .filters-container {
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
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .btn {
            padding: 0.6rem 1.25rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
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

        .btn-outline.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
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

        .table tbody tr:hover {
            background: var(--light-blue);
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

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            color: var(--text-dark);
            border-radius: var(--border-radius);
            text-decoration: none;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .pagination-btn:hover {
            background: var(--light-blue);
            border-color: var(--primary-blue);
        }

        .pagination-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
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
            font-size: 1.25rem;
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-number {
                font-size: 1.1rem;
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

            .action-buttons {
                flex-direction: column;
            }
        }
        
        .ticket-header {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
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
        
        .card-body {
            padding: 1.25rem;
        }
        
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
        }
        
        .comment-author {
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .comment-role {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }
        
        .comment-time {
            color: var(--dark-gray);
            font-size: 0.75rem;
        }
        
        .comment-content {
            color: var(--text-dark);
            line-height: 1.5;
        }
        
        .comment-internal {
            background: var(--light-blue);
            border-left: 3px solid var(--primary-blue);
            padding-left: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        
        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }
        
        .history-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
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
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
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
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
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
                    <h1>Ticket #<?php echo $ticket_id; ?> Details</h1>
                    <p>
                        <a href="tickets.php" style="color: var(--primary-blue); text-decoration: none;">
                            <i class="fas fa-arrow-left"></i> Back to All Tickets
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
                    <div style="display: flex; justify-content: between; align-items: start; margin-bottom: 1rem;">
                        <div>
                            <h2 style="margin-bottom: 0.5rem; color: var(--text-dark);"><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                                <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                    <?php echo ucfirst($ticket['priority']); ?> Priority
                                </span>
                                <span style="color: var(--dark-gray);">
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
                                    <p style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No comments yet
                                    </p>
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
                                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem;">
                                            <input type="checkbox" name="is_internal" value="1">
                                            Internal comment (visible only to committee members)
                                        </label>
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
                                    <p style="text-align: center; color: var(--dark-gray);">
                                        No assignment history
                                    </p>
                                <?php else: ?>
                                    <?php foreach ($assignment_history as $assignment): ?>
                                        <div class="history-item">
                                            <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($assignment['assignee_name']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0.25rem;">
                                                Assigned by <?php echo htmlspecialchars($assignment['assigned_by_name']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--dark-gray);">
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
                            <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
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
                            <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label class="form-label">Current Status</label>
                                    <input type="text" class="form-input" value="<?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">New Status</label>
                                    <select name="new_status" class="form-select" required>
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
                            <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
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

        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');

        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.documentElement.setAttribute('data-theme', isDark ? 'light' : 'dark');
            localStorage.setItem('theme', isDark ? 'light' : 'dark');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
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
        document.querySelector('select[name="new_status"]').addEventListener('change', function() {
            const resolutionNotesGroup = document.getElementById('resolutionNotesGroup');
            if (this.value === 'resolved') {
                resolutionNotesGroup.style.display = 'block';
            } else {
                resolutionNotesGroup.style.display = 'none';
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>