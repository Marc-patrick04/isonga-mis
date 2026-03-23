<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Redirect class representatives to their dedicated dashboard
if ($_SESSION['is_class_rep'] ?? 0) {
    header('Location: class_rep_dashboard.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: announcements.php');
    exit();
}

// Handle filters
$category = $_GET['category'] ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query for announcements
$query = "
    SELECT a.*, u.full_name as author_name, u.role as author_role
    FROM announcements a
    LEFT JOIN users u ON a.author_id = u.id
    WHERE a.status = 'published'
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
";

$params = [];
$types = ''; // For binding types

// Apply category filter
if ($category !== 'all') {
    $query .= " AND a.category = ?";
    $params[] = $category;
    $types .= 's'; // string type
}

// Apply priority filter
if ($priority !== 'all') {
    $query .= " AND a.priority = ?";
    $params[] = $priority;
    $types .= 's'; // string type
}

// Apply search filter
if (!empty($search)) {
    $query .= " AND (a.title LIKE ? OR a.content LIKE ? OR a.excerpt LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss'; // three strings
}

// Order by pinned first, then priority, then date
$query .= " ORDER BY a.is_pinned DESC, 
    CASE 
        WHEN a.priority = 'urgent' THEN 1
        WHEN a.priority = 'high' THEN 2
        WHEN a.priority = 'normal' THEN 3
        WHEN a.priority = 'low' THEN 4
        ELSE 5
    END,
    a.created_at DESC";

// Get total count for pagination (create a count query)
$count_query = "SELECT COUNT(*) as total FROM announcements a WHERE a.status = 'published' 
                AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())";
$count_params = [];
$count_types = '';

// Apply the same filters to count query
if ($category !== 'all') {
    $count_query .= " AND a.category = ?";
    $count_params[] = $category;
    $count_types .= 's';
}

if ($priority !== 'all') {
    $count_query .= " AND a.priority = ?";
    $count_params[] = $priority;
    $count_types .= 's';
}

if (!empty($search)) {
    $count_query .= " AND (a.title LIKE ? OR a.content LIKE ? OR a.excerpt LIKE ?)";
    $search_term = "%$search%";
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_types .= 'sss';
}

$count_stmt = $pdo->prepare($count_query);
if (!empty($count_params)) {
    for ($i = 0; $i < count($count_params); $i++) {
        $type = $count_types[$i] ?? 's';
        $count_stmt->bindValue($i + 1, $count_params[$i], $type === 'i' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
$count_stmt->execute();
$total_announcements = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Pagination
$per_page = 10;
$total_pages = $total_announcements > 0 ? ceil($total_announcements / $per_page) : 1;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $per_page;

// Add pagination to query (without quotes for LIMIT and OFFSET values)
$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii'; // integers for LIMIT and OFFSET

// Execute query
$stmt = $pdo->prepare($query);

// Bind parameters with proper types
if (!empty($params)) {
    for ($i = 0; $i < count($params); $i++) {
        $type = $types[$i] ?? 's';
        if ($type === 'i') {
            $stmt->bindValue($i + 1, $params[$i], PDO::PARAM_INT);
        } else {
            $stmt->bindValue($i + 1, $params[$i], PDO::PARAM_STR);
        }
    }
}

$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct categories for filter
$categories_stmt = $pdo->query("SELECT DISTINCT category FROM announcements WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get pinned announcements
$pinned_stmt = $pdo->query("
    SELECT a.*, u.full_name as author_name, u.role as author_role
    FROM announcements a
    LEFT JOIN users u ON a.author_id = u.id
    WHERE a.status = 'published' 
    AND a.is_pinned = '1'
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
    ORDER BY a.created_at DESC
    LIMIT 3
");
$pinned_announcements = $pinned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

function getPriorityBadge($priority) {
    $badges = [
        'urgent' => 'status-error',
        'high' => 'status-progress',
        'normal' => 'status-open',
        'low' => 'status-resolved'
    ];
    return $badges[$priority] ?? 'status-open';
}

function getPriorityIcon($priority) {
    $icons = [
        'urgent' => 'fa-exclamation-circle',
        'high' => 'fa-exclamation-triangle',
        'normal' => 'fa-info-circle',
        'low' => 'fa-info'
    ];
    return $icons[$priority] ?? 'fa-info-circle';
}

function getCategoryIcon($category) {
    $icons = [
        'academic' => 'fa-graduation-cap',
        'financial' => 'fa-money-bill-wave',
        'event' => 'fa-calendar-alt',
        'deadline' => 'fa-clock',
        'important' => 'fa-bullhorn',
        'general' => 'fa-newspaper',
        'exam' => 'fa-file-alt',
        'holiday' => 'fa-umbrella-beach',
        'meeting' => 'fa-users',
        'scholarship' => 'fa-award'
    ];
    return $icons[$category] ?? 'fa-newspaper';
}

function formatExcerpt($content, $length = 150) {
    $content = strip_tags($content);
    if (strlen($content) > $length) {
        $content = substr($content, 0, $length) . '...';
    }
    return $content;
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --booking-blue: #003b95;
            --booking-blue-light: #006ce4;
            --booking-green: #00a699;
            --booking-orange: #ff5a5f;
            --booking-yellow: #ffb400;
            --booking-gray-50: #f7f7f7;
            --booking-gray-100: #ebebeb;
            --booking-gray-200: #d8d8d8;
            --booking-gray-300: #b0b0b0;
            --booking-gray-400: #717171;
            --booking-gray-500: #2d2d2d;
            --booking-white: #ffffff;
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.16);
            --transition: all 0.2s ease;
        }

        [data-theme="dark"] {
            --booking-gray-50: #1a1a1a;
            --booking-gray-100: #2d2d2d;
            --booking-gray-200: #404040;
            --booking-gray-300: #666666;
            --booking-gray-400: #999999;
            --booking-gray-500: #ffffff;
            --booking-white: #2d2d2d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
            padding: 0 2rem;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

    /* Logo Styles */
.logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
}

.logo-image {
    height: 36px; /* Adjust based on your logo's aspect ratio */
    width: auto;
    object-fit: contain;
}

.logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-blue);
    letter-spacing: -0.5px;
}

/* Optional: Different logo for dark theme */
[data-theme="dark"] .logo-text {
    color: white; /* Or keep it blue for consistency */
}

[data-theme="dark"] .logo-image {
    filter: brightness(1.1); /* Slightly brighten logo for dark theme */
}
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--booking-gray-50);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .theme-toggle-btn {
            background: none;
            border: 1px solid var(--booking-gray-200);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .theme-toggle-btn:hover {
            border-color: var(--booking-blue);
            color: var(--booking-blue);
        }

        /* Navigation */
        .nav-container {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .main-nav {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .nav-links {
            display: flex;
            gap: 0;
            list-style: none;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--booking-gray-500);
            font-weight: 500;
            font-size: 0.9rem;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .nav-link:hover {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue-light);
        }

        .nav-link.active {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue);
            font-weight: 600;
        }

        .nav-link i {
            font-size: 0.85rem;
            width: 18px;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Page Header */
        .page-header {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--booking-blue);
        }

        .page-description {
            color: var(--booking-gray-400);
            margin-bottom: 1.5rem;
        }

        .header-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--booking-gray-50);
            color: var(--booking-blue);
        }

        .stat-content h4 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
        }

        /* Pinned Announcements */
        .pinned-section {
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--booking-gray-500);
        }

        .section-icon {
            color: var(--booking-yellow);
        }

        .pinned-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        /* Announcement Cards */
        .announcement-card {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--booking-gray-100);
            transition: var(--transition);
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--booking-gray-200);
        }

        .announcement-card.pinned {
            border: 2px solid var(--booking-yellow);
        }

        .announcement-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
            position: relative;
        }

        .announcement-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            background: var(--booking-gray-50);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--booking-gray-500);
            margin-bottom: 0.75rem;
        }

        .announcement-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .announcement-title a {
            color: var(--booking-gray-500);
            text-decoration: none;
            transition: var(--transition);
        }

        .announcement-title a:hover {
            color: var(--booking-blue);
        }

        .announcement-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--booking-gray-400);
            margin-top: 0.75rem;
        }

        .announcement-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .announcement-body {
            padding: 1.5rem;
        }

        .announcement-excerpt {
            color: var(--booking-gray-500);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .announcement-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .announcement-priority {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-urgent { background: #ffe6e6; color: var(--booking-orange); }
        .status-high { background: #fff8e6; color: var(--booking-orange); }
        .status-normal { background: #e6f2ff; color: var(--booking-blue); }
        .status-low { background: #f0f0f0; color: var(--booking-gray-400); }

        .announcement-actions {
            display: flex;
            gap: 0.5rem;
        }

        .pin-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--booking-yellow);
            color: var(--booking-gray-500);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        /* Filter Section */
        .filter-section {
            background: var(--booking-white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--booking-gray-100);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--booking-gray-500);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--booking-blue);
            color: white;
            border: 1px solid var(--booking-blue);
        }

        .btn-primary:hover {
            background: var(--booking-blue-light);
            border-color: var(--booking-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 107, 228, 0.2);
        }

        .btn-secondary {
            background: var(--booking-white);
            color: var(--booking-gray-500);
            border: 1px solid var(--booking-gray-200);
        }

        .btn-secondary:hover {
            background: var(--booking-gray-50);
            border-color: var(--booking-gray-300);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--booking-gray-100);
        }

        .pagination-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-200);
            color: var(--booking-gray-500);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover {
            background: var(--booking-gray-50);
            border-color: var(--booking-gray-300);
        }

        .pagination-link.active {
            background: var(--booking-blue);
            color: white;
            border-color: var(--booking-blue);
        }

        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Search Box */
        .search-box {
            position: relative;
        }

        .search-box .form-control {
            padding-left: 3rem;
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--booking-gray-300);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--booking-gray-400);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-400);
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        /* View Count */
        .view-count {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0 1rem;
            }
            
            .main-nav {
                padding: 0 1rem;
            }
            
            .nav-links {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 0.5rem;
            }
            
            .nav-link {
                padding: 1rem;
                font-size: 0.85rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                padding: 1.5rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .header-stats {
                flex-direction: column;
                gap: 1rem;
            }
            
            .pinned-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .announcement-meta {
                flex-wrap: wrap;
            }
            
            .announcement-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .user-name, .user-role {
                display: none;
            }
            
            .pagination {
                flex-wrap: wrap;
            }
            
            .announcement-header {
                padding: 1rem;
            }
            
            .announcement-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <!-- Header -->
    <header class="header">
<a href="dashboard.php" class="logo">
    <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo-image">
    <div class="logo-text">Isonga</div>
</a>
        
<!-- Add this to the header-actions div in dashboard.php -->
<div class="header-actions">
    <form method="POST" style="margin: 0;">
        <button type="submit" name="toggle_theme" class="theme-toggle-btn" title="Toggle Theme">
            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
        </button>
    </form>
    
    <!-- Logout Button - Add this -->
    <a href="../auth/logout.php" class="logout-btn" title="Logout">
        <i class="fas fa-sign-out-alt"></i>
    </a>
    
    <div class="user-menu">
        <div class="user-avatar">
            <?php echo strtoupper(substr($student_name, 0, 1)); ?>
        </div>
        <div class="user-info">
            <span class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></span>
            <span class="user-role">Student</span>
        </div>
    </div>
</div>
    </header>

    <!-- Navigation -->
    <nav class="nav-container">
        <div class="main-nav">
            <ul class="nav-links">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tickets.php" class="nav-link">
                        <i class="fas fa-ticket-alt"></i>
                        My Tickets
                    </a>
                </li>
                <li class="nav-item">
                    <a href="financial_aid.php" class="nav-link">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements.php" class="nav-link active">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </a>
                </li>
                <?php if ($is_class_rep): ?>
                <li class="nav-item">
                    <a href="class_rep_dashboard.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        Class Rep
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-bullhorn"></i>
                Campus Announcements
            </h1>
            <p class="page-description">Stay updated with the latest news, events, and important information</p>
            
            <div class="header-stats">
                <div class="stat-item">
                    <div class="stat-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-content">
                        <h4><?php echo $total_announcements; ?></h4>
                        <p>Total Announcements</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon">
                        <i class="fas fa-thumbtack"></i>
                    </div>
                    <div class="stat-content">
                        <h4><?php echo count($pinned_announcements); ?></h4>
                        <p>Pinned Announcements</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="stat-content">
                        <h4><?php 
                            $urgent_count = 0;
                            foreach ($announcements as $announcement) {
                                if ($announcement['priority'] === 'urgent') {
                                    $urgent_count++;
                                }
                            }
                            echo $urgent_count;
                        ?></h4>
                        <p>Urgent Announcements</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pinned Announcements -->
        <?php if (!empty($pinned_announcements)): ?>
        <div class="pinned-section">
            <div class="section-header">
                <i class="fas fa-thumbtack section-icon"></i>
                <h3 class="section-title">Pinned Announcements</h3>
            </div>
            
            <div class="pinned-grid">
                <?php foreach ($pinned_announcements as $announcement): ?>
                    <div class="announcement-card pinned">
                        <div class="pin-badge" title="Pinned Announcement">
                            <i class="fas fa-thumbtack"></i>
                        </div>
                        
                        <div class="announcement-header">
                            <div class="announcement-category">
                                <i class="fas <?php echo getCategoryIcon($announcement['category']); ?>"></i>
                                <?php echo safe_display(ucfirst($announcement['category'])); ?>
                            </div>
                            
                            <h3 class="announcement-title">
                                <a href="view_announcement.php?id=<?php echo $announcement['id']; ?>">
                                    <?php echo safe_display($announcement['title']); ?>
                                </a>
                            </h3>
                            
                            <div class="announcement-meta">
                                <div class="announcement-meta-item">
                                    <i class="far fa-user"></i>
                                    <?php echo safe_display($announcement['author_name'] ?? 'Administrator'); ?>
                                </div>
                                <div class="announcement-meta-item">
                                    <i class="far fa-clock"></i>
                                    <?php echo timeAgo($announcement['created_at']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="announcement-body">
                            <p class="announcement-excerpt">
                                <?php echo formatExcerpt($announcement['excerpt'] ?: $announcement['content']); ?>
                            </p>
                        </div>
                        
                        <div class="announcement-footer">
                            <span class="announcement-priority status-<?php echo $announcement['priority']; ?>">
                                <i class="fas <?php echo getPriorityIcon($announcement['priority']); ?>"></i>
                                <?php echo ucfirst($announcement['priority']); ?>
                            </span>
                            
                            <div class="announcement-actions">
                                <a href="view_announcement.php?id=<?php echo $announcement['id']; ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i>
                                    Read More
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label class="form-label">Search Announcements</label>
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Search by title or content..."
                               value="<?php echo safe_display($search); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <option value="all" <?php echo $category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo safe_display($cat); ?>" 
                                <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo safe_display(ucfirst($cat)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-control">
                        <option value="all" <?php echo $priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="normal" <?php echo $priority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i>
                        Apply Filters
                    </button>
                    <a href="announcements.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i>
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Announcements List -->
        <div>
            <div class="section-header">
                <i class="fas fa-list section-icon"></i>
                <h3 class="section-title">All Announcements</h3>
            </div>
            
            <?php if (empty($announcements)): ?>
                <div class="empty-state">
                    <i class="fas fa-newspaper"></i>
                    <h3>No announcements found</h3>
                    <p><?php echo !empty($search) || $category !== 'all' || $priority !== 'all' 
                        ? 'Try adjusting your filters or search terms.' 
                        : 'Check back later for new announcements.'; ?></p>
                    <?php if (!empty($search) || $category !== 'all' || $priority !== 'all'): ?>
                        <a href="announcements.php" class="btn btn-primary">
                            <i class="fas fa-redo"></i>
                            Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-card">
                            <div class="announcement-header">
                                <?php if ($announcement['is_pinned']): ?>
                                    <div class="pin-badge" title="Pinned Announcement">
                                        <i class="fas fa-thumbtack"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="announcement-category">
                                    <i class="fas <?php echo getCategoryIcon($announcement['category']); ?>"></i>
                                    <?php echo safe_display(ucfirst($announcement['category'])); ?>
                                </div>
                                
                                <h3 class="announcement-title">
                                    <a href="view_announcement.php?id=<?php echo $announcement['id']; ?>">
                                        <?php echo safe_display($announcement['title']); ?>
                                    </a>
                                </h3>
                                
                                <div class="announcement-meta">
                                    <div class="announcement-meta-item">
                                        <i class="far fa-user"></i>
                                        <?php echo safe_display($announcement['author_name'] ?? 'Administrator'); ?>
                                    </div>
                                    <div class="announcement-meta-item">
                                        <i class="far fa-clock"></i>
                                        <?php echo timeAgo($announcement['created_at']); ?>
                                    </div>
                                    <?php if ($announcement['expiry_date']): ?>
                                        <div class="announcement-meta-item">
                                            <i class="far fa-calendar-times"></i>
                                            Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="announcement-meta-item view-count">
                                        <i class="far fa-eye"></i>
                                        <?php echo $announcement['view_count']; ?> views
                                    </div>
                                </div>
                            </div>
                            
                            <div class="announcement-body">
                                <p class="announcement-excerpt">
                                    <?php echo formatExcerpt($announcement['excerpt'] ?: $announcement['content']); ?>
                                </p>
                            </div>
                            
                            <div class="announcement-footer">
                                <span class="announcement-priority status-<?php echo $announcement['priority']; ?>">
                                    <i class="fas <?php echo getPriorityIcon($announcement['priority']); ?>"></i>
                                    <?php echo ucfirst($announcement['priority']); ?>
                                </span>
                                
                                <div class="announcement-actions">
                                    <a href="view_announcement.php?id=<?php echo $announcement['id']; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye"></i>
                                        Read More
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" 
                               class="pagination-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-link disabled">
                                <i class="fas fa-chevron-left"></i>
                            </span>
                        <?php endif; ?>

                        <!-- Page Numbers -->
                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="?page=1&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" 
                               class="pagination-link">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="pagination-link disabled">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" 
                               class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="pagination-link disabled">...</span>
                            <?php endif; ?>
                            <a href="?page=<?php echo $total_pages; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" 
                               class="pagination-link">
                                <?php echo $total_pages; ?>
                            </a>
                        <?php endif; ?>

                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" 
                               class="pagination-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-link disabled">
                                <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Add smooth scroll animation
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add loading animation to cards on scroll
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

        // Observe cards
        document.querySelectorAll('.announcement-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(card);
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Auto-focus search input if there's a search term
        <?php if (!empty($search)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        });
        <?php endif; ?>

        // Add confirmation for filter reset
        document.querySelector('a[href="announcements.php"]').addEventListener('click', function(e) {
            if (this.textContent.includes('Reset')) {
                if (!confirm('Are you sure you want to reset all filters?')) {
                    e.preventDefault();
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="search"]');
                if (document.activeElement === searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.focus();
                }
            }
        });
    </script>
</body>
</html>