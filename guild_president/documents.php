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

// Get dashboard statistics for sidebar - PostgreSQL compatible
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;

    // Check if reports table exists and count pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (Exception $e) {
        error_log("Reports table query error: " . $e->getMessage());
        $pending_reports = 0;
    }

    // Check if messages table exists - using conversation_messages for PostgreSQL
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
        error_log("Messages table error: " . $e->getMessage());
        $unread_messages = 0;
    }

    // Check if documents table exists - PostgreSQL status check
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (Exception $e) {
        error_log("Documents table error: " . $e->getMessage());
        $pending_docs = 0;
    }

} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_tickets = $open_tickets = $pending_reports = $unread_messages = $pending_docs = 0;
}

// Get filter parameters
$category = $_GET['category'] ?? 'all';
$doc_type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

// Get dashboard statistics - PostgreSQL compatible
try {
    // Total documents
    $stmt = $pdo->query("SELECT COUNT(*) as total_docs FROM documents");
    $total_docs = $stmt->fetch(PDO::FETCH_ASSOC)['total_docs'] ?? 0;

    // Pending approvals
    $stmt = $pdo->query("SELECT COUNT(*) as pending_approvals FROM documents WHERE status = 'draft'");
    $pending_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['pending_approvals'] ?? 0;

    // Available templates
    $stmt = $pdo->query("SELECT COUNT(*) as total_templates FROM document_templates WHERE is_active = TRUE");
    $total_templates = $stmt->fetch(PDO::FETCH_ASSOC)['total_templates'] ?? 0;

} catch (PDOException $e) {
    error_log("Document stats error: " . $e->getMessage());
    $total_docs = $pending_approvals = $total_templates = 0;
}

// Get categories for filters - PostgreSQL compatible
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

    // Get recent documents - PostgreSQL compatible with LIMIT
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

    // Get committee members for mission paper - PostgreSQL role filter
    $committeeStmt = $pdo->query("
        SELECT id, full_name, role FROM users WHERE role LIKE '%committee%' OR role = 'guild_president' OR role = 'guild_vice_president'
        ORDER BY full_name
    ");
    $committeeMembers = $committeeStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Document queries error: " . $e->getMessage());
    $categories = $templates = $recent_docs = $committeeMembers = [];
}

// Get new student registrations count (last 7 days) - PostgreSQL syntax
try {
    $new_students_stmt = $pdo->prepare("
        SELECT COUNT(*) as new_students
        FROM users
        WHERE role = 'student'
        AND status = 'active'
        AND created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $new_students_stmt->execute();
    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
} catch (PDOException $e) {
    error_log("New students query error: " . $e->getMessage());
    $new_students = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Document Management - Isonga RPSU</title>
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
            margin-left: 70px;
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
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
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
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

        /* Quick Actions Grid */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .quick-action-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .quick-action-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .quick-action-icon {
            font-size: 2rem;
            color: var(--primary-blue);
            margin-bottom: 0.75rem;
        }

        .quick-action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .quick-action-desc {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        /* Cards */
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
            background: var(--light-gray);
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

        /* Document Items */
        .document-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
            gap: 0.75rem;
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
            font-size: 1rem;
            background: var(--light-blue);
            color: var(--primary-blue);
            flex-shrink: 0;
        }

        .document-info {
            flex: 1;
        }

        .document-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }

        .document-meta {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .action-btn {
            padding: 0.4rem;
            border: none;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: var(--transition);
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .action-btn:hover {
            background: var(--primary-blue);
            color: white;
        }

        /* Badges */
        .badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft, .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved, .status-generated {
            background: #d4edda;
            color: #155724;
        }

        .status-archived {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: inherit;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.7rem;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 2% auto;
            border-radius: var(--border-radius-lg);
            width: 95%;
            max-width: 700px;
            box-shadow: var(--shadow-lg);
            position: relative;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
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
            line-height: 1;
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--white);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-family: inherit;
            background: var(--white);
            color: var(--text-dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
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

        /* Checkbox Styles */
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: normal;
            gap: 0.5rem;
        }

        .checkbox-label input {
            width: auto;
        }

        /* File Upload Area */
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
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            border: 1px solid var(--medium-gray);
            gap: 0.75rem;
        }

        .file-icon {
            font-size: 1.2rem;
            color: var(--primary-blue);
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            display: block;
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .file-size {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Certificate Preview */
        .certificate-template {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border: 20px solid #2c3e50;
            padding: 2rem;
            text-align: center;
            font-family: 'Times New Roman', serif;
            position: relative;
            min-height: 450px;
        }

        .certificate-header {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .certificate-title {
            font-size: 1.4rem;
            color: #34495e;
            margin-bottom: 2rem;
            font-weight: 300;
            font-style: italic;
        }

        .certificate-recipient {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2c3e50;
            margin: 1.5rem 0;
            border-bottom: 2px solid #3498db;
            padding-bottom: 0.5rem;
            display: inline-block;
            text-transform: uppercase;
        }

        .certificate-content {
            font-size: 1rem;
            line-height: 1.6;
            color: #34495e;
            margin-bottom: 2rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .certificate-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
            padding: 0 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .certificate-signature {
            text-align: center;
            flex: 1;
            min-width: 150px;
        }

        .signature-line {
            border-top: 1px solid #2c3e50;
            width: 150px;
            margin: 1rem auto 0.5rem auto;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 0.75rem 1.25rem;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 500;
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            font-size: 0.8rem;
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

        .toast.info {
            background-color: var(--primary-blue);
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

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--light-gray);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .quick-actions-grid {
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

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 98%;
                margin: 1% auto;
            }

            .certificate-template {
                padding: 1rem;
                border-width: 10px;
            }

            .certificate-header {
                font-size: 1.2rem;
            }

            .certificate-recipient {
                font-size: 1.2rem;
            }

            .certificate-footer {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 1.2rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
            }

            .btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.7rem;
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
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    </div>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php" >
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" >
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
                        <span>Committee Performance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="manage_committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
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
                    <a href="reports.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Reports</span>
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
            <div class="container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Document Management</h1>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($total_docs); ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-number"><?php echo number_format($pending_approvals); ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-number"><?php echo number_format($total_templates); ?></div>
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
                                <h3><i class="fas fa-history"></i> Recent Documents</h3>
                                <div class="card-header-actions">
                                    <a href="documents_list.php" class="card-header-btn" title="View All">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_docs)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-file"></i>
                                        <p>No documents generated yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_docs as $doc): ?>
                                        <div class="document-item">
                                            <div class="document-icon">
                                                <?php
                                                $icon = 'file';
                                                switch ($doc['document_type'] ?? 'other') {
                                                    case 'certificate': $icon = 'award'; break;
                                                    case 'receipt': $icon = 'receipt'; break;
                                                    case 'mission': $icon = 'plane'; break;
                                                    case 'letter': $icon = 'envelope'; break;
                                                    default: $icon = 'file';
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
                                                <button class="action-btn" title="View Document" onclick="viewDocument(<?php echo $doc['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn" title="Download" onclick="downloadDocument(<?php echo $doc['id']; ?>)">
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
                        <!-- Document Statistics -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-chart-pie"></i> Document Statistics</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <?php
                                    $certCount = 0;
                                    $receiptCount = 0;
                                    $missionCount = 0;
                                    foreach ($recent_docs as $d) {
                                        if (($d['document_type'] ?? '') === 'certificate') $certCount++;
                                        if (($d['document_type'] ?? '') === 'receipt') $receiptCount++;
                                        if (($d['document_type'] ?? '') === 'mission') $missionCount++;
                                    }
                                    $approved = 0;
                                    foreach ($recent_docs as $d) {
                                        if (($d['status'] ?? '') === 'approved') $approved++;
                                    }
                                    ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Certificates Generated</span>
                                        <strong style="color: var(--text-dark);"><?php echo $certCount; ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Receipts Created</span>
                                        <strong style="color: var(--text-dark);"><?php echo $receiptCount; ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Mission Papers</span>
                                        <strong style="color: var(--text-dark);"><?php echo $missionCount; ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">Approval Rate</span>
                                        <strong style="color: var(--text-dark);"><?php echo $total_docs > 0 ? round(($approved / $total_docs) * 100) : 0; ?>%</strong>
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
        <div class="modal-content">
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
                            <input type="text" id="recipient_name" name="recipient_name" class="form-control" required placeholder="Enter full name">
                        </div>
                        <div class="form-group">
                            <label for="recipient_reg_number">Registration Number:</label>
                            <input type="text" id="recipient_reg_number" name="recipient_reg_number" class="form-control" placeholder="e.g., 24RP00123">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="event_name">Event/Activity Name:</label>
                        <input type="text" id="event_name" name="event_name" class="form-control" required placeholder="e.g., Annual Sports Day 2024">
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
                        <textarea id="certificate_description" name="description" class="form-control" rows="3" placeholder="Describe the achievement or participation..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="certificate_position">Position/Role (if any):</label>
                        <input type="text" id="certificate_position" name="position" class="form-control" placeholder="e.g., Team Captain, Event Coordinator">
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
        <div class="modal-content">
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
                            <option value="external">External Party</option>
                        </select>
                    </div>
                    
                    <div id="studentFields" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="student_name">Student Name:</label>
                                <input type="text" id="student_name" name="student_name" class="form-control" placeholder="Enter student name">
                            </div>
                            <div class="form-group">
                                <label for="student_reg_number">Registration Number:</label>
                                <input type="text" id="student_reg_number" name="student_reg_number" class="form-control" placeholder="e.g., 24RP00123">
                            </div>
                        </div>
                    </div>
                    
                    <div id="externalFields" style="display: none;">
                        <div class="form-group">
                            <label for="external_name">Organization/Person Name:</label>
                            <input type="text" id="external_name" name="external_name" class="form-control" placeholder="Enter name or organization">
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
                        <input type="text" id="custom_purpose" name="custom_purpose" class="form-control" placeholder="Specify payment purpose">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount_paid">Amount Paid (RWF):</label>
                            <input type="number" id="amount_paid" name="amount_paid" class="form-control" min="0" step="100" required placeholder="0">
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
                        <textarea id="receipt_notes" name="notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
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
        <div class="modal-content">
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
                        <textarea id="mission_purpose" name="purpose" class="form-control" rows="3" required placeholder="Describe the purpose and objectives of this mission..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="mission_destination">Destination:</label>
                            <input type="text" id="mission_destination" name="destination" class="form-control" required placeholder="e.g., Kigali City, Ministry of Education">
                        </div>
                        <div class="form-group">
                            <label for="mission_contact">Contact Person/Organization:</label>
                            <input type="text" id="mission_contact" name="contact_person" class="form-control" placeholder="Name of contact person">
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
                        <input type="number" id="mission_budget" name="budget" class="form-control" min="0" step="1000" placeholder="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="mission_requirements">Special Requirements:</label>
                        <textarea id="mission_requirements" name="requirements" class="form-control" rows="2" placeholder="Any special equipment, documents, or support needed..."></textarea>
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
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Upload Document</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="uploadDocumentForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_document">
                    <div class="form-group">
                        <label for="document_title">Document Title:</label>
                        <input type="text" id="document_title" name="title" class="form-control" required placeholder="Enter document title">
                    </div>
                    <div class="form-group">
                        <label for="document_description">Description:</label>
                        <textarea id="document_description" name="description" class="form-control" rows="3" placeholder="Brief description of the document..."></textarea>
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
                                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--primary-blue); margin-bottom: 0.5rem;"></i>
                                <p>Click to browse or drag and drop files here</p>
                                <small>Supported formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (Max: 10MB)</small>
                            </div>
                            <input type="file" id="document_file" name="document_file" class="file-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
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

    <!-- Certificate Preview Modal -->
    <div id="certificatePreviewModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Certificate Preview</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div id="certificatePreview" style="border: 2px solid #ddd; padding: 1.5rem; background: white; min-height: 400px;">
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

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        // Document Management JavaScript
        let currentReportId = null;

        document.addEventListener('DOMContentLoaded', function() {
            initializeModals();
            setupFormInteractions();
            setDefaultDates();
            
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
                    mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
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
            
            // Close mobile nav on resize
            window.addEventListener('resize', () => {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('mobile-open');
                    mobileOverlay.classList.remove('active');
                    if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                    document.body.style.overflow = '';
                }
            });
            
            // Add animation to cards
            const cards = document.querySelectorAll('.stat-card, .card, .quick-action-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.animation = `fadeInUp 0.3s ease forwards`;
                card.style.animationDelay = `${index * 0.05}s`;
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
            }, 100);
        });

        function initializeModals() {
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
            
            if (fileInput && fileUploadArea) {
                fileInput.addEventListener('change', function(e) {
                    if (this.files.length > 0) {
                        showFilePreview(this.files[0]);
                    }
                });
                
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
            const today = new Date().toISOString().split('T')[0];
            const eventDate = document.getElementById('event_date');
            const issueDate = document.getElementById('issue_date');
            const paymentDate = document.getElementById('payment_date');
            
            if (eventDate) eventDate.value = today;
            if (issueDate) issueDate.value = today;
            if (paymentDate) paymentDate.value = today;
            
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
            const modal = document.getElementById('generateCertificateModal');
            if (modal) modal.style.display = 'block';
        }

        function openCreateReceiptModal() {
            const modal = document.getElementById('createReceiptModal');
            if (modal) modal.style.display = 'block';
        }

        function openMissionPaperModal() {
            const modal = document.getElementById('missionPaperModal');
            if (modal) modal.style.display = 'block';
        }

        function openUploadDocumentModal() {
            const modal = document.getElementById('uploadDocumentModal');
            if (modal) modal.style.display = 'block';
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

        function previewCertificate() {
            const formData = new FormData(document.getElementById('generateCertificateForm'));
            const preview = document.getElementById('certificatePreview');
            
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
                    <div class="certificate-recipient">${escapeHtml(recipient)}</div>
                    <div class="certificate-content">
                        ${position ? `has served as <strong>${escapeHtml(position)}</strong> and ` : ''}
                        has successfully ${getCertificateVerb(certificateType)} in<br>
                        <strong>"${escapeHtml(event)}"</strong><br>
                        ${description ? `<em>${escapeHtml(description)}</em>` : ''}
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
                    <div style="text-align: center; margin-top: 1.5rem; color: #666; font-size: 0.75rem;">
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

        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            if (!toast) return;
            
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }

        function viewDocument(documentId) {
            window.open(`view_document.php?id=${documentId}&action=view`, '_blank');
        }

        function downloadDocument(documentId) {
            window.open(`view_document.php?id=${documentId}&action=download`, '_blank');
        }

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