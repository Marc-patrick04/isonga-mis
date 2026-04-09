<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

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

// Mark messages as read when viewing
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $message_id = $_GET['mark_read'];
    try {
        // Get conversation_id from the message
        $stmt = $pdo->prepare("SELECT conversation_id FROM conversation_messages WHERE id = ?");
        $stmt->execute([$message_id]);
        $conversation_id = $stmt->fetchColumn();
        
        if ($conversation_id) {
            // Update last_read_message_id for this user in this conversation
            $stmt = $pdo->prepare("
                UPDATE conversation_participants 
                SET last_read_message_id = ? 
                WHERE conversation_id = ? AND user_id = ? AND (last_read_message_id IS NULL OR ? > last_read_message_id)
            ");
            $stmt->execute([$message_id, $conversation_id, $student_id, $message_id]);
        }
        header('Location: messages.php');
        exit();
    } catch (PDOException $e) {
        error_log("Failed to mark message as read: " . $e->getMessage());
    }
}

// Get all conversations for the student
$conversations_query = "
    SELECT DISTINCT
        c.id as conversation_id,
        c.title,
        c.conversation_type,
        c.created_at as conversation_created_at,
        (
            SELECT COUNT(*) 
            FROM conversation_messages cm2 
            WHERE cm2.conversation_id = c.id 
            AND cm2.id > COALESCE(cp.last_read_message_id, 0)
        ) as unread_count,
        (
            SELECT cm3.content 
            FROM conversation_messages cm3 
            WHERE cm3.conversation_id = c.id 
            ORDER BY cm3.created_at DESC 
            LIMIT 1
        ) as last_message,
        (
            SELECT cm4.created_at 
            FROM conversation_messages cm4 
            WHERE cm4.conversation_id = c.id 
            ORDER BY cm4.created_at DESC 
            LIMIT 1
        ) as last_message_time,
        (
            SELECT u.full_name 
            FROM conversation_messages cm5
            JOIN users u ON cm5.sender_id = u.id
            WHERE cm5.conversation_id = c.id 
            ORDER BY cm5.created_at DESC 
            LIMIT 1
        ) as last_sender_name
    FROM conversations c
    JOIN conversation_participants cp ON c.id = cp.conversation_id
    WHERE cp.user_id = ?
    ORDER BY last_message_time DESC
";

$conversations_stmt = $pdo->prepare($conversations_query);
$conversations_stmt->execute([$student_id]);
$conversations = $conversations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get specific conversation messages if selected
$selected_conversation = null;
$conversation_messages = [];
$other_participants = [];

if (isset($_GET['conversation']) && is_numeric($_GET['conversation'])) {
    $conversation_id = $_GET['conversation'];
    
    // Verify user is part of this conversation
    $verify_stmt = $pdo->prepare("
        SELECT 1 FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $verify_stmt->execute([$conversation_id, $student_id]);
    
    if ($verify_stmt->fetchColumn()) {
        // Get conversation details
        $conv_stmt = $pdo->prepare("
            SELECT c.*, 
                   COUNT(DISTINCT cp.user_id) as participant_count
            FROM conversations c
            LEFT JOIN conversation_participants cp ON c.id = cp.conversation_id
            WHERE c.id = ?
            GROUP BY c.id
        ");
        $conv_stmt->execute([$conversation_id]);
        $selected_conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get other participants in the conversation
        $participants_stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.role, u.avatar_url
            FROM conversation_participants cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.conversation_id = ? AND cp.user_id != ?
            ORDER BY u.role, u.full_name
        ");
        $participants_stmt->execute([$conversation_id, $student_id]);
        $other_participants = $participants_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get messages
        $messages_stmt = $pdo->prepare("
            SELECT cm.*, u.full_name as sender_name, u.role as sender_role, u.avatar_url
            FROM conversation_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE cm.conversation_id = ?
            ORDER BY cm.created_at ASC
        ");
        $messages_stmt->execute([$conversation_id]);
        $conversation_messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mark messages as read
        $latest_message_id = !empty($conversation_messages) ? end($conversation_messages)['id'] : 0;
        if ($latest_message_id > 0) {
            $update_stmt = $pdo->prepare("
                UPDATE conversation_participants 
                SET last_read_message_id = ? 
                WHERE conversation_id = ? AND user_id = ? AND (last_read_message_id IS NULL OR ? > last_read_message_id)
            ");
            $update_stmt->execute([$latest_message_id, $conversation_id, $student_id, $latest_message_id]);
        }
    } else {
        header('Location: messages');
        exit();
    }
}

// Get system notifications for the student
$notifications_stmt = $pdo->prepare("
    SELECT * FROM system_notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$notifications_stmt->execute([$student_id]);
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
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

function getRoleBadgeClass($role) {
    $badges = [
        'class_representative' => 'badge-class-rep',
        'minister_public_relations' => 'badge-minister',
        'guild_president' => 'badge-president',
        'vice_guild_academic' => 'badge-vice',
        'vice_guild_finance' => 'badge-finance',
        'general_secretary' => 'badge-secretary',
        'minister_sports' => 'badge-sports',
        'minister_environment' => 'badge-environment',
        'minister_health' => 'badge-health',
        'minister_culture' => 'badge-culture',
        'minister_gender' => 'badge-gender',
        'president_representative_board' => 'badge-president',
        'committee_member' => 'badge-committee',
        'admin' => 'badge-admin'
    ];
    return $badges[$role] ?? 'badge-default';
}

function getRoleDisplayName($role) {
    $names = [
        'class_representative' => 'Class Representative',
        'minister_public_relations' => 'Minister of Public Relations',
        'guild_president' => 'Guild President',
        'vice_guild_academic' => 'Vice Guild President (Academic)',
        'vice_guild_finance' => 'Vice Guild President (Finance)',
        'general_secretary' => 'General Secretary',
        'minister_sports' => 'Minister of Sports',
        'minister_environment' => 'Minister of Environment',
        'minister_health' => 'Minister of Health',
        'minister_culture' => 'Minister of Culture',
        'minister_gender' => 'Minister of Gender',
        'president_representative_board' => 'President of Representative Board',
        'committee_member' => 'Committee Member',
        'admin' => 'Administrator'
    ];
    return $names[$role] ?? ucfirst(str_replace('_', ' ', $role));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Messages - Isonga RPSU</title>
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

        /* Messages Container */
        .messages-container {
            display: flex;
            gap: 1.5rem;
            min-height: calc(100vh - 250px);
        }

        /* Conversations Sidebar */
        .conversations-sidebar {
            width: 320px;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            flex-shrink: 0;
        }

        .conversations-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .conversations-header h3 {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .conversations-list {
            overflow-y: auto;
            max-height: calc(100vh - 250px);
        }

        .conversation-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: var(--text-dark);
        }

        .conversation-item:hover {
            background: var(--light-gray);
        }

        .conversation-item.active {
            background: var(--light-blue);
            border-left: 3px solid var(--primary-blue);
        }

        .conversation-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-last-message {
            font-size: 0.75rem;
            color: var(--dark-gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-meta {
            text-align: right;
            flex-shrink: 0;
        }

        .conversation-time {
            font-size: 0.65rem;
            color: var(--dark-gray);
            white-space: nowrap;
        }

        .unread-badge {
            background: var(--danger);
            color: white;
            border-radius: 20px;
            padding: 0.2rem 0.5rem;
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 0.25rem;
            display: inline-block;
        }

        /* Conversation View */
        .conversation-view {
            flex: 1;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .conversation-view-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .conversation-view-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .conversation-participants {
            font-size: 0.75rem;
            color: var(--dark-gray);
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        .participant-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            background: var(--light-gray);
            border-radius: 20px;
            font-size: 0.7rem;
        }

        /* Messages List */
        .messages-list {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-height: calc(100vh - 350px);
        }

        .message {
            display: flex;
            gap: 0.75rem;
            max-width: 80%;
        }

        .message.received {
            align-self: flex-start;
        }

        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .message-content {
            flex: 1;
        }

        .message-header {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
            flex-wrap: wrap;
        }

        .message-sender {
            font-weight: 600;
            font-size: 0.8rem;
        }

        .message-role {
            font-size: 0.65rem;
            padding: 0.15rem 0.4rem;
            border-radius: 12px;
            background: var(--light-gray);
            color: var(--dark-gray);
        }

        .message-time {
            font-size: 0.65rem;
            color: var(--dark-gray);
        }

        .message-bubble {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .message.sent .message-bubble {
            background: var(--primary-blue);
            color: white;
        }

        .message.sent .message-role {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .message.sent .message-sender {
            color: white;
        }

        .message.sent .message-time {
            color: rgba(255, 255, 255, 0.7);
        }

        .system-notification {
            background: var(--light-blue);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 0.75rem;
            color: var(--primary-blue);
            margin: 0.5rem 0;
        }

        /* Empty States */
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
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .empty-state p {
            font-size: 0.85rem;
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

            .messages-container {
                flex-direction: column;
            }

            .conversations-sidebar {
                width: 100%;
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

            .message {
                max-width: 95%;
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
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
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
                    <a href="messages.php" class="active">
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
                    <i class="fas fa-comments"></i>
                    Messages
                </h1>
                <p class="page-description">View messages from class representatives, committee members, and guild officials</p>
            </div>

            <!-- Messages Container -->
            <div class="messages-container">
                <!-- Conversations Sidebar -->
                <div class="conversations-sidebar">
                    <div class="conversations-header">
                        <h3>
                            <i class="fas fa-inbox"></i>
                            Conversations
                            <?php if ($unread_messages > 0): ?>
                                <span class="unread-badge" style="margin-left: 0.5rem;"><?php echo $unread_messages; ?> new</span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    <div class="conversations-list">
                        <?php if (empty($conversations)): ?>
                            <div class="empty-state" style="padding: 2rem;">
                                <i class="fas fa-inbox"></i>
                                <p>No conversations yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conv): ?>
                                <a href="messages.php?conversation=<?php echo $conv['conversation_id']; ?>" 
                                   class="conversation-item <?php echo (isset($_GET['conversation']) && $_GET['conversation'] == $conv['conversation_id']) ? 'active' : ''; ?>">
                                    <div class="conversation-avatar">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="conversation-info">
                                        <div class="conversation-title">
                                            <?php echo safe_display($conv['title'] ?? 'Group Conversation'); ?>
                                        </div>
                                        <div class="conversation-last-message">
                                            <?php echo safe_display(substr($conv['last_message'] ?? 'No messages', 0, 50)); ?>
                                        </div>
                                    </div>
                                    <div class="conversation-meta">
                                        <div class="conversation-time">
                                            <?php echo $conv['last_message_time'] ? timeAgo($conv['last_message_time']) : ''; ?>
                                        </div>
                                        <?php if ($conv['unread_count'] > 0): ?>
                                            <div class="unread-badge"><?php echo $conv['unread_count']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Conversation View -->
                <div class="conversation-view">
                    <?php if ($selected_conversation): ?>
                        <div class="conversation-view-header">
                            <div class="conversation-view-title">
                                <?php echo safe_display($selected_conversation['title'] ?? 'Conversation'); ?>
                            </div>
                            <div class="conversation-participants">
                                <i class="fas fa-users"></i>
                                <?php foreach ($other_participants as $index => $participant): ?>
                                    <span class="participant-badge">
                                        <i class="fas fa-user-circle"></i>
                                        <?php echo safe_display($participant['full_name']); ?>
                                        <span style="font-size: 0.6rem; color: var(--dark-gray);">
                                            (<?php echo getRoleDisplayName($participant['role']); ?>)
                                        </span>
                                    </span>
                                    <?php if ($index < count($other_participants) - 1): ?><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="messages-list" id="messagesList">
                            <?php if (empty($conversation_messages)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-comment-dots"></i>
                                    <h3>No messages yet</h3>
                                    <p>Be the first to send a message in this conversation</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($conversation_messages as $message): ?>
                                    <?php 
                                    $is_sent_by_me = ($message['sender_id'] == $student_id);
                                    $is_system = $message['is_system_notification'] ?? false;
                                    ?>
                                    
                                    <?php if ($is_system): ?>
                                        <div class="system-notification">
                                            <i class="fas fa-bell"></i>
                                            <?php echo safe_display($message['content']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="message <?php echo $is_sent_by_me ? 'sent' : 'received'; ?>">
                                            <div class="message-avatar">
                                                <?php echo strtoupper(substr($message['sender_name'], 0, 1)); ?>
                                            </div>
                                            <div class="message-content">
                                                <div class="message-header">
                                                    <span class="message-sender"><?php echo safe_display($message['sender_name']); ?></span>
                                                    <span class="message-role"><?php echo getRoleDisplayName($message['sender_role']); ?></span>
                                                    <span class="message-time"><?php echo timeAgo($message['created_at']); ?></span>
                                                </div>
                                                <div class="message-bubble">
                                                    <?php echo nl2br(safe_display($message['content'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Students can only view messages, not send replies -->
                        <div style="padding: 1rem; border-top: 1px solid var(--medium-gray); background: var(--light-gray); text-align: center;">
                            <p style="font-size: 0.8rem; color: var(--dark-gray);">
                                <i class="fas fa-info-circle"></i> 
                                This is a read-only conversation. You can view messages from <?php echo $selected_conversation['participant_count'] - 1; ?> participant(s).
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 3rem;">
                            <i class="fas fa-comments"></i>
                            <h3>Select a conversation</h3>
                            <p>Choose a conversation from the sidebar to view messages</p>
                        </div>
                    <?php endif; ?>
                </div>
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

        // Auto-scroll to bottom of messages
        const messagesList = document.getElementById('messagesList');
        if (messagesList) {
            messagesList.scrollTop = messagesList.scrollHeight;
        }

        // Refresh unread count periodically (every 30 seconds)
        setInterval(() => {
            fetch('get_unread_count.php')
                .then(response => response.json())
                .then(data => {
                    const badges = document.querySelectorAll('.notification-badge, .menu-badge');
                    if (data.unread > 0) {
                        badges.forEach(badge => {
                            badge.textContent = data.unread;
                            badge.style.display = 'inline-flex';
                        });
                    } else {
                        badges.forEach(badge => {
                            badge.style.display = 'none';
                        });
                    }
                })
                .catch(err => console.log('Error fetching unread count:', err));
        }, 30000);
    </script>
</body>
</html>