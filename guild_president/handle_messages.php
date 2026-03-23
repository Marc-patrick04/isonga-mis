<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => 'Unknown action'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'send_message':
                $conversation_id = $_POST['conversation_id'] ?? null;
                $message_content = trim($_POST['message_content'] ?? '');
                
                if (!$conversation_id || empty($message_content)) {
                    $response['message'] = 'Conversation ID and message content are required';
                    break;
                }
                
                // Verify user is part of conversation
                $check_stmt = $pdo->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
                $check_stmt->execute([$conversation_id, $user_id]);
                
                if (!$check_stmt->fetch()) {
                    $response['message'] = 'You are not part of this conversation';
                    break;
                }
                
                // Insert message into conversation_messages table
                $stmt = $pdo->prepare("
                    INSERT INTO conversation_messages (conversation_id, sender_id, content) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$conversation_id, $user_id, $message_content]);
                
                // Update conversation updated_at
                $update_stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
                $update_stmt->execute([$conversation_id]);
                
                $response = ['success' => true, 'message' => 'Message sent successfully!'];
                break;
                
            case 'create_conversation':
                $participants = $_POST['participants'] ?? [];
                $conversation_title = trim($_POST['conversation_title'] ?? 'Group Conversation');
                
                if (empty($participants)) {
                    $response['message'] = 'Please select at least one participant';
                    break;
                }
                
                // Create conversation
                $stmt = $pdo->prepare("
                    INSERT INTO conversations (title, created_by, conversation_type) 
                    VALUES (?, ?, 'group')
                ");
                $stmt->execute([$conversation_title, $user_id]);
                $new_conversation_id = $pdo->lastInsertId();
                
                // Add participants (including the creator)
                $participants[] = $user_id;
                $participants = array_unique($participants);
                
                $participant_stmt = $pdo->prepare("
                    INSERT INTO conversation_participants (conversation_id, user_id, role) 
                    VALUES (?, ?, ?)
                ");
                
                foreach ($participants as $participant_id) {
                    $role = ($participant_id == $user_id) ? 'admin' : 'member';
                    $participant_stmt->execute([$new_conversation_id, $participant_id, $role]);
                }
                
                $response = ['success' => true, 'message' => 'Conversation created successfully!', 'conversation_id' => $new_conversation_id];
                break;
                
            case 'create_announcement':
                $announcement_content = trim($_POST['announcement_content'] ?? '');
                $announcement_type = $_POST['announcement_type'] ?? 'committee';
                $announcement_title = trim($_POST['announcement_title'] ?? 'New Announcement');
                
                if (empty($announcement_content)) {
                    $response['message'] = 'Announcement content is required';
                    break;
                }
                
                // Create announcement conversation
                $stmt = $pdo->prepare("
                    INSERT INTO conversations (title, created_by, conversation_type) 
                    VALUES (?, ?, 'announcement')
                ");
                $stmt->execute([$announcement_title, $user_id]);
                $announcement_id = $pdo->lastInsertId();
                
                // Add all committee members as participants
                $members_stmt = $pdo->query("SELECT id FROM users WHERE status = 'active' AND role != 'admin'");
                $all_members = $members_stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $participant_stmt = $pdo->prepare("
                    INSERT INTO conversation_participants (conversation_id, user_id, role) 
                    VALUES (?, ?, ?)
                ");
                
                foreach ($all_members as $member_id) {
                    $role = ($member_id == $user_id) ? 'admin' : 'member';
                    $participant_stmt->execute([$announcement_id, $member_id, $role]);
                }
                
                // Create announcement message in conversation_messages
                $msg_stmt = $pdo->prepare("
                    INSERT INTO conversation_messages (conversation_id, sender_id, content) 
                    VALUES (?, ?, ?)
                ");
                $msg_stmt->execute([$announcement_id, $user_id, $announcement_content]);
                
                $response = ['success' => true, 'message' => 'Announcement created successfully!', 'conversation_id' => $announcement_id];
                break;
                
            default:
                $response['message'] = 'Invalid action';
                break;
        }
    }
} catch (PDOException $e) {
    error_log("Message handler error: " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    error_log("Message handler error: " . $e->getMessage());
    $response['message'] = 'Error: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>