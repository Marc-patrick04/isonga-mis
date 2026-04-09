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

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$student_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
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

// Apply category filter
if ($category !== 'all') {
    $query .= " AND a.category = ?";
    $params[] = $category;
}

// Apply priority filter
if ($priority !== 'all') {
    $query .= " AND a.priority = ?";
    $params[] = $priority;
}

// Apply search filter
if (!empty($search)) {
    $query .= " AND (a.title ILIKE ? OR a.content ILIKE ? OR a.excerpt ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
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

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM announcements a WHERE a.status = 'published' 
                AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())";
$count_params = [];

if ($category !== 'all') {
    $count_query .= " AND a.category = ?";
    $count_params[] = $category;
}

if ($priority !== 'all') {
    $count_query .= " AND a.priority = ?";
    $count_params[] = $priority;
}

if (!empty($search)) {
    $count_query .= " AND (a.title ILIKE ? OR a.content ILIKE ? OR a.excerpt ILIKE ?)";
    $search_term = "%$search%";
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
}

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_announcements = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Pagination
$per_page = 10;
$total_pages = $total_announcements > 0 ? ceil($total_announcements / $per_page) : 1;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $per_page;

// Add pagination to query
$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct categories for filter
$categories_stmt = $pdo->query("SELECT DISTINCT category FROM announcements WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get pinned announcements
$pinned_stmt = $pdo->prepare("
    SELECT a.*, u.full_name as author_name, u.role as author_role
    FROM announcements a
    LEFT JOIN users u ON a.author_id = u.id
    WHERE a.status = 'published' 
    AND a.is_pinned = true
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
    ORDER BY a.created_at DESC
    LIMIT 3
");
$pinned_stmt->execute();
$pinned_announcements = $pinned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

function getPriorityBadge($priority) {
    $badges = [
        'urgent' => 'status-urgent',
        'high' => 'status-high',
        'normal' => 'status-normal',
        'low' => 'status-low'
    ];
    return $badges[$priority] ?? 'status-normal';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Announcements - Isonga RPSU</title>
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

        [data-theme="dark"] {
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

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
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
        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--primary-blue);
        }

        .page-description {
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .header-stats {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .stat-content h4 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Pinned Section */
        .pinned-section {
            margin-bottom: 1.5rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .section-icon {
            color: var(--warning);
        }

        .pinned-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        /* Announcement Cards */
        .announcement-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .announcement-card.pinned {
            border: 2px solid var(--warning);
        }

        .announcement-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            position: relative;
        }

        .announcement-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.6rem;
            background: var(--light-gray);
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }

        .announcement-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .announcement-title a {
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .announcement-title a:hover {
            color: var(--primary-blue);
        }

        .announcement-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--dark-gray);
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }

        .announcement-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .announcement-body {
            padding: 1rem;
        }

        .announcement-excerpt {
            color: var(--text-dark);
            line-height: 1.5;
            font-size: 0.85rem;
        }

        .announcement-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .announcement-priority {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-urgent { background: #f8d7da; color: #721c24; }
        .status-high { background: #fff3cd; color: #856404; }
        .status-normal { background: #d1ecf1; color: #0c5460; }
        .status-low { background: #e2e3e5; color: #383d41; }

        .pin-badge {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            background: var(--warning);
            color: var(--text-dark);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .view-count {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--medium-gray);
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

        .form-control {
            padding: 0.6rem 0.75rem;
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

        .search-box {
            position: relative;
        }

        .search-box .form-control {
            padding-left: 2.5rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--medium-gray);
            flex-wrap: wrap;
        }

        .pagination-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 0.75rem;
            border-radius: var(--border-radius);
            background: var(--white);
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover {
            background: var(--light-blue);
            border-color: var(--primary-blue);
        }

        .pagination-link.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .empty-state p {
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        /* Overlay */
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

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
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

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .pinned-grid {
                grid-template-columns: 1fr;
            }

            .announcement-meta {
                flex-direction: column;
                gap: 0.25rem;
            }

            .announcement-footer {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-stats {
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .pagination {
                gap: 0.25rem;
            }

            .pagination-link {
                min-width: 32px;
                height: 32px;
                font-size: 0.75rem;
            }

            .stat-item {
                width: calc(50% - 0.5rem);
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
                <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo">
                <div class="brand-text">
                    <h1>Isonga RPSU</h1>
                </div>
            </div>
            <div class="user-menu">
                <form method="POST" style="margin: 0;">
                    <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                        <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                    </button>
                </form>
                <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                    <i class="fas fa-envelope"></i>
                    <?php if ($unread_messages > 0): ?>
                        <span class="notification-badge"><?php echo $unread_messages; ?></span>
                    <?php endif; ?>
                </a>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></div>
                        <div class="user-role">Student</div>
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
                        <span>My Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_aid.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php" class="active">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                        <?php if ($total_announcements > 0): ?>
                            <span class="menu-badge"><?php echo $total_announcements; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
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
                <?php if ($is_class_rep): ?>
                <li class="menu-item">
                    <a href="class_rep_dashboard.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Dashboard</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
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
                            <p>Pinned</p>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h4><?php 
                                $urgent_count = 0;
                                foreach ($announcements as $announcement) {
                                    if ($announcement['priority'] === 'urgent') $urgent_count++;
                                }
                                echo $urgent_count;
                            ?></h4>
                            <p>Urgent</p>
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
                                <span class="announcement-priority <?php echo getPriorityBadge($announcement['priority']); ?>">
                                    <i class="fas <?php echo getPriorityIcon($announcement['priority']); ?>"></i>
                                    <?php echo ucfirst($announcement['priority']); ?>
                                </span>
                                
                                <a href="view_announcement.php?id=<?php echo $announcement['id']; ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> Read More
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="form-group search-box">
                        <label class="form-label">Search</label>
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="form-control" placeholder="Search announcements..." value="<?php echo safe_display($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control">
                            <option value="all" <?php echo $category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo safe_display($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
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
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="announcements.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
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
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-card">
                                <div class="announcement-header">
                                    <?php if ($announcement['is_pinned']): ?>
                                        <div class="pin-badge" title="Pinned">
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
                                    <span class="announcement-priority <?php echo getPriorityBadge($announcement['priority']); ?>">
                                        <i class="fas <?php echo getPriorityIcon($announcement['priority']); ?>"></i>
                                        <?php echo ucfirst($announcement['priority']); ?>
                                    </span>
                                    
                                    <a href="view_announcement.php?id=<?php echo $announcement['id']; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye"></i> Read More
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" class="pagination-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled"><i class="fas fa-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1): ?>
                                <a href="?page=1&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" class="pagination-link">1</a>
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
                                <a href="?page=<?php echo $total_pages; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" class="pagination-link">
                                    <?php echo $total_pages; ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&category=<?php echo $category; ?>&priority=<?php echo $priority; ?>&search=<?php echo urlencode($search); ?>" class="pagination-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
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

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
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

        // Add loading animation to cards
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
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>