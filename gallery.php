<?php
session_start();
require_once 'config/database.php';

// Get all gallery categories
try {
    $categories_stmt = $pdo->query("SELECT * FROM gallery_categories WHERE status = 'active' ORDER BY name");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// Get filter parameters
$current_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$view_type = isset($_GET['view']) ? $_GET['view'] : 'grid';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Build filters with parameter binding
$params = [];
$where_conditions = ["g.status = 'active'"];

if ($current_category !== 'all') {
    $where_conditions[] = "g.category_id = ?";
    $params[] = $current_category;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Build sort order
$sort_order = '';
switch ($sort_by) {
    case 'oldest':
        $sort_order = "g.uploaded_at ASC";
        break;
    case 'popular':
        $sort_order = "g.views_count DESC";
        break;
    case 'featured':
        $sort_order = "g.featured DESC, g.uploaded_at DESC";
        break;
    case 'title':
        $sort_order = "g.title ASC";
        break;
    default:
        $sort_order = "g.uploaded_at DESC";
        break;
}

// Get featured images
try {
    $featured_sql = "
        SELECT g.*, gc.name as category_name
        FROM gallery_images g 
        LEFT JOIN gallery_categories gc ON g.category_id = gc.id 
        WHERE g.status = 'active' AND g.featured = true
    ";
    
    $featured_params = [];
    if ($current_category !== 'all') {
        $featured_sql .= " AND g.category_id = ?";
        $featured_params[] = $current_category;
    }
    
    $featured_sql .= " ORDER BY $sort_order LIMIT 12";
    
    $featured_stmt = $pdo->prepare($featured_sql);
    $featured_stmt->execute($featured_params);
    $featured_images = $featured_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Featured images error: " . $e->getMessage());
    $featured_images = [];
}

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 24;
$offset = ($page - 1) * $per_page;

// Get total images count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM gallery_images g 
    WHERE g.status = 'active'
";
$count_params = [];

if ($current_category !== 'all') {
    $count_sql .= " AND g.category_id = ?";
    $count_params[] = $current_category;
}

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$total_images = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_images / $per_page);

// Get images with pagination
try {
    $images_sql = "
        SELECT g.*, gc.name as category_name
        FROM gallery_images g 
        LEFT JOIN gallery_categories gc ON g.category_id = gc.id 
        WHERE g.status = 'active'
    ";
    
    $images_params = [];
    if ($current_category !== 'all') {
        $images_sql .= " AND g.category_id = ?";
        $images_params[] = $current_category;
    }
    
    $images_sql .= " ORDER BY $sort_order LIMIT ? OFFSET ?";
    $images_params[] = $per_page;
    $images_params[] = $offset;
    
    $images_stmt = $pdo->prepare($images_sql);
    $images_stmt->execute($images_params);
    $images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Gallery images query error: " . $e->getMessage());
    $images = [];
}

// Get recent uploads for sidebar
try {
    $recent_stmt = $pdo->prepare("
        SELECT g.id, g.title, g.image_path, g.uploaded_at, gc.name as category_name
        FROM gallery_images g 
        LEFT JOIN gallery_categories gc ON g.category_id = gc.id 
        WHERE g.status = 'active'
        ORDER BY g.uploaded_at DESC
        LIMIT 6
    ");
    $recent_stmt->execute();
    $recent_images = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_images = [];
}

// Get gallery statistics with category counts
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_images,
            COUNT(CASE WHEN featured = true THEN 1 END) as featured_images,
            COUNT(DISTINCT category_id) as total_categories,
            COALESCE(SUM(views_count), 0) as total_views
        FROM gallery_images 
        WHERE status = 'active'
    ");
    $stats_stmt->execute();
    $gallery_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gallery_stats = ['total_images' => 0, 'featured_images' => 0, 'total_categories' => 0, 'total_views' => 0];
}

// Get category counts
try {
    $cat_counts_stmt = $pdo->prepare("
        SELECT gc.id, gc.name, gc.icon, COUNT(g.id) as image_count
        FROM gallery_categories gc
        LEFT JOIN gallery_images g ON gc.id = g.category_id AND g.status = 'active'
        WHERE gc.status = 'active'
        GROUP BY gc.id, gc.name, gc.icon
        ORDER BY image_count DESC
    ");
    $cat_counts_stmt->execute();
    $category_counts = $cat_counts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $category_counts = [];
}

$page_title = "Photo Gallery - RPSU Musanze College";

// Function to get correct image URL
function getImageUrl($imagePath) {
    if (empty($imagePath)) {
        return '';
    }
    if (strpos($imagePath, 'assets/') === 0) {
        return $imagePath;
    }
    return 'assets/uploads/gallery/' . $imagePath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Explore our collection of campus moments, events, and activities captured through the lens at RP Musanze College.">
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

        /* Gallery Controls */
        .gallery-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .gallery-controls {
                margin-bottom: 2rem;
            }
        }

        .category-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .category-filter {
                gap: 0.75rem;
            }
        }

        .category-btn {
            padding: 0.4rem 0.8rem;
            border: 2px solid var(--gray-200);
            border-radius: 50px;
            background: var(--white);
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.7rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        @media (min-width: 768px) {
            .category-btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.875rem;
                gap: 0.5rem;
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

        .view-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .sort-control {
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--gray-700);
            font-size: 0.7rem;
            cursor: pointer;
        }

        @media (min-width: 768px) {
            .sort-control {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }

        .view-btn {
            padding: 0.4rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--gray-600);
            cursor: pointer;
            transition: var(--transition);
            width: 32px;
            height: 32px;
        }

        @media (min-width: 768px) {
            .view-btn {
                width: 36px;
                height: 36px;
                padding: 0.5rem;
            }
        }

        .view-btn:hover, .view-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        /* Featured Section */
        .featured-section {
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .featured-section {
                margin-bottom: 3rem;
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

        @media (min-width: 640px) {
            .featured-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .featured-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5rem;
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
            top: 0.75rem;
            left: 0.75rem;
            background: var(--warning);
            color: var(--white);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            z-index: 2;
        }

        @media (min-width: 768px) {
            .featured-badge {
                top: 1rem;
                left: 1rem;
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
            }
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: var(--transition);
        }

        .featured-card:hover .image-overlay {
            opacity: 1;
        }

        .overlay-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .overlay-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--white);
            color: var(--gray-800);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .overlay-btn {
                width: 40px;
                height: 40px;
                font-size: 0.875rem;
            }
        }

        .overlay-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: scale(1.1);
        }

        .featured-content {
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .featured-content {
                padding: 1.25rem;
            }
        }

        .image-category {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            background: var(--gray-100);
            color: var(--gray-700);
        }

        @media (min-width: 768px) {
            .image-category {
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
                margin-bottom: 0.75rem;
            }
        }

        .image-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .image-title {
                font-size: 1.1rem;
            }
        }

        .image-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.65rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .image-meta {
                font-size: 0.75rem;
            }
        }

        /* Gallery Layout */
        .gallery-section {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        @media (min-width: 1024px) {
            .gallery-section {
                grid-template-columns: 1fr 300px;
                gap: 2rem;
            }
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .gallery-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 1.5rem;
            }
        }

        .gallery-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .image-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            cursor: pointer;
        }

        .image-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .gallery-list .image-card {
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 768px) {
            .gallery-list .image-card {
                flex-direction: row;
                height: 120px;
            }
        }

        .image-preview {
            height: 140px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
        }

        @media (min-width: 768px) {
            .image-preview {
                height: 180px;
            }
        }

        .gallery-list .image-preview {
            height: 120px;
        }

        @media (min-width: 768px) {
            .gallery-list .image-preview {
                width: 160px;
                height: 100%;
                flex-shrink: 0;
            }
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .image-card:hover .image-preview img {
            transform: scale(1.05);
        }

        .image-content {
            padding: 0.75rem;
        }

        @media (min-width: 768px) {
            .image-content {
                padding: 1rem;
            }
        }

        .gallery-list .image-content {
            flex: 1;
        }

        .image-description {
            color: var(--gray-600);
            line-height: 1.4;
            margin-bottom: 0.5rem;
            font-size: 0.7rem;
            display: -webkit-box;
            /* -webkit-line-clamp: 2; */
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .image-description {
                font-size: 0.8rem;
                margin-bottom: 0.75rem;
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

        .recent-list {
            list-style: none;
        }

        .recent-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            gap: 0.75rem;
            align-items: center;
            cursor: pointer;
        }

        .recent-item:last-child {
            border-bottom: none;
        }

        .recent-thumb {
            width: 50px;
            height: 50px;
            border-radius: var(--border-radius);
            overflow: hidden;
            flex-shrink: 0;
            background: var(--gray-200);
        }

        @media (min-width: 768px) {
            .recent-thumb {
                width: 60px;
                height: 60px;
            }
        }

        .recent-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .recent-content {
            flex: 1;
        }

        .recent-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .recent-title {
                font-size: 0.875rem;
            }
        }

        .recent-meta {
            font-size: 0.65rem;
            color: var(--gray-600);
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

        .categories-list {
            list-style: none;
        }

        .category-item {
            padding: 0.4rem 0;
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
            }
        }

        /* Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .image-modal.active {
            display: flex;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }

        .modal-image {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
            border-radius: var(--border-radius);
        }

        .modal-info {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 1rem;
        }

        @media (min-width: 768px) {
            .modal-info {
                padding: 1.5rem;
                margin-top: 1rem;
            }
        }

        .modal-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }

        @media (min-width: 768px) {
            .modal-title {
                font-size: 1.5rem;
            }
        }

        .modal-description {
            color: var(--gray-600);
            margin-bottom: 0.75rem;
            line-height: 1.5;
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .modal-description {
                font-size: 0.875rem;
                margin-bottom: 1rem;
            }
        }

        .modal-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .modal-meta {
                font-size: 0.875rem;
            }
        }

        .modal-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        @media (min-width: 768px) {
            .modal-close {
                font-size: 2rem;
                top: -50px;
            }
        }

        .modal-nav {
            position: absolute;
            top: 50%;
            width: 100%;
            display: flex;
            justify-content: space-between;
            transform: translateY(-50%);
            padding: 0 0.5rem;
        }

        @media (min-width: 768px) {
            .modal-nav {
                padding: 0 1rem;
            }
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .nav-btn {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Image Placeholder */
        .image-placeholder {
            width: 100%;
            height: 100%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            font-size: 1.5rem;
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

        .featured-card, .image-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .featured-card:hover,
            .image-card:hover {
                transform: none;
            }
            
            .category-btn:hover,
            .view-btn:hover {
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
                    <a href="news">News</a>
                    <a href="events">Events</a>
                    <a href="committee">Committee</a>
                    <a href="gallery" class="active">Gallery</a>
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
                    <a href="news">News</a>
                    <a href="events">Events</a>
                    <a href="committee">Committee</a>
                    <a href="gallery" class="active">Gallery</a>
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
            <h1 class="page-title">Photo Gallery</h1>
            <p class="page-subtitle">Explore our collection of campus moments, events, and activities captured through the lens.</p>
        </div>

        <!-- Gallery Controls -->
        <div class="gallery-controls" data-aos="fade-up" data-aos-delay="100">
            <div class="category-filter">
                <a href="gallery?category=all&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>" 
                   class="category-btn <?php echo $current_category === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> All Photos
                </a>
                <?php foreach ($category_counts as $category): ?>
                    <a href="gallery?category=<?php echo $category['id']; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>" 
                       class="category-btn <?php echo $current_category == $category['id'] ? 'active' : ''; ?>">
                        <i class="fas fa-<?php echo isset($category['icon']) ? $category['icon'] : 'images'; ?>"></i>
                        <?php echo htmlspecialchars($category['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <div class="view-controls">
                <select class="sort-control" id="sortControl">
                    <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="popular" <?php echo $sort_by === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                    <option value="featured" <?php echo $sort_by === 'featured' ? 'selected' : ''; ?>>Featured</option>
                    <option value="title" <?php echo $sort_by === 'title' ? 'selected' : ''; ?>>Title A-Z</option>
                </select>
                
                <button class="view-btn <?php echo $view_type === 'grid' ? 'active' : ''; ?>" onclick="changeView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button class="view-btn <?php echo $view_type === 'list' ? 'active' : ''; ?>" onclick="changeView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Featured Images -->
        <?php if (!empty($featured_images)): ?>
        <section class="featured-section" data-aos="fade-up" data-aos-delay="200">
            <h2 class="section-title">
                <i class="fas fa-star"></i>
                Featured Photos
            </h2>
            <div class="featured-grid">
                <?php foreach ($featured_images as $image): 
                    $image_url = getImageUrl($image['image_path']);
                ?>
                    <article class="featured-card" onclick="openImageModal(<?php echo $image['id']; ?>)">
                        <div class="featured-image">
                            <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                 alt="<?php echo htmlspecialchars($image['title']); ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="image-placeholder" style="display: none;">
                                <i class="fas fa-image"></i>
                            </div>
                            <div class="featured-badge">
                                <i class="fas fa-star"></i> Featured
                            </div>
                            <div class="image-overlay">
                                <div class="overlay-buttons">
                                    <a href="<?php echo htmlspecialchars($image_url); ?>" 
                                       class="overlay-btn" download title="Download" onclick="event.stopPropagation()">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button class="overlay-btn" onclick="event.stopPropagation(); openImageModal(<?php echo $image['id']; ?>)" title="View Details">
                                        <i class="fas fa-expand"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="featured-content">
                            <div class="image-category">
                                <i class="fas fa-folder"></i>
                                <?php echo htmlspecialchars($image['category_name']); ?>
                            </div>
                            <h3 class="image-title"><?php echo htmlspecialchars($image['title']); ?></h3>
                            <div class="image-meta">
                                <span class="image-views">
                                    <i class="fas fa-eye"></i>
                                    <?php echo number_format($image['views_count']); ?> views
                                </span>
                                <span class="image-date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($image['uploaded_at'])); ?>
                                </span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Gallery Content -->
        <section class="gallery-section">
            <!-- Main Gallery Grid/List -->
            <div class="gallery-content">
                <?php if (empty($images)): ?>
                    <div class="empty-state">
                        <i class="fas fa-images"></i>
                        <h3>No Photos Found</h3>
                        <p>
                            <?php 
                            if ($current_category === 'all') {
                                echo 'There are no photos in the gallery at the moment. Please check back later.';
                            } else {
                                $category_name = '';
                                foreach ($category_counts as $cat) {
                                    if ($cat['id'] == $current_category) {
                                        $category_name = $cat['name'];
                                        break;
                                    }
                                }
                                echo "No photos found in the {$category_name} category.";
                            }
                            ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="<?php echo $view_type === 'list' ? 'gallery-list' : 'gallery-grid'; ?>" id="galleryContainer">
                        <?php foreach ($images as $image): 
                            $image_url = getImageUrl($image['image_path']);
                        ?>
                            <article class="image-card" onclick="openImageModal(<?php echo $image['id']; ?>)">
                                <div class="image-preview">
                                    <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                         alt="<?php echo htmlspecialchars($image['title']); ?>"
                                         loading="lazy"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="image-placeholder" style="display: none;">
                                        <i class="fas fa-image"></i>
                                    </div>
                                    <?php if ($image['featured']): ?>
                                        <div class="featured-badge">
                                            <i class="fas fa-star"></i> Featured
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="image-content">
                                    <div class="image-category">
                                        <i class="fas fa-folder"></i>
                                        <?php echo htmlspecialchars($image['category_name']); ?>
                                    </div>
                                    <h3 class="image-title"><?php echo htmlspecialchars($image['title']); ?></h3>
                                    <?php if ($view_type === 'list' && !empty($image['description'])): ?>
                                        <p class="image-description"><?php echo htmlspecialchars(substr($image['description'], 0, 100)) . (strlen($image['description']) > 100 ? '...' : ''); ?></p>
                                    <?php endif; ?>
                                    <div class="image-meta">
                                        <span class="image-views">
                                            <i class="fas fa-eye"></i>
                                            <?php echo number_format($image['views_count']); ?> views
                                        </span>
                                        <span class="image-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($image['uploaded_at'])); ?>
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
                                <a href="gallery?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page - 1; ?>">
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
                                <a href="gallery?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $i; ?>" 
                                   class="<?php echo $i == $page ? 'current' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="gallery?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page + 1; ?>">
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
                <!-- Recent Uploads -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="300">
                    <h3 class="sidebar-title">
                        <i class="fas fa-clock"></i>
                        Recent Uploads
                    </h3>
                    <ul class="recent-list">
                        <?php if (empty($recent_images)): ?>
                            <li class="recent-item">
                                <span style="color: var(--gray-600);">No recent uploads</span>
                            </li>
                        <?php else: ?>
                            <?php foreach ($recent_images as $recent): 
                                $recent_url = getImageUrl($recent['image_path']);
                            ?>
                                <li class="recent-item" onclick="openImageModal(<?php echo $recent['id']; ?>)">
                                    <div class="recent-thumb">
                                        <img src="<?php echo htmlspecialchars($recent_url); ?>" 
                                             alt="<?php echo htmlspecialchars($recent['title']); ?>"
                                             loading="lazy"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="image-placeholder" style="display: none;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    </div>
                                    <div class="recent-content">
                                        <div class="recent-title"><?php echo htmlspecialchars($recent['title']); ?></div>
                                        <div class="recent-meta">
                                            <?php echo htmlspecialchars($recent['category_name']); ?> • 
                                            <?php echo date('M j', strtotime($recent['uploaded_at'])); ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Gallery Statistics -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="400">
                    <h3 class="sidebar-title">
                        <i class="fas fa-chart-bar"></i>
                        Gallery Stats
                    </h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($gallery_stats['total_images']); ?></span>
                            <span class="stat-label">Total Photos</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($gallery_stats['featured_images']); ?></span>
                            <span class="stat-label">Featured</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($gallery_stats['total_categories']); ?></span>
                            <span class="stat-label">Categories</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($gallery_stats['total_views']); ?></span>
                            <span class="stat-label">Total Views</span>
                        </div>
                    </div>
                </div>

                <!-- Categories -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="500">
                    <h3 class="sidebar-title">
                        <i class="fas fa-folder"></i>
                        Categories
                    </h3>
                    <ul class="categories-list">
                        <?php foreach ($category_counts as $category): ?>
                            <li class="category-item">
                                <a href="gallery?category=<?php echo $category['id']; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>">
                                    <span>
                                        <i class="fas fa-<?php echo isset($category['icon']) ? $category['icon'] : 'images'; ?>"></i>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </span>
                                    <span class="category-count"><?php echo $category['image_count']; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- About Gallery -->
                <div class="sidebar-card" data-aos="fade-up" data-aos-delay="600">
                    <h3 class="sidebar-title">
                        <i class="fas fa-info-circle"></i>
                        About Our Gallery
                    </h3>
                    <p style="color: var(--gray-600); font-size: 0.8rem; line-height: 1.5;">
                        Our gallery showcases the vibrant life at RP Musanze College. From academic events to cultural celebrations, sports competitions to campus life - capture the moments that matter.
                    </p>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <small style="color: var(--gray-500);">
                            <i class="fas fa-camera"></i> Have photos to share? Contact the Media Committee!
                        </small>
                    </div>
                </div>
            </aside>
        </section>
    </div>

    <!-- Image Modal -->
    <div class="image-modal" id="imageModal">
        <div class="modal-content">
            <button class="modal-close" id="modalClose">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-nav">
                <button class="nav-btn" id="prevBtn">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="nav-btn" id="nextBtn">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <img class="modal-image" id="modalImage" src="" alt="">
            <div class="modal-info">
                <h3 class="modal-title" id="modalTitle"></h3>
                <p class="modal-description" id="modalDescription"></p>
                <div class="modal-meta">
                    <span id="modalCategory"></span>
                    <span id="modalDate"></span>
                </div>
            </div>
        </div>
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
                    <li><a href="/assets/rp_handbook.pdf"><i class="fas fa-chevron-right"></i> Student Handbook</a></li>
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
        
        // Sort control change
        const sortControl = document.getElementById('sortControl');
        if (sortControl) {
            sortControl.addEventListener('change', function() {
                window.location.href = 'gallery?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=' + this.value;
            });
        }
        
        // View change function
        function changeView(view) {
            window.location.href = 'gallery?category=<?php echo $current_category; ?>&view=' + view + '&sort=<?php echo $sort_by; ?>';
        }
        
        // Image modal functionality
        let currentImageIndex = 0;
        let allImages = <?php echo json_encode($images); ?>;
        
        function openImageModal(id) {
            const index = allImages.findIndex(img => img.id == id);
            if (index !== -1) {
                currentImageIndex = index;
                updateModalContent();
                document.getElementById('imageModal').classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
        
        function updateModalContent() {
            const image = allImages[currentImageIndex];
            if (!image) return;
            
            const imageUrl = '<?php echo addslashes(getImageUrl("")) ?>' + image.image_path;
            document.getElementById('modalImage').src = imageUrl;
            document.getElementById('modalTitle').textContent = image.title;
            document.getElementById('modalDescription').textContent = image.description || 'No description available';
            document.getElementById('modalCategory').innerHTML = '<i class="fas fa-folder"></i> ' + image.category_name;
            document.getElementById('modalDate').innerHTML = '<i class="fas fa-calendar"></i> ' + new Date(image.uploaded_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        }
        
        function nextImage() {
            if (currentImageIndex < allImages.length - 1) {
                currentImageIndex++;
                updateModalContent();
            }
        }
        
        function prevImage() {
            if (currentImageIndex > 0) {
                currentImageIndex--;
                updateModalContent();
            }
        }
        
        function closeModal() {
            document.getElementById('imageModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Modal event listeners
        const modal = document.getElementById('imageModal');
        const closeBtn = document.getElementById('modalClose');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (prevBtn) prevBtn.addEventListener('click', prevImage);
        if (nextBtn) nextBtn.addEventListener('click', nextImage);
        
        // Close modal on outside click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (modal.classList.contains('active')) {
                if (e.key === 'Escape') closeModal();
                if (e.key === 'ArrowLeft') prevImage();
                if (e.key === 'ArrowRight') nextImage();
            }
        });
        
        // Image error handling
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.style.display = 'none';
                const placeholder = this.nextElementSibling;
                if (placeholder && placeholder.classList.contains('image-placeholder')) {
                    placeholder.style.display = 'flex';
                }
            });
        });
    </script>
</body>
</html>