<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get case ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid case ID";
    header('Location: cases.php');
    exit();
}

$case_id = $_GET['id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_note'])) {
        $content = $_POST['content'];
        $note_type = $_POST['note_type'];
        $is_confidential = isset($_POST['is_confidential']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO case_notes (case_id, user_id, note_type, content, is_confidential)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$case_id, $user_id, $note_type, $content, $is_confidential]);
            
            $_SESSION['success_message'] = "Note added successfully!";
            header("Location: case_details.php?id=$case_id");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding note: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_case_status'])) {
        $status = $_POST['status'];
        $resolution_details = $_POST['resolution_details'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE arbitration_cases 
                SET status = ?, resolution_details = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $resolution_details, $case_id]);
            
            $_SESSION['success_message'] = "Case status updated successfully!";
            header("Location: case_details.php?id=$case_id");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating case: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['assign_case'])) {
        $assigned_to = $_POST['assigned_to'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE arbitration_cases 
                SET assigned_to = ?, assigned_by = ?, assigned_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$assigned_to, $user_id, $case_id]);
            
            $_SESSION['success_message'] = "Case assigned successfully!";
            header("Location: case_details.php?id=$case_id");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error assigning case: " . $e->getMessage();
        }
    }
}

// Get case details
try {
    $stmt = $pdo->prepare("
        SELECT ac.*, 
               u1.full_name as assigned_name,
               u2.full_name as creator_name,
               u3.full_name as assigned_by_name
        FROM arbitration_cases ac
        LEFT JOIN users u1 ON ac.assigned_to = u1.id
        LEFT JOIN users u2 ON ac.created_by = u2.id
        LEFT JOIN users u3 ON ac.assigned_by = u3.id
        WHERE ac.id = ?
    ");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
        $_SESSION['error_message'] = "Case not found";
        header('Location: cases.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching case details: " . $e->getMessage();
    header('Location: cases.php');
    exit();
}

// Get case notes
try {
    $stmt = $pdo->prepare("
        SELECT cn.*, u.full_name, u.role
        FROM case_notes cn
        JOIN users u ON cn.user_id = u.id
        WHERE cn.case_id = ?
        ORDER BY cn.created_at DESC
    ");
    $stmt->execute([$case_id]);
    $case_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $case_notes = [];
}

// Get case documents
try {
    $stmt = $pdo->prepare("
        SELECT cd.*, u.full_name as uploaded_by_name
        FROM case_documents cd
        JOIN users u ON cd.uploaded_by = u.id
        WHERE cd.case_id = ?
        ORDER BY cd.created_at DESC
    ");
    $stmt->execute([$case_id]);
    $case_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $case_documents = [];
}

// Get arbitration committee members for assignment
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role
        FROM users u 
        WHERE u.role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        AND u.status = 'active'
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
}

// Get related hearings
try {
    $stmt = $pdo->prepare("
        SELECT * FROM arbitration_hearings 
        WHERE case_id = ? 
        ORDER BY hearing_date DESC
    ");
    $stmt->execute([$case_id]);
    $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hearings = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case Details - <?php echo htmlspecialchars($case['case_number']); ?> - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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

        /* Header and sidebar styles remain the same as cases.php */
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

        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

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

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
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

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
        }

        /* Case Overview */
        .case-overview {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .case-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .case-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .case-number {
            font-size: 1rem;
            color: var(--dark-gray);
            font-weight: 600;
        }

        .case-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-filed {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-under_review {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-hearing_scheduled {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-mediation {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-dismissed {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-high {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .priority-low {
            background: #d4edda;
            color: var(--success);
        }

        /* Case Details Grid */
        .case-details-grid {
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

        .card-body {
            padding: 1.25rem;
        }

        /* Details Sections */
        .detail-section {
            margin-bottom: 1.5rem;
        }

        .detail-section:last-child {
            margin-bottom: 0;
        }

        .detail-section h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .detail-grid {
            display: grid;
            gap: 0.75rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .detail-value {
            text-align: right;
            font-size: 0.8rem;
        }

        .description-box {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            line-height: 1.6;
        }

        /* Notes Section */
        .notes-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .note-item {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .note-item:last-child {
            border-bottom: none;
        }

        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .note-author {
            font-weight: 600;
            font-size: 0.8rem;
        }

        .note-meta {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .note-content {
            font-size: 0.8rem;
            line-height: 1.5;
        }

        .note-type {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            margin: 0;
        }

        /* Alert */
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

        /* Responsive */
        @media (max-width: 1024px) {
            .case-details-grid {
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
            
            .case-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .case-status {
                align-items: flex-start;
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
                    <h1>Isonga - Arbitration</h1>
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
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration President</div>
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
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>Arbitration Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case_details.php?id=<?php echo $case_id; ?>" class="active">
                        <i class="fas fa-eye"></i>
                        <span>Case Details</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="election_committee.php">
                        <i class="fas fa-users-cog"></i>
                        <span>Election Committee</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Tickets</span>
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
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
                <div>
                    <h1 class="page-title">Case Details</h1>
                    <div class="case-number"><?php echo htmlspecialchars($case['case_number']); ?></div>
                </div>
                <div>
                    <a href="cases.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Cases
                    </a>
                </div>
            </div>

            <!-- Success/Error Messages -->
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

            <!-- Case Overview -->
            <div class="case-overview">
                <div class="case-header">
                    <div>
                        <h2 class="case-title"><?php echo htmlspecialchars($case['title']); ?></h2>
                        <div class="description-box">
                            <?php echo nl2br(htmlspecialchars($case['description'])); ?>
                        </div>
                    </div>
                    <div class="case-status">
                        <span class="status-badge status-<?php echo $case['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                        </span>
                        <span class="priority-badge priority-<?php echo $case['priority']; ?>">
                            <?php echo ucfirst($case['priority']); ?> Priority
                        </span>
                    </div>
                </div>
            </div>

            <!-- Case Details Grid -->
            <div class="case-details-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Case Notes -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Case Notes & Updates</h3>
                        </div>
                        <div class="card-body">
                            <!-- Add Note Form -->
                            <form method="POST" class="detail-section">
                                <div class="form-group">
                                    <label for="content">Add Note</label>
                                    <textarea class="form-control" id="content" name="content" required placeholder="Add your notes about this case..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="note_type">Note Type</label>
                                    <select class="form-control" id="note_type" name="note_type" required>
                                        <option value="general">General Note</option>
                                        <option value="hearing">Hearing Update</option>
                                        <option value="evidence">Evidence Review</option>
                                        <option value="decision">Decision Note</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="is_confidential" name="is_confidential" value="1">
                                        <label for="is_confidential">Confidential Note (Visible only to arbitration committee)</label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" name="add_note">
                                    <i class="fas fa-plus"></i> Add Note
                                </button>
                            </form>

                            <!-- Notes List -->
                            <div class="notes-list">
                                <?php if (empty($case_notes)): ?>
                                    <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                        <i class="fas fa-sticky-note" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                        <p>No notes yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($case_notes as $note): ?>
                                        <div class="note-item">
                                            <div class="note-header">
                                                <div class="note-author">
                                                    <?php echo htmlspecialchars($note['full_name']); ?>
                                                    <span class="note-type"><?php echo ucfirst($note['note_type']); ?></span>
                                                    <?php if ($note['is_confidential']): ?>
                                                        <span style="color: var(--danger); font-size: 0.7rem;">
                                                            <i class="fas fa-lock"></i> Confidential
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="note-meta">
                                                    <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div class="note-content">
                                                <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Case Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Case Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="detail-section">
                                <h4>Case Details</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Case Number:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($case['case_number']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Type:</span>
                                        <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Filed Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($case['filing_date'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Created By:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($case['creator_name']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="detail-section">
                                <h4>Parties Involved</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Complainant:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($case['complainant_name']); ?></span>
                                    </div>
                                    <?php if ($case['complainant_contact']): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Contact:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($case['complainant_contact']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Respondent:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($case['respondent_name']); ?></span>
                                    </div>
                                    <?php if ($case['respondent_contact']): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Contact:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($case['respondent_contact']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Assignment Section -->
                            <div class="detail-section">
                                <h4>Case Assignment</h4>
                                <form method="POST">
                                    <div class="form-group">
                                        <label for="assigned_to">Assign To Committee Member</label>
                                        <select class="form-control" id="assigned_to" name="assigned_to">
                                            <option value="">Not Assigned</option>
                                            <?php foreach ($committee_members as $member): ?>
                                                <option value="<?php echo $member['id']; ?>" 
                                                    <?php echo $case['assigned_to'] == $member['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($member['full_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary" name="assign_case">
                                        <i class="fas fa-user-check"></i> Assign Case
                                    </button>
                                </form>
                                
                                <?php if ($case['assigned_name']): ?>
                                <div style="margin-top: 1rem; padding: 0.75rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <div style="font-size: 0.8rem;">
                                        <strong>Currently Assigned To:</strong> <?php echo htmlspecialchars($case['assigned_name']); ?>
                                    </div>
                                    <?php if ($case['assigned_by_name']): ?>
                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                        Assigned by <?php echo htmlspecialchars($case['assigned_by_name']); ?> 
                                        on <?php echo date('M j, Y', strtotime($case['assigned_at'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Status Update Section -->
                            <div class="detail-section">
                                <h4>Update Case Status</h4>
                                <form method="POST">
                                    <div class="form-group">
                                        <label for="status">Case Status</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="filed" <?php echo $case['status'] === 'filed' ? 'selected' : ''; ?>>Filed</option>
                                            <option value="under_review" <?php echo $case['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                            <option value="hearing_scheduled" <?php echo $case['status'] === 'hearing_scheduled' ? 'selected' : ''; ?>>Hearing Scheduled</option>
                                            <option value="mediation" <?php echo $case['status'] === 'mediation' ? 'selected' : ''; ?>>Mediation</option>
                                            <option value="resolved" <?php echo $case['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="dismissed" <?php echo $case['status'] === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="resolution_details">Resolution Details</label>
                                        <textarea class="form-control" id="resolution_details" name="resolution_details" 
                                                  placeholder="Enter resolution details if case is resolved..."><?php echo htmlspecialchars($case['resolution_details'] ?? ''); ?></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary" name="update_case_status">
                                        <i class="fas fa-save"></i> Update Status
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Related Hearings -->
                    <?php if (!empty($hearings)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>Related Hearings</h3>
                        </div>
                        <div class="card-body">
                            <?php foreach ($hearings as $hearing): ?>
                                <div style="padding: 0.75rem; border-bottom: 1px solid var(--medium-gray);">
                                    <div style="font-weight: 600; font-size: 0.8rem;">
                                        <?php echo date('M j, Y g:i A', strtotime($hearing['hearing_date'])); ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                        <?php echo htmlspecialchars($hearing['location']); ?>
                                    </div>
                                    <?php if ($hearing['purpose']): ?>
                                    <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                        <?php echo htmlspecialchars($hearing['purpose']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
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

        // Auto-expand textareas
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });
    </script>
</body>
</html>