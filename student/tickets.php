<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: tickets.php');
    exit();
}

// Handle new ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $category_id = $_POST['category_id'];
    $subject = $_POST['subject'];
    $description = $_POST['description'];
    $priority = $_POST['priority'];
    $preferred_contact = $_POST['preferred_contact'];
    
    try {
        // Insert the ticket
        $stmt = $pdo->prepare("
            INSERT INTO tickets (reg_number, name, email, phone, department_id, program_id, academic_year, category_id, subject, description, priority, preferred_contact, status)
            SELECT u.reg_number, u.full_name, u.email, u.phone, u.department_id, u.program_id, u.academic_year, ?, ?, ?, ?, ?, 'open'
            FROM users u 
            WHERE u.id = ?
        ");
        
        $stmt->execute([$category_id, $subject, $description, $priority, $preferred_contact, $student_id]);
        
        $ticket_id = $pdo->lastInsertId();
        
        // Auto-assignment logic
        $findAssigneeStmt = $pdo->prepare("
            SELECT u.id 
            FROM issue_categories ic
            JOIN users u ON ic.auto_assign_role = u.role
            WHERE ic.id = ? 
            AND u.status = 'active'
            ORDER BY u.id
            LIMIT 1
        ");
        
        $findAssigneeStmt->execute([$category_id]);
        $assigned_to = $findAssigneeStmt->fetchColumn();
        
        if ($assigned_to) {
            // Update the ticket with the assigned user
            $updateTicketStmt = $pdo->prepare("
                UPDATE tickets 
                SET assigned_to = ?
                WHERE id = ?
            ");
            $updateTicketStmt->execute([$assigned_to, $ticket_id]);
            
            // Create assignment record
            $assignStmt = $pdo->prepare("
                INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                VALUES (?, ?, ?, NOW(), 'Auto-assigned based on issue category')
            ");
            
            $assignStmt->execute([$ticket_id, $assigned_to, $student_id]);
            
            $_SESSION['success_message'] = "Ticket #$ticket_id submitted successfully! It has been assigned to a staff member.";
        } else {
            $_SESSION['success_message'] = "Ticket #$ticket_id submitted successfully! It will be assigned shortly.";
        }
        
        header('Location: tickets.php');
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Failed to submit ticket. Please try again. Error: " . $e->getMessage();
    }
}

// Handle adding comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $ticket_id = $_POST['ticket_id'];
    $comment = $_POST['comment'];
    $is_internal = 0; // Student comments are always external
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$ticket_id, $student_id, $comment, $is_internal]);
        
        $_SESSION['success_message'] = "Comment added successfully!";
        header('Location: tickets.php?view=' . $ticket_id);
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Failed to add comment. Please try again.";
    }
}

// Handle filters and search
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$view_ticket_id = isset($_GET['view']) ? $_GET['view'] : null;

// Build query for tickets
$query = "
    SELECT t.*, ic.name as category_name, 
           u.full_name as assigned_to_name, t.status as ticket_status,
           CURRENT_DATE - t.created_at::date as days_old
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.reg_number = ?
";

$params = [$reg_number];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($category_filter !== 'all') {
    $query .= " AND t.category_id = ?";
    $params[] = $category_filter;
}

if (!empty($search_query)) {
    $query .= " AND (t.subject LIKE ? OR t.description LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY t.created_at DESC";

// Get tickets
$tickets_stmt = $pdo->prepare($query);
$tickets_stmt->execute($params);
$tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get specific ticket for viewing
$view_ticket = null;
$ticket_comments = [];
if ($view_ticket_id) {
    $view_stmt = $pdo->prepare("
        SELECT t.*, ic.name as category_name, 
               u.full_name as assigned_to_name, t.status as ticket_status,
               CURRENT_DATE - t.created_at::date as days_old
        FROM tickets t
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.id = ? AND t.reg_number = ?
    ");
    $view_stmt->execute([$view_ticket_id, $reg_number]);
    $view_ticket = $view_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get comments for this ticket
    if ($view_ticket) {
        $comments_stmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.role,
                   CASE WHEN tc.user_id = ? THEN 'me' ELSE 'them' END as comment_type
            FROM ticket_comments tc
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ");
        $comments_stmt->execute([$student_id, $view_ticket_id]);
        $ticket_comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get ticket statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
    FROM tickets 
    WHERE reg_number = ?
");
$stats_stmt->execute([$reg_number]);
$ticket_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get categories for filter and form
$categories_stmt = $pdo->prepare("SELECT * FROM issue_categories ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tickets - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --booking-blue: #003b95;
            --booking-blue-light: #006ce4;
            --booking-green: #00a699;
            --booking-orange: #ff5a5f;
            --booking-yellow: #ffb400;
            --booking-gray-50: #f7f7f7;
            --booking-gray-100: #ebebeb;
            --booking-gray-200: #d8d8d8;
            --booking-gray-300: #b0b0b0;
            --booking-gray-400: #717171;
            --booking-gray-500: #2d2d2d;
            --booking-white: #ffffff;
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.16);
            --transition: all 0.2s ease;
        }

        [data-theme="dark"] {
            --booking-gray-50: #1a1a1a;
            --booking-gray-100: #2d2d2d;
            --booking-gray-200: #404040;
            --booking-gray-300: #666666;
            --booking-gray-400: #999999;
            --booking-gray-500: #ffffff;
            --booking-white: #2d2d2d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
            padding: 0 2rem;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

/* Logo Styles */
.logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
}

.logo-image {
    height: 36px; /* Adjust based on your logo's aspect ratio */
    width: auto;
    object-fit: contain;
}

.logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-blue);
    letter-spacing: -0.5px;
}

/* Optional: Different logo for dark theme */
[data-theme="dark"] .logo-text {
    color: white; /* Or keep it blue for consistency */
}

[data-theme="dark"] .logo-image {
    filter: brightness(1.1); /* Slightly brighten logo for dark theme */
}

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--booking-gray-50);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .theme-toggle-btn {
            background: none;
            border: 1px solid var(--booking-gray-200);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .theme-toggle-btn:hover {
            border-color: var(--booking-blue);
            color: var(--booking-blue);
        }

        /* Navigation */
        .nav-container {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .main-nav {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .nav-links {
            display: flex;
            gap: 0;
            list-style: none;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--booking-gray-500);
            font-weight: 500;
            font-size: 0.9rem;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .nav-link:hover {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue-light);
        }

        .nav-link.active {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue);
            font-weight: 600;
        }

        .nav-link i {
            font-size: 0.85rem;
            width: 18px;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-500);
        }

        .page-subtitle {
            color: var(--booking-gray-400);
            font-size: 0.95rem;
        }

        .header-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
        }

        /* Stats Cards */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card-mini {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
            cursor: pointer;
        }

        .stat-card-mini:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--booking-gray-200);
        }

        .stat-card-mini.active {
            border-color: var(--booking-blue);
            background: #e6f2ff;
        }

        .stat-icon-mini {
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-icon-mini.total { background: #e6f2ff; color: var(--booking-blue); }
        .stat-icon-mini.open { background: #e6ffe6; color: var(--booking-green); }
        .stat-icon-mini.progress { background: #fff8e6; color: var(--booking-orange); }
        .stat-icon-mini.resolved { background: #f0f0f0; color: var(--booking-gray-400); }

        .stat-content-mini h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1;
        }

        .stat-content-mini p {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
        }

        /* Filters Card */
        .filters-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--booking-gray-500);
        }

        .filter-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        .search-box {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--booking-gray-400);
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        /* Tickets Table Card */
        .tickets-table-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-title i {
            color: var(--booking-blue);
        }

        .table-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .table-count {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--booking-blue);
            color: white;
            border: 1px solid var(--booking-blue);
        }

        .btn-primary:hover {
            background: var(--booking-blue-light);
            border-color: var(--booking-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 107, 228, 0.2);
        }

        .btn-outline {
            background: var(--booking-white);
            color: var(--booking-gray-500);
            border: 1px solid var(--booking-gray-200);
        }

        .btn-outline:hover {
            background: var(--booking-gray-50);
            border-color: var(--booking-gray-300);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Table Styles */
        .tickets-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tickets-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--booking-gray-400);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--booking-gray-100);
            background: var(--booking-gray-50);
        }

        .tickets-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--booking-gray-100);
            vertical-align: middle;
        }

        .tickets-table tr {
            transition: var(--transition);
        }

        .tickets-table tr:hover {
            background: var(--booking-gray-50);
        }

        .tickets-table tr:last-child td {
            border-bottom: none;
        }

        /* Ticket Status */
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-open { background: #e6ffe6; color: var(--booking-green); }
        .status-in_progress { background: #fff8e6; color: var(--booking-orange); }
        .status-resolved { background: #f0f0f0; color: var(--booking-gray-400); }
        .status-closed { background: #e6f2ff; color: var(--booking-blue); }

        /* Priority Badge */
        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-low { background: #e6ffe6; color: var(--booking-green); }
        .priority-medium { background: #fff8e6; color: var(--booking-orange); }
        .priority-high { background: #ffe6e6; color: var(--booking-orange); }

        /* Ticket Actions */
        .ticket-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--booking-gray-50);
            color: var(--booking-gray-400);
            border: 1px solid var(--booking-gray-200);
            cursor: pointer;
            transition: var(--transition);
        }

        .action-btn:hover {
            background: var(--booking-blue);
            color: white;
            border-color: var(--booking-blue);
        }

        /* Empty State */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }

        .empty-icon {
            font-size: 3rem;
            color: var(--booking-gray-300);
            margin-bottom: 1rem;
        }

        .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-500);
        }

        .empty-description {
            color: var(--booking-gray-400);
            margin-bottom: 1.5rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--booking-gray-500);
        }

        .modal-close {
            background: none;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            max-height: calc(90vh - 120px);
        }

        /* Ticket Details */
        .ticket-details-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
        }

        .ticket-meta {
            flex: 1;
        }

        .ticket-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-500);
        }

        .ticket-id {
            color: var(--booking-gray-400);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .ticket-status-large {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .ticket-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-card {
            background: var(--booking-gray-50);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius);
            padding: 1.25rem;
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--booking-gray-500);
            font-weight: 500;
        }

        .detail-value a {
            color: var(--booking-blue);
            text-decoration: none;
        }

        .detail-value a:hover {
            text-decoration: underline;
        }

        .ticket-description {
            background: var(--booking-gray-50);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .description-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--booking-gray-500);
        }

        .description-content {
            line-height: 1.6;
            color: var(--booking-gray-500);
        }

        /* Comments Section */
        .comments-section {
            margin-top: 2rem;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--booking-gray-500);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .comment {
            display: flex;
            gap: 1rem;
        }

        .comment-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--booking-gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--booking-gray-500);
            flex-shrink: 0;
            font-size: 0.9rem;
        }

        .comment.me .comment-avatar {
            background: var(--booking-blue);
            color: white;
        }

        .comment-content {
            flex: 1;
            background: var(--booking-gray-50);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius);
            padding: 1rem;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .comment-date {
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        .comment-text {
            color: var(--booking-gray-500);
            line-height: 1.5;
        }

        .comment-form {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--booking-gray-100);
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--booking-gray-500);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn-secondary {
            background: var(--booking-gray-100);
            color: var(--booking-gray-500);
            border: 1px solid var(--booking-gray-200);
        }

        .btn-secondary:hover {
            background: var(--booking-gray-200);
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid;
        }

        .alert-success {
            border-color: var(--booking-green);
            background: #f0fffc;
            color: var(--booking-green);
        }

        .alert-error {
            border-color: var(--booking-orange);
            background: #fff5f5;
            color: var(--booking-orange);
        }

        .alert i {
            font-size: 1rem;
            margin-top: 0.125rem;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0 1rem;
            }
            
            .main-nav {
                padding: 0 1rem;
            }
            
            .nav-links {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 0.5rem;
            }
            
            .nav-link {
                padding: 1rem;
                font-size: 0.85rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .table-controls {
                width: 100%;
                justify-content: space-between;
            }
            
            .tickets-table {
                display: block;
                overflow-x: auto;
            }
            
            .ticket-details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-overview {
                grid-template-columns: 1fr;
            }
            
            .user-name, .user-role {
                display: none;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
        <!-- Header -->
    <header class="header">
<a href="dashboard.php" class="logo">
    <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo-image">
    <div class="logo-text">Isonga</div>
</a>
        
<!-- Add this to the header-actions div in dashboard.php -->
<div class="header-actions">
    <form method="POST" style="margin: 0;">
        <button type="submit" name="toggle_theme" class="theme-toggle-btn" title="Toggle Theme">
            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
        </button>
    </form>
    
    <!-- Logout Button - Add this -->
    <a href="../auth/logout.php" class="logout-btn" title="Logout">
        <i class="fas fa-sign-out-alt"></i>
    </a>
    
    <div class="user-menu">
        <div class="user-avatar">
            <?php echo strtoupper(substr($student_name, 0, 1)); ?>
        </div>
        <div class="user-info">
            <span class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></span>
            <span class="user-role">Student</span>
        </div>
    </div>
</div>
    </header>

    <!-- Navigation -->
    <nav class="nav-container">
        <div class="main-nav">
            <ul class="nav-links">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link active">
                        <i class="fas fa-ticket-alt"></i>
                        My Tickets
                    </a>
                </li>
                <li class="nav-item">
                    <a href="financial_aid.php" class="nav-link">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">My Support Tickets</h1>
                <p class="page-subtitle">Track and manage your support requests</p>
            </div>
            <div class="header-actions-row">
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
                <button class="btn btn-primary" onclick="openNewTicketModal()">
                    <i class="fas fa-plus"></i>
                    New Ticket
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <div class="stats-overview">
            <div class="stat-card-mini <?php echo $status_filter === 'all' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=all&category=<?php echo $category_filter; ?>'">
                <div class="stat-icon-mini total">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="stat-content-mini">
                    <h3><?php echo $ticket_stats['total']; ?></h3>
                    <p>Total Tickets</p>
                </div>
            </div>
            
            <div class="stat-card-mini <?php echo $status_filter === 'open' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=open&category=<?php echo $category_filter; ?>'">
                <div class="stat-icon-mini open">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content-mini">
                    <h3><?php echo $ticket_stats['open']; ?></h3>
                    <p>Open</p>
                </div>
            </div>
            
            <div class="stat-card-mini <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=in_progress&category=<?php echo $category_filter; ?>'">
                <div class="stat-icon-mini progress">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="stat-content-mini">
                    <h3><?php echo $ticket_stats['in_progress']; ?></h3>
                    <p>In Progress</p>
                </div>
            </div>
            
            <div class="stat-card-mini <?php echo $status_filter === 'resolved' || $status_filter === 'closed' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=resolved&category=<?php echo $category_filter; ?>'">
                <div class="stat-icon-mini resolved">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content-mini">
                    <h3><?php echo $ticket_stats['resolved'] + $ticket_stats['closed']; ?></h3>
                    <p>Resolved</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Category</label>
                        <select name="category" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_display($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group search-box">
                        <label class="filter-label">Search</label>
                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" class="search-input" placeholder="Search tickets..." value="<?php echo safe_display($search_query); ?>" onkeypress="if(event.key === 'Enter') document.getElementById('filterForm').submit()">
                        </div>
                    </div>
                    
                    <div class="filter-group filter-actions">
                        <label class="filter-label" style="visibility: hidden;">Actions</label>
                        <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem;">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                        <a href="tickets.php" class="btn btn-outline" style="padding: 0.75rem 1.5rem;">
                            <i class="fas fa-redo"></i>
                            Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="tickets-table-card">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-list"></i>
                    All Tickets
                </h3>
                <div class="table-controls">
                    <span class="table-count"><?php echo count($tickets); ?> tickets found</span>
                </div>
            </div>

            <?php if (empty($tickets)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3 class="empty-title">No tickets found</h3>
                    <p class="empty-description">
                        <?php if ($status_filter !== 'all' || $category_filter !== 'all' || !empty($search_query)): ?>
                            Try adjusting your filters or search terms.
                        <?php else: ?>
                            You haven't submitted any support tickets yet.
                        <?php endif; ?>
                    </p>
                    <button class="btn btn-primary" onclick="openNewTicketModal()">
                        <i class="fas fa-plus"></i>
                        Submit Your First Ticket
                    </button>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo $ticket['id']; ?></strong>
                                        <?php if ($ticket['days_old'] <= 1): ?>
                                            <span style="display: block; font-size: 0.7rem; color: var(--booking-green); margin-top: 0.25rem;">NEW</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; margin-bottom: 0.25rem;"><?php echo safe_display($ticket['subject']); ?></div>
                                        <div style="font-size: 0.8rem; color: var(--booking-gray-400);">
                                            <?php echo date('M j, Y', strtotime($ticket['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td><?php echo safe_display($ticket['category_name']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo str_replace('_', '-', $ticket['status']); ?>">
                                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                            <?php echo ucfirst($ticket['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($ticket['assigned_to_name']): ?>
                                            <?php echo safe_display($ticket['assigned_to_name']); ?>
                                        <?php else: ?>
                                            <span style="color: var(--booking-gray-400); font-size: 0.85rem;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.85rem;"><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--booking-gray-400);"><?php echo date('g:i A', strtotime($ticket['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="ticket-actions">
                                            <button class="action-btn" title="View Ticket" onclick="viewTicket(<?php echo $ticket['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($ticket['status'] !== 'closed' && $ticket['status'] !== 'resolved'): ?>
                                                <button class="action-btn" title="Add Comment" onclick="viewTicket(<?php echo $ticket['id']; ?>)">
                                                    <i class="fas fa-comment"></i>
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
    </main>

    <!-- New Ticket Modal -->
    <div id="newTicketModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Submit New Ticket</h3>
                <button class="modal-close" onclick="closeNewTicketModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" action="tickets.php" id="newTicketForm">
                    <div class="form-group">
                        <label class="form-label">Issue Category</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo safe_display($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Brief description of your issue" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" placeholder="Please provide detailed information about your issue..." rows="5" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Preferred Contact Method</label>
                        <select name="preferred_contact" class="form-control" required>
                            <option value="email" selected>Email</option>
                            <option value="sms">SMS</option>
                            <option value="phone">Phone Call</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeNewTicketModal()">Cancel</button>
                        <button type="submit" name="submit_ticket" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                            Submit Ticket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Ticket Modal -->
    <div id="viewTicketModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Ticket Details</h3>
                <button class="modal-close" onclick="closeViewTicketModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <?php if ($view_ticket): ?>
                    <!-- Ticket Header -->
                    <div class="ticket-details-header">
                        <div class="ticket-meta">
                            <h2 class="ticket-title"><?php echo safe_display($view_ticket['subject']); ?></h2>
                            <div class="ticket-id">Ticket #<?php echo $view_ticket['id']; ?></div>
                            <span class="ticket-status-large status-<?php echo str_replace('_', '-', $view_ticket['status']); ?>">
                                <i class="fas fa-circle" style="font-size: 0.6rem;"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $view_ticket['status'])); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Ticket Details Grid -->
                    <div class="ticket-details-grid">
                        <div class="detail-card">
                            <div class="detail-label">Category</div>
                            <div class="detail-value"><?php echo safe_display($view_ticket['category_name']); ?></div>
                        </div>
                        
                        <div class="detail-card">
                            <div class="detail-label">Priority</div>
                            <div class="detail-value">
                                <span class="priority-badge priority-<?php echo $view_ticket['priority']; ?>">
                                    <?php echo ucfirst($view_ticket['priority']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <div class="detail-label">Assigned To</div>
                            <div class="detail-value">
                                <?php if ($view_ticket['assigned_to_name']): ?>
                                    <?php echo safe_display($view_ticket['assigned_to_name']); ?>
                                <?php else: ?>
                                    <span style="color: var(--booking-gray-400);">Pending assignment</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <div class="detail-label">Created</div>
                            <div class="detail-value"><?php echo date('F j, Y', strtotime($view_ticket['created_at'])); ?></div>
                        </div>
                    </div>

                    <!-- Ticket Description -->
                    <div class="ticket-description">
                        <h3 class="description-title">Description</h3>
                        <div class="description-content">
                            <?php echo nl2br(safe_display($view_ticket['description'])); ?>
                        </div>
                    </div>

                    <!-- Comments Section -->
                    <div class="comments-section">
                        <h3 class="section-title">
                            <i class="fas fa-comments"></i>
                            Comments
                        </h3>
                        
                        <?php if (empty($ticket_comments)): ?>
                            <div class="empty-state" style="padding: 2rem; background: var(--booking-gray-50); border-radius: var(--border-radius);">
                                <i class="fas fa-comment" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                <p style="color: var(--booking-gray-400);">No comments yet. Be the first to comment!</p>
                            </div>
                        <?php else: ?>
                            <div class="comments-list">
                                <?php foreach ($ticket_comments as $comment): ?>
                                    <div class="comment <?php echo $comment['comment_type']; ?>">
                                        <div class="comment-avatar">
                                            <?php echo strtoupper(substr($comment['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="comment-content">
                                            <div class="comment-header">
                                                <div class="comment-author"><?php echo safe_display($comment['full_name']); ?></div>
                                                <div class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></div>
                                            </div>
                                            <div class="comment-text"><?php echo nl2br(safe_display($comment['comment'])); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Comment Form -->
                        <?php if ($view_ticket['status'] !== 'closed' && $view_ticket['status'] !== 'resolved'): ?>
                            <div class="comment-form">
                                <form method="POST" action="tickets.php">
                                    <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                    <div class="form-group">
                                        <label class="form-label">Add a Comment</label>
                                        <textarea name="comment" class="form-control" placeholder="Type your comment here..." rows="3" required></textarea>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" name="add_comment" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i>
                                            Post Comment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 1.5rem; background: var(--booking-gray-50); border-radius: var(--border-radius);">
                                <i class="fas fa-info-circle" style="color: var(--booking-gray-400); margin-right: 0.5rem;"></i>
                                <span style="color: var(--booking-gray-400);">This ticket is closed. No further comments can be added.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem;"></i>
                        <h3 class="empty-title">Ticket Not Found</h3>
                        <p class="empty-description">The requested ticket could not be found or you don't have permission to view it.</p>
                        <button class="btn btn-primary" onclick="closeViewTicketModal()">
                            <i class="fas fa-arrow-left"></i>
                            Back to Tickets
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openNewTicketModal() {
            document.getElementById('newTicketModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeNewTicketModal() {
            document.getElementById('newTicketModal').style.display = 'none';
            document.getElementById('newTicketForm').reset();
            document.body.style.overflow = 'auto';
        }

        function viewTicket(ticketId) {
            window.location.href = 'tickets.php?view=' + ticketId;
        }

        function closeViewTicketModal() {
            window.location.href = 'tickets.php';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['newTicketModal', 'viewTicketModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'viewTicketModal') {
                        closeViewTicketModal();
                    } else {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                }
            });
        }

        // Close modal on ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = ['newTicketModal', 'viewTicketModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal.style.display === 'flex') {
                        if (modalId === 'viewTicketModal') {
                            closeViewTicketModal();
                        } else {
                            closeNewTicketModal();
                        }
                    }
                });
            }
        });

        // Auto-open view modal if viewing a ticket
        <?php if ($view_ticket_id): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('viewTicketModal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            });
        <?php endif; ?>

        // Auto-open new ticket modal if there's an error
        <?php if (isset($error_message) && isset($_POST['submit_ticket'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(() => {
                    openNewTicketModal();
                }, 300);
            });
        <?php endif; ?>

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add animation to stat cards
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe stat cards
        document.querySelectorAll('.stat-card-mini').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>