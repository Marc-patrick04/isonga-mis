<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';

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

// Get current academic year dynamically
$current_academic_year = getCurrentAcademicYear();

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Update bank balance
if ($action === 'update_balance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_balance = $_POST['new_balance'];
    $balance_date = $_POST['balance_date'];
    $notes = trim($_POST['notes']);
    
    try {
        $pdo->beginTransaction();
        
        // Update RPSU account balance
        $stmt = $pdo->prepare("UPDATE rpsu_account SET current_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE is_active = 1");
        $stmt->execute([$new_balance]);
        
        // Log the balance update
        $stmt = $pdo->prepare("INSERT INTO financial_audit_trail (action_type, table_name, record_id, new_values, performed_by, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'BALANCE_UPDATE',
            'rpsu_account',
            1,
            json_encode(['new_balance' => $new_balance, 'balance_date' => $balance_date, 'notes' => $notes]),
            $user_id,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $pdo->commit();
        
        $message = "Bank balance updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error updating bank balance: " . $e->getMessage();
        $message_type = "error";
    }
}

// Perform reconciliation
if ($action === 'reconcile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reconciliation_date = $_POST['reconciliation_date'];
    $bank_balance = $_POST['bank_balance'];
    $system_balance = $_POST['system_balance'];
    $difference = $_POST['difference'];
    $reconciliation_notes = trim($_POST['reconciliation_notes']);
    $status = $_POST['status'];
    
    try {
        $pdo->beginTransaction();
        
        // Insert reconciliation record
        $stmt = $pdo->prepare("INSERT INTO bank_reconciliations (reconciliation_date, bank_balance, system_balance, difference, notes, status, reconciled_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$reconciliation_date, $bank_balance, $system_balance, $difference, $reconciliation_notes, $status, $user_id]);
        $reconciliation_id = $pdo->lastInsertId();
        
        // If reconciled, update system balance to match bank balance
        if ($status === 'reconciled' && $difference == 0) {
            $stmt = $pdo->prepare("UPDATE rpsu_account SET current_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE is_active = 1");
            $stmt->execute([$bank_balance]);
        }
        
        // Log the reconciliation
        $stmt = $pdo->prepare("INSERT INTO financial_audit_trail (action_type, table_name, record_id, new_values, performed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'RECONCILIATION',
            'bank_reconciliations',
            $reconciliation_id,
            json_encode([
                'reconciliation_date' => $reconciliation_date,
                'bank_balance' => $bank_balance,
                'system_balance' => $system_balance,
                'difference' => $difference,
                'status' => $status
            ]),
            $user_id
        ]);
        
        $pdo->commit();
        
        $message = "Bank reconciliation completed successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error performing reconciliation: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get RPSU account details
try {
    $stmt = $pdo->query("SELECT * FROM rpsu_account WHERE is_active = 1 LIMIT 1");
    $rpsu_account = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rpsu_account = [];
    error_log("RPSU account error: " . $e->getMessage());
}

// Get recent transactions for reconciliation
try {
    $stmt = $pdo->prepare("
        SELECT 
            ft.*,
            bc.category_name,
            u_req.full_name as requested_by_name,
            u_finance.full_name as approved_by_finance_name,
            u_president.full_name as approved_by_president_name
        FROM financial_transactions ft
        LEFT JOIN budget_categories bc ON ft.category_id = bc.id
        LEFT JOIN users u_req ON ft.requested_by = u_req.id
        LEFT JOIN users u_finance ON ft.approved_by_finance = u_finance.id
        LEFT JOIN users u_president ON ft.approved_by_president = u_president.id
        WHERE ft.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND ft.status = 'completed'
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
    ");
    $stmt->execute();
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_transactions = [];
    error_log("Recent transactions error: " . $e->getMessage());
}

// Get reconciliation history
try {
    $stmt = $pdo->query("
        SELECT br.*, u.full_name as reconciled_by_name
        FROM bank_reconciliations br
        LEFT JOIN users u ON br.reconciled_by = u.id
        ORDER BY br.reconciliation_date DESC, br.created_at DESC
        LIMIT 10
    ");
    $reconciliation_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reconciliation_history = [];
    error_log("Reconciliation history error: " . $e->getMessage());
}

// Calculate reconciliation statistics
try {
    // Total transactions this month
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as total_expenses
        FROM financial_transactions 
        WHERE MONTH(transaction_date) = MONTH(CURDATE()) 
        AND YEAR(transaction_date) = YEAR(CURDATE())
        AND status = 'completed'
    ");
    $monthly_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Pending transactions
    $stmt = $pdo->query("
        SELECT COUNT(*) as pending_count
        FROM financial_transactions 
        WHERE status IN ('pending_approval', 'approved_by_finance')
    ");
    $pending_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Reconciliation frequency
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_reconciliations,
            MAX(reconciliation_date) as last_reconciliation,
            AVG(DATEDIFF(reconciliation_date, LAG(reconciliation_date) OVER (ORDER BY reconciliation_date))) as avg_days_between
        FROM bank_reconciliations 
        WHERE status = 'reconciled'
    ");
    $reconciliation_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $monthly_stats = $pending_stats = $reconciliation_stats = [];
    error_log("Reconciliation statistics error: " . $e->getMessage());
}

// Calculate system expected balance
$system_expected_balance = $rpsu_account['current_balance'] ?? 0;

// Create bank_reconciliations table if not exists
try {
    $stmt = $pdo->query("
        CREATE TABLE IF NOT EXISTS bank_reconciliations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            reconciliation_date DATE NOT NULL,
            bank_balance DECIMAL(15,2) NOT NULL,
            system_balance DECIMAL(15,2) NOT NULL,
            difference DECIMAL(15,2) NOT NULL,
            notes TEXT,
            status ENUM('pending', 'reconciled', 'discrepancy') DEFAULT 'pending',
            reconciled_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (reconciled_by) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Bank reconciliations table creation error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Reconciliation - Isonga RPSU</title>
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

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-reconciled {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-discrepancy {
            background: #f8d7da;
            color: #721c24;
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

        /* Balance Comparison */
        .balance-comparison {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .balance-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-top: 4px solid var(--finance-primary);
        }

        .balance-card.system {
            border-top-color: var(--success);
        }

        .balance-card.bank {
            border-top-color: var(--warning);
        }

        .balance-card.difference {
            border-top-color: var(--danger);
        }

        .balance-label {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .balance-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            font-family: 'Courier New', monospace;
        }

        /* Reconciliation Steps */
        .reconciliation-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .step-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
        }

        .step-number {
            width: 30px;
            height: 30px;
            background: var(--finance-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .step-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .step-description {
            font-size: 0.8rem;
            color: var(--dark-gray);
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
            max-width: 500px;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 200px;
            margin-top: 1rem;
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
            
            .balance-comparison,
            .reconciliation-steps {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
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
                    <h1>Isonga - Bank Reconciliation</h1>
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
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
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
                    <a href="bank_reconciliation.php" class="active">
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
                    <h1>Bank Reconciliation 🏦</h1>
                    <p>Reconcile bank statements with system records for <?php echo $current_academic_year; ?> academic year</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Bank Reconciliation Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($rpsu_account['current_balance'] ?? 0, 2); ?></div>
                        <div class="stat-label">Current Bank Balance</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-sync-alt"></i> System Record
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $monthly_stats['total_transactions'] ?? 0; ?></div>
                        <div class="stat-label">Transactions This Month</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-line"></i> Active
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_stats['pending_count'] ?? 0; ?></div>
                        <div class="stat-label">Pending Approvals</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-exclamation-circle"></i> Needs Attention
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $reconciliation_stats['total_reconciliations'] ?? 0; ?></div>
                        <div class="stat-label">Total Reconciliations</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-check-circle"></i> Tracked
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reconciliation Steps -->
            <div class="reconciliation-steps">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <div class="step-title">Get Bank Statement</div>
                    <div class="step-description">
                        Obtain the latest bank statement from your bank either online or via bank visit.
                    </div>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <div class="step-title">Compare Balances</div>
                    <div class="step-description">
                        Compare the bank statement balance with the system's recorded balance.
                    </div>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <div class="step-title">Identify Differences</div>
                    <div class="step-description">
                        Identify any discrepancies between bank records and system records.
                    </div>
                </div>
                <div class="step-card">
                    <div class="step-number">4</div>
                    <div class="step-title">Reconcile & Update</div>
                    <div class="step-description">
                        Resolve differences and update system records to match bank statements.
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h3>Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button class="btn btn-primary" onclick="openModal('updateBalanceModal')">
                            <i class="fas fa-edit"></i> Update Bank Balance
                        </button>
                        <button class="btn btn-success" onclick="openModal('reconcileModal')">
                            <i class="fas fa-sync-alt"></i> Perform Reconciliation
                        </button>
                        <button class="btn btn-warning" onclick="openModal('viewStatementModal')">
                            <i class="fas fa-file-invoice"></i> View Recent Transactions
                        </button>
                        <a href="financial_reports.php" class="btn">
                            <i class="fas fa-download"></i> Generate Reconciliation Report
                        </a>
                    </div>
                </div>
            </div>

            <!-- Balance Comparison -->
            <div class="balance-comparison">
                <div class="balance-card system">
                    <div class="balance-label">System Balance</div>
                    <div class="balance-amount">RWF <?php echo number_format($system_expected_balance, 2); ?></div>
                    <div style="font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.5rem;">
                        Last updated: <?php echo $rpsu_account ? date('M j, Y', strtotime($rpsu_account['updated_at'])) : 'Never'; ?>
                    </div>
                </div>
                <div class="balance-card bank">
                    <div class="balance-label">Bank Statement Balance</div>
                    <div class="balance-amount" id="bankBalanceDisplay">-</div>
                    <div style="font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.5rem;">
                        Enter current bank balance
                    </div>
                </div>
                <div class="balance-card difference">
                    <div class="balance-label">Difference</div>
                    <div class="balance-amount" id="differenceDisplay">-</div>
                    <div style="font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.5rem;">
                        Should be 0 when reconciled
                    </div>
                </div>
            </div>

            <!-- Recent Reconciliation History -->
            <div class="card">
                <div class="card-header">
                    <h3>Recent Reconciliation History</h3>
                    <div class="card-header-actions">
                        <button class="card-header-btn" title="Refresh">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Bank Balance</th>
                                <th>System Balance</th>
                                <th>Difference</th>
                                <th>Status</th>
                                <th>Reconciled By</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reconciliation_history)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No reconciliation history found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reconciliation_history as $reconciliation): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($reconciliation['reconciliation_date'])); ?></td>
                                        <td class="amount">RWF <?php echo number_format($reconciliation['bank_balance'], 2); ?></td>
                                        <td class="amount">RWF <?php echo number_format($reconciliation['system_balance'], 2); ?></td>
                                        <td class="amount <?php echo $reconciliation['difference'] == 0 ? 'income' : 'expense'; ?>">
                                            RWF <?php echo number_format(abs($reconciliation['difference']), 2); ?>
                                            <?php if ($reconciliation['difference'] != 0): ?>
                                                <br><small style="color: var(--danger);">Discrepancy</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $reconciliation['status']; ?>">
                                                <?php echo ucfirst($reconciliation['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($reconciliation['reconciled_by_name']); ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($reconciliation['notes'] ?? 'No notes'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Transactions for Reconciliation -->
            <div class="card">
                <div class="card-header">
                    <h3>Recent Transactions (Last 30 Days)</h3>
                    <div class="card-header-actions">
                        <button class="card-header-btn" title="Export">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_transactions)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        No recent transactions found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                        <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($transaction['description']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['category_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $transaction['transaction_type']; ?>">
                                                <?php echo ucfirst($transaction['transaction_type']); ?>
                                            </span>
                                        </td>
                                        <td class="amount <?php echo $transaction['transaction_type']; ?>">
                                            RWF <?php echo number_format($transaction['amount'], 2); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['reference_number'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $transaction['status'])); ?>
                                            </span>
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

    <!-- Update Balance Modal -->
    <div id="updateBalanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Bank Balance</h3>
                <button class="close" onclick="closeModal('updateBalanceModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="updateBalanceForm">
                    <input type="hidden" name="action" value="update_balance">
                    
                    <div class="form-group">
                        <label class="form-label">Current System Balance</label>
                        <input type="text" class="form-control" value="RWF <?php echo number_format($system_expected_balance, 2); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">New Bank Balance (RWF)</label>
                        <input type="number" class="form-control" name="new_balance" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Balance Date</label>
                        <input type="date" class="form-control" name="balance_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Reason for balance update..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('updateBalanceModal')">Cancel</button>
                <button type="submit" form="updateBalanceForm" class="btn btn-primary">Update Balance</button>
            </div>
        </div>
    </div>

    <!-- Reconcile Modal -->
    <div id="reconcileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Perform Bank Reconciliation</h3>
                <button class="close" onclick="closeModal('reconcileModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="reconcileForm">
                    <input type="hidden" name="action" value="reconcile">
                    
                    <div class="form-group">
                        <label class="form-label">Reconciliation Date</label>
                        <input type="date" class="form-control" name="reconciliation_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">System Balance (RWF)</label>
                        <input type="number" class="form-control" name="system_balance" value="<?php echo $system_expected_balance; ?>" step="0.01" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Bank Statement Balance (RWF)</label>
                        <input type="number" class="form-control" name="bank_balance" id="bankBalanceInput" step="0.01" required oninput="calculateDifference()">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Difference (RWF)</label>
                        <input type="number" class="form-control" name="difference" id="differenceInput" step="0.01" readonly>
                        <small id="differenceMessage" style="color: var(--dark-gray);"></small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reconciliation Status</label>
                        <select class="form-select" name="status" id="reconciliationStatus" onchange="updateStatusMessage()">
                            <option value="pending">Pending Review</option>
                            <option value="reconciled">Reconciled</option>
                            <option value="discrepancy">Discrepancy Found</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reconciliation Notes</label>
                        <textarea class="form-control" name="reconciliation_notes" id="reconciliationNotes" rows="4" placeholder="Describe any discrepancies or reconciliation details..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('reconcileModal')">Cancel</button>
                <button type="submit" form="reconcileForm" class="btn btn-primary">Save Reconciliation</button>
            </div>
        </div>
    </div>

    <!-- View Statement Modal -->
    <div id="viewStatementModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3>Recent Bank Statement Summary</h3>
                <button class="close" onclick="closeModal('viewStatementModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="card">
                    <div class="card-header">
                        <h4>Account Information</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($rpsu_account): ?>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div>
                                    <strong>Account Name:</strong><br>
                                    <?php echo htmlspecialchars($rpsu_account['account_name']); ?>
                                </div>
                                <div>
                                    <strong>Account Number:</strong><br>
                                    <?php echo htmlspecialchars($rpsu_account['account_number']); ?>
                                </div>
                                <div>
                                    <strong>Bank:</strong><br>
                                    <?php echo htmlspecialchars($rpsu_account['bank_name']); ?>
                                </div>
                                <div>
                                    <strong>Branch:</strong><br>
                                    <?php echo htmlspecialchars($rpsu_account['branch_name']); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--dark-gray);">No bank account configured</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-top: 1rem;">
                    <div class="card-header">
                        <h4>Monthly Summary</h4>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <strong>Total Income:</strong><br>
                                <span class="amount income">RWF <?php echo number_format($monthly_stats['total_income'] ?? 0, 2); ?></span>
                            </div>
                            <div>
                                <strong>Total Expenses:</strong><br>
                                <span class="amount expense">RWF <?php echo number_format($monthly_stats['total_expenses'] ?? 0, 2); ?></span>
                            </div>
                            <div>
                                <strong>Net Flow:</strong><br>
                                <span class="amount <?php echo ($monthly_stats['total_income'] - $monthly_stats['total_expenses']) >= 0 ? 'income' : 'expense'; ?>">
                                    RWF <?php echo number_format(($monthly_stats['total_income'] ?? 0) - ($monthly_stats['total_expenses'] ?? 0), 2); ?>
                                </span>
                            </div>
                            <div>
                                <strong>Transactions:</strong><br>
                                <?php echo $monthly_stats['total_transactions'] ?? 0; ?> transactions
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('viewStatementModal')">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Summary
                </button>
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
            
            // Initialize values for reconcile modal
            if (modalId === 'reconcileModal') {
                document.getElementById('bankBalanceInput').value = '';
                document.getElementById('differenceInput').value = '';
                document.getElementById('reconciliationStatus').value = 'pending';
                document.getElementById('reconciliationNotes').value = '';
                updateStatusMessage();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Calculate difference between bank and system balance
        function calculateDifference() {
            const systemBalance = <?php echo $system_expected_balance; ?>;
            const bankBalance = parseFloat(document.getElementById('bankBalanceInput').value) || 0;
            const difference = bankBalance - systemBalance;
            
            document.getElementById('differenceInput').value = difference.toFixed(2);
            
            // Update display
            document.getElementById('bankBalanceDisplay').textContent = 'RWF ' + bankBalance.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('differenceDisplay').textContent = 'RWF ' + Math.abs(difference).toLocaleString('en-US', {minimumFractionDigits: 2});
            
            // Update difference message
            const differenceMessage = document.getElementById('differenceMessage');
            if (difference === 0) {
                differenceMessage.textContent = 'Perfect match! Accounts are reconciled.';
                differenceMessage.style.color = 'var(--success)';
            } else if (difference > 0) {
                differenceMessage.textContent = 'Bank balance is higher than system balance.';
                differenceMessage.style.color = 'var(--warning)';
            } else {
                differenceMessage.textContent = 'System balance is higher than bank balance.';
                differenceMessage.style.color = 'var(--danger)';
            }
            
            // Auto-update status based on difference
            if (difference === 0) {
                document.getElementById('reconciliationStatus').value = 'reconciled';
            } else {
                document.getElementById('reconciliationStatus').value = 'discrepancy';
            }
            updateStatusMessage();
        }

        // Update status message based on selected status
        function updateStatusMessage() {
            const status = document.getElementById('reconciliationStatus').value;
            const difference = parseFloat(document.getElementById('differenceInput').value) || 0;
            
            let notes = document.getElementById('reconciliationNotes');
            
            switch (status) {
                case 'reconciled':
                    if (difference !== 0) {
                        notes.value = 'Accounts reconciled after adjusting for differences.';
                    } else {
                        notes.value = 'Accounts perfectly reconciled with no differences.';
                    }
                    break;
                case 'discrepancy':
                    notes.value = 'Discrepancy found between bank and system records. Investigation needed.';
                    break;
                case 'pending':
                    notes.value = 'Reconciliation pending review and verification.';
                    break;
            }
        }

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Initialize with current date
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date for all date inputs
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
        });
    </script>
</body>
</html>