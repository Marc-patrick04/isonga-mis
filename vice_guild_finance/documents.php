<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
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

// Get current academic year dynamically
$current_academic_year = getCurrentAcademicYear();

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Create documents table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financial_documents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            document_type ENUM('receipt', 'invoice', 'contract', 'approval_letter', 'budget_report', 'financial_statement', 'meeting_minutes', 'other') NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            file_path VARCHAR(500) NOT NULL,
            file_size INT,
            file_type VARCHAR(100),
            reference_number VARCHAR(100),
            amount DECIMAL(15,2),
            related_to ENUM('transaction', 'budget_request', 'student_aid', 'mission_allowance', 'committee_request', 'rental', 'other'),
            related_id INT,
            academic_year VARCHAR(20),
            uploaded_by INT,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_archived BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Documents table creation error: " . $e->getMessage());
}

// Handle file upload
if ($action === 'upload_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_type = $_POST['document_type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $reference_number = trim($_POST['reference_number']);
    $amount = $_POST['amount'] ?: null;
    $related_to = $_POST['related_to'];
    $related_id = $_POST['related_id'] ?: null;
    
    try {
        // Handle file upload
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/documents/';
            
            // Create upload directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $fileExtension = pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $title) . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            // Validate file type
            $allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];
            if (!in_array(strtolower($fileExtension), $allowedTypes)) {
                throw new Exception('Invalid file type. Allowed types: ' . implode(', ', $allowedTypes));
            }
            
            // Validate file size (10MB max)
            if ($_FILES['document_file']['size'] > 10 * 1024 * 1024) {
                throw new Exception('File size too large. Maximum size is 10MB.');
            }
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $filePath)) {
                // Insert document record
                $stmt = $pdo->prepare("
                    INSERT INTO financial_documents 
                    (document_type, title, description, file_path, file_size, file_type, reference_number, amount, related_to, related_id, academic_year, uploaded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $document_type,
                    $title,
                    $description,
                    $filePath,
                    $_FILES['document_file']['size'],
                    $_FILES['document_file']['type'],
                    $reference_number,
                    $amount,
                    $related_to,
                    $related_id,
                    $current_academic_year,
                    $user_id
                ]);
                
                $message = "Document uploaded successfully!";
                $message_type = "success";
            } else {
                throw new Exception('Failed to upload file. Please try again.');
            }
        } else {
            throw new Exception('Please select a valid file to upload.');
        }
    } catch (Exception $e) {
        $message = "Error uploading document: " . $e->getMessage();
        $message_type = "error";
    }
}

// Update document
if ($action === 'update_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = $_POST['document_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $reference_number = trim($_POST['reference_number']);
    $amount = $_POST['amount'] ?: null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE financial_documents 
            SET title = ?, description = ?, reference_number = ?, amount = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$title, $description, $reference_number, $amount, $document_id]);
        
        $message = "Document updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error updating document: " . $e->getMessage();
        $message_type = "error";
    }
}

// Archive document
if ($action === 'archive_document' && isset($_GET['id'])) {
    $document_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE financial_documents SET is_archived = TRUE WHERE id = ?");
        $stmt->execute([$document_id]);
        $message = "Document archived successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error archiving document: " . $e->getMessage();
        $message_type = "error";
    }
}

// Restore document
if ($action === 'restore_document' && isset($_GET['id'])) {
    $document_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE financial_documents SET is_archived = FALSE WHERE id = ?");
        $stmt->execute([$document_id]);
        $message = "Document restored successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error restoring document: " . $e->getMessage();
        $message_type = "error";
    }
}

// Delete document
if ($action === 'delete_document' && isset($_GET['id'])) {
    $document_id = $_GET['id'];
    
    try {
        // Get file path first
        $stmt = $pdo->prepare("SELECT file_path FROM financial_documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            // Delete physical file
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            // Delete database record
            $stmt = $pdo->prepare("DELETE FROM financial_documents WHERE id = ?");
            $stmt->execute([$document_id]);
            
            $message = "Document deleted successfully!";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Error deleting document: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get filter parameters
$type_filter = $_GET['type'] ?? 'all';
$related_filter = $_GET['related_to'] ?? 'all';
$archived_filter = $_GET['archived'] ?? 'false';
$search = $_GET['search'] ?? '';

// Build query for documents
$query = "
    SELECT 
        fd.*,
        u.full_name as uploaded_by_name
    FROM financial_documents fd
    LEFT JOIN users u ON fd.uploaded_by = u.id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($type_filter !== 'all') {
    $query .= " AND fd.document_type = ?";
    $params[] = $type_filter;
}

if ($related_filter !== 'all') {
    $query .= " AND fd.related_to = ?";
    $params[] = $related_filter;
}

if ($archived_filter === 'true') {
    $query .= " AND fd.is_archived = TRUE";
} else {
    $query .= " AND fd.is_archived = FALSE";
}

if (!empty($search)) {
    $query .= " AND (fd.title LIKE ? OR fd.description LIKE ? OR fd.reference_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY fd.uploaded_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $documents = [];
    error_log("Documents query error: " . $e->getMessage());
}

// Get statistics
try {
    // Total documents
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM financial_documents WHERE is_archived = FALSE");
    $total_documents = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Documents by type
    $stmt = $pdo->query("
        SELECT document_type, COUNT(*) as count 
        FROM financial_documents 
        WHERE is_archived = FALSE 
        GROUP BY document_type
    ");
    $documents_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total storage used
    $stmt = $pdo->query("SELECT SUM(file_size) as total_size FROM financial_documents");
    $total_storage = $stmt->fetch(PDO::FETCH_ASSOC)['total_size'] ?? 0;

    // Recent uploads
    $stmt = $pdo->query("
        SELECT fd.*, u.full_name as uploaded_by_name
        FROM financial_documents fd
        LEFT JOIN users u ON fd.uploaded_by = u.id
        WHERE fd.is_archived = FALSE
        ORDER BY fd.uploaded_at DESC
        LIMIT 5
    ");
    $recent_uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_documents = $total_storage = 0;
    $documents_by_type = $recent_uploads = [];
    error_log("Documents statistics error: " . $e->getMessage());
}

// Get related items for dropdowns
try {
    // Get transactions for related documents
    $stmt = $pdo->query("
        SELECT id, reference_number, description, amount 
        FROM financial_transactions 
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get budget requests
    $stmt = $pdo->query("
        SELECT id, request_title, requested_amount 
        FROM committee_budget_requests 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $budget_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get student aid requests
    $stmt = $pdo->query("
        SELECT id, request_title, amount_requested 
        FROM student_financial_aid 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $student_aid_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $transactions = $budget_requests = $student_aid_requests = [];
    error_log("Related items error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Documents - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse all the CSS from dashboard.php */
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
            --finance-primary: #1976D2;
            --finance-secondary: #2196F3;
            --finance-accent: #0D47A1;
            --finance-light: #E3F2FD;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
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
            --finance-primary: #2196F3;
            --finance-secondary: #64B5F6;
            --finance-accent: #1976D2;
            --finance-light: #0D1B2A;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
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
            color: var(--finance-primary);
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
            border-color: var(--finance-primary);
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
            background: var(--finance-primary);
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
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
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
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon {
            background: var(--finance-light);
            color: var(--finance-primary);
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

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        .trend-positive {
            color: var(--success);
        }

        .trend-negative {
            color: var(--danger);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
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
            background: var(--finance-light);
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

        .amount {
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        /* Document type badges */
        .type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-receipt {
            background: #d4edda;
            color: #155724;
        }

        .type-invoice {
            background: #cce7ff;
            color: #004085;
        }

        .type-contract {
            background: #e2e3e5;
            color: #383d41;
        }

        .type-approval_letter {
            background: #d1ecf1;
            color: #0c5460;
        }

        .type-budget_report {
            background: #fff3cd;
            color: #856404;
        }

        .type-financial_statement {
            background: #f8d7da;
            color: #721c24;
        }

        .type-meeting_minutes {
            background: #d6d8d9;
            color: #1b1e21;
        }

        .type-other {
            background: #e2e3e5;
            color: #383d41;
        }

        /* File icons */
        .file-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-right: 0.75rem;
        }

        .file-pdf {
            background: #ffebee;
            color: #f44336;
        }

        .file-doc {
            background: #e3f2fd;
            color: #2196f3;
        }

        .file-xls {
            background: #e8f5e8;
            color: #4caf50;
        }

        .file-img {
            background: #fff3e0;
            color: #ff9800;
        }

        .file-other {
            background: #f3e5f5;
            color: #9c27b0;
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
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-file {
            width: 100%;
            padding: 0.75rem;
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--light-gray);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .form-file:hover {
            border-color: var(--finance-primary);
            background: var(--finance-light);
        }

        .form-file input {
            display: none;
        }

        .btn {
            padding: 0.75rem 1.5rem;
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
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
            transform: translateY(-1px);
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Document grid */
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .document-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .document-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            align-items: center;
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
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .document-body {
            padding: 1rem;
        }

        .document-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .document-actions {
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

        .modal.active {
            display: flex;
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
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        /* Alerts */
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

        /* Charts */
        .chart-container {
            position: relative;
            height: 200px;
            margin-top: 1rem;
        }

        /* File preview */
        .file-preview {
            text-align: center;
            padding: 2rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
        }

        .file-preview i {
            font-size: 3rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .file-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .file-size {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .documents-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
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
                    <h1>Isonga - Official Documents</h1>
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
                        <div class="user-role">Vice Guild Finance</div>
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
                    <a href="budget_management.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Budget Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="rental_management.php">
                        <i class="fas fa-home"></i>
                        <span>Rental Properties</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="allowances.php">
                        <i class="fas fa-money-check"></i>
                        <span>Allowances</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="bank_reconciliation.php">
                        <i class="fas fa-university"></i>
                        <span>Bank Reconciliation</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php" class="active">
                        <i class="fas fa-file-contract"></i>
                        <span>Official Documents</span>
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
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Official Documents Management 📄</h1>
                    <p>Manage and organize all financial documents for <?php echo $current_academic_year; ?> academic year</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Documents Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_documents; ?></div>
                        <div class="stat-label">Total Documents</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-line"></i> Active
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-database"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo formatFileSize($total_storage); ?></div>
                        <div class="stat-label">Storage Used</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-hdd"></i> Managed
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $receipt_count = array_reduce($documents_by_type, function($carry, $item) {
                                return $carry + ($item['document_type'] === 'receipt' ? $item['count'] : 0);
                            }, 0);
                            echo $receipt_count;
                            ?>
                        </div>
                        <div class="stat-label">Receipts</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-file-invoice"></i> Financial Records
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($documents_by_type); ?></div>
                        <div class="stat-label">Document Types</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-tags"></i> Categorized
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions & Recent Uploads -->
            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <button class="btn btn-primary" onclick="openModal('uploadDocumentModal')">
                                <i class="fas fa-upload"></i> Upload New Document
                            </button>
                            <a href="?archived=true" class="btn btn-warning">
                                <i class="fas fa-archive"></i> View Archived
                            </a>
                            <button class="btn btn-success" onclick="generateDocumentReport()">
                                <i class="fas fa-download"></i> Generate Report
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recent Uploads -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Uploads</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_uploads)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                <i class="fas fa-file-upload fa-2x" style="margin-bottom: 1rem;"></i>
                                <p>No documents uploaded yet</p>
                            </div>
                        <?php else: ?>
                            <div class="documents-grid">
                                <?php foreach ($recent_uploads as $document): ?>
                                    <div class="document-card">
                                        <div class="document-header">
                                            <div class="file-icon <?php echo getFileIconClass($document['file_type']); ?>">
                                                <i class="<?php echo getFileIcon($document['file_type']); ?>"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-title"><?php echo htmlspecialchars($document['title']); ?></div>
                                                <div class="document-meta">
                                                    <span class="type-badge type-<?php echo $document['document_type']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $document['document_type'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="document-body">
                                            <div class="document-description">
                                                <?php echo htmlspecialchars($document['description'] ?: 'No description provided'); ?>
                                            </div>
                                            <div class="document-meta" style="margin-bottom: 1rem;">
                                                <div>Uploaded by: <?php echo htmlspecialchars($document['uploaded_by_name']); ?></div>
                                                <div>Date: <?php echo date('M j, Y', strtotime($document['uploaded_at'])); ?></div>
                                                <div>Size: <?php echo formatFileSize($document['file_size']); ?></div>
                                            </div>
                                            <div class="document-actions">
                                                <a href="<?php echo $document['file_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="<?php echo $document['file_path']; ?>" download class="btn btn-success btn-sm">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label class="filter-label">Document Type</label>
                    <select class="form-select" onchange="applyFilters()" id="typeFilter">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="receipt" <?php echo $type_filter === 'receipt' ? 'selected' : ''; ?>>Receipts</option>
                        <option value="invoice" <?php echo $type_filter === 'invoice' ? 'selected' : ''; ?>>Invoices</option>
                        <option value="contract" <?php echo $type_filter === 'contract' ? 'selected' : ''; ?>>Contracts</option>
                        <option value="approval_letter" <?php echo $type_filter === 'approval_letter' ? 'selected' : ''; ?>>Approval Letters</option>
                        <option value="budget_report" <?php echo $type_filter === 'budget_report' ? 'selected' : ''; ?>>Budget Reports</option>
                        <option value="financial_statement" <?php echo $type_filter === 'financial_statement' ? 'selected' : ''; ?>>Financial Statements</option>
                        <option value="meeting_minutes" <?php echo $type_filter === 'meeting_minutes' ? 'selected' : ''; ?>>Meeting Minutes</option>
                        <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Related To</label>
                    <select class="form-select" onchange="applyFilters()" id="relatedFilter">
                        <option value="all" <?php echo $related_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <option value="transaction" <?php echo $related_filter === 'transaction' ? 'selected' : ''; ?>>Transactions</option>
                        <option value="budget_request" <?php echo $related_filter === 'budget_request' ? 'selected' : ''; ?>>Budget Requests</option>
                        <option value="student_aid" <?php echo $related_filter === 'student_aid' ? 'selected' : ''; ?>>Student Aid</option>
                        <option value="mission_allowance" <?php echo $related_filter === 'mission_allowance' ? 'selected' : ''; ?>>Mission Allowances</option>
                        <option value="committee_request" <?php echo $related_filter === 'committee_request' ? 'selected' : ''; ?>>Committee Requests</option>
                        <option value="rental" <?php echo $related_filter === 'rental' ? 'selected' : ''; ?>>Rental Properties</option>
                        <option value="other" <?php echo $related_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="filter-group" style="flex: 1;">
                    <label class="filter-label">Search</label>
                    <input type="text" class="form-control" placeholder="Search by title, description, reference..." 
                           value="<?php echo htmlspecialchars($search); ?>" id="searchInput" onkeyup="applyFilters()">
                </div>
                <div class="filter-group">
                    <label class="filter-label" style="visibility: hidden;">Actions</label>
                    <button class="btn" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>

            <!-- Documents Table -->
            <div class="card">
                <div class="card-header">
                    <h3>All Documents</h3>
                    <div class="card-header-actions">
                        <span class="filter-label" style="margin-right: 1rem;">
                            Showing <?php echo count($documents); ?> document(s)
                        </span>
                        <button class="card-header-btn" onclick="openModal('uploadDocumentModal')" title="Upload Document">
                            <i class="fas fa-upload"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($documents)): ?>
                        <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                            <i class="fas fa-folder-open fa-3x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3 style="margin-bottom: 0.5rem;">No documents found</h3>
                            <p>Upload your first document to get started</p>
                            <button class="btn btn-primary" onclick="openModal('uploadDocumentModal')" style="margin-top: 1rem;">
                                <i class="fas fa-upload"></i> Upload Document
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Uploaded By</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $document): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center;">
                                                <div class="file-icon <?php echo getFileIconClass($document['file_type']); ?>">
                                                    <i class="<?php echo getFileIcon($document['file_type']); ?>"></i>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($document['title']); ?></strong>
                                                    <br>
                                                    <small style="color: var(--dark-gray);">
                                                        <?php echo formatFileSize($document['file_size']); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="type-badge type-<?php echo $document['document_type']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $document['document_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($document['description'] ?: '-'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($document['reference_number'] ?: '-'); ?>
                                        </td>
                                        <td>
                                            <?php if ($document['amount']): ?>
                                                <span class="amount">RWF <?php echo number_format($document['amount'], 2); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($document['uploaded_by_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($document['uploaded_at'])); ?>
                                            <br>
                                            <small style="color: var(--dark-gray);">
                                                <?php echo date('g:i A', strtotime($document['uploaded_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                <a href="<?php echo $document['file_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo $document['file_path']; ?>" download class="btn btn-success btn-sm">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <button class="btn btn-warning btn-sm" onclick="editDocument(<?php echo $document['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($document['is_archived']): ?>
                                                    <a href="?action=restore_document&id=<?php echo $document['id']; ?>" class="btn btn-success btn-sm">
                                                        <i class="fas fa-undo"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?action=archive_document&id=<?php echo $document['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-archive"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="?action=delete_document&id=<?php echo $document['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this document? This action cannot be undone.')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Upload Document Modal -->
    <div id="uploadDocumentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload New Document</h3>
                <button class="close" onclick="closeModal('uploadDocumentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload_document">
                    
                    <div class="form-group">
                        <label class="form-label">Document Type *</label>
                        <select class="form-select" name="document_type" required>
                            <option value="">Select Document Type</option>
                            <option value="receipt">Receipt</option>
                            <option value="invoice">Invoice</option>
                            <option value="contract">Contract</option>
                            <option value="approval_letter">Approval Letter</option>
                            <option value="budget_report">Budget Report</option>
                            <option value="financial_statement">Financial Statement</option>
                            <option value="meeting_minutes">Meeting Minutes</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Document Title *</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Brief description of the document..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" name="reference_number" placeholder="e.g., INV-001, RCPT-2024-001">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Amount (RWF)</label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Related To</label>
                        <select class="form-select" name="related_to">
                            <option value="other">Not Specific</option>
                            <option value="transaction">Transaction</option>
                            <option value="budget_request">Budget Request</option>
                            <option value="student_aid">Student Aid</option>
                            <option value="mission_allowance">Mission Allowance</option>
                            <option value="committee_request">Committee Request</option>
                            <option value="rental">Rental Property</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Related Item ID (Optional)</label>
                        <input type="number" class="form-control" name="related_id" placeholder="ID of related transaction, request, etc.">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Document File *</label>
                        <div class="form-file" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-cloud-upload-alt fa-2x" style="margin-bottom: 1rem; color: var(--dark-gray);"></i>
                            <div style="font-weight: 600; margin-bottom: 0.5rem;">Click to select file</div>
                            <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                Max file size: 10MB • Supported: PDF, DOC, XLS, JPG, PNG
                            </div>
                            <input type="file" id="fileInput" name="document_file" required onchange="updateFileName(this)">
                        </div>
                        <div id="fileName" style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--success);"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('uploadDocumentModal')">Cancel</button>
                <button type="submit" form="uploadForm" class="btn btn-primary">Upload Document</button>
            </div>
        </div>
    </div>

    <!-- Edit Document Modal -->
    <div id="editDocumentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Document</h3>
                <button class="close" onclick="closeModal('editDocumentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="editForm">
                    <input type="hidden" name="action" value="update_document">
                    <input type="hidden" name="document_id" id="editDocumentId">
                    
                    <div class="form-group">
                        <label class="form-label">Document Title *</label>
                        <input type="text" class="form-control" name="title" id="editTitle" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="editDescription" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" name="reference_number" id="editReferenceNumber">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Amount (RWF)</label>
                        <input type="number" class="form-control" name="amount" id="editAmount" step="0.01" min="0">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('editDocumentModal')">Cancel</button>
                <button type="submit" form="editForm" class="btn btn-primary">Update Document</button>
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

        // Filter functionality
        function applyFilters() {
            const type = document.getElementById('typeFilter').value;
            const related = document.getElementById('relatedFilter').value;
            const search = document.getElementById('searchInput').value;
            const archived = '<?php echo $archived_filter; ?>';
            
            let url = 'documents.php?';
            const params = [];
            
            if (type !== 'all') params.push(`type=${type}`);
            if (related !== 'all') params.push(`related_to=${related}`);
            if (search) params.push(`search=${encodeURIComponent(search)}`);
            if (archived === 'true') params.push(`archived=true`);
            
            window.location.href = url + params.join('&');
        }

        function resetFilters() {
            window.location.href = 'documents.php';
        }

        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // File name display
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : 'No file selected';
            document.getElementById('fileName').textContent = 'Selected: ' + fileName;
        }

        // Edit document
        function editDocument(documentId) {
            // In a real application, you would fetch document data via AJAX
            // For now, we'll get it from the table row
            const row = document.querySelector(`tr td .btn[onclick="editDocument(${documentId})"]`).closest('tr');
            
            if (row) {
                const cells = row.cells;
                document.getElementById('editDocumentId').value = documentId;
                document.getElementById('editTitle').value = cells[0].querySelector('strong').textContent;
                document.getElementById('editDescription').value = cells[2].textContent.trim();
                document.getElementById('editReferenceNumber').value = cells[3].textContent.trim();
                
                const amountText = cells[4].textContent.trim();
                if (amountText !== '-') {
                    const amount = amountText.replace('RWF', '').replace(/,/g, '').trim();
                    document.getElementById('editAmount').value = amount;
                } else {
                    document.getElementById('editAmount').value = '';
                }
                
                openModal('editDocumentModal');
            }
        }

        // Generate document report
        function generateDocumentReport() {
            alert('Document report generation feature would be implemented here. This would generate a PDF report of all documents with filters applied.');
        }

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Auto-focus search input
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });
    </script>
</body>
</html>

<?php
// Helper functions
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function getFileIcon($fileType) {
    if (strpos($fileType, 'pdf') !== false) return 'fas fa-file-pdf';
    if (strpos($fileType, 'word') !== false || strpos($fileType, 'document') !== false) return 'fas fa-file-word';
    if (strpos($fileType, 'excel') !== false || strpos($fileType, 'spreadsheet') !== false) return 'fas fa-file-excel';
    if (strpos($fileType, 'image') !== false) return 'fas fa-file-image';
    if (strpos($fileType, 'text') !== false) return 'fas fa-file-alt';
    return 'fas fa-file';
}

function getFileIconClass($fileType) {
    if (strpos($fileType, 'pdf') !== false) return 'file-pdf';
    if (strpos($fileType, 'word') !== false || strpos($fileType, 'document') !== false) return 'file-doc';
    if (strpos($fileType, 'excel') !== false || strpos($fileType, 'spreadsheet') !== false) return 'file-xls';
    if (strpos($fileType, 'image') !== false) return 'file-img';
    return 'file-other';
}
?>