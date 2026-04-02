<?php
session_start();
require_once 'config/database.php';

// Get all event categories
try {
    $categories_stmt = $pdo->query("SELECT * FROM event_categories WHERE is_active = 'active' ORDER BY name");
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
        $date_filter = "AND e.event_date >= CURRENT_DATE AND e.created_at >= CURRENT_DATE - INTERVAL '7 days'";
        break;
    default:
        $date_filter = "AND e.event_date >= CURRENT_DATE";
}

if ($current_category !== 'all') {
    $category_filter = "AND ec.slug = ?";
    $params[] = $current_category;
}

// Get featured events
try {
    $featured_stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.is_featured = true AND e.status = 'published' 
        $date_filter
        $category_filter
        ORDER BY e.event_date ASC 
        LIMIT 3
    ");
    if ($current_category !== 'all') {
        $featured_stmt->execute([$current_category]);
    } else {
        $featured_stmt->execute();
    }
    $featured_events = $featured_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Featured events error: " . $e->getMessage());
    $featured_events = [];
}

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 9;
$offset = ($page - 1) * $per_page;

// Get total events count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM events e 
    LEFT JOIN event_categories ec ON e.category_id = ec.id 
    WHERE e.status = 'published' 
    $date_filter
    $category_filter
";
$count_stmt = $pdo->prepare($count_sql);
if ($current_category !== 'all') {
    $count_stmt->execute([$current_category]);
} else {
    $count_stmt->execute();
}
$total_events = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_events / $per_page);

// Get events with pagination
try {
    $events_sql = "
        SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.status = 'published' 
        $date_filter
        $category_filter
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT ? OFFSET ?
    ";
    
    $events_params = [];
    if ($current_category !== 'all') {
        $events_params[] = $current_category;
    }
    $events_params[] = $per_page;
    $events_params[] = $offset;
    
    $events_stmt = $pdo->prepare($events_sql);
    $events_stmt->execute($events_params);
    $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Events query error: " . $e->getMessage());
    $events = [];
}

// Get upcoming events for sidebar
try {
    $upcoming_stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name, ec.color as category_color
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.status = 'published' AND e.event_date >= CURRENT_DATE
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT 5
    ");
    $upcoming_stmt->execute();
    $upcoming_events = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcoming_events = [];
}

// Get event statistics
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_events,
            COUNT(CASE WHEN event_date >= CURRENT_DATE THEN 1 END) as upcoming_events,
            COUNT(CASE WHEN event_date < CURRENT_DATE THEN 1 END) as past_events,
            COUNT(CASE WHEN created_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 END) as new_events
        FROM events 
        WHERE status = 'published'
    ");
    $stats_stmt->execute();
    $event_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $event_stats = ['total_events' => 0, 'upcoming_events' => 0, 'past_events' => 0, 'new_events' => 0];
}

// Get category counts
try {
    $category_counts_stmt = $pdo->prepare("
        SELECT ec.id, ec.name, ec.slug, ec.color, COUNT(e.id) as event_count
        FROM event_categories ec
        LEFT JOIN events e ON ec.id = e.category_id AND e.status = 'published'
        WHERE ec.is_active = 'active'
        GROUP BY ec.id, ec.name, ec.slug, ec.color
        ORDER BY event_count DESC
    ");
    $category_counts_stmt->execute();
    $category_counts = $category_counts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $category_counts = [];
}

$page_title = "Campus Events - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Discover exciting events, activities, and gatherings happening at RP Musanze College. Stay engaged with our vibrant campus community!">
    <title><?php echo $page_title; ?></title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" as="style">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    
    <!-- Favicon -->
    <link rel="icon" href="assets/images/logo.png" type="image/png">
    
    <style>
        /* CSS Variables - Matching index.php */
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
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Spacing */
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            
            /* Typography */
            --text-xs: 0.7rem;
            --text-sm: 0.8rem;
            --text-base: 0.9rem;
            --text-md: 1rem;
            --text-lg: 1.1rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.75rem;
        }

        @media (min-width: 768px) {
            :root {
                --space-md: 1.5rem;
                --space-lg: 2rem;
                --space-xl: 3rem;
                --text-sm: 0.875rem;
                --text-base: 1rem;
                --text-md: 1.125rem;
                --text-lg: 1.25rem;
                --text-xl: 1.5rem;
                --text-2xl: 1.875rem;
                --text-3xl: 2.25rem;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--gray-900);
            background: var(--light);
            overflow-x: hidden;
            font-size: var(--text-base);
        }

        /* Header & Navigation - Matching index.php */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.5rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: var(--transition);
        }

        .header.scrolled {
            box-shadow: var(--shadow-md);
            padding: 0.4rem 0;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
            gap: var(--space-sm);
        }

        @media (min-width: 768px) {
            .nav-container {
                padding: 0 1.5rem;
            }
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
            min-width: 0;
        }

        .logos {
            display: flex;
            gap: var(--space-xs);
            align-items: center;
            flex-shrink: 0;
        }

        .logo {
            height: 32px;
            width: auto;
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .logo {
                height: 40px;
            }
        }

        .brand-text {
            flex-shrink: 1;
            min-width: 0;
        }

        .brand-text h1 {
            font-size: 1rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.025em;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .brand-text h1 {
                font-size: 1.4rem;
            }
        }

        .brand-text p {
            font-size: 0.65rem;
            color: var(--gray-600);
            font-weight: 500;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .brand-text p {
                font-size: 0.75rem;
            }
        }

        /* Desktop Navigation */
        .desktop-nav {
            display: none;
            align-items: center;
            gap: var(--space-md);
        }

        @media (min-width: 768px) {
            .desktop-nav {
                display: flex;
            }
        }

        .nav-links {
            display: flex;
            gap: var(--space-lg);
        }

        .nav-links a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            position: relative;
            padding: var(--space-xs) 0;
            white-space: nowrap;
        }

        .nav-links a::after {
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

        .nav-links a:hover::after,
        .nav-links a.active::after {
            width: 100%;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--primary);
        }

        .login-buttons {
            display: flex;
            gap: var(--space-xs);
            align-items: center;
        }

        .login-btn {
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .login-btn {
                padding: 0.6rem 1.25rem;
                font-size: 0.8rem;
                gap: 0.5rem;
            }
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

        /* Mobile Navigation */
        .mobile-menu-btn {
            display: flex;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            font-size: 1.25rem;
            color: var(--gray-800);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        .mobile-menu-btn:hover {
            background: var(--gray-100);
        }

        .mobile-menu {
            position: fixed;
            top: 60px;
            left: 0;
            width: 100%;
            height: calc(100vh - 60px);
            background: var(--white);
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (min-width: 768px) {
            .mobile-menu {
                display: none;
            }
        }

        .mobile-menu.active {
            transform: translateX(0);
        }

        .mobile-nav {
            padding: var(--space-sm);
        }

        .mobile-nav .nav-links {
            flex-direction: column;
            gap: 0;
        }

        .mobile-nav .nav-links a {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.9rem;
        }

        .mobile-nav .nav-links a:last-child {
            border-bottom: none;
        }

        .mobile-login-buttons {
            padding: var(--space-sm);
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }

        .mobile-login-buttons .login-btn {
            width: 100%;
            justify-content: center;
            padding: 0.75rem;
            font-size: 0.85rem;
        }

        /* Main Content */
        .main-container {
            max-width: 1200px;
            margin: 70px auto 0;
            padding: 1.5rem 1rem;
        }

        @media (min-width: 768px) {
            .main-container {
                margin: 80px auto 0;
                padding: 2rem 1.5rem;
            }
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .page-header {
                margin-bottom: 3rem;
            }
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
        }

        @media (min-width: 768px) {
            .page-title {
                font-size: 2.5rem;
            }
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
            max-width: 600px;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .page-subtitle {
                font-size: 1.1rem;
            }
        }

        /* Event Type Filter */
        .event-type-filter {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .event-type-filter {
                gap: 1rem;
                margin-bottom: 2rem;
            }
        }

        .type-btn {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 50px;
            background: var(--white);
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.75rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .type-btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.875rem;
            }
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
            background: rgba(255, 255, 255, 0.2);
            color: inherit;
            padding: 0.2rem 0.4rem;
            border-radius: 15px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        /* Category Filter */
        .category-filter {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .category-filter {
                gap: 0.75rem;
                margin-bottom: 3rem;
            }
        }

        .category-btn {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 50px;
            background: var(--white);
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.75rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .category-btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.875rem;
            }
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

        /* Featured Section */
        .featured-section {
            margin-bottom: 2.5rem;
        }

        @media (min-width: 768px) {
            .featured-section {
                margin-bottom: 4rem;
            }
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .section-title {
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }
        }

        .section-title i {
            color: var(--primary);
        }

        .featured-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .featured-grid {
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 2rem;
            }
        }

        .featured-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            cursor: pointer;
        }

        .featured-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .featured-image {
            height: 180px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .featured-image {
                height: 200px;
            }
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
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 2;
        }

        .event-date {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            text-align: center;
            min-width: 55px;
            z-index: 2;
        }

        @media (min-width: 768px) {
            .event-date {
                min-width: 60px;
                padding: 0.5rem;
            }
        }

        .event-day {
            font-size: 1rem;
            font-weight: 800;
            display: block;
            line-height: 1;
        }

        @media (min-width: 768px) {
            .event-day {
                font-size: 1.25rem;
            }
        }

        .event-month {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .featured-content {
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .featured-content {
                padding: 1.5rem;
            }
        }

        .featured-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .featured-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .featured-title {
                font-size: 1.2rem;
                margin-bottom: 0.75rem;
            }
        }

        .featured-title a {
            color: inherit;
            text-decoration: none;
            transition: var(--transition);
        }

        .featured-title a:hover {
            color: var(--primary);
        }

        .featured-excerpt {
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 0.75rem;
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .featured-excerpt {
                font-size: 0.875rem;
                margin-bottom: 1rem;
            }
        }

        .featured-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .featured-meta {
                font-size: 0.75rem;
            }
        }

        .event-location {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Events Layout */
        .events-section {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        @media (min-width: 1024px) {
            .events-section {
                grid-template-columns: 1fr 300px;
                gap: 2rem;
            }
        }

        .events-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .events-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .events-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 1.5rem;
            }
        }

        .event-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            cursor: pointer;
        }

        .event-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .event-image {
            height: 140px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
        }

        @media (min-width: 768px) {
            .event-image {
                height: 160px;
            }
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
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .event-content {
                padding: 1.25rem;
            }
        }

        .event-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        @media (min-width: 768px) {
            .event-category {
                font-size: 0.7rem;
                margin-bottom: 0.75rem;
            }
        }

        .event-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .event-title {
                font-size: 1rem;
                margin-bottom: 0.5rem;
            }
        }

        .event-title a {
            color: inherit;
            text-decoration: none;
            transition: var(--transition);
        }

        .event-title a:hover {
            color: var(--primary);
        }

        .event-excerpt {
            color: var(--gray-600);
            line-height: 1.4;
            margin-bottom: 0.75rem;
            font-size: 0.75rem;
        }

        @media (min-width: 768px) {
            .event-excerpt {
                font-size: 0.8rem;
                margin-bottom: 1rem;
            }
        }

        .event-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .event-meta {
                font-size: 0.75rem;
            }
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
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-200);
        }

        @media (min-width: 768px) {
            .registration-info {
                margin-top: 1rem;
                padding-top: 1rem;
            }
        }

        .participants {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .register-btn {
            padding: 0.35rem 0.7rem;
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
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .sidebar {
                gap: 1.5rem;
            }
        }

        .sidebar-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        @media (min-width: 768px) {
            .sidebar-card {
                padding: 1.5rem;
            }
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .sidebar-title {
                font-size: 1.1rem;
                margin-bottom: 1rem;
            }
        }

        .sidebar-title i {
            color: var(--primary);
        }

        .upcoming-list {
            list-style: none;
        }

        .upcoming-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .upcoming-item:last-child {
            border-bottom: none;
        }

        .upcoming-item a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.8rem;
            transition: var(--transition);
            display: block;
            line-height: 1.4;
        }

        @media (min-width: 768px) {
            .upcoming-item a {
                font-size: 0.875rem;
            }
        }

        .upcoming-item a:hover {
            color: var(--primary);
        }

        .upcoming-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.25rem;
            font-size: 0.65rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .upcoming-meta {
                font-size: 0.7rem;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .stats-grid {
                gap: 1rem;
            }
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--gray-100);
            border-radius: var(--border-radius);
        }

        @media (min-width: 768px) {
            .stat-item {
                padding: 1rem;
            }
        }

        .stat-number {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
            display: block;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 1.5rem;
            }
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.25rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        @media (min-width: 768px) {
            .pagination {
                gap: 0.5rem;
                margin-top: 3rem;
            }
        }

        .pagination a, .pagination span {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--gray-700);
            font-weight: 500;
            font-size: 0.75rem;
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .pagination a, .pagination span {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
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
            padding: 2rem 1rem;
            color: var(--gray-600);
            grid-column: 1 / -1;
        }

        @media (min-width: 768px) {
            .empty-state {
                padding: 4rem 2rem;
            }
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }

        /* Footer - Matching index.php */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 2rem 1rem 1rem;
            margin-top: 2rem;
        }

        @media (min-width: 768px) {
            .footer {
                padding: 3rem 1.5rem 1.5rem;
                margin-top: 4rem;
            }
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 768px) {
            .footer-content {
                grid-template-columns: 2fr 1fr 1fr 1fr;
                gap: 2rem;
            }
        }

        .footer-logo {
            margin-bottom: 0.75rem;
        }

        .footer-logo .logo {
            height: 30px;
            filter: brightness(0) invert(1);
        }

        .footer-description {
            color: #9ca3af;
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .footer-description {
                font-size: 0.875rem;
                margin-bottom: 1.5rem;
            }
        }

        .social-links {
            display: flex;
            gap: 0.6rem;
        }

        .social-links a {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .social-links a {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
                border-radius: 8px;
            }
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }

        .footer-heading {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--warning);
        }

        @media (min-width: 768px) {
            .footer-heading {
                font-size: 1rem;
                margin-bottom: 1rem;
            }
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.4rem;
        }

        @media (min-width: 768px) {
            .footer-links li {
                margin-bottom: 0.5rem;
            }
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        @media (min-width: 768px) {
            .footer-links a {
                font-size: 0.875rem;
                gap: 0.5rem;
            }
        }

        .footer-links a:hover {
            color: var(--warning);
            padding-left: 3px;
        }

        .footer-bottom {
            max-width: 1200px;
            margin: 1rem auto 0;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            font-size: 0.65rem;
        }

        @media (min-width: 768px) {
            .footer-bottom {
                margin-top: 2rem;
                padding-top: 1.5rem;
                font-size: 0.75rem;
            }
        }

        /* Animation */
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

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .featured-card:hover,
            .event-card:hover {
                transform: none;
            }
            
            .category-btn:hover,
            .type-btn:hover {
                transform: none;
            }
            
            .login-btn:hover {
                transform: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <!-- Header - Matching index.php -->
    <header class="header" id="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/logo.png" alt="RPSU Logo" class="logo" loading="lazy">
                </div>
                <div class="brand-text">
                    <h1>Isonga</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            
            <!-- Desktop Navigation -->
            <div class="desktop-nav">
                <nav class="nav-links" aria-label="Main Navigation">
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
            </div>
            
            <!-- Mobile Menu Button -->
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle mobile menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <!-- Mobile Menu -->
        <div class="mobile-menu" id="mobileMenu" aria-hidden="true">
            <div class="mobile-nav">
                <nav class="nav-links" aria-label="Mobile Navigation">
                    <a href="index.php">Home</a>
                    <a href="announcements.php">Announcements</a>
                    <a href="news.php">News</a>
                    <a href="events.php" class="active">Events</a>
                    <a href="committee.php">Committee</a>
                    <a href="gallery.php">Gallery</a>
                </nav>
            </div>
            <div class="mobile-login-buttons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student Portal
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee Portal
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header" data-aos="fade-up">
            <h1 class="page-title">Campus Events</h1>
            <p class="page-subtitle">Discover exciting events, activities, and gatherings happening at RP Musanze College. Stay engaged with our vibrant campus community!</p>
        </div>

        <!-- Event Type Filter -->
        <div class="event-type-filter" data-aos="fade-up" data-aos-delay="100">
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
        <div class="category-filter" data-aos="fade-up" data-aos-delay="200">
            <a href="events.php?type=<?php echo $event_type; ?>&category=all" 
               class="category-btn <?php echo $current_category === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> All Categories
            </a>
            <?php foreach ($categories as $category): ?>
                <a href="events.php?type=<?php echo $event_type; ?>&category=<?php echo $category['slug']; ?>" 
                   class="category-btn <?php echo $current_category === $category['slug'] ? 'active' : ''; ?>">
                    <i class="fas fa-<?php echo $category['icon']; ?>"></i>
                    <?php echo htmlspecialchars($category['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Featured Events -->
        <?php if (!empty($featured_events) && $event_type === 'upcoming'): ?>
        <section class="featured-section" data-aos="fade-up" data-aos-delay="300">
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
                                     loading="lazy">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; background: <?php echo $featured['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem;">
                                    <i class="fas fa-<?php echo $featured['category_icon']; ?>"></i>
                                </div>
                            <?php endif; ?>
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
                                <a href="event_single.php?id=<?php echo $featured['id']; ?>">
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
                                             loading="lazy">
                                    <?php else: ?>
                                        <div style="width: 100%; height: 100%; background: <?php echo $event['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                            <i class="fas fa-<?php echo $event['category_icon']; ?>"></i>
                                        </div>
                                    <?php endif; ?>
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
                                        <a href="event_single.php?id=<?php echo $event['id']; ?>">
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
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="400">
                    <h3 class="sidebar-title">
                        <i class="fas fa-calendar-check"></i>
                        Coming Soon
                    </h3>
                    <ul class="upcoming-list">
                        <?php if (empty($upcoming_events)): ?>
                            <li class="upcoming-item">
                                <span style="color: var(--gray-600);">No upcoming events</span>
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
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="500">
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

                <!-- Categories List -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="600">
                    <h3 class="sidebar-title">
                        <i class="fas fa-folder"></i>
                        Event Categories
                    </h3>
                    <ul class="upcoming-list">
                        <?php foreach ($category_counts as $cat): ?>
                            <li class="upcoming-item">
                                <a href="events.php?type=<?php echo $event_type; ?>&category=<?php echo $cat['slug']; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                    <span class="category-count" style="float: right;"><?php echo $cat['event_count']; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- About Campus Events -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="700">
                    <h3 class="sidebar-title">
                        <i class="fas fa-info-circle"></i>
                        About Campus Events
                    </h3>
                    <p style="color: var(--gray-600); font-size: 0.8rem; line-height: 1.5;">
                        RP Musanze College hosts a vibrant calendar of events throughout the year. From academic workshops to cultural festivals, sports competitions to career fairs - there's always something exciting happening on campus!
                    </p>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <small style="color: var(--gray-500);">
                            <i class="fas fa-bullhorn"></i> Want to organize an event? Contact the Events Committee!
                        </small>
                    </div>
                </div>
            </aside>
        </section>
    </div>

    <!-- Footer - Matching index.php -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-info">
                <div class="footer-logo">
                    <img src="assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <p class="footer-description">
                    Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
                </p>
                <div class="social-links">
                    <a href="https://twitter.com/MusanzecollegSU" target="_blank" rel="noopener noreferrer" aria-label="Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    
                    <a href="https://www.instagram.com/rpmusanzecollege_su" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
            </div>
            
            <div class="footer-links-group">
                <h4 class="footer-heading">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="announcements.php"><i class="fas fa-chevron-right"></i> Announcements</a></li>
                    <li><a href="news.php"><i class="fas fa-chevron-right"></i> Campus News</a></li>
                    <li><a href="events.php"><i class="fas fa-chevron-right"></i> Events</a></li>
                    <li><a href="committee.php"><i class="fas fa-chevron-right"></i> Committee</a></li>
                </ul>
            </div>
            
            <div class="footer-links-group">
                <h4 class="footer-heading">Student Resources</h4>
                <ul class="footer-links">
                    <li><a href="https://www.rp.ac.rw/announcement" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> Academic Calendar</a></li>
                    <li><a href="https://www.google.com/maps/search/rp+musanze+college" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> Campus Map</a></li>
                    <li><a href="../assets/rp_handbook.pdf"><i class="fas fa-chevron-right"></i> Student Handbook</a></li>
                    <li><a href="gallery.php"><i class="fas fa-chevron-right"></i> Gallery</a></li>
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
                    <p>&copy; <?php echo date('Y'); ?> Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
                </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true,
            offset: 100
        });

        // Header scroll effect
        const header = document.getElementById('header');
        
        function updateHeader() {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }
        
        window.addEventListener('scroll', updateHeader);
        updateHeader();

        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const menuIcon = mobileMenuBtn.querySelector('i');
        
        function toggleMobileMenu() {
            const isExpanded = mobileMenuBtn.getAttribute('aria-expanded') === 'true';
            mobileMenuBtn.setAttribute('aria-expanded', !isExpanded);
            mobileMenu.setAttribute('aria-hidden', isExpanded);
            mobileMenu.classList.toggle('active');
            
            if (mobileMenu.classList.contains('active')) {
                menuIcon.classList.remove('fa-bars');
                menuIcon.classList.add('fa-times');
                document.body.style.overflow = 'hidden';
            } else {
                menuIcon.classList.remove('fa-times');
                menuIcon.classList.add('fa-bars');
                document.body.style.overflow = '';
            }
        }
        
        mobileMenuBtn.addEventListener('click', toggleMobileMenu);
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (mobileMenu.classList.contains('active') && 
                !mobileMenu.contains(event.target) && 
                !mobileMenuBtn.contains(event.target)) {
                toggleMobileMenu();
            }
        });
        
        // Close on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && mobileMenu.classList.contains('active')) {
                toggleMobileMenu();
            }
        });
        
        // Close mobile menu on window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && mobileMenu.classList.contains('active')) {
                toggleMobileMenu();
            }
        });
        
        // Registration function
        function registerForEvent(eventId) {
            alert('Registration feature will be implemented soon! Students will need to login to register for events.');
        }
        
        // Card click navigation (prevent double navigation)
        document.querySelectorAll('.event-card, .featured-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' || e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                const eventLink = this.querySelector('a[href*="event_single.php"]');
                if (eventLink) {
                    window.location.href = eventLink.href;
                }
            });
        });
        
        // Image error handling
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.style.display = 'none';
                const placeholder = this.parentElement.querySelector('.image-placeholder');
                if (placeholder) {
                    placeholder.style.display = 'flex';
                }
            });
        });
    </script>
</body>
</html>