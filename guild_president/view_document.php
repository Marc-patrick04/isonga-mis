<?php
session_start();
require_once '../config/database.php';
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$document_id = $_GET['id'] ?? 0;
$action = $_GET['action'] ?? 'view';

try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.full_name as generated_by_name, dt.name as template_name
        FROM documents d
        LEFT JOIN users u ON d.generated_by = u.id
        LEFT JOIN document_templates dt ON d.template_id = dt.id
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        throw new Exception("Document not found");
    }
    
    if ($action === 'download') {
        // Force download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($document['generated_file']) . '"');
        readfile($document['generated_file']);
    } else {
        // View in browser
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($document['generated_file']) . '"');
        readfile($document['generated_file']);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>