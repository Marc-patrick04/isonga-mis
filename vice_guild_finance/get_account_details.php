<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['account_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'Account ID is required']);
    exit();
}

$account_id = $_GET['account_id'];

try {
    // Get account details
    $stmt = $pdo->prepare("
        SELECT fa.*, 
               (SELECT COUNT(*) FROM financial_transactions ft WHERE ft.account_id = fa.id) as transaction_count
        FROM financial_accounts fa
        WHERE fa.id = ?
    ");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['success' => false, 'message' => 'Account not found']);
        exit();
    }

    // Get recent transactions for this account
    $stmt = $pdo->prepare("
        SELECT ft.*, bc.category_name
        FROM financial_transactions ft
        LEFT JOIN budget_categories bc ON ft.category_id = bc.id
        WHERE ft.account_id = ? AND ft.status = 'completed'
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$account_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'account' => $account,
        'transactions' => $transactions
    ]);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>