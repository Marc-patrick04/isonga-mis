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
        $query .= " AND (a.title ILIKE ? OR a.content ILIKE ? OR a.excerpt ILIKE ?)";
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
    error_log("Announcements query error: " . $e->getMessage());
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

// Get category counts for sidebar
try {
    $stmt = $pdo->query("
        SELECT category, COUNT(*) as count 
        FROM announcements 
        WHERE category IS NOT NULL AND category != '' 
        GROUP BY category 
        ORDER BY count DESC
    ");
    $category_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $category_counts = [];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Official announcements from RPSU leadership and college administration at Rwanda Polytechnic Musanze College">
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
            margin-bottom: 1rem;
        }

        .back-link:hover {
            gap: 0.75rem;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        @media (min-width: 768px) {
            .filter-section {
                padding: 1.5rem;
                margin-bottom: 2rem;
            }
        }

        .filter-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .filter-grid {
                flex-direction: row;
                align-items: flex-end;
            }
        }

        .search-box {
            position: relative;
            flex: 2;
        }

        .search-box input {
            width: 100%;
            padding: 0.7rem 1rem 0.7rem 2.5rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
            background: var(--white);
        }

        @media (min-width: 768px) {
            .search-box input {
                padding: 0.875rem 1rem 0.875rem 2.5rem;
                font-size: 1rem;
            }
        }

        .search-box input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            font-size: 0.9rem;
        }

        .filter-select {
            flex: 1;
            padding: 0.7rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: var(--white);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236c757d' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 12px 12px;
            padding-right: 2rem;
        }

        @media (min-width: 768px) {
            .filter-select {
                padding: 0.875rem 1rem;
                font-size: 1rem;
                background-size: 16px 16px;
            }
        }

        .filter-select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .filter-btn, .clear-filters {
            padding: 0.7rem 1rem;
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
            font-size: 0.85rem;
            min-height: 42px;
        }

        @media (min-width: 768px) {
            .filter-btn, .clear-filters {
                padding: 0.875rem 1.5rem;
                font-size: 0.9rem;
            }
        }

        .filter-btn {
            background: var(--gradient-primary);
            color: white;
        }

        .filter-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .clear-filters {
            background: var(--gray-200);
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .clear-filters:hover {
            background: var(--gray-300);
        }

        /* Content Layout */
        .content-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        @media (min-width: 768px) {
            .content-wrapper {
                flex-direction: row;
                gap: 2rem;
            }
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .sidebar {
                order: 2;
                flex: 0 0 280px;
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

        .recent-list, .filter-list {
            list-style: none;
        }

        .recent-item, .filter-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .recent-item:last-child, .filter-item:last-child {
            border-bottom: none;
        }

        .recent-item a, .filter-item a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
            transition: var(--transition);
            display: block;
            line-height: 1.4;
        }

        @media (min-width: 768px) {
            .recent-item a, .filter-item a {
                font-size: 0.875rem;
            }
        }

        .recent-item a:hover, .filter-item a:hover {
            color: var(--primary);
        }

        .recent-date {
            color: var(--gray-600);
            font-size: 0.7rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .filter-item a {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filter-count {
            background: var(--gray-200);
            color: var(--gray-600);
            padding: 0.2rem 0.4rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        /* Announcements Content */
        .announcements-content {
            flex: 1;
        }

        .announcements-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .announcements-grid {
                gap: 1.5rem;
            }
        }

        .announcement-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .announcement-card {
                padding: 1.5rem;
            }
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

        .announcement-card:hover {
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
            margin-bottom: 0.75rem;
        }

        @media (min-width: 640px) {
            .announcement-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .announcement-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .announcement-title {
                font-size: 1.25rem;
            }
        }

        .announcement-date {
            color: var(--gray-600);
            font-size: 0.7rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .announcement-date {
                font-size: 0.875rem;
            }
        }

        .announcement-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .announcement-meta {
                flex-direction: row;
                align-items: center;
                gap: 1rem;
                font-size: 0.875rem;
            }
        }

        .announcement-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .author-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .author-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.875rem;
            }
        }

        .announcement-excerpt {
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }

        @media (min-width: 768px) {
            .announcement-excerpt {
                font-size: 0.95rem;
                margin-bottom: 1rem;
            }
        }

        .announcement-content {
            color: var(--gray-800);
            line-height: 1.6;
            font-size: 0.85rem;
        }

        @media (min-width: 768px) {
            .announcement-content {
                font-size: 1rem;
            }
        }

        .announcement-content p {
            margin-bottom: 0.75rem;
        }

        .content-truncated {
            max-height: 120px;
            overflow: hidden;
            position: relative;
        }

        .content-truncated:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 50px;
            background: linear-gradient(transparent, var(--white));
            pointer-events: none;
        }

        .read-more {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.8rem;
            margin-top: 0.75rem;
            padding: 0.5rem 0;
        }

        @media (min-width: 768px) {
            .read-more {
                font-size: 0.875rem;
                margin-top: 1rem;
            }
        }

        .read-more:hover {
            gap: 0.75rem;
        }

        /* Single Announcement View */
        .single-announcement {
            width: 100%;
        }

        .single-announcement .announcement-card {
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .single-announcement .announcement-card {
                padding: 2rem;
            }
        }

        .single-announcement .announcement-title {
            font-size: 1.25rem;
        }

        @media (min-width: 768px) {
            .single-announcement .announcement-title {
                font-size: 1.75rem;
            }
        }

        .single-announcement .announcement-content {
            font-size: 0.9rem;
        }

        @media (min-width: 768px) {
            .single-announcement .announcement-content {
                font-size: 1.05rem;
                line-height: 1.7;
            }
        }

        .single-announcement .announcement-content p {
            margin-bottom: 1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .empty-state {
                padding: 4rem 2rem;
            }
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        @media (min-width: 768px) {
            .empty-state i {
                font-size: 4rem;
            }
        }

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .empty-state h3 {
                font-size: 1.5rem;
                margin-bottom: 0.75rem;
            }
        }

        .empty-state p {
            font-size: 0.8rem;
            max-width: 400px;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .empty-state p {
                font-size: 1rem;
            }
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

        .announcement-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .announcement-card:hover {
                transform: none;
            }
            
            .filter-btn:hover,
            .clear-filters:hover,
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
            
            .announcement-card {
                animation: none;
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
                
                <article class="announcement-card" data-aos="fade-up">
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
            <div class="page-header" data-aos="fade-up">
                <h1 class="page-title">Official Announcements</h1>
                <p class="page-subtitle">Stay updated with the latest official communications from RPSU leadership and college administration</p>
            </div>

            <!-- Filter Section -->
            <div class="filter-section" data-aos="fade-up" data-aos-delay="100">
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
                            <i class="fas fa-filter"></i> Apply
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
                    <div class="sidebar-card" data-aos="fade-up" data-aos-delay="200">
                        <h3 class="sidebar-title">
                            <i class="fas fa-clock"></i>
                            Recent Updates
                        </h3>
                        <ul class="recent-list">
                            <?php if (empty($recent_announcements)): ?>
                                <li class="recent-item">
                                    <span style="color: var(--gray-600);">No recent announcements</span>
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

                    <!-- Categories -->
                    <div class="sidebar-card" data-aos="fade-up" data-aos-delay="300">
                        <h3 class="sidebar-title">
                            <i class="fas fa-folder"></i>
                            Categories
                        </h3>
                        <ul class="filter-list">
                            <li class="filter-item">
                                <a href="announcements.php">
                                    All Announcements
                                    <span class="filter-count"><?php echo count($announcements); ?></span>
                                </a>
                            </li>
                            <?php foreach ($category_counts as $cat): ?>
                                <li class="filter-item">
                                    <a href="announcements?category=<?php echo urlencode($cat['category']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($cat['category'])); ?>
                                        <span class="filter-count"><?php echo $cat['count']; ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- About Announcements -->
                    <div class="sidebar-card" data-aos="fade-up" data-aos-delay="400">
                        <h3 class="sidebar-title">
                            <i class="fas fa-info-circle"></i>
                            About Announcements
                        </h3>
                        <p style="color: var(--gray-600); font-size: 0.8rem; line-height: 1.5;">
                            Official communications from RPSU leadership, college administration, and committee members. 
                            Check regularly for important updates affecting student life and activities.
                        </p>
                    </div>
                </aside>

                <!-- Announcements Content -->
                <main class="announcements-content">
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
                                <article class="announcement-card" data-aos="fade-up" data-aos-delay="<?php echo 200 + ($index * 50); ?>">
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
        
        // Auto-submit form when filters change on mobile
        const categorySelect = document.querySelector('select[name="category"]');
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                if (window.innerWidth < 768) {
                    this.closest('form').submit();
                }
            });
        }
        
        // Initialize content truncation
        function initializeContentTruncation() {
            document.querySelectorAll('.content-truncated').forEach(content => {
                if (content.scrollHeight > 120) {
                    content.style.maxHeight = '120px';
                    content.style.overflow = 'hidden';
                } else {
                    content.classList.remove('content-truncated');
                }
            });
        }
        
        initializeContentTruncation();
        
        // Animation on scroll for cards
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
        
        document.querySelectorAll('.announcement-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>