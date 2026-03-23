<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user needs to change password (first login)
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// Get dashboard statistics for Minister of Public Relations
try {
    // Total announcements
    $stmt = $pdo->query("SELECT COUNT(*) as total_announcements FROM announcements");
    $total_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['total_announcements'] ?? 0;
    
    // Total news articles
    $stmt = $pdo->query("SELECT COUNT(*) as total_news FROM news WHERE status = 'published'");
    $total_news = $stmt->fetch(PDO::FETCH_ASSOC)['total_news'] ?? 0;
    
    // Total events
    $stmt = $pdo->query("SELECT COUNT(*) as total_events FROM events WHERE status = 'published'");
    $total_events = $stmt->fetch(PDO::FETCH_ASSOC)['total_events'] ?? 0;
    
    // Total gallery images
    $stmt = $pdo->query("SELECT COUNT(*) as total_gallery FROM gallery_images WHERE status = 'active'");
    $total_gallery = $stmt->fetch(PDO::FETCH_ASSOC)['total_gallery'] ?? 0;
    
    // Total committee members
    $stmt = $pdo->query("SELECT COUNT(*) as total_committee FROM committee_members WHERE status = 'active'");
    $total_committee = $stmt->fetch(PDO::FETCH_ASSOC)['total_committee'] ?? 0;
    
    // Total associations (clubs with cultural category)
    $stmt = $pdo->query("SELECT COUNT(*) as total_associations FROM clubs WHERE category = 'cultural' AND status = 'active'");
    $total_associations = $stmt->fetch(PDO::FETCH_ASSOC)['total_associations'] ?? 0;
    
    // Upcoming events
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_events 
        FROM events 
        WHERE event_date >= CURDATE()
        AND status = 'published'
    ");
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_events'] ?? 0;

    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    // Recent announcements
    $stmt = $pdo->prepare("
        SELECT * FROM announcements 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent news
    $stmt = $pdo->prepare("
        SELECT n.*, nc.name as category_name
        FROM news n
        LEFT JOIN news_categories nc ON n.category_id = nc.id
        WHERE n.status = 'published'
        ORDER BY n.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_news = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming events
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name
        FROM events e
        LEFT JOIN event_categories ec ON e.category_id = ec.id
        WHERE e.event_date >= CURDATE()
        AND e.status = 'published'
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_events_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active associations (clubs)
    $stmt = $pdo->query("
        SELECT * FROM clubs 
        WHERE category = 'cultural' 
        AND status = 'active'
        ORDER BY name
        LIMIT 6
    ");
    $active_associations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activities (login activities)
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        ORDER BY la.login_time DESC 
        LIMIT 8
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Unread messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
    // News statistics by category
    $stmt = $pdo->query("
        SELECT 
            nc.name as category_name,
            COUNT(*) as news_count
        FROM news n
        LEFT JOIN news_categories nc ON n.category_id = nc.id
        WHERE n.status = 'published'
        GROUP BY n.category_id, nc.name
        ORDER BY news_count DESC
    ");
    $news_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Event statistics by category
    $stmt = $pdo->query("
        SELECT 
            ec.name as category_name,
            COUNT(*) as event_count
        FROM events e
        LEFT JOIN event_categories ec ON e.category_id = ec.id
        WHERE e.status = 'published'
        GROUP BY e.category_id, ec.name
        ORDER BY event_count DESC
    ");
    $event_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Handle general error
    error_log("Minister of Public Relations dashboard statistics error: " . $e->getMessage());
    $total_announcements = $total_news = $total_events = $total_gallery = 0;
    $total_committee = $total_associations = $upcoming_events = $unread_messages = 0;
    $recent_announcements = $recent_news = $upcoming_events_list = $active_associations = $recent_activities = [];
    $news_stats = $event_stats = [];
}
$total_students = 0; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minister of Public Relations & Associations Dashboard - Isonga RPSU</title>
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
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
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
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--primary-blue);
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
            background: var(--light-blue);
            color: var(--primary-blue);
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

        /* Content Grid */
        .content-grid {
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

        .status-upcoming {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-ongoing {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.7rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--primary-blue);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.75rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert a {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* Association Stats */
        .association-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .association-stat {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .association-name {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .association-count {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Progress Bars */
        .progress-bar {
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
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
            
            .quick-actions {
                grid-template-columns: 1fr;
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
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Public Relations & Associations</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
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
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Student Tickets</span>
                        <?php 
                        // Get pending tickets count for this minister
                        try {
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as pending_tickets 
                                FROM tickets 
                                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
                            ");
                            $stmt->execute([$user_id]);
                            $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
                            
                            if ($pending_tickets > 0): ?>
                                <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                            <?php endif;
                        } catch (PDOException $e) {
                            // Skip badge if error
                        }
                        ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                        <span class="member-count"><?php echo $total_announcements; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                        <span class="member-count"><?php echo $total_news; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                        <span class="member-count"><?php echo $total_events; ?></span>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                        <span class="member-count"><?php echo $total_gallery; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                        <span class="member-count"><?php echo $total_associations; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
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
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Welcome, Public Relations Minister <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 📢</h1>
                    <p>Manage communications, media relations, and student associations for <?php echo date('Y'); ?> academic year</p>
                </div>
            </div>

            <?php if ($password_change_required): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Action Required:</strong> Please <a href="profile.php?tab=security">change your password</a> for security reasons.
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_announcements; ?></div>
                        <div class="stat-label">Announcements</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_news; ?></div>
                        <div class="stat-label">News Articles</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_events; ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-images"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_gallery; ?></div>
                        <div class="stat-label">Gallery Images</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_committee; ?></div>
                        <div class="stat-label">Committee Members</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-church"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_associations; ?></div>
                        <div class="stat-label">Student Associations</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_events; ?></div>
                        <div class="stat-label">Upcoming Events</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $engagement_rate = $total_students > 0 ? round(($total_associations * 15 / $total_students) * 100) : 0;
                            echo $engagement_rate; 
                            ?>%
                        </div>
                        <div class="stat-label">Student Engagement</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Announcements -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Announcements</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="announcements.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_announcements)): ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--dark-gray); padding: 2rem;">No announcements found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_announcements as $announcement): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($announcement['title']); ?></td>
                                                <td>
                                                    <?php 
                                                    // Get author name
                                                    try {
                                                        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                                                        $stmt->execute([$announcement['author_id']]);
                                                        $author = $stmt->fetch(PDO::FETCH_ASSOC);
                                                        echo htmlspecialchars($author['full_name'] ?? 'Unknown');
                                                    } catch (PDOException $e) {
                                                        echo 'Unknown';
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($announcement['created_at'])); ?></td>
                                                <td>
                                                    <a href="announcements.php?action=view&id=<?php echo $announcement['id']; ?>" class="card-header-btn" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Upcoming Events -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Events</h3>
                            <div class="card-header-actions">
                                <a href="events.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_events_list)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No upcoming events</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Event</th>
                                            <th>Category</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_events_list as $event): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($event['title']); ?></td>
                                                <td><?php echo htmlspecialchars($event['category_name']); ?></td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($event['event_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($event['start_time'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($event['location']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Active Associations -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Active Student Associations</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($active_associations)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No active associations</p>
                                </div>
                            <?php else: ?>
                                <div class="association-stats">
                                    <?php foreach ($active_associations as $association): ?>
                                        <div class="association-stat">
                                            <div class="association-name"><?php echo htmlspecialchars($association['name']); ?></div>
                                            <div class="association-count"><?php echo $association['members_count']; ?> members</div>
                                            <div style="font-size: 0.6rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($association['category']); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="announcements.php?action=create" class="action-btn">
                            <i class="fas fa-bullhorn"></i>
                            <span class="action-label">New Announcement</span>
                        </a>
                        <a href="news.php?action=create" class="action-btn">
                            <i class="fas fa-newspaper"></i>
                            <span class="action-label">Create News</span>
                        </a>
                        <a href="events.php?action=create" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Schedule Event</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Media Report</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent System Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent activities</li>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> logged in
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, g:i A', strtotime($activity['login_time'])); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- News Categories Overview -->
                    <div class="card">
                        <div class="card-header">
                            <h3>News by Category</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($news_stats)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No news data available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($news_stats as $news): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($news['category_name']); ?></strong>
                                            </div>
                                            <div style="text-align: right;">
                                                <div style="font-size: 0.8rem; font-weight: 600;">
                                                    <?php echo $news['news_count']; ?> articles
                                                </div>
                                            </div>
                                        </div>
                                        <div class="attendance-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min(100, ($news['news_count'] / $total_news) * 100); ?>%"></div>
                                            </div>
                                            <div class="progress-text">
                                                <span>Percentage</span>
                                                <span><?php echo min(100, round(($news['news_count'] / $total_news) * 100)); ?>%</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Public Relations Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo $unread_messages; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Tickets</span>
                                    <strong style="color: var(--text-dark);">
                                        <?php 
                                        try {
                                            $stmt = $pdo->prepare("
                                                SELECT COUNT(*) as pending_tickets 
                                                FROM tickets 
                                                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
                                            ");
                                            $stmt->execute([$user_id]);
                                            $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
                                            echo $pending_tickets;
                                        } catch (PDOException $e) {
                                            echo '0';
                                        }
                                        ?>
                                    </strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Associations</span>
                                    <strong style="color: var(--text-dark);"><?php echo $total_associations; ?> groups</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Events This Month</span>
                                    <strong style="color: var(--text-dark);"><?php echo $upcoming_events; ?> events</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent News -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent News</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_news)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No recent news</p>
                                </div>
                            <?php else: ?>
                                <ul class="activity-list">
                                    <?php foreach ($recent_news as $news): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar" style="background: var(--light-blue); color: var(--primary-blue);">
                                                <i class="fas fa-newspaper"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($news['title']); ?></strong>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, Y', strtotime($news['created_at'])); ?> • 
                                                    <?php echo htmlspecialchars($news['category_name']); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        // Check for saved theme preference or respect OS preference
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

        // Auto-refresh dashboard every 3 minutes
        setInterval(() => {
            // You can add auto-refresh logic here
            console.log('Public Relations Dashboard auto-refresh triggered');
        }, 180000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>