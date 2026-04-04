<?php
session_start();
require_once 'config/database.php';

// Get all news categories
try {
    $categories_stmt = $pdo->query("SELECT * FROM news_categories WHERE is_active = 'active' ORDER BY name");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// Get featured news
try {
    $featured_stmt = $pdo->prepare("
        SELECT n.*, nc.name as category_name, nc.color as category_color, nc.icon as category_icon,
               u.full_name as author_name
        FROM news n 
        LEFT JOIN news_categories nc ON n.category_id = nc.id 
        LEFT JOIN users u ON n.author_id = u.id 
        WHERE n.is_featured = true AND n.status = 'published'
        ORDER BY n.created_at DESC 
        LIMIT 3
    ");
    $featured_stmt->execute();
    $featured_news = $featured_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Featured news error: " . $e->getMessage());
    $featured_news = [];
}

// Get category from URL or default to all
$current_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$category_filter = '';
$params = [];

if ($current_category !== 'all') {
    $category_filter = "AND nc.slug = ?";
    $params[] = $current_category;
}

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 9;
$offset = ($page - 1) * $per_page;

// Get total news count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM news n 
    LEFT JOIN news_categories nc ON n.category_id = nc.id 
    WHERE n.status = 'published' $category_filter
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_news = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_news / $per_page);

// Get news with pagination
try {
    $news_sql = "
        SELECT n.*, nc.name as category_name, nc.color as category_color, nc.icon as category_icon,
               u.full_name as author_name
        FROM news n 
        LEFT JOIN news_categories nc ON n.category_id = nc.id 
        LEFT JOIN users u ON n.author_id = u.id 
        WHERE n.status = 'published' $category_filter
        ORDER BY n.created_at DESC 
        LIMIT ? OFFSET ?
    ";
    
    $news_params = $params;
    $news_params[] = $per_page;
    $news_params[] = $offset;
    
    $news_stmt = $pdo->prepare($news_sql);
    $news_stmt->execute($news_params);
    $news = $news_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("News query error: " . $e->getMessage());
    $news = [];
}

// Get popular news for sidebar
try {
    $popular_stmt = $pdo->prepare("
        SELECT n.*, nc.name as category_name, nc.color as category_color
        FROM news n 
        LEFT JOIN news_categories nc ON n.category_id = nc.id 
        WHERE n.status = 'published'
        ORDER BY n.views_count DESC, n.created_at DESC 
        LIMIT 5
    ");
    $popular_stmt->execute();
    $popular_news = $popular_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $popular_news = [];
}

// Get category counts
try {
    $category_counts_stmt = $pdo->prepare("
        SELECT nc.id, nc.name, nc.slug, nc.color, COUNT(n.id) as news_count
        FROM news_categories nc
        LEFT JOIN news n ON nc.id = n.category_id AND n.status = 'published'
        WHERE nc.is_active = 'active'
        GROUP BY nc.id, nc.name, nc.slug, nc.color
        ORDER BY news_count DESC
    ");
    $category_counts_stmt->execute();
    $category_counts = $category_counts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $category_counts = [];
}

// Get this month's news count
try {
    $month_count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM news 
        WHERE status = 'published' 
        AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE)
        AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)
    ");
    $month_count_stmt->execute();
    $month_count = $month_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $month_count = 0;
}

$page_title = "Campus News - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Latest campus news, stories, and updates from RP Musanze College community">
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

        /* News Layout */
        .news-section {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        @media (min-width: 1024px) {
            .news-section {
                grid-template-columns: 1fr 300px;
                gap: 2rem;
            }
        }

        .news-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .news-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .news-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 1.5rem;
            }
        }

        .news-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            cursor: pointer;
        }

        .news-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .news-image {
            height: 140px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
        }

        @media (min-width: 768px) {
            .news-image {
                height: 160px;
            }
        }

        .news-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .news-card:hover .news-image img {
            transform: scale(1.05);
        }

        .news-content {
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .news-content {
                padding: 1.25rem;
            }
        }

        .news-category {
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
            .news-category {
                font-size: 0.7rem;
                margin-bottom: 0.75rem;
            }
        }

        .news-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .news-title {
                font-size: 1rem;
                margin-bottom: 0.5rem;
            }
        }

        .news-title a {
            color: inherit;
            text-decoration: none;
            transition: var(--transition);
        }

        .news-title a:hover {
            color: var(--primary);
        }

        .news-excerpt {
            color: var(--gray-600);
            line-height: 1.4;
            margin-bottom: 0.75rem;
            font-size: 0.75rem;
        }

        @media (min-width: 768px) {
            .news-excerpt {
                font-size: 0.8rem;
                margin-bottom: 1rem;
            }
        }

        .news-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.65rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .news-meta {
                font-size: 0.7rem;
            }
        }

        .news-views {
            display: flex;
            align-items: center;
            gap: 0.25rem;
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

        .popular-list {
            list-style: none;
        }

        .popular-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .popular-item:last-child {
            border-bottom: none;
        }

        .popular-item a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.8rem;
            transition: var(--transition);
            display: block;
            line-height: 1.4;
        }

        @media (min-width: 768px) {
            .popular-item a {
                font-size: 0.875rem;
            }
        }

        .popular-item a:hover {
            color: var(--primary);
        }

        .popular-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.25rem;
            font-size: 0.65rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .popular-meta {
                font-size: 0.7rem;
            }
        }

        .categories-list {
            list-style: none;
        }

        .category-item {
            padding: 0.4rem 0;
        }

        @media (min-width: 768px) {
            .category-item {
                padding: 0.5rem 0;
            }
        }

        .category-item a {
            color: var(--gray-600);
            text-decoration: none;
            font-size: 0.8rem;
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        @media (min-width: 768px) {
            .category-item a {
                font-size: 0.875rem;
            }
        }

        .category-item a:hover {
            color: var(--primary);
        }

        .category-count {
            background: var(--gray-200);
            color: var(--gray-600);
            padding: 0.2rem 0.4rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        @media (min-width: 768px) {
            .category-count {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
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

        .featured-card, .news-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .featured-card:hover,
            .news-card:hover {
                transform: none;
            }
            
            .category-btn:hover {
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
                    <a href="index">Home</a>
                    <a href="announcements">Announcements</a>
                    <a href="news" class="active">News</a>
                    <a href="events">Events</a>
                    <a href="committee">Committee</a>
                    <a href="gallery">Gallery</a>
                </nav>
                <div class="login-buttons">
                    <a href="auth/student_login" class="login-btn btn-student">
                        <i class="fas fa-user-graduate"></i> Student
                    </a>
                    <a href="auth/login" class="login-btn btn-committee">
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
                    <a href="index">Home</a>
                    <a href="announcements">Announcements</a>
                    <a href="news" class="active">News</a>
                    <a href="events">Events</a>
                    <a href="committee">Committee</a>
                    <a href="gallery">Gallery</a>
                </nav>
            </div>
            <div class="mobile-login-buttons">
                <a href="auth/student_login" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student Portal
                </a>
                <a href="auth/login" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee Portal
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header" data-aos="fade-up">
            <h1 class="page-title">Campus News</h1>
            <p class="page-subtitle">Stay updated with the latest happenings, stories, and achievements from RP Musanze College community</p>
        </div>

        <!-- Category Filter -->
        <div class="category-filter" data-aos="fade-up" data-aos-delay="100">
            <a href="news?category=all" class="category-btn <?php echo $current_category === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> All News
            </a>
            <?php foreach ($categories as $category): ?>
                <a href="news?category=<?php echo $category['slug']; ?>" 
                   class="category-btn <?php echo $current_category === $category['slug'] ? 'active' : ''; ?>">
                    <i class="fas fa-<?php echo $category['icon']; ?>"></i>
                    <?php echo htmlspecialchars($category['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Featured News -->
        <?php if (!empty($featured_news)): ?>
        <section class="featured-section" data-aos="fade-up" data-aos-delay="200">
            <h2 class="section-title">
                <i class="fas fa-star"></i>
                Featured Stories
            </h2>
            <div class="featured-grid">
                <?php foreach ($featured_news as $featured): ?>
                    <article class="featured-card">
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
                        </div>
                        <div class="featured-content">
                            <div class="featured-category" style="background: <?php echo $featured['category_color']; ?>20; color: <?php echo $featured['category_color']; ?>;">
                                <i class="fas fa-<?php echo $featured['category_icon']; ?>"></i>
                                <?php echo htmlspecialchars($featured['category_name']); ?>
                            </div>
                            <h3 class="featured-title">
                                <a href="news_single?id=<?php echo $featured['id']; ?>">
                                    <?php echo htmlspecialchars($featured['title']); ?>
                                </a>
                            </h3>
                            <p class="featured-excerpt"><?php echo htmlspecialchars($featured['excerpt'] ?? substr($featured['content'], 0, 120) . '...'); ?></p>
                            <div class="featured-meta">
                                <span>
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($featured['created_at'])); ?>
                                </span>
                                <span>
                                    <i class="fas fa-eye"></i>
                                    <?php echo $featured['views_count']; ?> views
                                </span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- News Content -->
        <section class="news-section">
            <!-- Main News Grid -->
            <div class="news-content">
                <?php if (empty($news)): ?>
                    <div class="empty-state">
                        <i class="fas fa-newspaper"></i>
                        <h3>No News Found</h3>
                        <p>There are no news articles in this category at the moment. Please check back later.</p>
                    </div>
                <?php else: ?>
                    <div class="news-grid">
                        <?php foreach ($news as $item): ?>
                            <article class="news-card">
                                <div class="news-image">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['title']); ?>"
                                             loading="lazy">
                                    <?php else: ?>
                                        <div style="width: 100%; height: 100%; background: <?php echo $item['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                            <i class="fas fa-<?php echo $item['category_icon']; ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="news-content">
                                    <div class="news-category" style="background: <?php echo $item['category_color']; ?>20; color: <?php echo $item['category_color']; ?>;">
                                        <i class="fas fa-<?php echo $item['category_icon']; ?>"></i>
                                        <?php echo htmlspecialchars($item['category_name']); ?>
                                    </div>
                                    <h3 class="news-title">
                                        <a href="news_single?id=<?php echo $item['id']; ?>">
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="news-excerpt"><?php echo htmlspecialchars($item['excerpt'] ?? substr($item['content'], 0, 100) . '...'); ?></p>
                                    <div class="news-meta">
                                        <span>
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($item['created_at'])); ?>
                                        </span>
                                        <span class="news-views">
                                            <i class="fas fa-eye"></i>
                                            <?php echo $item['views_count']; ?>
                                        </span>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="news?category=<?php echo $current_category; ?>&page=<?php echo $page - 1; ?>">
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
                                <a href="news?category=<?php echo $current_category; ?>&page=<?php echo $i; ?>" 
                                   class="<?php echo $i == $page ? 'current' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="news?category=<?php echo $current_category; ?>&page=<?php echo $page + 1; ?>">
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
                <!-- Popular News -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="300">
                    <h3 class="sidebar-title">
                        <i class="fas fa-fire"></i>
                        Popular News
                    </h3>
                    <ul class="popular-list">
                        <?php if (empty($popular_news)): ?>
                            <li class="popular-item">
                                <span style="color: var(--gray-600);">No popular news yet</span>
                            </li>
                        <?php else: ?>
                            <?php foreach ($popular_news as $popular): ?>
                                <li class="popular-item">
                                    <a href="news_single?id=<?php echo $popular['id']; ?>">
                                        <?php echo htmlspecialchars($popular['title']); ?>
                                    </a>
                                    <div class="popular-meta">
                                        <span><?php echo htmlspecialchars($popular['category_name']); ?></span>
                                        <span><i class="fas fa-eye"></i> <?php echo $popular['views_count']; ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Categories -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="400">
                    <h3 class="sidebar-title">
                        <i class="fas fa-folder"></i>
                        News Categories
                    </h3>
                    <ul class="categories-list">
                        <li class="category-item">
                            <a href="news?category=all">
                                All Categories
                                <span class="category-count"><?php echo $total_news; ?></span>
                            </a>
                        </li>
                        <?php foreach ($category_counts as $cat): ?>
                            <li class="category-item">
                                <a href="news?category=<?php echo $cat['slug']; ?>">
                                    <i class="fas fa-<?php 
                                        $icon_map = [
                                            'entertainment' => 'music',
                                            'sports' => 'running',
                                            'culture' => 'palette',
                                            'academic' => 'graduation-cap',
                                            'campus-life' => 'university',
                                            'innovation' => 'lightbulb'
                                        ];
                                        echo $icon_map[$cat['slug']] ?? 'newspaper';
                                    ?>"></i>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                    <span class="category-count"><?php echo $cat['news_count']; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Quick Stats -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="500">
                    <h3 class="sidebar-title">
                        <i class="fas fa-chart-bar"></i>
                        News Stats
                    </h3>
                    <div style="color: var(--gray-600); font-size: 0.8rem; line-height: 1.8;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Total News:</span>
                            <strong><?php echo $total_news; ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>Featured:</span>
                            <strong><?php echo count($featured_news); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>Categories:</span>
                            <strong><?php echo count($categories); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>This Month:</span>
                            <strong><?php echo $month_count; ?></strong>
                        </div>
                    </div>
                </div>

                <!-- About Campus News -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="600">
                    <h3 class="sidebar-title">
                        <i class="fas fa-info-circle"></i>
                        About Campus News
                    </h3>
                    <p style="color: var(--gray-600); font-size: 0.8rem; line-height: 1.5;">
                        Stay informed about everything happening at RP Musanze College. From sports achievements to cultural events, academic breakthroughs to campus innovations - we cover it all to keep our community connected and engaged.
                    </p>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <small style="color: var(--gray-500);">
                            <i class="fas fa-clock"></i> Updated daily by Public Relations Committee
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
                    <li><a href="announcements"><i class="fas fa-chevron-right"></i> Announcements</a></li>
                    <li><a href="news"><i class="fas fa-chevron-right"></i> Campus News</a></li>
                    <li><a href="events"><i class="fas fa-chevron-right"></i> Events</a></li>
                    <li><a href="committee"><i class="fas fa-chevron-right"></i> Committee</a></li>
                </ul>
            </div>
            
            <div class="footer-links-group">
                <h4 class="footer-heading">Student Resources</h4>
                <ul class="footer-links">
                    <li><a href="https://www.rp.ac.rw/announcement" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> Academic Calendar</a></li>
                    <li><a href="https://www.google.com/maps/search/rp+musanze+college" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> Campus Map</a></li>
                    <li><a href="../assets/rp_handbook.pdf"><i class="fas fa-chevron-right"></i> Student Handbook</a></li>
                    <li><a href="gallery"><i class="fas fa-chevron-right"></i> Gallery</a></li>
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
        
        // Card click navigation
        document.querySelectorAll('.news-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' || e.target.closest('a')) {
                    return;
                }
                const newsLink = this.querySelector('a[href*="news_single"]');
                if (newsLink) {
                    window.location.href = newsLink.href;
                }
            });
        });
        
        document.querySelectorAll('.featured-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' || e.target.closest('a')) {
                    return;
                }
                const newsLink = this.querySelector('a[href*="news_single"]');
                if (newsLink) {
                    window.location.href = newsLink.href;
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