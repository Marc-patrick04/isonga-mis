<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_representative_board') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? 0;

try {
    if ($type === 'audit') {
        $stmt = $pdo->prepare("
            SELECT 
                ca.*,
                cm.name as member_name,
                cm.role as member_role,
                ca.response_text as response,
                ca.responded_at,
                ca.audit_type,
                NULL as supporting_docs,
                'audit' as type,
                ca.status
            FROM committee_audit ca
            JOIN committee_members cm ON ca.target_member_id = cm.id
            WHERE ca.id = ? AND ca.audited_by = ?
        ");
        $stmt->execute([$id, $_SESSION['user_id']]);
    } elseif ($type === 'explanation') {
        $stmt = $pdo->prepare("
            SELECT 
                ce.*,
                cm.name as member_name,
                cm.role as member_role,
                ce.response_text as response,
                ce.responded_at,
                ce.subject,
                ce.supporting_docs,
                'explanation' as type,
                ce.status
            FROM committee_explanations ce
            JOIN committee_members cm ON ce.committee_member_id = cm.id
            WHERE ce.id = ? AND ce.requested_by = ?
        ");
        $stmt->execute([$id, $_SESSION['user_id']]);
    } else {
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }
    
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($response) {
        echo json_encode([
            'member_name' => $response['member_name'],
            'member_role' => $response['member_role'],
            'response' => $response['response'],
            'responded_at' => date('F j, Y g:i A', strtotime($response['responded_at'])),
            'type' => $response['type'],
            'status' => $response['status'],
            'subject' => $response['subject'] ?? ($response['audit_type'] ?? ''),
            'supporting_docs' => $response['supporting_docs'] ?? null
        ]);
    } else {
        echo json_encode(['error' => 'Response not found']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>