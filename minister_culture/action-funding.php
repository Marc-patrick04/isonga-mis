<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
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
        $error = '';
        
        // Get the committee ID for Minister of Culture
        try {
            $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'minister_culture'");
            $stmt->execute([$user_id]);
            $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$committee_member) {
                $error = "You are not assigned as Minister of Culture in any committee.";
            } else {
                // For Minister of Culture, we use their committee_member_id as committee_id
                $committee_id = $committee_member['id'];
            }
        } catch (PDOException $e) {
            $error = "Error fetching committee information: " . $e->getMessage();
        }
        
        // SIMPLE FILE UPLOAD - FIXED VERSION
        $action_plan_file_path = '';
        
        if (isset($_FILES['action_plan_file']) && $_FILES['action_plan_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['action_plan_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/culture_action_plans/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['action_plan_file']['name'], PATHINFO_EXTENSION);
                $filename = 'culture_action_plan_' . time() . '_' . $user_id . '.' . $file_extension;
                $destination = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['action_plan_file']['tmp_name'], $destination)) {
                    $action_plan_file_path = 'assets/uploads/culture_action_plans/' . $filename;
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
                    VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'submitted', NOW(), NOW())
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

// Get budget requests data for the Minister of Culture
try {
    // Get the committee member ID for Minister of Culture
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'minister_culture'");
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
        $error = "You are not assigned as Minister of Culture in any committee.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action & Funding - Minister of Culture & Civic Education - Isonga RPSU</title>
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
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
            border: 1px solid var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-outline:hover {
            background: var(--light-purple);
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
            border-left: 3px solid var(--primary-purple);
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
            background: var(--light-purple);
            color: var(--primary-purple);
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
        .status-under_review { background: #cce7ff; color: var(--primary-purple); }
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
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
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

        /* Responsive */
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .page-actions {
                width: 100%;
                justify-content: space-between;
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
                    <h1>Isonga - Minister of Culture & Civic Education</h1>
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
                        <div class="user-role">Minister of Culture & Civic Education</div>
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
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php" class="active">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                        <?php if (isset($stats['pending_review']) && $stats['pending_review'] > 0): ?>
                            <span class="menu-badge"><?php echo $stats['pending_review']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php">
                        <i class="fas fa-palette"></i>
                        <span>Cultural Resources</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="troupe.php">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
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
                    <h1>Action & Funding 💰</h1>
                    <p>Request budget for cultural action plans and track funding status</p>
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
                            <p>Submit your first funding request to get started with your cultural action plans.</p>
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
            <form method="POST" enctype="multipart/form-data" id="budgetRequestForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Request Title *</label>
                        <input type="text" class="form-control" name="request_title" placeholder="e.g., Cultural Festival Budget" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Requested Amount (RWF) *</label>
                        <input type="number" class="form-control" name="requested_amount" placeholder="Enter amount in RWF" min="0" step="0.01" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purpose *</label>
                        <textarea class="form-control" name="purpose" rows="4" placeholder="Describe the purpose of this funding and how it will be used for cultural activities..." required></textarea>
                    </div>

                    <!-- SIMPLE FILE UPLOAD - EXACTLY LIKE VICE GUILD ACADEMIC VERSION -->
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

        // NO COMPLEX FILE UPLOAD LOGIC - Simple file input works fine
    </script>
</body>
</html>