<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get case ID from URL
$case_id = $_GET['id'] ?? null;
if (!$case_id) {
    header('Location: cases.php');
    exit();
}

// Get case details
try {
    $stmt = $pdo->prepare("
        SELECT 
            ac.*,
            u.full_name as assigned_to_name,
            creator.full_name as created_by_name,
            assigner.full_name as assigned_by_name,
            DATEDIFF(CURDATE(), ac.filing_date) as days_open
        FROM arbitration_cases ac
        LEFT JOIN users u ON ac.assigned_to = u.id
        LEFT JOIN users creator ON ac.created_by = creator.id
        LEFT JOIN users assigner ON ac.assigned_by = assigner.id
        WHERE ac.id = ?
    ");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
        header('Location: cases.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Case view error: " . $e->getMessage());
    header('Location: cases.php');
    exit();
}

// Get case notes
try {
    $stmt = $pdo->prepare("
        SELECT cn.*, u.full_name as user_name
        FROM case_notes cn
        JOIN users u ON cn.user_id = u.id
        WHERE cn.case_id = ?
        ORDER BY cn.created_at DESC
    ");
    $stmt->execute([$case_id]);
    $case_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Case notes fetch error: " . $e->getMessage());
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
    error_log("Case documents fetch error: " . $e->getMessage());
    $case_documents = [];
}

// Get case hearings
try {
    $stmt = $pdo->prepare("
        SELECT ah.*, u.full_name as created_by_name
        FROM arbitration_hearings ah
        JOIN users u ON ah.created_by = u.id
        WHERE ah.case_id = ?
        ORDER BY ah.hearing_date DESC
    ");
    $stmt->execute([$case_id]);
    $case_hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Case hearings fetch error: " . $e->getMessage());
    $case_hearings = [];
}

// Handle add note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $note_type = $_POST['note_type'];
    $content = $_POST['content'];
    $is_confidential = isset($_POST['is_confidential']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO case_notes (case_id, user_id, note_type, content, is_confidential) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$case_id, $user_id, $note_type, $content, $is_confidential]);
        
        $_SESSION['success_message'] = "Note added successfully!";
        header("Location: case-view.php?id=$case_id");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding note: " . $e->getMessage();
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $resolution_details = $_POST['resolution_details'] ?? null;
    
    try {
        $update_data = ['status' => $new_status];
        if ($new_status === 'resolved') {
            $update_data['resolution_date'] = date('Y-m-d');
            $update_data['resolution_details'] = $resolution_details;
        }
        
        $set_clause = implode(', ', array_map(fn($field) => "$field = ?", array_keys($update_data)));
        $values = array_values($update_data);
        $values[] = $case_id;
        
        $stmt = $pdo->prepare("UPDATE arbitration_cases SET $set_clause WHERE id = ?");
        $stmt->execute($values);
        
        $_SESSION['success_message'] = "Case status updated successfully!";
        header("Location: case-view.php?id=$case_id");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case <?php echo htmlspecialchars($case['case_number']); ?> - Arbitration Secretary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Include all CSS styles from cases.php */
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

        /* Include all other CSS styles from cases.php */
        .header { background: var(--white); box-shadow: var(--shadow-sm); padding: 1rem 0; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--medium-gray); height: 80px; display: flex; align-items: center; }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 1.5rem; width: 100%; }
        .logo-section { display: flex; align-items: center; gap: 0.75rem; }
        .logos { display: flex; gap: 0.75rem; align-items: center; }
        .logo { height: 40px; width: auto; }
        .brand-text h1 { font-size: 1.3rem; font-weight: 700; color: var(--primary-blue); }
        .user-menu { display: flex; align-items: center; gap: 1.5rem; }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .user-avatar { width: 50px; height: 50px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1.1rem; border: 3px solid var(--medium-gray); overflow: hidden; position: relative; transition: var(--transition); }
        .user-avatar:hover { border-color: var(--primary-blue); transform: scale(1.05); }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: var(--text-dark); font-size: 0.95rem; }
        .user-role { font-size: 0.8rem; color: var(--dark-gray); }
        .header-actions { display: flex; align-items: center; gap: 0.75rem; }
        .icon-btn { width: 44px; height: 44px; border: none; background: var(--light-gray); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-dark); cursor: pointer; transition: var(--transition); position: relative; font-size: 1.1rem; }
        .icon-btn:hover { background: var(--primary-blue); color: white; transform: translateY(-2px); }
        .notification-badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: 600; border: 2px solid var(--white); }
        .logout-btn { background: var(--gradient-primary); color: white; padding: 0.6rem 1.2rem; border-radius: 20px; text-decoration: none; font-weight: 600; transition: var(--transition); font-size: 0.85rem; border: none; cursor: pointer; }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .dashboard-container { display: grid; grid-template-columns: 220px 1fr; min-height: calc(100vh - 80px); }
        .sidebar { background: var(--white); border-right: 1px solid var(--medium-gray); padding: 1.5rem 0; position: sticky; top: 60px; height: calc(100vh - 60px); overflow-y: auto; }
        .sidebar-menu { list-style: none; }
        .menu-item { margin-bottom: 0.25rem; }
        .menu-item a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1.5rem; color: var(--text-dark); text-decoration: none; transition: var(--transition); border-left: 3px solid transparent; font-size: 0.85rem; }
        .menu-item a:hover, .menu-item a.active { background: var(--light-blue); border-left-color: var(--primary-blue); color: var(--primary-blue); }
        .menu-item i { width: 16px; text-align: center; font-size: 0.9rem; }
        .menu-badge { background: var(--danger); color: white; border-radius: 10px; padding: 0.1rem 0.4rem; font-size: 0.7rem; font-weight: 600; margin-left: auto; }
        .main-content { padding: 1.5rem; overflow-y: auto; height: calc(100vh - 80px); }
        .page-header { display: flex; justify-content: between; align-items: center; margin-bottom: 1.5rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
        .page-actions { display: flex; gap: 1rem; }
        .btn { padding: 0.6rem 1.2rem; border-radius: var(--border-radius); text-decoration: none; font-weight: 600; font-size: 0.85rem; border: none; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: var(--gradient-primary); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; border: 1px solid var(--primary-blue); color: var(--primary-blue); }
        .btn-outline:hover { background: var(--primary-blue); color: white; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        .card { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { font-size: 1rem; font-weight: 600; color: var(--text-dark); }
        .card-body { padding: 1.5rem; }
        .alert { padding: 0.75rem 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; border-left: 4px solid; font-size: 0.8rem; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-error { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }
        .status-badge { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .status-filed { background: #fff3cd; color: var(--warning); }
        .status-under_review { background: #cce7ff; color: var(--primary-blue); }
        .status-hearing_scheduled { background: #e2e3ff; color: #6f42c1; }
        .status-mediation { background: #fff0f0; color: #e83e8c; }
        .status-resolved { background: #d4edda; color: var(--success); }
        .status-dismissed { background: #f8d7da; color: var(--danger); }
        .status-appealed { background: #ffe6cc; color: #fd7e14; }
        .priority-badge { padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .priority-urgent { background: #f8d7da; color: var(--danger); }
        .priority-high { background: #ffe6cc; color: #fd7e14; }
        .priority-medium { background: #fff3cd; color: var(--warning); }
        .priority-low { background: #d4edda; color: var(--success); }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-dark); }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; transition: var(--transition); }
        .form-control:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1); }
        .form-select { width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; }
        .form-textarea { min-height: 120px; resize: vertical; }
        .checkbox-group { display: flex; align-items: center; gap: 0.5rem; }
        .checkbox { width: 16px; height: 16px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; }
        .info-item { display: flex; flex-direction: column; gap: 0.25rem; }
        .info-label { font-weight: 600; color: var(--dark-gray); font-size: 0.8rem; }
        .info-value { color: var(--text-dark); }
        .notes-list { display: grid; gap: 1rem; }
        .note-item { padding: 1rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--light-gray); }
        .note-header { display: flex; justify-content: between; align-items: center; margin-bottom: 0.5rem; }
        .note-meta { display: flex; gap: 1rem; font-size: 0.8rem; color: var(--dark-gray); }
        .note-type { font-weight: 600; text-transform: capitalize; }
        .note-confidential { background: var(--danger); color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.7rem; }
        .note-content { color: var(--text-dark); line-height: 1.6; }
        .documents-list { display: grid; gap: 1rem; }
        .document-item { display: flex; justify-content: between; align-items: center; padding: 1rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); }
        .document-info { display: flex; align-items: center; gap: 1rem; }
        .document-icon { width: 40px; height: 40px; background: var(--light-blue); border-radius: var(--border-radius); display: flex; align-items: center; justify-content: center; color: var(--primary-blue); }
        .document-details { flex: 1; }
        .document-title { font-weight: 600; margin-bottom: 0.25rem; }
        .document-meta { font-size: 0.8rem; color: var(--dark-gray); }
        .document-actions { display: flex; gap: 0.5rem; }
        .tabs { display: flex; border-bottom: 1px solid var(--medium-gray); margin-bottom: 1.5rem; }
        .tab { padding: 1rem 1.5rem; background: none; border: none; color: var(--dark-gray); cursor: pointer; transition: var(--transition); border-bottom: 2px solid transparent; }
        .tab.active { color: var(--primary-blue); border-bottom-color: var(--primary-blue); }
        .tab:hover { color: var(--primary-blue); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        @media (max-width: 768px) {
            .dashboard-container { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .page-header { flex-direction: column; gap: 1rem; align-items: start; }
            .page-actions { width: 100%; justify-content: space-between; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
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
                 <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php" class="active">
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php">
                        <i class="fas fa-sticky-note"></i>
                        <span>Case Notes</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
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
                    <h1 class="page-title">Case: <?php echo htmlspecialchars($case['case_number']); ?></h1>
                    <p style="color: var(--dark-gray); font-size: 0.9rem; margin-top: 0.25rem;">
                        <?php echo htmlspecialchars($case['title']); ?>
                    </p>
                </div>
                <div class="page-actions">
                    <a href="cases.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Cases
                    </a>
                    <a href="case-notes.php?case_id=<?php echo $case_id; ?>" class="btn btn-primary">
                        <i class="fas fa-sticky-note"></i> Manage Notes
                    </a>
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

            <!-- Case Overview Card -->
            <div class="card">
                <div class="card-header">
                    <h3>Case Overview</h3>
                    <div>
                        <span class="status-badge status-<?php echo $case['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                        </span>
                        <span class="priority-badge priority-<?php echo $case['priority']; ?>" style="margin-left: 0.5rem;">
                            <?php echo ucfirst($case['priority']); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Case Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($case['case_number']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Case Type</span>
                            <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Filing Date</span>
                            <span class="info-value"><?php echo date('M j, Y', strtotime($case['filing_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Days Open</span>
                            <span class="info-value"><?php echo $case['days_open']; ?> days</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Assigned To</span>
                            <span class="info-value">
                                <?php echo $case['assigned_to_name'] ? htmlspecialchars($case['assigned_to_name']) : '<span style="color: var(--dark-gray); font-style: italic;">Unassigned</span>'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Assigned By</span>
                            <span class="info-value">
                                <?php echo $case['assigned_by_name'] ? htmlspecialchars($case['assigned_by_name']) : 'N/A'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('parties')">Parties Information</button>
                <button class="tab" onclick="openTab('description')">Case Description</button>
                <button class="tab" onclick="openTab('notes')">Case Notes (<?php echo count($case_notes); ?>)</button>
                <button class="tab" onclick="openTab('documents')">Documents (<?php echo count($case_documents); ?>)</button>
                <button class="tab" onclick="openTab('hearings')">Hearings (<?php echo count($case_hearings); ?>)</button>
                <button class="tab" onclick="openTab('actions')">Case Actions</button>
            </div>

            <!-- Parties Information Tab -->
            <div id="parties" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Parties Information</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                            <!-- Complainant -->
                            <div>
                                <h4 style="margin-bottom: 1rem; color: var(--primary-blue);">
                                    <i class="fas fa-user-circle"></i> Complainant
                                </h4>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Name</span>
                                        <span class="info-value"><?php echo htmlspecialchars($case['complainant_name']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Contact</span>
                                        <span class="info-value"><?php echo htmlspecialchars($case['complainant_contact'] ?? 'N/A'); ?></span>
                                    </div>
                                    <?php if ($case['complainant_id']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Student ID</span>
                                        <span class="info-value"><?php echo htmlspecialchars($case['complainant_id']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Respondent -->
                            <div>
                                <h4 style="margin-bottom: 1rem; color: var(--danger);">
                                    <i class="fas fa-user-circle"></i> Respondent
                                </h4>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Name</span>
                                        <span class="info-value"><?php echo htmlspecialchars($case['respondent_name']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Contact</span>
                                        <span class="info-value"><?php echo htmlspecialchars($case['respondent_contact'] ?? 'N/A'); ?></span>
                                    </div>
                                    <?php if ($case['respondent_id']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Student ID</span>
                                        <span class="info-value"><?php echo htmlspecialchars($case['respondent_id']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case Description Tab -->
            <div id="description" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Case Description</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Case Title</label>
                            <div class="form-control" style="background: var(--light-gray);"><?php echo htmlspecialchars($case['title']); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Detailed Description</label>
                            <div class="form-control form-textarea" style="background: var(--light-gray); min-height: 200px; white-space: pre-wrap;"><?php echo htmlspecialchars($case['description']); ?></div>
                        </div>
                        <?php if ($case['resolution_details']): ?>
                        <div class="form-group">
                            <label class="form-label">Resolution Details</label>
                            <div class="form-control form-textarea" style="background: var(--light-gray); min-height: 120px; white-space: pre-wrap;"><?php echo htmlspecialchars($case['resolution_details']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Case Notes Tab -->
            <div id="notes" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Case Notes</h3>
                        <button class="btn btn-primary btn-sm" onclick="document.getElementById('addNoteModal').style.display='flex'">
                            <i class="fas fa-plus"></i> Add Note
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($case_notes)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-sticky-note" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No notes added yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="notes-list">
                                <?php foreach ($case_notes as $note): ?>
                                    <div class="note-item">
                                        <div class="note-header">
                                            <div class="note-meta">
                                                <span class="note-type"><?php echo htmlspecialchars($note['note_type']); ?></span>
                                                <span>By: <?php echo htmlspecialchars($note['user_name']); ?></span>
                                                <span>On: <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></span>
                                            </div>
                                            <?php if ($note['is_confidential']): ?>
                                                <span class="note-confidential">Confidential</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="note-content">
                                            <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Documents Tab -->
            <div id="documents" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Case Documents</h3>
                        <a href="documents.php?case_id=<?php echo $case_id; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-upload"></i> Manage Documents
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($case_documents)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-file" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No documents uploaded yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="documents-list">
                                <?php foreach ($case_documents as $doc): ?>
                                    <div class="document-item">
                                        <div class="document-info">
                                            <div class="document-icon">
                                                <i class="fas fa-file-pdf"></i>
                                            </div>
                                            <div class="document-details">
                                                <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                                <div class="document-meta">
                                                    <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?> • 
                                                    Uploaded by <?php echo htmlspecialchars($doc['uploaded_by_name']); ?> on 
                                                    <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                                                    <?php if ($doc['is_confidential']): ?>
                                                        • <span style="color: var(--danger);">Confidential</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="document-actions">
                                            <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-outline btn-sm">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Hearings Tab -->
            <div id="hearings" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Case Hearings</h3>
                        <a href="hearings.php?case_id=<?php echo $case_id; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Schedule Hearing
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($case_hearings)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-gavel" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No hearings scheduled yet.</p>
                            </div>
                        <?php else: ?>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($case_hearings as $hearing): ?>
                                    <div style="padding: 1rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius);">
                                        <div style="display: flex; justify-content: between; align-items: start; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong><?php echo date('M j, Y g:i A', strtotime($hearing['hearing_date'])); ?></strong>
                                                <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    Location: <?php echo htmlspecialchars($hearing['location']); ?>
                                                </div>
                                            </div>
                                            <span class="status-badge status-<?php echo $hearing['status']; ?>">
                                                <?php echo ucfirst($hearing['status']); ?>
                                            </span>
                                        </div>
                                        <?php if ($hearing['purpose']): ?>
                                            <div style="margin-bottom: 0.5rem;">
                                                <strong>Purpose:</strong> <?php echo htmlspecialchars($hearing['purpose']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($hearing['minutes']): ?>
                                            <div style="margin-bottom: 0.5rem;">
                                                <strong>Minutes:</strong> <?php echo nl2br(htmlspecialchars($hearing['minutes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Case Actions Tab -->
            <div id="actions" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Case Actions</h3>
                    </div>
                    <div class="card-body">
                        <!-- Status Update Form -->
                        <form method="POST" style="margin-bottom: 2rem;">
                            <h4 style="margin-bottom: 1rem;">Update Case Status</h4>
                            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; align-items: start;">
                                <div class="form-group">
                                    <label class="form-label">New Status</label>
                                    <select name="status" class="form-control" required>
                                        <option value="filed" <?php echo $case['status'] === 'filed' ? 'selected' : ''; ?>>Filed</option>
                                        <option value="under_review" <?php echo $case['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                        <option value="hearing_scheduled" <?php echo $case['status'] === 'hearing_scheduled' ? 'selected' : ''; ?>>Hearing Scheduled</option>
                                        <option value="mediation" <?php echo $case['status'] === 'mediation' ? 'selected' : ''; ?>>Mediation</option>
                                        <option value="resolved" <?php echo $case['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="dismissed" <?php echo $case['status'] === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                                        <option value="appealed" <?php echo $case['status'] === 'appealed' ? 'selected' : ''; ?>>Appealed</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Resolution Details (if resolved)</label>
                                    <textarea name="resolution_details" class="form-control form-textarea" placeholder="Enter resolution details if marking as resolved..."><?php echo htmlspecialchars($case['resolution_details'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <button type="submit" name="update_status" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Status
                            </button>
                        </form>

                        <!-- Quick Actions -->
                        <div>
                            <h4 style="margin-bottom: 1rem;">Quick Actions</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                <a href="case-notes.php?case_id=<?php echo $case_id; ?>" class="btn btn-outline" style="text-align: center;">
                                    <i class="fas fa-sticky-note" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i><br>
                                    Add Note
                                </a>
                                <a href="documents.php?case_id=<?php echo $case_id; ?>" class="btn btn-outline" style="text-align: center;">
                                    <i class="fas fa-file-upload" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i><br>
                                    Upload Document
                                </a>
                                <a href="hearings.php?case_id=<?php echo $case_id; ?>" class="btn btn-outline" style="text-align: center;">
                                    <i class="fas fa-gavel" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i><br>
                                    Schedule Hearing
                                </a>
                                <a href="cases.php?assign=<?php echo $case_id; ?>" class="btn btn-outline" style="text-align: center;">
                                    <i class="fas fa-user-check" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i><br>
                                    Assign Case
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Note Modal -->
    <div id="addNoteModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Case Note</h3>
                <button class="modal-close" onclick="document.getElementById('addNoteModal').style.display='none'">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Note Type</label>
                        <select name="note_type" class="form-control" required>
                            <option value="general">General</option>
                            <option value="hearing">Hearing</option>
                            <option value="evidence">Evidence</option>
                            <option value="decision">Decision</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Content</label>
                        <textarea name="content" class="form-control form-textarea" placeholder="Enter your note here..." required></textarea>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_confidential" id="is_confidential" class="checkbox">
                        <label for="is_confidential">Mark as confidential</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('addNoteModal').style.display='none'">Cancel</button>
                    <button type="submit" name="add_note" class="btn btn-primary">Add Note</button>
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

        // Tab functionality
        function openTab(tabName) {
            // Hide all tab contents
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }

            // Remove active class from all tabs
            const tabs = document.getElementsByClassName('tab');
            for (let i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }

            // Show the specific tab content and activate the tab
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            const modal = document.getElementById('addNoteModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>
</body>
</html>