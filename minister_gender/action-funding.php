<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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

// Get unread messages count for badge
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_request'])) {
        // Handle new budget request submission
        $request_title = $_POST['request_title'] ?? '';
        $requested_amount = $_POST['requested_amount'] ?? '';
        $purpose = $_POST['purpose'] ?? '';
        $error = '';
        
        // Get the committee ID for Minister of Gender
        try {
            $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'minister_gender'");
            $stmt->execute([$user_id]);
            $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$committee_member) {
                $error = "You are not assigned as Minister of Gender in any committee.";
            } else {
                // For Minister of Gender, we use their committee_member_id as committee_id
                $committee_id = $committee_member['id'];
            }
        } catch (PDOException $e) {
            $error = "Error fetching committee information: " . $e->getMessage();
        }
        
        // File upload handling
        $action_plan_file_path = '';
        
        if (isset($_FILES['action_plan_file']) && $_FILES['action_plan_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['action_plan_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/action_plans/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['action_plan_file']['name'], PATHINFO_EXTENSION);
                $filename = 'action_plan_' . time() . '_' . $user_id . '.' . $file_extension;
                $destination = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['action_plan_file']['tmp_name'], $destination)) {
                    $action_plan_file_path = 'assets/uploads/action_plans/' . $filename;
                } else {
                    $error = "Failed to save uploaded file. Please try again.";
                }
            } else {
                $error = "File upload error. Please try again.";
            }
        } else {
            $error = "Please select an action plan file to upload.";
        }
        
        if (empty($error)) {
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
                    // Refresh the page to show the new request
                    header("Location: action-funding.php");
                    exit();
                } else {
                    $error = "Failed to submit budget request.";
                }
                
            } catch (PDOException $e) {
                $error = "Failed to submit budget request: " . $e->getMessage();
                error_log("Budget request submission error: " . $e->getMessage());
            }
        }
    }
}

// Get budget requests data for the Minister of Gender
try {
    // Get the committee member ID for Minister of Gender
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'minister_gender'");
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
        
        // Ensure stats are not null
        if (!$stats) {
            $stats = ['total_requests' => 0, 'total_approved_amount' => 0, 'total_funded_amount' => 0, 'pending_review' => 0, 'approved_requests' => 0];
        }
    } else {
        $budget_requests = [];
        $stats = ['total_requests' => 0, 'total_approved_amount' => 0, 'total_funded_amount' => 0, 'pending_review' => 0, 'approved_requests' => 0];
        $error = "You are not assigned as Minister of Gender in any committee.";
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
    <title>Action Funding - Minister of Gender & Protocol - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #a78bfa;
            --accent-purple: #7c3aed;
            --light-purple: #f3f4f6;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            --primary-purple: #a78bfa;
            --secondary-purple: #c4b5fd;
            --accent-purple: #8b5cf6;
            --light-purple: #1f2937;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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
            border: 1px solid var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-outline:hover {
            background: var(--primary-purple);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
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
            border-left: 4px solid var(--primary-purple);
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
            background: var(--light-purple);
            color: var(--primary-purple);
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
            background: var(--light-purple);
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
        .table-container {
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
            background: var(--light-purple);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: #e9ecef; color: #6c757d; }
        .status-submitted { background: #fff3cd; color: #856404; }
        .status-under_review { background: #cce7ff; color: var(--primary-purple); }
        .status-approved_by_finance { background: #d4edda; color: #155724; }
        .status-approved_by_president { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-funded { background: #d1ecf1; color: #0c5460; }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

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
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
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

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
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
            background: var(--light-purple);
        }

        .modal-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-close:hover {
            color: var(--text-dark);
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
            padding: 20px;
            text-align: center;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .file-upload-area:hover {
            border-color: var(--primary-purple);
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
                background: var(--primary-purple);
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
                margin: 10% auto;
                width: 95%;
            }

            .action-buttons {
                flex-direction: column;
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
                    <h1>Isonga - Action Funding</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
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
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Gender & Protocol</div>
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
                        <span>Gender Issues</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="protocol.php">
                        <i class="fas fa-handshake"></i>
                        <span>Protocol & Visitors</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Gender Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostel-management.php">
                        <i class="fas fa-building"></i>
                        <span>Hostel Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php" class="active">
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
                <div class="page-title">
                    <h1>Action Funding 💰</h1>
                    <p>Request budget for gender-related action plans and track funding status</p>
                </div>
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
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <?php if (isset($stats)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_requests'] ?? 0); ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['pending_review'] ?? 0); ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['approved_requests'] ?? 0); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($stats['total_approved_amount'] ?? 0); ?></div>
                        <div class="stat-label">Total Approved</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Budget Requests Table -->
            <div class="card">
                <div class="card-header">
                    <h3>My Funding Requests</h3>
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
                            <p>Submit your first funding request to get started with your gender action plans.</p>
                            <button class="btn btn-primary" onclick="openNewRequestModal()" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First Request
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
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
                                            <td><?php echo htmlspecialchars($request['request_title']); ?></td>
                                            <td>RWF <?php echo number_format($request['requested_amount']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if (!empty($request['action_plan_file_path'])): ?>
                                                        <button class="btn btn-outline btn-sm" onclick="previewFile('<?php echo $request['action_plan_file_path']; ?>')" title="Preview File">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="../<?php echo $request['action_plan_file_path']; ?>" class="btn btn-primary btn-sm" title="Download File" download>
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($request['status'] === 'approved_by_president' && !empty($request['generated_letter_path'])): ?>
                                                        <a href="../<?php echo $request['generated_letter_path']; ?>" class="btn btn-success btn-sm" title="Download Approval Letter" download>
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
                <h3 class="modal-title">New Funding Request</h3>
                <button class="modal-close" onclick="closeNewRequestModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="budgetRequestForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Request Title *</label>
                        <input type="text" class="form-control" name="request_title" placeholder="e.g., Gender Equality Workshop Budget" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Requested Amount (RWF) *</label>
                        <input type="number" class="form-control" name="requested_amount" placeholder="Enter amount in RWF" min="0" step="1" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purpose *</label>
                        <textarea class="form-control" name="purpose" rows="4" placeholder="Describe the purpose of this funding and how it will be used for gender-related activities..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Action Plan Document *</label>
                        <div class="file-upload-area">
                            <input type="file" name="action_plan_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required style="display: block; margin: 0 auto;">
                            <div class="form-text" style="margin-top: 10px;">Upload your action plan (PDF, DOC, DOCX, JPG, PNG)</div>
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

    <!-- File Preview Modal -->
    <div class="modal file-preview-modal" id="filePreviewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">File Preview</h3>
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
            document.body.style.overflow = 'hidden';
        }

        function closeNewRequestModal() {
            document.getElementById('newRequestModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        function viewRequest(requestId) {
            window.location.href = 'view_budget_request.php?id=' + requestId;
        }

        function editRequest(requestId) {
            window.location.href = 'edit_budget_request.php?id=' + requestId;
        }

        // File preview function
        function previewFile(filePath) {
            const fileExtension = filePath.split('.').pop().toLowerCase();
            const previewFrame = document.getElementById('filePreviewFrame');
            const downloadLink = document.getElementById('downloadFileLink');
            
            // Set download link
            downloadLink.href = '../' + filePath;
            downloadLink.download = filePath.split('/').pop();
            
            // Handle different file types
            if (fileExtension === 'pdf') {
                previewFrame.src = '../' + filePath;
            } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) {
                previewFrame.src = '../' + filePath;
            } else if (['doc', 'docx'].includes(fileExtension)) {
                previewFrame.src = 'https://docs.google.com/gview?url=' + encodeURIComponent(window.location.origin + '/isonga-mis/' + filePath) + '&embedded=true';
            } else {
                previewFrame.srcdoc = `
                    <html>
                        <body style="display: flex; align-items: center; justify-content: center; height: 100%; font-family: sans-serif; background: var(--white);">
                            <div style="text-align: center;">
                                <i class="fas fa-file" style="font-size: 4rem; color: var(--dark-gray);"></i>
                                <h3>File Preview Not Available</h3>
                                <p>Please download the file to view it.</p>
                            </div>
                        </body>
                    </html>
                `;
            }
            
            document.getElementById('filePreviewModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeFilePreview() {
            document.getElementById('filePreviewModal').classList.remove('show');
            document.getElementById('filePreviewFrame').src = 'about:blank';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('newRequestModal');
            if (e.target === modal) {
                closeNewRequestModal();
            }
            
            const previewModal = document.getElementById('filePreviewModal');
            if (e.target === previewModal) {
                closeFilePreview();
            }
        });

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
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