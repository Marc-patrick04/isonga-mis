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

// Get unread messages count for badge
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Handle announcement actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_announcement':
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                
                // Validate required fields
                if (empty($title) || empty($content)) {
                    $_SESSION['error_message'] = "Title and content are required fields.";
                    break;
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO announcements (title, content, excerpt, author_id, created_at, updated_at)
                        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$title, $content, $excerpt, $user_id]);
                    
                    $announcement_id = $pdo->lastInsertId();
                    
                    // Create conversation for this announcement
                    $conversation_title = "Announcement: " . $title;
                    $stmt = $pdo->prepare("
                        INSERT INTO conversations (title, created_by, conversation_type, created_at, updated_at)
                        VALUES (?, ?, 'announcement', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$conversation_title, $user_id]);
                    $conversation_id = $pdo->lastInsertId();
                    
                    // Add announcement message to conversation
                    $stmt = $pdo->prepare("
                        INSERT INTO conversation_messages (conversation_id, sender_id, content, created_at)
                        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$conversation_id, $user_id, $content]);
                    
                    // Add all committee members to the conversation
                    $stmt = $pdo->prepare("
                        SELECT id FROM users WHERE role != 'student' AND status = 'active'
                    ");
                    $stmt->execute();
                    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($committee_members as $member) {
                        $stmt = $pdo->prepare("
                            INSERT INTO conversation_participants (conversation_id, user_id, role, joined_at)
                            VALUES (?, ?, 'member', CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$conversation_id, $member['id']]);
                    }
                    
                    $_SESSION['success_message'] = "Announcement created and shared with committee members successfully!";
                    
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating announcement: " . $e->getMessage();
                }
                break;
                
            case 'update_announcement':
                $announcement_id = $_POST['announcement_id'];
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                
                // Validate required fields
                if (empty($title) || empty($content)) {
                    $_SESSION['error_message'] = "Title and content are required fields.";
                    break;
                }
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE announcements 
                        SET title = ?, content = ?, excerpt = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND author_id = ?
                    ");
                    $stmt->execute([$title, $content, $excerpt, $announcement_id, $user_id]);
                    
                    $_SESSION['success_message'] = "Announcement updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating announcement: " . $e->getMessage();
                }
                break;
                
            case 'delete_announcement':
                $announcement_id = $_POST['announcement_id'];
                
                try {
                    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ? AND author_id = ?");
                    $stmt->execute([$announcement_id, $user_id]);
                    
                    $_SESSION['success_message'] = "Announcement deleted successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting announcement: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: announcements.php");
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$author_filter = $_GET['author'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query for announcements
$query = "
    SELECT a.*, u.full_name as author_name, u.role as author_role
    FROM announcements a
    LEFT JOIN users u ON a.author_id = u.id
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($search)) {
    $query .= " AND (a.title ILIKE ? OR a.content ILIKE ? OR a.excerpt ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($author_filter !== 'all') {
    $query .= " AND a.author_id = ?";
    $params[] = $author_filter;
}

if (!empty($date_from)) {
    $query .= " AND DATE(a.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(a.created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY a.created_at DESC";

// Get announcements
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Announcements query error: " . $e->getMessage());
    $announcements = [];
}

// Get authors for filter
try {
    $stmt = $pdo->query("
        SELECT DISTINCT u.id, u.full_name, u.role 
        FROM announcements a
        JOIN users u ON a.author_id = u.id
        ORDER BY u.full_name
    ");
    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $authors = [];
}

// Get statistics for dashboard
try {
    // Total announcements
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM announcements");
    $total_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // My announcements
    $stmt = $pdo->prepare("SELECT COUNT(*) as my_announcements FROM announcements WHERE author_id = ?");
    $stmt->execute([$user_id]);
    $my_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['my_announcements'] ?? 0;
    
    // Recent announcements (last 7 days)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as recent 
        FROM announcements 
        WHERE author_id = ? AND created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $stmt->execute([$user_id]);
    $recent_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['recent'] ?? 0;
    
    // Committee announcements
    $stmt = $pdo->query("
        SELECT COUNT(*) as committee_announcements 
        FROM announcements a
        JOIN users u ON a.author_id = u.id
        WHERE u.role != 'student'
    ");
    $committee_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['committee_announcements'] ?? 0;
    
} catch (PDOException $e) {
    $total_announcements = $my_announcements = $recent_announcements = $committee_announcements = 0;
}

// Check if we're viewing/editing a specific announcement
$edit_announcement = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name as author_name, u.role as author_role
            FROM announcements a
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.id = ? AND a.author_id = ?
        ");
        $stmt->execute([$_GET['edit'], $user_id]);
        $edit_announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_announcement) {
            $_SESSION['error_message'] = "Announcement not found or you don't have permission to edit it.";
            header("Location: announcements.php");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error loading announcement: " . $e->getMessage();
        header("Location: announcements.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Announcements Management - Minister of Public Relations</title>
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

        .dashboard-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
            gap: 0.75rem;
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

        /* Announcement Form */
        .announcement-form {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
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
            flex-wrap: wrap;
        }

        /* Announcements Grid */
        .announcements-grid {
            display: grid;
            gap: 1.5rem;
        }

        .announcement-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .announcement-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .announcement-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .announcement-body {
            padding: 1.25rem;
        }

        .announcement-excerpt {
            color: var(--text-dark);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .announcement-content {
            color: var(--text-dark);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .announcement-actions {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            flex-wrap: wrap;
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
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

            .main-content.sidebar-collapsed {
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .form-actions {
                flex-direction: column;
            }

            .announcement-meta {
                flex-direction: column;
                gap: 0.5rem;
            }

            .stat-number {
                font-size: 1.1rem;
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

            .announcement-actions {
                flex-direction: column;
            }

            .announcement-actions .btn {
                width: 100%;
                justify-content: center;
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
                    <h1>Isonga - Announcements</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <!-- <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button> -->
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <!-- <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div> -->
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
                <!-- <div class="welcome-section">
                    <h1>Announcements Management</h1>
                    <p>Create and manage official announcements for the student community</p>
                </div> -->
                <div class="header-actions">
                    <?php if ($edit_announcement): ?>
                        <a href="announcements.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Announcements
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" onclick="toggleAnnouncementForm()">
                            <i class="fas fa-plus"></i> New Announcement
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_announcements); ?></div>
                        <div class="stat-label">Total Announcements</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($my_announcements); ?></div>
                        <div class="stat-label">My Announcements</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($recent_announcements); ?></div>
                        <div class="stat-label">Recent (7 days)</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($committee_announcements); ?></div>
                        <div class="stat-label">Committee Announcements</div>
                    </div>
                </div>
            </div>

            <?php if (!$edit_announcement): ?>
                <!-- Announcement Form (Initially Hidden) -->
                <div class="announcement-form" id="announcementForm" style="display: none;">
                    <h2 class="form-title">Create New Announcement</h2>
                    <form method="POST" id="createAnnouncementForm">
                        <input type="hidden" name="action" value="create_announcement">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Announcement Title *</label>
                                <input type="text" name="title" class="form-input" placeholder="Enter announcement title" required maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Excerpt (Optional)</label>
                                <textarea name="excerpt" class="form-textarea" placeholder="Brief summary of the announcement (will be auto-generated if empty)" maxlength="500"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Announcement Content *</label>
                                <textarea name="content" class="form-textarea" placeholder="Enter the full announcement content" required></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="toggleAnnouncementForm()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Publish Announcement
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-input" placeholder="Search announcements..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Author</label>
                            <select name="author" class="form-select">
                                <option value="all" <?php echo $author_filter === 'all' ? 'selected' : ''; ?>>All Authors</option>
                                <?php foreach ($authors as $author): ?>
                                    <option value="<?php echo $author['id']; ?>" <?php echo $author_filter == $author['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($author['full_name']); ?> (<?php echo htmlspecialchars($author['role']); ?>)
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
                            <a href="announcements.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Announcements List -->
                <div class="announcements-grid">
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h3>No Announcements Found</h3>
                            <p>There are no announcements matching your criteria.</p>
                            <button type="button" class="btn btn-primary" onclick="toggleAnnouncementForm()" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First Announcement
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-card">
                                <div class="announcement-header">
                                    <h3 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                    <div class="announcement-meta">
                                        <span><strong>Author:</strong> <?php echo htmlspecialchars($announcement['author_name']); ?> (<?php echo htmlspecialchars($announcement['author_role']); ?>)</span>
                                        <span><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                        <span><strong>Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($announcement['updated_at'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="announcement-body">
                                    <?php if (!empty($announcement['excerpt'])): ?>
                                        <div class="announcement-excerpt">
                                            <strong>Summary:</strong> <?php echo htmlspecialchars($announcement['excerpt']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="announcement-content">
                                        <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                                    </div>
                                </div>
                                
                                <div class="announcement-actions">
                                    <?php if ($announcement['author_id'] == $user_id): ?>
                                        <a href="?edit=<?php echo $announcement['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_announcement">
                                            <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this announcement?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                            <i class="fas fa-info-circle"></i> You can only edit your own announcements
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Edit Announcement Form -->
                <div class="announcement-form">
                    <h2 class="form-title">Edit Announcement</h2>
                    <form method="POST" id="editAnnouncementForm">
                        <input type="hidden" name="action" value="update_announcement">
                        <input type="hidden" name="announcement_id" value="<?php echo $edit_announcement['id']; ?>">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Announcement Title *</label>
                                <input type="text" name="title" class="form-input" value="<?php echo htmlspecialchars($edit_announcement['title']); ?>" required maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Excerpt (Optional)</label>
                                <textarea name="excerpt" class="form-textarea" placeholder="Brief summary of the announcement" maxlength="500"><?php echo htmlspecialchars($edit_announcement['excerpt'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Announcement Content *</label>
                                <textarea name="content" class="form-textarea" required><?php echo htmlspecialchars($edit_announcement['content']); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="announcements.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Announcement
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        // const themeToggle = document.getElementById('themeToggle');
        // const body = document.body;

        // const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        // if (savedTheme === 'dark') {
        //     body.classList.add('dark-mode');
        //     themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        // }

        // themeToggle.addEventListener('click', () => {
        //     body.classList.toggle('dark-mode');
        //     const isDark = body.classList.contains('dark-mode');
        //     localStorage.setItem('theme', isDark ? 'dark' : 'light');
        //     themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        // });

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

        // Toggle announcement form visibility
        function toggleAnnouncementForm() {
            const form = document.getElementById('announcementForm');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth' });
            } else {
                form.style.display = 'none';
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

            // Add loading animations
            const cards = document.querySelectorAll('.announcement-card, .announcement-form');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const title = form.querySelector('input[name="title"]');
                    const content = form.querySelector('textarea[name="content"]');
                    
                    if (title && content) {
                        if (!title.value.trim()) {
                            e.preventDefault();
                            alert('Please enter an announcement title.');
                            title.focus();
                            return;
                        }
                        
                        if (!content.value.trim()) {
                            e.preventDefault();
                            alert('Please enter announcement content.');
                            content.focus();
                            return;
                        }
                    }
                });
            });
        });

        // Auto-save draft (optional feature)
        let autoSaveTimer;
        document.addEventListener('DOMContentLoaded', function() {
            const titleInput = document.querySelector('input[name="title"]');
            const contentTextarea = document.querySelector('textarea[name="content"]');
            const excerptTextarea = document.querySelector('textarea[name="excerpt"]');
            
            if (titleInput && contentTextarea && !document.querySelector('#editAnnouncementForm')) {
                [titleInput, contentTextarea, excerptTextarea].forEach(element => {
                    if (element) {
                        element.addEventListener('input', function() {
                            clearTimeout(autoSaveTimer);
                            autoSaveTimer = setTimeout(() => {
                                // Save to localStorage as draft
                                const draft = {
                                    title: titleInput.value,
                                    content: contentTextarea.value,
                                    excerpt: excerptTextarea ? excerptTextarea.value : ''
                                };
                                localStorage.setItem('announcement_draft', JSON.stringify(draft));
                                console.log('Draft auto-saved');
                            }, 2000);
                        });
                    }
                });
                
                // Load draft on page load
                const draft = localStorage.getItem('announcement_draft');
                if (draft) {
                    const draftData = JSON.parse(draft);
                    titleInput.value = draftData.title || '';
                    contentTextarea.value = draftData.content || '';
                    if (excerptTextarea) {
                        excerptTextarea.value = draftData.excerpt || '';
                    }
                }
            }
        });

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>