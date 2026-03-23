<?php
session_start();
require_once 'config/database.php';

// Get all gallery categories - Use 'active' string for status
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
$where_conditions = ["g.status = 'active'"];  // status is string

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
    default: // newest
        $sort_order = "g.uploaded_at DESC";
        break;
}

// Get featured images - featured is boolean (true/false)
try {
    $featured_sql = "
        SELECT g.*, gc.name as category_name
        FROM gallery_images g 
        LEFT JOIN gallery_categories gc ON g.category_id = gc.id 
        WHERE g.status = 'active' AND g.featured = true
    ";
    
    // Add category filter if needed
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

// Get gallery statistics
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

$page_title = "Photo Gallery - RPSU Musanze College";

// Function to get correct image URL
function getImageUrl($imagePath) {
    // If the path already starts with assets/, use it as is
    if (strpos($imagePath, 'assets/') === 0) {
        return $imagePath;
    }
    // Otherwise, prepend the assets path
    return 'assets/uploads/gallery/' . $imagePath;
}

// Debug info (remove after testing)
error_log("Total images found: " . count($images));
error_log("Total featured images: " . count($featured_images));
if (!empty($images)) {
    error_log("First image: " . $images[0]['title'] . " - Path: " . $images[0]['image_path']);
}
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

        /* Header & Navigation - Same as events.php */
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

        /* Gallery Controls */
        .gallery-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .category-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
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

        .view-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .view-btn {
            padding: 0.5rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--gray-600);
            cursor: pointer;
            transition: var(--transition);
        }

        .view-btn:hover, .view-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .sort-control {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--gray-700);
            font-size: 0.875rem;
            cursor: pointer;
        }

        /* Featured Images */
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
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
            z-index: 2;
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--white);
            color: var(--gray-800);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
        }

        .overlay-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: scale(1.1);
        }

        .featured-content {
            padding: 1.25rem;
        }

        .image-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .image-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .image-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .image-views, .image-date {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Gallery Grid View */
        .gallery-section {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
            align-items: start;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
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

        .gallery-grid .image-card {
            height: 100%;
        }

        .gallery-list .image-card {
            display: flex;
            height: 120px;
        }

        .gallery-list .image-card .image-preview {
            width: 160px;
            height: 100%;
            flex-shrink: 0;
        }

        .gallery-list .image-card .image-content {
            flex: 1;
            padding: 1rem;
        }

        .image-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .image-preview {
            height: 180px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
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
            padding: 1.25rem;
        }

        .image-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .image-description {
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .image-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--gray-600);
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

        .recent-list {
            list-style: none;
        }

        .recent-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .recent-item:last-child {
            border-bottom: none;
        }

        .recent-thumb {
            width: 60px;
            height: 60px;
            border-radius: var(--border-radius);
            overflow: hidden;
            flex-shrink: 0;
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
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .recent-meta {
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

        /* Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            padding: 2rem;
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
            max-height: 80vh;
            object-fit: contain;
            border-radius: var(--border-radius);
        }

        .modal-info {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            margin-top: -5px;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }

        .modal-description {
            color: var(--gray-600);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .modal-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .modal-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: none;
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
        }

        .modal-nav {
            position: absolute;
            top: 50%;
            width: 100%;
            display: flex;
            justify-content: space-between;
            transform: translateY(-50%);
            padding: 0 1rem;
        }

        .nav-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.3);
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
            .gallery-section {
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

            .featured-grid, .gallery-grid {
                grid-template-columns: 1fr;
            }

            .gallery-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .category-filter {
                justify-content: center;
            }

            .view-controls {
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

            .gallery-grid {
                gap: 1.25rem;
            }

            .image-content {
                padding: 1rem;
            }

            .image-title {
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

            .gallery-list .image-card {
                flex-direction: column;
                height: auto;
            }

            .gallery-list .image-card .image-preview {
                width: 100%;
                height: 160px;
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

            .category-filter {
                gap: 0.5rem;
            }

            .category-btn {
                padding: 0.6rem 1.25rem;
                font-size: 0.8rem;
            }

            .featured-image, .image-preview {
                height: 160px;
            }

            .featured-content, .image-content {
                padding: 1rem;
            }

            .featured-title, .image-title {
                font-size: 1rem;
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

        .featured-card, .image-card {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Add this to handle broken images better */
        .gallery-image-fallback {
            width: 100%;
            height: 100%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            font-size: 1.5rem;
        }
        
        .image-placeholder {
            width: 100%;
            height: 100%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
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
                <a href="events.php">Events</a>
                <a href="committee.php">Committee</a>
                <a href="gallery.php" class="active">Gallery</a>
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
                <a href="events.php">Events</a>
                <a href="committee.php">Committee</a>
                <a href="gallery.php" class="active">Gallery</a>
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
            <h1 class="page-title">Photo Gallery</h1>
            <p class="page-subtitle">Explore our collection of campus moments, events, and activities captured through the lens.</p>
        </div>

        <!-- Gallery Controls -->
        <div class="gallery-controls">
            <div class="category-filter">
                <a href="gallery.php?category=all&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>" 
                   class="category-btn <?php echo $current_category === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> All Photos
                </a>
                <?php foreach ($categories as $category): ?>
                    <a href="gallery.php?category=<?php echo $category['id']; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>" 
                       class="category-btn <?php echo $current_category == $category['id'] ? 'active' : ''; ?>">
                        <i class="fas fa-<?php echo isset($category['icon']) ? $category['icon'] : 'images'; ?>"></i>
                        <?php echo htmlspecialchars($category['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <div class="view-controls">
                <select class="sort-control" onchange="window.location.href='gallery.php?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort='+this.value">
                    <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="popular" <?php echo $sort_by === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                    <option value="featured" <?php echo $sort_by === 'featured' ? 'selected' : ''; ?>>Featured</option>
                    <option value="title" <?php echo $sort_by === 'title' ? 'selected' : ''; ?>>Title A-Z</option>
                </select>
                
                <button class="view-btn <?php echo $view_type === 'grid' ? 'active' : ''; ?>" 
                        onclick="changeView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button class="view-btn <?php echo $view_type === 'list' ? 'active' : ''; ?>" 
                        onclick="changeView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Featured Images -->
        <?php if (!empty($featured_images)): ?>
        <section class="featured-section">
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
                                 class="gallery-img"
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
                                       class="overlay-btn" download title="Download">
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
                                    <?php echo $image['views_count']; ?> views
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
                                foreach ($categories as $cat) {
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
                                         class="gallery-img"
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
                                        <p class="image-description"><?php echo htmlspecialchars($image['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="image-meta">
                                        <span class="image-views">
                                            <i class="fas fa-eye"></i>
                                            <?php echo $image['views_count']; ?> views
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
                                <a href="gallery.php?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page - 1; ?>">
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
                                <a href="gallery.php?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $i; ?>" 
                                   class="<?php echo $i == $page ? 'current' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="gallery.php?category=<?php echo $current_category; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page + 1; ?>">
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
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-clock"></i>
                        Recent Uploads
                    </h3>
                    <ul class="recent-list">
                        <?php if (empty($recent_images)): ?>
                            <li class="recent-item">
                                <span style="color: var(--gray-600); font-size: 0.875rem;">No recent uploads</span>
                            </li>
                        <?php else: ?>
                            <?php foreach ($recent_images as $recent): 
                                $recent_url = getImageUrl($recent['image_path']);
                            ?>
                                <li class="recent-item" onclick="openImageModal(<?php echo $recent['id']; ?>)">
                                    <div class="recent-thumb">
                                        <img src="<?php echo htmlspecialchars($recent_url); ?>" 
                                             alt="<?php echo htmlspecialchars($recent['title']); ?>"
                                             class="gallery-img"
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
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-chart-bar"></i>
                        Gallery Stats
                    </h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $gallery_stats['total_images']; ?></span>
                            <span class="stat-label">Total Photos</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $gallery_stats['featured_images']; ?></span>
                            <span class="stat-label">Featured</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $gallery_stats['total_categories']; ?></span>
                            <span class="stat-label">Categories</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $gallery_stats['total_views']; ?></span>
                            <span class="stat-label">Total Views</span>
                        </div>
                    </div>
                </div>

                <!-- Categories -->
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-folder"></i>
                        Categories
                    </h3>
                    <ul class="categories-list">
                        <?php foreach ($categories as $category): ?>
                            <li class="category-item">
                                <a href="gallery.php?category=<?php echo $category['id']; ?>&view=<?php echo $view_type; ?>&sort=<?php echo $sort_by; ?>">
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
                <div class="sidebar-card">
                    <h3 class="sidebar-title">
                        <i class="fas fa-info-circle"></i>
                        About Our Gallery
                    </h3>
                    <p style="color: var(--gray-600); font-size: 0.875rem; line-height: 1.5;">
                        Our gallery showcases the vibrant life at RP Musanze College. From academic events to cultural celebrations, sports competitions to campus life - capture the moments that matter.
                    </p>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                        <small style="color: var(--gray-500); font-size: 0.75rem;">
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
        // ... (keep all your existing JavaScript) ...

        // Enhanced image loading with better error handling
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('.gallery-img');
            
            images.forEach(img => {
                // Check if image loads successfully
                img.onload = function() {
                    console.log('Image loaded:', img.src);
                };
                
                img.onerror = function() {
                    console.error('Failed to load image:', img.src);
                    // Hide the broken image and show placeholder
                    img.style.display = 'none';
                    const placeholder = img.nextElementSibling;
                    if (placeholder && placeholder.classList.contains('image-placeholder')) {
                        placeholder.style.display = 'flex';
                    }
                };
            });
        });

        // Debug function to check image URLs
        function debugImages() {
            const images = document.querySelectorAll('.gallery-img');
            console.log('Total images found:', images.length);
            images.forEach((img, index) => {
                console.log(`Image ${index + 1}:`, img.src);
            });
        }

        // Call debug on page load
        setTimeout(debugImages, 1000);
    </script>
</body>
</html>