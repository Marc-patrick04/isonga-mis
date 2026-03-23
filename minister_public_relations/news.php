<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Handle news actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_news':
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                $category_id = $_POST['category_id'];
                $status = $_POST['status'];
                $is_featured = isset($_POST['is_featured']) ? 1 : 0;
                
                // Handle image upload
                $image_url = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/news/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $file_name = 'news_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    // Validate image type
                    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array(strtolower($file_extension), $allowed_types)) {
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
                            $image_url = 'assets/uploads/news/' . $file_name;
                        }
                    }
                }
                
                // Validate required fields
                if (empty($title) || empty($content) || empty($category_id)) {
                    $_SESSION['error_message'] = "Title, content, and category are required fields.";
                    break;
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO news (title, content, excerpt, image_url, category_id, author_id, views_count, is_featured, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $title, 
                        $content, 
                        $excerpt, 
                        $image_url, 
                        $category_id, 
                        $user_id, 
                        $is_featured, 
                        $status
                    ]);
                    
                    $news_id = $pdo->lastInsertId();
                    
                    $_SESSION['success_message'] = "News article created successfully!";
                    
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating news article: " . $e->getMessage();
                }
                break;
                
            case 'update_news':
                $news_id = $_POST['news_id'];
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                $category_id = $_POST['category_id'];
                $status = $_POST['status'];
                $is_featured = isset($_POST['is_featured']) ? 1 : 0;
                $remove_image = isset($_POST['remove_image']) ? 1 : 0;
                
                // Get current news data
                try {
                    $stmt = $pdo->prepare("SELECT image_url FROM news WHERE id = ? AND author_id = ?");
                    $stmt->execute([$news_id, $user_id]);
                    $current_news = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$current_news) {
                        $_SESSION['error_message'] = "News article not found or you don't have permission to edit it.";
                        break;
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error loading news article: " . $e->getMessage();
                    break;
                }
                
                $image_url = $current_news['image_url'];
                
                // Handle image upload
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/news/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $file_name = 'news_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    // Validate image type
                    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array(strtolower($file_extension), $allowed_types)) {
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
                            // Delete old image if exists
                            if ($image_url && file_exists('../' . $image_url)) {
                                unlink('../' . $image_url);
                            }
                            $image_url = 'assets/uploads/news/' . $file_name;
                        }
                    }
                }
                
                // Handle image removal
                if ($remove_image && $image_url) {
                    if (file_exists('../' . $image_url)) {
                        unlink('../' . $image_url);
                    }
                    $image_url = null;
                }
                
                // Validate required fields
                if (empty($title) || empty($content) || empty($category_id)) {
                    $_SESSION['error_message'] = "Title, content, and category are required fields.";
                    break;
                }
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE news 
                        SET title = ?, content = ?, excerpt = ?, image_url = ?, category_id = ?, 
                            is_featured = ?, status = ?, updated_at = NOW()
                        WHERE id = ? AND author_id = ?
                    ");
                    $stmt->execute([
                        $title, 
                        $content, 
                        $excerpt, 
                        $image_url, 
                        $category_id, 
                        $is_featured, 
                        $status,
                        $news_id, 
                        $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "News article updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating news article: " . $e->getMessage();
                }
                break;
                
            case 'delete_news':
                $news_id = $_POST['news_id'];
                
                try {
                    // Get image URL before deletion
                    $stmt = $pdo->prepare("SELECT image_url FROM news WHERE id = ? AND author_id = ?");
                    $stmt->execute([$news_id, $user_id]);
                    $news = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($news) {
                        // Delete associated image file
                        if ($news['image_url'] && file_exists('../' . $news['image_url'])) {
                            unlink('../' . $news['image_url']);
                        }
                        
                        // Delete news article
                        $stmt = $pdo->prepare("DELETE FROM news WHERE id = ? AND author_id = ?");
                        $stmt->execute([$news_id, $user_id]);
                        
                        $_SESSION['success_message'] = "News article deleted successfully!";
                    } else {
                        $_SESSION['error_message'] = "News article not found or you don't have permission to delete it.";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting news article: " . $e->getMessage();
                }
                break;
                
            case 'update_news_status':
                $news_id = $_POST['news_id'];
                $status = $_POST['status'];
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE news 
                        SET status = ?, updated_at = NOW()
                        WHERE id = ? AND author_id = ?
                    ");
                    $stmt->execute([$status, $news_id, $user_id]);
                    
                    $_SESSION['success_message'] = "News status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating news status: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: news.php");
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$featured_filter = $_GET['featured'] ?? 'all';
$author_filter = $_GET['author'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query for news
$query = "
    SELECT n.*, 
           nc.name as category_name,
           nc.color as category_color,
           nc.icon as category_icon,
           nc.slug as category_slug,
           u.full_name as author_name,
           u.role as author_role
    FROM news n
    LEFT JOIN news_categories nc ON n.category_id = nc.id
    LEFT JOIN users u ON n.author_id = u.id
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($search)) {
    $query .= " AND (n.title LIKE ? OR n.content LIKE ? OR n.excerpt LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($category_filter !== 'all') {
    $query .= " AND n.category_id = ?";
    $params[] = $category_filter;
}

if ($status_filter !== 'all') {
    $query .= " AND n.status = ?";
    $params[] = $status_filter;
}

if ($featured_filter !== 'all') {
    $query .= " AND n.is_featured = ?";
    $params[] = ($featured_filter === 'featured') ? 1 : 0;
}

if ($author_filter !== 'all') {
    $query .= " AND n.author_id = ?";
    $params[] = $author_filter;
}

if (!empty($date_from)) {
    $query .= " AND DATE(n.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(n.created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY n.created_at DESC";

// Get news articles
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $news_articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("News query error: " . $e->getMessage());
    $news_articles = [];
}

// Get news categories for filter and form
try {
    $stmt = $pdo->query("SELECT * FROM news_categories WHERE is_active = 1 ORDER BY name");
    $news_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $news_categories = [];
}

// Get authors for filter
try {
    $stmt = $pdo->query("
        SELECT DISTINCT u.id, u.full_name, u.role 
        FROM news n
        JOIN users u ON n.author_id = u.id
        ORDER BY u.full_name
    ");
    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $authors = [];
}

// Get statistics for dashboard
try {
    // Total news articles
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM news");
    $total_news = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // My news articles
    $stmt = $pdo->prepare("SELECT COUNT(*) as my_news FROM news WHERE author_id = ?");
    $stmt->execute([$user_id]);
    $my_news = $stmt->fetch(PDO::FETCH_ASSOC)['my_news'] ?? 0;
    
    // Published articles
    $stmt = $pdo->prepare("SELECT COUNT(*) as published FROM news WHERE author_id = ? AND status = 'published'");
    $stmt->execute([$user_id]);
    $published_news = $stmt->fetch(PDO::FETCH_ASSOC)['published'] ?? 0;
    
    // Featured articles
    $stmt = $pdo->prepare("SELECT COUNT(*) as featured FROM news WHERE author_id = ? AND is_featured = 1");
    $stmt->execute([$user_id]);
    $featured_news = $stmt->fetch(PDO::FETCH_ASSOC)['featured'] ?? 0;
    
    // Total views
    $stmt = $pdo->prepare("SELECT SUM(views_count) as total_views FROM news WHERE author_id = ?");
    $stmt->execute([$user_id]);
    $total_views = $stmt->fetch(PDO::FETCH_ASSOC)['total_views'] ?? 0;
    
    // Draft articles
    $stmt = $pdo->prepare("SELECT COUNT(*) as drafts FROM news WHERE author_id = ? AND status = 'draft'");
    $stmt->execute([$user_id]);
    $draft_news = $stmt->fetch(PDO::FETCH_ASSOC)['drafts'] ?? 0;
    
} catch (PDOException $e) {
    $total_news = $my_news = $published_news = $featured_news = $total_views = $draft_news = 0;
}

// Check if we're viewing/editing a specific news article
$edit_news = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT n.*, 
                   nc.name as category_name,
                   nc.color as category_color,
                   nc.icon as category_icon,
                   u.full_name as author_name,
                   u.role as author_role
            FROM news n
            LEFT JOIN news_categories nc ON n.category_id = nc.id
            LEFT JOIN users u ON n.author_id = u.id
            WHERE n.id = ? AND n.author_id = ?
        ");
        $stmt->execute([$_GET['edit'], $user_id]);
        $edit_news = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_news) {
            $_SESSION['error_message'] = "News article not found or you don't have permission to edit it.";
            header("Location: news.php");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error loading news article: " . $e->getMessage();
        header("Location: news.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Management - Minister of Public Relations</title>
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
        }

        .dark-mode {
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
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
            transition: var(--transition);
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
            height: 80px;
            display: flex;
            align-items: center;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--primary-blue);
            transform: scale(1.05);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1.1rem;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar */
        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 80px;
            height: calc(100vh - 80px);
            overflow-y: auto;
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
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
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
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .dashboard-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--primary-blue);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card .stat-icon {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filter-form {
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

        .form-select, .form-input {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* News Form */
        .news-form {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }

        .form-grid {
            display: grid;
            gap: 1rem;
        }

        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .form-checkbox {
            margin-right: 0.5rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .image-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
        }

        .image-upload:hover {
            border-color: var(--primary-blue);
        }

        .image-upload input {
            display: none;
        }

        .image-preview {
            max-width: 300px;
            margin: 1rem auto;
            border-radius: var(--border-radius);
            overflow: hidden;
            display: none;
        }

        .image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }

        .current-image {
            margin: 1rem 0;
            text-align: center;
        }

        .current-image img {
            max-width: 300px;
            border-radius: var(--border-radius);
        }

        /* News Grid */
        .news-grid {
            display: grid;
            gap: 1.5rem;
        }

        .news-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .news-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .news-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .news-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .news-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .news-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .category-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-published {
            background: #d4edda;
            color: var(--success);
        }

        .status-draft {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-archived {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .featured-badge {
            background: var(--warning);
            color: var(--text-dark);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .news-body {
            padding: 1.25rem;
        }

        .news-excerpt {
            color: var(--text-dark);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .news-content {
            color: var(--text-dark);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .news-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-top: 1rem;
        }

        .news-actions {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            gap: 0.75rem;
            justify-content: space-between;
            align-items: center;
        }

        .action-group {
            display: flex;
            gap: 0.75rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .news-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .action-group {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .news-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Public Relations & Associations</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Public Relations</div>
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
        <nav class="sidebar">
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
                        <span>Student Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php" class="active">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                        <span class="member-count"><?php echo $total_news; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                    </a>
                </li>
                                <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>News Management</h1>
                    <p>Create and publish news articles for the student community</p>
                </div>
                <div class="header-actions">
                    <?php if ($edit_news): ?>
                        <a href="news.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to News
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" onclick="toggleNewsForm()">
                            <i class="fas fa-plus"></i> New News Article
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_news; ?></div>
                        <div class="stat-label">Total News Articles</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $my_news; ?></div>
                        <div class="stat-label">My Articles</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_views; ?></div>
                        <div class="stat-label">Total Views</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $featured_news; ?></div>
                        <div class="stat-label">Featured Articles</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $published_news; ?></div>
                        <div class="stat-label">Published</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $draft_news; ?></div>
                        <div class="stat-label">Drafts</div>
                    </div>
                </div>
            </div>

            <?php if (!$edit_news): ?>
                <!-- News Form (Initially Hidden) -->
                <div class="news-form" id="newsForm" style="display: none;">
                    <h2 class="form-title">Create New News Article</h2>
                    <form method="POST" id="createNewsForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create_news">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">News Title *</label>
                                <input type="text" name="title" class="form-input" placeholder="Enter news title" required maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($news_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Featured Image</label>
                                <div class="image-upload" onclick="document.getElementById('imageInput').click()">
                                    <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; margin-bottom: 1rem; color: var(--dark-gray);"></i>
                                    <p>Click to upload featured image</p>
                                    <p style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.5rem;">
                                        JPG, PNG, GIF, WEBP (Max 5MB)
                                    </p>
                                    <input type="file" id="imageInput" name="image" accept="image/*" onchange="previewImage(this)">
                                </div>
                                <div class="image-preview" id="imagePreview">
                                    <img src="" alt="Preview">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Excerpt (Optional)</label>
                                <textarea name="excerpt" class="form-textarea" placeholder="Brief summary of the news article (will be auto-generated if empty)" maxlength="500"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">News Content *</label>
                                <textarea name="content" class="form-textarea" placeholder="Enter the full news content" required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Settings</label>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="is_featured" id="is_featured" class="form-checkbox">
                                    <label for="is_featured">Feature this article</label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label for="status">Status:</label>
                                    <select name="status" class="form-select">
                                        <option value="draft">Draft</option>
                                        <option value="published">Published</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="toggleNewsForm()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Publish News
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-input" placeholder="Search news articles..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($news_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Featured</label>
                            <select name="featured" class="form-select">
                                <option value="all" <?php echo $featured_filter === 'all' ? 'selected' : ''; ?>>All Articles</option>
                                <option value="featured" <?php echo $featured_filter === 'featured' ? 'selected' : ''; ?>>Featured Only</option>
                                <option value="regular" <?php echo $featured_filter === 'regular' ? 'selected' : ''; ?>>Regular Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Author</label>
                            <select name="author" class="form-select">
                                <option value="all" <?php echo $author_filter === 'all' ? 'selected' : ''; ?>>All Authors</option>
                                <?php foreach ($authors as $author): ?>
                                    <option value="<?php echo $author['id']; ?>" <?php echo $author_filter == $author['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($author['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-input" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-input" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="news.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- News List -->
                <div class="news-grid">
                    <?php if (empty($news_articles)): ?>
                        <div class="empty-state">
                            <i class="fas fa-newspaper"></i>
                            <h3>No News Articles Found</h3>
                            <p>There are no news articles matching your criteria.</p>
                            <button type="button" class="btn btn-primary" onclick="toggleNewsForm()" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First News Article
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($news_articles as $article): ?>
                            <div class="news-card">
                                <?php if ($article['image_url']): ?>
                                    <img src="../<?php echo htmlspecialchars($article['image_url']); ?>" alt="<?php echo htmlspecialchars($article['title']); ?>" class="news-image">
                                <?php endif; ?>
                                
                                <div class="news-header">
                                    <h3 class="news-title"><?php echo htmlspecialchars($article['title']); ?></h3>
                                    <div class="news-meta">
                                        <span class="category-badge" style="background: <?php echo htmlspecialchars($article['category_color'] ?? '#EFF6FF'); ?>; color: <?php echo htmlspecialchars($article['category_color'] ?? '#3B82F6'); ?>;">
                                            <i class="<?php echo htmlspecialchars($article['category_icon'] ?? 'fas fa-folder'); ?>"></i>
                                            <?php echo htmlspecialchars($article['category_name']); ?>
                                        </span>
                                        <span><strong>Author:</strong> <?php echo htmlspecialchars($article['author_name']); ?></span>
                                        <span><strong>Created:</strong> <?php echo date('M j, Y', strtotime($article['created_at'])); ?></span>
                                        <span class="status-badge status-<?php echo $article['status']; ?>">
                                            <?php echo ucfirst($article['status']); ?>
                                        </span>
                                        <?php if ($article['is_featured']): ?>
                                            <span class="featured-badge">
                                                <i class="fas fa-star"></i> Featured
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="news-body">
                                    <?php if (!empty($article['excerpt'])): ?>
                                        <div class="news-excerpt">
                                            <?php echo htmlspecialchars($article['excerpt']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="news-content">
                                        <?php 
                                        // Show truncated content for better performance
                                        $content = $article['content'];
                                        if (strlen($content) > 300) {
                                            $content = substr($content, 0, 300) . '...';
                                        }
                                        echo nl2br(htmlspecialchars($content)); 
                                        ?>
                                    </div>
                                    
                                    <div class="news-stats">
                                        <span><i class="fas fa-eye"></i> <?php echo $article['views_count']; ?> views</span>
                                        <span><i class="fas fa-clock"></i> Updated: <?php echo date('M j, Y', strtotime($article['updated_at'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="news-actions">
                                    <div class="action-group">
                                        <?php if ($article['author_id'] == $user_id): ?>
                                            <a href="?edit=<?php echo $article['id']; ?>" class="btn btn-warning">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="update_news_status">
                                                <input type="hidden" name="news_id" value="<?php echo $article['id']; ?>">
                                                <select name="status" onchange="this.form.submit()" class="form-select" style="font-size: 0.8rem; padding: 0.4rem;">
                                                    <option value="draft" <?php echo $article['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                                    <option value="published" <?php echo $article['status'] === 'published' ? 'selected' : ''; ?>>Publish</option>
                                                    <option value="archived" <?php echo $article['status'] === 'archived' ? 'selected' : ''; ?>>Archive</option>
                                                </select>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                                <i class="fas fa-info-circle"></i> You can only edit your own articles
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($article['author_id'] == $user_id): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_news">
                                            <input type="hidden" name="news_id" value="<?php echo $article['id']; ?>">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this news article?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Edit News Form -->
                <div class="news-form">
                    <h2 class="form-title">Edit News Article</h2>
                    <form method="POST" id="editNewsForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_news">
                        <input type="hidden" name="news_id" value="<?php echo $edit_news['id']; ?>">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">News Title *</label>
                                <input type="text" name="title" class="form-input" value="<?php echo htmlspecialchars($edit_news['title']); ?>" required maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($news_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo $edit_news['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Featured Image</label>
                                
                                <?php if ($edit_news['image_url']): ?>
                                    <div class="current-image">
                                        <p><strong>Current Image:</strong></p>
                                        <img src="../<?php echo htmlspecialchars($edit_news['image_url']); ?>" alt="Current featured image">
                                        <div class="checkbox-group" style="margin-top: 0.5rem;">
                                            <input type="checkbox" name="remove_image" id="remove_image" class="form-checkbox">
                                            <label for="remove_image">Remove current image</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="image-upload" onclick="document.getElementById('editImageInput').click()">
                                    <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; margin-bottom: 1rem; color: var(--dark-gray);"></i>
                                    <p>Click to upload new featured image</p>
                                    <p style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.5rem;">
                                        JPG, PNG, GIF, WEBP (Max 5MB)
                                    </p>
                                    <input type="file" id="editImageInput" name="image" accept="image/*" onchange="previewEditImage(this)">
                                </div>
                                <div class="image-preview" id="editImagePreview">
                                    <img src="" alt="Preview">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Excerpt (Optional)</label>
                                <textarea name="excerpt" class="form-textarea" placeholder="Brief summary of the news article" maxlength="500"><?php echo htmlspecialchars($edit_news['excerpt'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">News Content *</label>
                                <textarea name="content" class="form-textarea" required><?php echo htmlspecialchars($edit_news['content']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Settings</label>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="is_featured" id="edit_is_featured" class="form-checkbox" <?php echo $edit_news['is_featured'] ? 'checked' : ''; ?>>
                                    <label for="edit_is_featured">Feature this article</label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label for="edit_status">Status:</label>
                                    <select name="status" class="form-select">
                                        <option value="draft" <?php echo $edit_news['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="published" <?php echo $edit_news['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                        <option value="archived" <?php echo $edit_news['status'] === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="news.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update News Article
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        // Check for saved theme preference or respect OS preference
        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // Toggle news form visibility
        function toggleNewsForm() {
            const form = document.getElementById('newsForm');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth' });
            } else {
                form.style.display = 'none';
            }
        }

        // Image preview functions
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.querySelector('img').src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function previewEditImage(input) {
            const preview = document.getElementById('editImagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.querySelector('img').src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Auto-generate excerpt if empty
        document.addEventListener('DOMContentLoaded', function() {
            const contentTextarea = document.querySelector('textarea[name="content"]');
            const excerptTextarea = document.querySelector('textarea[name="excerpt"]');
            
            if (contentTextarea && excerptTextarea) {
                contentTextarea.addEventListener('blur', function() {
                    if (!excerptTextarea.value.trim() && this.value.trim()) {
                        // Generate excerpt from first 150 characters of content
                        const content = this.value.trim();
                        const excerpt = content.length > 150 ? content.substring(0, 150) + '...' : content;
                        excerptTextarea.value = excerpt;
                    }
                });
            }
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const title = form.querySelector('input[name="title"]');
                    const content = form.querySelector('textarea[name="content"]');
                    const category = form.querySelector('select[name="category_id"]');
                    
                    if (title && content && category) {
                        if (!title.value.trim()) {
                            e.preventDefault();
                            alert('Please enter a news title.');
                            title.focus();
                            return;
                        }
                        
                        if (!content.value.trim()) {
                            e.preventDefault();
                            alert('Please enter news content.');
                            content.focus();
                            return;
                        }
                        
                        if (!category.value) {
                            e.preventDefault();
                            alert('Please select a category.');
                            category.focus();
                            return;
                        }
                    }
                });
            });
        });

        // Auto-save draft
        let autoSaveTimer;
        document.addEventListener('DOMContentLoaded', function() {
            const titleInput = document.querySelector('input[name="title"]');
            const contentTextarea = document.querySelector('textarea[name="content"]');
            const excerptTextarea = document.querySelector('textarea[name="excerpt"]');
            const categorySelect = document.querySelector('select[name="category_id"]');
            
            if (titleInput && contentTextarea) {
                const elements = [titleInput, contentTextarea, categorySelect];
                if (excerptTextarea) elements.push(excerptTextarea);
                
                elements.forEach(element => {
                    if (element) {
                        element.addEventListener('input', function() {
                            clearTimeout(autoSaveTimer);
                            autoSaveTimer = setTimeout(() => {
                                // Save to localStorage as draft
                                const draft = {
                                    title: titleInput.value,
                                    content: contentTextarea.value,
                                    excerpt: excerptTextarea ? excerptTextarea.value : '',
                                    category_id: categorySelect ? categorySelect.value : ''
                                };
                                localStorage.setItem('news_draft', JSON.stringify(draft));
                                console.log('News draft auto-saved');
                            }, 2000);
                        });
                    }
                });
                
                // Load draft on page load
                const draft = localStorage.getItem('news_draft');
                if (draft) {
                    const draftData = JSON.parse(draft);
                    titleInput.value = draftData.title || '';
                    contentTextarea.value = draftData.content || '';
                    if (excerptTextarea) {
                        excerptTextarea.value = draftData.excerpt || '';
                    }
                    if (categorySelect && draftData.category_id) {
                        categorySelect.value = draftData.category_id;
                    }
                }
            }
        });

        // Clear draft when form is submitted
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    localStorage.removeItem('news_draft');
                });
            });
        });
    </script>
</body>
</html>