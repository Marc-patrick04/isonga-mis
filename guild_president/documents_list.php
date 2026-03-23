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

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];

    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];

    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'];
    } catch (Exception $e) {
        error_log("Reports table query error: " . $e->getMessage());
        $pending_reports = 0;
    }

    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as unread_messages FROM messages WHERE recipient_id = ? AND read_status = 0");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    } catch (Exception $e) {
        error_log("Messages table error: " . $e->getMessage());
        $unread_messages = 0;
    }

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

// Get filter parameters
$category = $_GET['category'] ?? 'all';
$doc_type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at_desc';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// First, let's check the structure of the documents table
try {
    $checkTableStmt = $pdo->query("DESCRIBE documents");
    $tableStructure = $checkTableStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Documents table structure: " . print_r($tableStructure, true));
} catch (Exception $e) {
    error_log("Error checking table structure: " . $e->getMessage());
}

// Build query for documents - FIXED VERSION
$query = "
    SELECT d.*, 
           u.full_name as generated_by_name
    FROM documents d
    LEFT JOIN users u ON d.generated_by = u.id
    WHERE 1=1
";

$params = [];
$types = [];

// Apply filters
if ($doc_type !== 'all') {
    $query .= " AND d.document_type = ?";
    $params[] = $doc_type;
    $types[] = PDO::PARAM_STR;
}

if ($status !== 'all') {
    $query .= " AND d.status = ?";
    $params[] = $status;
    $types[] = PDO::PARAM_STR;
}

if (!empty($search)) {
    $query .= " AND (d.title LIKE ? OR d.description LIKE ? OR u.full_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types[] = PDO::PARAM_STR;
    $types[] = PDO::PARAM_STR;
    $types[] = PDO::PARAM_STR;
}

// Apply sorting
switch ($sort) {
    case 'title_asc':
        $query .= " ORDER BY d.title ASC";
        break;
    case 'title_desc':
        $query .= " ORDER BY d.title DESC";
        break;
    case 'created_at_asc':
        $query .= " ORDER BY d.created_at ASC";
        break;
    case 'created_at_desc':
    default:
        $query .= " ORDER BY d.created_at DESC";
        break;
}

// Count total records for pagination
$countQuery = "SELECT COUNT(*) as total FROM ($query) as count_table";
try {
    $countStmt = $pdo->prepare($countQuery);
    for ($i = 0; $i < count($params); $i++) {
        $countStmt->bindValue($i+1, $params[$i], $types[$i]);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);
} catch (Exception $e) {
    error_log("Count query error: " . $e->getMessage());
    $totalRecords = 0;
    $totalPages = 0;
}

// Add pagination to main query
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types[] = PDO::PARAM_INT;
$types[] = PDO::PARAM_INT;

// Execute main query
try {
    $stmt = $pdo->prepare($query);
    for ($i = 0; $i < count($params); $i++) {
        $stmt->bindValue($i+1, $params[$i], $types[$i]);
    }
    $stmt->execute();
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Main query error: " . $e->getMessage());
    $documents = [];
}

// Get categories for filter dropdown (if the table exists)
try {
    $categoriesStmt = $pdo->query("SELECT * FROM document_categories ORDER BY name");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Categories query error: " . $e->getMessage());
    $categories = [];
}

// Get document types for filter dropdown
$documentTypes = [
    'certificate' => 'Certificate',
    'receipt' => 'Receipt',
    'mission' => 'Mission Paper',
    'letter' => 'Letter',
    'report' => 'Report',
    'other' => 'Other'
];

// Get status options for filter dropdown
$statusOptions = [
    'draft' => 'Draft',
    'pending_approval' => 'Pending Approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'archived' => 'Archived'
];

// Get actual document types from database for dynamic dropdown
$actualDocTypes = [];
try {
    $typeStmt = $pdo->query("SELECT DISTINCT document_type FROM documents WHERE document_type IS NOT NULL");
    $dbTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dbTypes as $type) {
        $actualDocTypes[$type['document_type']] = $documentTypes[$type['document_type']] ?? ucfirst($type['document_type']);
    }
} catch (Exception $e) {
    error_log("Document types query error: " . $e->getMessage());
    $actualDocTypes = $documentTypes;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Documents - Isonga RPSU</title>
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
        
        /* Additional styles for documents list */
        .filter-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }
        
        .search-box {
            display: flex;
            gap: 0.5rem;
        }
        
        .search-box input {
            flex: 1;
        }
        
        .documents-table {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .table-header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 0.5fr;
            padding: 1rem 1.5rem;
            background: var(--light-blue);
            font-weight: 600;
            color: var(--text-dark);
            border-bottom: 1px solid var(--medium-gray);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 0.5fr;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }
        
        .table-row:hover {
            background: var(--light-blue);
        }
        
        .table-cell {
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        
        .document-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }
        
        .document-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .document-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }
        
        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            color: var(--text-dark);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            margin: 0 1rem;
            color: var(--dark-gray);
            font-size: 0.8rem;
        }
        
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
        
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            justify-content: flex-end;
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
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-draft { background: #fff3cd; color: #856404; }
        .status-pending_approval { background: #cce7ff; color: var(--primary-blue); }
        .status-approved { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }
        .status-archived { background: #e2e3e5; color: var(--dark-gray); }
        
        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .table-header, .table-row {
                grid-template-columns: 2fr 1fr 1fr 0.5fr;
            }
            
            .table-header .document-date, .table-row .document-date {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .table-header, .table-row {
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
            }
            
            .table-header .document-date, 
            .table-row .document-date,
            .table-header .document-status,
            .table-row .document-status {
                display: none;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
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
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents_list.php" class="active">
                        <i class="fas fa-list"></i>
                        <span>All Documents</span>
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
                        <h1>All Documents</h1>
                        <p>View, manage, and filter all documents in the system</p>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" action="documents_list.php" id="filterForm">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="search">Search Documents</label>
                                <div class="search-box">
                                    <input type="text" id="search" name="search" class="form-control" 
                                           placeholder="Search by title, description, or creator..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <?php if (!empty($categories)): ?>
                            <div class="filter-group">
                                <label for="category">Category</label>
                                <select id="category" name="category" class="form-control">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="filter-group">
                                <label for="type">Document Type</label>
                                <select id="type" name="type" class="form-control">
                                    <option value="all">All Types</option>
                                    <?php foreach ($actualDocTypes as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" 
                                            <?php echo $doc_type == $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="all">All Statuses</option>
                                    <?php foreach ($statusOptions as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" 
                                            <?php echo $status == $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="sort">Sort By</label>
                                <select id="sort" name="sort" class="form-control">
                                    <option value="created_at_desc" <?php echo $sort == 'created_at_desc' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="created_at_asc" <?php echo $sort == 'created_at_asc' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="title_asc" <?php echo $sort == 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
                                    <option value="title_desc" <?php echo $sort == 'title_desc' ? 'selected' : ''; ?>>Title Z-A</option>
                                </select>
                            </div>
                            <div class="filter-group" style="justify-content: flex-end;">
                                <button type="submit" class="btn btn-primary" style="margin-top: 1.75rem;">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="documents_list.php" class="btn btn-secondary" style="margin-top: 1.75rem;">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Documents Table -->
                <div class="documents-table">
                    <div class="table-header">
                        <div class="table-cell">Document</div>
                        <div class="table-cell">Type</div>
                        <div class="table-cell">Date</div>
                        <div class="table-cell">Status</div>
                        <div class="table-cell">Actions</div>
                    </div>
                    
                    <?php if (empty($documents)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file"></i>
                            <h3>No documents found</h3>
                            <p>Try adjusting your filters or create a new document.</p>
                            <a href="documents.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create Document
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <div class="table-row">
                                <div class="table-cell">
                                    <div class="document-icon" 
                                         style="background: var(--light-blue); color: var(--primary-blue);">
                                        <?php
                                        $icon = 'file';
                                        switch ($doc['document_type']) {
                                            case 'certificate': $icon = 'award'; break;
                                            case 'receipt': $icon = 'receipt'; break;
                                            case 'mission': $icon = 'plane'; break;
                                            case 'letter': $icon = 'envelope'; break;
                                            case 'report': $icon = 'file-alt'; break;
                                        }
                                        ?>
                                        <i class="fas fa-<?php echo $icon; ?>"></i>
                                    </div>
                                    <div>
                                        <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                        <div class="document-description">
                                            <?php echo htmlspecialchars($doc['description'] ?? 'No description'); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                            By: <?php echo htmlspecialchars($doc['generated_by_name'] ?? 'Unknown'); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-cell">
                                    <?php echo htmlspecialchars($actualDocTypes[$doc['document_type']] ?? ucfirst($doc['document_type'])); ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                                </div>
                                <div class="table-cell">
                                    <span class="status-badge status-<?php echo $doc['status']; ?>">
                                        <?php echo htmlspecialchars($statusOptions[$doc['status']] ?? ucfirst($doc['status'])); ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view" title="View Document"
                                                onclick="viewDocument(<?php echo $doc['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn btn-download" title="Download"
                                                onclick="downloadDocument(<?php echo $doc['id']; ?>)">
                                            <i class="fas fa-download"></i>
                                        </button>
                                        <?php if ($doc['status'] === 'pending_approval'): ?>
                                            <button class="action-btn btn-success" title="Approve"
                                                    onclick="approveDocument(<?php echo $doc['id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="action-btn btn-danger" title="Reject"
                                                    onclick="rejectDocument(<?php echo $doc['id']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <button class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" 
                                onclick="changePage(<?php echo $page - 1; ?>)" 
                                <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        
                        <div class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                            (<?php echo $totalRecords; ?> total documents)
                        </div>
                        
                        <button class="pagination-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" 
                                onclick="changePage(<?php echo $page + 1; ?>)" 
                                <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Success/Error Message Toast -->
    <div id="toast" class="toast"></div>
    
    <script>
        // Document Management JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            initializeThemeToggle();
            setupFilterInteractions();
        });
        
        function setupFilterInteractions() {
            // Auto-submit form when certain filters change
            const autoSubmitFilters = ['category', 'type', 'status', 'sort'];
            autoSubmitFilters.forEach(filterId => {
                const element = document.getElementById(filterId);
                if (element) {
                    element.addEventListener('change', function() {
                        document.getElementById('filterForm').submit();
                    });
                }
            });
        }
        
        function changePage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        function viewDocument(documentId) {
            window.open(`view_document.php?id=${documentId}&action=view`, '_blank');
        }
        
        function downloadDocument(documentId) {
            window.open(`view_document.php?id=${documentId}&action=download`, '_blank');
        }
        
        function approveDocument(documentId) {
            if (confirm('Are you sure you want to approve this document?')) {
                fetch('handle_document_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=approve_document&document_id=${documentId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Error approving document: ' + error.message, 'error');
                });
            }
        }
        
        function rejectDocument(documentId) {
            const reason = prompt('Please provide a reason for rejection:');
            if (reason !== null) {
                fetch('handle_document_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=reject_document&document_id=${documentId}&reason=${encodeURIComponent(reason)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Error rejecting document: ' + error.message, 'error');
                });
            }
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
    </script>
</body>
</html>