<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User query error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar - PostgreSQL compatible
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (PDOException $e) {
        error_log("Reports query error: " . $e->getMessage());
        $pending_reports = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (PDOException $e) {
        error_log("Documents query error: " . $e->getMessage());
        $pending_docs = 0;
    }
    
    // Unread messages - PostgreSQL compatible
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Messages query error: " . $e->getMessage());
        $unread_messages = 0;
    }
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_tickets = $open_tickets = $pending_reports = $pending_docs = $unread_messages = 0;
}

// Get current conversation ID
$conversation_id = $_GET['conversation'] ?? null;
$current_conversation = null;
$conversation_messages = [];
$conversation_participants = [];

// Get all conversations for the user - PostgreSQL compatible
try {
    $conversations_stmt = $pdo->prepare("
        SELECT 
            c.*,
            (SELECT content FROM conversation_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM conversation_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT sender_id FROM conversation_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_sender_id,
            (SELECT full_name FROM users WHERE id = (SELECT sender_id FROM conversation_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1)) as last_sender_name,
            (SELECT COUNT(*) FROM conversation_messages cm WHERE cm.conversation_id = c.id AND cm.id > COALESCE(cp.last_read_message_id, 0)) as unread_count
        FROM conversations c
        JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
        ORDER BY c.updated_at DESC
    ");
    $conversations_stmt->execute([$user_id]);
    $conversations = $conversations_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Conversations error: " . $e->getMessage());
    $conversations = [];
}

// Get committee members for new conversation - PostgreSQL compatible (excluding admin and student)
try {
    $members_stmt = $pdo->query("
        SELECT id, full_name, role, department_id 
        FROM users 
        WHERE status = 'active' 
        AND role != 'admin' 
        AND role != 'student'
        ORDER BY full_name
    ");
    $committee_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Committee members query error: " . $e->getMessage());
    $committee_members = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'send_message':
                $conversation_id = $_POST['conversation_id'];
                $message_content = trim($_POST['message_content']);
                
                if (!empty($message_content) && $conversation_id) {
                    // Insert message into conversation_messages table
                    $stmt = $pdo->prepare("
                        INSERT INTO conversation_messages (conversation_id, sender_id, content) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$conversation_id, $user_id, $message_content]);
                    
                    // Update conversation updated_at - PostgreSQL uses CURRENT_TIMESTAMP
                    $update_stmt = $pdo->prepare("UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $update_stmt->execute([$conversation_id]);
                    
                    $_SESSION['success'] = "Message sent successfully!";
                    
                    // Redirect to same conversation
                    header("Location: messages.php?conversation=$conversation_id");
                    exit();
                }
                break;
                
            case 'create_conversation':
                $participants = $_POST['participants'] ?? [];
                $conversation_title = trim($_POST['conversation_title']) ?: 'Group Conversation';
                
                if (!empty($participants)) {
                    // Create conversation
                    $stmt = $pdo->prepare("
                        INSERT INTO conversations (title, created_by, conversation_type) 
                        VALUES (?, ?, 'group')
                    ");
                    $stmt->execute([$conversation_title, $user_id]);
                    $new_conversation_id = $pdo->lastInsertId();
                    
                    // Add participants (including the creator)
                    $all_participants = array_unique(array_merge($participants, [$user_id]));
                    
                    $participant_stmt = $pdo->prepare("
                        INSERT INTO conversation_participants (conversation_id, user_id, role) 
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($all_participants as $participant_id) {
                        $role = ($participant_id == $user_id) ? 'admin' : 'member';
                        $participant_stmt->execute([$new_conversation_id, $participant_id, $role]);
                    }
                    
                    $_SESSION['success'] = "Conversation created successfully!";
                    
                    // Redirect to new conversation
                    header("Location: messages.php?conversation=$new_conversation_id");
                    exit();
                }
                break;
                
            case 'create_announcement':
                $announcement_content = trim($_POST['announcement_content']);
                $announcement_title = trim($_POST['announcement_title']) ?: 'New Announcement';
                
                if (!empty($announcement_content)) {
                    // Create announcement conversation
                    $stmt = $pdo->prepare("
                        INSERT INTO conversations (title, created_by, conversation_type) 
                        VALUES (?, ?, 'announcement')
                    ");
                    $stmt->execute([$announcement_title, $user_id]);
                    $announcement_id = $pdo->lastInsertId();
                    
                    // Add all committee members as participants
                    $members_stmt = $pdo->query("
                        SELECT id FROM users WHERE status = 'active' AND role != 'admin' AND role != 'student'
                    ");
                    $all_members = $members_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $participant_stmt = $pdo->prepare("
                        INSERT INTO conversation_participants (conversation_id, user_id, role) 
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($all_members as $member_id) {
                        $role = ($member_id == $user_id) ? 'admin' : 'member';
                        $participant_stmt->execute([$announcement_id, $member_id, $role]);
                    }
                    
                    // Create announcement message
                    $msg_stmt = $pdo->prepare("
                        INSERT INTO conversation_messages (conversation_id, sender_id, content) 
                        VALUES (?, ?, ?)
                    ");
                    $msg_stmt->execute([$announcement_id, $user_id, $announcement_content]);
                    
                    $_SESSION['success'] = "Announcement created successfully!";
                    
                    // Redirect to announcement conversation
                    header("Location: messages.php?conversation=$announcement_id");
                    exit();
                }
                break;
        }
        
    } catch (PDOException $e) {
        error_log("Action error: " . $e->getMessage());
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    }
}

// Load current conversation data if selected
if ($conversation_id) {
    try {
        // Get conversation details
        $conv_stmt = $pdo->prepare("
            SELECT c.*, u.full_name as created_by_name 
            FROM conversations c 
            JOIN users u ON c.created_by = u.id 
            WHERE c.id = ?
        ");
        $conv_stmt->execute([$conversation_id]);
        $current_conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get participants
        $part_stmt = $pdo->prepare("
            SELECT cp.*, u.full_name, u.role, u.department_id 
            FROM conversation_participants cp 
            JOIN users u ON cp.user_id = u.id 
            WHERE cp.conversation_id = ?
        ");
        $part_stmt->execute([$conversation_id]);
        $conversation_participants = $part_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get messages from conversation_messages table
        $msg_stmt = $pdo->prepare("
            SELECT cm.*, u.full_name as sender_name, u.role as sender_role 
            FROM conversation_messages cm 
            JOIN users u ON cm.sender_id = u.id 
            WHERE cm.conversation_id = ?
            ORDER BY cm.created_at ASC
        ");
        $msg_stmt->execute([$conversation_id]);
        $conversation_messages = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mark messages as read for current user
        if (!empty($conversation_messages)) {
            $last_message_id = end($conversation_messages)['id'];
            $update_stmt = $pdo->prepare("
                UPDATE conversation_participants 
                SET last_read_message_id = ? 
                WHERE conversation_id = ? AND user_id = ?
            ");
            $update_stmt->execute([$last_message_id, $conversation_id, $user_id]);
        }
        
    } catch (PDOException $e) {
        error_log("Conversation load error: " . $e->getMessage());
        $_SESSION['error'] = "Error loading conversation: " . $e->getMessage();
    }
}

// Get new student registrations count (last 7 days) - PostgreSQL syntax
try {
    $new_students_stmt = $pdo->prepare("
        SELECT COUNT(*) as new_students 
        FROM users 
        WHERE role = 'student' 
        AND status = 'active' 
        AND created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $new_students_stmt->execute();
    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
} catch (PDOException $e) {
    error_log("New students query error: " . $e->getMessage());
    $new_students = 0;
}

// Display success/error messages from session
$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Messages & Announcements - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
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
            height: 100vh;
            overflow: hidden;
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            height: calc(100vh - 73px);
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
            width: 70px;
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
            height: calc(100vh - 73px);
        }

        .main-content.sidebar-collapsed {
            margin-left: 70px;
        }

        /* Messages Layout */
        .messages-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            height: 100%;
            overflow: hidden;
        }

        /* Conversations Sidebar */
        .conversations-sidebar {
            border-right: 1px solid var(--medium-gray);
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--white);
        }

        .sidebar-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
        }

        .conversation-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--medium-gray);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .conversation-item:hover, .conversation-item.active {
            background: var(--light-blue);
        }

        .conversation-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.85rem;
        }

        .conversation-preview {
            font-size: 0.75rem;
            color: var(--dark-gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-meta {
            text-align: right;
            font-size: 0.65rem;
            color: var(--dark-gray);
            flex-shrink: 0;
        }

        .unread-badge {
            background: var(--primary-blue);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        /* Chat Area */
        .chat-area {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }

        .chat-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--white);
        }

        .chat-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .chat-participants {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .messages-area {
            flex: 1;
            padding: 1.25rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            background: var(--light-gray);
        }

        .message {
            max-width: 70%;
            padding: 0.6rem 1rem;
            border-radius: 18px;
            position: relative;
            animation: messageSlide 0.2s ease;
        }

        @keyframes messageSlide {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.sent {
            align-self: flex-end;
            background: var(--primary-blue);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            align-self: flex-start;
            background: var(--white);
            color: var(--text-dark);
            border-bottom-left-radius: 4px;
            box-shadow: var(--shadow-sm);
        }

        .message.announcement {
            align-self: center;
            background: var(--warning);
            color: #856404;
            max-width: 85%;
            text-align: center;
            border-radius: var(--border-radius);
        }

        .message-content {
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
            word-wrap: break-word;
        }

        .message-time {
            font-size: 0.65rem;
            opacity: 0.7;
            text-align: right;
        }

        .message.received .message-time {
            text-align: left;
        }

        .message-sender {
            font-weight: 600;
            font-size: 0.7rem;
            margin-bottom: 0.25rem;
            opacity: 0.9;
        }

        .message-input-area {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--white);
        }

        .message-form {
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            padding: 0.7rem 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: 24px;
            resize: none;
            font-family: inherit;
            font-size: 0.85rem;
            max-height: 100px;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
        }

        .message-input:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        .send-button {
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 50%;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .send-button:hover {
            background: var(--accent-blue);
            transform: scale(1.02);
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 2% auto;
            border-radius: var(--border-radius-lg);
            width: 95%;
            max-width: 550px;
            box-shadow: var(--shadow-lg);
            position: relative;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            line-height: 1;
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--white);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-family: inherit;
            background: var(--white);
            color: var(--text-dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .participant-list {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 0.5rem;
        }

        .participant-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .participant-item:hover {
            background: var(--light-gray);
        }

        .participant-item input[type="checkbox"] {
            margin: 0;
            width: 16px;
            height: 16px;
        }

        .participant-info {
            flex: 1;
        }

        .participant-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .participant-role {
            font-size: 0.7rem;
            color: var(--dark-gray);
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

        /* Toast */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 0.75rem 1.25rem;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 500;
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            font-size: 0.8rem;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            background-color: var(--success);
        }

        .toast.error {
            background-color: var(--danger);
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
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--light-gray);
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

            .messages-container {
                grid-template-columns: 1fr;
            }

            .conversations-sidebar {
                display: none;
            }

            .conversations-sidebar.mobile-show {
                display: flex;
                position: absolute;
                top: 0;
                left: 0;
                width: 300px;
                height: 100%;
                background: var(--white);
                z-index: 10;
                box-shadow: var(--shadow-lg);
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
                max-width: 85%;
            }

            .sidebar-header {
                padding: 1rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .message {
                max-width: 90%;
            }

            .modal-content {
                width: 98%;
                margin: 1% auto;
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
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - President</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Guild President</div>
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
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
                        <?php endif; ?>
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
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
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
            <!-- Toast Messages -->
            <?php if ($success_message): ?>
                <div class="toast success show" id="toast">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
                <script>
                    setTimeout(() => { document.getElementById('toast')?.classList.remove('show'); }, 3000);
                </script>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="toast error show" id="toast">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <script>
                    setTimeout(() => { document.getElementById('toast')?.classList.remove('show'); }, 5000);
                </script>
            <?php endif; ?>

            <!-- Messages Container -->
            <div class="messages-container">
                <!-- Conversations Sidebar -->
                <div class="conversations-sidebar" id="conversationsSidebar">
                    <div class="sidebar-header">
                        <h2><i class="fas fa-comments"></i> Messages</h2>
                        <div class="action-buttons">
                            <button class="btn btn-primary" id="newConversationBtn">
                                <i class="fas fa-plus"></i> New Chat
                            </button>
                            <button class="btn btn-success" id="newAnnouncementBtn">
                                <i class="fas fa-bullhorn"></i> Announcement
                            </button>
                        </div>
                    </div>
                    
                    <div class="conversation-list">
                        <?php if (empty($conversations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-comments"></i>
                                <p>No conversations yet</p>
                                <p style="font-size: 0.7rem; margin-top: 0.5rem;">Start a new chat or make an announcement</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conv): ?>
                                <div class="conversation-item <?php echo ($conv['id'] == $conversation_id) ? 'active' : ''; ?>" 
                                     data-conversation-id="<?php echo $conv['id']; ?>">
                                    <div class="conversation-avatar">
                                        <?php 
                                        if ($conv['conversation_type'] === 'announcement') {
                                            echo '<i class="fas fa-bullhorn"></i>';
                                        } else if ($conv['conversation_type'] === 'group') {
                                            echo '<i class="fas fa-users"></i>';
                                        } else {
                                            echo strtoupper(substr($conv['title'], 0, 1));
                                        }
                                        ?>
                                    </div>
                                    <div class="conversation-info">
                                        <div class="conversation-title">
                                            <?php echo htmlspecialchars($conv['title']); ?>
                                            <?php if ($conv['conversation_type'] === 'announcement'): ?>
                                                <i class="fas fa-bullhorn" style="margin-left: 0.25rem; font-size: 0.7rem; color: var(--warning);"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="conversation-preview">
                                            <?php 
                                            if ($conv['last_sender_id'] == $user_id) {
                                                echo 'You: ';
                                            } else if ($conv['last_sender_name']) {
                                                echo htmlspecialchars($conv['last_sender_name']) . ': ';
                                            }
                                            echo htmlspecialchars(substr($conv['last_message'] ?? 'No messages yet', 0, 50));
                                            ?>
                                        </div>
                                    </div>
                                    <div class="conversation-meta">
                                        <div><?php echo $conv['last_message_time'] ? date('M j', strtotime($conv['last_message_time'])) : ''; ?></div>
                                        <?php if (($conv['unread_count'] ?? 0) > 0): ?>
                                            <div class="unread-badge"><?php echo $conv['unread_count']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chat Area -->
                <div class="chat-area">
                    <?php if ($current_conversation): ?>
                        <div class="chat-header">
                            <div class="chat-title">
                                <?php echo htmlspecialchars($current_conversation['title'] ?? 'Untitled Conversation'); ?>
                                <?php if (($current_conversation['conversation_type'] ?? '') === 'announcement'): ?>
                                    <span style="color: var(--warning); margin-left: 0.5rem; font-size: 0.7rem;">
                                        <i class="fas fa-bullhorn"></i> Announcement
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="chat-participants">
                                <i class="fas fa-users"></i> 
                                <?php 
                                $participant_names = array_map(function($p) {
                                    return htmlspecialchars($p['full_name'] ?? 'Unknown User');
                                }, $conversation_participants);
                                echo implode(', ', array_slice($participant_names, 0, 3));
                                if (count($participant_names) > 3) echo ' + ' . (count($participant_names) - 3) . ' more';
                                ?>
                            </div>
                        </div>

                        <div class="messages-area" id="messagesArea">
                            <?php foreach ($conversation_messages as $message): ?>
                                <div class="message <?php 
                                    echo $message['sender_id'] == $user_id ? 'sent' : 'received';
                                    echo ($current_conversation['conversation_type'] ?? '') === 'announcement' ? ' announcement' : '';
                                ?>">
                                    <?php if ($message['sender_id'] != $user_id && ($current_conversation['conversation_type'] ?? '') !== 'direct'): ?>
                                        <div class="message-sender">
                                            <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($message['sender_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="message-content">
                                        <?php echo nl2br(htmlspecialchars($message['content'])); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('g:i A', strtotime($message['created_at'])); ?>
                                        <?php if ($message['sender_id'] == $user_id): ?>
                                            <i class="fas fa-check-double" style="margin-left: 0.25rem;"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (($current_conversation['conversation_type'] ?? '') !== 'announcement' || ($current_conversation['created_by'] ?? 0) == $user_id): ?>
                            <div class="message-input-area">
                                <form class="message-form" method="POST">
                                    <input type="hidden" name="conversation_id" value="<?php echo $conversation_id; ?>">
                                    <input type="hidden" name="action" value="send_message">
                                    <textarea 
                                        class="message-input" 
                                        name="message_content" 
                                        placeholder="Type your message..." 
                                        rows="1"
                                        required
                                    ></textarea>
                                    <button type="submit" class="send-button">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="message-input-area" style="text-align: center; color: var(--dark-gray); padding: 0.75rem;">
                                <i class="fas fa-info-circle"></i> Announcements are read-only
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state" style="height: 100%; display: flex; flex-direction: column; justify-content: center;">
                            <i class="fas fa-comments" style="font-size: 3rem;"></i>
                            <h3>Welcome to Messages</h3>
                            <p>Select a conversation or start a new one</p>
                            <div class="action-buttons" style="justify-content: center; margin-top: 1rem;">
                                <button class="btn btn-primary" id="newConversationBtn2">
                                    <i class="fas fa-plus"></i> New Chat
                                </button>
                                <button class="btn btn-success" id="newAnnouncementBtn2">
                                    <i class="fas fa-bullhorn"></i> Announcement
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- New Conversation Modal -->
    <div id="newConversationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> New Conversation</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_conversation">
                    
                    <div class="form-group">
                        <label for="conversation_title">Conversation Title (Optional):</label>
                        <input type="text" id="conversation_title" name="conversation_title" class="form-control" 
                               placeholder="Enter group name...">
                    </div>
                    
                    <div class="form-group">
                        <label>Select Participants:</label>
                        <div class="participant-list">
                            <?php foreach ($committee_members as $member): ?>
                                <div class="participant-item">
                                    <input type="checkbox" name="participants[]" value="<?php echo $member['id']; ?>" 
                                           id="participant_<?php echo $member['id']; ?>">
                                    <div class="participant-info">
                                        <div class="participant-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                        <div class="participant-role">
                                            <?php echo str_replace('_', ' ', htmlspecialchars($member['role'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($committee_members)): ?>
                                <div class="empty-state" style="padding: 1rem;">
                                    <p>No committee members available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Create Conversation</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- New Announcement Modal -->
    <div id="newAnnouncementModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-bullhorn"></i> New Announcement</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_announcement">
                    
                    <div class="form-group">
                        <label for="announcement_title">Announcement Title:</label>
                        <input type="text" id="announcement_title" name="announcement_title" class="form-control" 
                               placeholder="Enter announcement title..." required>
                    </div>
                    
                    <div class="form-group">
                        <label for="announcement_content">Announcement Content:</label>
                        <textarea id="announcement_content" name="announcement_content" class="form-control" 
                                  placeholder="Type your announcement here..." rows="6" required></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-success">Publish Announcement</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar Toggle
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            
            const savedSidebarState = localStorage.getItem('sidebarCollapsed');
            if (savedSidebarState === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
                if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
            }
            
            function toggleSidebar() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed);
                const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
                if (sidebarToggle) sidebarToggle.innerHTML = icon;
                if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
            }
            
            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
            if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
            
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
                    mobileOverlay.classList.remove('active');
                    if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                    document.body.style.overflow = '';
                }
            });

            // Modal elements
            const conversationModal = document.getElementById('newConversationModal');
            const announcementModal = document.getElementById('newAnnouncementModal');
            const messagesArea = document.getElementById('messagesArea');
            const messageInput = document.querySelector('.message-input');

            // Open modal buttons
            const newConvBtn = document.getElementById('newConversationBtn');
            const newConvBtn2 = document.getElementById('newConversationBtn2');
            const newAnnBtn = document.getElementById('newAnnouncementBtn');
            const newAnnBtn2 = document.getElementById('newAnnouncementBtn2');
            
            if (newConvBtn) newConvBtn.addEventListener('click', () => conversationModal.style.display = 'block');
            if (newConvBtn2) newConvBtn2.addEventListener('click', () => conversationModal.style.display = 'block');
            if (newAnnBtn) newAnnBtn.addEventListener('click', () => announcementModal.style.display = 'block');
            if (newAnnBtn2) newAnnBtn2.addEventListener('click', () => announcementModal.style.display = 'block');

            // Close modals
            function closeModals() {
                if (conversationModal) conversationModal.style.display = 'none';
                if (announcementModal) announcementModal.style.display = 'none';
            }
            
            document.querySelectorAll('.close, .close-modal').forEach(btn => {
                btn.addEventListener('click', closeModals);
            });

            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeModals();
                }
            });

            // Conversation item clicks
            document.querySelectorAll('.conversation-item').forEach(item => {
                item.addEventListener('click', function() {
                    const conversationId = this.getAttribute('data-conversation-id');
                    window.location.href = `messages.php?conversation=${conversationId}`;
                });
            });

            // Auto-resize textarea
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
                });
            }

            // Auto-scroll to bottom of messages
            if (messagesArea) {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }

            // Add animation to cards
            const cards = document.querySelectorAll('.conversation-item, .stat-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.animation = `fadeInUp 0.3s ease forwards`;
                card.style.animationDelay = `${index * 0.03}s`;
            });
            
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(8px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 100);
        });
    </script>
</body>
</html>