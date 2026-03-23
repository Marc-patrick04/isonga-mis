<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Get filter parameters
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_category = $_GET['category'] ?? '';
$filter_month = $_GET['month'] ?? '';
$search = $_GET['search'] ?? '';

// Add new transaction
if ($action === 'add_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_type = $_POST['transaction_type'];
    $category_id = $_POST['category_id'];
    $amount = $_POST['amount'];
    $description = trim($_POST['description']);
    $transaction_date = $_POST['transaction_date'];
    $reference_number = trim($_POST['reference_number']);
    $payee_payer = trim($_POST['payee_payer']);
    $payment_method = $_POST['payment_method'];
    $supporting_docs = ''; // Handle file upload in real implementation
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO financial_transactions 
            (transaction_type, category_id, amount, description, transaction_date, reference_number, payee_payer, payment_method, supporting_docs_path, requested_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ");
        $stmt->execute([$transaction_type, $category_id, $amount, $description, $transaction_date, $reference_number, $payee_payer, $payment_method, $supporting_docs, $user_id]);
        
        $message = "Transaction added successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error adding transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Edit transaction
if ($action === 'edit_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = $_POST['transaction_id'];
    $category_id = $_POST['category_id'];
    $amount = $_POST['amount'];
    $description = trim($_POST['description']);
    $transaction_date = $_POST['transaction_date'];
    $reference_number = trim($_POST['reference_number']);
    $payee_payer = trim($_POST['payee_payer']);
    $payment_method = $_POST['payment_method'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE financial_transactions 
            SET category_id = ?, amount = ?, description = ?, transaction_date = ?, reference_number = ?, payee_payer = ?, payment_method = ?
            WHERE id = ?
        ");
        $stmt->execute([$category_id, $amount, $description, $transaction_date, $reference_number, $payee_payer, $payment_method, $transaction_id]);
        
        $message = "Transaction updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error updating transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Submit for approval
if ($action === 'submit_approval' && isset($_GET['id'])) {
    $transaction_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE financial_transactions SET status = 'pending_approval' WHERE id = ?");
        $stmt->execute([$transaction_id]);
        
        $message = "Transaction submitted for approval!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error submitting transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Approve transaction (Finance level)
if ($action === 'approve_finance' && isset($_GET['id'])) {
    $transaction_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE financial_transactions SET status = 'approved_by_finance', approved_by_finance = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$user_id, $transaction_id]);
        
        $message = "Transaction approved! Waiting for president approval.";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error approving transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Reject transaction
if ($action === 'reject_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = $_POST['transaction_id'];
    $rejection_reason = trim($_POST['rejection_reason']);
    
    try {
        $stmt = $pdo->prepare("UPDATE financial_transactions SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        $stmt->execute([$rejection_reason, $transaction_id]);
        
        $message = "Transaction rejected!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error rejecting transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Delete transaction
if ($action === 'delete_transaction' && isset($_GET['id'])) {
    $transaction_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM financial_transactions WHERE id = ? AND status = 'draft'");
        $stmt->execute([$transaction_id]);
        
        if ($stmt->rowCount() > 0) {
            $message = "Transaction deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Cannot delete transaction - only draft transactions can be deleted.";
            $message_type = "error";
        }
    } catch (PDOException $e) {
        $message = "Error deleting transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Complete transaction (mark as paid)
if ($action === 'complete_transaction' && isset($_GET['id'])) {
    $transaction_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE financial_transactions SET status = 'completed' WHERE id = ? AND status = 'approved_by_president'");
        $stmt->execute([$transaction_id]);
        
        if ($stmt->rowCount() > 0) {
            $message = "Transaction marked as completed!";
            $message_type = "success";
        } else {
            $message = "Transaction must be approved by president first.";
            $message_type = "error";
        }
    } catch (PDOException $e) {
        $message = "Error completing transaction: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all budget categories
try {
    $stmt = $pdo->query("SELECT * FROM budget_categories WHERE is_active = 1 ORDER BY category_type, category_name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    error_log("Categories error: " . $e->getMessage());
}

// Build query for transactions with filters
$query = "
    SELECT 
        ft.*,
        bc.category_name,
        bc.category_type,
        u_req.full_name as requested_by_name,
        u_finance.full_name as approved_by_finance_name,
        u_president.full_name as approved_by_president_name
    FROM financial_transactions ft
    LEFT JOIN budget_categories bc ON ft.category_id = bc.id
    LEFT JOIN users u_req ON ft.requested_by = u_req.id
    LEFT JOIN users u_finance ON ft.approved_by_finance = u_finance.id
    LEFT JOIN users u_president ON ft.approved_by_president = u_president.id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($filter_type) {
    $query .= " AND ft.transaction_type = ?";
    $params[] = $filter_type;
}

if ($filter_status) {
    $query .= " AND ft.status = ?";
    $params[] = $filter_status;
}

if ($filter_category) {
    $query .= " AND ft.category_id = ?";
    $params[] = $filter_category;
}

if ($filter_month) {
    $query .= " AND DATE_FORMAT(ft.transaction_date, '%Y-%m') = ?";
    $params[] = $filter_month;
}

if ($search) {
    $query .= " AND (ft.description LIKE ? OR ft.reference_number LIKE ? OR ft.payee_payer LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY ft.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    error_log("Transactions error: " . $e->getMessage());
}

// Get transaction statistics
try {
    // Total transactions count
    $stmt = $pdo->query("SELECT COUNT(*) as total_count FROM financial_transactions");
    $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_count'] ?? 0;

    // Pending approvals count
    $stmt = $pdo->query("SELECT COUNT(*) as pending_count FROM financial_transactions WHERE status = 'approved_by_finance'");
    $pending_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['pending_count'] ?? 0;

    // Total income this month
    $stmt = $pdo->query("
        SELECT SUM(amount) as monthly_income 
        FROM financial_transactions 
        WHERE transaction_type = 'income' 
        AND status = 'completed'
        AND MONTH(transaction_date) = MONTH(CURDATE())
        AND YEAR(transaction_date) = YEAR(CURDATE())
    ");
    $monthly_income = $stmt->fetch(PDO::FETCH_ASSOC)['monthly_income'] ?? 0;

    // Total expenses this month
    $stmt = $pdo->query("
        SELECT SUM(amount) as monthly_expenses 
        FROM financial_transactions 
        WHERE transaction_type = 'expense' 
        AND status = 'completed'
        AND MONTH(transaction_date) = MONTH(CURDATE())
        AND YEAR(transaction_date) = YEAR(CURDATE())
    ");
    $monthly_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['monthly_expenses'] ?? 0;

    // Recent transactions for chart
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month,
            SUM(CASE WHEN transaction_type = 'income' AND status = 'completed' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN transaction_type = 'expense' AND status = 'completed' THEN amount ELSE 0 END) as expenses
        FROM financial_transactions 
        WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_count = $pending_approvals = $monthly_income = $monthly_expenses = 0;
    $monthly_trends = [];
    error_log("Transaction stats error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse all the CSS from dashboard.php */
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --finance-primary: #1976D2;
            --finance-secondary: #2196F3;
            --finance-accent: #0D47A1;
            --finance-light: #E3F2FD;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-blue: #1e88e5;
            --secondary-blue: #64b5f6;
            --accent-blue: #1565c0;
            --light-blue: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --finance-primary: #2196F3;
            --finance-secondary: #64B5F6;
            --finance-accent: #1976D2;
            --finance-light: #0D1B2A;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
            height: 80px;
            display: flex;
            align-items: center;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--finance-primary);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--finance-primary);
            transform: scale(1.05);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1.1rem;
        }

        .icon-btn:hover {
            background: var(--finance-primary);
            color: white;
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        /* Sidebar */
        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 60px;
            height: calc(100vh - 60px);
            overflow-y: auto;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item {
            margin-bottom: 0.25rem;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
        }

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
        }

        .menu-badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
        }

        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon {
            background: var(--finance-light);
            color: var(--finance-primary);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        .trend-positive {
            color: var(--success);
        }

        .trend-negative {
            color: var(--danger);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--finance-light);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        .amount {
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        .amount.income {
            color: var(--success);
        }

        .amount.expense {
            color: var(--danger);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .status-pending_approval {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-approved_by_finance {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-approved_by_president {
            background: #d4edda;
            color: var(--success);
        }

        .status-rejected {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        .tab.active {
            color: var(--finance-primary);
            border-bottom-color: var(--finance-primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Filters */
        .filters {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        /* Charts */
        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .table {
                font-size: 0.7rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Transactions</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Vice Guild Finance</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="budget_management.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Budget Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php" class="active">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                        <?php if ($pending_approvals > 0): ?>
                            <span class="menu-badge"><?php echo $pending_approvals; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="rental_management.php">
                        <i class="fas fa-home"></i>
                        <span>Rental Properties</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="allowances.php">
                        <i class="fas fa-money-check"></i>
                        <span>Allowances</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="bank_reconciliation.php">
                        <i class="fas fa-university"></i>
                        <span>Bank Reconciliation</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Official Documents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Financial Transactions 💰</h1>
                    <p>Manage all income and expense transactions with approval workflow</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Transaction Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_count; ?></div>
                        <div class="stat-label">Total Transactions</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-line"></i> All Time
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($monthly_income, 2); ?></div>
                        <div class="stat-label">Monthly Income</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-trending-up"></i> This Month
                        </div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($monthly_expenses, 2); ?></div>
                        <div class="stat-label">Monthly Expenses</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-trending-down"></i> This Month
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_approvals; ?></div>
                        <div class="stat-label">Pending Approvals</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-exclamation-circle"></i> Needs Attention
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Trends Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Monthly Income vs Expenses</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyTrendsChart"></canvas>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="form-group">
                            <label class="form-label">Transaction Type</label>
                            <select class="form-select" name="type">
                                <option value="">All Types</option>
                                <option value="income" <?php echo $filter_type === 'income' ? 'selected' : ''; ?>>Income</option>
                                <option value="expense" <?php echo $filter_type === 'expense' ? 'selected' : ''; ?>>Expense</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Statuses</option>
                                <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="pending_approval" <?php echo $filter_status === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                                <option value="approved_by_finance" <?php echo $filter_status === 'approved_by_finance' ? 'selected' : ''; ?>>Approved by Finance</option>
                                <option value="approved_by_president" <?php echo $filter_status === 'approved_by_president' ? 'selected' : ''; ?>>Approved by President</option>
                                <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Month</label>
                            <input type="month" class="form-control" name="month" value="<?php echo $filter_month; ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Description, Reference, Payee...">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary" style="margin-bottom: 0;">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="transactions.php" class="btn" style="margin-bottom: 0;">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Transactions Table -->
            <div class="card">
                <div class="card-header">
                    <h3>All Transactions</h3>
                    <div class="card-header-actions">
                        <button class="card-header-btn" onclick="openModal('addTransactionModal')" title="Add New Transaction">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Payee/Payer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No transactions found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $transaction['transaction_type'] === 'income' ? 'active' : 'inactive'; ?>">
                                                <?php echo ucfirst($transaction['transaction_type']); ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 200px;">
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                            <?php if ($transaction['reference_number']): ?>
                                                <small style="color: var(--dark-gray);">Ref: <?php echo htmlspecialchars($transaction['reference_number']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['category_name']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['payee_payer']); ?></td>
                                        <td>
                                            <span class="amount <?php echo $transaction['transaction_type']; ?>">
                                                RWF <?php echo number_format($transaction['amount'], 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $transaction['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                <!-- View/Edit for draft transactions -->
                                                <?php if ($transaction['status'] === 'draft'): ?>
                                                    <button class="btn btn-warning btn-sm" onclick="editTransaction(<?php echo $transaction['id']; ?>, <?php echo htmlspecialchars(json_encode($transaction)); ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?action=submit_approval&id=<?php echo $transaction['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Submit this transaction for approval?')">
                                                        <i class="fas fa-paper-plane"></i>
                                                    </a>
                                                    <a href="?action=delete_transaction&id=<?php echo $transaction['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this transaction?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Finance approval for pending transactions -->
                                                <?php if ($transaction['status'] === 'pending_approval'): ?>
                                                    <a href="?action=approve_finance&id=<?php echo $transaction['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this transaction?')">
                                                        <i class="fas fa-check"></i> Approve
                                                    </a>
                                                    <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?php echo $transaction['id']; ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php endif; ?>

                                                <!-- Complete for president-approved transactions -->
                                                <?php if ($transaction['status'] === 'approved_by_president'): ?>
                                                    <a href="?action=complete_transaction&id=<?php echo $transaction['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Mark this transaction as completed/paid?')">
                                                        <i class="fas fa-check-double"></i> Complete
                                                    </a>
                                                <?php endif; ?>

                                                <!-- View details for all transactions -->
                                                <button class="btn btn-primary btn-sm" onclick="viewTransactionDetails(<?php echo htmlspecialchars(json_encode($transaction)); ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Transaction Modal -->
    <div id="addTransactionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Transaction</h3>
                <button class="close" onclick="closeModal('addTransactionModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="transactionForm">
                    <input type="hidden" name="action" value="add_transaction">
                    <input type="hidden" name="transaction_id" id="editTransactionId">
                    
                    <div class="form-group">
                        <label class="form-label">Transaction Type *</label>
                        <select class="form-select" name="transaction_type" id="transactionType" required>
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select class="form-select" name="category_id" id="transactionCategory" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" data-type="<?php echo $category['category_type']; ?>">
                                    <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo ucfirst($category['category_type']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Amount (RWF) *</label>
                        <input type="number" class="form-control" name="amount" id="transactionAmount" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description" id="transactionDescription" rows="3" required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Transaction Date *</label>
                        <input type="date" class="form-control" name="transaction_date" id="transactionDate" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" name="reference_number" id="transactionReference">
                    </div>

                    <div class="form-group">
                        <label class="form-label" id="payeePayerLabel">Payee/Payer *</label>
                        <input type="text" class="form-control" name="payee_payer" id="transactionPayee" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Payment Method *</label>
                        <select class="form-select" name="payment_method" id="transactionMethod" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addTransactionModal')">Cancel</button>
                <button type="submit" form="transactionForm" class="btn btn-primary">Save Transaction</button>
            </div>
        </div>
    </div>

    <!-- Reject Transaction Modal -->
    <div id="rejectTransactionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Transaction</h3>
                <button class="close" onclick="closeModal('rejectTransactionModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="rejectForm">
                    <input type="hidden" name="action" value="reject_transaction">
                    <input type="hidden" name="transaction_id" id="rejectTransactionId">
                    
                    <div class="form-group">
                        <label class="form-label">Rejection Reason *</label>
                        <textarea class="form-control" name="rejection_reason" rows="4" required placeholder="Please provide a reason for rejecting this transaction..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('rejectTransactionModal')">Cancel</button>
                <button type="submit" form="rejectForm" class="btn btn-danger">Reject Transaction</button>
            </div>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div id="transactionDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Transaction Details</h3>
                <button class="close" onclick="closeModal('transactionDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="transactionDetailsContent">
                <!-- Details will be loaded here by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('transactionDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            // Reset form
            if (modalId === 'addTransactionModal') {
                document.getElementById('transactionForm').reset();
                document.getElementById('editTransactionId').value = '';
                document.querySelector('#addTransactionModal .modal-header h3').textContent = 'Add New Transaction';
                document.querySelector('#transactionForm input[name="action"]').value = 'add_transaction';
            }
        }

        // Transaction type change handler
        document.getElementById('transactionType').addEventListener('change', function() {
            const type = this.value;
            const label = document.getElementById('payeePayerLabel');
            label.textContent = type === 'income' ? 'Payer *' : 'Payee *';
            
            // Filter categories based on type
            const categorySelect = document.getElementById('transactionCategory');
            const options = categorySelect.options;
            
            for (let i = 0; i < options.length; i++) {
                const option = options[i];
                if (option.value === '') continue;
                
                const categoryType = option.getAttribute('data-type');
                if (categoryType === type) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                    if (option.selected) {
                        categorySelect.value = '';
                    }
                }
            }
        });

        // Edit transaction
        function editTransaction(id, transaction) {
            document.getElementById('editTransactionId').value = id;
            document.getElementById('transactionType').value = transaction.transaction_type;
            document.getElementById('transactionCategory').value = transaction.category_id;
            document.getElementById('transactionAmount').value = transaction.amount;
            document.getElementById('transactionDescription').value = transaction.description;
            document.getElementById('transactionDate').value = transaction.transaction_date;
            document.getElementById('transactionReference').value = transaction.reference_number || '';
            document.getElementById('transactionPayee').value = transaction.payee_payer;
            document.getElementById('transactionMethod').value = transaction.payment_method;
            
            // Update UI for type
            document.getElementById('transactionType').dispatchEvent(new Event('change'));
            
            // Change form action to edit
            document.querySelector('#transactionForm input[name="action"]').value = 'edit_transaction';
            document.querySelector('#addTransactionModal .modal-header h3').textContent = 'Edit Transaction';
            
            openModal('addTransactionModal');
        }

        // Reject transaction modal
        function openRejectModal(transactionId) {
            document.getElementById('rejectTransactionId').value = transactionId;
            openModal('rejectTransactionModal');
        }

        // View transaction details
        function viewTransactionDetails(transaction) {
            const content = document.getElementById('transactionDetailsContent');
            
            let detailsHtml = `
                <div style="display: grid; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Transaction ID</span>
                        <strong>#${transaction.id}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Type</span>
                        <span class="status-badge status-${transaction.transaction_type}">${transaction.transaction_type.charAt(0).toUpperCase() + transaction.transaction_type.slice(1)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Amount</span>
                        <strong class="amount ${transaction.transaction_type}" style="font-size: 1.1rem;">RWF ${parseFloat(transaction.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Category</span>
                        <strong>${transaction.category_name}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Description</span>
                        <strong>${transaction.description}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Date</span>
                        <strong>${new Date(transaction.transaction_date).toLocaleDateString()}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">${transaction.transaction_type === 'income' ? 'Payer' : 'Payee'}</span>
                        <strong>${transaction.payee_payer}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Payment Method</span>
                        <strong>${transaction.payment_method.replace('_', ' ').toUpperCase()}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Reference</span>
                        <strong>${transaction.reference_number || 'N/A'}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Status</span>
                        <span class="status-badge status-${transaction.status}">${transaction.status.replace(/_/g, ' ').toUpperCase()}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Requested By</span>
                        <strong>${transaction.requested_by_name}</strong>
                    </div>
            `;
            
            if (transaction.approved_by_finance_name) {
                detailsHtml += `
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Approved by Finance</span>
                        <strong>${transaction.approved_by_finance_name}</strong>
                    </div>
                `;
            }
            
            if (transaction.approved_by_president_name) {
                detailsHtml += `
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--dark-gray);">Approved by President</span>
                        <strong>${transaction.approved_by_president_name}</strong>
                    </div>
                `;
            }
            
            if (transaction.rejection_reason) {
                detailsHtml += `
                    <div>
                        <span style="color: var(--dark-gray);">Rejection Reason</span>
                        <div style="background: var(--light-gray); padding: 0.75rem; border-radius: var(--border-radius); margin-top: 0.5rem;">
                            ${transaction.rejection_reason}
                        </div>
                    </div>
                `;
            }
            
            detailsHtml += `</div>`;
            
            content.innerHTML = detailsHtml;
            openModal('transactionDetailsModal');
        }

        // Initialize chart
        document.addEventListener('DOMContentLoaded', function() {
            const monthlyTrends = <?php echo json_encode($monthly_trends); ?>;
            
            if (monthlyTrends.length > 0) {
                const ctx = document.getElementById('monthlyTrendsChart').getContext('2d');
                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: monthlyTrends.map(item => new Date(item.month + '-01').toLocaleDateString('en-US', {month: 'short', year: 'numeric'})).reverse(),
                        datasets: [
                            {
                                label: 'Income',
                                data: monthlyTrends.map(item => item.income).reverse(),
                                backgroundColor: '#28a745',
                                borderColor: '#1e7e34',
                                borderWidth: 1
                            },
                            {
                                label: 'Expenses',
                                data: monthlyTrends.map(item => item.expenses).reverse(),
                                backgroundColor: '#dc3545',
                                borderColor: '#c82333',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return 'RWF ' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Set default date to today
            document.getElementById('transactionDate').valueAsDate = new Date();
        });

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>