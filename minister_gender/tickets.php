<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
case 'update_status':
    $ticket_id = $_POST['ticket_id'];
    $new_status = $_POST['status'];
    $resolution_notes = $_POST['resolution_notes'] ?? '';
    
    error_log("DEBUG: Updating ticket $ticket_id to status $new_status");
    error_log("DEBUG: User ID: $user_id, Resolution notes: $resolution_notes");
    
    try {
        // First, check if the ticket exists and is assigned to this user
        $check_stmt = $pdo->prepare("SELECT id, assigned_to FROM tickets WHERE id = ?");
        $check_stmt->execute([$ticket_id]);
        $ticket = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            $_SESSION['error_message'] = "Ticket not found!";
            error_log("DEBUG: Ticket not found - ID: $ticket_id");
            break;
        }
        
        if ($ticket['assigned_to'] != $user_id) {
            $_SESSION['error_message'] = "You are not assigned to this ticket!";
            error_log("DEBUG: User $user_id not assigned to ticket. Assigned to: " . $ticket['assigned_to']);
            break;
        }
        
        // Update the ticket
        $stmt = $pdo->prepare("
            UPDATE tickets 
            SET status = ?, 
                resolution_notes = ?, 
                resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END, 
                updated_at = NOW()
            WHERE id = ? AND assigned_to = ?
        ");
        
        $result = $stmt->execute([$new_status, $resolution_notes, $new_status, $ticket_id, $user_id]);
        $rowCount = $stmt->rowCount();
        
        error_log("DEBUG: Update executed. Result: " . ($result ? 'true' : 'false') . ", Rows affected: $rowCount");
        
        if ($rowCount > 0) {
            $_SESSION['success_message'] = "Ticket status updated successfully from " . $ticket['status'] . " to $new_status!";
        } else {
            $_SESSION['error_message'] = "No changes made. Ticket might not exist or you don't have permission.";
        }
        
    } catch (PDOException $e) {
        $error_msg = "Error updating ticket: " . $e->getMessage();
        $_SESSION['error_message'] = $error_msg;
        error_log("DEBUG: PDO Exception: " . $e->getMessage());
    }
    break;
                
            case 'add_comment':
                $ticket_id = $_POST['ticket_id'];
                $comment = $_POST['comment'];
                $is_internal = $_POST['is_internal'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
                    
                    $_SESSION['success_message'] = "Comment added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding comment: " . $e->getMessage();
                }
                break;
                
            case 'escalate_ticket':
                $ticket_id = $_POST['ticket_id'];
                $escalated_to = $_POST['escalated_to'];
                $reason = $_POST['reason'];
                
                try {
                    // Get current assignee
                    $stmt = $pdo->prepare("SELECT assigned_to FROM tickets WHERE id = ?");
                    $stmt->execute([$ticket_id]);
                    $current_assignee = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_to'];
                    
                    // Update ticket assignment
                    $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ?, escalation_level = escalation_level + 1, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$escalated_to, $ticket_id]);
                    
                    // Record escalation
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_to, reason, escalated_at, previous_assignee) 
                        VALUES (?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$ticket_id, $user_id, $escalated_to, $reason, $current_assignee]);
                    
                    $_SESSION['success_message'] = "Ticket escalated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error escalating ticket: " . $e->getMessage();
                }
                break;
                
            case 'assign_ticket':
                $ticket_id = $_POST['ticket_id'];
                $assigned_to = $_POST['assigned_to'];
                $reason = $_POST['reason'] ?? '';
                
                try {
                    // Update ticket assignment
                    $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$assigned_to, $ticket_id]);
                    
                    // Record assignment
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason) 
                        VALUES (?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$ticket_id, $assigned_to, $user_id, $reason]);
                    
                    $_SESSION['success_message'] = "Ticket assigned successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error assigning ticket: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: tickets.php");
        exit();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for tickets - Gender-related issues (category_id = 7)
$query = "
    SELECT t.*, 
           ic.name as category_name,
           d.name as department_name,
           p.name as program_name,
           u_assigned.full_name as assigned_to_name,
           u_assigned.role as assigned_to_role
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN departments d ON t.department_id = d.id
    LEFT JOIN programs p ON t.program_id = p.id
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    WHERE t.category_id = 7  -- Gender-related issues
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

if ($search_query) {
    $query .= " AND (t.name LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR t.reg_number LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Order by creation date (newest first)
$query .= " ORDER BY t.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Tickets query error: " . $e->getMessage());
    $tickets = [];
}

// Get ticket statistics
try {
    // Total tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tickets WHERE category_id = 7");
    $stmt->execute();
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Tickets by status
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM tickets 
        WHERE category_id = 7 
        GROUP BY status
    ");
    $stmt->execute();
    $tickets_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tickets by priority
    $stmt = $pdo->prepare("
        SELECT priority, COUNT(*) as count 
        FROM tickets 
        WHERE category_id = 7 
        GROUP BY priority
    ");
    $stmt->execute();
    $tickets_by_priority = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Ticket statistics error: " . $e->getMessage());
    $total_tickets = 0;
    $tickets_by_status = [];
    $tickets_by_priority = [];
}

// Get committee members for escalation and assignment
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.email
        FROM users u
        WHERE u.role IN ('guild_president', 'vice_guild_academic', 'vice_guild_finance', 'general_secretary', 'minister_gender')
        AND u.status = 'active'
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Committee members error: " . $e->getMessage());
    $committee_members = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gender Issues Management - Minister of Gender & Protocol</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #a78bfa;
            --accent-purple: #7c3aed;
            --light-purple: #f3f4f6;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-purple: #a78bfa;
            --secondary-purple: #c4b5fd;
            --accent-purple: #8b5cf6;
            --light-purple: #1f2937;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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
            border-left: 3px solid var(--primary-purple);
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
            background: var(--light-purple);
            color: var(--primary-purple);
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
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-select, .form-input {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-purple);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-purple);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Tickets Table */
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

        .card-body {
            padding: 1.25rem;
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
            background: #cce7ff;
            color: var(--primary-purple);
        }

        .status-in_progress {
            background: #fff3cd;
            color: var(--warning);
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
            background: #d1ecf1;
            color: #0c5460;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            max-width: 600px;
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
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-checkbox {
            margin-right: 0.5rem;
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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

        /* Ticket Details */
        .ticket-details {
            display: grid;
            gap: 1rem;
        }

        .detail-section {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
        }

        .detail-section h4 {
            margin-bottom: 0.75rem;
            color: var(--primary-purple);
            font-size: 0.9rem;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 120px 1fr;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .detail-value {
            color: var(--text-dark);
        }

        .comments-section {
            max-height: 300px;
            overflow-y: auto;
        }

        .comment {
            padding: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .comment:last-child {
            border-bottom: none;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.7rem;
        }

        .comment-author {
            font-weight: 600;
            color: var(--primary-purple);
        }

        .comment-date {
            color: var(--dark-gray);
        }

        .comment-internal {
            background: #fff3cd;
            border-left: 3px solid var(--warning);
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
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
                    <h1>Isonga - Minister of Gender & Protocol</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
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
                        <div class="user-role">Minister of Gender & Protocol</div>
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
            <a href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="tickets.php" class="active">
                <i class="fas fa-ticket-alt"></i>
                <span>Gender Issues</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="protocol.php">
                <i class="fas fa-handshake"></i>
                <span>Protocol & Visitors</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="clubs.php">
                <i class="fas fa-users"></i>
                <span>Gender Clubs</span>
            </a>
        </li>

        <li class="menu-item">
            <a href="hostel-management.php">
                <i class="fas fa-building"></i>
                <span>Hostel Management</span>
            </a>
        </li>
        
        <!-- Added Action Funding -->
        <li class="menu-item">
            <a href="action-funding.php">
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
            <div class="page-header">
                <div class="page-title">
                    <h1>Gender Issues Management</h1>
                    <p>Manage and resolve gender-related concerns and complaints</p>
                </div>
            </div>

            <!-- Alert Messages -->
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

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_tickets; ?></div>
                        <div class="stat-label">Total Issues</div>
                    </div>
                </div>
                <?php 
                $open_tickets = 0;
                $in_progress_tickets = 0;
                $resolved_tickets = 0;
                
                foreach ($tickets_by_status as $status) {
                    switch ($status['status']) {
                        case 'open':
                            $open_tickets = $status['count'];
                            break;
                        case 'in_progress':
                            $in_progress_tickets = $status['count'];
                            break;
                        case 'resolved':
                            $resolved_tickets = $status['count'];
                            break;
                    }
                }
                ?>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_tickets; ?></div>
                        <div class="stat-label">Open Issues</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $in_progress_tickets; ?></div>
                        <div class="stat-label">In Progress</div>
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
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
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
                        <input type="text" name="search" class="form-input" placeholder="Search by name, subject, or description..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="tickets.php" class="btn btn-secondary" style="margin-top: 0.5rem;">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Tickets Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Gender Issues (<?php echo count($tickets); ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($tickets)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No gender issues found matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Subject</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tickets as $ticket): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($ticket['name']); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($ticket['reg_number']); ?></div>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?php echo htmlspecialchars(substr($ticket['description'], 0, 50)); ?>...
                                                </div>
                                            </td>
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
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($ticket['assigned_to_name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars(str_replace('_', ' ', $ticket['assigned_to_role'])); ?></div>
                                                <?php else: ?>
                                                    <span style="color: var(--dark-gray); font-style: italic;">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-size: 0.8rem;"><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo date('g:i A', strtotime($ticket['created_at'])); ?></div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-primary btn-sm" onclick="viewTicket(<?php echo $ticket['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <?php if ($ticket['status'] !== 'resolved' && $ticket['status'] !== 'closed'): ?>
                                                        <button class="btn btn-secondary btn-sm" onclick="openUpdateModal(<?php echo $ticket['id']; ?>, '<?php echo $ticket['status']; ?>')">
                                                            <i class="fas fa-edit"></i> Update
                                                        </button>
                                                    <?php endif; ?>
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
        </main>
    </div>

    <!-- View Ticket Modal -->
    <div id="viewTicketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Ticket Details</h3>
                <button class="modal-close" onclick="closeModal('viewTicketModal')">&times;</button>
            </div>
            <div class="modal-body" id="ticketDetails">
                <!-- Ticket details will be loaded here via AJAX -->
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Ticket Status</h3>
                <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="updateStatusForm" method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="ticket_id" id="update_ticket_id">
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="status_select" class="form-select" required>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-textarea" placeholder="Add any notes about the resolution..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Comment Modal -->
    <div id="addCommentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Comment</h3>
                <button class="modal-close" onclick="closeModal('addCommentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addCommentForm" method="POST">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="ticket_id" id="comment_ticket_id">
                    
                    <div class="form-group">
                        <label class="form-label">Comment</label>
                        <textarea name="comment" class="form-textarea" placeholder="Enter your comment..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_internal" value="1" class="form-checkbox">
                            Internal Comment (not visible to student)
                        </label>
                    </div>
                    
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-comment"></i> Add Comment
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addCommentModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Escalate Ticket Modal -->
    <div id="escalateTicketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Escalate Ticket</h3>
                <button class="modal-close" onclick="closeModal('escalateTicketModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="escalateTicketForm" method="POST">
                    <input type="hidden" name="action" value="escalate_ticket">
                    <input type="hidden" name="ticket_id" id="escalate_ticket_id">
                    
                    <div class="form-group">
                        <label class="form-label">Escalate To</label>
                        <select name="escalated_to" class="form-select" required>
                            <option value="">Select Committee Member</option>
                            <?php foreach ($committee_members as $member): ?>
                                <?php if ($member['id'] != $user_id): ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo htmlspecialchars($member['full_name']); ?> - <?php echo htmlspecialchars(str_replace('_', ' ', $member['role'])); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason for Escalation</label>
                        <textarea name="reason" class="form-textarea" placeholder="Explain why you are escalating this ticket..." required></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-level-up-alt"></i> Escalate Ticket
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('escalateTicketModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
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
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function viewTicket(ticketId) {
            // Load ticket details via AJAX
            fetch(`get_ticket_details.php?id=${ticketId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(data => {
                    document.getElementById('ticketDetails').innerHTML = data;
                    openModal('viewTicketModal');
                })
                .catch(error => {
                    console.error('Error loading ticket details:', error);
                    document.getElementById('ticketDetails').innerHTML = `
                        <div class="alert alert-error">
                            <p>Error loading ticket details. Please try again.</p>
                        </div>
                    `;
                    openModal('viewTicketModal');
                });
        }

        function openUpdateModal(ticketId, currentStatus) {
            document.getElementById('update_ticket_id').value = ticketId;
            document.getElementById('status_select').value = currentStatus;
            openModal('updateStatusModal');
        }

        function openCommentModal(ticketId) {
            document.getElementById('comment_ticket_id').value = ticketId;
            document.getElementById('addCommentForm').reset();
            openModal('addCommentModal');
        }

        function openEscalateModal(ticketId) {
            document.getElementById('escalate_ticket_id').value = ticketId;
            document.getElementById('escalateTicketForm').reset();
            openModal('escalateTicketModal');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }

        // Form submission handlers
        document.getElementById('updateStatusForm')?.addEventListener('submit', function(e) {
            const status = document.getElementById('status_select').value;
            if (status === 'resolved' && !confirm('Are you sure you want to mark this ticket as resolved?')) {
                e.preventDefault();
            }
        });

        document.getElementById('escalateTicketForm')?.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to escalate this ticket?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>