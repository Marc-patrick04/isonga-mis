<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    die('Invalid request');
}

$request_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get budget request details
    $stmt = $pdo->prepare("
        SELECT cbr.*, c.name as committee_name, u.full_name as requester_name
        FROM committee_budget_requests cbr
        LEFT JOIN committees c ON cbr.committee_id = c.id
        LEFT JOIN users u ON cbr.requested_by = u.id
        WHERE cbr.id = ? AND cbr.requested_by = ?
    ");
    $stmt->execute([$request_id, $user_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        die('Request not found or access denied');
    }
    
    if (empty($request['generated_letter_path'])) {
        // Generate the letter first
        header('Location: generate_approval_letter.php?id=' . $request_id);
        exit();
    }
    
    $filepath = '../' . $request['generated_letter_path'];
    
    if (!file_exists($filepath)) {
        // Regenerate if file doesn't exist
        header('Location: generate_approval_letter.php?id=' . $request_id);
        exit();
    }
    
    // Output PDF for download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="approval_letter_' . $request_id . '.pdf"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>