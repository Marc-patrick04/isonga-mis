<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

// Get filter parameters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort_by'] ?? 'last_login_desc';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

// Build query for user login activities
$query = "
    SELECT 
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.status as user_status,
        u.created_at as registered_at,
        u.last_login,
        u.login_count,
        u.avatar_url,
        u.department_id,
        u.program_id,
        la.id as activity_id,
        la.ip_address,
        la.user_agent,
        la.login_time,
        la.success as login_success,
        la.failure_reason,
        d.name as department_name,
        p.name as program_name
    FROM users u
    LEFT JOIN login_activities la ON u.id = la.user_id AND la.id IN (
        SELECT MAX(id) FROM login_activities WHERE user_id = u.id GROUP BY user_id
    )
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($search)) {
    $query .= " AND (u.full_name ILIKE ? OR u.email ILIKE ? OR u.reg_number ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($role_filter !== 'all') {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== 'all') {
    $query .= " AND u.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $query .= " AND u.last_login >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND u.last_login <= ?";
    $params[] = $date_to . ' 23:59:59';
}

// Apply sorting
switch ($sort_by) {
    case 'name_asc':
        $query .= " ORDER BY u.full_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY u.full_name DESC";
        break;
    case 'last_login_asc':
        $query .= " ORDER BY u.last_login ASC NULLS LAST";
        break;
    case 'last_login_desc':
    default:
        $query .= " ORDER BY u.last_login DESC NULLS LAST";
        break;
    case 'login_count_desc':
        $query .= " ORDER BY u.login_count DESC";
        break;
    case 'registered_desc':
        $query .= " ORDER BY u.created_at DESC";
        break;
}

$query .= " LIMIT ?";
$params[] = $limit;

// Get users with login info
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Users query error: " . $e->getMessage());
    $users = [];
}

// Get login activity timeline (last 30 days)
try {
    $stmt = $pdo->query("
        SELECT 
            DATE(login_time) as login_date,
            COUNT(*) as login_count,
            COUNT(CASE WHEN success = true THEN 1 END) as successful_logins,
            COUNT(CASE WHEN success = false THEN 1 END) as failed_logins
        FROM login_activities
        WHERE login_time >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY DATE(login_time)
        ORDER BY login_date DESC
    ");
    $login_timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $login_timeline = [];
}

// Get role statistics
try {
    $stmt = $pdo->query("
        SELECT 
            role,
            COUNT(*) as total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_users,
            AVG(login_count) as avg_logins
        FROM users
        GROUP BY role
        ORDER BY total_users DESC
    ");
    $role_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $role_stats = [];
}

// Get recent login activities (detailed)
try {
    $stmt = $pdo->prepare("
        SELECT 
            la.*,
            u.full_name,
            u.role,
            u.email
        FROM login_activities la
        JOIN users u ON la.user_id = u.id
        ORDER BY la.login_time DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recent_logins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_logins = [];
}

// Get users who haven't logged in recently (inactive users)
try {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.full_name,
            u.email,
            u.role,
            u.created_at,
            u.last_login,
            u.login_count,
            EXTRACT(DAY FROM (CURRENT_DATE - u.last_login)) as days_inactive
        FROM users u
        WHERE u.last_login IS NOT NULL 
        AND u.last_login < CURRENT_DATE - INTERVAL '30 days'
        AND u.status = 'active'
        ORDER BY u.last_login ASC
        LIMIT 10
    ");
    $inactive_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inactive_users = [];
}

// Get never logged in users
try {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.full_name,
            u.email,
            u.role,
            u.created_at,
            u.status
        FROM users u
        WHERE u.last_login IS NULL
        AND u.status = 'active'
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
    $never_logged_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $never_logged_users = [];
}

// Get today's login statistics
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_logins_today,
            COUNT(DISTINCT user_id) as unique_users_today
        FROM login_activities
        WHERE DATE(login_time) = CURRENT_DATE
    ");
    $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $today_stats = ['total_logins_today' => 0, 'unique_users_today' => 0];
}

// Get current active sessions (based on recent activity in last 15 minutes)
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT user_id) as active_sessions
        FROM login_activities
        WHERE login_time >= CURRENT_TIMESTAMP - INTERVAL '15 minutes'
    ");
    $active_sessions = $stmt->fetch(PDO::FETCH_ASSOC)['active_sessions'] ?? 0;
} catch (PDOException $e) {
    $active_sessions = 0;
}

// Get roles list for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT role FROM users ORDER BY role");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $roles = [];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>User Activity & Login Tracking - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --secondary: #1e88e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --sidebar-bg: #1f2937;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Header */
        .header {
            background: var(--header-bg);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text p {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border-color);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
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
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem 0;
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
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
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover {
            background: var(--bg-primary);
            border-left-color: var(--primary);
        }

        .menu-item a.active {
            background: var(--bg-primary);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.blue { background: rgba(0, 86, 179, 0.1); color: var(--primary); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.orange { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.purple { background: rgba(124, 58, 237, 0.1); color: #7c3aed; }
        .stat-icon.red { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-top: 0.25rem;
        }

        /* Card */
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 i {
            color: var(--primary);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Filters */
        .filters-card {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .form-select, .form-input {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 0.75rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        .data-table td {
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table tr:hover td {
            background: var(--bg-primary);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-active, .status-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive, .status-failed {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .status-suspended {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .role-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--bg-primary);
            color: var(--primary);
        }

        /* User Agent Tooltip */
        .user-agent-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: help;
        }

        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .metric-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .metric-item:last-child {
            border-bottom: none;
        }

        .metric-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .metric-value {
            font-weight: 700;
            font-size: 0.875rem;
        }

        /* Button */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--bg-primary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .page-btn {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-primary);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .page-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
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

        .card, .stat-card {
            animation: fadeInUp 0.3s ease forwards;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                display: none;
            }
            
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 0.75rem 1rem;
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .data-table th, .data-table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo-img">
                <div class="logo-text">
                    <h1>Isonga Admin</h1>
                    <p>User Activity Tracking</p>
                </div>
            </div>
            <div class="user-area">
                <button class="theme-toggle" id="themeToggle" title="Toggle Dark/Light Mode">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Administrator'); ?></div>
                        <div class="user-role">System Administrator</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="hero.php"><i class="fas fa-images"></i> Hero Images</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="user_activity.php" class="active"><i class="fas fa-history"></i> User Activity</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="programs.php"><i class="fas fa-graduation-cap"></i> Programs</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="announcements.php"><i class="fas fa-bell"></i> Announcements</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <!-- Stats Row -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format(count($users)); ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-sign-in-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($today_stats['total_logins_today'] ?? 0); ?></div>
                        <div class="stat-label">Logins Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-user-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($today_stats['unique_users_today'] ?? 0); ?></div>
                        <div class="stat-label">Active Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($active_sessions); ?></div>
                        <div class="stat-label">Active Now (15m)</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-input" placeholder="Name, email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['role']; ?>" <?php echo $role_filter === $role['role'] ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($role['role']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sort By</label>
                        <select name="sort_by" class="form-select">
                            <option value="last_login_desc" <?php echo $sort_by === 'last_login_desc' ? 'selected' : ''; ?>>Last Login (Newest)</option>
                            <option value="last_login_asc" <?php echo $sort_by === 'last_login_asc' ? 'selected' : ''; ?>>Last Login (Oldest)</option>
                            <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="login_count_desc" <?php echo $sort_by === 'login_count_desc' ? 'selected' : ''; ?>>Login Count (High to Low)</option>
                            <option value="registered_desc" <?php echo $sort_by === 'registered_desc' ? 'selected' : ''; ?>>Recently Registered</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="user_activity.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> User Login History</h3>
                    <div>
                        <span class="status-badge status-active">Active</span>
                        <span class="status-badge status-inactive">Inactive</span>
                        <span class="status-badge status-suspended">Suspended</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>IP Address</th>
                                    <th>Login Count</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <p>No users found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user_data): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <div style="width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.7rem;">
                                                        <?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($user_data['full_name']); ?></div>
                                                        <div style="font-size: 0.65rem; color: var(--text-secondary);"><?php echo htmlspecialchars($user_data['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="role-badge"><?php echo ucfirst($user_data['role']); ?></span></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $user_data['user_status']; ?>">
                                                    <?php echo ucfirst($user_data['user_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user_data['last_login']): ?>
                                                    <div><?php echo date('M j, Y', strtotime($user_data['last_login'])); ?></div>
                                                    <div style="font-size: 0.65rem; color: var(--text-secondary);"><?php echo date('g:i A', strtotime($user_data['last_login'])); ?></div>
                                                <?php else: ?>
                                                    <span style="color: var(--text-secondary);">Never logged in</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user_data['ip_address']): ?>
                                                    <code style="font-size: 0.7rem;"><?php echo htmlspecialchars($user_data['ip_address']); ?></code>
                                                <?php else: ?>
                                                    <span style="color: var(--text-secondary);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600;"><?php echo number_format($user_data['login_count'] ?? 0); ?></div>
                                                <div style="font-size: 0.65rem; color: var(--text-secondary);">total logins</div>
                                            </td>
                                            <td>
                                                <div><?php echo date('M j, Y', strtotime($user_data['registered_at'])); ?></div>
                                                <div style="font-size: 0.65rem; color: var(--text-secondary);">joined</div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Two Column Layout for Additional Info -->
            <div class="metrics-grid">
                <!-- Role Statistics -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Role Statistics</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($role_stats)): ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-pie"></i>
                                <p>No data available</p>
                            </div>
                        <?php else: ?>
                            <div class="metrics-grid" style="grid-template-columns: 1fr;">
                                <?php foreach ($role_stats as $stat): ?>
                                    <div class="metric-item">
                                        <span class="metric-label"><?php echo ucfirst($stat['role']); ?></span>
                                        <span class="metric-value"><?php echo $stat['total_users']; ?> users</span>
                                    </div>
                                    <div style="margin-left: 1rem; margin-bottom: 0.5rem;">
                                        <div style="font-size: 0.7rem; color: var(--text-secondary);">
                                            Active: <?php echo $stat['active_users']; ?> | 
                                            Inactive: <?php echo $stat['inactive_users']; ?> | 
                                            Avg Logins: <?php echo round($stat['avg_logins']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Login Activities -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Login Activities</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_logins)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No recent logins</p>
                            </div>
                        <?php else: ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($recent_logins as $login): ?>
                                    <div class="metric-item">
                                        <div>
                                            <div style="font-weight: 600; font-size: 0.8rem;"><?php echo htmlspecialchars($login['full_name']); ?></div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                                <i class="fas fa-user-tag"></i> <?php echo ucfirst($login['role']); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-size: 0.7rem;">
                                                <?php if ($login['success'] == 't' || $login['success'] == true): ?>
                                                    <span class="status-badge status-success">Success</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-failed">Failed</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.65rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                                <?php echo date('M j, g:i A', strtotime($login['login_time'])); ?>
                                            </div>
                                            <div style="font-size: 0.6rem; color: var(--text-secondary);">
                                                <i class="fas fa-network-wired"></i> <?php echo htmlspecialchars($login['ip_address']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Inactive Users (30+ days) -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-slash"></i> Inactive Users (30+ days)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($inactive_users)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-check"></i>
                                <p>All users are active!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($inactive_users as $user_data): ?>
                                <div class="metric-item">
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.8rem;"><?php echo htmlspecialchars($user_data['full_name']); ?></div>
                                        <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                            <?php echo htmlspecialchars($user_data['email']); ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-size: 0.7rem; color: var(--warning);">
                                            <?php echo $user_data['days_inactive']; ?> days ago
                                        </div>
                                        <div style="font-size: 0.6rem; color: var(--text-secondary);">
                                            Last: <?php echo date('M j, Y', strtotime($user_data['last_login'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Never Logged In -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-plus"></i> Never Logged In</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($never_logged_users)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>All users have logged in!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($never_logged_users as $user_data): ?>
                                <div class="metric-item">
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.8rem;"><?php echo htmlspecialchars($user_data['full_name']); ?></div>
                                        <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                            <?php echo ucfirst($user_data['role']); ?> • <?php echo htmlspecialchars($user_data['email']); ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                            Registered: <?php echo date('M j, Y', strtotime($user_data['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        
        // Animate cards on load
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card, .stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.03}s`;
            });
        });
    </script>
</body>
</html>