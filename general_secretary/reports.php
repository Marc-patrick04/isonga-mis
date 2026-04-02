<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data for sidebar
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User query error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (Exception $e) {
        error_log("Reports table query error: " . $e->getMessage());
        $pending_reports = 0;
    }
    
    // Unread messages
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    } catch (Exception $e) {
        error_log("Messages query error: " . $e->getMessage());
        $unread_messages = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (Exception $e) {
        error_log("Documents table error: " . $e->getMessage());
        $pending_docs = 0;
    }
    
    // New students count
    $new_students = 0;
    try {
        $new_students_stmt = $pdo->prepare("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
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
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_tickets = $open_tickets = $pending_reports = $unread_messages = $pending_docs = $new_students = $upcoming_meetings = $pending_minutes = 0;
}

// Handle report export
if (isset($_GET['export']) && isset($_GET['id'])) {
    $report_id = intval($_GET['id']);
    $export_type = $_GET['export'];
    
    try {
        // Get report data
        $stmt = $pdo->prepare("
            SELECT r.*, u.full_name, u.role as user_role, rt.name as template_name
            FROM reports r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN report_templates rt ON r.template_id = rt.id 
            WHERE r.id = ?
        ");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            if ($export_type === 'pdf') {
                exportReportToPDF($report);
            } elseif ($export_type === 'word') {
                exportReportToWord($report);
            }
        }
    } catch (PDOException $e) {
        error_log("Export error: " . $e->getMessage());
    }
    exit;
}

// Get filter parameters
$report_type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query with filters
$whereConditions = ["r.status != 'draft'"];
$params = [];

if ($report_type !== 'all') {
    $whereConditions[] = "r.report_type = ?";
    $params[] = $report_type;
}

if ($status !== 'all') {
    $whereConditions[] = "r.status = ?";
    $params[] = $status;
}

if ($date_from) {
    $whereConditions[] = "DATE(r.submitted_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $whereConditions[] = "DATE(r.submitted_at) <= ?";
    $params[] = $date_to;
}

$whereClause = $whereConditions ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get reports
try {
    // Total count
    $countQuery = "SELECT COUNT(*) FROM reports r $whereClause";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalReports = $countStmt->fetchColumn();
    $totalPages = ceil($totalReports / $limit);

    // Reports data
    $reportsQuery = "
        SELECT 
            r.*,
            u.full_name,
            u.role as user_role,
            rt.name as template_name,
            ru.full_name as reviewer_name
        FROM reports r 
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN report_templates rt ON r.template_id = rt.id 
        LEFT JOIN users ru ON r.reviewed_by = ru.id 
        $whereClause
        ORDER BY 
            CASE 
                WHEN r.status = 'submitted' THEN 1
                WHEN r.status = 'reviewed' THEN 2
                ELSE 3
            END,
            r.submitted_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $reportsStmt = $pdo->prepare($reportsQuery);
    $paramIndex = 1;
    foreach ($params as $param) {
        $reportsStmt->bindValue($paramIndex, $param);
        $paramIndex++;
    }
    $reportsStmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $reportsStmt->bindValue($paramIndex, $offset, PDO::PARAM_INT);
    
    $reportsStmt->execute();
    $reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get team reports
    $teamReports = [];
    try {
        $teamReportsStmt = $pdo->query("
            SELECT 
                tr.*,
                u.full_name as team_leader_name,
                u.role as team_leader_role
            FROM team_reports tr 
            JOIN users u ON tr.team_leader_id = u.id 
            WHERE tr.status != 'draft'
            ORDER BY tr.submitted_at DESC 
            LIMIT 10
        ");
        $teamReports = $teamReportsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Team reports query error: " . $e->getMessage());
        $teamReports = [];
    }

    // Statistics
    $stats = [];
    $typeStats = [];
    try {
        $statsStmt = $pdo->query("SELECT status, COUNT(*) as count FROM reports WHERE status != 'draft' GROUP BY status");
        $stats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $typeStatsStmt = $pdo->query("SELECT report_type, COUNT(*) as count FROM reports WHERE status != 'draft' GROUP BY report_type");
        $typeStats = $typeStatsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Statistics query error: " . $e->getMessage());
    }

} catch (PDOException $e) {
    error_log("Main reports query error: " . $e->getMessage());
    $reports = $teamReports = $stats = $typeStats = [];
    $totalReports = 0;
    $totalPages = 1;
}

// Handle report actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $report_id = $_POST['report_id'] ?? '';
    
    try {
        switch ($action) {
            case 'review_report':
                $feedback = $_POST['feedback'] ?? '';
                $new_status = $_POST['status'] ?? 'reviewed';
                
                $stmt = $pdo->prepare("
                    UPDATE reports 
                    SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_status, $feedback, $user_id, $report_id]);
                
                $_SESSION['success'] = "Report reviewed successfully";
                break;
                
            case 'review_team_report':
                $feedback = $_POST['feedback'] ?? '';
                $new_status = $_POST['status'] ?? 'reviewed';
                
                $stmt = $pdo->prepare("
                    UPDATE team_reports 
                    SET status = ?, feedback = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_status, $feedback, $report_id]);
                
                $_SESSION['success'] = "Team report reviewed successfully";
                break;
        }
        
        header("Location: reports.php?" . $_SERVER['QUERY_STRING']);
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    }
}

// Export functions
function exportReportToPDF($report) {
    // In production, use a library like TCPDF or Dompdf
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="report_' . $report['id'] . '.pdf"');
    
    // Simple PDF content - replace with proper PDF generation
    $content = "Report: " . $report['title'] . "\n";
    $content .= "Author: " . $report['full_name'] . "\n";
    $content .= "Date: " . $report['submitted_at'] . "\n\n";
    $content .= "Content:\n";
    
    // Decode JSON content if it's stored as JSON
    $report_content = json_decode($report['content'], true);
    if (is_array($report_content)) {
        foreach ($report_content as $key => $value) {
            $content .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
        }
    } else {
        $content .= $report['content'];
    }
    
    echo $content;
}

function exportReportToWord($report) {
    header('Content-Type: application/vnd.ms-word');
    header('Content-Disposition: attachment; filename="report_' . $report['id'] . '.doc"');
    
    echo "<html>";
    echo "<body>";
    echo "<h1>" . htmlspecialchars($report['title']) . "</h1>";
    echo "<p><strong>Author:</strong> " . htmlspecialchars($report['full_name']) . "</p>";
    echo "<p><strong>Date:</strong> " . htmlspecialchars($report['submitted_at']) . "</p>";
    echo "<div>" . nl2br(htmlspecialchars($report['content'])) . "</div>";
    echo "</body>";
    echo "</html>";
}
$pending_tickets = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Committee Reports - Isonga RPSU</title>
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
            --info: #4dd0e1;
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

        /* Page Header */
        .page-header {
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
            border-color: var(--primary-blue);
            color: var(--primary-blue);
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.7rem;
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

        /* Filters Card */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .filter-select {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        /* Reports Container */
        .reports-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 992px) {
            .reports-container {
                grid-template-columns: 1fr;
            }
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
            background: var(--light-blue);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        /* Report Card */
        .report-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
            overflow: hidden;
        }

        .report-card:hover {
            box-shadow: var(--shadow-md);
        }

        .report-card.submitted {
            border-left-color: var(--warning);
        }

        .report-card.reviewed {
            border-left-color: var(--info);
        }

        .report-card.approved {
            border-left-color: var(--success);
        }

        .report-card.rejected {
            border-left-color: var(--danger);
        }

        .report-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem;
            background: var(--light-gray);
        }

        .report-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .report-meta {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .report-body {
            padding: 1rem;
        }

        .report-content {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .report-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Badges */
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-submitted {
            background: #fff3cd;
            color: #856404;
        }

        .status-reviewed {
            background: #cce7ff;
            color: #004085;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .page-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .page-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .page-btn.active {
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

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-blue);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            background: var(--white);
            position: sticky;
            bottom: 0;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        /* Form */
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
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
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

            #sidebarToggleBtn {
                display: none;
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

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                justify-content: stretch;
            }

            .filter-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .report-header {
                flex-direction: column;
            }

            .report-actions {
                flex-direction: column;
            }

            .modal-content {
                width: 95%;
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
                    <h1>Isonga - General Secretary Reports</h1>
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
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
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
                    <a href="reports.php" class="active">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
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
            

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $totalReports; ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $submitted = array_filter($stats, function($s) { return $s['status'] === 'submitted'; });
                            echo !empty($submitted) ? current($submitted)['count'] : 0;
                            ?>
                        </div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $reviewed = array_filter($stats, function($s) { return $s['status'] === 'reviewed'; });
                            echo !empty($reviewed) ? current($reviewed)['count'] : 0;
                            ?>
                        </div>
                        <div class="stat-label">Reviewed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($teamReports); ?></div>
                        <div class="stat-label">Team Reports</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="reports.php">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Report Type</label>
                            <select name="type" class="filter-select">
                                <option value="all" <?php echo $report_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="monthly" <?php echo $report_type === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="activity" <?php echo $report_type === 'activity' ? 'selected' : ''; ?>>Activity</option>
                                <option value="team" <?php echo $report_type === 'team' ? 'selected' : ''; ?>>Team</option>
                                <option value="academic" <?php echo $report_type === 'academic' ? 'selected' : ''; ?>>Academic</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="submitted" <?php echo $status === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                <option value="reviewed" <?php echo $status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">From Date</label>
                            <input type="date" name="date_from" class="filter-select" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">To Date</label>
                            <input type="date" name="date_to" class="filter-select" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="reports.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Reports Container -->
            <div class="reports-container">
                <!-- Individual Reports -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user"></i> Individual Committee Reports</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reports)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-alt"></i>
                                <p>No reports found matching your criteria</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($reports as $report): ?>
                                <div class="report-card <?php echo $report['status']; ?>">
                                    <div class="report-header">
                                        <div>
                                            <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                            <div class="report-meta">
                                                By <?php echo htmlspecialchars($report['full_name']); ?> • 
                                                <?php echo ucfirst($report['report_type']); ?> Report
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <span class="status-badge status-<?php echo $report['status']; ?>">
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                            <div class="report-meta" style="margin-top: 0.25rem;">
                                                <?php echo $report['submitted_at'] ? date('M j, Y', strtotime($report['submitted_at'])) : 'Not submitted'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="report-body">
                                        <div class="report-content">
                                            <?php 
                                            $content_preview = json_decode($report['content'], true);
                                            if (is_array($content_preview)) {
                                                $preview = implode(' ', array_slice($content_preview, 0, 2));
                                                echo htmlspecialchars(substr($preview, 0, 100)) . (strlen($preview) > 100 ? '...' : '');
                                            } else {
                                                echo htmlspecialchars(substr($report['content'], 0, 100)) . (strlen($report['content']) > 100 ? '...' : '');
                                            }
                                            ?>
                                        </div>
                                        <div class="report-actions">
                                            <button class="btn btn-primary btn-sm" onclick="viewReportDetails(<?php echo $report['id']; ?>)">
                                                <i class="fas fa-eye"></i> View Details
                                            </button>
                                            <?php if ($report['status'] === 'submitted'): ?>
                                                <button class="btn btn-warning btn-sm" onclick="reviewReport(<?php echo $report['id']; ?>)">
                                                    <i class="fas fa-check-circle"></i> Review
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($report['feedback']): ?>
                                                <button class="btn btn-info btn-sm" onclick="viewFeedback('<?php echo htmlspecialchars($report['feedback']); ?>')">
                                                    <i class="fas fa-comment"></i> View Feedback
                                                </button>
                                            <?php endif; ?>
                                            <div class="export-options" style="display: flex; gap: 0.25rem;">
                                                <a href="reports.php?export=pdf&id=<?php echo $report['id']; ?>" class="btn btn-danger btn-sm" target="_blank">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <a href="reports.php?export=word&id=<?php echo $report['id']; ?>" class="btn btn-success btn-sm" target="_blank">
                                                    <i class="fas fa-file-word"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Team Reports -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-users"></i> Team Reports</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($teamReports)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No team reports submitted yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($teamReports as $report): ?>
                                <div class="report-card <?php echo $report['status']; ?>">
                                    <div class="report-header">
                                        <div>
                                            <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                            <div class="report-meta">
                                                Team Leader: <?php echo htmlspecialchars($report['team_leader_name']); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <span class="status-badge status-<?php echo $report['status']; ?>">
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                            <div class="report-meta" style="margin-top: 0.25rem;">
                                                <?php echo $report['submitted_at'] ? date('M j, Y', strtotime($report['submitted_at'])) : 'Not submitted'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="report-body">
                                        <div class="report-content">
                                            <?php echo htmlspecialchars(substr($report['overall_summary'] ?? '', 0, 120)) . (strlen($report['overall_summary'] ?? '') > 120 ? '...' : ''); ?>
                                        </div>
                                        <div class="report-actions">
                                            <button class="btn btn-primary btn-sm" onclick="viewTeamReportDetails(<?php echo $report['id']; ?>)">
                                                <i class="fas fa-eye"></i> View Team Report
                                            </button>
                                            <?php if ($report['status'] === 'submitted'): ?>
                                                <button class="btn btn-warning btn-sm" onclick="reviewTeamReport(<?php echo $report['id']; ?>)">
                                                    <i class="fas fa-check-circle"></i> Review Team Report
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-btn">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="page-btn <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-btn">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- View Report Modal -->
    <div id="viewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Report Details</h3>
                <button class="close-modal" onclick="closeModal('viewReportModal')">&times;</button>
            </div>
            <div class="modal-body" id="reportDetails">
                <!-- Report details will be loaded here -->
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                    <p>Loading report details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewReportModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Review Report Modal -->
    <div id="reviewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Review Report</h3>
                <button class="close-modal" onclick="closeModal('reviewReportModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="reviewReportForm" method="POST">
                    <input type="hidden" name="report_id" id="review_report_id">
                    <input type="hidden" name="action" value="review_report">
                    
                    <div class="form-group">
                        <label class="form-label">Status:</label>
                        <select name="status" id="review_status" class="form-select" required>
                            <option value="reviewed">Mark as Reviewed</option>
                            <option value="approved">Approve Report</option>
                            <option value="rejected">Reject Report</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Feedback & Comments:</label>
                        <textarea name="feedback" id="review_feedback" class="form-control" rows="6" 
                                  placeholder="Provide your feedback, comments, and any follow-up actions required..." required></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                        <button type="button" class="btn btn-outline" onclick="closeModal('reviewReportModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Team Report Modal -->
    <div id="viewTeamReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Team Report Details</h3>
                <button class="close-modal" onclick="closeModal('viewTeamReportModal')">&times;</button>
            </div>
            <div class="modal-body" id="teamReportDetails">
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                    <p>Loading team report details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewTeamReportModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Review Team Report Modal -->
    <div id="reviewTeamReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Review Team Report</h3>
                <button class="close-modal" onclick="closeModal('reviewTeamReportModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="reviewTeamReportForm" method="POST">
                    <input type="hidden" name="report_id" id="review_team_report_id">
                    <input type="hidden" name="action" value="review_team_report">
                    
                    <div class="form-group">
                        <label class="form-label">Status:</label>
                        <select name="status" id="team_review_status" class="form-select" required>
                            <option value="reviewed">Mark as Reviewed</option>
                            <option value="approved">Approve Team Report</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Feedback & Comments:</label>
                        <textarea name="feedback" id="team_review_feedback" class="form-control" rows="6" 
                                  placeholder="Provide your feedback on the team report and any recommendations..." required></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                        <button type="button" class="btn btn-outline" onclick="closeModal('reviewTeamReportModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Feedback Modal -->
    <div id="viewFeedbackModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Review Feedback</h3>
                <button class="close-modal" onclick="closeModal('viewFeedbackModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="feedback-content">
                    <p id="feedbackText" style="white-space: pre-wrap;"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewFeedbackModal')">Close</button>
            </div>
        </div>
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

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Report functions
        function viewReportDetails(reportId) {
            openModal('viewReportModal');
            
            fetch(`get_report_details.php?id=${reportId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('reportDetails').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error loading report details:', error);
                    document.getElementById('reportDetails').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            Error loading report details. Please try again.
                        </div>
                    `;
                });
        }

        function reviewReport(reportId) {
            document.getElementById('review_report_id').value = reportId;
            openModal('reviewReportModal');
        }

        function viewTeamReportDetails(reportId) {
            openModal('viewTeamReportModal');
            
            fetch(`get_team_report_details.php?id=${reportId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('teamReportDetails').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error loading team report details:', error);
                    document.getElementById('teamReportDetails').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            Error loading team report details. Please try again.
                        </div>
                    `;
                });
        }

        function reviewTeamReport(reportId) {
            document.getElementById('review_team_report_id').value = reportId;
            openModal('reviewTeamReportModal');
        }

        function viewFeedback(feedback) {
            document.getElementById('feedbackText').textContent = feedback;
            openModal('viewFeedbackModal');
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });
    </script>
</body>
</html>