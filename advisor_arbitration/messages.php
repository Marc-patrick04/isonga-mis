<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Advisor Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'advisor_arbitration') {
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
    // Cases assigned to advisor
    $stmt = $pdo->prepare("SELECT COUNT(*) as my_cases FROM arbitration_cases WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $my_cases = $stmt->fetch(PDO::FETCH_ASSOC)['my_cases'] ?? 0;
    
    // Pending cases for assigned cases
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_cases FROM arbitration_cases WHERE assigned_to = ? AND status IN ('filed', 'under_review')");
    $stmt->execute([$user_id]);
    $pending_cases = $stmt->fetch(PDO::FETCH_ASSOC)['pending_cases'] ?? 0;
    
    // Upcoming hearings for assigned cases
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_hearings 
        FROM arbitration_hearings ah 
        JOIN arbitration_cases ac ON ah.case_id = ac.id 
        WHERE ah.hearing_date >= CURRENT_DATE 
        AND ah.status = 'scheduled'
        AND ac.assigned_to = ?
    ");
    $stmt->execute([$user_id]);
    $upcoming_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_hearings'] ?? 0;
    
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
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    } catch (PDOException $e) {
        $unread_messages = 0;
    }
    
} catch (PDOException $e) {
    $my_cases = $pending_cases = $upcoming_hearings = $unread_messages = 0;
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

// Get ALL committee members for new conversation - CHANGED THIS PART
try {
    $members_stmt = $pdo->query("
        SELECT id, full_name, role, department_id 
        FROM users 
        WHERE status = 'active' 
        AND role != 'student'  -- Exclude regular students
        AND role != 'admin'    -- Exclude admins if needed
        ORDER BY 
            CASE 
                WHEN role LIKE '%president%' THEN 1
                WHEN role LIKE '%vice_president%' THEN 2
                WHEN role LIKE '%minister%' THEN 3
                WHEN role LIKE '%secretary%' THEN 4
                ELSE 5
            END,
            full_name
    ");
    $committee_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
    error_log("Committee members error: " . $e->getMessage());
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
                    
                    // Update conversation updated_at
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
                $conversation_title = trim($_POST['conversation_title']) ?: 'Committee Discussion';
                $conversation_type = $_POST['conversation_type'] ?? 'group';
                
                if (!empty($participants) || $conversation_type === 'announcement') {
                    // Create conversation
                    $stmt = $pdo->prepare("
                        INSERT INTO conversations (title, created_by, conversation_type) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$conversation_title, $user_id, $conversation_type]);
                    $new_conversation_id = $pdo->lastInsertId();
                    
                    // Add participants (including the creator)
                    $all_participants = array_unique(array_merge($participants, [$user_id]));
                    
                    $participant_stmt = $pdo->prepare("
                        INSERT INTO conversation_participants (conversation_id, user_id, role) 
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($all_participants as $participant_id) {
                        $role = ($participant_id == $user_id) ? 'member' : 'member';
                        $participant_stmt->execute([$new_conversation_id, $participant_id, $role]);
                    }
                    
                    $_SESSION['success'] = "Conversation created successfully!";
                    
                    // Redirect to new conversation
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Arbitration Advisor - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* All CSS styles remain exactly the same as in your code */
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
            --sidebar-collapsed-width: 70px;
        }

        .dark-mode {
            --primary-blue: #1e88e5;
            --secondary-blue: #64b5f6;
            --accent-blue: #1565c0;
            --light-blue: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
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
            width: 100%;
            gap: 0.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
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
            transform: translateY(-1px);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
            text-align: center;
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
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            height: calc(100vh - 180px);
            min-height: 500px;
        }

        /* Conversations Sidebar */
        .conversations-sidebar {
            border-right: 1px solid var(--medium-gray);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .sidebar-header h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--text-dark);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
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
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .conversation-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--medium-gray);
            cursor: pointer;
            transition: var(--transition);
            gap: 0.75rem;
        }

        .conversation-item:hover {
            background: var(--light-gray);
        }

        .conversation-item.active {
            background: var(--light-blue);
            border-left: 3px solid var(--primary-blue);
        }

        .conversation-avatar {
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

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-title {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
            flex-shrink: 0;
        }

        .unread-badge {
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            font-weight: 600;
        }

        /* Chat Area */
        .chat-area {
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--white);
        }

        .chat-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .chat-participants {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .messages-area {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .message {
            max-width: 75%;
            padding: 0.6rem 0.875rem;
            border-radius: var(--border-radius);
            position: relative;
        }

        .message.sent {
            align-self: flex-end;
            background: var(--primary-blue);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            align-self: flex-start;
            background: var(--light-gray);
            color: var(--text-dark);
            border-bottom-left-radius: 4px;
        }

        .message.announcement {
            align-self: center;
            max-width: 90%;
            background: var(--warning);
            color: var(--text-dark);
            text-align: center;
        }

        .message-sender {
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            opacity: 0.8;
        }

        .message-content {
            margin-bottom: 0.5rem;
            line-height: 1.4;
            font-size: 0.85rem;
        }

        .message-time {
            font-size: 0.65rem;
            opacity: 0.7;
            text-align: right;
        }

        .message-input-area {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--white);
        }

        .message-form {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            resize: none;
            min-height: 40px;
            max-height: 100px;
            font-family: inherit;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        .send-button {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--primary-blue);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .send-button:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
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
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .close {
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        .close:hover {
            color: var(--text-dark);
        }

        .modal-body {
            padding: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.875rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        .participant-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
        }

        .participant-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .participant-item:last-child {
            border-bottom: none;
        }

        .participant-item:hover {
            background: var(--light-gray);
        }

        .participant-item input[type="checkbox"] {
            margin-right: 0.75rem;
        }

        .participant-info {
            flex: 1;
        }

        .participant-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .participant-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
            text-transform: capitalize;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* Toast Messages */
        .toast {
            position: fixed;
            top: 90px;
            right: 1rem;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 500;
            z-index: 1050;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 350px;
            font-size: 0.8rem;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem 1.5rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .empty-state p {
            margin-bottom: 1rem;
        }

        /* Overlay for mobile */
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

            .messages-container {
                grid-template-columns: 1fr;
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
                height: calc(100vh - 140px);
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

            .messages-container {
                height: calc(100vh - 150px);
            }

            .message {
                max-width: 85%;
            }

            .conversation-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.7rem;
            }

            .conversation-title {
                font-size: 0.8rem;
            }

            .conversation-preview {
                font-size: 0.7rem;
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
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
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
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration Advisor</div>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php" >
                        <i class="fas fa-balance-scale"></i>
                        <span>My Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
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
        <main class="main-content">
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="toast success show" id="toast">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
                <script>
                    setTimeout(() => {
                        document.getElementById('toast')?.classList.remove('show');
                    }, 3000);
                </script>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="toast error show" id="toast">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <script>
                    setTimeout(() => {
                        document.getElementById('toast')?.classList.remove('show');
                    }, 5000);
                </script>
            <?php endif; ?>

            <!-- Messages Container -->
            <div class="messages-container">
                <!-- Conversations Sidebar -->
                <div class="conversations-sidebar">
                    <div class="sidebar-header">
                        <h2>Arbitration Communications</h2>
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
                                <p>No conversations yet</p>
                                <p style="font-size: 0.8rem; margin-top: 0.5rem;">Start a new chat with committee members</p>
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
                                                <i class="fas fa-bullhorn" style="margin-left: 0.25rem; color: var(--warning);"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="conversation-preview">
                                            <?php 
                                            if ($conv['last_sender_id'] == $user_id) {
                                                echo 'You: ';
                                            } else if ($conv['last_sender_name']) {
                                                echo htmlspecialchars($conv['last_sender_name']) . ': ';
                                            }
                                            echo htmlspecialchars($conv['last_message'] ?? 'No messages yet');
                                            ?>
                                        </div>
                                    </div>
                                    <div class="conversation-meta">
                                        <div><?php echo $conv['last_message_time'] ? date('M j', strtotime($conv['last_message_time'])) : ''; ?></div>
                                        <?php if ($conv['unread_count'] > 0): ?>
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
                            <div>
                                <div class="chat-title">
                                    <?php echo htmlspecialchars($current_conversation['title'] ?? 'Untitled Conversation'); ?>
                                    <?php if (($current_conversation['conversation_type'] ?? '') === 'announcement'): ?>
                                        <span style="color: var(--warning); margin-left: 0.5rem;">
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
                        </div>

                        <div class="messages-area" id="messagesArea">
                            <?php foreach ($conversation_messages as $message): ?>
                                <div class="message <?php 
                                    echo $message['sender_id'] == $user_id ? 'sent' : 'received';
                                    echo ($current_conversation['conversation_type'] ?? '') === 'announcement' ? ' announcement' : '';
                                ?>">
                                    <?php if ($message['sender_id'] != $user_id && ($current_conversation['conversation_type'] ?? '') !== 'direct'): ?>
                                        <div class="message-sender">
                                            <?php echo htmlspecialchars($message['sender_name']); ?>
                                            <?php if (($current_conversation['conversation_type'] ?? '') === 'announcement'): ?>
                                                <span style="color: var(--warning); margin-left: 0.5rem;">
                                                    <i class="fas fa-bullhorn"></i> Announcement
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="message-content">
                                        <?php echo nl2br(htmlspecialchars($message['content'])); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('g:i A', strtotime($message['created_at'])); ?>
                                        <?php if ($message['sender_id'] == $user_id): ?>
                                            <i class="fas fa-check-double" style="margin-left: 0.25rem; opacity: 0.7;"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
                            <div class="message-input-area" style="text-align: center; color: var(--dark-gray);">
                                <i class="fas fa-info-circle"></i> Announcements are read-only
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state" style="height: 100%; display: flex; flex-direction: column; justify-content: center;">
                            <i class="fas fa-comments" style="font-size: 4rem;"></i>
                            <h3>Arbitration Communications</h3>
                            <p>Select a conversation or start a new one</p>
                            <div class="action-buttons" style="justify-content: center; margin-top: 1rem;">
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
                <h3>New Conversation</h3>
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
                        <label>Select Committee Members:</label>
                        <div class="participant-list">
                            <?php if (empty($committee_members)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-users-slash" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                    <p>No committee members available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($committee_members as $member): ?>
                                    <?php if ($member['id'] != $user_id): ?>
                                        <div class="participant-item">
                                            <input type="checkbox" name="participants[]" value="<?php echo $member['id']; ?>" 
                                                   id="participant_<?php echo $member['id']; ?>">
                                            <div class="participant-info">
                                                <div class="participant-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                <div class="participant-role">
                                                    <?php 
                                                    $role_display = str_replace('_', ' ', $member['role']);
                                                    echo $role_display;
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const conversationModal = document.getElementById('newConversationModal');
            const messagesArea = document.getElementById('messagesArea');
            const messageInput = document.querySelector('.message-input');

            // Open modal buttons
            document.getElementById('newConversationBtn')?.addEventListener('click', () => conversationModal.style.display = 'block');
            document.getElementById('newConversationBtn2')?.addEventListener('click', () => conversationModal.style.display = 'block');

            // Close modals
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
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }

            // Auto-scroll to bottom of messages
            if (messagesArea) {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }

            // Dark mode toggle
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;

            if (themeToggle) {
                const savedTheme = localStorage.getItem('theme') || 'light';
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
            }

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
                        : '<i class="fas fa-bars"></i>';
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

            // Functions
            function closeModals() {
                conversationModal.style.display = 'none';
            }
        });
    </script>
</body>
</html>