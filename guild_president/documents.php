<?php
session_start();
require_once '../config/database.php';
// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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
// Get dashboard statistics for sidebar - CORRECTED
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];

    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];

    // Check if reports table exists and count pending reports - CORRECTED
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'];
    } catch (Exception $e) {
        error_log("Reports table query error: " . $e->getMessage());
        $pending_reports = 0;
    }

    // Check if messages table exists
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as unread_messages FROM messages WHERE recipient_id = ? AND read_status = 0");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    } catch (Exception $e) {
        error_log("Messages table error: " . $e->getMessage());
        $unread_messages = 0;
    }

    // Check if documents table exists
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'pending_approval'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];
    } catch (Exception $e) {
        error_log("Documents table error: " . $e->getMessage());
        $pending_docs = 0;
    }

} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_tickets = $open_tickets = $pending_reports = $unread_messages = $pending_docs = 0;
}
// Debug: Check if we have any reports at all
try {
    $debugStmt = $pdo->query("SELECT COUNT(*) as total FROM reports");
    $debugTotal = $debugStmt->fetch(PDO::FETCH_ASSOC)['total'];
    error_log("Total reports in database: " . $debugTotal);
} catch (Exception $e) {
    error_log("Debug query failed: " . $e->getMessage());
}
// Get filter parameters
$category = $_GET['category'] ?? 'all';
$doc_type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;
// Get dashboard statistics
try {
    // Total documents
    $stmt = $pdo->query("SELECT COUNT(*) as total_docs FROM documents");
    $total_docs = $stmt->fetch(PDO::FETCH_ASSOC)['total_docs'];

    // Pending approvals
    $stmt = $pdo->query("SELECT COUNT(*) as pending_approvals FROM documents WHERE status = 'draft'");
    $pending_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['pending_approvals'];



    // Available templates
    $stmt = $pdo->query("SELECT COUNT(*) as total_templates FROM document_templates WHERE is_active = TRUE");
    $total_templates = $stmt->fetch(PDO::FETCH_ASSOC)['total_templates'];

} catch (PDOException $e) {
    //$total_docs = $pending_approvals = $pending_requests = $total_templates = 0;
}
// Get categories for filters
try {
    $categoriesStmt = $pdo->query("SELECT * FROM document_categories ORDER BY name");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get templates
    $templatesStmt = $pdo->query("
        SELECT dt.*, dc.name as category_name, dc.icon, dc.color
        FROM document_templates dt
        LEFT JOIN document_categories dc ON dt.category_id = dc.id
        WHERE dt.is_active = TRUE
        ORDER BY dt.name
    ");
    $templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent documents
    $docsStmt = $pdo->prepare("
        SELECT d.*, u.full_name as generated_by_name, dt.name as template_name
        FROM documents d
        LEFT JOIN users u ON d.generated_by = u.id
        LEFT JOIN document_templates dt ON d.template_id = dt.id
        ORDER BY d.created_at DESC
        LIMIT 8
    ");
    $docsStmt->execute();
    $recent_docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);



    // Get committee members for mission paper
    $committeeStmt = $pdo->query("
        SELECT id, full_name, role FROM users WHERE role LIKE '%committee%' OR role = 'guild_president'
    ");
    $committeeMembers = $committeeStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $categories = $templates = $recent_docs = $pending_requests_list = $committeeMembers = [];
}
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --border-radius: 8px;
            --transition: all 0.2s ease;
        }
        /* Add all the CSS from tickets.php for consistency */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
            font-size: 0.875rem;
            transition: var(--transition);
        }
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
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .page-header {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
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
            font-size: 0.9rem;
        }
        /* Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-left: 3px solid var(--primary-blue);
        }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }
        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        .quick-action-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }
        .quick-action-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
        }
        .quick-action-icon {
            font-size: 2rem;
            color: var(--primary-blue);
            margin-bottom: 1rem;
        }
        .quick-action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        .quick-action-desc {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            font-size: 1.1rem;
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
            padding: 1.5rem;
        }
        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .template-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
            cursor: pointer;
        }
        .template-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .template-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            background: var(--primary-blue);
            color: white;
        }
        .template-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        .template-description {
            color: var(--dark-gray);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        .template-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }
        /* Document Items */
        .document-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }
        .document-item:hover {
            background: var(--light-blue);
        }
        .document-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1rem;
            background: var(--light-blue);
            color: var(--primary-blue);
        }
        .document-info {
            flex: 1;
        }
        .document-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }
        .document-meta {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }
        .action-btn {
            padding: 0.5rem;
            border: none;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            background: var(--light-gray);
            color: var(--text-dark);
        }
        .action-btn:hover {
            background: var(--primary-blue);
            color: white;
        }
        /* Request Items */
        .request-item {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            transition: var(--transition);
        }
        .request-item.urgent { border-left-color: var(--danger); }
        .request-item.high { border-left-color: var(--warning); }
        .request-item.medium { border-left-color: var(--primary-blue); }
        .request-item.low { border-left-color: var(--success); }
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        .request-title {
            font-weight: 600;
            color: var(--text-dark);
        }
        .request-meta {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }
        .request-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        /* Buttons */
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
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }
        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }
        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-warning {
            background: var(--warning);
            color: black;
        }
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }
        /* Badges */
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .priority-urgent { background: var(--danger); color: white; }
        .priority-high { background: var(--warning); color: black; }
        .priority-medium { background: var(--primary-blue); color: white; }
        .priority-low { background: var(--success); color: white; }
        .status-open { background: #fff3cd; color: var(--warning); }
        .status-in_progress { background: #cce7ff; color: var(--primary-blue); }
        .status-resolved { background: #d4edda; color: var(--success); }
        .status-closed { background: #e2e3e5; color: var(--dark-gray); }
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-md);
            animation: slideIn 0.3s;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1;
        }
        .modal-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }
        .close:hover {
            color: var(--danger);
        }
        .modal-body {
            padding: 1.5rem;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
            background: var(--white);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        /* Checkbox Styles */
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: normal;
            margin-bottom: 0.5rem;
        }
        .checkbox-label input[type="checkbox"] {
            margin-right: 0.5rem;
        }
        /* File Upload Styles */
        .file-upload-area {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }
        .file-upload-area:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }
        .file-upload-placeholder {
            pointer-events: none;
        }
        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .file-preview-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            border: 1px solid var(--medium-gray);
        }
        .file-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
            color: var(--primary-blue);
        }
        .file-info {
            flex: 1;
        }
        .file-name {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .file-size {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }
        /* Toast Styles */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 600;
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        .toast.show {
            transform: translateX(0);
        }
        .toast.success {
            background-color: var(--success);
        }
        .toast.error {
            background-color: var(--danger);
        }
        .toast.warning {
            background-color: var(--warning);
            color: black;
        }
        .toast.info {
            background-color: var(--primary-blue);
            color: white;
        }
        /* Certificate Preview Styles */
        .certificate-template {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border: 20px solid #2c3e50;
            padding: 3rem;
            text-align: center;
            font-family: 'Times New Roman', serif;
            position: relative;
        }
        .certificate-header {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 2rem;
            text-transform: uppercase;
        }
        .certificate-title {
            font-size: 1.8rem;
            color: #34495e;
            margin-bottom: 3rem;
            font-weight: 300;
        }
        .certificate-recipient {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin: 2rem 0;
            border-bottom: 2px solid #3498db;
            padding-bottom: 1rem;
            display: inline-block;
        }
        .certificate-content {
            font-size: 1.2rem;
            line-height: 1.6;
            color: #34495e;
            margin-bottom: 3rem;
        }
        .certificate-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 4rem;
        }
        .certificate-signature {
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #2c3e50;
            width: 200px;
            margin: 0.5rem 0;
        }
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            .content-grid {
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
            .template-grid {
                grid-template-columns: 1fr;
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
            .quick-actions-grid {
                grid-template-columns: 1fr;
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
        /* Dark Mode */
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

        /* Certificate Preview Styles */
.certificate-template {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    border: 20px solid #2c3e50;
    padding: 3rem;
    text-align: center;
    font-family: 'Times New Roman', serif;
    position: relative;
    min-height: 500px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.certificate-header {
    font-size: 2.5rem;
    font-weight: bold;
    color: #2c3e50;
    margin-bottom: 2rem;
    text-transform: uppercase;
    letter-spacing: 3px;
}

.certificate-title {
    font-size: 1.8rem;
    color: #34495e;
    margin-bottom: 3rem;
    font-weight: 300;
    font-style: italic;
}

.certificate-recipient {
    font-size: 2.2rem;
    font-weight: bold;
    color: #2c3e50;
    margin: 2rem 0;
    border-bottom: 3px solid #3498db;
    padding-bottom: 1rem;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.certificate-content {
    font-size: 1.3rem;
    line-height: 1.8;
    color: #34495e;
    margin-bottom: 3rem;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

.certificate-footer {
    display: flex;
    justify-content: space-between;
    margin-top: 4rem;
    padding: 0 2rem;
}

.certificate-signature {
    text-align: center;
    flex: 1;
}

.signature-line {
    border-top: 2px solid #2c3e50;
    width: 200px;
    margin: 2rem auto 0.5rem auto;
}

/* Responsive adjustments for certificate */
@media (max-width: 768px) {
    .certificate-template {
        padding: 1.5rem;
        border-width: 10px;
    }
    
    .certificate-header {
        font-size: 1.8rem;
    }
    
    .certificate-recipient {
        font-size: 1.6rem;
    }
    
    .certificate-content {
        font-size: 1.1rem;
    }
    
    .certificate-footer {
        flex-direction: column;
        gap: 2rem;
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
                    <a href="tickets.php">
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
                    <a href="documents.php" class="active">
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
                    <a href="messages.php">
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
        <main class="main-content">
            <div class="container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Document Management</h1>
                        <p>Generate certificates, manage templates, and documents handling</p>
                    </div>
                </div>
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_docs; ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-number"><?php echo $pending_approvals; ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>

                    <div class="stat-card success">
                        <div class="stat-number"><?php echo $total_templates; ?></div>
                        <div class="stat-label">Available Templates</div>
                    </div>
                </div>
                <!-- Quick Actions -->
                <div class="quick-actions-grid">
                    <div class="quick-action-card" onclick="openGenerateCertificateModal()">
                        <div class="quick-action-icon">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <div class="quick-action-title">Generate Certificate</div>
                        <div class="quick-action-desc">Create award and participation certificates</div>
                    </div>
                    <div class="quick-action-card" onclick="openCreateReceiptModal()">
                        <div class="quick-action-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="quick-action-title">Create Receipt</div>
                        <div class="quick-action-desc">Generate payment receipts for students</div>
                    </div>
                    <div class="quick-action-card" onclick="openMissionPaperModal()">
                        <div class="quick-action-icon">
                            <i class="fas fa-plane"></i>
                        </div>
                        <div class="quick-action-title">Mission Paper</div>
                        <div class="quick-action-desc">Authorize official missions and travels</div>
                    </div>
                    <div class="quick-action-card" onclick="openUploadDocumentModal()">
                        <div class="quick-action-icon">
                            <i class="fas fa-upload"></i>
                        </div>
                        <div class="quick-action-title">Upload Document</div>
                        <div class="quick-action-desc">Add documents to the repository</div>
                    </div>
                </div>
                <!-- Two Column Layout -->
                <div class="content-grid">
                    <!-- Left Column -->
                    <div class="left-column">
<!-- Recent Documents -->
<div class="card">
    <div class="card-header">
        <h3>Recent Documents</h3>
        <div class="card-header-actions">
            <a href="documents_list.php" class="card-header-btn" title="View All">
                <i class="fas fa-external-link-alt"></i>
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($recent_docs)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                <i class="fas fa-file" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>No documents generated yet</p>
            </div>
        <?php else: ?>
            <?php foreach ($recent_docs as $doc): ?>
                <div class="document-item">
                    <div class="document-icon" style="background: var(--light-blue); color: var(--primary-blue);">
                        <?php
                        $icon = 'file';
                        $color = 'var(--primary-blue)';
                        switch ($doc['document_type']) {
                            case 'certificate': $icon = 'award'; $color = '#28a745'; break;
                            case 'receipt': $icon = 'receipt'; $color = '#dc3545'; break;
                            case 'mission': $icon = 'plane'; $color = '#ffc107'; break;
                            case 'letter': $icon = 'envelope'; $color = '#0056b3'; break;
                        }
                        ?>
                        <i class="fas fa-<?php echo $icon; ?>"></i>
                    </div>
                    <div class="document-info">
                        <div class="document-title"><?php echo htmlspecialchars($doc['title'] ?? 'Untitled Document'); ?></div>
                        <div class="document-meta">
                            <?php echo htmlspecialchars($doc['generated_by_name'] ?? 'Unknown'); ?> •
                            <?php echo date('M j, Y', strtotime($doc['created_at'])); ?> •
                            <span class="badge status-<?php echo $doc['status'] ?? 'draft'; ?>">
                                <?php echo ucfirst($doc['status'] ?? 'Draft'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button class="action-btn btn-view" title="View Document"
                                onclick="viewDocument(<?php echo $doc['id']; ?>)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="action-btn btn-download" title="Download"
                                onclick="downloadDocument(<?php echo $doc['id']; ?>)">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
                    </div>
                    <!-- Right Column -->
                    <div class="right-column">
                        <!-- Quick Stats -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Document Statistics</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Certificates Generated</span>
                                        <strong style="color: var(--text-dark);">
                                            <?php
                                            $certCount = array_filter($recent_docs, fn($d) => ($d['document_type'] ?? '') === 'certificate');
                                            echo count($certCount);
                                            ?>
                                        </strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Receipts This Month</span>
                                        <strong style="color: var(--text-dark);">
                                            <?php
                                            $receiptCount = array_filter($recent_docs, fn($d) => ($d['document_type'] ?? '') === 'receipt');
                                            echo count($receiptCount);
                                            ?>
                                        </strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Mission Papers</span>
                                        <strong style="color: var(--text-dark);">
                                            <?php
                                            $missionCount = array_filter($recent_docs, fn($d) => ($d['document_type'] ?? '') === 'mission');
                                            echo count($missionCount);
                                            ?>
                                        </strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Approval Rate</span>
                                        <strong style="color: var(--text-dark);">
                                            <?php
                                            $approved = array_filter($recent_docs, fn($d) => ($d['status'] ?? '') === 'approved');
                                            echo $total_docs > 0 ? round((count($approved) / $total_docs) * 100) : 0;
                                            ?>%
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
<!-- Generate Certificate Modal -->
<div id="generateCertificateModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><i class="fas fa-certificate"></i> Generate Certificate</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <form id="generateCertificateForm">
                <input type="hidden" name="action" value="generate_certificate">
                <div class="form-group">
                    <label for="certificate_template">Certificate Type:</label>
                    <select name="template_id" id="certificate_template" class="form-control" required>
                        <option value="">Select Certificate Type</option>
                        <option value="1">Certificate of Participation</option>
                        <option value="2">Certificate of Achievement</option>
                        <option value="3">Certificate of Appreciation</option>
                        <option value="4">Certificate of Leadership</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="recipient_name">Recipient Name:</label>
                        <input type="text" id="recipient_name" name="recipient_name" class="form-control" required
                               placeholder="Enter full name">
                    </div>
                    <div class="form-group">
                        <label for="recipient_reg_number">Registration Number:</label>
                        <input type="text" id="recipient_reg_number" name="recipient_reg_number" class="form-control"
                               placeholder="e.g., 24RP00123">
                    </div>
                </div>
                <div class="form-group">
                    <label for="event_name">Event/Activity Name:</label>
                    <input type="text" id="event_name" name="event_name" class="form-control" required
                           placeholder="e.g., Annual Sports Day 2024">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="event_date">Event Date:</label>
                        <input type="date" id="event_date" name="event_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="issue_date">Issue Date:</label>
                        <input type="date" id="issue_date" name="issue_date" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="certificate_description">Achievement Description:</label>
                    <textarea id="certificate_description" name="description" class="form-control" rows="3"
                              placeholder="Describe the achievement or participation..."></textarea>
                </div>
                <div class="form-group">
                    <label for="certificate_position">Position/Role (if any):</label>
                    <input type="text" id="certificate_position" name="position" class="form-control"
                           placeholder="e.g., Team Captain, Event Coordinator">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="previewCertificate()">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-download"></i> Generate Certificate
                    </button>
                    <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Create Receipt Modal -->
<div id="createReceiptModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-receipt"></i> Create Receipt</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <form id="createReceiptForm">
                <input type="hidden" name="action" value="create_receipt">
                <div class="form-group">
                    <label for="receipt_for">Receipt For:</label>
                    <select id="receipt_for" name="receipt_for" class="form-control" required onchange="toggleReceiptFields()">
                        <option value="">Select Recipient Type</option>
                        <option value="student">Student</option>
                        <option value="committee">Committee Member</option>
                        <option value="external">External Party</option>
                    </select>
                </div>
                
                <div id="studentFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="student_name">Student Name:</label>
                            <input type="text" id="student_name" name="student_name" class="form-control"
                                   placeholder="Enter student name">
                        </div>
                        <div class="form-group">
                            <label for="student_reg_number">Registration Number:</label>
                            <input type="text" id="student_reg_number" name="student_reg_number" class="form-control"
                                   placeholder="e.g., 24RP00123">
                        </div>
                    </div>
                </div>
                
                <div id="externalFields" style="display: none;">
                    <div class="form-group">
                        <label for="external_name">Organization/Person Name:</label>
                        <input type="text" id="external_name" name="external_name" class="form-control"
                               placeholder="Enter name or organization">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="payment_purpose">Payment Purpose:</label>
                    <select id="payment_purpose" name="payment_purpose" class="form-control" required onchange="toggleCustomPurpose()">
                        <option value="">Select Purpose</option>
                        <option value="contribution">Student Contribution</option>
                        <option value="event">Event Participation</option>
                        <option value="fine">Fine Payment</option>
                        <option value="donation">Donation</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group" id="customPurposeField" style="display: none;">
                    <label for="custom_purpose">Custom Purpose:</label>
                    <input type="text" id="custom_purpose" name="custom_purpose" class="form-control"
                           placeholder="Specify payment purpose">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="amount_paid">Amount Paid (RWF):</label>
                        <input type="number" id="amount_paid" name="amount_paid" class="form-control"
                               min="0" step="100" required placeholder="0">
                    </div>
                    <div class="form-group">
                        <label for="payment_method">Payment Method:</label>
                        <select id="payment_method" name="payment_method" class="form-control" required>
                            <option value="">Select Method</option>
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="payment_date">Payment Date:</label>
                    <input type="date" id="payment_date" name="payment_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="receipt_notes">Additional Notes:</label>
                    <textarea id="receipt_notes" name="notes" class="form-control" rows="2"
                              placeholder="Any additional information..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-receipt"></i> Generate Receipt
                    </button>
                    <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Mission Paper Modal -->
<div id="missionPaperModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><i class="fas fa-plane"></i> Create Mission Paper</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <form id="missionPaperForm">
                <input type="hidden" name="action" value="create_mission">
                
                <div class="form-group">
                    <label for="mission_assignee">Person Assigned:</label>
                    <select id="mission_assignee" name="assignee_id" class="form-control" required>
                        <option value="">Select Committee Member</option>
                        <?php foreach ($committeeMembers as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['full_name'] . ' - ' . str_replace('_', ' ', $member['role'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="mission_purpose">Mission Purpose:</label>
                    <textarea id="mission_purpose" name="purpose" class="form-control" rows="3" required
                              placeholder="Describe the purpose and objectives of this mission..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="mission_destination">Destination:</label>
                        <input type="text" id="mission_destination" name="destination" class="form-control" required
                               placeholder="e.g., Kigali City, Ministry of Education">
                    </div>
                    <div class="form-group">
                        <label for="mission_contact">Contact Person/Organization:</label>
                        <input type="text" id="mission_contact" name="contact_person" class="form-control"
                               placeholder="Name of contact person">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="mission_start_date">Start Date & Time:</label>
                        <input type="datetime-local" id="mission_start_date" name="start_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="mission_end_date">End Date & Time:</label>
                        <input type="datetime-local" id="mission_end_date" name="end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="mission_transport">Mode of Transport:</label>
                    <select id="mission_transport" name="transport_mode" class="form-control" required>
                        <option value="">Select Transport</option>
                        <option value="public">Public Transport</option>
                        <option value="private">Private Vehicle</option>
                        <option value="college">College Vehicle</option>
                        <option value="walking">Walking</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="mission_budget">Estimated Budget (RWF):</label>
                    <input type="number" id="mission_budget" name="budget" class="form-control"
                           min="0" step="1000" placeholder="0">
                </div>
                
                <div class="form-group">
                    <label for="mission_requirements">Special Requirements:</label>
                    <textarea id="mission_requirements" name="requirements" class="form-control" rows="2"
                              placeholder="Any special equipment, documents, or support needed..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="requires_accommodation" value="1">
                        Requires Overnight Accommodation
                    </label>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="requires_advance" value="1">
                        Request Travel Advance
                    </label>
                </div>
                
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-file-signature"></i> Generate Mission Paper
                    </button>
                    <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


    <!-- Upload Document Modal -->
    <div id="uploadDocumentModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Upload Document</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="uploadDocumentForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_document">
                    <div class="form-group">
                        <label for="document_title">Document Title:</label>
                        <input type="text" id="document_title" name="title" class="form-control" required
                               placeholder="Enter document title">
                    </div>
                    <div class="form-group">
                        <label for="document_description">Description:</label>
                        <textarea id="document_description" name="description" class="form-control" rows="3"
                                  placeholder="Brief description of the document..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="document_category">Category:</label>
                        <select id="document_category" name="category_id" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="document_access">Access Level:</label>
                        <select id="document_access" name="access_level" class="form-control" required>
                            <option value="committee">Committee Members Only</option>
                            <option value="executive">Executive Committee Only</option>
                            <option value="public">Public (All Students)</option>
                            <option value="confidential">Confidential (Guild President Only)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="document_file">Select File:</label>
                        <div class="file-upload-area" id="fileUploadArea">
                            <div class="file-upload-placeholder">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--primary-blue); margin-bottom: 1rem;"></i>
                                <p>Click to browse or drag and drop files here</p>
                                <small>Supported formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (Max: 10MB)</small>
                            </div>
                            <input type="file" id="document_file" name="document_file" class="file-input"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                        </div>
                        <div id="filePreview" style="display: none; margin-top: 1rem;">
                            <div class="file-preview-item">
                                <i class="fas fa-file-pdf file-icon"></i>
                                <div class="file-info">
                                    <span class="file-name" id="fileName"></span>
                                    <span class="file-size" id="fileSize"></span>
                                </div>
                                <button type="button" class="btn btn-danger btn-sm" onclick="removeFile()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="notify_committee" value="1" checked>
                            Notify committee members about this upload
                        </label>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Upload Document
                        </button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Template Usage Modal -->
    <div id="templateUsageModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 id="templateModalTitle"><i class="fas fa-file-alt"></i> Use Template</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div id="templateFormContainer">
                    <!-- Dynamic form will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Certificate Preview Modal -->
<!-- Certificate Preview Modal -->
<div id="certificatePreviewModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Certificate Preview</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="certificatePreview" style="border: 2px solid #ddd; padding: 2rem; background: white; min-height: 500px;">
                <!-- Certificate preview will be generated here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="document.getElementById('generateCertificateForm').dispatchEvent(new Event('submit'));">
                    <i class="fas fa-download"></i> Generate & Download
                </button>
                <button type="button" class="btn btn-secondary close-modal">Close Preview</button>
            </div>
        </div>
    </div>
</div>


    <!-- Success/Error Message Toast -->
    <div id="toast" class="toast"></div>
    <script>
    // Document Management JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        initializeDocumentModals();
        setupFormInteractions();
        // Set default dates for forms
        setDefaultDates();
        // Initialize dark mode toggle
        initializeThemeToggle();
    });

    function initializeDocumentModals() {
        // Close modals when clicking X or outside
        const closeButtons = document.querySelectorAll('.close, .close-modal');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', closeAllModals);
        });
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                closeAllModals();
            }
        });
        
        // Form submissions
        const certificateForm = document.getElementById('generateCertificateForm');
        const receiptForm = document.getElementById('createReceiptForm');
        const missionForm = document.getElementById('missionPaperForm');
        const uploadForm = document.getElementById('uploadDocumentForm');
        
        if (certificateForm) {
            certificateForm.addEventListener('submit', function(e) {
                e.preventDefault();
                handleCertificateGeneration(e);
            });
        }
        if (receiptForm) {
            receiptForm.addEventListener('submit', function(e) {
                e.preventDefault();
                handleReceiptCreation(e);
            });
        }
        if (missionForm) {
            missionForm.addEventListener('submit', function(e) {
                e.preventDefault();
                handleMissionPaperCreation(e);
            });
        }
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                handleDocumentUpload(e);
            });
        }
    }

    function setupFormInteractions() {
        // Receipt form interactions
        const receiptFor = document.getElementById('receipt_for');
        if (receiptFor) {
            receiptFor.addEventListener('change', function() {
                const studentFields = document.getElementById('studentFields');
                const externalFields = document.getElementById('externalFields');
                if (this.value === 'student') {
                    studentFields.style.display = 'block';
                    externalFields.style.display = 'none';
                } else if (this.value === 'external') {
                    studentFields.style.display = 'none';
                    externalFields.style.display = 'block';
                } else {
                    studentFields.style.display = 'none';
                    externalFields.style.display = 'none';
                }
            });
        }
        
        // Payment purpose interaction
        const paymentPurpose = document.getElementById('payment_purpose');
        if (paymentPurpose) {
            paymentPurpose.addEventListener('change', function() {
                const customField = document.getElementById('customPurposeField');
                customField.style.display = this.value === 'other' ? 'block' : 'none';
            });
        }
        
        // File upload interactions
        const fileInput = document.getElementById('document_file');
        const fileUploadArea = document.getElementById('fileUploadArea');
        const filePreview = document.getElementById('filePreview');
        
        if (fileInput && fileUploadArea) {
            fileInput.addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    const file = this.files[0];
                    showFilePreview(file);
                }
            });
            
            // Drag and drop functionality
            fileUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--primary-blue)';
                this.style.background = 'var(--light-blue)';
            });
            
            fileUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--medium-gray)';
                this.style.background = 'transparent';
            });
            
            fileUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--medium-gray)';
                this.style.background = 'transparent';
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    showFilePreview(e.dataTransfer.files[0]);
                }
            });
        }
    }

    function setDefaultDates() {
        // Set today's date for date fields
        const today = new Date().toISOString().split('T')[0];
        const eventDate = document.getElementById('event_date');
        const issueDate = document.getElementById('issue_date');
        const paymentDate = document.getElementById('payment_date');
        
        if (eventDate) eventDate.value = today;
        if (issueDate) issueDate.value = today;
        if (paymentDate) paymentDate.value = today;
        
        // Set default mission dates (tomorrow 9 AM to 5 PM)
        const startDate = new Date();
        startDate.setDate(startDate.getDate() + 1);
        startDate.setHours(9, 0, 0, 0);
        
        const endDate = new Date(startDate);
        endDate.setHours(17, 0, 0, 0);
        
        const missionStart = document.getElementById('mission_start_date');
        const missionEnd = document.getElementById('mission_end_date');
        
        if (missionStart) missionStart.value = startDate.toISOString().slice(0, 16);
        if (missionEnd) missionEnd.value = endDate.toISOString().slice(0, 16);
    }

    function showFilePreview(file) {
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const filePreview = document.getElementById('filePreview');
        
        if (fileName && fileSize && filePreview) {
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            filePreview.style.display = 'block';
        }
    }

    function removeFile() {
        const fileInput = document.getElementById('document_file');
        const filePreview = document.getElementById('filePreview');
        if (fileInput && filePreview) {
            fileInput.value = '';
            filePreview.style.display = 'none';
        }
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Modal open functions
    function openGenerateCertificateModal() {
        document.getElementById('generateCertificateModal').style.display = 'block';
    }

    function openCreateReceiptModal() {
        document.getElementById('createReceiptModal').style.display = 'block';
    }

    function openMissionPaperModal() {
        document.getElementById('missionPaperModal').style.display = 'block';
    }

    function openUploadDocumentModal() {
        document.getElementById('uploadDocumentModal').style.display = 'block';
    }

    function closeAllModals() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.style.display = 'none';
        });
    }

    // Form handling functions
    function handleCertificateGeneration(e) {
        const form = document.getElementById('generateCertificateForm');
        const formData = new FormData(form);
        
        showToast('Generating certificate...', 'info');
        
        fetch('handle_document_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeAllModals();
                // Refresh the page after successful generation
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error generating certificate: ' + error.message, 'error');
        });
    }

    function handleReceiptCreation(e) {
        const form = document.getElementById('createReceiptForm');
        const formData = new FormData(form);
        
        showToast('Creating receipt...', 'info');
        
        fetch('handle_document_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeAllModals();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error creating receipt: ' + error.message, 'error');
        });
    }

    function handleMissionPaperCreation(e) {
        const form = document.getElementById('missionPaperForm');
        const formData = new FormData(form);
        
        showToast('Creating mission paper...', 'info');
        
        fetch('handle_document_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeAllModals();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error creating mission paper: ' + error.message, 'error');
        });
    }

    function handleDocumentUpload(e) {
        const form = document.getElementById('uploadDocumentForm');
        const formData = new FormData(form);
        
        showToast('Uploading document...', 'info');
        
        fetch('handle_document_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeAllModals();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error uploading document: ' + error.message, 'error');
        });
    }

    // Preview and generation functions
    function previewCertificate() {
        const formData = new FormData(document.getElementById('generateCertificateForm'));
        const preview = document.getElementById('certificatePreview');
        
        // Generate preview HTML
        preview.innerHTML = generateCertificatePreview(formData);
        document.getElementById('certificatePreviewModal').style.display = 'block';
    }

    function generateCertificatePreview(formData) {
        const recipient = formData.get('recipient_name') || 'Recipient Name';
        const event = formData.get('event_name') || 'Event Name';
        const description = formData.get('description') || '';
        const position = formData.get('position') || '';
        const certificateType = getCertificateType(formData.get('template_id'));
        
        return `
            <div class="certificate-template">
                <div class="certificate-header">CERTIFICATE OF ${certificateType.toUpperCase()}</div>
                <div class="certificate-title">This is to certify that</div>
                <div class="certificate-recipient">${recipient}</div>
                <div class="certificate-content">
                    ${position ? `has served as <strong>${position}</strong> and ` : ''}
                    has successfully ${getCertificateVerb(certificateType)} in<br>
                    <strong>"${event}"</strong><br>
                    ${description ? `<em>${description}</em>` : ''}
                </div>
                <div class="certificate-footer">
                    <div class="certificate-signature">
                        <div class="signature-line"></div>
                        <div>Guild President</div>
                        <div>RPSU - RP Musanze</div>
                    </div>
                    <div class="certificate-signature">
                        <div class="signature-line"></div>
                        <div>College Director</div>
                        <div>RP Musanze College</div>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 2rem; color: #666; font-size: 0.9rem;">
                    <em>This is a preview. The actual certificate will include official logos and seals.</em>
                </div>
            </div>
        `;
    }

    function getCertificateType(templateId) {
        const types = {
            '1': 'Participation',
            '2': 'Achievement',
            '3': 'Appreciation', 
            '4': 'Leadership'
        };
        return types[templateId] || 'Certificate';
    }

    function getCertificateVerb(type) {
        const verbs = {
            'Participation': 'participated',
            'Achievement': 'achieved excellence',
            'Appreciation': 'been recognized for outstanding contribution',
            'Leadership': 'demonstrated exceptional leadership'
        };
        return verbs[type] || 'completed';
    }

    function generateCertificate() {
        // For now, just show success message since we're storing in database
        showToast('Certificate saved successfully! You can download it from the documents list.', 'success');
        closeAllModals();
    }

    // Toast notification function
    function showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        if (!toast) {
            console.error('Toast element not found');
            return;
        }
        toast.textContent = message;
        toast.className = `toast ${type} show`;
        setTimeout(() => {
            toast.classList.remove('show');
        }, 5000);
    }

    // Theme toggle functionality
    function initializeThemeToggle() {
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
    }

    // View and Download functions
    function viewDocument(documentId) {
        window.open(`view_document.php?id=${documentId}&action=view`, '_blank');
    }

    function downloadDocument(documentId) {
        window.open(`view_document.php?id=${documentId}&action=download`, '_blank');
    }

    // Template modal function
    function openTemplateModal(templateId) {
        // For now, just show a message - you can implement template preview later
        showToast('Template preview feature coming soon!', 'info');
    }



    // Auto-refresh dashboard every 3 minutes
    setInterval(() => {
        // You can add auto-refresh logic here
        console.log('Dashboard auto-refresh triggered');
    }, 180000);

    // Add loading animations
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
    });


    // Helper functions for form interactions
function toggleReceiptFields() {
    const receiptFor = document.getElementById('receipt_for').value;
    const studentFields = document.getElementById('studentFields');
    const externalFields = document.getElementById('externalFields');
    
    if (receiptFor === 'student') {
        studentFields.style.display = 'block';
        externalFields.style.display = 'none';
    } else if (receiptFor === 'external') {
        studentFields.style.display = 'none';
        externalFields.style.display = 'block';
    } else {
        studentFields.style.display = 'none';
        externalFields.style.display = 'none';
    }
}

function toggleCustomPurpose() {
    const purpose = document.getElementById('payment_purpose').value;
    const customField = document.getElementById('customPurposeField');
    customField.style.display = purpose === 'other' ? 'block' : 'none';
}
</script>
</body>
</html>
