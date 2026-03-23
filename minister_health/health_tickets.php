<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Health
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_health') {
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
    if (isset($_POST['action'])) {
        $ticket_id = $_POST['ticket_id'] ?? null;
        
        switch ($_POST['action']) {
            case 'update_status':
                $status = $_POST['status'];
                $resolution_notes = $_POST['resolution_notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE tickets 
                        SET status = ?, resolution_notes = ?, resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END 
                        WHERE id = ? AND assigned_to = ?
                    ");
                    $stmt->execute([$status, $resolution_notes, $status, $ticket_id, $user_id]);
                    
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
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
                    
                    $_SESSION['success_message'] = "Comment added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding comment: " . $e->getMessage();
                }
                break;
                
            case 'assign_ticket':
                $assigned_to = $_POST['assigned_to'];
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE tickets 
                        SET assigned_to = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$assigned_to, $ticket_id]);
                    
                    // Log assignment
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                        VALUES (?, ?, ?, NOW(), 'Manual assignment by Minister of Health')
                    ");
                    $stmt->execute([$ticket_id, $assigned_to, $user_id]);
                    
                    $_SESSION['success_message'] = "Ticket reassigned successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error reassigning ticket: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: health_tickets.php" . ($ticket_id ? "?view=" . $ticket_id : ""));
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
           ic.name as category_name,
           d.name as department_name,
           p.name as program_name,
           u_assigned.full_name as assigned_to_name,
           u_assigned.role as assigned_to_role
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN users u_student ON t.reg_number = u_student.reg_number
    LEFT JOIN departments d ON u_student.department_id = d.id
    LEFT JOIN programs p ON u_student.program_id = p.id
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    WHERE t.category_id IN (2, 4, 10)  -- Accommodation, Health, Other
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
    $query .= " AND (t.name LIKE ? OR t.reg_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

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

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tickets = [];
    error_log("Tickets query error: " . $e->getMessage());
}

// Get ticket counts for statistics
try {
    // Total health tickets
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM tickets 
        WHERE category_id IN (2, 4, 10)
    ");
    $stmt->execute();
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as open_count 
        FROM tickets 
        WHERE category_id IN (2, 4, 10) AND status = 'open'
    ");
    $stmt->execute();
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_count'] ?? 0;
    
    // In progress tickets
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as progress_count 
        FROM tickets 
        WHERE category_id IN (2, 4, 10) AND status = 'in_progress'
    ");
    $stmt->execute();
    $progress_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['progress_count'] ?? 0;
    
    // High priority tickets
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as high_count 
        FROM tickets 
        WHERE category_id IN (2, 4, 10) AND priority = 'high' AND status IN ('open', 'in_progress')
    ");
    $stmt->execute();
    $high_priority = $stmt->fetch(PDO::FETCH_ASSOC)['high_count'] ?? 0;
    
    // Tickets by category
    $stmt = $pdo->prepare("
        SELECT ic.name as category_name, COUNT(*) as count
        FROM tickets t
        JOIN issue_categories ic ON t.category_id = ic.id
        WHERE t.category_id IN (2, 4, 10)
        GROUP BY ic.id, ic.name
    ");
    $stmt->execute();
    $tickets_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Ticket statistics error: " . $e->getMessage());
    $total_tickets = $open_tickets = $progress_tickets = $high_priority = 0;
    $tickets_by_category = [];
}

// Get committee members for assignment
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, cm.role as committee_role
        FROM users u
        LEFT JOIN committee_members cm ON u.id = cm.user_id
        WHERE u.role IN ('minister_health', 'vice_guild_academic', 'general_secretary', 'minister_gender')
        AND u.status = 'active'
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
    error_log("Committee members error: " . $e->getMessage());
}

// Handle single ticket view
$view_ticket = null;
$ticket_comments = [];
$ticket_assignments = [];

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $ticket_id = $_GET['view'];
    
    try {
        // Get ticket details
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   ic.name as category_name,
                   d.name as department_name,
                   p.name as program_name,
                   u_assigned.full_name as assigned_to_name,
                   u_assigned.role as assigned_to_role,
                   u_assigned.email as assigned_to_email
            FROM tickets t
            LEFT JOIN issue_categories ic ON t.category_id = ic.id
            LEFT JOIN users u_student ON t.reg_number = u_student.reg_number
            LEFT JOIN departments d ON u_student.department_id = d.id
            LEFT JOIN programs p ON u_student.program_id = p.id
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticket_id]);
        $view_ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get ticket comments
        $stmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.role
            FROM ticket_comments tc
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ");
        $stmt->execute([$ticket_id]);
        $ticket_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get ticket assignment history
        $stmt = $pdo->prepare("
            SELECT ta.*, u_assigned.full_name as assigned_to_name, u_assigned_by.full_name as assigned_by_name
            FROM ticket_assignments ta
            LEFT JOIN users u_assigned ON ta.assigned_to = u_assigned.id
            LEFT JOIN users u_assigned_by ON ta.assigned_by = u_assigned_by.id
            WHERE ta.ticket_id = ?
            ORDER BY ta.assigned_at DESC
        ");
        $stmt->execute([$ticket_id]);
        $ticket_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Ticket view error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading ticket details.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Tickets Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #20c997;
            --accent-green: #198754;
            --light-green: #d1f2eb;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-green: #20c997;
            --secondary-green: #3dd9a7;
            --accent-green: #198754;
            --light-green: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
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
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-description {
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
        }

        .btn-outline:hover {
            background: var(--primary-green);
            color: white;
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

        .stat-card.info {
            border-left-color: var(--info);
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

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
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
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
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
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        /* Tables */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
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
            color: var(--info);
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
            background: #f8d7da;
            color: var(--danger);
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

        .action-btn {
            padding: 0.25rem 0.5rem;
            border: none;
            background: none;
            color: var(--primary-green);
            cursor: pointer;
            border-radius: 4px;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .action-btn:hover {
            background: var(--light-green);
        }

        /* Ticket View */
        .ticket-view {
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
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .ticket-body {
            padding: 1.25rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .ticket-details {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .ticket-section {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            border-bottom: 1px solid var(--medium-gray);
            padding-bottom: 0.5rem;
        }

        .ticket-description {
            line-height: 1.6;
            color: var(--text-dark);
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .comment-item {
            padding: 1rem;
            border-radius: var(--border-radius);
            background: var(--light-gray);
            border-left: 3px solid var(--primary-green);
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

        .comment-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .comment-content {
            color: var(--text-dark);
            line-height: 1.5;
        }

        .comment-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .form-textarea {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            resize: vertical;
            min-height: 100px;
            transition: var(--transition);
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ticket-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-card {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
        }

        .sidebar-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }

        .info-grid {
            display: grid;
            gap: 0.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .info-value {
            color: var(--text-dark);
            font-size: 0.8rem;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .ticket-body {
                grid-template-columns: 1fr;
            }
            
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .page-actions {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .table {
                font-size: 0.7rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
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
                    <h1>Isonga - Health Tickets</h1>
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
                        <div class="user-role">Minister of Health</div>
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
                    <a href="health_tickets.php"  class="active">
                        <i class="fas fa-heartbeat"></i>
                        <span>Health Issues</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostels.php">
                        <i class="fas fa-bed"></i>
                        <span>Hostel Management</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="campaigns.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Health Campaigns</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="epidemics.php">
                        <i class="fas fa-virus"></i>
                        <span>Epidemic Prevention</span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Health & Welfare Tickets</h1>
                    <p class="page-description">Manage student health issues, accommodation problems, and welfare concerns</p>
                </div>
                <div class="page-actions">
                    <a href="health_tickets.php" class="btn btn-outline">
                        <i class="fas fa-list"></i> All Tickets
                    </a>
                    <button class="btn btn-primary" onclick="document.getElementById('exportForm').submit()">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <form id="exportForm" action="export_tickets.php" method="POST" style="display: none;">
                        <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
                        <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                        <input type="hidden" name="priority" value="<?php echo $priority_filter; ?>">
                    </form>
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

            <?php if ($view_ticket): ?>
                <!-- Single Ticket View -->
                <div class="ticket-view">
                    <div class="ticket-header">
                        <h2 class="ticket-title"><?php echo htmlspecialchars($view_ticket['subject']); ?></h2>
                        <div class="ticket-meta">
                            <span><strong>Ticket ID:</strong> #<?php echo $view_ticket['id']; ?></span>
                            <span><strong>Student:</strong> <?php echo htmlspecialchars($view_ticket['name']); ?> (<?php echo htmlspecialchars($view_ticket['reg_number']); ?>)</span>
                            <span><strong>Department:</strong> <?php echo htmlspecialchars($view_ticket['department_name'] ?? 'N/A'); ?></span>
                            <span><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($view_ticket['created_at'])); ?></span>
                        </div>
                    </div>
                    
                    <div class="ticket-body">
                        <div class="ticket-details">
                            <!-- Ticket Description -->
                            <div class="ticket-section">
                                <h3 class="section-title">Issue Description</h3>
                                <div class="ticket-description">
                                    <?php echo nl2br(htmlspecialchars($view_ticket['description'])); ?>
                                </div>
                            </div>

                            <!-- Comments -->
                            <div class="ticket-section">
                                <h3 class="section-title">Comments & Updates</h3>
                                <div class="comments-list">
                                    <?php if (empty($ticket_comments)): ?>
                                        <p style="color: var(--dark-gray); text-align: center; padding: 2rem;">No comments yet</p>
                                    <?php else: ?>
                                        <?php foreach ($ticket_comments as $comment): ?>
                                            <div class="comment-item">
                                                <div class="comment-header">
                                                    <span class="comment-author">
                                                        <?php echo htmlspecialchars($comment['full_name']); ?>
                                                        <?php if ($comment['is_internal']): ?>
                                                            <span style="color: var(--warning); font-size: 0.7rem;">(Internal Note)</span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="comment-time">
                                                        <?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?>
                                                    </span>
                                                </div>
                                                <div class="comment-content">
                                                    <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Add Comment Form -->
                                <form method="POST" class="comment-form">
                                    <input type="hidden" name="action" value="add_comment">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    
                                    <textarea name="comment" class="form-textarea" placeholder="Add a comment or update..." required></textarea>
                                    
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="is_internal" id="is_internal" value="1">
                                        <label for="is_internal">Internal note (not visible to student)</label>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-comment"></i> Add Comment
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="ticket-sidebar">
                            <!-- Ticket Actions -->
                            <div class="sidebar-card">
                                <h3 class="sidebar-title">Ticket Actions</h3>
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select" onchange="this.form.submit()">
                                            <option value="open" <?php echo $view_ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="in_progress" <?php echo $view_ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="resolved" <?php echo $view_ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="closed" <?php echo $view_ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Resolution Notes</label>
                                        <textarea name="resolution_notes" class="form-textarea" placeholder="Add resolution notes..."><?php echo htmlspecialchars($view_ticket['resolution_notes'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                                        <i class="fas fa-save"></i> Update Status
                                    </button>
                                </form>
                            </div>

                            <!-- Ticket Information -->
                            <div class="sidebar-card">
                                <h3 class="sidebar-title">Ticket Information</h3>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Category:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_ticket['category_name']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Priority:</span>
                                        <span class="priority-badge priority-<?php echo $view_ticket['priority']; ?>">
                                            <?php echo ucfirst($view_ticket['priority']); ?>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Status:</span>
                                        <span class="status-badge status-<?php echo $view_ticket['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $view_ticket['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Assigned To:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_ticket['assigned_to_name'] ?? 'Unassigned'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Contact:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_ticket['email']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Phone:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_ticket['phone']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Assignment History -->
                            <?php if (!empty($ticket_assignments)): ?>
                                <div class="sidebar-card">
                                    <h3 class="sidebar-title">Assignment History</h3>
                                    <div class="info-grid">
                                        <?php foreach ($ticket_assignments as $assignment): ?>
                                            <div class="info-item">
                                                <div>
                                                    <div style="font-weight: 600; font-size: 0.75rem;"><?php echo htmlspecialchars($assignment['assigned_to_name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                        <?php echo date('M j, g:i A', strtotime($assignment['assigned_at'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Reassign Ticket -->
                            <div class="sidebar-card">
                                <h3 class="sidebar-title">Reassign Ticket</h3>
                                <form method="POST">
                                    <input type="hidden" name="action" value="assign_ticket">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    
                                    <div class="form-group">
                                        <select name="assigned_to" class="form-select" required>
                                            <option value="">Select assignee...</option>
                                            <?php foreach ($committee_members as $member): ?>
                                                <option value="<?php echo $member['id']; ?>" <?php echo $view_ticket['assigned_to'] == $member['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($member['full_name'] . ' (' . $member['role'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-outline" style="width: 100%;">
                                        <i class="fas fa-user-plus"></i> Reassign
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Tickets List View -->
                
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card success">
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
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $progress_tickets; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $high_priority; ?></div>
                            <div class="stat-label">High Priority</div>
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
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <option value="2" <?php echo $category_filter === '2' ? 'selected' : ''; ?>>Accommodation</option>
                                <option value="4" <?php echo $category_filter === '4' ? 'selected' : ''; ?>>Health & Wellness</option>
                                <option value="10" <?php echo $category_filter === '10' ? 'selected' : ''; ?>>Other</option>
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
                            <a href="health_tickets.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Tickets Table -->
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
                                <th>Created</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No tickets found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td>#<?php echo $ticket['id']; ?></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($ticket['name']); ?></div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($ticket['reg_number']); ?></div>
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
                                        <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                        <td>
                                            <a href="?view=<?php echo $ticket['id']; ?>" class="action-btn" title="View Ticket">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
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

        // Auto-refresh tickets every 2 minutes
        setInterval(() => {
            if (!document.querySelector('.ticket-view')) {
                window.location.reload();
            }
        }, 120000);

        // Add confirmation for critical actions
        document.addEventListener('DOMContentLoaded', function() {
            const statusSelects = document.querySelectorAll('select[name="status"]');
            statusSelects.forEach(select => {
                select.addEventListener('change', function() {
                    if (this.value === 'closed') {
                        if (!confirm('Are you sure you want to close this ticket? This action cannot be undone.')) {
                            this.value = '<?php echo $view_ticket['status'] ?? "open"; ?>';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>