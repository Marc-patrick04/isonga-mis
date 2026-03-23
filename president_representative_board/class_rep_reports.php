<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_representative_board') {
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

// Handle form actions
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Delete Report
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $report_id = $_GET['id'];
        
        // Delete the report
        $stmt = $pdo->prepare("DELETE FROM class_rep_reports WHERE id = ?");
        $stmt->execute([$report_id]);
        
        $message = "Report deleted successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Update Report Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $report_id = $_POST['report_id'];
        $status = $_POST['status'];
        $feedback = $_POST['feedback'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE class_rep_reports 
            SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$status, $feedback, $user_id, $report_id]);
        
        $message = "Report status updated successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Export Reports
if ($action === 'export') {
    try {
        // Get reports for export
        $stmt = $pdo->query("
            SELECT 
                crr.*,
                u.full_name as rep_name,
                u.reg_number,
                d.name as department_name,
                p.name as program_name,
                admin.full_name as reviewed_by_name
            FROM class_rep_reports crr
            JOIN users u ON crr.user_id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            LEFT JOIN users admin ON crr.reviewed_by = admin.id
            ORDER BY crr.created_at DESC
        ");
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=class_rep_reports_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, [
            'ID', 'Representative', 'Registration Number', 'Department', 'Program',
            'Report Title', 'Report Type', 'Report Period', 'Activity Date',
            'Status', 'Submitted At', 'Reviewed By', 'Reviewed At'
        ]);
        
        // Add data rows
        foreach ($reports as $report) {
            fputcsv($output, [
                $report['id'],
                $report['rep_name'],
                $report['reg_number'],
                $report['department_name'] ?? 'N/A',
                $report['program_name'] ?? 'N/A',
                $report['title'],
                $report['report_type'],
                $report['report_period'],
                $report['activity_date'],
                $report['status'],
                $report['submitted_at'],
                $report['reviewed_by_name'] ?? 'N/A',
                $report['reviewed_at']
            ]);
        }
        
        fclose($output);
        exit();
        
    } catch (Exception $e) {
        $message = "Export failed: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters
$query = "
    SELECT 
        crr.*,
        u.full_name as rep_name,
        u.reg_number,
        d.name as department_name,
        p.name as program_name,
        admin.full_name as reviewed_by_name
    FROM class_rep_reports crr
    JOIN users u ON crr.user_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    LEFT JOIN users admin ON crr.reviewed_by = admin.id
";

$where_conditions = [];
$params = [];

// Apply filters
if ($status_filter) {
    $where_conditions[] = "crr.status = ?";
    $params[] = $status_filter;
}

if ($type_filter) {
    $where_conditions[] = "crr.report_type = ?";
    $params[] = $type_filter;
}

if ($date_from) {
    $where_conditions[] = "DATE(crr.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(crr.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY crr.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $total_reports = count($reports);
    
    $stmt = $pdo->query("SELECT COUNT(*) as submitted FROM class_rep_reports WHERE status = 'submitted'");
    $submitted_count = $stmt->fetch(PDO::FETCH_ASSOC)['submitted'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as reviewed FROM class_rep_reports WHERE status = 'reviewed'");
    $reviewed_count = $stmt->fetch(PDO::FETCH_ASSOC)['reviewed'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as approved FROM class_rep_reports WHERE status = 'approved'");
    $approved_count = $stmt->fetch(PDO::FETCH_ASSOC)['approved'] ?? 0;
    
    // Get report type counts
    $stmt = $pdo->query("
        SELECT report_type, COUNT(*) as count 
        FROM class_rep_reports 
        GROUP BY report_type 
        ORDER BY count DESC
    ");
    $type_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $reports = [];
    $total_reports = $submitted_count = $reviewed_count = $approved_count = 0;
    $type_counts = [];
    error_log("Class rep reports data error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = 1 AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM class_rep_reports WHERE status = 'submitted'");
    $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURDATE() AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_messages FROM conversation_messages cm JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
} catch (PDOException $e) {
    $sidebar_reps_count = $pending_reports = $upcoming_meetings = $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Representative Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #007bff;
            --secondary-blue: #0056b3;
            --accent-blue: #0069d9;
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
        }

        .dark-mode {
            --primary-blue: #4dabf7;
            --secondary-blue: #339af0;
            --accent-blue: #228be6;
            --light-blue: #1a365d;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
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

        .menu-divider {
            height: 1px;
            background: var(--medium-gray);
            margin: 1rem 1.5rem;
        }

        .menu-section {
            padding: 0.75rem 1.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
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

        /* Content Grid */
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

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
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

        .btn {
            padding: 0.5rem 1rem;
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

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #138496;
            transform: translateY(-1px);
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
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #cce7ff;
            color: var(--info);
        }

        .status-submitted {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-reviewed {
            background: #d4edda;
            color: var(--success);
        }

        .status-approved {
            background: #d1f2eb;
            color: var(--primary-blue);
        }

        .status-rejected {
            background: #f8d7da;
            color: var(--danger);
        }

        /* Filters */
        .filters {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-title {
            font-weight: 600;
            color: var(--text-dark);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Report Type Stats */
        .type-stats {
            display: grid;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .type-stat {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .type-name {
            font-size: 0.8rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .type-count {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-blue);
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
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
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

        .empty-state h4 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
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
            
            .form-row {
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
                    <h1>Isonga - Representative Board President</h1>
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
                        <div class="user-role">President - Representative Board</div>
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
                    <a href="class_reps.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Management</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php" class="active">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_performance.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Class Rep Performance</span>
                    </a>
                </li>
                
                <li class="menu-divider"></li>
                <li class="menu-section">Other Features</li>
                
                <li class="menu-item">
                    <a href="committee_budget_requests.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-handshake"></i>
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile</span>
                    </a>
                </li>
            </ul>
        </nav>


        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Class Representative Reports</h1>
                    <p>Review and manage reports submitted by class representatives</p>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i> 
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_reports; ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $submitted_count; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $reviewed_count; ?></div>
                        <div class="stat-label">Reviewed</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approved_count; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filters-header">
                    <div class="filters-title">Filter Reports</div>
                    <div class="filter-actions">
                        <a href="class_rep_reports.php" class="btn btn-sm">
                            <i class="fas fa-refresh"></i> Clear Filters
                        </a>
                        <a href="class_rep_reports.php?action=export<?php echo $status_filter ? '&status=' . $status_filter : ''; ?><?php echo $type_filter ? '&type=' . $type_filter : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-download"></i> Export CSV
                        </a>
                    </div>
                </div>
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                <option value="reviewed" <?php echo $status_filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="type">Report Type</label>
                            <select class="form-control" id="type" name="type">
                                <option value="">All Types</option>
                                <option value="monthly" <?php echo $type_filter === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="weekly" <?php echo $type_filter === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="activity" <?php echo $type_filter === 'activity' ? 'selected' : ''; ?>>Activity</option>
                                <option value="incident" <?php echo $type_filter === 'incident' ? 'selected' : ''; ?>>Incident</option>
                                <option value="meeting" <?php echo $type_filter === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                                <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="date_from">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="date_to">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </form>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Reports List -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Class Representative Reports (<?php echo $total_reports; ?>)</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($reports)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-alt"></i>
                                    <h4>No Reports Found</h4>
                                    <p>No class representative reports match your current filters.</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Report Details</th>
                                                <th>Representative</th>
                                                <th>Type</th>
                                                <th>Dates</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reports as $report): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($report['title']); ?></strong>
                                                        <br>
                                                        <small style="color: var(--dark-gray);">
                                                            Created: <?php echo date('M j, Y', strtotime($report['created_at'])); ?>
                                                        </small>
                                                        <?php if ($report['submitted_at']): ?>
                                                            <br>
                                                            <small style="color: var(--dark-gray);">
                                                                Submitted: <?php echo date('M j, Y', strtotime($report['submitted_at'])); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($report['rep_name']); ?></strong>
                                                        <br>
                                                        <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($report['reg_number']); ?></small>
                                                        <br>
                                                        <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($report['department_name'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td>
                                                        <span style="text-transform: capitalize;"><?php echo $report['report_type']; ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($report['report_period']): ?>
                                                            Period: <?php echo date('M Y', strtotime($report['report_period'])); ?>
                                                        <?php endif; ?>
                                                        <?php if ($report['activity_date']): ?>
                                                            <br>
                                                            Activity: <?php echo date('M j, Y', strtotime($report['activity_date'])); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $report['status']; ?>">
                                                            <?php echo ucfirst($report['status']); ?>
                                                        </span>
                                                        <?php if ($report['reviewed_by_name']): ?>
                                                            <br>
                                                            <small style="color: var(--dark-gray);">
                                                                By: <?php echo htmlspecialchars($report['reviewed_by_name']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                            <button class="btn btn-info btn-sm view-report" data-report-id="<?php echo $report['id']; ?>" title="View Report">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <?php if ($report['status'] === 'submitted'): ?>
                                                                <button class="btn btn-warning btn-sm review-report" data-report-id="<?php echo $report['id']; ?>" title="Review Report">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <a href="?action=delete&id=<?php echo $report['id']; ?>" 
                                                               class="btn btn-danger btn-sm"
                                                               onclick="return confirm('Are you sure you want to delete this report? This action cannot be undone.')"
                                                               title="Delete Report">
                                                                <i class="fas fa-trash"></i>
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

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Report Types Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Reports by Type</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($type_counts)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No report type statistics available</p>
                                </div>
                            <?php else: ?>
                                <div class="type-stats">
                                    <?php foreach ($type_counts as $type): ?>
                                        <div class="type-stat">
                                            <span class="type-name" style="text-transform: capitalize;"><?php echo $type['report_type']; ?></span>
                                            <span class="type-count"><?php echo $type['count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <a href="class_rep_reports.php?status=submitted" class="btn btn-warning">
                                    <i class="fas fa-clock"></i> View Pending Reports
                                </a>
                                <a href="class_rep_reports.php?action=export" class="btn btn-success">
                                    <i class="fas fa-download"></i> Export All Reports
                                </a>
                                <a href="class_reps.php" class="btn btn-primary">
                                    <i class="fas fa-users"></i> Manage Representatives
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Information Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Report Status Guide</h3>
                        </div>
                        <div class="card-body">
                            <div style="font-size: 0.8rem; color: var(--text-dark);">
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <span class="status-badge status-draft">Draft</span>
                                    <span> - Report is being prepared by representative</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <span class="status-badge status-submitted">Submitted</span>
                                    <span> - Awaiting your review</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <span class="status-badge status-reviewed">Reviewed</span>
                                    <span> - You have reviewed the report</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <span class="status-badge status-approved">Approved</span>
                                    <span> - Report has been approved</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span class="status-badge status-rejected">Rejected</span>
                                    <span> - Report needs revision</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- View Report Modal -->
    <div id="viewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Report Details</h3>
                <button class="card-header-btn close-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="reportDetails">
                <!-- Report details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary close-modal">Close</button>
            </div>
        </div>
    </div>

    <!-- Review Report Modal -->
    <div id="reviewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Review Report</h3>
                <button class="card-header-btn close-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="report_id" id="review_report_id">
                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-control" id="status" name="status" required>
                            <option value="reviewed">Reviewed</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="feedback">Feedback</label>
                        <textarea class="form-control" id="feedback" name="feedback" rows="4" placeholder="Provide feedback to the class representative..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn close-modal">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
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

        // Modal functionality
        const viewReportModal = document.getElementById('viewReportModal');
        const reviewReportModal = document.getElementById('reviewReportModal');
        const reportDetails = document.getElementById('reportDetails');
        const reviewReportId = document.getElementById('review_report_id');

        // View Report
        document.querySelectorAll('.view-report').forEach(button => {
            button.addEventListener('click', function() {
                const reportId = this.getAttribute('data-report-id');
                
                // Show loading
                reportDetails.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading report details...</div>';
                viewReportModal.style.display = 'flex';
                
// Load report details via AJAX
fetch(`get_rep_report_details.php?id=${reportId}`)
    .then(response => response.text())
    .then(html => {
        reportDetails.innerHTML = html;
    })
    .catch(error => {
        reportDetails.innerHTML = '<div style="text-align: center; color: var(--danger); padding: 2rem;">Error loading report details.</div>';
    });
            });
        });

        // Review Report
        document.querySelectorAll('.review-report').forEach(button => {
            button.addEventListener('click', function() {
                const reportId = this.getAttribute('data-report-id');
                reviewReportId.value = reportId;
                reviewReportModal.style.display = 'flex';
            });
        });

        // Close modals
        document.querySelectorAll('.close-modal').forEach(button => {
            button.addEventListener('click', function() {
                viewReportModal.style.display = 'none';
                reviewReportModal.style.display = 'none';
            });
        });

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === viewReportModal) {
                viewReportModal.style.display = 'none';
            }
            if (event.target === reviewReportModal) {
                reviewReportModal.style.display = 'none';
            }
        });

        // Auto-refresh page every 5 minutes
        setInterval(() => {
            // You can add auto-refresh logic here if needed
            console.log('Class rep reports page auto-refresh triggered');
        }, 300000);
    </script>
</body>
</html>