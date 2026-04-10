<?php
session_start();
require_once '../config/database.php';
require_once '../config/email_config_base.php';

// Check if email_config exists before requiring
if (file_exists('../config/email_config.php')) {
    require_once '../config/email_config.php';
} else {
    // Create a fallback email function if file doesn't exist
    if (!function_exists('sendEmail')) {
        function sendEmail($to, $subject, $body) {
            error_log("Email would be sent to: $to - Subject: $subject");
            return true;
        }
    }
}

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
                $priority = $_POST['priority'] ?? 'normal';
                $category = $_POST['category'] ?? 'general';
                $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
                $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
                
                // Validate required fields
                if (empty($title) || empty($content)) {
                    $_SESSION['error_message'] = "Title and content are required fields.";
                    break;
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO announcements (title, content, excerpt, author_id, priority, category, is_pinned, expiry_date, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        RETURNING id
                    ");
                    $stmt->execute([$title, $content, $excerpt, $user_id, $priority, $category, $is_pinned, $expiry_date]);
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $announcement_id = $result['id'];
                    
                    // Create conversation for this announcement
                    $conversation_title = "Announcement: " . $title;
                    $stmt = $pdo->prepare("
                        INSERT INTO conversations (title, created_by, conversation_type, created_at, updated_at)
                        VALUES (?, ?, 'announcement', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        RETURNING id
                    ");
                    $stmt->execute([$conversation_title, $user_id]);
                    $conv_result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $conversation_id = $conv_result['id'];
                    
                    // Add announcement message to conversation
                    $stmt = $pdo->prepare("
                        INSERT INTO conversation_messages (conversation_id, sender_id, content, created_at)
                        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$conversation_id, $user_id, $content]);
                    
                    // Add all active users to the conversation
                    $stmt = $pdo->prepare("
                        SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL
                    ");
                    $stmt->execute();
                    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($all_users as $member) {
                        $stmt = $pdo->prepare("
                            INSERT INTO conversation_participants (conversation_id, user_id, role, joined_at)
                            VALUES (?, ?, 'member', CURRENT_TIMESTAMP)
                            ON CONFLICT (conversation_id, user_id) DO NOTHING
                        ");
                        $stmt->execute([$conversation_id, $member['id']]);
                    }
                    
                    $pdo->commit();
                    
                    // ============================================
                    // SEND EMAIL NOTIFICATIONS TO ALL USERS
                    // ============================================
                    
                    // Get base URL for email links
                    $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
                    $base_url .= str_replace('admin/announcements.php', '', $_SERVER['SCRIPT_NAME']);
                    
                    // Get all active users with valid emails
                    $users_stmt = $pdo->prepare("
                        SELECT id, full_name, email, role 
                        FROM users 
                        WHERE status = 'active' 
                        AND deleted_at IS NULL 
                        AND email IS NOT NULL 
                        AND email != ''
                    ");
                    $users_stmt->execute();
                    $all_users_list = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $email_sent_count = 0;
                    $email_fail_count = 0;
                    
                    // Priority badge for email
                    $priority_badge = '';
                    $priority_color = '';
                    switch ($priority) {
                        case 'urgent':
                            $priority_badge = '🔴 URGENT';
                            $priority_color = '#dc3545';
                            break;
                        case 'high':
                            $priority_badge = '🟠 HIGH PRIORITY';
                            $priority_color = '#fd7e14';
                            break;
                        case 'normal':
                            $priority_badge = '🔵 NORMAL';
                            $priority_color = '#3B82F6';
                            break;
                        case 'low':
                            $priority_badge = '🟢 LOW PRIORITY';
                            $priority_color = '#28a745';
                            break;
                    }
                    
                    // Create email content
                    $email_subject = ($priority === 'urgent' ? '🚨 URGENT: ' : '📢 ') . $title . " - Isonga RPSU";
                    
                    $email_body = '<!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>New Announcement</title>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: linear-gradient(135deg, #3B82F6 0%, #1D4ED8 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                            .content { background: #f9fafb; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #e5e7eb; border-top: none; }
                            .title { font-size: 24px; margin-bottom: 10px; }
                            .meta { color: #6b7280; font-size: 12px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }
                            .priority-badge { display: inline-block; background: ' . $priority_color . '; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-left: 10px; }
                            .message { margin: 20px 0; white-space: pre-wrap; }
                            .button { display: inline-block; background: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 20px; }
                            .footer { text-align: center; padding: 20px; font-size: 12px; color: #6b7280; }
                            .excerpt { background: #e5e7eb; padding: 15px; border-radius: 8px; margin: 15px 0; font-style: italic; }
                            .category { display: inline-block; background: #e5e7eb; padding: 2px 8px; border-radius: 4px; font-size: 11px; margin-left: 10px; }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <div class="header">
                                <h1 class="title">📢 ' . ($priority === 'urgent' ? 'URGENT ANNOUNCEMENT' : 'New Announcement') . '</h1>
                                <p>Isonga RPSU - Admin Announcement</p>
                            </div>
                            <div class="content">
                                <h2>' . htmlspecialchars($title) . '
                                    <span class="priority-badge">' . $priority_badge . '</span>
                                </h2>
                                <div class="meta">
                                    Published by: ' . htmlspecialchars($_SESSION['full_name']) . ' (System Admin)<br>
                                    Date: ' . date('F j, Y g:i A') . '
                                </div>';
                    
                    if (!empty($excerpt)) {
                        $email_body .= '<div class="excerpt">📌 <strong>Summary:</strong><br>' . nl2br(htmlspecialchars($excerpt)) . '</div>';
                    }
                    
                    $email_body .= '<div class="message">' . nl2br(htmlspecialchars($content)) . '</div>
                                <div style="text-align: center;">
                                    <a href="' . $base_url . 'announcements.php" class="button">View All Announcements</a>
                                </div>
                            </div>
                            <div class="footer">
                                <p>This is an automated message from the Isonga RPSU Management System.</p>
                                <p>© ' . date('Y') . ' Isonga RPSU - All Rights Reserved</p>
                            </div>
                        </div>
                    </body>
                    </html>';
                    
                    // Send emails to all users
                    foreach ($all_users_list as $user_data) {
                        if (!empty($user_data['email']) && filter_var($user_data['email'], FILTER_VALIDATE_EMAIL)) {
                            try {
                                sendEmail($user_data['email'], $email_subject, $email_body);
                                $email_sent_count++;
                                error_log("Announcement email sent to: " . $user_data['email'] . " (Role: " . $user_data['role'] . ")");
                            } catch (Exception $e) {
                                $email_fail_count++;
                                error_log("Failed to send email to: " . $user_data['email'] . " - Error: " . $e->getMessage());
                            }
                        }
                    }
                    
                    $_SESSION['success_message'] = "✅ Announcement created successfully!";
                    $_SESSION['success_message'] .= "<br>📧 Email notifications sent to $email_sent_count users ($email_fail_count failed).";
                    
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $_SESSION['error_message'] = "Error creating announcement: " . $e->getMessage();
                    error_log("Announcement creation error: " . $e->getMessage());
                }
                break;
                
            case 'update_announcement':
                $announcement_id = $_POST['announcement_id'];
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                $priority = $_POST['priority'] ?? 'normal';
                $category = $_POST['category'] ?? 'general';
                $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
                $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
                $status = $_POST['status'] ?? 'published';
                
                // Validate required fields
                if (empty($title) || empty($content)) {
                    $_SESSION['error_message'] = "Title and content are required fields.";
                    break;
                }
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE announcements 
                        SET title = ?, content = ?, excerpt = ?, priority = ?, category = ?, 
                            is_pinned = ?, expiry_date = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$title, $content, $excerpt, $priority, $category, $is_pinned, $expiry_date, $status, $announcement_id]);
                    
                    $_SESSION['success_message'] = "Announcement updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating announcement: " . $e->getMessage();
                }
                break;
                
            case 'delete_announcement':
                $announcement_id = $_POST['announcement_id'];
                
                try {
                    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
                    $stmt->execute([$announcement_id]);
                    
                    $_SESSION['success_message'] = "Announcement deleted successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting announcement: " . $e->getMessage();
                }
                break;
                
            case 'toggle_pin':
                $announcement_id = $_POST['announcement_id'];
                $is_pinned = $_POST['is_pinned'] == '1' ? 0 : 1;
                
                try {
                    $stmt = $pdo->prepare("UPDATE announcements SET is_pinned = ? WHERE id = ?");
                    $stmt->execute([$is_pinned, $announcement_id]);
                    
                    $_SESSION['success_message'] = $is_pinned ? "Announcement pinned successfully!" : "Announcement unpinned successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error toggling pin status: " . $e->getMessage();
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
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
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

if ($status_filter !== 'all') {
    $query .= " AND a.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $query .= " AND a.priority = ?";
    $params[] = $priority_filter;
}

if ($category_filter !== 'all') {
    $query .= " AND a.category = ?";
    $params[] = $category_filter;
}

if (!empty($date_from)) {
    $query .= " AND DATE(a.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(a.created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY a.is_pinned DESC, a.created_at DESC";

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
    
    // Published announcements
    $stmt = $pdo->query("SELECT COUNT(*) as published FROM announcements WHERE status = 'published'");
    $published_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['published'] ?? 0;
    
    // Draft announcements
    $stmt = $pdo->query("SELECT COUNT(*) as draft FROM announcements WHERE status = 'draft'");
    $draft_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['draft'] ?? 0;
    
    // Archived announcements
    $stmt = $pdo->query("SELECT COUNT(*) as archived FROM announcements WHERE status = 'archived'");
    $archived_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['archived'] ?? 0;
    
    // Pinned announcements
    $stmt = $pdo->query("SELECT COUNT(*) as pinned FROM announcements WHERE is_pinned = true");
    $pinned_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['pinned'] ?? 0;
    
    // Urgent announcements
    $stmt = $pdo->query("SELECT COUNT(*) as urgent FROM announcements WHERE priority = 'urgent' AND status = 'published'");
    $urgent_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['urgent'] ?? 0;
    
    // Recent announcements (last 7 days)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as recent 
        FROM announcements 
        WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $stmt->execute();
    $recent_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['recent'] ?? 0;
    
    // My announcements
    $stmt = $pdo->prepare("SELECT COUNT(*) as my_announcements FROM announcements WHERE author_id = ?");
    $stmt->execute([$user_id]);
    $my_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['my_announcements'] ?? 0;
    
    // Get total active users count for email info
    $stmt = $pdo->query("
        SELECT COUNT(*) as total_users 
        FROM users 
        WHERE status = 'active' 
        AND deleted_at IS NULL 
        AND email IS NOT NULL 
        AND email != ''
    ");
    $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'] ?? 0;
    
    // Get categories list
    $stmt = $pdo->query("
        SELECT DISTINCT category, COUNT(*) as count 
        FROM announcements 
        GROUP BY category 
        ORDER BY count DESC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $total_announcements = $published_announcements = $draft_announcements = $archived_announcements = 0;
    $pinned_announcements = $urgent_announcements = $recent_announcements = $my_announcements = $total_users = 0;
    $categories = [];
}

// Check if we're viewing/editing a specific announcement
$edit_announcement = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name as author_name, u.role as author_role
            FROM announcements a
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$_GET['edit']]);
        $edit_announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_announcement) {
            $_SESSION['error_message'] = "Announcement not found.";
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
    <title>Announcements Management - Admin Panel</title>
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
            --info: #17a2b8;
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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

        .stat-card.info {
            border-left-color: var(--info);
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

        .stat-card.info .stat-icon {
            background: #d1ecf1;
            color: var(--info);
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
            font-size: 0.7rem;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            position: relative;
        }

        .announcement-card.pinned {
            border-left: 4px solid var(--warning);
        }

        .announcement-card.urgent {
            border-left: 4px solid var(--danger);
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .pinned-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--warning);
            color: var(--text-dark);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .urgent-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--danger);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .announcement-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
            padding-right: 80px;
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
            font-size: 0.75rem;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: var(--info);
        }

        /* Email Info Banner */
        .email-info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .email-info-banner i {
            font-size: 2rem;
            opacity: 0.8;
        }

        .email-info-content {
            flex: 1;
        }

        .email-info-content h4 {
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }

        .email-info-content p {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        /* Priority Badge */
        .priority-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .priority-urgent {
            background: var(--danger);
            color: white;
        }

        .priority-high {
            background: #fd7e14;
            color: white;
        }

        .priority-normal {
            background: var(--primary-blue);
            color: white;
        }

        .priority-low {
            background: var(--success);
            color: white;
        }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-published {
            background: var(--success);
            color: white;
        }

        .status-draft {
            background: var(--warning);
            color: var(--text-dark);
        }

        .status-archived {
            background: var(--dark-gray);
            color: white;
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
            
            .email-info-banner {
                flex-direction: column;
                text-align: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Admin Announcements</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">System Administrator</div>
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
                    <a href="users.php">
                        <i class="fas fa-users"></i>
                        <span>User Management</span>
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
                    <a href="committee.php">
                        <i class="fas fa-user-tie"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
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
                    <h1>Announcements Management</h1>
                    <p>Create, manage, and broadcast announcements to all users</p>
                </div>
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

            <!-- Email Notification Info Banner -->
            <?php if (!$edit_announcement): ?>
            <div class="email-info-banner">
                <i class="fas fa-envelope-open-text"></i>
                <div class="email-info-content">
                    <h4><i class="fas fa-bullhorn"></i> Email Notifications Enabled</h4>
                    <p>When you publish a new announcement, all <?php echo number_format($total_users); ?> active users will receive an email notification automatically.</p>
                </div>
                <i class="fas fa-check-circle" style="font-size: 2rem;"></i>
            </div>
            <?php endif; ?>

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
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($published_announcements); ?></div>
                        <div class="stat-label">Published</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-pen"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($draft_announcements); ?></div>
                        <div class="stat-label">Drafts</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($archived_announcements); ?></div>
                        <div class="stat-label">Archived</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-thumbtack"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($pinned_announcements); ?></div>
                        <div class="stat-label">Pinned</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($urgent_announcements); ?></div>
                        <div class="stat-label">Urgent</div>
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
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="low">🟢 Low Priority</option>
                                        <option value="normal" selected>🔵 Normal Priority</option>
                                        <option value="high">🟠 High Priority</option>
                                        <option value="urgent">🔴 Urgent</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="general">General</option>
                                        <option value="academic">Academic</option>
                                        <option value="event">Event</option>
                                        <option value="administrative">Administrative</option>
                                        <option value="emergency">Emergency</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Expiry Date (Optional)</label>
                                    <input type="date" name="expiry_date" class="form-input">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <input type="checkbox" name="is_pinned" value="1">
                                    Pin this announcement (stays at top)
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Excerpt (Optional)</label>
                                <textarea name="excerpt" class="form-textarea" placeholder="Brief summary of the announcement (will be auto-generated if empty)" maxlength="500"></textarea>
                                <small style="color: var(--dark-gray); margin-top: 0.25rem;">This will be used as a preview in email notifications.</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Announcement Content *</label>
                                <textarea name="content" class="form-textarea" placeholder="Enter the full announcement content" required></textarea>
                                <small style="color: var(--dark-gray); margin-top: 0.25rem;">This will be included in the email notification to all users.</small>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="toggleAnnouncementForm()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Publish & Send Emails
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
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="normal" <?php echo $priority_filter === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
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
                            <div class="announcement-card <?php echo $announcement['is_pinned'] ? 'pinned' : ''; ?> <?php echo $announcement['priority'] === 'urgent' ? 'urgent' : ''; ?>">
                                <?php if ($announcement['is_pinned']): ?>
                                    <div class="pinned-badge">
                                        <i class="fas fa-thumbtack"></i> Pinned
                                    </div>
                                <?php elseif ($announcement['priority'] === 'urgent'): ?>
                                    <div class="urgent-badge">
                                        <i class="fas fa-exclamation-triangle"></i> URGENT
                                    </div>
                                <?php endif; ?>
                                
                                <div class="announcement-header">
                                    <h3 class="announcement-title">
                                        <?php echo htmlspecialchars($announcement['title']); ?>
                                        <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                                            <?php echo ucfirst($announcement['priority']); ?>
                                        </span>
                                        <span class="status-badge status-<?php echo $announcement['status']; ?>">
                                            <?php echo ucfirst($announcement['status']); ?>
                                        </span>
                                    </h3>
                                    <div class="announcement-meta">
                                        <span><strong>Author:</strong> <?php echo htmlspecialchars($announcement['author_name']); ?> (<?php echo htmlspecialchars($announcement['author_role']); ?>)</span>
                                        <span><strong>Category:</strong> <?php echo ucfirst($announcement['category']); ?></span>
                                        <span><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                        <span><strong>Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($announcement['updated_at'])); ?></span>
                                        <?php if ($announcement['expiry_date']): ?>
                                            <span><strong>Expires:</strong> <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?></span>
                                        <?php endif; ?>
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
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                        <input type="hidden" name="is_pinned" value="<?php echo $announcement['is_pinned']; ?>">
                                        <button type="submit" class="btn btn-warning btn-sm" title="<?php echo $announcement['is_pinned'] ? 'Unpin' : 'Pin'; ?>">
                                            <i class="fas fa-thumbtack"></i> <?php echo $announcement['is_pinned'] ? 'Unpin' : 'Pin'; ?>
                                        </button>
                                    </form>
                                    <a href="?edit=<?php echo $announcement['id']; ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this announcement? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
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
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="low" <?php echo $edit_announcement['priority'] === 'low' ? 'selected' : ''; ?>>🟢 Low Priority</option>
                                        <option value="normal" <?php echo $edit_announcement['priority'] === 'normal' ? 'selected' : ''; ?>>🔵 Normal Priority</option>
                                        <option value="high" <?php echo $edit_announcement['priority'] === 'high' ? 'selected' : ''; ?>>🟠 High Priority</option>
                                        <option value="urgent" <?php echo $edit_announcement['priority'] === 'urgent' ? 'selected' : ''; ?>>🔴 Urgent</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="general" <?php echo $edit_announcement['category'] === 'general' ? 'selected' : ''; ?>>General</option>
                                        <option value="academic" <?php echo $edit_announcement['category'] === 'academic' ? 'selected' : ''; ?>>Academic</option>
                                        <option value="event" <?php echo $edit_announcement['category'] === 'event' ? 'selected' : ''; ?>>Event</option>
                                        <option value="administrative" <?php echo $edit_announcement['category'] === 'administrative' ? 'selected' : ''; ?>>Administrative</option>
                                        <option value="emergency" <?php echo $edit_announcement['category'] === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="published" <?php echo $edit_announcement['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                        <option value="draft" <?php echo $edit_announcement['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="archived" <?php echo $edit_announcement['status'] === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="form-input" value="<?php echo $edit_announcement['expiry_date']; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <input type="checkbox" name="is_pinned" value="1" <?php echo $edit_announcement['is_pinned'] ? 'checked' : ''; ?>>
                                        Pin this announcement
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Excerpt</label>
                                <textarea name="excerpt" class="form-textarea" placeholder="Brief summary" maxlength="500"><?php echo htmlspecialchars($edit_announcement['excerpt'] ?? ''); ?></textarea>
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