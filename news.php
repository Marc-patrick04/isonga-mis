<?php
session_start();
require_once 'config/database.php';

// Get all news categories - FIXED: use 'active' string
try {
    $categories_stmt = $pdo->query("SELECT * FROM news_categories WHERE is_active = 'active' ORDER BY name");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// Get featured news - FIXED: use true for boolean
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

// Get total news count for pagination - FIXED: use parameter binding
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

// Get news with pagination - FIXED: PostgreSQL syntax with OFFSET
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

// Get category counts - FIXED: use proper PostgreSQL syntax
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

// Get this month's news count - FIXED: PostgreSQL date functions
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="icon" href="assets/images/logo.png">
    <style>
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
            flex-direction: column;
            justify-content: center;
            align-items: center;
            width: 30px;
            height: 30px;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 1001;
        }

        .mobile-menu-toggle span {
            width: 25px;
            height: 3px;
            background: var(--gray-800);
            margin: 2px 0;
            transition: var(--transition);
            border-radius: 2px;
        }

        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }

        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }

        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -6px);
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

        /* Featured News */
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

        /* News Grid */
        .news-section {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
            align-items: start;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .news-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .news-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .news-image {
            height: 160px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
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
            padding: 1.25rem;
        }

        .news-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .news-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .news-excerpt {
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        .news-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--gray-600);
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

        .popular-list {
            list-style: none;
        }

        .popular-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .popular-item:last-child {
            border-bottom: none;
        }

        .popular-item a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            display: block;
            line-height: 1.4;
        }

        .popular-item a:hover {
            color: var(--primary);
        }

        .popular-meta {
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
            .news-section {
                grid-template-columns: 1fr;
            }

            .sidebar {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                gap: 1rem;
                padding: 0 1rem;
            }

            .nav-links {
                gap: 1rem;
                flex-wrap: wrap;
                justify-content: center;
            }

            .login-buttons {
                width: 100%;
                justify-content: center;
            }

            .page-title {
                font-size: 2rem;
            }

            .featured-grid {
                grid-template-columns: 1fr;
            }

            .news-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        @media (max-width: 480px) {
            .nav-links {
                flex-direction: column;
                gap: 0.5rem;
            }

            .category-filter {
                flex-direction: column;
                align-items: center;
            }

            .category-btn {
                width: 100%;
                max-width: 250px;
                justify-content: center;
            }
        }

        /* Enhanced Mobile Responsiveness */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }

            .nav-links {
                display: none;
                position: fixed;
                top: 80px;
                left: 0;
                width: 100%;
                background: var(--white);
                flex-direction: column;
                padding: 1.5rem;
                box-shadow: var(--shadow-lg);
                z-index: 999;
                gap: 1rem;
            }

            .nav-links.active {
                display: flex;
            }

            .login-buttons {
                display: none;
                position: fixed;
                bottom: 0;
                left: 0;
                width: 100%;
                background: var(--white);
                padding: 1rem;
                box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
                z-index: 999;
            }

            .login-buttons.active {
                display: flex;
            }

            .main-container {
                margin-top: 70px;
                padding: 1.5rem 1rem;
            }

            .page-title {
                font-size: 1.8rem;
            }

            .page-subtitle {
                font-size: 1rem;
            }

            .featured-section {
                margin-bottom: 2.5rem;
            }

            .featured-grid {
                gap: 1.5rem;
            }

            .featured-image {
                height: 180px;
            }

            .featured-content {
                padding: 1.25rem;
            }

            .featured-title {
                font-size: 1.1rem;
            }

            .news-grid {
                gap: 1.25rem;
            }

            .news-image {
                height: 140px;
            }

            .news-content {
                padding: 1rem;
            }

            .news-title {
                font-size: 0.95rem;
            }

            .sidebar {
                gap: 1.25rem;
            }

            .sidebar-card {
                padding: 1.25rem;
            }

            .pagination {
                flex-wrap: wrap;
                gap: 0.25rem;
            }

            .pagination a, .pagination span {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .footer {
                padding: 2rem 1rem 1rem;
            }

            .footer-content {
                gap: 1.5rem;
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

            .page-title {
                font-size: 1.6rem;
            }

            .page-subtitle {
                font-size: 0.9rem;
            }

            .section-title {
                font-size: 1.3rem;
            }

            .category-btn {
                padding: 0.6rem 1.25rem;
                font-size: 0.8rem;
            }

            .featured-image {
                height: 160px;
            }

            .featured-content {
                padding: 1rem;
            }

            .featured-title {
                font-size: 1rem;
            }

            .featured-excerpt {
                font-size: 0.8rem;
            }

            .news-image {
                height: 120px;
            }

            .news-content {
                padding: 0.875rem;
            }

            .news-title {
                font-size: 0.9rem;
            }

            .news-excerpt {
                font-size: 0.75rem;
            }

            .sidebar-card {
                padding: 1rem;
            }

            .sidebar-title {
                font-size: 1rem;
            }

            .footer-heading {
                font-size: 0.9rem;
            }

            .footer-links a {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 360px) {
            .main-container {
                padding: 1rem 0.75rem;
            }

            .page-title {
                font-size: 1.4rem;
            }

            .page-subtitle {
                font-size: 0.85rem;
            }

            .featured-image {
                height: 140px;
            }

            .news-image {
                height: 100px;
            }

            .category-btn {
                padding: 0.5rem 1rem;
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

        .featured-card, .news-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Touch-friendly improvements */
        @media (hover: none) {
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
            
            <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
            
            <nav class="nav-links" id="navLinks">
                <a href="index.php">Home</a>
                <a href="announcements.php">Announcements</a>
                <a href="news.php" class="active">News</a>
                <a href="events.php">Events</a>
                <a href="committee.php">Committee</a>
                <a href="gallery.php">Gallery</a>
            </nav>
            <div class="login-buttons" id="loginButtons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Campus News</h1>
            <p class="page-subtitle">Stay updated with the latest happenings, stories, and achievements from RP Musanze College community</p>
        </div>

        <!-- Category Filter -->
        <div class="category-filter">
            <a href="news.php?category=all" class="category-btn <?php echo $current_category === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> All News
            </a>
            <?php foreach ($categories as $category): ?>
                <a href="news.php?category=<?php echo $category['slug']; ?>" 
                   class="category-btn <?php echo $current_category === $category['slug'] ? 'active' : ''; ?>"
                   style="<?php echo $current_category === $category['slug'] ? 'background-color: ' . $category['color'] . '; border-color: ' . $category['color'] : ''; ?>">
                    <i class="fas fa-<?php echo $category['icon']; ?>"></i>
                    <?php echo htmlspecialchars($category['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Featured News -->
        <?php if (!empty($featured_news)): ?>
        <section class="featured-section">
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
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <?php endif; ?>
                            <div class="featured-image-placeholder" style="<?php echo empty($featured['image_url']) ? 'display: block;' : 'display: none;'; ?> width: 100%; height: 100%; background: <?php echo $featured['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem;">
                                <i class="fas fa-<?php echo $featured['category_icon']; ?>"></i>
                            </div>
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
    <a href="news_single.php?id=<?php echo $featured['id']; ?>" style="color: inherit; text-decoration: none;">
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
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <?php endif; ?>
                                    <div class="featured-image-placeholder" style="<?php echo empty($item['image_url']) ? 'display: flex;' : 'display: none;'; ?> width: 100%; height: 100%; background: <?php echo $item['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                        <i class="fas fa-<?php echo $item['category_icon']; ?>"></i>
                                    </div>
                                </div>
                                <div class="news-content">
                                    <div class="news-category" style="background: <?php echo $item['category_color']; ?>20; color: <?php echo $item['category_color']; ?>;">
                                        <i class="fas fa-<?php echo $item['category_icon']; ?>"></i>
                                        <?php echo htmlspecialchars($item['category_name']); ?>
                                    </div>
<h3 class="news-title">
    <a href="news_single.php?id=<?php echo $item['id']; ?>" style="color: inherit; text-decoration: none;">
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
                                <a href="news.php?category=<?php echo $current_category; ?>&page=<?php echo $page - 1; ?>">
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
                                <a href="news.php?category=<?php echo $current_category; ?>&page=<?php echo $i; ?>" 
                                   class="<?php echo $i == $page ? 'current' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="news.php?category=<?php echo $current_category; ?>&page=<?php echo $page + 1; ?>">
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
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-fire"></i>
                        Popular News
                    </h3>
                    <ul class="popular-list">
                        <?php if (empty($popular_news)): ?>
                            <li class="popular-item">
                                <span style="color: var(--gray-600); font-size: 0.875rem;">No popular news yet</span>
                            </li>
                        <?php else: ?>
                            <?php foreach ($popular_news as $popular): ?>
                                <li class="popular-item">
<a href="news_single.php?id=<?php echo $popular['id']; ?>">
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
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-folder"></i>
                        News Categories
                    </h3>
                    <ul class="categories-list">
                        <li class="category-item">
                            <a href="news.php?category=all">
                                All Categories
                                <span class="category-count"><?php echo $total_news; ?></span>
                            </a>
                        </li>
                        <?php 
                        // Get category counts
                        try {
                            $category_counts_stmt = $pdo->query("
                                SELECT nc.id, nc.name, nc.slug, nc.color, COUNT(n.id) as news_count
                                FROM news_categories nc
                                LEFT JOIN news n ON nc.id = n.category_id AND n.status = 'published'
                                WHERE nc.is_active = 1
                                GROUP BY nc.id, nc.name, nc.slug, nc.color
                                ORDER BY news_count DESC
                            ");
                            $category_counts = $category_counts_stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $category_counts = [];
                        }
                        ?>
                        
                        <?php foreach ($category_counts as $cat): ?>
                            <li class="category-item">
                                <a href="news.php?category=<?php echo $cat['slug']; ?>">
                                    <i class="fas fa-<?php 
                                        // Map category names to icons
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
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-chart-bar"></i>
                        News Stats
                    </h3>
                    <div style="color: var(--gray-600); font-size: 0.875rem; line-height: 1.8;">
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
                            <strong>
                                <?php 
                                try {
                                    $month_count = $pdo->query("
                                        SELECT COUNT(*) as count 
                                        FROM news 
                                        WHERE status = 'published' 
                                        AND MONTH(created_at) = MONTH(CURRENT_DATE())
                                        AND YEAR(created_at) = YEAR(CURRENT_DATE())
                                    ")->fetch()['count'];
                                    echo $month_count;
                                } catch (PDOException $e) {
                                    echo '0';
                                }
                                ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <!-- About Campus News -->
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-info-circle"></i>
                        About Campus News
                    </h3>
                    <p style="color: var(--gray-600); font-size: 0.875rem; line-height: 1.5;">
                        Stay informed about everything happening at RP Musanze College. From sports achievements to cultural events, academic breakthroughs to campus innovations - we cover it all to keep our community connected and engaged.
                    </p>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <small style="color: var(--gray-500); font-size: 0.75rem;">
                            <i class="fas fa-clock"></i> Updated daily by Public Relations Committee
                        </small>
                    </div>
                </div>
            </aside>
        </section>
    </div>

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
        const navLinks = document.getElementById('navLinks');
        const loginButtons = document.getElementById('loginButtons');

        mobileMenuToggle.addEventListener('click', function() {
            this.classList.toggle('active');
            navLinks.classList.toggle('active');
            loginButtons.classList.toggle('active');
            
            // Prevent body scrolling when menu is open
            if (navLinks.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.nav-container') && 
                navLinks.classList.contains('active')) {
                mobileMenuToggle.classList.remove('active');
                navLinks.classList.remove('active');
                loginButtons.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Close mobile menu when window is resized above mobile breakpoint
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                mobileMenuToggle.classList.remove('active');
                navLinks.classList.remove('active');
                loginButtons.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Image error handling
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.addEventListener('error', function() {
                    this.style.display = 'none';
                    const placeholder = this.nextElementSibling;
                    if (placeholder && placeholder.classList.contains('featured-image-placeholder')) {
                        placeholder.style.display = 'flex';
                    }
                });
            });
        });

        // Smooth scrolling for category filter
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                // Add loading state
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                
                // Remove active class from all buttons
                document.querySelectorAll('.category-btn').forEach(b => {
                    b.classList.remove('active');
                    b.style.backgroundColor = '';
                    b.style.borderColor = '';
                });
                
                // Add active class to clicked button
                this.classList.add('active');
                const categoryColor = this.style.backgroundColor;
                if (categoryColor) {
                    this.style.backgroundColor = categoryColor;
                    this.style.borderColor = categoryColor;
                }
            });
        });

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
        document.querySelectorAll('.featured-card, .news-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // View count simulation (in a real app, this would be done via AJAX)
        document.querySelectorAll('.news-card, .featured-card').forEach(card => {
            card.addEventListener('click', function() {
                const viewsElement = this.querySelector('.news-views') || this.querySelector('.featured-meta span:last-child');
                if (viewsElement) {
                    const currentViews = parseInt(viewsElement.textContent.match(/\d+/)[0]);
                    viewsElement.innerHTML = viewsElement.innerHTML.replace(/\d+/, currentViews + 1);
                }
            });
        });

        // Category filter active state persistence
        document.addEventListener('DOMContentLoaded', function() {
            const currentCategory = '<?php echo $current_category; ?>';
            if (currentCategory !== 'all') {
                const activeBtn = document.querySelector(`.category-btn[href*="${currentCategory}"]`);
                if (activeBtn) {
                    // Get category color from data attribute or compute it
                    const category = <?php echo json_encode($categories); ?>.find(cat => cat.slug === currentCategory);
                    if (category) {
                        activeBtn.style.backgroundColor = category.color;
                        activeBtn.style.borderColor = category.color;
                        activeBtn.style.color = 'white';
                    }
                }
            }
        });

        // Add hover effects to news cards
        document.querySelectorAll('.news-card, .featured-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.cursor = 'pointer';
            });
            
// Add proper navigation to news cards
card.addEventListener('click', function(e) {
    // Don't navigate if clicking on a link inside the card
    if (e.target.tagName === 'A' || e.target.closest('a')) {
        return;
    }
    
    // Find the news link in this card
    const newsLink = this.querySelector('a[href*="news_single.php"]');
    if (newsLink) {
        window.location.href = newsLink.href;
    }
});
        });

        // Touch-friendly improvements for mobile
        if ('ontouchstart' in window) {
            document.body.classList.add('touch-device');
            
            // Increase tap target sizes for mobile
            document.querySelectorAll('.category-btn, .login-btn, .pagination a').forEach(btn => {
                btn.style.minHeight = '44px';
                btn.style.minWidth = '44px';
                btn.style.display = 'flex';
                btn.style.alignItems = 'center';
                btn.style.justifyContent = 'center';
            });
        }
    </script>
</body>
</html>