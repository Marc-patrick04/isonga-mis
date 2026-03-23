<?php
session_start();
require_once 'config/database.php';

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Get all announcements from database
try {
    $query = "
        SELECT a.*, u.full_name as author_name 
        FROM announcements a 
        LEFT JOIN users u ON a.author_id = u.id 
        WHERE 1=1
    ";
    
    $params = [];
    
    // Apply category filter
    if ($category_filter !== 'all') {
        $query .= " AND a.category = ?";
        $params[] = $category_filter;
    }
    
    // Apply search filter
    if (!empty($search_query)) {
        $query .= " AND (a.title LIKE ? OR a.content LIKE ? OR a.excerpt LIKE ?)";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $query .= " ORDER BY a.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $announcements = [];
}

// Get unique categories for filter dropdown
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM announcements WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}

// Get recent announcements for sidebar
try {
    $stmt = $pdo->query("
        SELECT * FROM announcements 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_announcements = [];
}

// Check if we're viewing a single announcement
$single_announcement = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $announcement_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name as author_name 
            FROM announcements a 
            LEFT JOIN users u ON a.author_id = u.id 
            WHERE a.id = ?
        ");
        $stmt->execute([$announcement_id]);
        $single_announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $single_announcement = null;
    }
}

$page_title = $single_announcement ? htmlspecialchars($single_announcement['title']) . " - Announcements" : "Announcements - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Official announcements from RPSU leadership and college administration at Rwanda Polytechnic Musanze College">
    
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
        /* CSS Variables - Enhanced */
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
            --space-xs: 0.5rem;
            --space-sm: 1rem;
            --space-md: 1.5rem;
            --space-lg: 2rem;
            --space-xl: 3rem;
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        *:focus {
            outline: 2px solid var(--primary-light);
            outline-offset: 2px;
        }

        html {
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.6;
            color: var(--gray-900);
            background: var(--light);
            overflow-x: hidden;
            font-size: 16px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Header & Navigation - Mobile First */
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
            padding: 0.5rem 0;
        }

        .nav-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 var(--space-md);
        }

        /* Logo Section */
        .logo-section {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            min-width: 0;
            flex: 1;
        }

        .logos {
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .logo {
            height: 40px;
            width: auto;
            transition: var(--transition);
        }

        .brand-text {
            flex-shrink: 1;
            min-width: 0;
            overflow: hidden;
        }

        .brand-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.025em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .brand-text p {
            font-size: 0.75rem;
            color: var(--gray-600);
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Desktop Navigation - Hidden on Mobile */
        .desktop-nav {
            display: none;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: flex;
            background: none;
            border: none;
            width: 44px;
            height: 44px;
            font-size: 1.5rem;
            color: var(--gray-800);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .mobile-menu-btn:hover,
        .mobile-menu-btn:focus {
            background: var(--gray-100);
        }

        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 70px;
            left: 0;
            width: 100%;
            height: calc(100vh - 70px);
            background: var(--white);
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .mobile-menu.active {
            transform: translateX(0);
        }

        .mobile-nav {
            padding: var(--space-md);
        }

        .mobile-nav .nav-links {
            flex-direction: column;
            gap: 0;
        }

        .mobile-nav .nav-links a {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 1rem;
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: block;
        }

        .mobile-nav .nav-links a.active {
            color: var(--primary);
            background: var(--gray-100);
        }

        .mobile-nav .nav-links a:last-child {
            border-bottom: none;
        }

        .mobile-login-buttons {
            padding: var(--space-md);
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }

        .mobile-login-buttons .login-btn {
            width: 100%;
            justify-content: center;
            padding: 1rem;
            font-size: 0.9rem;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .login-btn:hover,
        .login-btn:focus {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Main Content */
        .main-container {
            width: 100%;
            max-width: 1200px;
            margin: 70px auto 0;
            padding: var(--space-md);
            display: flex;
            flex-direction: column;
            gap: var(--space-lg);
        }

        /* Page Header */
        .page-header {
            text-align: center;
            padding: var(--space-lg) 0;
        }

        .page-title {
            font-size: clamp(1.75rem, 4vw, 2.5rem);
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.75rem;
            letter-spacing: -0.025em;
            line-height: 1.2;
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            padding: 0.75rem 0;
            transition: var(--transition);
        }

        .back-link:hover,
        .back-link:focus {
            gap: 0.75rem;
        }

        /* Filter Section - Mobile Optimized */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: var(--space-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .filter-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .search-box {
            position: relative;
            width: 100%;
        }

        .search-box input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.5rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            background: var(--white);
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            pointer-events: none;
        }

        .filter-select {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            font-size: 1rem;
            background: var(--white);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236c757d' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px 16px;
            padding-right: 2.5rem;
        }

        .filter-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
            outline: none;
        }

        .filter-buttons {
            display: flex;
            gap: 0.75rem;
            width: 100%;
        }

        .filter-btn,
        .clear-filters {
            flex: 1;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            min-height: 44px; /* Minimum touch target */
        }

        .filter-btn {
            background: var(--gradient-primary);
            color: white;
        }

        .filter-btn:hover,
        .filter-btn:focus {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .clear-filters {
            background: var(--gray-200);
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .clear-filters:hover,
        .clear-filters:focus {
            background: var(--gray-300);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Main Content Layout */
        .content-wrapper {
            display: flex;
            flex-direction: column;
            gap: var(--space-lg);
        }

        /* Sidebar - Mobile First */
        .sidebar {
            order: -1; /* Move sidebar to top on mobile */
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
        }

        .sidebar-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: var(--space-md);
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

        .recent-list {
            list-style: none;
        }

        .recent-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .recent-item:last-child {
            border-bottom: none;
        }

        .recent-item a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            display: block;
            line-height: 1.4;
        }

        .recent-item a:hover,
        .recent-item a:focus {
            color: var(--primary);
        }

        .recent-date {
            color: var(--gray-600);
            font-size: 0.8rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .filter-list {
            list-style: none;
        }

        .filter-item {
            padding: 0.5rem 0;
        }

        .filter-item a {
            color: var(--gray-600);
            text-decoration: none;
            font-size: 0.95rem;
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
        }

        .filter-item a:hover,
        .filter-item a:focus {
            color: var(--primary);
            padding-left: 0.5rem;
        }

        .filter-count {
            background: var(--gray-200);
            color: var(--gray-600);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 24px;
            text-align: center;
        }

        /* Announcements Content */
        .announcements-content {
            width: 100%;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: var(--space-xl) var(--space-md);
            color: var(--gray-600);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            color: var(--gray-600);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 1.5rem;
            line-height: 1.6;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Announcements Grid */
        .announcements-grid {
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
        }

        .announcement-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: var(--space-md);
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        .announcement-card:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--gradient-primary);
            transition: var(--transition);
        }

        .announcement-card:hover,
        .announcement-card:focus-within {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .announcement-card:hover:before {
            width: 6px;
        }

        .announcement-header {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .announcement-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.3;
        }

        .announcement-date {
            color: var(--gray-600);
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .announcement-meta {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .announcement-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .author-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.875rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .announcement-excerpt {
            color: var(--gray-600);
            line-height: 1.6;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }

        .announcement-content {
            color: var(--gray-800);
            line-height: 1.7;
            font-size: 1rem;
        }

        .announcement-content p {
            margin-bottom: 1rem;
        }

        .announcement-content ul,
        .announcement-content ol {
            margin-bottom: 1rem;
            padding-left: 1.5rem;
        }

        .announcement-content li {
            margin-bottom: 0.5rem;
        }

        .read-more {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.95rem;
            margin-top: 1rem;
            padding: 0.5rem 0;
        }

        .read-more:hover,
        .read-more:focus {
            gap: 0.75rem;
        }

        /* Content truncation for list view */
        .content-truncated {
            max-height: 150px;
            overflow: hidden;
            position: relative;
        }

        .content-truncated:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: linear-gradient(transparent, var(--white));
            pointer-events: none;
        }

        /* Single Announcement View */
        .single-announcement {
            width: 100%;
        }

        .single-announcement .announcement-card {
            padding: var(--space-md);
        }

        .single-announcement .announcement-title {
            font-size: clamp(1.5rem, 3vw, 1.75rem);
        }

        .single-announcement .announcement-content {
            font-size: 1.05rem;
            line-height: 1.8;
        }

        /* Footer */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: var(--space-xl) var(--space-md) var(--space-md);
            margin-top: var(--space-xl);
        }

        .footer-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--space-lg);
            margin-bottom: var(--space-lg);
        }

        .footer-info {
            text-align: center;
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
            font-size: 0.95rem;
        }

        .social-links {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-size: 1rem;
        }

        .social-links a:hover,
        .social-links a:focus {
            background: var(--primary);
            transform: translateY(-2px);
        }

        .footer-heading {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--warning);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-links a:hover,
        .footer-links a:focus {
            color: var(--warning);
        }

        .footer-bottom {
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            font-size: 0.85rem;
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

        .announcement-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Responsive Design */
        @media (min-width: 640px) {
            /* Small tablets & large phones */
            .announcement-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
            
            .announcement-meta {
                flex-direction: row;
                align-items: center;
            }
            
            .filter-grid {
                flex-direction: row;
                align-items: flex-end;
            }
            
            .search-box {
                flex: 2;
            }
            
            .filter-select {
                flex: 1;
            }
            
            .filter-buttons {
                flex: none;
                width: auto;
            }
        }

        @media (min-width: 768px) {
            /* Tablets */
            .mobile-menu-btn {
                display: none;
            }
            
            .desktop-nav {
                display: flex;
                align-items: center;
                gap: var(--space-lg);
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
            }
            
            .nav-links a:hover::after,
            .nav-links a:focus::after {
                width: 100%;
            }
            
            .nav-links a.active {
                color: var(--primary);
            }
            
            .nav-links a.active::after {
                width: 100%;
            }
            
            .login-buttons {
                display: flex;
                gap: var(--space-sm);
                align-items: center;
            }
            
            .login-btn {
                padding: 0.6rem 1.25rem;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                transition: var(--transition);
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.85rem;
            }
            
            .mobile-menu {
                display: none !important;
            }
            
            /* Content layout for tablets */
            .content-wrapper {
                flex-direction: row;
                gap: var(--space-xl);
            }
            
            .sidebar {
                order: 2;
                flex: 0 0 300px;
            }
            
            .announcements-content {
                flex: 1;
            }
            
            /* Footer adjustments */
            .footer-content {
                grid-template-columns: 2fr 1fr 1fr;
                gap: var(--space-xl);
            }
            
            .footer-info {
                text-align: left;
            }
            
            .social-links {
                justify-content: flex-start;
            }
        }

        @media (min-width: 1024px) {
            /* Desktop */
            .main-container {
                padding: var(--space-xl) var(--space-md);
            }
            
            .page-header {
                padding: var(--space-xl) 0;
            }
            
            .announcement-card {
                padding: var(--space-lg);
            }
            
            .single-announcement .announcement-card {
                padding: var(--space-xl);
            }
            
            /* Footer adjustments */
            .footer-content {
                grid-template-columns: 2fr 1fr 1fr 1fr;
            }
        }

        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .announcement-card:hover {
                transform: none;
            }
            
            .login-btn:hover,
            .filter-btn:hover,
            .clear-filters:hover {
                transform: none;
            }
            
            /* Larger touch targets */
            .nav-links a,
            .footer-links a,
            .recent-item a,
            .filter-item a {
                padding: 0.875rem 0;
            }
            
            .btn,
            .login-btn,
            .filter-btn,
            .clear-filters {
                min-height: 48px;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
            
            .announcement-card {
                animation: none;
            }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            body {
                background: #0f172a;
                color: #e2e8f0;
            }
            
            .header {
                background: rgba(15, 23, 42, 0.98);
                border-bottom-color: rgba(255, 255, 255, 0.05);
            }
            
            .brand-text h1 {
                background: var(--gradient-primary);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            
            .filter-section,
            .sidebar-card,
            .announcement-card,
            .single-announcement .announcement-card {
                background: #1e293b;
                border-color: #334155;
            }
            
            .search-box input,
            .filter-select {
                background: #1e293b;
                border-color: #475569;
                color: #e2e8f0;
            }
            
            .search-box input:focus,
            .filter-select:focus {
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.2);
            }
            
            .clear-filters {
                background: #334155;
                color: #cbd5e1;
                border-color: #475569;
            }
            
            .clear-filters:hover {
                background: #475569;
            }
            
            .announcement-title,
            .sidebar-title,
            .page-title {
                color: #f1f5f9;
            }
            
            .announcement-content,
            .announcement-excerpt,
            .recent-item a,
            .filter-item a,
            .page-subtitle {
                color: #cbd5e1;
            }
            
            .announcement-date,
            .announcement-meta,
            .recent-date {
                color: #94a3b8;
            }
            
            .content-truncated:after {
                background: linear-gradient(transparent, #1e293b);
            }
            
            .footer {
                background: #0f172a;
                border-top: 1px solid #334155;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header" id="header">
        <div class="container nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/logo.png" alt="RPSU Logo" class="logo logo-rpsu" loading="lazy">
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
                    <a href="announcements.php" class="active">Announcements</a>
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
                    <a href="announcements.php" class="active">Announcements</a>
                    <a href="news.php">News</a>
                    <a href="events.php">Events</a>
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
        <?php if ($single_announcement): ?>
            <!-- Single Announcement View -->
            <div class="single-announcement">
                <a href="announcements.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to All Announcements
                </a>
                
                <article class="announcement-card">
                    <div class="announcement-header">
                        <h1 class="announcement-title">
                            <?php echo htmlspecialchars($single_announcement['title']); ?>
                        </h1>
                        <div class="announcement-date">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('F j, Y', strtotime($single_announcement['created_at'])); ?>
                        </div>
                    </div>
                    
                    <div class="announcement-meta">
                        <?php if (!empty($single_announcement['author_name'])): ?>
                            <div class="announcement-author">
                                <div class="author-avatar">
                                    <?php echo strtoupper(substr($single_announcement['author_name'], 0, 1)); ?>
                                </div>
                                <span>By <?php echo htmlspecialchars($single_announcement['author_name']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="announcement-time">
                            <i class="fas fa-clock"></i>
                            <?php echo date('g:i A', strtotime($single_announcement['created_at'])); ?>
                        </div>
                    </div>

                    <div class="announcement-content">
                        <?php 
                        $content = $single_announcement['content'];
                        $content = nl2br(htmlspecialchars($content));
                        echo $content;
                        ?>
                    </div>
                </article>
            </div>

        <?php else: ?>
            <!-- Announcements List View -->
            
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Official Announcements</h1>
                <p class="page-subtitle">Stay updated with the latest official communications from RPSU leadership and college administration</p>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="filter-grid">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search announcements..." 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               aria-label="Search announcements">
                    </div>
                    
                    <div>
                        <select name="category" class="filter-select" aria-label="Filter by category">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" 
                                        <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($category)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-buttons">
                        <button type="submit" class="filter-btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <?php if ($category_filter !== 'all' || !empty($search_query)): ?>
                            <a href="announcements.php" class="clear-filters">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="content-wrapper">
                <!-- Sidebar -->
                <aside class="sidebar">
                    <!-- Recent Announcements -->
                    <div class="sidebar-card">
                        <h3 class="sidebar-title">
                            <i class="fas fa-clock"></i>
                            Recent Updates
                        </h3>
                        <ul class="recent-list">
                            <?php if (empty($recent_announcements)): ?>
                                <li class="recent-item">
                                    <span style="color: var(--gray-600); font-size: 0.95rem;">No recent announcements</span>
                                </li>
                            <?php else: ?>
                                <?php foreach ($recent_announcements as $recent): ?>
                                    <li class="recent-item">
                                        <a href="announcements.php?id=<?php echo $recent['id']; ?>">
                                            <?php echo htmlspecialchars($recent['title']); ?>
                                        </a>
                                        <div class="recent-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($recent['created_at'])); ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Quick Info -->
                    <div class="sidebar-card">
                        <h3 class="sidebar-title">
                            <i class="fas fa-info-circle"></i>
                            About Announcements
                        </h3>
                        <p style="color: var(--gray-600); font-size: 0.95rem; line-height: 1.5;">
                            Official communications from RPSU leadership, college administration, and committee members. 
                            Check regularly for important updates affecting student life and activities.
                        </p>
                    </div>
                </aside>

                <!-- Announcements Content -->
                <main class="announcements-content" id="announcements-content">
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h3>No Announcements Found</h3>
                            <p>
                                <?php if ($category_filter !== 'all' || !empty($search_query)): ?>
                                    No announcements match your current filters. Try adjusting your search criteria.
                                <?php else: ?>
                                    There are no announcements at this time. Please check back later for updates.
                                <?php endif; ?>
                            </p>
                            <?php if ($category_filter !== 'all' || !empty($search_query)): ?>
                                <a href="announcements.php" class="login-btn btn-student" style="margin-top: 1rem; display: inline-block;">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="announcements-grid">
                            <?php foreach ($announcements as $index => $announcement): ?>
                                <article class="announcement-card" id="announcement-<?php echo $announcement['id']; ?>">
                                    <div class="announcement-header">
                                        <h2 class="announcement-title">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                        </h2>
                                        <div class="announcement-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('F j, Y', strtotime($announcement['created_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="announcement-meta">
                                        <?php if (!empty($announcement['author_name'])): ?>
                                            <div class="announcement-author">
                                                <div class="author-avatar">
                                                    <?php echo strtoupper(substr($announcement['author_name'], 0, 1)); ?>
                                                </div>
                                                <span>By <?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="announcement-time">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('g:i A', strtotime($announcement['created_at'])); ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($announcement['excerpt'])): ?>
                                        <div class="announcement-excerpt">
                                            <?php echo htmlspecialchars($announcement['excerpt']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="announcement-content content-truncated">
                                        <?php 
                                        $content = $announcement['content'];
                                        $content = nl2br(htmlspecialchars($content));
                                        echo $content;
                                        ?>
                                    </div>

                                    <a href="announcements.php?id=<?php echo $announcement['id']; ?>" class="read-more">
                                        Read Full Announcement <i class="fas fa-arrow-right"></i>
                                    </a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </main>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="footer" aria-labelledby="footer-heading">
        <div class="container">
            <div class="footer-content">
                <div class="footer-info">
                    <div class="footer-logo">
                        <img src="assets/images/rp_logo.png" alt="RP Musanze College" class="logo" loading="lazy">
                    </div>
                    <p class="footer-description">
                        Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
                    </p>
                    <div class="social-links">
                        <a href="https://twitter.com/MusanzecollegSU" target="_blank" rel="noopener noreferrer" aria-label="Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://www.facebook.com/RP-Musanze-College" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://www.linkedin.com/in/rp-musanze-college-3963b0203" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="https://www.instagram.com/rpmusanzecollege_su" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                    </div>
                </div>
                
                <div class="footer-links-group">
                    <h4 class="footer-heading">Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                        <li><a href="announcements.php"><i class="fas fa-chevron-right"></i> Announcements</a></li>
                        <li><a href="news.php"><i class="fas fa-chevron-right"></i> Campus News</a></li>
                        <li><a href="events.php"><i class="fas fa-chevron-right"></i> Events</a></li>
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
                <p>&copy; 2025 Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Initialize AOS with reduced motion support
        document.addEventListener('DOMContentLoaded', function() {
            // Check for reduced motion preference
            const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            
            AOS.init({
                duration: 800,
                once: true,
                offset: 100,
                disable: prefersReducedMotion
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
            
            // Throttle scroll events for performance
            let ticking = false;
            window.addEventListener('scroll', function() {
                if (!ticking) {
                    window.requestAnimationFrame(function() {
                        updateHeader();
                        ticking = false;
                    });
                    ticking = true;
                }
            });
            
            updateHeader(); // Initial check

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
            
            // Close mobile menu when clicking outside or pressing Escape
            document.addEventListener('click', function(event) {
                if (mobileMenu.classList.contains('active') && 
                    !mobileMenu.contains(event.target) && 
                    !mobileMenuBtn.contains(event.target)) {
                    toggleMobileMenu();
                }
            });
            
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && mobileMenu.classList.contains('active')) {
                    toggleMobileMenu();
                }
            });

            // Auto-submit form when filters change on mobile
            const categorySelect = document.querySelector('select[name="category"]');
            if (categorySelect) {
                categorySelect.addEventListener('change', function() {
                    // Only auto-submit on mobile/tablet
                    if (window.innerWidth < 768) {
                        this.closest('form').submit();
                    }
                });
            }

            // Add search debouncing
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.closest('form').submit();
                    }, 500);
                });
            }

            // Initialize content truncation
            initializeContentTruncation();

            // Smooth scrolling for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    if (targetId === '#') return;
                    
                    const target = document.querySelector(targetId);
                    if (target) {
                        // Close mobile menu if open
                        if (mobileMenu && mobileMenu.classList.contains('active')) {
                            toggleMobileMenu();
                        }
                        
                        const headerHeight = header.offsetHeight;
                        const targetPosition = target.getBoundingClientRect().top + window.pageYOffset;
                        const offsetPosition = targetPosition - headerHeight - 20;
                        
                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });
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

            // Observe announcement cards for animation
            document.querySelectorAll('.announcement-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });

            // Performance monitoring
            if ('performance' in window) {
                window.addEventListener('load', function() {
                    setTimeout(() => {
                        const timing = performance.timing;
                        const loadTime = timing.loadEventEnd - timing.navigationStart;
                        console.log('Page load time: ' + loadTime + 'ms');
                    }, 0);
                });
            }

            // Service Worker registration for PWA capabilities (optional)
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register('/sw.js').then(
                        function(registration) {
                            console.log('ServiceWorker registration successful');
                        },
                        function(err) {
                            console.log('ServiceWorker registration failed: ', err);
                        }
                    );
                });
            }
        });

        function initializeContentTruncation() {
            document.querySelectorAll('.content-truncated').forEach(content => {
                if (content.scrollHeight > 150) {
                    content.style.maxHeight = '150px';
                    content.style.overflow = 'hidden';
                } else {
                    content.classList.remove('content-truncated');
                }
            });
        }

        // Error handling
        window.addEventListener('error', function(e) {
            console.error('Error occurred:', e.error);
        });

        // Offline detection
        window.addEventListener('online', function() {
            document.body.classList.remove('offline');
            console.log('You are online');
        });

        window.addEventListener('offline', function() {
            document.body.classList.add('offline');
            console.log('You are offline');
        });
    </script>
</body>
</html>