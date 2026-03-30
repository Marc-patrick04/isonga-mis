<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
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
}

// Get dashboard statistics for sidebar
try {
    // Culture-related tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as culture_tickets FROM tickets WHERE category_id = 8 AND status IN ('open', 'in_progress')");
    $stmt->execute();
    $culture_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['culture_tickets'] ?? 0;
    
    // Pending cultural events
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_events 
        FROM events 
        WHERE category_id = 3 
        AND status = 'published'
        AND event_date >= CURRENT_DATE
    ");
    $stmt->execute();
    $pending_events = $stmt->fetch(PDO::FETCH_ASSOC)['pending_events'] ?? 0;
    
    // Cultural clubs count
    $stmt = $pdo->prepare("SELECT COUNT(*) as cultural_clubs FROM clubs WHERE category = 'cultural' AND status = 'active'");
    $stmt->execute();
    $cultural_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['cultural_clubs'] ?? 0;

    // Cultural tickets count
    $stmt = $pdo->prepare("SELECT COUNT(*) as cultural_tickets FROM tickets WHERE category_id = 8 AND status IN ('open', 'in_progress')");
    $stmt->execute();
    $cultural_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['cultural_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted' AND user_id = ?");
        $stmt->execute([$user_id]);
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (PDOException $e) {
        $pending_reports = 0;
    }
    
    // Unread messages
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
        $unread_messages = 0;
    }
    
    // New students count
    $new_students = 0;
    try {
        $new_students_stmt = $pdo->prepare("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= NOW() - INTERVAL '7 days'
        ");
        $new_students_stmt->execute();
        $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    } catch (PDOException $e) {
        $new_students = 0;
    }
    
    // Upcoming meetings count
    $upcoming_meetings = 0;
    try {
        $upcoming_meetings = $pdo->query("
            SELECT COUNT(*) as count FROM meetings 
            WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $upcoming_meetings = 0;
    }
    
    // Pending minutes count
    $pending_minutes = 0;
    try {
        $pending_minutes = $pdo->query("
            SELECT COUNT(*) as count FROM meeting_minutes 
            WHERE approval_status = 'submitted'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $pending_minutes = 0;
    }
    
    // Pending tickets for badge
    $pending_tickets_badge = 0;
    try {
        $ticketStmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE status IN ('open', 'in_progress') 
            AND (assigned_to = ? OR assigned_to IS NULL)
        ");
        $ticketStmt->execute([$user_id]);
        $pending_tickets_badge = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets_badge = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (PDOException $e) {
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    $culture_tickets = $pending_events = $cultural_clubs = $pending_reports = $unread_messages = 0;
    $new_students = $upcoming_meetings = $pending_minutes = $pending_tickets_badge = $pending_docs = 0;
}

// Get current conversation ID
$conversation_id = $_GET['conversation'] ?? null;
$current_conversation = null;
$conversation_messages = [];
$conversation_participants = [];

// Get all conversations for the user
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
    $conversations = [];
    error_log("Conversations error: " . $e->getMessage());
}

// Get committee members for new conversation
try {
    $members_stmt = $pdo->query("
        SELECT id, full_name, role, department_id 
        FROM users 
        WHERE status = 'active' AND role != 'admin' AND role != 'student'
        ORDER BY full_name
    ");
    $committee_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
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
                    $stmt = $pdo->prepare("
                        INSERT INTO conversation_messages (conversation_id, sender_id, content) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$conversation_id, $user_id, $message_content]);
                    
                    $update_stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
                    $update_stmt->execute([$conversation_id]);
                    
                    $_SESSION['success'] = "Message sent successfully!";
                    header("Location: messages.php?conversation=$conversation_id");
                    exit();
                }
                break;
                
            case 'create_conversation':
                $participants = $_POST['participants'] ?? [];
                $conversation_title = trim($_POST['conversation_title']) ?: 'Group Conversation';
                
                if (!empty($participants)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO conversations (title, created_by, conversation_type) 
                        VALUES (?, ?, 'group')
                    ");
                    $stmt->execute([$conversation_title, $user_id]);
                    $new_conversation_id = $pdo->lastInsertId();
                    
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
                    header("Location: messages.php?conversation=$new_conversation_id");
                    exit();
                }
                break;
        }
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    }
}

// Load current conversation data if selected
if ($conversation_id) {
    try {
        $conv_stmt = $pdo->prepare("
            SELECT c.*, u.full_name as created_by_name 
            FROM conversations c 
            JOIN users u ON c.created_by = u.id 
            WHERE c.id = ?
        ");
        $conv_stmt->execute([$conversation_id]);
        $current_conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
        
        $part_stmt = $pdo->prepare("
            SELECT cp.*, u.full_name, u.role, u.department_id 
            FROM conversation_participants cp 
            JOIN users u ON cp.user_id = u.id 
            WHERE cp.conversation_id = ?
        ");
        $part_stmt->execute([$conversation_id]);
        $conversation_participants = $part_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $msg_stmt = $pdo->prepare("
            SELECT cm.*, u.full_name as sender_name, u.role as sender_role 
            FROM conversation_messages cm 
            JOIN users u ON cm.sender_id = u.id 
            WHERE cm.conversation_id = ?
            ORDER BY cm.created_at ASC
        ");
        $msg_stmt->execute([$conversation_id]);
        $conversation_messages = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);
        
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

// Display success/error messages from session
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Messages - Minister of Culture - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #A78BFA;
            --accent-purple: #7C3AED;
            --light-purple: #f3f0ff;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            --primary-purple: #A78BFA;
            --secondary-purple: #C4B5FD;
            --accent-purple: #8B5CF6;
            --light-purple: #1f1a2e;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #4dd0e1;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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

        /* Messages Container */
        .messages-container {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 1.5rem;
            height: calc(100vh - 80px - 3rem);
            min-height: 500px;
        }

        /* Conversations Sidebar */
        .conversations-sidebar {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-purple);
        }

        .sidebar-header h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-purple);
            color: var(--primary-purple);
        }

        /* Conversation List */
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

        .conversation-item:hover {
            background: var(--light-purple);
        }

        .conversation-item.active {
            background: var(--light-purple);
            border-left: 3px solid var(--primary-purple);
        }

        .conversation-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
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
            font-size: 0.7rem;
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
            background: var(--primary-purple);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        /* Chat Area */
        .chat-area {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-purple);
        }

        .chat-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .chat-participants {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .messages-area {
            flex: 1;
            padding: 1rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .message {
            max-width: 75%;
            padding: 0.6rem 0.9rem;
            border-radius: 18px;
            position: relative;
            animation: messageSlide 0.3s ease;
        }

        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.sent {
            align-self: flex-end;
            background: var(--primary-purple);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            align-self: flex-start;
            background: var(--light-gray);
            color: var(--text-dark);
            border-bottom-left-radius: 4px;
        }

        .message-content {
            margin-bottom: 0.25rem;
            word-wrap: break-word;
            font-size: 0.8rem;
        }

        .message-time {
            font-size: 0.6rem;
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
            padding: 0.6rem 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: 24px;
            resize: none;
            font-family: inherit;
            font-size: 0.8rem;
            max-height: 100px;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
        }

        .message-input:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.1);
        }

        .send-button {
            background: var(--primary-purple);
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
            background: var(--accent-purple);
            transform: scale(1.05);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-purple);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
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
            padding: 0.5rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .participant-item:hover {
            background: var(--light-gray);
        }

        .participant-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .participant-info {
            flex: 1;
        }

        .participant-name {
            font-weight: 600;
            font-size: 0.8rem;
        }

        .participant-role {
            font-size: 0.65rem;
            color: var(--dark-gray);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 90px;
            right: 20px;
            padding: 0.75rem 1.25rem;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 600;
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
                background: var(--primary-purple);
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

            #sidebarToggleBtn {
                display: none;
            }

            .messages-container {
                grid-template-columns: 280px 1fr;
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

            .messages-container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .conversations-sidebar {
                max-height: 300px;
            }

            .message {
                max-width: 85%;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
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

            .message {
                max-width: 90%;
            }

            .conversation-avatar {
                width: 36px;
                height: 36px;
                font-size: 0.8rem;
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Minister of Culture</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
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
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Culture & Civic Education</div>
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
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                        <?php if ($pending_tickets_badge > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets_badge; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php">
                        <i class="fas fa-palette"></i>
                        <span>Cultural Resources</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="troupe.php">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
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
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="toast success show" id="successToast">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
                <script>
                    setTimeout(() => {
                        document.getElementById('successToast')?.classList.remove('show');
                    }, 3000);
                </script>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="toast error show" id="errorToast">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <script>
                    setTimeout(() => {
                        document.getElementById('errorToast')?.classList.remove('show');
                    }, 5000);
                </script>
            <?php endif; ?>

            <!-- Messages Container -->
            <div class="messages-container">
                <!-- Conversations Sidebar -->
                <div class="conversations-sidebar">
                    <div class="sidebar-header">
                        <h2><i class="fas fa-comments"></i> Cultural Communications</h2>
                        <div class="action-buttons">
                            <button class="btn btn-primary" id="newConversationBtn">
                                <i class="fas fa-plus"></i> New Chat
                            </button>
                        </div>
                    </div>
                    
                    <div class="conversation-list">
                        <?php if (empty($conversations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-comments"></i>
                                <h3>No conversations yet</h3>
                                <p>Start a new chat with committee members</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conv): ?>
                                <div class="conversation-item <?php echo ($conv['id'] == $conversation_id) ? 'active' : ''; ?>" 
                                     data-conversation-id="<?php echo $conv['id']; ?>"
                                     onclick="location.href='messages.php?conversation=<?php echo $conv['id']; ?>'">
                                    <div class="conversation-avatar">
                                        <?php 
                                        if (($conv['conversation_type'] ?? '') === 'announcement') {
                                            echo '<i class="fas fa-bullhorn"></i>';
                                        } else if (($conv['conversation_type'] ?? '') === 'group') {
                                            echo '<i class="fas fa-users"></i>';
                                        } else {
                                            echo strtoupper(substr($conv['title'], 0, 1));
                                        }
                                        ?>
                                    </div>
                                    <div class="conversation-info">
                                        <div class="conversation-title">
                                            <?php echo htmlspecialchars($conv['title']); ?>
                                            <?php if (($conv['conversation_type'] ?? '') === 'announcement'): ?>
                                                <i class="fas fa-bullhorn" style="margin-left: 0.25rem; color: var(--warning); font-size: 0.7rem;"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="conversation-preview">
                                            <?php 
                                            if (($conv['last_sender_id'] ?? 0) == $user_id) {
                                                echo 'You: ';
                                            } else if (!empty($conv['last_sender_name'])) {
                                                echo htmlspecialchars($conv['last_sender_name']) . ': ';
                                            }
                                            echo htmlspecialchars($conv['last_message'] ?? 'No messages yet');
                                            ?>
                                        </div>
                                    </div>
                                    <div class="conversation-meta">
                                        <?php if ($conv['last_message_time']): ?>
                                            <div><?php echo date('M j', strtotime($conv['last_message_time'])); ?></div>
                                        <?php endif; ?>
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
                                <?php 
                                $participant_names = array_map(function($p) {
                                    return $p['full_name'] ?? 'Unknown User';
                                }, $conversation_participants);
                                echo implode(', ', $participant_names);
                                ?>
                            </div>
                        </div>

                        <div class="messages-area" id="messagesArea">
                            <?php if (empty($conversation_messages)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-comment-dots"></i>
                                    <p>No messages yet. Start the conversation!</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($conversation_messages as $message): ?>
                                    <div class="message <?php echo $message['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                        <?php if ($message['sender_id'] != $user_id && ($current_conversation['conversation_type'] ?? '') !== 'direct'): ?>
                                            <div class="message-sender">
                                                <?php echo htmlspecialchars($message['sender_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="message-content">
                                            <?php echo nl2br(htmlspecialchars($message['content'])); ?>
                                        </div>
                                        <div class="message-time">
                                            <?php echo date('g:i A', strtotime($message['created_at'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php if (($current_conversation['conversation_type'] ?? '') !== 'announcement'): ?>
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
                            <div class="message-input-area" style="text-align: center; padding: 1rem;">
                                <i class="fas fa-info-circle"></i> Announcements are read-only
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state" style="display: flex; flex-direction: column; justify-content: center; min-height: 400px;">
                            <i class="fas fa-comments" style="font-size: 4rem;"></i>
                            <h3>Cultural Communications</h3>
                            <p>Select a conversation or start a new one</p>
                            <div style="margin-top: 1rem;">
                                <button class="btn btn-primary" id="newConversationBtn2">
                                    <i class="fas fa-plus"></i> New Chat
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
                <h3><i class="fas fa-plus"></i> New Cultural Conversation</h3>
                <button class="close-modal" onclick="closeModal('newConversationModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_conversation">
                    
                    <div class="form-group">
                        <label class="form-label">Conversation Title (Optional)</label>
                        <input type="text" name="conversation_title" class="form-control" 
                               placeholder="Enter group name...">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Select Participants</label>
                        <div class="participant-list">
                            <?php if (empty($committee_members)): ?>
                                <div class="empty-state" style="padding: 1rem;">
                                    <p>No other committee members found</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($committee_members as $member): ?>
                                    <div class="participant-item">
                                        <input type="checkbox" name="participants[]" value="<?php echo $member['id']; ?>" 
                                               id="participant_<?php echo $member['id']; ?>">
                                        <label for="participant_<?php echo $member['id']; ?>" style="flex: 1; cursor: pointer;">
                                            <div class="participant-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                            <div class="participant-role"><?php echo str_replace('_', ' ', $member['role']); ?></div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">Create Conversation</button>
                        <button type="button" class="btn btn-outline" onclick="closeModal('newConversationModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
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
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars</i>';
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
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Button click handlers
        document.getElementById('newConversationBtn')?.addEventListener('click', () => openModal('newConversationModal'));
        document.getElementById('newConversationBtn2')?.addEventListener('click', () => openModal('newConversationModal'));

        // Auto-resize textarea
        const messageInput = document.querySelector('.message-input');
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });
        }

        // Auto-scroll to bottom of messages
        const messagesArea = document.getElementById('messagesArea');
        if (messagesArea) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('newConversationModal');
            if (event.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Auto-refresh messages every 30 seconds if in a conversation
        <?php if ($conversation_id && !empty($conversation_messages)): ?>
        let refreshInterval = setInterval(() => {
            window.location.reload();
        }, 30000);
        
        window.addEventListener('beforeunload', () => {
            clearInterval(refreshInterval);
        });
        <?php endif; ?>
    </script>
</body>
</html>