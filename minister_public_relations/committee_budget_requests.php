<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Initialize variables to prevent undefined errors
$unread_messages = 0;
$pending_tickets = 0;
$success = '';
$error = '';

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    // Handle new budget request submission
    $request_title = trim($_POST['request_title'] ?? '');
    $requested_amount = $_POST['requested_amount'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');
    
    // Get the committee ID for Minister of Public Relations
    try {
        $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'minister_public_relations'");
        $stmt->execute([$user_id]);
        $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$committee_member) {
            $error = "You are not assigned as Minister of Public Relations in any committee.";
        } else {
            $committee_id = $committee_member['id'];
        }
    } catch (PDOException $e) {
        $error = "Error fetching committee information: " . $e->getMessage();
    }
    
    // File upload handling
    $action_plan_file_path = '';
    
    if (empty($error) && isset($_FILES['action_plan_file']) && $_FILES['action_plan_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['action_plan_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/action_plans/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['action_plan_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $filename = 'action_plan_' . time() . '_' . $user_id . '.' . $file_extension;
                $destination = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['action_plan_file']['tmp_name'], $destination)) {
                    $action_plan_file_path = 'assets/uploads/action_plans/' . $filename;
                } else {
                    $error = "Failed to save uploaded file. Please try again.";
                }
            } else {
                $error = "Invalid file type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG";
            }
        } else {
            $error = "File upload error. Please try again.";
        }
    } elseif (empty($error)) {
        $error = "Please select an action plan file to upload.";
    }
    
    if (empty($error) && !empty($request_title) && !empty($requested_amount) && !empty($purpose)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO committee_budget_requests 
                (committee_id, request_title, action_plan_file_path, requested_amount, purpose, requested_by, request_date, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            
            $result = $stmt->execute([
                $committee_id, 
                $request_title, 
                $action_plan_file_path, 
                $requested_amount, 
                $purpose, 
                $user_id
            ]);
            
            if ($result) {
                $success = "Budget request submitted successfully!";
                // Refresh to show the new request
                header("Location: committee_budget_requests.php?success=1");
                exit();
            } else {
                $error = "Failed to submit budget request.";
            }
            
        } catch (PDOException $e) {
            $error = "Failed to submit budget request: " . $e->getMessage();
            error_log("Budget request submission error: " . $e->getMessage());
        }
    } elseif (empty($error)) {
        $error = "Please fill in all required fields.";
    }
}

// Check for success parameter in URL
if (isset($_GET['success'])) {
    $success = "Budget request submitted successfully!";
}

// Get budget requests data for the Minister of Public Relations
try {
    // Get the committee member ID for Minister of Public Relations
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'minister_public_relations'");
    $stmt->execute([$user_id]);
    $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($committee_member) {
        $committee_id = $committee_member['id'];
        
        // Get budget requests for this committee
        $stmt = $pdo->prepare("
            SELECT cbr.*, cm.name as committee_member_name, cm.role,
                   u.full_name as requester_name
            FROM committee_budget_requests cbr
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            LEFT JOIN users u ON cbr.requested_by = u.id
            WHERE cbr.requested_by = ?
            ORDER BY cbr.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $budget_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_requests,
                COALESCE(SUM(CASE WHEN status IN ('approved_by_finance', 'approved_by_president', 'funded') THEN requested_amount ELSE 0 END), 0) as total_approved_amount,
                COALESCE(SUM(CASE WHEN status = 'funded' THEN requested_amount ELSE 0 END), 0) as total_funded_amount,
                COUNT(CASE WHEN status = 'submitted' THEN 1 END) as pending_review,
                COUNT(CASE WHEN status IN ('approved_by_finance', 'approved_by_president', 'funded') THEN 1 END) as approved_requests
            FROM committee_budget_requests 
            WHERE requested_by = ?
        ");
        $stmt->execute([$user_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats) {
            $stats = ['total_requests' => 0, 'total_approved_amount' => 0, 'total_funded_amount' => 0, 'pending_review' => 0, 'approved_requests' => 0];
        }
    } else {
        $budget_requests = [];
        $stats = ['total_requests' => 0, 'total_approved_amount' => 0, 'total_funded_amount' => 0, 'pending_review' => 0, 'approved_requests' => 0];
    }
    
    // Get unread messages count
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $unread_messages = $result['unread_messages'] ?? 0;
    } catch (PDOException $e) {
        $unread_messages = 0;
        error_log("Unread messages query error: " . $e->getMessage());
    }
    
    // Get pending tickets count
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE assigned_to = ? AND status IN ('open', 'in_progress')
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pending_tickets = $result['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets = 0;
        error_log("Pending tickets query error: " . $e->getMessage());
    }
    
} catch (PDOException $e) {
    $budget_requests = [];
    $stats = ['total_requests' => 0, 'total_approved_amount' => 0, 'total_funded_amount' => 0, 'pending_review' => 0, 'approved_requests' => 0];
    error_log("Budget requests query error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Committee Action Funding - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #3B82F6;
            --secondary-blue: #60A5FA;
            --accent-blue: #1D4ED8;
            --light-blue: #EFF6FF;
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

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
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

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        /* Buttons */
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
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--light-blue);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--light-blue);
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
        .table-responsive {
            overflow-x: auto;
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

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: #e9ecef; color: #6c757d; }
        .status-submitted { background: #fff3cd; color: #856404; }
        .status-under_review { background: #cce7ff; color: #004085; }
        .status-approved_by_finance { background: #d4edda; color: #155724; }
        .status-approved_by_president { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-funded { background: #d1ecf1; color: #0c5460; }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
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
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        .required {
            color: var(--danger);
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

        /* Action Buttons */
        .action-buttons {
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

        .modal.show {
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

        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
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

        /* File Upload Area */
        .file-upload-area {
            border: 2px dashed var(--medium-gray);
            padding: 1.5rem;
            text-align: center;
            border-radius: var(--border-radius);
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload-area:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
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

        /* File Preview Modal */
        .file-preview-modal .modal-content {
            max-width: 90%;
            max-height: 90%;
        }

        .file-preview-container {
            width: 100%;
            height: 500px;
            border: none;
            background: var(--white);
        }

        .file-preview-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            justify-content: center;
        }

        /* Animations */
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

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 4rem;
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
                justify-content: space-between;
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .action-buttons {
                flex-direction: column;
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
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .modal-content {
                width: 95%;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
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
                <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Committee Action Funding</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Public Relations</div>
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
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php" class="active">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                        <?php if (isset($stats['pending_review']) && $stats['pending_review'] > 0): ?>
                            <span class="menu-badge"><?php echo $stats['pending_review']; ?></span>
                        <?php endif; ?>
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
            <!-- Page Header -->
            <div class="page-header">
               
                <div class="page-actions">
                    <button class="btn btn-outline" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-primary" onclick="openNewRequestModal()">
                        <i class="fas fa-plus"></i> New Funding Request
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_requests']); ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['pending_review']); ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['approved_requests']); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($stats['total_approved_amount']); ?></div>
                        <div class="stat-label">Total Approved</div>
                    </div>
                </div>
            </div>

            <!-- Budget Requests Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> My Funding Requests</h3>
                    <div class="card-header-actions">
                        <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($budget_requests)): ?>
                        <div class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <h3>No Funding Requests Yet</h3>
                            <p>Submit your first funding request to get started with your committee action plans.</p>
                            <button class="btn btn-primary" onclick="openNewRequestModal()" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First Request
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Request Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($budget_requests as $request): ?>
                                        <tr>
                                            <td>#<?php echo $request['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['request_title']); ?></strong>
                                                <?php if (!empty($request['purpose'])): ?>
                                                    <br><small class="form-text"><?php echo htmlspecialchars(substr($request['purpose'], 0, 50)); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>RWF <?php echo number_format($request['requested_amount']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                                    <?php echo str_replace('_', ' ', $request['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-outline" onclick="viewRequest(<?php echo $request['id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (!empty($request['action_plan_file_path'])): ?>
                                                        <button class="btn btn-sm btn-primary" onclick="previewFile('<?php echo htmlspecialchars($request['action_plan_file_path']); ?>')" title="Preview File">
                                                            <i class="fas fa-file"></i>
                                                        </button>
                                                        <a href="../<?php echo htmlspecialchars($request['action_plan_file_path']); ?>" class="btn btn-sm btn-success" title="Download File" download>
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($request['status'] === 'approved_by_president' && !empty($request['generated_letter_path'])): ?>
                                                        <a href="../<?php echo htmlspecialchars($request['generated_letter_path']); ?>" class="btn btn-sm btn-success" title="Download Approval Letter" download>
                                                            <i class="fas fa-file-pdf"></i>
                                                        </a>
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
            </div>
        </main>
    </div>

    <!-- New Request Modal -->
    <div class="modal" id="newRequestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-plus-circle"></i> New Funding Request</h3>
                <button class="modal-close" onclick="closeNewRequestModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="budgetRequestForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Request Title <span class="required">*</span></label>
                        <input type="text" class="form-control" name="request_title" placeholder="e.g., Media Campaign Budget" required>
                        <div class="form-text">A clear, descriptive title for your funding request</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Requested Amount (RWF) <span class="required">*</span></label>
                        <input type="number" class="form-control" name="requested_amount" placeholder="Enter amount in RWF" min="0" step="100" required>
                        <div class="form-text">Enter the total amount you are requesting</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purpose <span class="required">*</span></label>
                        <textarea class="form-control" name="purpose" rows="4" placeholder="Describe the purpose of this funding and how it will be used..." required></textarea>
                        <div class="form-text">Explain why you need this funding and how it will benefit the committee's activities</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Action Plan Document <span class="required">*</span></label>
                        <div class="file-upload-area" onclick="document.getElementById('action_plan_file').click()">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--primary-blue);"></i>
                            <p style="margin-top: 0.5rem;">Click to upload or drag and drop</p>
                            <input type="file" id="action_plan_file" name="action_plan_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required style="display: none;">
                            <div class="form-text" style="margin-top: 0.5rem;">Supported: PDF, DOC, DOCX, JPG, JPEG, PNG (Max 5MB)</div>
                        </div>
                        <div id="file_name_display" style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--success); display: none;">
                            <i class="fas fa-check-circle"></i> Selected: <span id="selected_file_name"></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeNewRequestModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="submit_request">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Request Modal -->
    <div class="modal" id="viewRequestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-file-invoice"></i> Request Details</h3>
                <button class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewRequestContent">
                <p>Loading...</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- File Preview Modal -->
    <div class="modal file-preview-modal" id="filePreviewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-file"></i> File Preview</h3>
                <button class="modal-close" onclick="closeFilePreview()">&times;</button>
            </div>
            <div class="modal-body">
                <iframe id="filePreviewFrame" class="file-preview-container" src=""></iframe>
                <div class="file-preview-actions">
                    <button class="btn btn-outline" onclick="closeFilePreview()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <a id="downloadFileLink" class="btn btn-primary" download>
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
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

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Modal Functions
        function openNewRequestModal() {
            document.getElementById('newRequestModal').classList.add('show');
        }

        function closeNewRequestModal() {
            document.getElementById('newRequestModal').classList.remove('show');
            document.getElementById('budgetRequestForm').reset();
            document.getElementById('file_name_display').style.display = 'none';
        }

        function viewRequest(requestId) {
            const modal = document.getElementById('viewRequestModal');
            const content = document.getElementById('viewRequestContent');
            modal.classList.add('show');
            content.innerHTML = '<p style="text-align: center;"><i class="fas fa-spinner fa-spin"></i> Loading request details...</p>';
            
            // Fetch request details via AJAX
            fetch(`get_budget_request.php?id=${requestId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        content.innerHTML = `
                            <div class="detail-section">
                                <h4>Request Information</h4>
                                <div class="detail-item"><strong>Title:</strong> ${escapeHtml(data.request.request_title)}</div>
                                <div class="detail-item"><strong>Amount:</strong> RWF ${Number(data.request.requested_amount).toLocaleString()}</div>
                                <div class="detail-item"><strong>Status:</strong> <span class="status-badge status-${data.request.status}">${data.request.status.replace(/_/g, ' ')}</span></div>
                                <div class="detail-item"><strong>Request Date:</strong> ${new Date(data.request.request_date).toLocaleDateString()}</div>
                            </div>
                            <div class="detail-section">
                                <h4>Purpose</h4>
                                <p>${escapeHtml(data.request.purpose)}</p>
                            </div>
                            ${data.request.finance_approval_notes ? `
                            <div class="detail-section">
                                <h4>Finance Review Notes</h4>
                                <p>${escapeHtml(data.request.finance_approval_notes)}</p>
                            </div>
                            ` : ''}
                            ${data.request.president_approval_notes ? `
                            <div class="detail-section">
                                <h4>President Review Notes</h4>
                                <p>${escapeHtml(data.request.president_approval_notes)}</p>
                            </div>
                            ` : ''}
                            ${data.request.rejection_reason ? `
                            <div class="detail-section">
                                <h4>Rejection Reason</h4>
                                <p style="color: var(--danger);">${escapeHtml(data.request.rejection_reason)}</p>
                            </div>
                            ` : ''}
                        `;
                    } else {
                        content.innerHTML = `<p class="alert alert-danger">${escapeHtml(data.message)}</p>`;
                    }
                })
                .catch(error => {
                    content.innerHTML = `<p class="alert alert-danger">Error loading request details. Please try again.</p>`;
                });
        }

        function closeViewModal() {
            document.getElementById('viewRequestModal').classList.remove('show');
        }

        // File preview function
        function previewFile(filePath) {
            const fileExtension = filePath.split('.').pop().toLowerCase();
            const previewFrame = document.getElementById('filePreviewFrame');
            const downloadLink = document.getElementById('downloadFileLink');
            
            downloadLink.href = '../' + filePath;
            downloadLink.download = filePath.split('/').pop();
            
            if (fileExtension === 'pdf') {
                previewFrame.src = '../' + filePath;
            } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) {
                previewFrame.src = '../' + filePath;
            } else if (['doc', 'docx'].includes(fileExtension)) {
                previewFrame.src = 'https://docs.google.com/gview?url=' + encodeURIComponent(window.location.origin + '/isonga-mis/' + filePath) + '&embedded=true';
            } else {
                previewFrame.srcdoc = `
                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column; gap: 1rem; font-family: Arial, sans-serif;">
                        <i class="fas fa-file" style="font-size: 4rem; color: #6c757d;"></i>
                        <h3>File Preview Not Available</h3>
                        <p>Please download the file to view it.</p>
                    </div>
                `;
            }
            
            document.getElementById('filePreviewModal').classList.add('show');
        }

        function closeFilePreview() {
            document.getElementById('filePreviewModal').classList.remove('show');
            document.getElementById('filePreviewFrame').src = 'about:blank';
        }

        // File input display
        const fileInput = document.getElementById('action_plan_file');
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const fileName = e.target.files[0]?.name;
                if (fileName) {
                    document.getElementById('selected_file_name').textContent = fileName;
                    document.getElementById('file_name_display').style.display = 'block';
                } else {
                    document.getElementById('file_name_display').style.display = 'none';
                }
            });
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            const newModal = document.getElementById('newRequestModal');
            if (e.target === newModal) {
                closeNewRequestModal();
            }
            
            const viewModal = document.getElementById('viewRequestModal');
            if (e.target === viewModal) {
                closeViewModal();
            }
            
            const previewModal = document.getElementById('filePreviewModal');
            if (e.target === previewModal) {
                closeFilePreview();
            }
        });

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-hide success messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });
    </script>
</body>
</html>