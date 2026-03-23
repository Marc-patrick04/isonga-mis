<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Environment & Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_environment') {
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

// Get dashboard statistics for Minister of Environment & Security
try {
    // Total environmental projects
    $stmt = $pdo->query("SELECT COUNT(*) as total_projects FROM innovation_projects WHERE category_id = 2 AND status IN ('pending_review', 'approved', 'in_progress')");
    $total_projects = $stmt->fetch(PDO::FETCH_ASSOC)['total_projects'] ?? 0;
    
    // Total campus facilities
    $stmt = $pdo->query("SELECT COUNT(*) as total_facilities FROM sports_facilities WHERE status = 'available'");
    $total_facilities = $stmt->fetch(PDO::FETCH_ASSOC)['total_facilities'] ?? 0;
    
    // Pending facility maintenance requests
    $stmt = $pdo->query("SELECT COUNT(*) as pending_maintenance FROM facility_bookings WHERE status = 'pending' AND purpose LIKE '%maintenance%'");
    $pending_maintenance = $stmt->fetch(PDO::FETCH_ASSOC)['pending_maintenance'] ?? 0;
    
    // Security incidents reported this month
    $stmt = $pdo->query("
        SELECT COUNT(*) as security_incidents 
        FROM tickets 
        WHERE category_id = 5 
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $security_incidents = $stmt->fetch(PDO::FETCH_ASSOC)['security_incidents'] ?? 0;
    
    // Active environmental clubs
    $stmt = $pdo->query("SELECT COUNT(*) as active_clubs FROM clubs WHERE category = 'environment' AND status = 'active'");
    $active_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['active_clubs'] ?? 0;
    
    // Upcoming environmental events
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_events 
        FROM events 
        WHERE category_id = 5 
        AND event_date >= CURDATE()
        AND status = 'published'
    ");
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_events'] ?? 0;

    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    // Recent environmental projects
    $stmt = $pdo->prepare("
        SELECT * FROM innovation_projects 
        WHERE category_id = 2 
        AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY submitted_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active environmental initiatives
    $stmt = $pdo->query("
        SELECT * FROM clubs 
        WHERE category = 'environment' 
        AND status = 'active'
        ORDER BY name
        LIMIT 6
    ");
    $active_initiatives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming environmental events
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name
        FROM events e
        LEFT JOIN event_categories ec ON e.category_id = ec.id
        WHERE e.category_id = 5
        AND e.event_date >= CURDATE()
        AND e.status = 'published'
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_events_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    // Facility maintenance status
    $stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as facility_count
        FROM sports_facilities 
        GROUP BY status
        ORDER BY facility_count DESC
    ");
    $facility_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Environmental project statistics
    $stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as project_count
        FROM innovation_projects 
        WHERE category_id = 2
        GROUP BY status
        ORDER BY project_count DESC
    ");
    $project_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Handle general error
    error_log("Minister of Environment & Security dashboard statistics error: " . $e->getMessage());
    $total_projects = $total_facilities = $pending_maintenance = $security_incidents = 0;
    $active_clubs = $upcoming_events = $unread_messages = 0;
    $recent_projects = $active_initiatives = $upcoming_events_list = $recent_activities = [];
    $facility_status = $project_status = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minister of Environment & Security Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #4caf50;
            --accent-green: #2e7d32;
            --light-green: #e8f5e9;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-green: #4caf50;
            --secondary-green: #66bb6a;
            --accent-green: #388e3c;
            --light-green: #1b5e20;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            color: var(--primary-green);
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
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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
            border-left: 3px solid var(--primary-green);
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
            background: var(--light-green);
            color: var(--primary-green);
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
            color: var(--primary-green);
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
            border-color: var(--primary-green);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-green);
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

        /* Initiative Stats */
        .initiative-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .initiative-stat {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .initiative-name {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .initiative-count {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-green);
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
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Environment & Security</h1>
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
                        <div class="user-role">Minister of Environment & Security</div>
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
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
                        <span class="member-count"><?php echo $total_projects; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="security.php">
                        <i class="fas fa-shield-alt"></i>
                        <span>Security</span>
                        <?php if ($security_incidents > 0): ?>
                            <span class="menu-badge"><?php echo $security_incidents; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                        <?php if ($pending_maintenance > 0): ?>
                            <span class="menu-badge"><?php echo $pending_maintenance; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Environmental Clubs</span>
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
                    <h1>Welcome, Environment Minister <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 🌱</h1>
                    <p>Oversee campus environment, security, and sustainability initiatives for <?php echo date('Y'); ?> academic year</p>
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
                        <i class="fas fa-leaf"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_projects; ?></div>
                        <div class="stat-label">Environmental Projects</div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_maintenance; ?></div>
                        <div class="stat-label">Maintenance Requests</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $security_incidents; ?></div>
                        <div class="stat-label">Security Incidents</div>
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
                        <div class="stat-number"><?php echo $active_clubs; ?></div>
                        <div class="stat-label">Environmental Clubs</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_events; ?></div>
                        <div class="stat-label">Upcoming Events</div>
                    </div>
                </div>


            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Active Environmental Initiatives -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Active Environmental Initiatives</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="clubs.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Initiative Name</th>
                                        <th>Category</th>
                                        <th>Department</th>
                                        <th>Members</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($active_initiatives)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; color: var(--dark-gray); padding: 2rem;">No active environmental initiatives</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($active_initiatives as $initiative): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($initiative['name']); ?></td>
                                                <td><?php echo htmlspecialchars(ucfirst($initiative['category'])); ?></td>
                                                <td><?php echo htmlspecialchars($initiative['department'] ?? 'N/A'); ?></td>
                                                <td><?php echo $initiative['members_count']; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $initiative['status']; ?>">
                                                        <?php echo ucfirst($initiative['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Upcoming Environmental Events -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Environmental Events</h3>
                            <div class="card-header-actions">
                                <a href="events.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_events_list)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-leaf" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No upcoming environmental events</p>
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

                    <!-- Recent Environmental Projects -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Environmental Projects</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_projects)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No recent environmental projects</p>
                                </div>
                            <?php else: ?>
                                <div class="initiative-stats">
                                    <?php foreach ($recent_projects as $project): ?>
                                        <div class="initiative-stat">
                                            <div class="initiative-name"><?php echo htmlspecialchars($project['title']); ?></div>
                                            <div class="initiative-count"><?php echo htmlspecialchars(ucfirst($project['status'])); ?></div>
                                            <div style="font-size: 0.6rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo date('M j', strtotime($project['submitted_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="projects.php?action=add" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span class="action-label">New Project</span>
                        </a>
                        <a href="events.php?action=schedule" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Schedule Event</span>
                        </a>
                        <a href="maintenance.php?action=create" class="action-btn">
                            <i class="fas fa-tools"></i>
                            <span class="action-label">Maintenance Request</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Environment Report</span>
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

                    <!-- Project Status Overview -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Project Status Overview</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($project_status)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No project data available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($project_status as $project): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars(ucfirst($project['status'])); ?></strong>
                                            </div>
                                            <div style="text-align: right;">
                                                <div style="font-size: 0.8rem; font-weight: 600;">
                                                    <?php echo $project['project_count']; ?> projects
                                                </div>
                                            </div>
                                        </div>
                                        <div class="attendance-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min(100, ($project['project_count'] / $total_projects) * 100); ?>%"></div>
                                            </div>
                                            <div class="progress-text">
                                                <span>Percentage</span>
                                                <span><?php echo min(100, round(($project['project_count'] / $total_projects) * 100)); ?>%</span>
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
                            <h3>Environment & Security Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo $unread_messages; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Maintenance Requests</span>
                                    <strong style="color: var(--text-dark);"><?php echo $pending_maintenance; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Initiatives</span>
                                    <strong style="color: var(--text-dark);"><?php echo $active_clubs; ?> clubs</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Events This Month</span>
                                    <strong style="color: var(--text-dark);"><?php echo $upcoming_events; ?> events</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Actions Alert -->
                    <?php if ($pending_maintenance > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Action Required:</strong> You have 
                            <a href="maintenance.php"><?php echo $pending_maintenance; ?> maintenance requests pending approval</a>.
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
            console.log('Environment & Security Dashboard auto-refresh triggered');
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