<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

try {
    // Get last check time from session
    $last_check = $_SESSION['last_notification_check'] ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
    
    // Check for new notifications since last check
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as new_count
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ?
        AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        AND cm.is_system_notification = 1
        AND cm.notification_type IN ('audit_request', 'explanation_request')
        AND (cm.expires_at IS NULL OR cm.expires_at > NOW())
        AND cm.created_at > ?
    ");
    $stmt->execute([$_SESSION['user_id'], $last_check]);
    $new_count = $stmt->fetchColumn();
    
    // Get total unread count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_count
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ?
        AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        AND cm.is_system_notification = 1
        AND cm.notification_type IN ('audit_request', 'explanation_request')
        AND (cm.expires_at IS NULL OR cm.expires_at > NOW())
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $total_count = $stmt->fetchColumn();
    
    // Update last check time
    $_SESSION['last_notification_check'] = date('Y-m-d H:i:s');
    
    echo json_encode([
        'new_count' => $new_count,
        'total_count' => $total_count,
        'last_check' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
?>