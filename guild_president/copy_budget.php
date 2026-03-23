<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$from_year = $input['from_year'] ?? '';
$to_year = $input['to_year'] ?? '';

header('Content-Type: application/json');

try {
    // Check if target year already has budget data
    $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM budget_allocations WHERE academic_year = ?");
    $checkStmt->execute([$to_year]);
    $existing_count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($existing_count > 0) {
        echo json_encode(['success' => false, 'message' => 'Budget already exists for target academic year']);
        exit();
    }
    
    // Get source budget data
    $sourceStmt = $pdo->prepare("SELECT * FROM budget_allocations WHERE academic_year = ?");
    $sourceStmt->execute([$from_year]);
    $source_budgets = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($source_budgets)) {
        echo json_encode(['success' => false, 'message' => 'No budget data found for source academic year']);
        exit();
    }
    
    // Copy budget data
    $copyStmt = $pdo->prepare("
        INSERT INTO budget_allocations (academic_year, category_name, allocated_amount, remaining_amount, created_by) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($source_budgets as $budget) {
        $copyStmt->execute([
            $to_year, 
            $budget['category_name'], 
            $budget['allocated_amount'], 
            $budget['allocated_amount'], // Reset remaining amount to full allocation
            $_SESSION['user_id']
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Budget copied successfully']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>