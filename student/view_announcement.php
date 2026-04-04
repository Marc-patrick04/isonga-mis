<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login');
    exit();
}

// Redirect class representatives to their dedicated dashboard
if ($_SESSION['is_class_rep'] ?? 0) {
    header('Location: class_rep_dashboard');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: announcements');
    exit();
}

$announcement_id = $_GET['id'];
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
    header('Location: view_announcement?id=' . $announcement_id);
    exit();
}

// Get announcement details
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name as author_name, u.role as author_role, 
           u.email as author_email, u.phone as author_phone
    FROM announcements a
    LEFT JOIN users u ON a.author_id = u.id
    WHERE a.id = ? AND a.status = 'published'
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
");
$stmt->execute([$announcement_id]);
$announcement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$announcement) {
    header('Location: announcements');
    exit();
}

// Increment view count
$update_stmt = $pdo->prepare("UPDATE announcements SET view_count = view_count + 1 WHERE id = ?");
$update_stmt->execute([$announcement_id]);

// Get related announcements (same category)
$related_stmt = $pdo->prepare("
    SELECT a.id, a.title, a.created_at, a.priority, a.is_pinned, a.category, a.excerpt
    FROM announcements a
    WHERE a.id != ? 
    AND a.category = ?
    AND a.status = 'published'
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
    ORDER BY a.is_pinned DESC, a.created_at DESC
    LIMIT 3
");
$related_stmt->execute([$announcement_id, $announcement['category']]);
$related_announcements = $related_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get latest announcements
$latest_stmt = $pdo->prepare("
    SELECT a.id, a.title, a.created_at, a.priority, a.is_pinned, a.category
    FROM announcements a
    WHERE a.id != ? 
    AND a.status = 'published'
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
    ORDER BY a.created_at DESC
    LIMIT 5
");
$latest_stmt->execute([$announcement_id]);
$latest_announcements = $latest_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
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
        return date('F j, Y', $time);
    }
}

function formatContent($content) {
    // Convert line breaks to paragraphs
    $content = nl2br($content);
    
    // Convert URLs to clickable links
    $content = preg_replace(
        '/(https?:\/\/[^\s]+)/',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="content-link">$1</a>',
        $content
    );
    
    return $content;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safe_display($announcement['title']); ?> - Isonga RPSU</title>
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
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        /* Page Header */
        .page-header {
            grid-column: 1 / -1;
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .header-left {
            flex: 1;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--booking-gray-400);
        }

        .breadcrumb a {
            color: var(--booking-gray-400);
            text-decoration: none;
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            color: var(--booking-blue);
        }

        .breadcrumb i {
            font-size: 0.8rem;
        }

        .announcement-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1.3;
            color: var(--booking-gray-500);
        }

        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--booking-gray-400);
        }

        .meta-item i {
            width: 16px;
            text-align: center;
        }

        .header-right {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .announcement-status {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-urgent { background: #ffe6e6; color: var(--booking-orange); }
        .status-high { background: #fff8e6; color: var(--booking-orange); }
        .status-normal { background: #e6f2ff; color: var(--booking-blue); }
        .status-low { background: #f0f0f0; color: var(--booking-gray-400); }
        .status-pinned { background: #fff8e6; color: var(--booking-yellow); }

        /* Main Content Area */
        .content-card {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--booking-gray-100);
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .card-title i {
            color: var(--booking-blue);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Announcement Content */
        .announcement-content {
            line-height: 1.7;
            color: var(--booking-gray-500);
        }

        .announcement-content h2,
        .announcement-content h3,
        .announcement-content h4 {
            margin: 1.5rem 0 1rem;
            color: var(--booking-gray-500);
            font-weight: 600;
        }

        .announcement-content h2 { font-size: 1.5rem; }
        .announcement-content h3 { font-size: 1.25rem; }
        .announcement-content h4 { font-size: 1.1rem; }

        .announcement-content p {
            margin-bottom: 1rem;
        }

        .announcement-content ul,
        .announcement-content ol {
            margin: 1rem 0 1rem 2rem;
        }

        .announcement-content li {
            margin-bottom: 0.5rem;
        }

        .announcement-content blockquote {
            border-left: 4px solid var(--booking-blue);
            padding-left: 1rem;
            margin: 1.5rem 0;
            font-style: italic;
            color: var(--booking-gray-400);
        }

        .announcement-content img {
            max-width: 100%;
            height: auto;
            border-radius: var(--border-radius);
            margin: 1rem 0;
        }

        .announcement-content .content-link {
            color: var(--booking-blue);
            text-decoration: none;
            border-bottom: 1px dotted var(--booking-blue);
            transition: var(--transition);
        }

        .announcement-content .content-link:hover {
            color: var(--booking-blue-light);
            border-bottom-style: solid;
        }

        /* Announcement Image */
        .announcement-image {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-card {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--booking-gray-100);
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--booking-gray-100);
            background: var(--booking-gray-50);
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--booking-gray-500);
        }

        .sidebar-title i {
            color: var(--booking-blue);
        }

        .sidebar-body {
            padding: 1.25rem;
        }

        /* Related Announcements */
        .related-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .related-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--booking-gray-100);
            transition: var(--transition);
            text-decoration: none;
            color: var(--booking-gray-500);
        }

        .related-item:hover {
            background: var(--booking-gray-50);
            border-color: var(--booking-blue-light);
            transform: translateX(4px);
        }

        .related-item.pinned {
            border-left: 4px solid var(--booking-yellow);
        }

        .related-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--booking-gray-50);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--booking-blue);
        }

        .related-content {
            flex: 1;
        }

        .related-title {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .related-meta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .related-priority {
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Author Info */
        .author-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
        }

        .author-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .author-details {
            flex: 1;
        }

        .author-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .author-role {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
            margin-bottom: 0.5rem;
        }

        .author-contact {
            display: flex;
            gap: 0.75rem;
            font-size: 0.85rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--booking-gray-400);
            text-decoration: none;
            transition: var(--transition);
        }

        .contact-item:hover {
            color: var(--booking-blue);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--booking-gray-100);
        }

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

        .btn-success {
            background: var(--booking-green);
            color: white;
            border: 1px solid var(--booking-green);
        }

        .btn-success:hover {
            background: #00b894;
            border-color: #00b894;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Print Styles */
        @media print {
            .header, .nav-container, .sidebar, .action-buttons {
                display: none;
            }
            
            .main-content {
                grid-template-columns: 1fr;
                padding: 0;
            }
            
            .content-card {
                box-shadow: none;
                border: none;
            }
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .main-content {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

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
            
            .header-top {
                flex-direction: column;
                gap: 1.5rem;
            }
            
            .announcement-title {
                font-size: 1.5rem;
            }
            
            .announcement-meta {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .announcement-status {
                justify-content: flex-start;
            }
            
            .card-header, .card-body {
                padding: 1.25rem;
            }
        }

        @media (max-width: 480px) {
            .user-name, .user-role {
                display: none;
            }
            
            .announcement-title {
                font-size: 1.25rem;
            }
            
            .status-badge {
                padding: 0.4rem 0.75rem;
                font-size: 0.8rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .author-info {
                flex-direction: column;
                text-align: center;
            }
            
            .author-contact {
                justify-content: center;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
<a href="dashboard" class="logo">
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
    <a href="../auth/logout" class="logout-btn" title="Logout">
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
                    <a href="dashboard" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tickets" class="nav-link">
                        <i class="fas fa-ticket-alt"></i>
                        My Tickets
                    </a>
                </li>
                <li class="nav-item">
                    <a href="financial_aid" class="nav-link">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile" class="nav-link">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements" class="nav-link active">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </a>
                </li>
                <?php if ($is_class_rep): ?>
                <li class="nav-item">
                    <a href="class_rep_dashboard" class="nav-link">
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
            <div class="header-top">
                <div class="header-left">
                    <div class="breadcrumb">
                        <a href="dashboard"><i class="fas fa-home"></i> Dashboard</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="announcements">Announcements</a>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo safe_display($announcement['title']); ?></span>
                    </div>
                    
                    <h1 class="announcement-title"><?php echo safe_display($announcement['title']); ?></h1>
                    
                    <div class="announcement-meta">
                        <div class="meta-item">
                            <i class="far fa-user"></i>
                            <span><?php echo safe_display($announcement['author_name'] ?? 'Administrator'); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="far fa-clock"></i>
                            <span><?php echo date('F j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                            <span style="color: var(--booking-gray-300);">(<?php echo timeAgo($announcement['created_at']); ?>)</span>
                        </div>
                        <?php if ($announcement['expiry_date']): ?>
                            <div class="meta-item">
                                <i class="far fa-calendar-times"></i>
                                <span>Expires: <?php echo date('F j, Y', strtotime($announcement['expiry_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="meta-item">
                            <i class="far fa-eye"></i>
                            <span><?php echo $announcement['view_count'] + 1; ?> views</span>
                        </div>
                    </div>
                </div>
                
                <div class="header-right">
                    <div class="announcement-status">
                        <span class="status-badge status-<?php echo $announcement['priority']; ?>">
                            <i class="fas <?php echo getPriorityIcon($announcement['priority']); ?>"></i>
                            <?php echo ucfirst($announcement['priority']); ?> Priority
                        </span>
                        
                        <span class="status-badge">
                            <i class="fas <?php echo getCategoryIcon($announcement['category']); ?>"></i>
                            <?php echo safe_display(ucfirst($announcement['category'])); ?>
                        </span>
                        
                        <?php if ($announcement['is_pinned']): ?>
                            <span class="status-badge status-pinned">
                                <i class="fas fa-thumbtack"></i>
                                Pinned
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="content-area">
            <!-- Announcement Content -->
            <div class="content-card">
                <?php if ($announcement['image_url']): ?>
                    <img src="<?php echo safe_display($announcement['image_url']); ?>" 
                         alt="<?php echo safe_display($announcement['title']); ?>" 
                         class="announcement-image">
                <?php endif; ?>
                
                <div class="card-body">
                    <div class="announcement-content">
                        <?php echo formatContent($announcement['content']); ?>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="card-body" style="padding-top: 0;">
                    <div class="action-buttons">
                        <a href="announcements" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back to Announcements
                        </a>
                        <button onclick="window.print()" class="btn btn-secondary">
                            <i class="fas fa-print"></i>
                            Print
                        </button>
                        <button onclick="shareAnnouncement()" class="btn btn-primary">
                            <i class="fas fa-share-alt"></i>
                            Share
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Author Information -->
            <?php if ($announcement['author_name']): ?>
            <div class="content-card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-user-tie"></i>
                        About the Author
                    </h3>
                </div>
                <div class="card-body">
                    <div class="author-info">
                        <div class="author-avatar">
                            <?php echo strtoupper(substr($announcement['author_name'], 0, 1)); ?>
                        </div>
                        <div class="author-details">
                            <div class="author-name"><?php echo safe_display($announcement['author_name']); ?></div>
                            <div class="author-role"><?php echo safe_display(ucfirst(str_replace('_', ' ', $announcement['author_role']))); ?></div>
                            <div class="author-contact">
                                <?php if ($announcement['author_email']): ?>
                                    <a href="mailto:<?php echo safe_display($announcement['author_email']); ?>" class="contact-item">
                                        <i class="far fa-envelope"></i>
                                        Email
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Related Announcements -->
            <?php if (!empty($related_announcements)): ?>
            <div class="sidebar-card">
                <div class="sidebar-header">
                    <h3 class="sidebar-title">
                        <i class="fas fa-link"></i>
                        Related Announcements
                    </h3>
                </div>
                <div class="sidebar-body">
                    <div class="related-list">
                        <?php foreach ($related_announcements as $related): ?>
                            <a href="view_announcement?id=<?php echo $related['id']; ?>" 
                               class="related-item <?php echo $related['is_pinned'] ? 'pinned' : ''; ?>">
                                <div class="related-icon">
                                    <i class="fas <?php echo getCategoryIcon($related['category']); ?>"></i>
                                </div>
                                <div class="related-content">
                                    <div class="related-title"><?php echo safe_display($related['title']); ?></div>
                                    <div class="related-meta">
                                        <span><?php echo timeAgo($related['created_at']); ?></span>
                                        <span class="related-priority status-<?php echo $related['priority']; ?>">
                                            <?php echo ucfirst($related['priority']); ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Latest Announcements -->
            <div class="sidebar-card">
                <div class="sidebar-header">
                    <h3 class="sidebar-title">
                        <i class="fas fa-history"></i>
                        Latest Announcements
                    </h3>
                </div>
                <div class="sidebar-body">
                    <div class="related-list">
                        <?php foreach ($latest_announcements as $latest): ?>
                            <a href="view_announcement?id=<?php echo $latest['id']; ?>" 
                               class="related-item <?php echo $latest['is_pinned'] ? 'pinned' : ''; ?>">
                                <div class="related-icon">
                                    <i class="fas <?php echo getCategoryIcon($latest['category']); ?>"></i>
                                </div>
                                <div class="related-content">
                                    <div class="related-title"><?php echo safe_display($latest['title']); ?></div>
                                    <div class="related-meta">
                                        <span><?php echo timeAgo($latest['created_at']); ?></span>
                                        <span class="related-priority status-<?php echo $latest['priority']; ?>">
                                            <?php echo ucfirst($latest['priority']); ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="sidebar-card">
                <div class="sidebar-header">
                    <h3 class="sidebar-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </h3>
                </div>
                <div class="sidebar-body">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <a href="announcements?category=<?php echo urlencode($announcement['category']); ?>" 
                           class="btn btn-secondary btn-sm">
                            <i class="fas <?php echo getCategoryIcon($announcement['category']); ?>"></i>
                            More <?php echo safe_display(ucfirst($announcement['category'])); ?> Announcements
                        </a>
                        <a href="announcements?priority=urgent" class="btn btn-secondary btn-sm">
                            <i class="fas fa-exclamation-circle"></i>
                            View Urgent Announcements
                        </a>
                        <a href="announcements?category=academic" class="btn btn-secondary btn-sm">
                            <i class="fas fa-graduation-cap"></i>
                            Academic Announcements
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Share functionality
        function shareAnnouncement() {
            const title = "<?php echo addslashes($announcement['title']); ?>";
            const url = window.location.href;
            
            if (navigator.share) {
                // Use Web Share API if available
                navigator.share({
                    title: title,
                    text: "Check out this announcement: " + title,
                    url: url
                }).catch(console.error);
            } else {
                // Fallback to copying to clipboard
                navigator.clipboard.writeText(title + "\n" + url)
                    .then(() => {
                        alert("Link copied to clipboard!");
                    })
                    .catch(err => {
                        console.error('Failed to copy: ', err);
                        // Fallback to prompt
                        prompt("Copy this link to share:", url);
                    });
            }
        }

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
        document.querySelectorAll('.content-card, .sidebar-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(card);
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Print functionality enhancement
        const printButton = document.querySelector('button[onclick="window.print()"]');
        if (printButton) {
            printButton.addEventListener('click', function() {
                // Add a small delay to ensure content is ready
                setTimeout(() => {
                    window.print();
                }, 100);
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'announcements';
            }
            
            // Ctrl+P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl+S to share
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                shareAnnouncement();
            }
        });

        // Make external links open in new tab
        document.querySelectorAll('.announcement-content a').forEach(link => {
            if (link.hostname !== window.location.hostname) {
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
            }
        });

        // Add copy link functionality to meta items
        document.querySelectorAll('.meta-item').forEach(item => {
            item.style.cursor = 'pointer';
            item.addEventListener('click', function(e) {
                if (e.target.tagName === 'A') return;
                
                const text = this.textContent.trim();
                navigator.clipboard.writeText(text)
                    .then(() => {
                        const originalHTML = this.innerHTML;
                        const icon = '<i class="fas fa-check" style="color: var(--booking-green); margin-left: 0.5rem;"></i>';
                        this.innerHTML += icon;
                        
                        setTimeout(() => {
                            this.innerHTML = originalHTML;
                        }, 2000);
                    })
                    .catch(console.error);
            });
        });
    </script>
</body>
</html>