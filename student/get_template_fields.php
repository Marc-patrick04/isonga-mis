<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? false)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get template ID from query parameter
$template_id = $_GET['template_id'] ?? null;

if (!$template_id) {
    echo json_encode(['success' => false, 'message' => 'Template ID is required']);
    exit();
}

try {
    // Fetch template from database
    $stmt = $pdo->prepare("SELECT * FROM class_rep_templates WHERE id = ? AND is_active = true");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        echo json_encode(['success' => false, 'message' => 'Template not found']);
        exit();
    }

    // Ensure fields is properly parsed
    if (is_string($template['fields'])) {
        // Validate JSON
        $decoded = json_decode($template['fields'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If invalid JSON, provide default structure
            $template['fields'] = json_encode(['sections' => []]);
        }
    }

    // Return template data
    echo json_encode([
        'success' => true,
        'template' => $template
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>