<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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

// Get dashboard statistics for Minister of Gender
try {
    // Total gender-related tickets
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_tickets 
        FROM tickets 
        WHERE category_id = 7 AND status IN ('open', 'in_progress')
    ");
    $stmt->execute();
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Total students (for gender statistics)
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    // Gender distribution
    $stmt = $pdo->query("SELECT gender, COUNT(*) as count FROM users WHERE role = 'student' AND status = 'active' GROUP BY gender");
    $gender_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate male/female percentages
    $male_count = 0;
    $female_count = 0;
    foreach ($gender_distribution as $gender) {
        if ($gender['gender'] === 'male') {
            $male_count = $gender['count'];
        } elseif ($gender['gender'] === 'female') {
            $female_count = $gender['count'];
        }
    }
    
    $male_percentage = $total_students > 0 ? round(($male_count / $total_students) * 100) : 0;
    $female_percentage = $total_students > 0 ? round(($female_count / $total_students) * 100) : 0;
    
    // Upcoming gender-related events
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_events 
        FROM events 
        WHERE category_id IN (3, 5) AND event_date >= CURDATE() AND status = 'published'
    ");
    $stmt->execute();
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_events'] ?? 0;
    
    // Pending gender-related reports
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_reports 
        FROM reports 
        WHERE report_type IN ('incident', 'activity') AND status = 'draft'
    ");
    $stmt->execute();
    $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    
    // Recent gender-related activities
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id
        WHERE e.category_id IN (3, 5) 
        AND e.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY e.event_date DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent gender-related tickets
    $stmt = $pdo->prepare("
        SELECT t.*, ic.name as category_name
        FROM tickets t
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        WHERE t.category_id = 7
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming meetings
    $stmt = $pdo->prepare("
        SELECT * FROM meetings 
        WHERE meeting_date >= CURDATE() 
        AND status = 'scheduled'
        ORDER BY meeting_date ASC, start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activities (login activities)
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        ORDER BY la.login_time DESC 
        LIMIT 8
    ");
    $recent_logins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Unread messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
    // Gender-related clubs
    $stmt = $pdo->query("
        SELECT COUNT(*) as gender_clubs 
        FROM clubs 
        WHERE category IN ('cultural', 'other') AND status = 'active'
    ");
    $gender_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['gender_clubs'] ?? 0;
    
    // Committee members by gender
    $stmt = $pdo->query("
        SELECT u.gender, COUNT(*) as count 
        FROM committee_members cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.status = 'active'
        GROUP BY u.gender
    ");
    $committee_gender = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate committee gender diversity
    $committee_male = 0;
    $committee_female = 0;
    foreach ($committee_gender as $gender) {
        if ($gender['gender'] === 'male') {
            $committee_male = $gender['count'];
        } elseif ($gender['gender'] === 'female') {
            $committee_female = $gender['count'];
        }
    }
    
    $total_committee = $committee_male + $committee_female;
    $committee_diversity = $total_committee > 0 ? round(min($committee_male, $committee_female) / $total_committee * 100) : 0;
    
} catch (PDOException $e) {
    // Handle general error
    error_log("Minister of Gender dashboard statistics error: " . $e->getMessage());
    $total_tickets = $total_students = $upcoming_events = $pending_reports = 0;
    $male_percentage = $female_percentage = $gender_clubs = $committee_diversity = 0;
    $recent_activities = $recent_tickets = $upcoming_meetings = $recent_logins = [];
}
$unread_messages= 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minister of Gender & Protocol Dashboard - Isonga RPSU</title>
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
            --male-color: #3B82F6;
            --female-color: #EC4899;
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
            --male-color: #60a5fa;
            --female-color: #f472b6;
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

        .stat-card.male {
            border-left-color: var(--male-color);
        }

        .stat-card.female {
            border-left-color: var(--female-color);
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

        .stat-card.male .stat-icon {
            background: #dbeafe;
            color: var(--male-color);
        }

        .stat-card.female .stat-icon {
            background: #fce7f3;
            color: var(--female-color);
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-purple);
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

        /* Gender Distribution */
        .gender-distribution {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .gender-stat {
            flex: 1;
            text-align: center;
            padding: 1rem;
            border-radius: var(--border-radius);
        }

        .gender-male {
            background: #dbeafe;
            color: var(--male-color);
        }

        .gender-female {
            background: #fce7f3;
            color: var(--female-color);
        }

        .gender-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .gender-label {
            font-size: 0.8rem;
            font-weight: 500;
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
            border-radius: 3px;
        }

        .progress-male {
            background: var(--male-color);
        }

        .progress-female {
            background: var(--female-color);
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
                    <h1>Isonga - Minister of Gender & Protocol</h1>
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
                        <div class="user-role">Minister of Gender & Protocol</div>
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
                <span>Gender Issues</span>
                <?php if ($total_tickets > 0): ?>
                    <span class="menu-badge"><?php echo $total_tickets; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="menu-item">
            <a href="protocol.php">
                <i class="fas fa-handshake"></i>
                <span>Protocol & Visitors</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="clubs.php">
                <i class="fas fa-users"></i>
                <span>Gender Clubs</span>
            </a>
        </li>

        <li class="menu-item">
            <a href="hostel-management.php">
                <i class="fas fa-building"></i>
                <span>Hostel Management</span>
            </a>
        </li>
        
        <!-- Added Action Funding -->
        <li class="menu-item">
            <a href="action-funding.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Action Funding</span>
            </a>
        </li>
        
        <li class="menu-item">
            <a href="reports.php">
                <i class="fas fa-file-alt"></i>
                <span>Reports & Analytics</span>
                <?php if ($pending_reports > 0): ?>
                    <span class="menu-badge"><?php echo $pending_reports; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="menu-item">
            <a href="meetings.php">
                <i class="fas fa-calendar-alt"></i>
                <span>Meetings</span>
                <?php if (count($upcoming_meetings) > 0): ?>
                    <span class="menu-badge"><?php echo count($upcoming_meetings); ?></span>
                <?php endif; ?>
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
                    <h1>Welcome, Minister of Gender & Protocol <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 👩‍⚖️</h1>
                    <p>Promoting gender equality, empowerment, and managing protocol for <?php echo date('Y'); ?> academic year</p>
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
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card male">
                    <div class="stat-icon">
                        <i class="fas fa-male"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $male_percentage; ?>%</div>
                        <div class="stat-label">Male Students</div>
                    </div>
                </div>
                <div class="stat-card female">
                    <div class="stat-icon">
                        <i class="fas fa-female"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $female_percentage; ?>%</div>
                        <div class="stat-label">Female Students</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_tickets; ?></div>
                        <div class="stat-label">Gender Issues</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_events; ?></div>
                        <div class="stat-label">Upcoming Events</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_reports; ?></div>
                        <div class="stat-label">Pending Reports</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $gender_clubs; ?></div>
                        <div class="stat-label">Gender Clubs</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $committee_diversity; ?>%</div>
                        <div class="stat-label">Committee Diversity</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Gender Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Gender Activities</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>

                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activities)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-calendar" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No recent gender activities</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Event Name</th>
                                            <th>Category</th>
                                            <th>Date</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($activity['title']); ?></td>
                                                <td><?php echo htmlspecialchars($activity['category_name']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($activity['event_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($activity['location']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $activity['status']; ?>">
                                                        <?php echo ucfirst($activity['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Gender Issues -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Gender Issues</h3>
                            <div class="card-header-actions">
                                <a href="tickets.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_tickets)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No recent gender issues reported</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Subject</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_tickets as $ticket): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ticket['name']); ?></td>
                                                <td><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                                        <?php echo ucfirst($ticket['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Gender Distribution -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Student Gender Distribution</h3>
                        </div>
                        <div class="card-body">
                            <div class="gender-distribution">
                                <div class="gender-stat gender-male">
                                    <div class="gender-number"><?php echo $male_percentage; ?>%</div>
                                    <div class="gender-label">Male Students</div>
                                    <div class="progress-bar">
                                        <div class="progress-fill progress-male" style="width: <?php echo $male_percentage; ?>%"></div>
                                    </div>
                                    <div class="progress-text">
                                        <span><?php echo $male_count; ?> students</span>
                                    </div>
                                </div>
                                <div class="gender-stat gender-female">
                                    <div class="gender-number"><?php echo $female_percentage; ?>%</div>
                                    <div class="gender-label">Female Students</div>
                                    <div class="progress-bar">
                                        <div class="progress-fill progress-female" style="width: <?php echo $female_percentage; ?>%"></div>
                                    </div>
                                    <div class="progress-text">
                                        <span><?php echo $female_count; ?> students</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="tickets.php?action=add" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span class="action-label">New Issue</span>
                        </a>
                        <a href="events.php?action=create" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Plan Event</span>
                        </a>
                        <a href="protocol.php?action=visitor" class="action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span class="action-label">Add Visitor</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Gender Report</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent System Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent System Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_logins)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent activities</li>
                                <?php else: ?>
                                    <?php foreach ($recent_logins as $activity): ?>
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

                    <!-- Upcoming Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Meetings</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_meetings)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No upcoming meetings</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming_meetings as $meeting): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($meeting['meeting_type']); ?></div>
                                            </div>
                                            <div style="text-align: right;">
                                                <div style="font-size: 0.8rem; font-weight: 600;">
                                                    <?php echo date('M j', strtotime($meeting['meeting_date'])); ?>
                                                </div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo date('g:i A', strtotime($meeting['start_time'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($meeting['location']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Gender Program Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo $unread_messages; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Reports</span>
                                    <strong style="color: var(--text-dark);"><?php echo $pending_reports; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Gender Clubs</span>
                                    <strong style="color: var(--text-dark);"><?php echo $gender_clubs; ?> clubs</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Upcoming Events</span>
                                    <strong style="color: var(--text-dark);"><?php echo $upcoming_events; ?> events</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Actions Alert -->
                    <?php if ($total_tickets > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Action Required:</strong> You have 
                            <a href="tickets.php"><?php echo $total_tickets; ?> gender issues pending resolution</a>.
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
            console.log('Gender Dashboard auto-refresh triggered');
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