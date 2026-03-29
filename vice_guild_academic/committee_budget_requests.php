<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_request'])) {
        // Handle new budget request submission
        $request_title = $_POST['request_title'] ?? '';
        $requested_amount = $_POST['requested_amount'] ?? '';
        $purpose = $_POST['purpose'] ?? '';
        
        // Get the committee ID for Vice Guild Academic - FIXED
        try {
            $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'vice_guild_academic'");
            $stmt->execute([$user_id]);
            $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$committee_member) {
                $error = "You are not assigned as Vice Guild Academic in any committee.";
            } else {
                // For Vice Guild Academic, we use their committee_member_id as committee_id
                $committee_id = $committee_member['id'];
            }
        } catch (PDOException $e) {
            $error = "Error fetching committee information: " . $e->getMessage();
        }
        
// File upload handling - FIXED VERSION
$action_plan_file_path = '';
$error = '';

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
                    header("Location: committee_budget_requests.php");
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

// Get budget requests data for the Vice Guild Academic - FIXED QUERY
try {
    // Get the committee member ID for Vice Guild Academic
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'vice_guild_academic'");
    $stmt->execute([$user_id]);
    $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($committee_member) {
        $committee_id = $committee_member['id'];
        
        // Get budget requests for this committee - FIXED QUERY
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
        
        // Statistics - FIXED QUERY
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status IN ('approved_by_finance', 'approved_by_president', 'funded') THEN requested_amount ELSE 0 END) as total_approved_amount,
                SUM(CASE WHEN status = 'funded' THEN requested_amount ELSE 0 END) as total_funded_amount,
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
        $error = "You are not assigned as Vice Guild Academic in any committee.";
    }
    
} catch (PDOException $e) {
    $budget_requests = [];
    $stats = ['total_requests' => 0, 'total_approved_amount' => 0, 'total_funded_amount' => 0, 'pending_review' => 0, 'approved_requests' => 0];
    error_log("Budget requests query error: " . $e->getMessage());
}

// Debug: Check what data we have
error_log("User ID: " . $user_id);
error_log("Budget requests count: " . count($budget_requests));
error_log("Stats: " . print_r($stats, true));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Action Funding - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
                <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Your existing CSS styles remain the same */
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
            --academic-primary: #4CAF50;
            --academic-secondary: #66BB6A;
            --academic-accent: #2E7D32;
            --academic-light: #1B3E1B;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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

        /* Header - Same as dashboard */
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
            color: var(--academic-primary);
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
            border-color: var(--academic-primary);
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
            background: var(--academic-primary);
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

        /* Sidebar - Same as dashboard */
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
            background: var(--academic-light);
            border-left-color: var(--academic-primary);
            color: var(--academic-primary);
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            border: 1px solid var(--academic-primary);
            color: var(--academic-primary);
        }

        .btn-outline:hover {
            background: var(--academic-light);
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
            border-left: 3px solid var(--academic-primary);
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
            background: var(--academic-light);
            color: var(--academic-primary);
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

        /* Card */
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: #e9ecef; color: #6c757d; }
        .status-submitted { background: #fff3cd; color: var(--warning); }
        .status-under_review { background: #cce7ff; color: var(--primary-blue); }
        .status-approved_by_finance { background: #d4edda; color: var(--success); }
        .status-approved_by_president { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }
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
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--academic-primary);
            background: var(--academic-light);
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-label {
            cursor: pointer;
            display: block;
        }

        .file-upload-icon {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
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

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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

        /* File Preview Modal */
        .file-preview-modal .modal-content {
            max-width: 90%;
            max-height: 90%;
        }

        .file-preview-container {
            width: 100%;
            height: 600px;
            border: none;
            background: var(--white);
        }

        .file-preview-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            justify-content: center;
        }

        /* =============================================
           RESPONSIVE — mobile-first breakpoints
        ============================================= */

        /* Hamburger button (hidden on desktop) */
        .hamburger {
            display: none;
            flex-direction: column;
            justify-content: center;
            gap: 5px;
            width: 44px;
            height: 44px;
            background: var(--light-gray);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            padding: 10px;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .hamburger span {
            display: block;
            height: 2px;
            background: var(--text-dark);
            border-radius: 2px;
            transition: var(--transition);
        }
        .hamburger:hover { background: var(--academic-primary); }
        .hamburger:hover span { background: #fff; }

        /* Sidebar overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
        }
        .sidebar-overlay.active { display: block; }

        /* Table wrapper */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* ── 1280 px ── */
        @media (max-width: 1280px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* ── 1024 px ── */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            .brand-text h1 { font-size: 1.05rem; }
        }

        /* ── 768 px ── tablet */
        @media (max-width: 768px) {
            .hamburger { display: flex; }

            .dashboard-container { grid-template-columns: 1fr; }

            /* Sidebar becomes slide-in drawer */
            .sidebar {
                position: fixed;
                top: 80px;
                left: 0;
                width: 260px;
                height: calc(100vh - 80px);
                z-index: 200;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                box-shadow: var(--shadow-lg);
            }
            .sidebar.open { transform: translateX(0); }

            .main-content {
                height: auto;
                overflow-y: visible;
            }

            .stats-grid { grid-template-columns: repeat(2, 1fr); }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .page-actions {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            .page-actions .btn { flex: 1; justify-content: center; }

            .nav-container { padding: 0 1rem; }
            .user-details { display: none; }

            /* Modal full-width on tablet */
            .modal-content {
                width: 95%;
                max-width: 95%;
                margin: 1rem;
            }

            .table th, .table td {
                padding: 0.6rem 0.5rem;
                font-size: 0.75rem;
            }

            .header { height: 70px; }
            .dashboard-container { min-height: calc(100vh - 70px); }
            .sidebar { top: 70px; height: calc(100vh - 70px); }
        }

        /* ── 480 px ── large phone */
        @media (max-width: 480px) {
            .header { height: 64px; }
            .dashboard-container { min-height: calc(100vh - 64px); }
            .sidebar { top: 64px; height: calc(100vh - 64px); }

            .stats-grid { grid-template-columns: 1fr 1fr; }

            .main-content { padding: 0.875rem; }

            .brand-text h1 { font-size: 0.9rem; }

            .page-title h1 { font-size: 1.1rem; }

            .page-actions { flex-direction: column; }
            .page-actions .btn { width: 100%; justify-content: center; }

            .stat-card { padding: 0.75rem; gap: 0.75rem; }
            .stat-number { font-size: 1.15rem; }

            /* Action buttons in table — stack icons */
            .action-buttons { flex-wrap: wrap; gap: 0.35rem; }

            /* File preview iframe shorter on phones */
            .file-preview-container { height: 320px; }

            /* Modal */
            .modal-body { padding: 1rem; }
            .modal-footer { flex-direction: column; gap: 0.5rem; }
            .modal-footer .btn { width: 100%; justify-content: center; }
        }

        /* ── 360 px ── small phone */
        @media (max-width: 360px) {
            .stats-grid { grid-template-columns: 1fr; }
            .brand-text h1 { display: none; }
        }
    </style>
</head>
<body>
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                    <span></span><span></span><span></span>
                </button>
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Committee Action Funding</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
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
                        <div class="user-role">Vice Guild Academic</div>
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
                    <a href="academic_meetings.php">
                        <i class="fas fa-calendar-check"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_tickets.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academic Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Academic Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
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
                    <a href="performance_tracking.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Performance Tracking</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="innovation_projects.php">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_calendar.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Committee Action Funding 💰</h1>
                    <p>Request budget for your committee action plans and track funding status</p>
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
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
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
            <div class="stat-number"><?php echo isset($stats['total_requests']) ? $stats['total_requests'] : 0; ?></div>
            <div class="stat-label">Total Requests</div>
        </div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number"><?php echo isset($stats['pending_review']) ? $stats['pending_review'] : 0; ?></div>
            <div class="stat-label">Pending Review</div>
        </div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number"><?php echo isset($stats['approved_requests']) ? $stats['approved_requests'] : 0; ?></div>
            <div class="stat-label">Approved</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number">RWF <?php echo number_format(isset($stats['total_approved_amount']) ? $stats['total_approved_amount'] : 0); ?></div>
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
                        <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                            <i class="fas fa-money-bill-wave" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
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
                                                    <?php echo str_replace('_', ' ', $request['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-outline" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (!empty($request['action_plan_file_path'])): ?>
                                                        <button class="btn btn-sm btn-primary" onclick="previewFile('<?php echo $request['action_plan_file_path']; ?>')" title="Preview File">
                                                            <i class="fas fa-file"></i>
                                                        </button>
                                                        <a href="../<?php echo $request['action_plan_file_path']; ?>" class="btn btn-sm btn-success" title="Download File" download>
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($request['status'] === 'approved_by_president' && !empty($request['generated_letter_path'])): ?>
                                                        <a href="../<?php echo $request['generated_letter_path']; ?>" class="btn btn-sm btn-success" title="Download Approval Letter" download>
                                                            <i class="fas fa-file-pdf"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($request['status'] === 'draft'): ?>
                                                        <button class="btn btn-sm btn-primary" onclick="editRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
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
<form method="POST" enctype="multipart/form-data" id="budgetRequestForm" onsubmit="console.log('Form submitted', document.querySelector('input[name=\"action_plan_file\"]').files)">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Request Title *</label>
                        <input type="text" class="form-control" name="request_title" placeholder="e.g., Workshop Materials Budget" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Requested Amount (RWF) *</label>
                        <input type="number" class="form-control" name="requested_amount" placeholder="Enter amount in RWF" min="0" step="0.01" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purpose *</label>
                        <textarea class="form-control" name="purpose" rows="4" placeholder="Describe the purpose of this funding and how it will be used..." required></textarea>
                    </div>

<div class="form-group">
    <label class="form-label">Action Plan Document *</label>
    <div style="border: 2px dashed #ccc; padding: 20px; text-align: center; border-radius: 8px;">
        <input type="file" name="action_plan_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required 
               style="display: block; margin: 0 auto;">
        <div class="form-text" style="margin-top: 10px;">Click choose file to upload your action plan</div>
    </div>
</div>
                </div>
                <div class="modal-footer">
                    <!-- <button type="button" class="btn btn-outline" onclick="closeNewRequestModal()">Cancel</button> -->
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
                <!-- <button class="modal-close" onclick="closeFilePreview()">&times;</button> -->
            </div>
            <div class="modal-body">
                <iframe id="filePreviewFrame" class="file-preview-container" src=""></iframe>
                <div class="file-preview-actions">
                    <!-- <button class="btn btn-outline" onclick="closeFilePreview()"> -->
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
    // ── Dark Mode Toggle ──────────────────────────────────────
    const themeToggle = document.getElementById('themeToggle');
    const body = document.body;

    const savedTheme = localStorage.getItem('theme') ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
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

    // ── Hamburger / Sidebar Toggle (mobile) ───────────────────
    const hamburgerBtn   = document.getElementById('hamburgerBtn');
    const sidebar        = document.querySelector('.sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('open');
        sidebarOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (hamburgerBtn) hamburgerBtn.addEventListener('click', () =>
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar()
    );

    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    document.querySelectorAll('.sidebar .menu-item a').forEach(link =>
        link.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); })
    );

    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });

    // Modal Functions
    function openNewRequestModal() {
        document.getElementById('newRequestModal').classList.add('show');
    }

    function closeNewRequestModal() {
        document.getElementById('newRequestModal').classList.remove('show');
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
        } else if (['jpg', 'jpeg', 'png'].includes(fileExtension)) {
            previewFrame.src = '../' + filePath;
        } else if (['doc', 'docx'].includes(fileExtension)) {
            previewFrame.src = 'https://docs.google.com/gview?url=' + encodeURIComponent(window.location.origin + '/isonga-mis/' + filePath) + '&embedded=true';
        } else {
            previewFrame.src = 'about:blank';
            previewFrame.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column; gap: 1rem;">
                    <i class="fas fa-file" style="font-size: 4rem; color: var(--dark-gray);"></i>
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

    // SIMPLE FILE UPLOAD - NO RESET FUNCTIONALITY
    document.addEventListener('DOMContentLoaded', function() {
        const fileInput = document.querySelector('input[name="action_plan_file"]');
        const fileUploadArea = document.getElementById('fileUploadArea');
        
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    const file = this.files[0];
                    const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                    
                    fileUploadArea.innerHTML = `
                        <div style="text-align: center;">
                            <i class="fas fa-file-check" style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                            <div><strong>${file.name}</strong></div>
                            <div class="form-text">Size: ${fileSizeMB} MB - File selected successfully</div>
                        </div>
                    `;
                }
            });
        }
    });

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

    // NO FORM VALIDATION - Let server handle it
</script>
</body>
</html>