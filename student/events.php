<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$student_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$status_filter = $_GET['status'] ?? 'upcoming';
$search_query = $_GET['search'] ?? '';

// Build query for events with filters
$query = "
    SELECT e.*, 
           ec.name as category_name,
           ec.color as category_color,
           ec.icon as category_icon,
           CASE 
               WHEN e.event_date < CURRENT_DATE THEN 'past'
               WHEN e.event_date = CURRENT_DATE THEN 'today'
               ELSE 'upcoming'
           END as event_status
    FROM events e
    LEFT JOIN event_categories ec ON e.category_id = ec.id
    WHERE e.status = 'published'
";

$params = [];

// Add filters
if ($category_filter !== 'all') {
    $query .= " AND e.category_id = ?";
    $params[] = $category_filter;
}

if ($status_filter === 'upcoming') {
    $query .= " AND e.event_date >= CURRENT_DATE";
} elseif ($status_filter === 'past') {
    $query .= " AND e.event_date < CURRENT_DATE";
} elseif ($status_filter === 'today') {
    $query .= " AND e.event_date = CURRENT_DATE";
}

if (!empty($search_query)) {
    $query .= " AND (e.title ILIKE ? OR e.description ILIKE ? OR e.location ILIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY 
    CASE 
        WHEN e.event_date = CURRENT_DATE THEN 0
        WHEN e.event_date > CURRENT_DATE THEN 1
        ELSE 2
    END,
    e.event_date ASC,
    e.start_time ASC";

// Get filtered events
$events_stmt = $pdo->prepare($query);
$events_stmt->execute($params);
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get event categories for filters
$categories_stmt = $pdo->prepare("SELECT * FROM event_categories WHERE is_active = true ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count events by status
$count_upcoming = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date >= CURRENT_DATE");
$count_upcoming->execute();
$upcoming_count = $count_upcoming->fetchColumn();

$count_today = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date = CURRENT_DATE");
$count_today->execute();
$today_count = $count_today->fetchColumn();

$count_past = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date < CURRENT_DATE");
$count_past->execute();
$past_count = $count_past->fetchColumn();

$count_featured = $pdo->prepare("SELECT COUNT(*) FROM events WHERE is_featured = true AND status = 'published' AND event_date >= CURRENT_DATE");
$count_featured->execute();
$featured_count = $count_featured->fetchColumn();

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Campus Events - Isonga RPSU</title>
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
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
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
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
            width: 20px;
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
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Page Header */
        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--primary-blue);
        }

        .page-description {
            color: var(--dark-gray);
            margin-bottom: 1rem;
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
            border-radius: var(--border-radius);
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.active {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.25rem;
        }

        .stat-icon.upcoming { background: var(--light-blue); color: var(--primary-blue); }
        .stat-icon.today { background: #fff3cd; color: #856404; }
        .stat-icon.past { background: #e2e3e5; color: var(--dark-gray); }
        .stat-icon.featured { background: #f8d7da; color: var(--danger); }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-box {
            position: relative;
        }

        .search-box .form-control {
            padding-left: 2.5rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
        }

        /* Events Grid */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .event-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }

        .event-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .event-card.featured {
            border: 2px solid var(--warning);
        }

        .event-featured-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--warning);
            color: var(--text-dark);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 2;
        }

        .event-image {
            height: 180px;
            background: var(--gradient-primary);
            position: relative;
            overflow: hidden;
        }

        .event-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .event-image .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            opacity: 0.7;
        }

        .event-category {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: rgba(0, 0, 0, 0.7);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .event-content {
            padding: 1rem;
        }

        .event-date {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .date-badge {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 55px;
            height: 55px;
            background: var(--light-blue);
            border-radius: var(--border-radius);
            text-align: center;
        }

        .date-day {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1;
        }

        .date-month {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .event-time {
            flex: 1;
        }

        .event-time div {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .event-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .event-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            line-height: 1.5;
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .event-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--dark-gray);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .event-location, .event-organizer {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .event-status {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-upcoming { background: #d4edda; color: #155724; }
        .status-today { background: #fff3cd; color: #856404; }
        .status-past { background: #e2e3e5; color: #383d41; }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
            background: var(--white);
            border-radius: var(--border-radius);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .empty-state p {
            font-size: 0.85rem;
        }

        /* Section Header */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary-blue);
        }

        .event-count {
            font-size: 0.8rem;
            color: var(--dark-gray);
            font-weight: normal;
        }

        /* Overlay */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(2px);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
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

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .events-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo">
                <div class="brand-text">
                    <h1>Isonga RPSU</h1>
                </div>
            </div>
            <div class="user-menu">
                <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                    <i class="fas fa-envelope"></i>
                    <?php if ($unread_messages > 0): ?>
                        <span class="notification-badge"><?php echo $unread_messages; ?></span>
                    <?php endif; ?>
                </a>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></div>
                        <div class="user-role">Student</div>
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>My Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_aid.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                        <?php if ($upcoming_count > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
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
                <?php if ($is_class_rep): ?>
                <li class="menu-item">
                    <a href="class_rep_dashboard.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Dashboard</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-calendar-alt"></i>
                    Campus Events
                </h1>
                <p class="page-description">Discover upcoming activities and events on campus</p>
            </div>

            <!-- Event Statistics -->
            <div class="stats-grid">
                <div class="stat-card <?php echo $status_filter === 'upcoming' ? 'active' : ''; ?>" onclick="window.location.href='events.php?status=upcoming&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon upcoming">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="stat-number"><?php echo $upcoming_count; ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card <?php echo $status_filter === 'today' ? 'active' : ''; ?>" onclick="window.location.href='events.php?status=today&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon today">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-number"><?php echo $today_count; ?></div>
                    <div class="stat-label">Today's Events</div>
                </div>
                <div class="stat-card <?php echo $status_filter === 'past' ? 'active' : ''; ?>" onclick="window.location.href='events.php?status=past&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon past">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-number"><?php echo $past_count; ?></div>
                    <div class="stat-label">Past Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon featured">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-number"><?php echo $featured_count; ?></div>
                    <div class="stat-label">Featured Events</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="events.php">
                    <div class="filter-grid">
                        <div class="form-group search-box">
                            <label class="form-label">Search</label>
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" class="form-control" placeholder="Search events..." value="<?php echo safe_display($search_query); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="today" <?php echo $status_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="past" <?php echo $status_filter === 'past' ? 'selected' : ''; ?>>Past</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Events</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-control">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo safe_display($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="events.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Events Grid -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-calendar-alt"></i>
                    <?php
                    if ($status_filter === 'upcoming') echo 'Upcoming Events';
                    elseif ($status_filter === 'today') echo 'Today\'s Events';
                    elseif ($status_filter === 'past') echo 'Past Events';
                    else echo 'All Events';
                    ?>
                    <span class="event-count">(<?php echo count($events); ?> events)</span>
                </h3>
            </div>

            <?php if (empty($events)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No events found</h3>
                    <p>No events match your current filters. Try adjusting your search criteria or check back later for new events.</p>
                </div>
            <?php else: ?>
                <div class="events-grid">
                    <?php foreach ($events as $event): ?>
                        <div class="event-card <?php echo $event['is_featured'] ? 'featured' : ''; ?>">
                            <?php if ($event['is_featured']): ?>
                                <div class="event-featured-badge">
                                    <i class="fas fa-star"></i> Featured
                                </div>
                            <?php endif; ?>
                            <div class="event-image">
                                <?php if ($event['image_url']): ?>
                                    <img src="<?php echo safe_display($event['image_url']); ?>" alt="<?php echo safe_display($event['title']); ?>">
                                <?php else: ?>
                                    <div class="placeholder">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="event-category" style="background: <?php echo $event['category_color'] ?? '#3B82F6'; ?>">
                                    <i class="fas fa-<?php echo $event['category_icon'] ?? 'calendar'; ?>"></i>
                                    <?php echo safe_display($event['category_name']); ?>
                                </div>
                            </div>
                            <div class="event-content">
                                <div class="event-date">
                                    <div class="date-badge">
                                        <div class="date-day"><?php echo date('j', strtotime($event['event_date'])); ?></div>
                                        <div class="date-month"><?php echo date('M', strtotime($event['event_date'])); ?></div>
                                    </div>
                                    <div class="event-time">
                                        <div><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                            <?php if ($event['end_time']): ?> - <?php echo date('g:i A', strtotime($event['end_time'])); ?><?php endif; ?>
                                        </div>
                                        <div><i class="fas fa-map-marker-alt"></i> <?php echo safe_display($event['location']); ?></div>
                                    </div>
                                </div>
                                <h3 class="event-title"><?php echo safe_display($event['title']); ?></h3>
                                <p class="event-description"><?php echo safe_display($event['excerpt'] ?? $event['description']); ?></p>
                                
                                <div class="event-meta">
                                    <div class="event-organizer">
                                        <i class="fas fa-user"></i>
                                        <?php echo safe_display($event['organizer']) ?: 'College Administration'; ?>
                                    </div>
                                    <div class="event-status status-<?php echo $event['event_status']; ?>">
                                        <?php echo ucfirst($event['event_status']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Auto-focus search input if there's a search term
        <?php if (!empty($search_query)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>