<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get case ID from URL if provided
$case_id = $_GET['case_id'] ?? null;

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Handle filters
$document_type_filter = $_GET['document_type'] ?? '';
$confidential_filter = $_GET['confidential'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build WHERE clause for filters (PostgreSQL compatible)
$where_conditions = [];
$params = [];

if ($case_id) {
    $where_conditions[] = "cd.case_id = ?";
    $params[] = $case_id;
}

if (!empty($document_type_filter)) {
    $where_conditions[] = "cd.document_type = ?";
    $params[] = $document_type_filter;
}

if ($confidential_filter !== '') {
    $where_conditions[] = "cd.is_confidential = ?";
    $params[] = $confidential_filter === '1' ? true : false;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(cd.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(cd.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) 
    FROM case_documents cd
    JOIN arbitration_cases ac ON cd.case_id = ac.id
    $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_documents = $stmt->fetchColumn();
$total_pages = ceil($total_documents / $per_page);

// Get documents with filters and pagination
$sql = "
    SELECT 
        cd.*,
        ac.case_number,
        ac.title as case_title,
        u.full_name as uploaded_by_name,
        u.role as uploaded_by_role
    FROM case_documents cd
    JOIN arbitration_cases ac ON cd.case_id = ac.id
    JOIN users u ON cd.uploaded_by = u.id
    $where_clause
    ORDER BY cd.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get case details if case_id is provided
$case_details = null;
if ($case_id) {
    try {
        $stmt = $pdo->prepare("SELECT case_number, title FROM arbitration_cases WHERE id = ?");
        $stmt->execute([$case_id]);
        $case_details = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Case details error: " . $e->getMessage());
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $case_id = $_POST['case_id'];
    $document_type = $_POST['document_type'];
    $title = $_POST['title'];
    $description = $_POST['description'] ?? '';
    $is_confidential = isset($_POST['is_confidential']) ? true : false;
    
    // File upload handling
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_type = $file['type'];
        
        // Generate unique filename
        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
        $upload_dir = '../uploads/documents/';
        $file_path = $upload_dir . $unique_filename;
        
        // Create uploads directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Validate file type
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            $_SESSION['error_message'] = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
        } elseif ($file_size > 10 * 1024 * 1024) { // 10MB limit
            $_SESSION['error_message'] = "File size too large. Maximum size is 10MB.";
        } else {
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $file_path)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO case_documents 
                        (case_id, document_type, title, description, file_name, file_path, file_type, file_size, uploaded_by, is_confidential, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $case_id, $document_type, $title, $description, 
                        $file_name, $file_path, $file_type, $file_size, 
                        $user_id, $is_confidential
                    ]);
                    
                    $_SESSION['success_message'] = "Document uploaded successfully!";
                    header("Location: documents.php?" . ($case_id ? "case_id=$case_id&" : "") . "page=$page");
                    exit();
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error uploading document: " . $e->getMessage();
                    // Remove uploaded file if database insert fails
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            } else {
                $_SESSION['error_message'] = "Error uploading file. Please try again.";
            }
        }
    } else {
        $_SESSION['error_message'] = "Please select a file to upload.";
    }
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $document_id = $_POST['document_id'];
    
    try {
        // Get file path before deletion
        $stmt = $pdo->prepare("SELECT file_path FROM case_documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM case_documents WHERE id = ?");
            $stmt->execute([$document_id]);
            
            // Delete physical file
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            $_SESSION['success_message'] = "Document deleted successfully!";
        }
        
        header("Location: documents.php?" . ($case_id ? "case_id=$case_id&" : "") . "page=$page");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting document: " . $e->getMessage();
    }
}

// Get all cases for dropdown
$cases = [];
try {
    $stmt = $pdo->query("SELECT id, case_number, title FROM arbitration_cases ORDER BY created_at DESC");
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Cases fetch error: " . $e->getMessage());
}

// Get statistics (PostgreSQL uses INTERVAL)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_documents FROM case_documents");
    $total_documents_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_documents'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as confidential_documents FROM case_documents WHERE is_confidential = true");
    $confidential_documents_count = $stmt->fetch(PDO::FETCH_ASSOC)['confidential_documents'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as recent_documents FROM case_documents WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'");
    $recent_documents_count = $stmt->fetch(PDO::FETCH_ASSOC)['recent_documents'] ?? 0;
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(file_size), 0) as total_size FROM case_documents");
    $total_size = $stmt->fetch(PDO::FETCH_ASSOC)['total_size'] ?? 0;
    $total_size_mb = round($total_size / (1024 * 1024), 2);
    
    // Get sidebar statistics
    $stmt = $pdo->query("SELECT COUNT(*) as pending_cases FROM arbitration_cases WHERE status IN ('filed', 'under_review')");
    $sidebar_pending_cases = $stmt->fetch(PDO::FETCH_ASSOC)['pending_cases'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as active_elections FROM elections WHERE status IN ('nomination', 'campaign', 'voting')");
    $sidebar_active_elections = $stmt->fetch(PDO::FETCH_ASSOC)['active_elections'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as recent_notes FROM case_notes WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'");
    $sidebar_recent_notes = $stmt->fetch(PDO::FETCH_ASSOC)['recent_notes'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $sidebar_unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_documents_count = $confidential_documents_count = $recent_documents_count = 0;
    $total_size_mb = 0;
    $sidebar_pending_cases = $sidebar_active_elections = $sidebar_recent_notes = $sidebar_unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Case Documents - Arbitration Secretary</title>
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
            --purple: #6f42c1;
            --orange: #fd7e14;
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
            --purple: #9c27b0;
            --orange: #ff9800;
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
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
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
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

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
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
            padding: 1.5rem;
        }

        /* Alert */
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.25rem;
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
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
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
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Filters Card */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            padding: 1.5rem;
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-title {
            font-weight: 600;
            color: var(--text-dark);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-file {
            padding: 0.5rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        /* Documents Grid */
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .document-card {
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            transition: var(--transition);
            overflow: hidden;
        }

        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .document-card.confidential {
            border-left: 4px solid var(--danger);
        }

        .document-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .document-icon {
            width: 48px;
            height: 48px;
            background: var(--light-blue);
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-blue);
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .document-info {
            flex: 1;
        }

        .document-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .document-meta {
            font-size: 0.75rem;
            color: var(--dark-gray);
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        .document-type {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: var(--light-blue);
            color: var(--primary-blue);
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .document-confidential {
            background: var(--danger);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
        }

        .document-body {
            padding: 1rem;
        }

        .document-description {
            color: var(--text-dark);
            font-size: 0.85rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .document-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .document-footer {
            padding: 1rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .document-uploader {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .document-date {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .file-size {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .delete-form {
            display: inline;
        }

        /* File Input */
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-input-label {
            display: block;
            padding: 1rem;
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-input-label:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .file-input-label i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--dark-gray);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid var(--medium-gray);
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            color: var(--text-dark);
            text-decoration: none;
            border-radius: 4px;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .pagination-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--danger);
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
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
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

            .filters-form {
                grid-template-columns: 1fr;
            }

            .documents-grid {
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
                grid-template-columns: 1fr 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
                justify-content: space-between;
            }

            .document-header {
                flex-direction: column;
            }

            .document-info {
                margin-left: 0;
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
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .stat-number {
                font-size: 1.2rem;
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
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($sidebar_unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $sidebar_unread_messages; ?></span>
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
                        <div class="user-role">Arbitration Secretary</div>
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
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                        <?php if ($sidebar_pending_cases > 0): ?>
                            <span class="menu-badge"><?php echo $sidebar_pending_cases; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php">
                        <i class="fas fa-sticky-note"></i>
                        <span>Case Notes</span>
                        <?php if ($sidebar_recent_notes > 0): ?>
                            <span class="menu-badge"><?php echo $sidebar_recent_notes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php" class="active">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                        <?php if ($sidebar_active_elections > 0): ?>
                            <span class="menu-badge"><?php echo $sidebar_active_elections; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
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
                        <?php if ($sidebar_unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $sidebar_unread_messages; ?></span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Case Documents</h1>
                    <p style="color: var(--dark-gray); font-size: 0.9rem; margin-top: 0.25rem;">
                        <?php if ($case_details): ?>
                            For Case: <?php echo htmlspecialchars($case_details['case_number']); ?> - <?php echo htmlspecialchars($case_details['title']); ?>
                        <?php else: ?>
                            Manage all documents across arbitration cases
                        <?php endif; ?>
                    </p>
                </div>
                <div class="page-actions">
                    <?php if ($case_id): ?>
                        <a href="case-view.php?id=<?php echo $case_id; ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Case
                        </a>
                    <?php else: ?>
                        <a href="cases.php" class="btn btn-outline">
                            <i class="fas fa-balance-scale"></i> View Cases
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-primary" onclick="document.getElementById('uploadModal').style.display='flex'">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
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

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_documents_count); ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-eye-slash"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($confidential_documents_count); ?></div>
                        <div class="stat-label">Confidential</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($recent_documents_count); ?></div>
                        <div class="stat-label">Last 7 Days</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-database"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_size_mb; ?> MB</div>
                        <div class="stat-label">Total Storage</div>
                    </div>
                </div>
            </div>

            <!-- Filters Card -->
            <div class="filters-card">
                <div class="filters-header">
                    <h3 class="filters-title">Filter Documents</h3>
                </div>
                <form method="GET" class="filters-form">
                    <?php if ($case_id): ?>
                        <input type="hidden" name="case_id" value="<?php echo $case_id; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Document Type</label>
                        <select name="document_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="complaint" <?php echo $document_type_filter === 'complaint' ? 'selected' : ''; ?>>Complaint</option>
                            <option value="response" <?php echo $document_type_filter === 'response' ? 'selected' : ''; ?>>Response</option>
                            <option value="evidence" <?php echo $document_type_filter === 'evidence' ? 'selected' : ''; ?>>Evidence</option>
                            <option value="witness_statement" <?php echo $document_type_filter === 'witness_statement' ? 'selected' : ''; ?>>Witness Statement</option>
                            <option value="expert_report" <?php echo $document_type_filter === 'expert_report' ? 'selected' : ''; ?>>Expert Report</option>
                            <option value="hearing_minutes" <?php echo $document_type_filter === 'hearing_minutes' ? 'selected' : ''; ?>>Hearing Minutes</option>
                            <option value="decision" <?php echo $document_type_filter === 'decision' ? 'selected' : ''; ?>>Decision</option>
                            <option value="other" <?php echo $document_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confidentiality</label>
                        <select name="confidential" class="form-select">
                            <option value="">All Documents</option>
                            <option value="1" <?php echo $confidential_filter === '1' ? 'selected' : ''; ?>>Confidential Only</option>
                            <option value="0" <?php echo $confidential_filter === '0' ? 'selected' : ''; ?>>Non-Confidential Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="documents.php<?php echo $case_id ? '?case_id=' . $case_id : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-sync-alt"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Documents Grid -->
            <div class="card">
                <div class="card-header">
                    <h3>Case Documents (<?php echo number_format($total_documents); ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($documents)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file"></i>
                            <p>No documents found matching your criteria.</p>
                            <button class="btn btn-primary" style="margin-top: 1rem;" onclick="document.getElementById('uploadModal').style.display='flex'">
                                <i class="fas fa-upload"></i> Upload First Document
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="documents-grid">
                            <?php foreach ($documents as $doc): ?>
                                <div class="document-card <?php echo $doc['is_confidential'] ? 'confidential' : ''; ?>">
                                    <div class="document-header">
                                        <div class="document-icon">
                                            <?php
                                            $file_extension = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                            $icon = 'file';
                                            if (in_array($file_extension, ['pdf'])) $icon = 'file-pdf';
                                            elseif (in_array($file_extension, ['doc', 'docx'])) $icon = 'file-word';
                                            elseif (in_array($file_extension, ['xls', 'xlsx'])) $icon = 'file-excel';
                                            elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'file-image';
                                            elseif (in_array($file_extension, ['txt'])) $icon = 'file-alt';
                                            ?>
                                            <i class="fas fa-<?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="document-info">
                                            <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                            <div class="document-meta">
                                                <span class="document-type"><?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?></span>
                                                <?php if ($doc['is_confidential']): ?>
                                                    <span class="document-confidential">Confidential</span>
                                                <?php endif; ?>
                                                <span class="file-size"><?php echo round($doc['file_size'] / 1024, 1); ?> KB</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="document-body">
                                        <?php if ($doc['description']): ?>
                                            <div class="document-description">
                                                <?php echo nl2br(htmlspecialchars($doc['description'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="document-actions">
                                            <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-primary btn-sm">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                            <a href="case-view.php?id=<?php echo $doc['case_id']; ?>" class="btn btn-outline btn-sm">
                                                <i class="fas fa-eye"></i> View Case
                                            </a>
                                            <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                                <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" name="delete_document" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="document-footer">
                                        <div class="document-uploader">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($doc['uploaded_by_name']); ?>
                                        </div>
                                        <div class="document-date">
                                            <i class="fas fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">
                                <i class="fas fa-chevron-left"></i> Previous
                            </span>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= min($total_pages, 10); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">
                                Next <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Upload Document Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Document</h3>
                <button class="modal-close" onclick="document.getElementById('uploadModal').style.display='none'">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Case</label>
                        <select name="case_id" class="form-control" required>
                            <option value="">Select Case</option>
                            <?php foreach ($cases as $case): ?>
                                <option value="<?php echo $case['id']; ?>" <?php echo $case_id == $case['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($case['case_number']); ?> - <?php echo htmlspecialchars($case['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document Type</label>
                        <select name="document_type" class="form-control" required>
                            <option value="complaint">Complaint</option>
                            <option value="response">Response</option>
                            <option value="evidence">Evidence</option>
                            <option value="witness_statement">Witness Statement</option>
                            <option value="expert_report">Expert Report</option>
                            <option value="hearing_minutes">Hearing Minutes</option>
                            <option value="decision">Decision</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" placeholder="Enter document title" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" class="form-control form-textarea" placeholder="Enter document description"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document File</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="document_file" id="document_file" required accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.xls,.xlsx">
                            <label for="document_file" class="file-input-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <div>Click to choose file or drag and drop</div>
                                <div style="font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.5rem;">
                                    Max file size: 10MB • Allowed types: PDF, DOC, DOCX, TXT, JPG, PNG, XLS, XLSX
                                </div>
                            </label>
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_confidential" id="is_confidential" class="checkbox">
                        <label for="is_confidential">Mark as confidential (only visible to arbitration committee members)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('uploadModal').style.display='none'">Cancel</button>
                    <button type="submit" name="upload_document" class="btn btn-primary">Upload Document</button>
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

        // Modal functionality
        const uploadModal = document.getElementById('uploadModal');

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === uploadModal) {
                uploadModal.style.display = 'none';
            }
        });

        // File input styling
        const fileInput = document.getElementById('document_file');
        const fileInputLabel = fileInput?.nextElementSibling;

        if (fileInput && fileInputLabel) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const fileName = this.files[0].name;
                    fileInputLabel.innerHTML = `
                        <i class="fas fa-file"></i>
                        <div>${fileName}</div>
                        <div style="font-size: 0.75rem; color: var(--success); margin-top: 0.5rem;">
                            File selected successfully
                        </div>
                    `;
                    fileInputLabel.style.borderColor = 'var(--success)';
                    fileInputLabel.style.background = 'var(--light-blue)';
                }
            });

            // Drag and drop functionality
            fileInputLabel.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--primary-blue)';
                this.style.background = 'var(--light-blue)';
            });

            fileInputLabel.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--medium-gray)';
                this.style.background = 'var(--white)';
            });

            fileInputLabel.addEventListener('drop', function(e) {
                e.preventDefault();
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
        }

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .card, .filters-card');
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