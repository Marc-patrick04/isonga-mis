<?php
require_once '../config/database.php';

if (isset($_GET['template_id'])) {
    $template_id = (int)$_GET['template_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT fields FROM report_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'fields' => json_decode($template['fields'], true)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Template not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No template ID provided']);
}
?>