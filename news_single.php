<?php
session_start();
require_once 'config/database.php';

// Get news ID from URL
$news_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($news_id <= 0) {
    header('Location: news.php');
    exit();
}

// Get the specific news article
try {
    $stmt = $pdo->prepare("
        SELECT n.*, nc.name as category_name, nc.color as category_color, nc.icon as category_icon,
               u.full_name as author_name, u.role as author_role
        FROM news n 
        LEFT JOIN news_categories nc ON n.category_id = nc.id 
        LEFT JOIN users u ON n.author_id = u.id 
        WHERE n.id = ? AND n.status = 'published'
    ");
    $stmt->execute([$news_id]);
    $news = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$news) {
        header('Location: news.php');
        exit();
    }
    
    // Increment view count
    $update_stmt = $pdo->prepare("UPDATE news SET views_count = views_count + 1 WHERE id = ?");
    $update_stmt->execute([$news_id]);
    
} catch (PDOException $e) {
    header('Location: news.php');
    exit();
}

// Get related news (same category)
try {
    $related_stmt = $pdo->prepare("
        SELECT n.*, nc.name as category_name, nc.color as category_color
        FROM news n 
        LEFT JOIN news_categories nc ON n.category_id = nc.id 
        WHERE n.category_id = ? AND n.id != ? AND n.status = 'published'
        ORDER BY n.created_at DESC 
        LIMIT 3
    ");
    $related_stmt->execute([$news['category_id'], $news_id]);
    $related_news = $related_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $related_news = [];
}

$page_title = $news['title'] . " - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Add all the CSS from news.php here, plus additional styles for single news page */
        
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
        
        .news-article {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
        }

        .article-header {
            position: relative;
        }

        .article-image {
            height: 400px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .article-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .article-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.7));
            padding: 2rem;
            color: white;
        }

        .article-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1rem;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: white;
        }

        .article-title {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1rem;
            color: white;
        }

        .article-meta {
            display: flex;
            gap: 2rem;
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .article-content {
            padding: 3rem;
        }

        .article-body {
            font-size: 1.1rem;
            line-height: 1.8;
            color: var(--gray-800);
        }

        .article-body p {
            margin-bottom: 1.5rem;
        }

        .article-body h2, .article-body h3 {
            margin: 2rem 0 1rem;
            color: var(--gray-900);
        }

        .article-body ul, .article-body ol {
            margin-bottom: 1.5rem;
            padding-left: 2rem;
        }

        .article-body li {
            margin-bottom: 0.5rem;
        }

        .article-footer {
            border-top: 1px solid var(--gray-200);
            padding: 2rem 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .related-news {
            margin-top: 3rem;
        }

        .related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .back-link:hover {
            gap: 0.75rem;
        }

        @media (max-width: 768px) {
            .article-title {
                font-size: 2rem;
            }
            
            .article-content {
                padding: 2rem;
            }
            
            .article-image {
                height: 300px;
            }
            
            .article-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header (same as news.php) -->
    <header class="header" id="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/rp_logo.png" alt="RP Musanze College" class="logo logo-rp">
                    <img src="assets/images/rpsu_logo.jpeg" alt="RPSU" class="logo logo-rpsu">
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
                <a href="events.php">Events</a>
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
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Back Link -->
        <a href="news.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to All News
        </a>

        <!-- News Article -->
        <article class="news-article">
            <div class="article-header">
                <div class="article-image">
                    <?php if (!empty($news['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($news['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($news['title']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <?php endif; ?>
                    <div class="featured-image-placeholder" style="<?php echo empty($news['image_url']) ? 'display: flex;' : 'display: none;'; ?> width: 100%; height: 100%; background: <?php echo $news['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 4rem;">
                        <i class="fas fa-<?php echo $news['category_icon']; ?>"></i>
                    </div>
                </div>
                <div class="article-overlay">
                    <div class="article-category">
                        <i class="fas fa-<?php echo $news['category_icon']; ?>"></i>
                        <?php echo htmlspecialchars($news['category_name']); ?>
                    </div>
                    <h1 class="article-title"><?php echo htmlspecialchars($news['title']); ?></h1>
                    <div class="article-meta">
                        <span><i class="fas fa-user"></i> By <?php echo htmlspecialchars($news['author_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y', strtotime($news['created_at'])); ?></span>
                        <span><i class="fas fa-eye"></i> <?php echo ($news['views_count'] + 1); ?> views</span>
                        <span><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($news['created_at'])); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="article-content">
                <div class="article-body">
                    <?php 
                    // Format and display the content with proper HTML
                    $content = $news['content'];
                    
                    // Convert line breaks to paragraphs
                    $paragraphs = explode("\n", $content);
                    foreach ($paragraphs as $paragraph) {
                        $paragraph = trim($paragraph);
                        if (!empty($paragraph)) {
                            echo '<p>' . htmlspecialchars($paragraph) . '</p>';
                        }
                    }
                    ?>
                </div>
            </div>
            
            <div class="article-footer">
                <div>
                    <strong>Published:</strong> 
                    <?php echo date('F j, Y \a\t g:i A', strtotime($news['created_at'])); ?>
                </div>
                <div>
                    <strong>Category:</strong> 
                    <?php echo htmlspecialchars($news['category_name']); ?>
                </div>
            </div>
        </article>

        <!-- Related News -->
        <?php if (!empty($related_news)): ?>
        <section class="related-news">
            <h2 class="section-title">
                <i class="fas fa-newspaper"></i>
                Related News
            </h2>
            <div class="related-grid">
                <?php foreach ($related_news as $related): ?>
                    <article class="news-card">
                        <div class="news-image">
                            <?php if (!empty($related['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($related['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($related['title']); ?>"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            <div class="featured-image-placeholder" style="<?php echo empty($related['image_url']) ? 'display: flex;' : 'display: none;'; ?> width: 100%; height: 100%; background: <?php echo $related['category_color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                <i class="fas fa-<?php echo $related['category_icon']; ?>"></i>
                            </div>
                        </div>
                        <div class="news-content">
                            <div class="news-category" style="background: <?php echo $related['category_color']; ?>20; color: <?php echo $related['category_color']; ?>;">
                                <i class="fas fa-<?php echo $related['category_icon']; ?>"></i>
                                <?php echo htmlspecialchars($related['category_name']); ?>
                            </div>
                            <h3 class="news-title">
                                <a href="news_single.php?id=<?php echo $related['id']; ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($related['title']); ?>
                                </a>
                            </h3>
                            <p class="news-excerpt"><?php echo htmlspecialchars(substr($related['content'], 0, 100) . '...'); ?></p>
                            <div class="news-meta">
                                <span>
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($related['created_at'])); ?>
                                </span>
                                <span class="news-views">
                                    <i class="fas fa-eye"></i>
                                    <?php echo $related['views_count']; ?>
                                </span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <!-- Footer (same as news.php) -->
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
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div class="footer-links-group">
                <h4 class="footer-heading">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="announcements.php">Announcements</a></li>
                    <li><a href="news.php">Campus News</a></li>
                    <li><a href="events.php">Events</a></li>
                </ul>
            </div>
            <div class="footer-links-group">
                <h4 class="footer-heading">Student Resources</h4>
                <ul class="footer-links">
                    <li><a href="#">Academic Calendar</a></li>
                    <li><a href="#">Campus Map</a></li>
                    <li><a href="#">Student Handbook</a></li>
                    <li><a href="gallery.php">Gallery</a></li>
                </ul>
            </div>
            <div class="footer-links-group">
                <h4 class="footer-heading">Contact Info</h4>
                <ul class="footer-links">
                    <li><i class="fas fa-map-marker-alt"></i> Rwanda Polytechnic Musanze</li>
                    <li><i class="fas fa-phone"></i> +250 788 123 456</li>
                    <li><i class="fas fa-envelope"></i> rpsu@rpmusanze.ac.rw</li>
                    <li><i class="fas fa-clock"></i> Mon - Fri: 8:00 - 17:00</li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2025 Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Add the same JavaScript from news.php here
        window.addEventListener('scroll', function() {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>