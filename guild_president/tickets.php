<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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
}

// Get dashboard statistics for sidebar
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'];
    } catch (PDOException $e) {
        $pending_reports = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];
    } catch (PDOException $e) {
        $pending_docs = 0;
    }
    
    // Unread messages - FIXED: Using conversation_messages table
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    } catch (PDOException $e) {
        $unread_messages = 0;
    }
    
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $pending_reports = $pending_docs = $unread_messages = 0;
}


// Handle filters and search
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// DEBUG: Log what we're receiving
error_log("Filters - Status: $status_filter, Priority: $priority_filter, Category: $category_filter, Search: $search_query");

// Build the base query - SIMPLIFIED VERSION
$query = "
    SELECT t.*, 
           ic.name as category_name, 
           u.full_name as assigned_name,
           d.name as department_name,
           p.name as program_name
    FROM tickets t 
    LEFT JOIN issue_categories ic ON t.category_id = ic.id 
    LEFT JOIN users u ON t.assigned_to = u.id 
    LEFT JOIN departments d ON t.department_id = d.id
    LEFT JOIN programs p ON t.program_id = p.id
    WHERE 1=1
";

$params = [];
$types = ''; // For binding types

// Apply filters - SIMPLIFIED
if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($priority_filter !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority_filter;
    $types .= 's';
}

if ($category_filter !== 'all' && is_numeric($category_filter)) {
    $query .= " AND t.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

if (!empty($search_query)) {
    $query .= " AND (t.subject LIKE ? OR t.description LIKE ? OR t.name LIKE ? OR t.reg_number LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

// Add ordering
$query .= " ORDER BY 
    CASE 
        WHEN t.priority = 'high' THEN 1
        WHEN t.priority = 'medium' THEN 2
        WHEN t.priority = 'low' THEN 3
        ELSE 4
    END,
    t.created_at DESC";

// DEBUG: Log the query and parameters
error_log("Main Query: " . $query);
error_log("Parameters: " . implode(', ', $params));

// Get total count for pagination - SIMPLIFIED
try {
    $count_query = "SELECT COUNT(*) as total FROM tickets t WHERE 1=1";
    $count_params = [];
    
    if ($status_filter !== 'all') {
        $count_query .= " AND t.status = ?";
        $count_params[] = $status_filter;
    }
    
    if ($priority_filter !== 'all') {
        $count_query .= " AND t.priority = ?";
        $count_params[] = $priority_filter;
    }
    
    if ($category_filter !== 'all' && is_numeric($category_filter)) {
        $count_query .= " AND t.category_id = ?";
        $count_params[] = $category_filter;
    }
    
    if (!empty($search_query)) {
        $count_query .= " AND (t.subject LIKE ? OR t.description LIKE ? OR t.name LIKE ? OR t.reg_number LIKE ?)";
        $search_param = "%$search_query%";
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
    }
    
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($count_params);
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    error_log("Total tickets found: " . $total_tickets);
    
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_tickets = 0;
}

// Pagination
$per_page = 15;
$total_pages = ceil($total_tickets / $per_page);
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Add pagination to main query
$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Fetch tickets - WITH PROPER PARAMETER BINDING
try {
    $stmt = $pdo->prepare($query);
    
    // Bind parameters manually to avoid LIMIT/OFFSET issues
    foreach ($params as $key => $value) {
        $param_type = PDO::PARAM_STR;
        
        // Determine parameter type
        if (is_int($value)) {
            $param_type = PDO::PARAM_INT;
        } elseif ($key === count($params) - 2 || $key === count($params) - 1) {
            // Last two parameters are LIMIT and OFFSET (integers)
            $param_type = PDO::PARAM_INT;
        }
        
        $stmt->bindValue($key + 1, $value, $param_type);
    }
    
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Tickets fetched: " . count($tickets));
    
    // DEBUG: Log first ticket if available
    if (!empty($tickets)) {
        error_log("First ticket: " . json_encode($tickets[0]));
    }
    
} catch (PDOException $e) {
    error_log("Tickets query error: " . $e->getMessage());
    error_log("Full error: " . $e->getMessage());
    $tickets = [];
}

// Get categories for filter dropdown
try {
    $stmt = $pdo->query("SELECT * FROM issue_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Categories query error: " . $e->getMessage());
    $categories = [];
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

// Handle ticket actions (reassign, escalate, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reassign_ticket'])) {
        $ticket_id = $_POST['ticket_id'];
        $new_assignee = $_POST['new_assignee'];
        
        try {
            // Update ticket assignment
            $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
            $stmt->execute([$new_assignee, $ticket_id]);
            
            // Log the reassignment
            $stmt = $pdo->prepare("
                INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason) 
                VALUES (?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$ticket_id, $new_assignee, $user_id, "Manually reassigned by Guild President"]);
            
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
            $stmt->execute([$ticket_id, $user_id, "Ticket reassigned to $assignee_name by Guild President"]);
            
            $_SESSION['success_message'] = "Ticket successfully reassigned";
            header("Location: tickets.php");
            exit();
            
        } catch (PDOException $e) {
            error_log("Reassignment error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to reassign ticket";
        }
    }
    
    if (isset($_POST['update_status'])) {
        $ticket_id = $_POST['ticket_id'];
        $new_status = $_POST['new_status'];
        
        try {
            $update_data = [$new_status, $ticket_id];
            $query = "UPDATE tickets SET status = ?";
            
            if ($new_status === 'resolved') {
                $query .= ", resolved_at = NOW()";
            }
            
            $query .= " WHERE id = ?";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($update_data);
            
            // Add comment
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) 
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, "Status changed to " . ucfirst($new_status) . " by Guild President"]);
            
            $_SESSION['success_message'] = "Ticket status updated successfully";
            header("Location: tickets.php");
            exit();
            
        } catch (PDOException $e) {
            error_log("Status update error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to update ticket status";
        }
    }
}

// Get statistics for dashboard cards - SIMPLIFIED
try {
    // Total tickets with current filters
    $stats_query = "SELECT COUNT(*) as total FROM tickets t WHERE 1=1";
    $stats_params = [];
    
    if ($status_filter !== 'all') {
        $stats_query .= " AND t.status = ?";
        $stats_params[] = $status_filter;
    }
    
    if ($priority_filter !== 'all') {
        $stats_query .= " AND t.priority = ?";
        $stats_params[] = $priority_filter;
    }
    
    if ($category_filter !== 'all' && is_numeric($category_filter)) {
        $stats_query .= " AND t.category_id = ?";
        $stats_params[] = $category_filter;
    }
    
    if (!empty($search_query)) {
        $stats_query .= " AND (t.subject LIKE ? OR t.description LIKE ? OR t.name LIKE ? OR t.reg_number LIKE ?)";
        $search_param = "%$search_query%";
        $stats_params[] = $search_param;
        $stats_params[] = $search_param;
        $stats_params[] = $search_param;
        $stats_params[] = $search_param;
    }
    
    // Total tickets
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute($stats_params);
    $total_tickets_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Open tickets
    $open_query = $stats_query . " AND t.status = 'open'";
    $stmt = $pdo->prepare($open_query);
    $stmt->execute($stats_params);
    $open_tickets_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // In progress tickets
    $in_progress_query = $stats_query . " AND t.status = 'in_progress'";
    $stmt = $pdo->prepare($in_progress_query);
    $stmt->execute($stats_params);
    $in_progress_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Resolved tickets
    $resolved_query = $stats_query . " AND t.status = 'resolved'";
    $stmt = $pdo->prepare($resolved_query);
    $stmt->execute($stats_params);
    $resolved_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // High priority tickets
    $high_priority_query = $stats_query . " AND t.priority = 'high' AND t.status NOT IN ('resolved', 'closed')";
    $stmt = $pdo->prepare($high_priority_query);
    $stmt->execute($stats_params);
    $high_priority_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Overdue tickets
    $overdue_query = $stats_query . " AND t.due_date < CURDATE() AND t.status NOT IN ('resolved', 'closed')";
    $stmt = $pdo->prepare($overdue_query);
    $stmt->execute($stats_params);
    $overdue_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_tickets_count = $open_tickets_count = $in_progress_count = $resolved_count = $high_priority_count = $overdue_count = 0;
}

// DEBUG: Check if we have any tickets at all in the database
try {
    $debug_stmt = $pdo->query("SELECT COUNT(*) as debug_total FROM tickets");
    $debug_total = $debug_stmt->fetch(PDO::FETCH_ASSOC)['debug_total'];
    error_log("DEBUG: Total tickets in database: " . $debug_total);
    
    // Check if we can fetch some sample tickets
    $sample_stmt = $pdo->query("SELECT id, subject, status FROM tickets LIMIT 5");
    $sample_tickets = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("DEBUG: Sample tickets: " . json_encode($sample_tickets));
    
} catch (PDOException $e) {
    error_log("DEBUG query failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tickets Management - Isonga RPSU</title>
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
            color: var(--warning);
        }

        .status-in_progress {
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
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .filters-form {
                grid-template-columns: 1fr 1fr;
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
            
            .table {
                display: block;
                overflow-x: auto;
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
                    <h1>Isonga - President</h1>
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
                        <div class="user-role">Guild President</div>
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
        <!-- Sidebar - MATCHING DASHBOARD.PHP -->
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
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
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
                <?php 
                // Get new student registrations count (last 7 days)
                try {
                    $new_students_stmt = $pdo->prepare("
                        SELECT COUNT(*) as new_students 
                        FROM users 
                        WHERE role = 'student' 
                        AND status = 'active' 
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $new_students_stmt->execute();
                    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'];
                } catch (PDOException $e) {
                    $new_students = 0;
                }
                ?>
                <?php if ($new_students > 0): ?>
                    <span class="menu-badge"><?php echo $new_students; ?> new</span>
                <?php endif; ?>
            </a>
        </li>
                <li class="menu-item">
                    <a href="messages.php" >
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
                    <h1>All Student Tickets</h1>
                    <p>Manage and monitor all student support tickets across the system</p>
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

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_tickets_count; ?></div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_tickets_count; ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $in_progress_count; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolved_count; ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $high_priority_count; ?></div>
                        <div class="stat-label">High Priority</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $overdue_count; ?></div>
                        <div class="stat-label">Overdue</div>
                    </div>
                </div>
            </div>

            <!-- Quick Status Summary -->
            <div style="display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap;">
                <a href="?status=open<?php echo $priority_filter !== 'all' ? '&priority=' . $priority_filter : ''; echo $category_filter !== 'all' ? '&category=' . $category_filter : ''; echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                   class="btn btn-outline btn-sm <?php echo $status_filter === 'open' ? 'active' : ''; ?>" 
                   style="text-decoration: none;">
                    <i class="fas fa-clock"></i> Open (<?php echo $open_tickets_count; ?>)
                </a>
                <a href="?status=in_progress<?php echo $priority_filter !== 'all' ? '&priority=' . $priority_filter : ''; echo $category_filter !== 'all' ? '&category=' . $category_filter : ''; echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                   class="btn btn-outline btn-sm <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" 
                   style="text-decoration: none;">
                    <i class="fas fa-spinner"></i> In Progress (<?php echo $in_progress_count; ?>)
                </a>
                <a href="?status=resolved<?php echo $priority_filter !== 'all' ? '&priority=' . $priority_filter : ''; echo $category_filter !== 'all' ? '&category=' . $category_filter : ''; echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                   class="btn btn-outline btn-sm <?php echo $status_filter === 'resolved' ? 'active' : ''; ?>" 
                   style="text-decoration: none;">
                    <i class="fas fa-check-circle"></i> Resolved (<?php echo $resolved_count; ?>)
                </a>
                <a href="?priority=high<?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; echo $category_filter !== 'all' ? '&category=' . $category_filter : ''; echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                   class="btn btn-outline btn-sm <?php echo $priority_filter === 'high' ? 'active' : ''; ?>" 
                   style="text-decoration: none; border-color: var(--danger); color: var(--danger);">
                    <i class="fas fa-exclamation-triangle"></i> High Priority (<?php echo $high_priority_count; ?>)
                </a>
            </div>

            <!-- Filters -->
            <div class="filters-container">
                <form method="GET" class="filters-form">
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
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
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

            <!-- Tickets Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>
                        Student Tickets 
                        <?php if ($status_filter !== 'all' || $priority_filter !== 'all' || $category_filter !== 'all' || !empty($search_query)): ?>
                            <small style="font-size: 0.8rem; color: var(--dark-gray); font-weight: normal;">
                                (Filtered: <?php echo $total_tickets; ?> found)
                                <?php if ($status_filter !== 'all'): ?> • Status: <?php echo ucfirst($status_filter); ?><?php endif; ?>
                                <?php if ($priority_filter !== 'all'): ?> • Priority: <?php echo ucfirst($priority_filter); ?><?php endif; ?>
                                <?php if ($category_filter !== 'all'): ?> • Category: <?php 
                                    $category_name = 'Unknown';
                                    foreach ($categories as $cat) {
                                        if ($cat['id'] == $category_filter) {
                                            $category_name = $cat['name'];
                                            break;
                                        }
                                    }
                                    echo $category_name;
                                ?><?php endif; ?>
                                <?php if (!empty($search_query)): ?> • Search: "<?php echo htmlspecialchars($search_query); ?>"<?php endif; ?>
                            </small>
                        <?php else: ?>
                            <small style="font-size: 0.8rem; color: var(--dark-gray); font-weight: normal;">
                                (<?php echo $total_tickets; ?> total)
                            </small>
                        <?php endif; ?>
                    </h3>
                    <div>
                        <a href="tickets.php" class="btn btn-outline btn-sm">
                            <i class="fas fa-sync-alt"></i> Reset Filters
                        </a>
                    </div>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Due Date</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No tickets found matching your criteria</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>#<?php echo $ticket['id']; ?></td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($ticket['name']); ?></strong></div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($ticket['reg_number']); ?></div>
                                        <?php if ($ticket['department_name']): ?>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($ticket['department_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 200px;">
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                            <?php echo strlen($ticket['description']) > 100 ? 
                                                htmlspecialchars(substr($ticket['description'], 0, 100)) . '...' : 
                                                htmlspecialchars($ticket['description']); ?>
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
                                        <?php if ($ticket['assigned_name']): ?>
                                            <?php echo htmlspecialchars($ticket['assigned_name']); ?>
                                        <?php else: ?>
                                            <span style="color: var(--dark-gray); font-style: italic;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ticket['due_date']): ?>
                                            <?php 
                                            $due_date = new DateTime($ticket['due_date']);
                                            $today = new DateTime();
                                            $is_overdue = $due_date < $today && !in_array($ticket['status'], ['resolved', 'closed']);
                                            ?>
                                            <span style="color: <?php echo $is_overdue ? 'var(--danger)' : 'var(--text-dark)'; ?>;">
                                                <?php echo $due_date->format('M j, Y'); ?>
                                                <?php if ($is_overdue): ?>
                                                    <br><small style="color: var(--danger);">Overdue</small>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--dark-gray);">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-outline btn-sm" onclick="viewTicket(<?php echo $ticket['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline btn-sm" onclick="reassignTicket(<?php echo $ticket['id']; ?>, '<?php echo $ticket['assigned_name'] ?? 'Unassigned'; ?>')">
                                                <i class="fas fa-user-edit"></i>
                                            </button>
                                            <button class="btn btn-outline btn-sm" onclick="updateStatus(<?php echo $ticket['id']; ?>, '<?php echo $ticket['status']; ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" class="pagination-btn">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="pagination-btn active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" class="pagination-btn">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Reassign Ticket Modal -->
    <div id="reassignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reassign Ticket</h3>
                <button class="modal-close" onclick="closeModal('reassignModal')">&times;</button>
            </div>
            <form method="POST" id="reassignForm">
                <div class="modal-body">
                    <input type="hidden" name="ticket_id" id="reassignTicketId">
                    <div class="form-group">
                        <label class="form-label">Current Assignee</label>
                        <input type="text" id="currentAssignee" class="form-input" readonly>
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
            <form method="POST" id="statusForm">
                <div class="modal-body">
                    <input type="hidden" name="ticket_id" id="statusTicketId">
                    <div class="form-group">
                        <label class="form-label">Current Status</label>
                        <input type="text" id="currentStatus" class="form-input" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Status</label>
                        <select name="new_status" class="form-select" required>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
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
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function reassignTicket(ticketId, currentAssignee) {
            document.getElementById('reassignTicketId').value = ticketId;
            document.getElementById('currentAssignee').value = currentAssignee;
            openModal('reassignModal');
        }

        function updateStatus(ticketId, currentStatus) {
            document.getElementById('statusTicketId').value = ticketId;
            document.getElementById('currentStatus').value = currentStatus.replace('_', ' ');
            document.querySelector('select[name="new_status"]').value = currentStatus;
            openModal('statusModal');
        }

        function viewTicket(ticketId) {
            window.location.href = 'ticket_details.php?id=' + ticketId;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>