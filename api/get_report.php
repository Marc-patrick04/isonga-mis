<?php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (isset($_GET['id'])) {
    $report_id = (int)$_GET['id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, rt.name as template_name
            FROM reports r 
            LEFT JOIN report_templates rt ON r.template_id = rt.id
            WHERE r.id = ? AND r.user_id = ?
        ");
        $stmt->execute([$report_id, $user_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            header('Content-Type: application/json');
            echo json_encode($report);
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No report ID provided']);
}
?>