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
$note_type_filter = $_GET['note_type'] ?? '';
$confidential_filter = $_GET['confidential'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if ($case_id) {
    $where_conditions[] = "cn.case_id = ?";
    $params[] = $case_id;
}

if (!empty($note_type_filter)) {
    $where_conditions[] = "cn.note_type = ?";
    $params[] = $note_type_filter;
}

if ($confidential_filter !== '') {
    $where_conditions[] = "cn.is_confidential = ?";
    $params[] = $confidential_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(cn.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(cn.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) 
    FROM case_notes cn
    JOIN arbitration_cases ac ON cn.case_id = ac.id
    $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_notes = $stmt->fetchColumn();
$total_pages = ceil($total_notes / $per_page);

// Get case notes with filters and pagination
$sql = "
    SELECT 
        cn.*,
        ac.case_number,
        ac.title as case_title,
        u.full_name as user_name,
        u.role as user_role
    FROM case_notes cn
    JOIN arbitration_cases ac ON cn.case_id = ac.id
    JOIN users u ON cn.user_id = u.id
    $where_clause
    ORDER BY cn.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$case_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Handle add note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $case_id = $_POST['case_id'];
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
        header("Location: case-notes.php?" . ($case_id ? "case_id=$case_id&" : "") . "page=$page");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding note: " . $e->getMessage();
    }
}

// Handle delete note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'])) {
    $note_id = $_POST['note_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM case_notes WHERE id = ?");
        $stmt->execute([$note_id]);
        
        $_SESSION['success_message'] = "Note deleted successfully!";
        header("Location: case-notes.php?" . ($case_id ? "case_id=$case_id&" : "") . "page=$page");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting note: " . $e->getMessage();
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

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_notes FROM case_notes");
    $total_notes_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_notes'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as confidential_notes FROM case_notes WHERE is_confidential = 1");
    $confidential_notes_count = $stmt->fetch(PDO::FETCH_ASSOC)['confidential_notes'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as recent_notes FROM case_notes WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recent_notes_count = $stmt->fetch(PDO::FETCH_ASSOC)['recent_notes'];
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_notes_count = $confidential_notes_count = $recent_notes_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case Notes - Arbitration Secretary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Include all CSS styles from case-view.php */
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

        /* Include all other CSS styles from case-view.php */
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
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c82333; }
        .card { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { font-size: 1rem; font-weight: 600; color: var(--text-dark); }
        .card-body { padding: 1.5rem; }
        .alert { padding: 0.75rem 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; border-left: 4px solid; font-size: 0.8rem; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-error { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); padding: 1.5rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); border-left: 4px solid var(--primary-blue); transition: var(--transition); display: flex; align-items: center; gap: 1rem; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .stat-card .stat-icon { background: var(--light-blue); color: var(--primary-blue); }
        .stat-card.success .stat-icon { background: #d4edda; color: var(--success); }
        .stat-card.warning .stat-icon { background: #fff3cd; color: var(--warning); }
        .stat-card.danger .stat-icon { background: #f8d7da; color: var(--danger); }
        .stat-content { flex: 1; }
        .stat-number { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.25rem; color: var(--text-dark); }
        .stat-label { color: var(--dark-gray); font-size: 0.8rem; font-weight: 500; }
        .filters-card { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; padding: 1.5rem; }
        .filters-header { display: flex; justify-content: between; align-items: center; margin-bottom: 1rem; }
        .filters-title { font-weight: 600; color: var(--text-dark); }
        .filters-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-dark); }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; transition: var(--transition); }
        .form-control:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1); }
        .form-select { width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; }
        .form-textarea { min-height: 120px; resize: vertical; }
        .checkbox-group { display: flex; align-items: center; gap: 0.5rem; }
        .checkbox { width: 16px; height: 16px; }
        .filter-actions { display: flex; gap: 0.5rem; align-items: end; }
        .notes-list { display: grid; gap: 1rem; }
        .note-item { padding: 1.5rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); transition: var(--transition); }
        .note-item:hover { box-shadow: var(--shadow-sm); }
        .note-item.confidential { border-left: 4px solid var(--danger); background: #fff5f5; }
        .note-header { display: flex; justify-content: between; align-items: start; margin-bottom: 1rem; }
        .note-case { font-weight: 600; color: var(--primary-blue); }
        .note-meta { display: flex; gap: 1rem; font-size: 0.8rem; color: var(--dark-gray); flex-wrap: wrap; }
        .note-type { font-weight: 600; text-transform: capitalize; padding: 0.25rem 0.5rem; border-radius: 4px; background: var(--light-blue); color: var(--primary-blue); }
        .note-confidential { background: var(--danger); color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.7rem; }
        .note-content { color: var(--text-dark); line-height: 1.6; margin-bottom: 1rem; white-space: pre-wrap; }
        .note-actions { display: flex; gap: 0.5rem; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid var(--medium-gray); }
        .pagination-btn { padding: 0.5rem 0.75rem; border: 1px solid var(--medium-gray); background: var(--white); color: var(--text-dark); text-decoration: none; border-radius: 4px; transition: var(--transition); font-size: 0.8rem; }
        .pagination-btn:hover { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .pagination-btn.active { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .pagination-btn.disabled { opacity: 0.5; cursor: not-allowed; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-lg); width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: between; align-items: center; }
        .modal-title { font-weight: 600; color: var(--text-dark); }
        .modal-close { background: none; border: none; font-size: 1.25rem; color: var(--dark-gray); cursor: pointer; transition: var(--transition); }
        .modal-close:hover { color: var(--danger); }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--medium-gray); display: flex; justify-content: flex-end; gap: 0.75rem; }
        .delete-form { display: inline; }
        @media (max-width: 768px) {
            .dashboard-container { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .page-header { flex-direction: column; gap: 1rem; align-items: start; }
            .page-actions { width: 100%; justify-content: space-between; }
            .filters-form { grid-template-columns: 1fr; }
            .note-header { flex-direction: column; gap: 0.5rem; }
            .note-meta { justify-content: space-between; width: 100%; }
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
                    <a href="cases.php" >
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php"class="active">
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
                    <h1 class="page-title">Case Notes</h1>
                    <p style="color: var(--dark-gray); font-size: 0.9rem; margin-top: 0.25rem;">
                        <?php if ($case_details): ?>
                            For Case: <?php echo htmlspecialchars($case_details['case_number']); ?> - <?php echo htmlspecialchars($case_details['title']); ?>
                        <?php else: ?>
                            Manage all case notes across arbitration cases
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
                    <button class="btn btn-primary" onclick="document.getElementById('addNoteModal').style.display='flex'">
                        <i class="fas fa-plus"></i> Add Note
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
                        <i class="fas fa-sticky-note"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_notes_count; ?></div>
                        <div class="stat-label">Total Notes</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-eye-slash"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $confidential_notes_count; ?></div>
                        <div class="stat-label">Confidential Notes</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $recent_notes_count; ?></div>
                        <div class="stat-label">Last 7 Days</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_notes; ?></div>
                        <div class="stat-label">Filtered Notes</div>
                    </div>
                </div>
            </div>

            <!-- Filters Card -->
            <div class="filters-card">
                <div class="filters-header">
                    <h3 class="filters-title">Filter Notes</h3>
                </div>
                <form method="GET" class="filters-form">
                    <?php if ($case_id): ?>
                        <input type="hidden" name="case_id" value="<?php echo $case_id; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Note Type</label>
                        <select name="note_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="general" <?php echo $note_type_filter === 'general' ? 'selected' : ''; ?>>General</option>
                            <option value="hearing" <?php echo $note_type_filter === 'hearing' ? 'selected' : ''; ?>>Hearing</option>
                            <option value="evidence" <?php echo $note_type_filter === 'evidence' ? 'selected' : ''; ?>>Evidence</option>
                            <option value="decision" <?php echo $note_type_filter === 'decision' ? 'selected' : ''; ?>>Decision</option>
                            <option value="other" <?php echo $note_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confidentiality</label>
                        <select name="confidential" class="form-select">
                            <option value="">All Notes</option>
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
                        <a href="case-notes.php<?php echo $case_id ? '?case_id=' . $case_id : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Notes List -->
            <div class="card">
                <div class="card-header">
                    <h3>Case Notes (<?php echo $total_notes; ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($case_notes)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                            <i class="fas fa-sticky-note" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No case notes found matching your criteria.</p>
                            <button class="btn btn-primary" style="margin-top: 1rem;" onclick="document.getElementById('addNoteModal').style.display='flex'">
                                <i class="fas fa-plus"></i> Add First Note
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="notes-list">
                            <?php foreach ($case_notes as $note): ?>
                                <div class="note-item <?php echo $note['is_confidential'] ? 'confidential' : ''; ?>">
                                    <div class="note-header">
                                        <div>
                                            <div class="note-case">
                                                <?php echo htmlspecialchars($note['case_number']); ?>: <?php echo htmlspecialchars($note['case_title']); ?>
                                            </div>
                                            <div class="note-meta">
                                                <span class="note-type"><?php echo htmlspecialchars($note['note_type']); ?></span>
                                                <span>By: <?php echo htmlspecialchars($note['user_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $note['user_role'])); ?>)</span>
                                                <span>On: <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></span>
                                                <?php if ($note['is_confidential']): ?>
                                                    <span class="note-confidential">Confidential</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="note-actions">
                                            <a href="case-view.php?id=<?php echo $note['case_id']; ?>" class="btn btn-outline btn-sm" title="View Case">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($note['user_id'] == $user_id): ?>
                                                <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this note?');">
                                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                                    <button type="submit" name="delete_note" class="btn btn-danger btn-sm" title="Delete Note">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="note-content">
                                        <?php echo nl2br(htmlspecialchars($note['content'])); ?>
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

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
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

    <!-- Add Note Modal -->
    <div id="addNoteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Case Note</h3>
                <button class="modal-close" onclick="document.getElementById('addNoteModal').style.display='none'">&times;</button>
            </div>
            <form method="POST">
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
                        <textarea name="content" class="form-control form-textarea" placeholder="Enter your note here..." required style="min-height: 200px;"></textarea>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_confidential" id="is_confidential" class="checkbox">
                        <label for="is_confidential">Mark as confidential (only visible to arbitration committee members)</label>
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

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            const modal = document.getElementById('addNoteModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Auto-focus on textarea when modal opens
        document.getElementById('addNoteModal').addEventListener('shown', function() {
            this.querySelector('textarea').focus();
        });
    </script>
</body>
</html>