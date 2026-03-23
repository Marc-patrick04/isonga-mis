<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Health
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_health') {
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

// Get dashboard statistics for Minister of Health
try {
    // Health-related tickets (assuming category_id 4 is for health)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as health_tickets 
        FROM tickets 
        WHERE category_id = 4 
        AND status IN ('open', 'in_progress')
    ");
    $stmt->execute();
    $health_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['health_tickets'] ?? 0;
    
    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    // Students in hostels
    $stmt = $pdo->query("
        SELECT COUNT(*) as hostel_students 
        FROM hostel_allocations 
        WHERE status = 'active' AND check_out_date IS NULL
    ");
    $hostel_students = $stmt->fetch(PDO::FETCH_ASSOC)['hostel_students'] ?? 0;
    
    // Pending hostel requests (assuming tickets with category_id 2 are for accommodation)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_hostel 
        FROM tickets 
        WHERE category_id = 2 
        AND status IN ('open', 'in_progress')
    ");
    $stmt->execute();
    $pending_hostel = $stmt->fetch(PDO::FETCH_ASSOC)['pending_hostel'] ?? 0;
    
    // Health awareness campaigns (using events table if exists, otherwise placeholder)
    $health_campaigns = 0;
    try {
        // Check if events table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'events'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as health_campaigns 
                FROM events 
                WHERE (category_id IN (SELECT id FROM event_categories WHERE name LIKE '%health%' OR name LIKE '%medical%')
                OR title LIKE '%health%' OR title LIKE '%medical%')
                AND event_date >= CURDATE()
            ");
            $stmt->execute();
            $health_campaigns = $stmt->fetch(PDO::FETCH_ASSOC)['health_campaigns'] ?? 0;
        }
    } catch (Exception $e) {
        // Events table doesn't exist or has issues
        error_log("Events table error: " . $e->getMessage());
    }
    

    
    // Recent health incidents (using tickets for health issues)
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name, d.name as department_name
        FROM tickets t
        JOIN users u ON t.reg_number = u.reg_number
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE t.category_id = 4  -- Health category
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming health events (if events table exists)
    $upcoming_events = [];
    try {
        // Check if events table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'events'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT e.*, ec.name as category_name
                FROM events e
                LEFT JOIN event_categories ec ON e.category_id = ec.id
                WHERE (ec.name LIKE '%health%' OR ec.name LIKE '%medical%' OR e.title LIKE '%health%' OR e.title LIKE '%medical%')
                AND e.event_date >= CURDATE()
                AND e.status = 'published'
                ORDER BY e.event_date ASC
                LIMIT 5
            ");
            $stmt->execute();
            $upcoming_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Events table doesn't exist or has issues
        error_log("Events table error: " . $e->getMessage());
    }
    
    // Recent health-related tickets
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name, d.name as department_name
        FROM tickets t
        JOIN users u ON t.reg_number = u.reg_number
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE t.category_id = 4  -- Health category
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activities (login activities if table exists)
    $recent_activities = [];
    try {
        // Check if login_activities table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'login_activities'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("
                SELECT la.*, u.full_name, u.role 
                FROM login_activities la 
                JOIN users u ON la.user_id = u.id 
                ORDER BY la.login_time DESC 
                LIMIT 8
            ");
            $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Login activities table doesn't exist or has issues
        error_log("Login activities table error: " . $e->getMessage());
    }
    
    // Unread messages (if conversation system exists)
    $unread_messages = 0;
    try {
        // Check if conversation tables exist
        $stmt = $pdo->query("SHOW TABLES LIKE 'conversation_messages'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as unread_messages 
                FROM conversation_messages cm
                JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
                WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
            ");
            $stmt->execute([$user_id]);
            $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
        }
    } catch (Exception $e) {
        // Conversation tables don't exist or have issues
        error_log("Conversation tables error: " . $e->getMessage());
    }
    
    // Health statistics by department
    $stmt = $pdo->query("
        SELECT 
            d.name as department_name,
            COUNT(t.id) as health_issues
        FROM tickets t
        JOIN users u ON t.reg_number = u.reg_number
        JOIN departments d ON u.department_id = d.id
        WHERE t.category_id = 4  -- Health issues
        AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY d.id, d.name
        ORDER BY health_issues DESC
        LIMIT 5
    ");
    $health_by_department = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Handle general error
    error_log("Minister of Health dashboard statistics error: " . $e->getMessage());
    $health_tickets = $total_students = $hostel_students = $pending_hostel = 0;
    $recent_incidents = $upcoming_events = $recent_tickets = $recent_activities = [];
    $health_by_department = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minister of Health Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #20c997;
            --accent-green: #198754;
            --light-green: #d1f2eb;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-green: #20c997;
            --secondary-green: #3dd9a7;
            --accent-green: #198754;
            --light-green: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
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

        .stat-card.info {
            border-left-color: var(--info);
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

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
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

        .status-open {
            background: #cce7ff;
            color: var(--info);
        }

        .status-in_progress {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-closed {
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

        /* Department Stats */
        .department-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .department-stat {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .department-name {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .department-count {
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
                    <h1>Isonga - Minister of Health</h1>
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
                        <div class="user-role">Minister of Health</div>
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
                    <a href="health_tickets.php">
                        <i class="fas fa-heartbeat"></i>
                        <span>Health Issues</span>
                        <?php if ($health_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $health_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostels.php">
                        <i class="fas fa-bed"></i>
                        <span>Hostel Management</span>
                        <?php if ($pending_hostel > 0): ?>
                            <span class="menu-badge"><?php echo $pending_hostel; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="campaigns.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Health Campaigns</span>
                        <?php if ($health_campaigns > 0): ?>
                            <span class="menu-badge"><?php echo $health_campaigns; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="epidemics.php">
                        <i class="fas fa-virus"></i>
                        <span>Epidemic Prevention</span>
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
                    <h1>Welcome, Health Minister <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 🏥</h1>
                    <p>Manage student health, welfare, and social affairs for <?php echo date('Y'); ?> academic year</p>
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
                        <i class="fas fa-user-injured"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $health_tickets; ?></div>
                        <div class="stat-label">Health Issues</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_hostel; ?></div>
                        <div class="stat-label">Pending Hostel Requests</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $health_campaigns; ?></div>
                        <div class="stat-label">Health Campaigns</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($upcoming_events); ?></div>
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
                            $health_percentage = $total_students > 0 ? round(($health_tickets / $total_students) * 100) : 0;
                            echo $health_percentage; 
                            ?>%
                        </div>
                        <div class="stat-label">Health Issue Rate</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Health Issues -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Health & Welfare Issues</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="health_tickets.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Issue Type</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_tickets)): ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent health issues</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_tickets as $ticket): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ticket['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Upcoming Health Events -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Health Events</h3>
                            <div class="card-header-actions">
                                <a href="campaigns.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_events)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No upcoming health events</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Event</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Category</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_events as $event): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($event['title']); ?></td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($event['event_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($event['start_time'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($event['location']); ?></td>
                                                <td><?php echo htmlspecialchars($event['category_name']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Health Issues by Department -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Health Issues by Department</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($health_by_department)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No health issues data by department</p>
                                </div>
                            <?php else: ?>
                                <div class="department-stats">
                                    <?php foreach ($health_by_department as $dept): ?>
                                        <div class="department-stat">
                                            <div class="department-name"><?php echo htmlspecialchars($dept['department_name']); ?></div>
                                            <div class="department-count"><?php echo $dept['health_issues']; ?></div>
                                            <div style="font-size: 0.6rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                issues
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="health_tickets.php?action=add" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span class="action-label">New Health Issue</span>
                        </a>
                        <a href="hostels.php?action=assign" class="action-btn">
                            <i class="fas fa-bed"></i>
                            <span class="action-label">Assign Hostel</span>
                        </a>
                        <a href="campaigns.php?action=create" class="action-btn">
                            <i class="fas fa-bullhorn"></i>
                            <span class="action-label">Health Campaign</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Health Report</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">


                    <!-- Health Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Health & Welfare Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo $unread_messages; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Hostel Requests</span>
                                    <strong style="color: var(--text-dark);"><?php echo $pending_hostel; ?></strong>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Health Campaigns</span>
                                    <strong style="color: var(--text-dark);"><?php echo $health_campaigns; ?> events</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Actions Alert -->
                    <?php if ($health_tickets > 0 || $pending_hostel > 0 || $restaurant_complaints > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Action Required:</strong> You have 
                            <?php
                            $pending_items = [];
                            if ($health_tickets > 0) $pending_items[] = "<a href='health_tickets.php'>$health_tickets health issues</a>";


                            
                            echo implode(', ', $pending_items);
                            ?> pending.
                        </div>
                    <?php endif; ?>

                    <!-- Recent Health Incidents -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Health Incidents</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_incidents)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No recent health incidents</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_incidents as $incident): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($incident['subject']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo date('M j, Y', strtotime($incident['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div style="text-align: right;">
                                                <span class="status-badge status-<?php echo $incident['status']; ?>" style="font-size: 0.6rem;">
                                                    <?php echo ucfirst($incident['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-dark);">
                                            <?php 
                                            $description = htmlspecialchars($incident['description']);
                                            echo strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description;
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
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
            console.log('Health Dashboard auto-refresh triggered');
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