<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Access denied']);
    exit();
}

$template_id = $_GET['id'] ?? null;

if (!$template_id) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Template ID is required']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT dt.*, dc.name as category_name, dc.icon, dc.color
        FROM document_templates dt
        LEFT JOIN document_categories dc ON dt.category_id = dc.id
        WHERE dt.id = ?
    ");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Template not found']);
        exit();
    }

    // Decode JSON fields safely
    if ($template['fields']) {
        $decoded_fields = json_decode($template['fields'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $template['fields'] = $decoded_fields;
        } else {
            // Handle invalid JSON - create empty fields array
            $template['fields'] = [];
            error_log("Invalid JSON in template fields for template ID: " . $template_id);
        }
    } else {
        $template['fields'] = [];
    }

    if ($template['required_roles']) {
        $decoded_roles = json_decode($template['required_roles'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $template['required_roles'] = $decoded_roles;
        } else {
            $template['required_roles'] = [];
            error_log("Invalid JSON in required_roles for template ID: " . $template_id);
        }
    } else {
        $template['required_roles'] = [];
    }

    header('Content-Type: application/json');
    echo json_encode($template);

} catch (PDOException $e) {
    error_log("Template details error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>