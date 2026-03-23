<?php
session_start();
require_once 'config/database.php';

// Get all event categories
try {
    $categories_stmt = $pdo->query("SELECT * FROM event_categories WHERE is_active = 1 ORDER BY name");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// Get event type from URL or default to 'upcoming'
$event_type = isset($_GET['type']) ? $_GET['type'] : 'upcoming';
$current_category = isset($_GET['category']) ? $_GET['category'] : 'all';

// Build filters based on event type and category
$date_filter = '';
$category_filter = '';

switch ($event_type) {
    case 'upcoming':
        $date_filter = "AND e.event_date >= CURRENT_DATE";
        break;
    case 'past':
        $date_filter = "AND e.event_date < CURRENT_DATE";
        break;
    case 'new':
        $date_filter = "AND e.event_date >= CURRENT_DATE AND e.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
        break;
    default:
        $date_filter = "AND e.event_date >= CURRENT_DATE";
}

if ($current_category !== 'all') {
    $category_filter = "AND ec.slug = " . $pdo->quote($current_category);
}

// Get featured events
try {
    $featured_stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.is_featured = 1 AND e.status = 'published' 
        $date_filter
        $category_filter
        ORDER BY e.event_date ASC 
        LIMIT 3
    ");
    $featured_stmt->execute();
    $featured_events = $featured_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $featured_events = [];
}

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 9;
$offset = ($page - 1) * $per_page;

// Get total events count for pagination
$count_stmt = $pdo->prepare("
    SELECT COUNT(*) as total 
    FROM events e 
    LEFT JOIN event_categories ec ON e.category_id = ec.id 
    WHERE e.status = 'published' 
    $date_filter
    $category_filter
");
$count_stmt->execute();
$total_events = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_events / $per_page);

// Get events with pagination
try {
    $events_stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.status = 'published' 
        $date_filter
        $category_filter
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT :offset, :per_page
    ");
    $events_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $events_stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
    $events_stmt->execute();
    $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $events = [];
}

// Get upcoming events for sidebar
try {
    $upcoming_stmt = $pdo->query("
        SELECT e.*, ec.name as category_name, ec.color as category_color
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.status = 'published' AND e.event_date >= CURRENT_DATE
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT 5
    ");
    $upcoming_events = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcoming_events = [];
}

// Get event statistics
try {
    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_events,
            COUNT(CASE WHEN event_date >= CURRENT_DATE THEN 1 END) as upcoming_events,
            COUNT(CASE WHEN event_date < CURRENT_DATE THEN 1 END) as past_events,
            COUNT(CASE WHEN created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY) THEN 1 END) as new_events
        FROM events 
        WHERE status = 'published'
    ");
    $event_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $event_stats = ['total_events' => 0, 'upcoming_events' => 0, 'past_events' => 0, 'new_events' => 0];
}

$page_title = "Campus Events - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="icon" href="assets/images/logo.png">
    <style>
        /* Add cursor pointer to event cards */
        .featured-card, .event-card {
            cursor: pointer;
            transition: var(--transition);
        }

        .featured-card:hover, .event-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        :root {
            --primary: #0056b3;
            --primary-dark: #003d82;
            --primary-light: #4d8be6;
            --secondary: #1e88e5;
            --accent: #0d47a1;
            --light: #f8fafc;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #343a40;
            --gray-900: #212529;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --border-radius-xl: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--gray-900);
            background: var(--light);
            overflow-x: hidden;
            font-size: 14px;
        }

        /* Header & Navigation */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: var(--transition);
        }

        .header.scrolled {
            box-shadow: var(--shadow-md);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
            transition: var(--transition);
        }

        .logo-rp {
            max-width: 100px;
        }

        .logo-rpsu {
            max-width: 60px;
        }

        .brand-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.025em;
        }

        .brand-text p {
            font-size: 0.75rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            position: relative;
            padding: 0.5rem 0;
        }

        .nav-links a:after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 0;
            background: var(--gradient-primary);
            transition: var(--transition);
            border-radius: 1px;
        }

        .nav-links a:hover:after {
            width: 100%;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a.active {
            color: var(--primary);
        }

        .nav-links a.active:after {
            width: 100%;
        }

        .login-buttons {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .login-btn {
            padding: 0.6rem 1.25rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .btn-student {
            background: var(--gradient-secondary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-committee {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .login-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Mobile Menu */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-800);
            cursor: pointer;
            padding: 0.5rem;
        }

        .mobile-nav {
            display: none;
            position: fixed;
            top: 70px;
            left: 0;
            width: 100%;
            background: var(--white);
            box-shadow: var(--shadow-lg);
            z-index: 999;
            padding: 1rem;
            border-top: 1px solid var(--gray-200);
        }

        .mobile-nav.active {
            display: block;
        }

        .mobile-nav-links {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .mobile-nav-links a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .mobile-nav-links a:hover, .mobile-nav-links a.active {
            background: var(--primary);
            color: var(--white);
        }

        .mobile-login-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }

        /* Main Content */
        .main-container {
            max-width: 1200px;
            margin: 80px auto 0;
            padding: 2rem 1.5rem;
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: 1.1rem;
            line-height: 1.5;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Event Type Filter */
        .event-type-filter {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .type-btn {
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--gray-200);
            border-radius: 50px;
            background: var(--white);
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .type-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .type-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
        }

        .type-badge {
            background: rgba(255,255,255,0.2);
            color: inherit;
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Category Filter */
        .category-filter {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 3rem;
        }

        .category-btn {
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--gray-200);
            border-radius: 50px;
            background: var(--white);
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .category-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .category-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
        }

        /* Featured Events */
        .featured-section {
            margin-bottom: 4rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary);
        }

        .featured-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .featured-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
        }

        .featured-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .featured-image {
            height: 200px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .featured-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .featured-card:hover .featured-image img {
            transform: scale(1.05);
        }

        .featured-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: var(--warning);
            color: var(--white);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .event-date {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            text-align: center;
            min-width: 60px;
        }

        .event-day {
            font-size: 1.25rem;
            font-weight: 800;
            display: block;
            line-height: 1;
        }

        .event-month {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .featured-content {
            padding: 1.5rem;
        }

        .featured-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .featured-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        .featured-excerpt {
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .featured-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .event-location {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Events Grid */
        .events-section {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
            align-items: start;
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .event-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .event-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .event-image {
            height: 160px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
        }

        .event-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .event-card:hover .event-image img {
            transform: scale(1.05);
        }

        .event-content {
            padding: 1.25rem;
        }

        .event-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .event-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .event-excerpt {
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        .event-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .registration-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }

        .participants {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .register-btn {
            padding: 0.4rem 0.8rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .register-btn:hover {
            background: var(--primary-dark);
        }

        .register-btn:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .sidebar-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-title i {
            color: var(--primary);
        }

        .upcoming-list {
            list-style: none;
        }

        .upcoming-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .upcoming-item:last-child {
            border-bottom: none;
        }

        .upcoming-item a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            display: block;
            line-height: 1.4;
        }

        .upcoming-item a:hover {
            color: var(--primary);
        }

        .upcoming-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.25rem;
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .categories-list {
            list-style: none;
        }

        .category-item {
            padding: 0.5rem 0;
        }

        .category-item a {
            color: var(--gray-600);
            text-decoration: none;
            font-size: 0.875rem;
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .category-item a:hover {
            color: var(--primary);
        }

        .category-count {
            background: var(--gray-200);
            color: var(--gray-600);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--gray-100);
            border-radius: var(--border-radius);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            display: block;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 3rem;
        }

        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--gray-700);
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .pagination a:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
        }

        .pagination .current {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
        }

        .pagination .disabled {
            color: var(--gray-400);
            pointer-events: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }

        /* Footer */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 3rem 1.5rem 1.5rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1000px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 2rem;
        }

        .footer-logo {
            margin-bottom: 1rem;
        }

        .footer-logo .logo {
            height: 35px;
            filter: brightness(0) invert(1);
        }

        .footer-description {
            color: #9ca3af;
            line-height: 1.5;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .social-links {
            display: flex;
            gap: 0.75rem;
        }

        .social-links a {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }

        .footer-heading {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--warning);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .footer-links a:hover {
            color: var(--warning);
            padding-left: 3px;
        }

        .footer-bottom {
            max-width: 1000px;
            margin: 0 auto;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            margin-top: 2rem;
            font-size: 0.75rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .events-section {
                grid-template-columns: 1fr;
            }

            .sidebar {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                flex-direction: row;
                gap: 1rem;
                padding: 0 1rem;
            }

            .nav-links, .login-buttons {
                display: none;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .page-title {
                font-size: 2rem;
            }

            .featured-grid {
                grid-template-columns: 1fr;
            }

            .events-grid {
                grid-template-columns: 1fr;
            }

            .event-type-filter, .category-filter {
                flex-direction: column;
                align-items: center;
            }

            .type-btn, .category-btn {
                width: 100%;
                max-width: 250px;
                justify-content: center;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            /* Mobile-specific adjustments */
            .main-container {
                margin-top: 70px;
                padding: 1rem;
            }

            .page-header {
                margin-bottom: 2rem;
            }

            .page-title {
                font-size: 1.75rem;
            }

            .page-subtitle {
                font-size: 1rem;
            }

            .featured-section {
                margin-bottom: 2.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }

            .featured-grid {
                gap: 1.5rem;
            }

            .featured-content {
                padding: 1.25rem;
            }

            .featured-title {
                font-size: 1.1rem;
            }

            .events-grid {
                gap: 1.25rem;
            }

            .event-content {
                padding: 1rem;
            }

            .event-title {
                font-size: 0.95rem;
            }

            .sidebar-card {
                padding: 1.25rem;
            }

            .sidebar-title {
                font-size: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }

            .stat-item {
                padding: 0.75rem;
            }

            .stat-number {
                font-size: 1.25rem;
            }

            .pagination {
                flex-wrap: wrap;
                gap: 0.25rem;
            }

            .pagination a, .pagination span {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .logo-section {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .brand-text h1 {
                font-size: 1.2rem;
            }

            .brand-text p {
                font-size: 0.7rem;
            }

            .logos {
                justify-content: center;
            }

            .logo {
                height: 35px;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .page-subtitle {
                font-size: 0.9rem;
            }

            .event-type-filter, .category-filter {
                gap: 0.5rem;
            }

            .type-btn, .category-btn {
                padding: 0.6rem 1.25rem;
                font-size: 0.8rem;
            }

            .featured-image {
                height: 160px;
            }

            .event-image {
                height: 140px;
            }

            .featured-content, .event-content {
                padding: 1rem;
            }

            .featured-title {
                font-size: 1rem;
            }

            .event-title {
                font-size: 0.9rem;
            }

            .featured-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .registration-info {
                flex-direction: column;
                gap: 0.75rem;
                align-items: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .footer {
                padding: 2rem 1rem 1rem;
            }

            .footer-content {
                gap: 1.5rem;
            }
        }

        /* Loading Animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .featured-card, .event-card {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header" id="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/logo.png" alt="RPSU" class="logo logo-rpsu">
                </div>
                <div class="brand-text">
                    <h1>Isonga</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <nav class="nav-links">
                <a href="index.php">Home</a>
                <a href="announcements.php">Announcements</a>
                <a href="news.php">News</a>
                <a href="events.php" class="active">Events</a>
                <a href="committee.php">Committee</a>
                <a href="gallery.php">Gallery</a>
            </nav>
            <div class="login-buttons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee
                </a>
            </div>
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <!-- Mobile Navigation -->
        <div class="mobile-nav" id="mobileNav">
            <div class="mobile-nav-links">
                <a href="index.php">Home</a>
                <a href="announcements.php">Announcements</a>
                <a href="news.php">News</a>
                <a href="events.php" class="active">Events</a>
                <a href="committee.php">Committee</a>
                <a href="gallery.php">Gallery</a>
            </div>
            <div class="mobile-login-buttons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student Login
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee Login
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Campus Events</h1>
            <p class="page-subtitle">Discover exciting events, activities, and gatherings happening at RP Musanze College. Stay engaged with our vibrant campus community!</p>
        </div>

        <!-- Event Type Filter -->
        <div class="event-type-filter">
            <a href="events.php?type=upcoming&category=<?php echo $current_category; ?>" 
               class="type-btn <?php echo $event_type === 'upcoming' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Upcoming Events
                <span class="type-badge"><?php echo $event_stats['upcoming_events']; ?></span>
            </a>
            <a href="events.php?type=new&category=<?php echo $current_category; ?>" 
               class="type-btn <?php echo $event_type === 'new' ? 'active' : ''; ?>">
                <i class="fas fa-star"></i> New Events
                <span class="type-badge"><?php echo $event_stats['new_events']; ?></span>
            </a>
            <a href="events.php?type=past&category=<?php echo $current_category; ?>" 
               class="type-btn <?php echo $event_type === 'past' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Past Events
                <span class="type-badge"><?php echo $event_stats['past_events']; ?></span>
            </a>
        </div>

        <!-- Category Filter -->
        <div class="category-filter">
            <a href="events.php?type=<?php echo $event_type; ?>&category=all" 
               class="category-btn <?php echo $current_category === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> All Categories
            </a>
            <?php foreach ($categories as $category): ?>
                <a href="events.php?type=<?php echo $event_type; ?>&category=<?php echo $category['slug']; ?>" 
                   class="category-btn <?php echo $current_category === $category['slug'] ? 'active' : ''; ?>"
                   style="<?php echo $current_category === $category['slug'] ? 'background-color: ' . $category['color'] . '; border-color: ' . $category['color'] : ''; ?>">
                    <i class="fas fa-<?php echo $category['icon']; ?>"></i>
                    <?php echo htmlspecialchars($category['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Featured Events -->
        <?php if (!empty($featured_events) && $event_type === 'upcoming'): ?>
        <section class="featured-section">
            <h2 class="section-title">
                <i class="fas fa-star"></i>
                Featured Events
            </h2>
            <div class="featured-grid">
                <?php foreach ($featured_events as $featured): ?>
                    <article class="featured-card" onclick="window.location.href='event_single.php?id=<?php echo $featured['id']; ?>'">
                        <div class="featured-image">
                            <?php if (!empty($featured['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($featured['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($featured['title']); ?>"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            <div class="featured-image-placeholder" style="<?php echo empty($featured['image_url']) ? 'display: flex;' : 'display: none;'; ?> width: 100%; height: 100%; background: <?php echo $featured['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem;">
                                <i class="fas fa-<?php echo $featured['category_icon']; ?>"></i>
                            </div>
                            <div class="featured-badge">
                                <i class="fas fa-star"></i> Featured
                            </div>
                            <div class="event-date">
                                <span class="event-day"><?php echo date('j', strtotime($featured['event_date'])); ?></span>
                                <span class="event-month"><?php echo date('M', strtotime($featured['event_date'])); ?></span>
                            </div>
                        </div>
                        <div class="featured-content">
                            <div class="featured-category" style="background: <?php echo $featured['category_color']; ?>20; color: <?php echo $featured['category_color']; ?>;">
                                <i class="fas fa-<?php echo $featured['category_icon']; ?>"></i>
                                <?php echo htmlspecialchars($featured['category_name']); ?>
                            </div>
                            <h3 class="featured-title">
                                <a href="event_single.php?id=<?php echo $featured['id']; ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($featured['title']); ?>
                                </a>
                            </h3>
                            <p class="featured-excerpt"><?php echo htmlspecialchars($featured['excerpt'] ?? substr($featured['description'], 0, 120) . '...'); ?></p>
                            <div class="featured-meta">
                                <span class="event-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($featured['location']); ?>
                                </span>
                                <span>
                                    <i class="fas fa-clock"></i>
                                    <?php echo date('g:i A', strtotime($featured['start_time'])); ?>
                                </span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Events Content -->
        <section class="events-section">
            <!-- Main Events Grid -->
            <div class="events-content">
                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Events Found</h3>
                        <p>
                            <?php 
                            if ($event_type === 'upcoming') {
                                echo 'There are no upcoming events scheduled at the moment. Please check back later for new events.';
                            } elseif ($event_type === 'new') {
                                echo 'No new events have been added recently. Check back soon for fresh events!';
                            } else {
                                echo 'No past events found in this category.';
                            }
                            ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="events-grid">
                        <?php foreach ($events as $event): ?>
                            <article class="event-card" onclick="window.location.href='event_single.php?id=<?php echo $event['id']; ?>'">
                                <div class="event-image">
                                    <?php if (!empty($event['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($event['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($event['title']); ?>"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <?php endif; ?>
                                    <div class="featured-image-placeholder" style="<?php echo empty($event['image_url']) ? 'display: flex;' : 'display: none;'; ?> width: 100%; height: 100%; background: <?php echo $event['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                        <i class="fas fa-<?php echo $event['category_icon']; ?>"></i>
                                    </div>
                                    <div class="event-date">
                                        <span class="event-day"><?php echo date('j', strtotime($event['event_date'])); ?></span>
                                        <span class="event-month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="event-content">
                                    <div class="event-category" style="background: <?php echo $event['category_color']; ?>20; color: <?php echo $event['category_color']; ?>;">
                                        <i class="fas fa-<?php echo $event['category_icon']; ?>"></i>
                                        <?php echo htmlspecialchars($event['category_name']); ?>
                                    </div>
                                    <h3 class="event-title">
                                        <a href="event_single.php?id=<?php echo $event['id']; ?>" style="color: inherit; text-decoration: none;">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="event-excerpt"><?php echo htmlspecialchars($event['excerpt'] ?? substr($event['description'], 0, 100) . '...'); ?></p>
                                    <div class="event-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($event['location']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                            <?php if ($event['end_time']): ?>
                                                - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                            <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-users"></i>
                                            <span>Organized by: <?php echo htmlspecialchars($event['organizer']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($event_type === 'upcoming' && $event['registration_required']): ?>
                                    <div class="registration-info">
                                        <div class="participants">
                                            <i class="fas fa-user-check"></i>
                                            <?php echo $event['registered_participants']; ?>
                                            <?php if ($event['max_participants']): ?>
                                                / <?php echo $event['max_participants']; ?> registered
                                            <?php else: ?>
                                                registered
                                            <?php endif; ?>
                                        </div>
                                        <button class="register-btn" 
                                                <?php echo ($event['max_participants'] && $event['registered_participants'] >= $event['max_participants']) ? 'disabled' : ''; ?>
                                                onclick="event.stopPropagation(); registerForEvent(<?php echo $event['id']; ?>)">
                                            <?php echo ($event['max_participants'] && $event['registered_participants'] >= $event['max_participants']) ? 'Full' : 'Register'; ?>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="events.php?type=<?php echo $event_type; ?>&category=<?php echo $current_category; ?>&page=<?php echo $page - 1; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php else: ?>
                                <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                            <?php endif; ?>

                            <?php 
                            // Display page numbers
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <a href="events.php?type=<?php echo $event_type; ?>&category=<?php echo $current_category; ?>&page=<?php echo $i; ?>" 
                                   class="<?php echo $i == $page ? 'current' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="events.php?type=<?php echo $event_type; ?>&category=<?php echo $current_category; ?>&page=<?php echo $page + 1; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <aside class="sidebar">
                <!-- Upcoming Events -->
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-calendar-check"></i>
                        Coming Soon
                    </h3>
                    <ul class="upcoming-list">
                        <?php if (empty($upcoming_events)): ?>
                            <li class="upcoming-item">
                                <span style="color: var(--gray-600); font-size: 0.875rem;">No upcoming events</span>
                            </li>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $upcoming): ?>
                                <li class="upcoming-item">
                                    <a href="event_single.php?id=<?php echo $upcoming['id']; ?>">
                                        <?php echo htmlspecialchars($upcoming['title']); ?>
                                    </a>
                                    <div class="upcoming-meta">
                                        <span><?php echo htmlspecialchars($upcoming['category_name']); ?></span>
                                        <span><?php echo date('M j', strtotime($upcoming['event_date'])); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Event Statistics -->
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-chart-bar"></i>
                        Event Stats
                    </h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $event_stats['total_events']; ?></span>
                            <span class="stat-label">Total Events</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $event_stats['upcoming_events']; ?></span>
                            <span class="stat-label">Upcoming</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $event_stats['new_events']; ?></span>
                            <span class="stat-label">This Week</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo count($categories); ?></span>
                            <span class="stat-label">Categories</span>
                        </div>
                    </div>
                </div>

                <!-- About Campus Events -->
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-info-circle"></i>
                        About Campus Events
                    </h3>
                    <p style="color: var(--gray-600); font-size: 0.875rem; line-height: 1.5;">
                        RP Musanze College hosts a vibrant calendar of events throughout the year. From academic workshops to cultural festivals, sports competitions to career fairs - there's always something exciting happening on campus!
                    </p>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <small style="color: var(--gray-500); font-size: 0.75rem;">
                            <i class="fas fa-bullhorn"></i> Want to organize an event? Contact the Events Committee!
                        </small>
                    </div>
                </div>
            </aside>
        </section>
    </div>

    <!-- Footer -->
 <footer class="footer">
    <div class="footer-content">
        <div class="footer-info">
            <div class="footer-logo">
                <img src="assets/images/rp_logo.png" alt="RP Musanze" class="logo">
            </div>
            <p class="footer-description">
                Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
            </p>
            <div class="social-links">
                <a href="https://twitter.com/MusanzecollegSU" target="_blank"><i class="fab fa-twitter"></i></a>
                <a href="https://www.facebook.com/RP-Musanze-College" target="_blank"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.linkedin.com/in/rp-musanze-college-3963b0203" target="_blank"><i class="fab fa-linkedin-in"></i></a>
                <a href="https://www.instagram.com/rpmusanzecollege_su" target="_blank"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Quick Links</h4>
            <ul class="footer-links">
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="news.php">Campus News</a></li>
                <li><a href="events.php">Events</a></li>
                <li><a href="committee.php">Committee</a></li>
            </ul>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Student Resources</h4>
            <ul class="footer-links">
                <li><a href="https://www.rp.ac.rw/announcement" target="_blank">Academic Calendar</a></li>
                <li><a href="https://www.google.com/maps/search/rp+musanze+college" target="_blank">Campus Map</a></li>
                <li><a href="../assets/rp_handbook.pdf">Student Handbook</a></li>
                <li><a href="gallery.php">Gallery</a></li>
            </ul>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Contact Info</h4>
            <ul class="footer-links">
                <li><i class="fas fa-map-marker-alt"></i> Rwanda Polytechnic Musanze College Student Union</li>
                <li><i class="fas fa-phone"></i> +250 788 123 456</li>
                <li><i class="fas fa-envelope"></i> iprcmusanzesu@gmail.com</li>
                <li><i class="fas fa-clock"></i> Mon - Fri: 8:00 - 17:00</li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; 2025 Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
    </div>
</footer>


    <script>
        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Mobile menu toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileNav = document.getElementById('mobileNav');
        
        mobileMenuToggle.addEventListener('click', function() {
            mobileNav.classList.toggle('active');
            const icon = mobileMenuToggle.querySelector('i');
            if (mobileNav.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.nav-container') && !event.target.closest('.mobile-nav')) {
                mobileNav.classList.remove('active');
                const icon = mobileMenuToggle.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Registration button functionality
        function registerForEvent(eventId) {
            alert('Registration feature will be implemented soon! Students will need to login to register for events.');
            // In a real implementation, this would open a registration modal or redirect to registration page
        }

        // Remove the old card click functionality since we're using proper links
        // The onclick events on the cards will handle navigation

        // Animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe cards for animation
        document.querySelectorAll('.featured-card, .event-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Active state for filters
        document.addEventListener('DOMContentLoaded', function() {
            const currentCategory = '<?php echo $current_category; ?>';
            if (currentCategory !== 'all') {
                const activeBtn = document.querySelector(`.category-btn[href*="${currentCategory}"]`);
                if (activeBtn) {
                    const category = <?php echo json_encode($categories); ?>.find(cat => cat.slug === currentCategory);
                    if (category) {
                        activeBtn.style.backgroundColor = category.color;
                        activeBtn.style.borderColor = category.color;
                        activeBtn.style.color = 'white';
                    }
                }
            }
        });
    </script>
</body>
</html>