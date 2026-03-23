<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

$user_id = $_SESSION['user_id'];
$conversation_id = $_GET['conversation_id'] ?? null;
$last_message_id = $_GET['last_message_id'] ?? 0;

if (!$conversation_id) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

try {
    // Verify user is part of conversation
    $check_stmt = $pdo->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
    $check_stmt->execute([$conversation_id, $user_id]);
    
    if (!$check_stmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit();
    }
    
    // Get new messages from conversation_messages table
    $stmt = $pdo->prepare("
        SELECT cm.*, u.full_name as sender_name, u.role as sender_role 
        FROM conversation_messages cm 
        JOIN users u ON cm.sender_id = u.id 
        WHERE cm.conversation_id = ? AND cm.id > ? 
        ORDER BY cm.created_at ASC
    ");
    $stmt->execute([$conversation_id, $last_message_id]);
    $new_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($new_messages);
    
} catch (PDOException $e) {
    error_log("Get messages error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([]);
}
?>