<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'view';
$category_id = $_GET['category_id'] ?? null;
$image_id = $_GET['image_id'] ?? null;
$message = '';
$error = '';

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Initialize variables
$images = [];
$all_categories = [];
$categories = [];
$current_category = null;
$current_image = null;
$stats = [
    'total_images' => 0, 
    'total_categories' => 0, 
    'total_views' => 0, 
    'total_downloads' => 0, 
    'featured_images' => 0
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_category') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        
        if (!empty($name)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO gallery_categories (name, description, created_by, status) 
                    VALUES (?, ?, ?, 'active')
                ");
                $stmt->execute([$name, $description, $user_id]);
                $message = "Category '$name' added successfully!";
                $action = 'categories';
            } catch (PDOException $e) {
                $error = "Error adding category: " . $e->getMessage();
            }
        } else {
            $error = "Category name is required.";
        }
    }
    elseif ($action === 'edit_category' && $category_id) {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $status = $_POST['status'] ?? 'active';
        
        if (!empty($name)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE gallery_categories 
                    SET name = ?, description = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description, $status, $category_id]);
                $message = "Category updated successfully!";
                $action = 'categories';
            } catch (PDOException $e) {
                $error = "Error updating category: " . $e->getMessage();
            }
        } else {
            $error = "Category name is required.";
        }
    }
    elseif ($action === 'upload_image') {
        $category_id = $_POST['category_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $tags_input = $_POST['tags'] ?? '';
        $featured = isset($_POST['featured']) ? 1 : 0;
        
        if (!empty($title) && $category_id && isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $upload_dir = '../assets/uploads/gallery/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['image']['name']);
            $file_path = $upload_dir . $file_name;
            $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_ext, $allowed_types)) {
                if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
                    $dimensions = '';
                    $img_size = @getimagesize($file_path);
                    if ($img_size) {
                        $dimensions = $img_size[0] . 'x' . $img_size[1];
                    }
                    
                    $tags_json = '[]';
                    if (!empty($tags_input)) {
                        $tags_array = array_map('trim', explode(',', $tags_input));
                        $tags_json = json_encode($tags_array);
                    }
                    
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO gallery_images 
                            (category_id, title, description, image_path, image_name, file_size, file_type, dimensions, uploaded_by, featured, tags) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
                        ");
                        $stmt->execute([
                            $category_id, 
                            $title, 
                            $description,
                            'assets/uploads/gallery/' . $file_name,
                            $file_name,
                            $_FILES['image']['size'],
                            $file_ext,
                            $dimensions,
                            $user_id,
                            $featured,
                            $tags_json
                        ]);
                        
                        $stmt = $pdo->prepare("
                            UPDATE gallery_categories 
                            SET image_count = image_count + 1, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $stmt->execute([$category_id]);
                        
                        $message = "Image uploaded successfully!";
                        $action = 'view';
                    } catch (PDOException $e) {
                        $error = "Error uploading image: " . $e->getMessage();
                        unlink($file_path);
                    }
                } else {
                    $error = "Error moving uploaded file.";
                }
            } else {
                $error = "Invalid file type. Only JPG, JPEG, PNG, GIF, and WEBP are allowed.";
            }
        } else {
            $error = "Please fill all required fields and select an image.";
        }
    }
    elseif ($action === 'edit_image' && $image_id) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $category_id = $_POST['category_id'];
        $tags_input = $_POST['tags'] ?? '';
        $featured = isset($_POST['featured']) ? 1 : 0;
        
        if (!empty($title) && $category_id) {
            $tags_json = '[]';
            if (!empty($tags_input)) {
                $tags_array = array_map('trim', explode(',', $tags_input));
                $tags_json = json_encode($tags_array);
            }
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE gallery_images 
                    SET title = ?, description = ?, category_id = ?, featured = ?, tags = ?::jsonb 
                    WHERE id = ?
                ");
                $stmt->execute([$title, $description, $category_id, $featured, $tags_json, $image_id]);
                $message = "Image updated successfully!";
                $action = 'view';
            } catch (PDOException $e) {
                $error = "Error updating image: " . $e->getMessage();
            }
        } else {
            $error = "Please fill all required fields.";
        }
    }
}

// Handle delete actions
if (isset($_GET['delete'])) {
    if ($_GET['delete'] === 'category' && $category_id) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM gallery_images WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $image_count = $stmt->fetchColumn();
            
            if ($image_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM gallery_categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $message = "Category deleted successfully!";
            } else {
                $error = "Cannot delete category with images. Please delete or move images first.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting category: " . $e->getMessage();
        }
        $action = 'categories';
    }
    elseif ($_GET['delete'] === 'image' && $image_id) {
        try {
            $stmt = $pdo->prepare("SELECT category_id, image_path FROM gallery_images WHERE id = ?");
            $stmt->execute([$image_id]);
            $image = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($image) {
                $stmt = $pdo->prepare("DELETE FROM gallery_images WHERE id = ?");
                $stmt->execute([$image_id]);
                
                $stmt = $pdo->prepare("
                    UPDATE gallery_categories 
                    SET image_count = GREATEST(0, image_count - 1), updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$image['category_id']]);
                
                if (file_exists('../' . $image['image_path'])) {
                    unlink('../' . $image['image_path']);
                }
                
                $message = "Image deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting image: " . $e->getMessage();
        }
        $action = 'view';
    }
}

// Get data based on current action
try {
    // Get all categories for dropdowns
    $categories_stmt = $pdo->query("
        SELECT * FROM gallery_categories 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($action === 'view' || $action === 'categories') {
        $categories_stmt = $pdo->query("
            SELECT gc.*, COUNT(gi.id) as actual_image_count
            FROM gallery_categories gc
            LEFT JOIN gallery_images gi ON gc.id = gi.category_id AND gi.status = 'active'
            GROUP BY gc.id
            ORDER BY gc.name
        ");
        $all_categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($action === 'view' || $action === 'images') {
        $images_sql = "
            SELECT gi.*, gc.name as category_name 
            FROM gallery_images gi
            LEFT JOIN gallery_categories gc ON gi.category_id = gc.id
            WHERE gi.status = 'active'
        ";
        
        $images_params = [];
        if ($category_id) {
            $images_sql .= " AND gi.category_id = ?";
            $images_params[] = $category_id;
        }
        
        $images_sql .= " ORDER BY gi.uploaded_at DESC";
        
        $images_stmt = $pdo->prepare($images_sql);
        $images_stmt->execute($images_params);
        $images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($action === 'edit_category' && $category_id) {
        $stmt = $pdo->prepare("SELECT * FROM gallery_categories WHERE id = ?");
        $stmt->execute([$category_id]);
        $current_category = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($action === 'edit_image' && $image_id) {
        $stmt = $pdo->prepare("SELECT * FROM gallery_images WHERE id = ?");
        $stmt->execute([$image_id]);
        $current_image = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get statistics
    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_images,
            COUNT(DISTINCT category_id) as total_categories,
            COALESCE(SUM(views_count), 0) as total_views,
            COALESCE(SUM(downloads_count), 0) as total_downloads,
            COUNT(CASE WHEN featured = 1 THEN 1 END) as featured_images
        FROM gallery_images 
        WHERE status = 'active'
    ");
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats_result) {
        $stats = $stats_result;
    }
    
    // Get unread messages count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
    // Get pending tickets count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE assigned_to = ? AND status IN ('open', 'in_progress')
    ");
    $stmt->execute([$user_id]);
    $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Gallery data error: " . $e->getMessage());
}
// Initialize variables to prevent undefined errors
$unread_messages = 0;
$pending_tickets = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Gallery Management - Isonga RPSU</title>
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
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
            width: 20px;
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
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Page Header */
        .dashboard-header {
            margin-bottom: 1.5rem;
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

        /* Buttons */
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
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
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
            border-left: 4px solid var(--primary-blue);
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
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
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--light-blue);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            resize: vertical;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        /* Tabs */
        .tabs {
            display: flex;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            gap: 0.25rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        /* Gallery Grid */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .gallery-item {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .gallery-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .gallery-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
        }

        .gallery-content {
            padding: 1rem;
        }

        .gallery-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .gallery-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .gallery-tags {
            margin-bottom: 0.5rem;
        }

        .tag {
            display: inline-block;
            background: var(--light-blue);
            color: var(--primary-blue);
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }

        .gallery-description {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .gallery-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--medium-gray);
        }

        .action-link {
            color: var(--primary-blue);
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .action-link:hover {
            text-decoration: underline;
        }

        .action-link.danger {
            color: var(--danger);
        }

        /* Categories Grid */
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .category-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .category-header {
            padding: 1rem;
            background: var(--light-blue);
            border-bottom: 1px solid var(--medium-gray);
        }

        .category-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .category-description {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .category-body {
            padding: 1rem;
        }

        .category-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .category-stat {
            text-align: center;
        }

        .stat-value {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-blue);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger);
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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            text-align: center;
            cursor: pointer;
        }

        .action-btn:hover {
            border-color: var(--primary-blue);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.75rem;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 4rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(2px);
                z-index: 999;
            }

            .overlay.active {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }

            .categories-grid {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .gallery-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>
    
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Gallery Management</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
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
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php" class="active">
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
                    <a href="committee_budget_requests.php">
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
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Gallery Management</h1>
                    <p>Manage gallery categories and images for RPSU</p>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-images"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_images']); ?></div>
                        <div class="stat-label">Total Images</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_categories']); ?></div>
                        <div class="stat-label">Categories</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_views']); ?></div>
                        <div class="stat-label">Total Views</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['featured_images']); ?></div>
                        <div class="stat-label">Featured Images</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="?action=add_category" class="action-btn">
                    <i class="fas fa-folder-plus"></i>
                    <span class="action-label">Add Category</span>
                </a>
                <a href="?action=upload_image" class="action-btn">
                    <i class="fas fa-upload"></i>
                    <span class="action-label">Upload Image</span>
                </a>
                <a href="?action=categories" class="action-btn">
                    <i class="fas fa-folder"></i>
                    <span class="action-label">Manage Categories</span>
                </a>
                <a href="?action=view" class="action-btn">
                    <i class="fas fa-images"></i>
                    <span class="action-label">View All Images</span>
                </a>
            </div>

            <!-- Tabs Navigation -->
            <?php if ($action === 'view'): ?>
                <div class="tabs">
                    <button class="tab <?php echo !$category_id ? 'active' : ''; ?>" onclick="window.location.href='?action=view'">
                        <i class="fas fa-images"></i> All Images
                    </button>
                    <button class="tab <?php echo $action === 'categories' ? 'active' : ''; ?>" onclick="window.location.href='?action=categories'">
                        <i class="fas fa-folder"></i> Categories
                    </button>
                    <?php foreach ($categories as $cat): ?>
                        <button class="tab <?php echo $category_id == $cat['id'] ? 'active' : ''; ?>" 
                                onclick="window.location.href='?action=view&category_id=<?php echo $cat['id']; ?>'">
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($action === 'view'): ?>
                <!-- Images View -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-images"></i>
                            <?php 
                            if ($category_id) {
                                $category_name = '';
                                foreach ($categories as $cat) {
                                    if ($cat['id'] == $category_id) {
                                        $category_name = $cat['name'];
                                        break;
                                    }
                                }
                                echo "Images in " . htmlspecialchars($category_name);
                            } else {
                                echo "All Gallery Images";
                            }
                            ?>
                        </h3>
                        <div class="card-header-actions">
                            <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                <?php echo count($images); ?> images
                            </span>
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($images)): ?>
                            <div class="empty-state">
                                <i class="fas fa-images"></i>
                                <p>No images found in this category.</p>
                                <a href="?action=upload_image" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-upload"></i> Upload First Image
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="gallery-grid">
                                <?php foreach ($images as $image): ?>
                                    <div class="gallery-item">
                                        <img src="../<?php echo htmlspecialchars($image['image_path']); ?>" 
                                             alt="<?php echo htmlspecialchars($image['title']); ?>" 
                                             class="gallery-image"
                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZThlOGU4Ii8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkltYWdlIE5vdCBGb3VuZDwvdGV4dD48L3N2Zz4='">
                                        <div class="gallery-content">
                                            <div class="gallery-title"><?php echo htmlspecialchars($image['title']); ?></div>
                                            <div class="gallery-meta">
                                                <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($image['category_name']); ?></span>
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($image['uploaded_at'])); ?></span>
                                            </div>
                                            <?php 
                                            if (!empty($image['tags'])) {
                                                $tags_array = json_decode($image['tags'], true);
                                                if (is_array($tags_array) && !empty($tags_array)) {
                                                    echo '<div class="gallery-tags">';
                                                    foreach ($tags_array as $tag) {
                                                        echo '<span class="tag">' . htmlspecialchars($tag) . '</span>';
                                                    }
                                                    echo '</div>';
                                                }
                                            }
                                            ?>
                                            <?php if (!empty($image['description'])): ?>
                                                <div class="gallery-description">
                                                    <?php echo htmlspecialchars(substr($image['description'], 0, 100)); ?>
                                                    <?php if (strlen($image['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="gallery-actions">
                                                <a href="?action=edit_image&image_id=<?php echo $image['id']; ?>" class="action-link">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="?action=view&delete=image&image_id=<?php echo $image['id']; ?>" 
                                                   class="action-link danger" 
                                                   onclick="return confirm('Are you sure you want to delete this image?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'categories'): ?>
                <!-- Categories View -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-folder"></i> Gallery Categories</h3>
                        <div class="card-header-actions">
                            <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                <?php echo count($all_categories); ?> categories
                            </span>
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <a href="?action=add_category" class="card-header-btn" title="Add Category">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($all_categories)): ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <p>No categories found.</p>
                                <a href="?action=add_category" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-folder-plus"></i> Create First Category
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="categories-grid">
                                <?php foreach ($all_categories as $category): ?>
                                    <div class="category-card">
                                        <div class="category-header">
                                            <div class="category-name"><?php echo htmlspecialchars($category['name']); ?></div>
                                            <?php if (!empty($category['description'])): ?>
                                                <div class="category-description"><?php echo htmlspecialchars($category['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="category-body">
                                            <div class="category-stats">
                                                <div class="category-stat">
                                                    <div class="stat-value"><?php echo $category['actual_image_count']; ?></div>
                                                    <div class="stat-label">Images</div>
                                                </div>
                                                <div class="category-stat">
                                                    <div class="stat-value">
                                                        <span class="status-badge status-<?php echo $category['status']; ?>">
                                                            <?php echo ucfirst($category['status']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="stat-label">Status</div>
                                                </div>
                                            </div>
                                            <div class="gallery-actions">
                                                <a href="?action=view&category_id=<?php echo $category['id']; ?>" class="action-link">
                                                    <i class="fas fa-eye"></i> View Images
                                                </a>
                                                <a href="?action=edit_category&category_id=<?php echo $category['id']; ?>" class="action-link">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="?action=categories&delete=category&category_id=<?php echo $category['id']; ?>" 
                                                   class="action-link danger" 
                                                   onclick="return confirm('Are you sure you want to delete this category?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'add_category' || $action === 'edit_category'): ?>
                <!-- Add/Edit Category Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas <?php echo $action === 'add_category' ? 'fa-folder-plus' : 'fa-edit'; ?>"></i> 
                            <?php echo $action === 'add_category' ? 'Add New Category' : 'Edit Category'; ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?action=<?php echo $action; ?><?php echo $category_id ? '&category_id=' . $category_id : ''; ?>">
                            <div class="form-group">
                                <label class="form-label" for="name">Category Name <span style="color: var(--danger);">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo isset($current_category) ? htmlspecialchars($current_category['name']) : ''; ?>" 
                                       required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($current_category) ? htmlspecialchars($current_category['description']) : ''; ?></textarea>
                                <div class="form-text">Optional description for the category</div>
                            </div>
                            <?php if ($action === 'edit_category'): ?>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="active" <?php echo (isset($current_category) && $current_category['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($current_category) && $current_category['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'add_category' ? 'Create Category' : 'Update Category'; ?>
                                </button>
                                <a href="?action=<?php echo $action === 'add_category' ? 'categories' : 'view'; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'upload_image' || $action === 'edit_image'): ?>
                <!-- Upload/Edit Image Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas <?php echo $action === 'upload_image' ? 'fa-upload' : 'fa-edit'; ?>"></i> 
                            <?php echo $action === 'upload_image' ? 'Upload New Image' : 'Edit Image'; ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" 
                              action="?action=<?php echo $action; ?><?php echo $image_id ? '&image_id=' . $image_id : ''; ?>" 
                              enctype="multipart/form-data">
                            <div class="form-group">
                                <label class="form-label" for="category_id">Category <span style="color: var(--danger);">*</span></label>
                                <select class="form-control" id="category_id" name="category_id" required>
                                    <option value="">Select a category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                <?php echo (isset($current_image) && $current_image['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="title">Image Title <span style="color: var(--danger);">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo isset($current_image) ? htmlspecialchars($current_image['title']) : ''; ?>" 
                                       required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($current_image) ? htmlspecialchars($current_image['description']) : ''; ?></textarea>
                                <div class="form-text">Optional description for the image</div>
                            </div>
                            <?php if ($action === 'upload_image'): ?>
                                <div class="form-group">
                                    <label class="form-label" for="image">Image File <span style="color: var(--danger);">*</span></label>
                                    <input type="file" class="form-control" id="image" name="image" accept="image/*" required>
                                    <div class="form-text">Supported formats: JPG, JPEG, PNG, GIF, WEBP. Max file size: 5MB</div>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <label class="form-label">Current Image</label>
                                    <div>
                                        <img src="../<?php echo htmlspecialchars($current_image['image_path']); ?>" 
                                             alt="<?php echo htmlspecialchars($current_image['title']); ?>" 
                                             style="max-width: 200px; max-height: 150px; border-radius: var(--border-radius);">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label class="form-label" for="tags">Tags</label>
                                <input type="text" class="form-control" id="tags" name="tags" 
                                       value="<?php 
                                       if (isset($current_image) && !empty($current_image['tags'])) {
                                           $tags_array = json_decode($current_image['tags'], true);
                                           if (is_array($tags_array) && !empty($tags_array)) {
                                               echo htmlspecialchars(implode(', ', $tags_array));
                                           }
                                       }
                                       ?>">
                                <div class="form-text">Comma-separated tags for better searchability (e.g., graduation, event, sports)</div>
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="featured" name="featured" value="1" 
                                           <?php echo (isset($current_image) && $current_image['featured'] == 1) ? 'checked' : ''; ?>>
                                    <label class="form-label" for="featured" style="margin-bottom: 0;">Featured Image</label>
                                </div>
                                <div class="form-text">Featured images will be highlighted in the gallery</div>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'upload_image' ? 'Upload Image' : 'Update Image'; ?>
                                </button>
                                <a href="?action=view" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

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

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // File input validation
        const fileInput = document.getElementById('image');
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const fileSize = file.size / 1024 / 1024;
                    if (fileSize > 5) {
                        alert('File size must be less than 5MB');
                        e.target.value = '';
                    }
                }
            });
        }

        // Auto-hide success messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });
    </script>
</body>
</html>