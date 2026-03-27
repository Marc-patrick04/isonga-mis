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

// ==================== DASHBOARD STATISTICS ====================
try {
    // User Statistics - using PostgreSQL boolean (true/false)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM committee_members WHERE status = 'active'");
    $active_committees = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Ticket Statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'resolved'");
    $resolved_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE status IN ('open', 'in_progress')");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Club Statistics - using PostgreSQL boolean is_active
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clubs WHERE status = 'active'");
    $active_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM associations WHERE status = 'active'");
    $active_associations = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Content Statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM announcements WHERE status = 'published'");
    $total_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM news WHERE status = 'published'");
    $total_news = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Events - using PostgreSQL CURRENT_DATE
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE event_date >= CURRENT_DATE AND status = 'published'");
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE status = 'published'");
    $total_events = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Arbitration Cases
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases");
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases WHERE status IN ('filed', 'under_review', 'hearing_scheduled', 'mediation')");
    $open_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases WHERE status IN ('resolved', 'dismissed')");
    $resolved_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Department & Program Stats - using PostgreSQL boolean is_active (true/false)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM departments WHERE is_active = true");
    $total_departments = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM programs WHERE is_active = true");
    $total_programs = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Gallery
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM gallery_images WHERE status = 'active'");
    $total_gallery = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // ==================== RECENT DATA ====================
    $stmt = $pdo->query("
        SELECT id, title, excerpt, created_at 
        FROM announcements 
        WHERE status = 'published' 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT id, title, excerpt, created_at 
        FROM news 
        WHERE status = 'published' 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_news = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT id, title, event_date, start_time, location 
        FROM events 
        WHERE event_date >= CURRENT_DATE AND status = 'published' 
        ORDER BY event_date ASC 
        LIMIT 5
    ");
    $upcoming_events_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT id, name, role, photo_url 
        FROM committee_members 
        WHERE status = 'active' 
        ORDER BY role_order ASC, name ASC 
        LIMIT 6
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT t.id, t.subject, t.status, t.created_at, t.name as student_name 
        FROM tickets t
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT ac.id, ac.case_number, ac.title, ac.status, ac.created_at,
               ac.complainant_name as student_name 
        FROM arbitration_cases ac 
        ORDER BY ac.created_at DESC 
        LIMIT 5
    ");
    $recent_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        ORDER BY la.login_time DESC 
        LIMIT 10
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate metrics
    $resolution_rate = $total_tickets > 0 ? round(($resolved_tickets / $total_tickets) * 100) : 0;
    $case_resolution_rate = $total_cases > 0 ? round(($resolved_cases / $total_cases) * 100) : 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'");
    $new_users_30days = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as total FROM login_activities WHERE login_time >= CURRENT_DATE - INTERVAL '7 days'");
    $active_users_7days = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    // Initialize all variables with defaults
    $total_users = $total_students = $active_committees = $total_tickets = $resolved_tickets = 0;
    $open_tickets = $active_clubs = $active_associations = $total_announcements = $total_news = 0;
    $upcoming_events = $total_events = $total_cases = $open_cases = $resolved_cases = 0;
    $total_departments = $total_programs = $total_gallery = $resolution_rate = $case_resolution_rate = 0;
    $new_users_30days = $active_users_7days = 0;
    $recent_announcements = $recent_news = $upcoming_events_list = $committee_members = [];
    $recent_tickets = $recent_cases = $recent_activities = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard - Isonga RPSU Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Light Mode (Default) */
        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --secondary: #1e88e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            
            /* Light Mode Colors */
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

        /* Dark Mode */
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

        /* Header Styles */
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

        /* Welcome Section */
        .welcome-section {
            margin-bottom: 2rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .welcome-section p {
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.25rem;
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
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.blue { background: rgba(0, 86, 179, 0.1); color: var(--primary); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.orange { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-icon.purple { background: rgba(124, 58, 237, 0.1); color: #7c3aed; }
        .stat-icon.teal { background: rgba(20, 184, 166, 0.1); color: #14b8a6; }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-top: 0.25rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: var(--shadow);
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

        /* Lists */
        .item-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .list-item {
            display: flex;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .list-item:last-child {
            padding-bottom: 0;
            border-bottom: none;
        }

        .item-icon {
            width: 40px;
            height: 40px;
            background: var(--bg-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }

        .item-content {
            flex: 1;
        }

        .item-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .item-meta {
            font-size: 0.7rem;
            color: var(--text-secondary);
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.25rem;
        }

        .item-meta i {
            width: 12px;
            margin-right: 0.25rem;
        }

        .item-excerpt {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-resolved, .status-active, .status-published {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-open, .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-in_progress, .status-under_review {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 0.75rem 0;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        .data-table td {
            padding: 0.75rem 0;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .activity-item {
            display: flex;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .activity-item:last-child {
            padding-bottom: 0;
            border-bottom: none;
        }

        .activity-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.65rem;
            color: var(--text-secondary);
        }

        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.2s;
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .action-btn span {
            font-size: 0.7rem;
            font-weight: 500;
        }

        /* View All Link */
        .view-all {
            font-size: 0.7rem;
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .view-all:hover {
            text-decoration: underline;
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

        .empty-state p {
            font-size: 0.8rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .header-container {
                padding: 0.75rem 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .main-content {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
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
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo-img">
                <div class="logo-text">
                    <h1>Isonga Admin</h1>
                    <p>RPSU Management System</p>
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
        <!-- Sidebar -->
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="programs.php"><i class="fas fa-graduation-cap"></i> Programs</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="content.php"><i class="fas fa-newspaper"></i> Content</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars($user['full_name'] ?? 'Admin'); ?>! 👋</h1>
                <p>Here's what's happening across your campus today.</p>
            </div>

            <!-- Stats Grid - Row 1 -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_users); ?></div>
                        <div class="stat-label">Active Users</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_students); ?></div>
                        <div class="stat-label">Active Students</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($resolved_tickets); ?></div>
                        <div class="stat-label">Issues Resolved</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_tickets; ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid - Row 2 -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-user-tie"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_committees; ?></div>
                        <div class="stat-label">Committee Members</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon teal"><i class="fas fa-calendar-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_events; ?></div>
                        <div class="stat-label">Upcoming Events</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red"><i class="fas fa-balance-scale"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_cases; ?></div>
                        <div class="stat-label">Pending Cases</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-bullhorn"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_announcements; ?></div>
                        <div class="stat-label">Announcements</div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Left Column -->
                <div>
                    <!-- Recent Announcements -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
                            <a href="content.php?tab=announcements" class="view-all">View All →</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_announcements)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bullhorn"></i>
                                    <p>No announcements yet</p>
                                </div>
                            <?php else: ?>
                                <div class="item-list">
                                    <?php foreach ($recent_announcements as $item): ?>
                                        <div class="list-item">
                                            <div class="item-icon"><i class="fas fa-bullhorn"></i></div>
                                            <div class="item-content">
                                                <div class="item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                                <div class="item-meta">
                                                    <span><i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                                                </div>
                                                <div class="item-excerpt"><?php echo htmlspecialchars(substr(strip_tags($item['excerpt'] ?? ''), 0, 80)); ?>...</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent News -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-newspaper"></i> Recent News</h3>
                            <a href="content.php?tab=news" class="view-all">View All →</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_news)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-newspaper"></i>
                                    <p>No news articles yet</p>
                                </div>
                            <?php else: ?>
                                <div class="item-list">
                                    <?php foreach ($recent_news as $item): ?>
                                        <div class="list-item">
                                            <div class="item-icon"><i class="fas fa-newspaper"></i></div>
                                            <div class="item-content">
                                                <div class="item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                                <div class="item-meta">
                                                    <span><i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                                                </div>
                                                <div class="item-excerpt"><?php echo htmlspecialchars(substr(strip_tags($item['excerpt'] ?? ''), 0, 80)); ?>...</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Upcoming Events -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-calendar-alt"></i> Upcoming Events</h3>
                            <a href="events.php" class="view-all">View All →</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_events_list)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-alt"></i>
                                    <p>No upcoming events</p>
                                </div>
                            <?php else: ?>
                                <div class="item-list">
                                    <?php foreach ($upcoming_events_list as $item): ?>
                                        <div class="list-item">
                                            <div class="item-icon"><i class="fas fa-calendar-check"></i></div>
                                            <div class="item-content">
                                                <div class="item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                                <div class="item-meta">
                                                    <span><i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($item['event_date'])); ?></span>
                                                    <?php if (!empty($item['start_time'])): ?>
                                                        <span><i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($item['start_time'])); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['location'])): ?>
                                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($item['location']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- System Overview -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-pie"></i> System Overview</h3>
                        </div>
                        <div class="card-body">
                            <div class="metrics-grid">
                                <div class="metric-item">
                                    <span class="metric-label">Departments</span>
                                    <span class="metric-value"><?php echo $total_departments; ?></span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Programs</span>
                                    <span class="metric-value"><?php echo $total_programs; ?></span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Active Clubs</span>
                                    <span class="metric-value"><?php echo $active_clubs; ?></span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Associations</span>
                                    <span class="metric-value"><?php echo $active_associations; ?></span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Gallery Images</span>
                                    <span class="metric-value"><?php echo $total_gallery; ?></span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Total Events</span>
                                    <span class="metric-value"><?php echo $total_events; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Tickets -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-ticket-alt"></i> Recent Support Tickets</h3>
                            <a href="tickets.php" class="view-all">View All →</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_tickets)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-ticket-alt"></i>
                                    <p>No tickets found</p>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr><th>Student</th><th>Subject</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_tickets as $ticket): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ticket['student_name'] ?? 'Unknown'); ?></td>
                                                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($ticket['subject'] ?? 'N/A'); ?></td>
                                                <td><span class="status-badge status-<?php echo str_replace('_', '-', $ticket['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Cases -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-balance-scale"></i> Recent Arbitration Cases</h3>
                            <a href="arbitration.php" class="view-all">View All →</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_cases)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-balance-scale"></i>
                                    <p>No cases found</p>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr><th>Case #</th><th>Title</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_cases as $case): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($case['case_number'] ?? '#' . $case['id']); ?></td>
                                                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($case['title']); ?></td>
                                                <td><span class="status-badge status-<?php echo str_replace('_', '-', $case['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Committee Members -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-users"></i> Committee Members</h3>
                            <a href="committee.php" class="view-all">Manage →</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($committee_members)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No committee members found</p>
                                </div>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.75rem;">
                                    <?php foreach ($committee_members as $member): ?>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; background: var(--bg-primary); border-radius: 10px;">
                                            <div style="width: 36px; height: 36px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.8rem;">
                                                <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; font-size: 0.75rem;"><?php echo htmlspecialchars($member['name']); ?></div>
                                                <div style="font-size: 0.65rem; color: var(--primary);"><?php echo htmlspecialchars($member['role']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activities)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> 
                                                    logged in as <?php echo ucfirst($activity['role']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, g:i A', strtotime($activity['login_time'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Performance Metrics -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-line"></i> Performance Metrics</h3>
                        </div>
                        <div class="card-body">
                            <div class="metrics-grid">
                                <div class="metric-item">
                                    <span class="metric-label">Ticket Resolution Rate</span>
                                    <span class="metric-value"><?php echo $resolution_rate; ?>%</span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Case Resolution Rate</span>
                                    <span class="metric-value"><?php echo $case_resolution_rate; ?>%</span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">New Users (30d)</span>
                                    <span class="metric-value"><?php echo $new_users_30days; ?></span>
                                </div>
                                <div class="metric-item">
                                    <span class="metric-label">Active Users (7d)</span>
                                    <span class="metric-value"><?php echo $active_users_7days; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <a href="users.php?action=add" class="action-btn">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Add User</span>
                                </a>
                                <a href="content.php?tab=announcements&action=add" class="action-btn">
                                    <i class="fas fa-bullhorn"></i>
                                    <span>Post Announcement</span>
                                </a>
                                <a href="events.php?action=add" class="action-btn">
                                    <i class="fas fa-calendar-plus"></i>
                                    <span>Create Event</span>
                                </a>
                                <a href="committee.php?action=add" class="action-btn">
                                    <i class="fas fa-user-tie"></i>
                                    <span>Add Committee</span>
                                </a>
                                <a href="reports.php" class="action-btn">
                                    <i class="fas fa-chart-pie"></i>
                                    <span>Generate Report</span>
                                </a>
                                <a href="settings.php" class="action-btn">
                                    <i class="fas fa-cog"></i>
                                    <span>Settings</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        // Check for saved theme preference
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        // Toggle theme
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