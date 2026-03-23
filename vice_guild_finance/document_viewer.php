<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['file']) || empty($_GET['file'])) {
    die('No file specified');
}

$file_path = $_GET['file'];
$full_path = '../' . $file_path;

// Security check - ensure file is in uploads directory
$allowed_paths = [
    'assets/uploads/allowance_receipts/',
    'assets/uploads/'
];

$is_allowed = false;
foreach ($allowed_paths as $allowed_path) {
    if (strpos($file_path, $allowed_path) === 0) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    die('Invalid file path');
}

if (!file_exists($full_path)) {
    die('File not found: ' . $full_path);
}

$file_extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$file_name = basename($full_path);

// Set appropriate headers
switch ($file_extension) {
    case 'pdf':
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file_name . '"');
        break;
    case 'jpg':
    case 'jpeg':
        header('Content-Type: image/jpeg');
        break;
    case 'png':
        header('Content-Type: image/png');
        break;
    case 'gif':
        header('Content-Type: image/gif');
        break;
    default:
        // For unsupported types, force download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        break;
}

header('Content-Length: ' . filesize($full_path));
readfile($full_path);
exit;
?>