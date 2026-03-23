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

// Get current academic year
$current_academic_year = '2024-2025';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_account'])) {
        // Add new bank account
        try {
            $account_name = $_POST['account_name'];
            $account_number = $_POST['account_number'];
            $bank_name = $_POST['bank_name'];
            $account_type = $_POST['account_type'];
            $current_balance = $_POST['current_balance'] ?? 0;
            $authorized_signatories = json_encode($_POST['authorized_signatories'] ?? []);
            
            $stmt = $pdo->prepare("
                INSERT INTO financial_accounts 
                (account_name, account_number, bank_name, account_type, current_balance, authorized_signatories, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $account_name, $account_number, $bank_name, $account_type, 
                $current_balance, $authorized_signatories
            ]);
            
            $_SESSION['success_message'] = "Bank account added successfully!";
            header('Location: accounts.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding bank account: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_account'])) {
        // Update bank account
        try {
            $account_id = $_POST['account_id'];
            $account_name = $_POST['account_name'];
            $account_number = $_POST['account_number'];
            $bank_name = $_POST['bank_name'];
            $account_type = $_POST['account_type'];
            $current_balance = $_POST['current_balance'];
            $authorized_signatories = json_encode($_POST['authorized_signatories'] ?? []);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $stmt = $pdo->prepare("
                UPDATE financial_accounts 
                SET account_name = ?, account_number = ?, bank_name = ?, account_type = ?,
                    current_balance = ?, authorized_signatories = ?, is_active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $account_name, $account_number, $bank_name, $account_type,
                $current_balance, $authorized_signatories, $is_active,
                $account_id
            ]);
            
            $_SESSION['success_message'] = "Bank account updated successfully!";
            header('Location: accounts.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating bank account: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_balance'])) {
        // Update account balance
        try {
            $account_id = $_POST['account_id'];
            $new_balance = $_POST['new_balance'];
            $balance_notes = $_POST['balance_notes'] ?? '';
            
            $stmt = $pdo->prepare("
                UPDATE financial_accounts 
                SET current_balance = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$new_balance, $account_id]);
            
            // Create account_balance_history table if it doesn't exist
            try {
                $stmt = $pdo->query("
                    CREATE TABLE IF NOT EXISTS account_balance_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        account_id INT NOT NULL,
                        previous_balance DECIMAL(15,2) NOT NULL,
                        new_balance DECIMAL(15,2) NOT NULL,
                        updated_by INT NOT NULL,
                        notes TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (account_id) REFERENCES financial_accounts(id),
                        FOREIGN KEY (updated_by) REFERENCES users(id)
                    )
                ");
            } catch (PDOException $e) {
                // Table might already exist
            }
            
            // Log balance update
            $stmt = $pdo->prepare("
                INSERT INTO account_balance_history 
                (account_id, previous_balance, new_balance, updated_by, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $current_balance = $_POST['current_balance']; // Previous balance
            $stmt->execute([$account_id, $current_balance, $new_balance, $user_id, $balance_notes]);
            
            $_SESSION['success_message'] = "Account balance updated successfully!";
            header('Location: accounts.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating account balance: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['deactivate_account'])) {
        // Deactivate account
        try {
            $account_id = $_POST['account_id'];
            
            $stmt = $pdo->prepare("
                UPDATE financial_accounts 
                SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$account_id]);
            
            $_SESSION['success_message'] = "Account deactivated successfully!";
            header('Location: accounts.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deactivating account: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['activate_account'])) {
        // Activate account
        try {
            $account_id = $_POST['account_id'];
            
            $stmt = $pdo->prepare("
                UPDATE financial_accounts 
                SET is_active = 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$account_id]);
            
            $_SESSION['success_message'] = "Account activated successfully!";
            header('Location: accounts.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error activating account: " . $e->getMessage();
        }
    }
}

// Get account data for editing if account_id is provided
$edit_account = null;
if (isset($_GET['edit_account'])) {
    $account_id = $_GET['edit_account'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM financial_accounts WHERE id = ?");
        $stmt->execute([$account_id]);
        $edit_account = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error loading account for editing: " . $e->getMessage();
    }
}

// Get accounts data
try {
    // All bank accounts
    $stmt = $pdo->query("
        SELECT fa.*, 
               (SELECT COUNT(*) FROM financial_transactions ft WHERE ft.account_id = fa.id AND ft.status = 'completed') as transaction_count,
               (SELECT SUM(amount) FROM financial_transactions ft WHERE ft.account_id = fa.id AND ft.transaction_type = 'income' AND ft.status = 'completed') as total_income,
               (SELECT SUM(amount) FROM financial_transactions ft WHERE ft.account_id = fa.id AND ft.transaction_type = 'expense' AND ft.status = 'completed') as total_expenses
        FROM financial_accounts fa
        ORDER BY fa.is_active DESC, fa.account_type, fa.account_name
    ");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Account statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_accounts,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_accounts,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_accounts,
            SUM(current_balance) as total_balance,
            AVG(current_balance) as average_balance,
            MAX(current_balance) as highest_balance,
            MIN(current_balance) as lowest_balance
        FROM financial_accounts
        WHERE is_active = 1
    ");
    $account_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Recent transactions across all accounts
    $stmt = $pdo->query("
        SELECT ft.*, fa.account_name, bc.category_name
        FROM financial_transactions ft
        LEFT JOIN financial_accounts fa ON ft.account_id = fa.id
        LEFT JOIN budget_categories bc ON ft.category_id = bc.id
        WHERE ft.status = 'completed'
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
        LIMIT 10
    ");
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Balance history (if table exists)
    $balance_history = [];
    try {
        $stmt = $pdo->query("
            SELECT abh.*, fa.account_name, u.full_name as updated_by_name
            FROM account_balance_history abh
            LEFT JOIN financial_accounts fa ON abh.account_id = fa.id
            LEFT JOIN users u ON abh.updated_by = u.id
            ORDER BY abh.created_at DESC
            LIMIT 20
        ");
        $balance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist yet - create sample data for demonstration
        $balance_history = [];
    }

    // Get users for authorized signatories
    $stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE status = 'active' 
        AND role IN ('guild_president', 'vice_guild_finance', 'general_secretary')
        ORDER BY full_name
    ");
    $authorized_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Account type distribution
    $stmt = $pdo->query("
        SELECT 
            account_type,
            COUNT(*) as account_count,
            SUM(current_balance) as total_balance
        FROM financial_accounts 
        WHERE is_active = 1
        GROUP BY account_type
        ORDER BY total_balance DESC
    ");
    $account_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly balance trends (using actual data from financial_transactions)
    $balance_trends = [];
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month,
            SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as net_flow
        FROM financial_transactions 
        WHERE status = 'completed'
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate cumulative balance
    $cumulative_balance = 0;
    foreach (array_reverse($monthly_data) as $month_data) {
        $cumulative_balance += $month_data['net_flow'];
        $balance_trends[] = [
            'month' => $month_data['month'],
            'balance' => $cumulative_balance > 0 ? $cumulative_balance : 0
        ];
    }

} catch (PDOException $e) {
    error_log("Accounts error: " . $e->getMessage());
    $accounts = $recent_transactions = $balance_history = $authorized_users = $account_types = [];
    $account_stats = [
        'total_accounts' => 0,
        'active_accounts' => 0,
        'inactive_accounts' => 0,
        'total_balance' => 0,
        'average_balance' => 0,
        'highest_balance' => 0,
        'lowest_balance' => 0
    ];
    $balance_trends = [];
}

// Calculate additional metrics
$utilization_rate = $account_stats['total_balance'] > 0 ? 
    round(($account_stats['total_balance'] / 10000000) * 100, 1) : 0; // Assuming 10M as target

// Calculate growth rate based on actual data
$growth_rate = 0;
if (count($balance_trends) >= 2) {
    $current = end($balance_trends)['balance'];
    $previous = $balance_trends[count($balance_trends)-2]['balance'];
    if ($previous > 0) {
        $growth_rate = round((($current - $previous) / $previous) * 100, 1);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Accounts Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* All the CSS styles from your original code remain the same */
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
            grid-template-columns: 2fr 1fr;
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

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .account-type-main {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .account-type-savings {
            background: #d4edda;
            color: var(--success);
        }

        .account-type-project {
            background: #fff3cd;
            color: var(--warning);
        }

        .account-type-emergency {
            background: #f8d7da;
            color: var(--danger);
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-check-input {
            width: 16px;
            height: 16px;
        }

        .form-check-label {
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
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

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
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
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            border-bottom: 2px solid transparent;
        }

        .tab.active {
            color: var(--finance-primary);
            border-bottom-color: var(--finance-primary);
        }

        .tab:hover {
            color: var(--finance-primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Account Cards */
        .account-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .account-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
            position: relative;
        }

        .account-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .account-card.main {
            border-left-color: var(--primary-blue);
        }

        .account-card.savings {
            border-left-color: var(--success);
        }

        .account-card.project {
            border-left-color: var(--warning);
        }

        .account-card.emergency {
            border-left-color: var(--danger);
        }

        .account-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .account-name {
            font-weight: 700;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .account-status {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .account-details {
            margin-bottom: 1rem;
        }

        .account-detail {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
        }

        .account-detail-label {
            color: var(--dark-gray);
        }

        .account-detail-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .account-balance {
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            margin: 1rem 0;
            color: var(--text-dark);
            font-family: 'Courier New', monospace;
        }

        .account-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert a {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
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
            height: 200px;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--finance-primary);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--finance-primary);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.75rem;
        }

        /* Account Actions */
        .account-actions {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        /* Signatory List */
        .signatory-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .signatory-badge {
            background: var(--finance-light);
            color: var(--finance-primary);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .account-cards {
                grid-template-columns: 1fr;
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
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .account-actions {
                flex-direction: column;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 600px;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--finance-light);
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        /* View Account Details */
        .account-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-group {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .transaction-history {
            margin-top: 1.5rem;
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .transaction-info {
            flex: 1;
        }

        .transaction-description {
            font-weight: 600;
            color: var(--text-dark);
        }

        .transaction-meta {
            font-size: 0.75rem;
            color: var(--dark-gray);
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
                    <h1>Isonga - Bank Accounts</h1>
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
                    <a href="payment_requests.php">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Payment Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="accounts.php" class="active">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Bank Accounts</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="approvals.php">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Approvals</span>
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
                    <h1>Bank Accounts Management 🏦</h1>
                    <p>Manage all organization bank accounts, balances, and authorized signatories</p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Accounts Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-piggy-bank"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $account_stats['total_accounts']; ?></div>
                        <div class="stat-label">Total Accounts</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-line"></i> All accounts
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['total_balance'], 2); ?></div>
                        <div class="stat-label">Total Balance</div>
                        <div class="stat-trend <?php echo $growth_rate >= 0 ? 'trend-positive' : 'trend-negative'; ?>">
                            <i class="fas fa-<?php echo $growth_rate >= 0 ? 'trend-up' : 'trend-down'; ?>"></i> 
                            <?php echo $growth_rate; ?>% growth
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $account_stats['active_accounts']; ?></div>
                        <div class="stat-label">Active Accounts</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-check"></i> Operational
                        </div>
                    </div>
                </div>
                <div class="stat-card <?php echo $utilization_rate > 80 ? 'success' : ($utilization_rate > 50 ? 'warning' : 'danger'); ?>">
                    <div class="stat-icon">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $utilization_rate; ?>%</div>
                        <div class="stat-label">Funds Utilization</div>
                        <div class="stat-trend <?php echo $utilization_rate > 80 ? 'trend-positive' : 'trend-negative'; ?>">
                            <i class="fas fa-<?php echo $utilization_rate > 80 ? 'check' : 'exclamation'; ?>-circle"></i>
                            <?php echo $utilization_rate > 80 ? 'Optimal' : ($utilization_rate > 50 ? 'Moderate' : 'Low'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['average_balance'], 2); ?></div>
                        <div class="stat-label">Average Balance</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-balance-scale"></i> Per account
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['highest_balance'], 2); ?></div>
                        <div class="stat-label">Highest Balance</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-trophy"></i> Top account
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['lowest_balance'], 2); ?></div>
                        <div class="stat-label">Lowest Balance</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-info-circle"></i> Needs monitoring
                        </div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $account_stats['inactive_accounts']; ?></div>
                        <div class="stat-label">Inactive Accounts</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-exclamation-triangle"></i> Requires attention
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <!-- Account Types Distribution -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Account Types Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="accountTypesChart"></canvas>
                    </div>
                </div>

                <!-- Balance Trends -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Balance Trends</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="balanceTrendsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab(event, 'accounts-overview')">Accounts Overview</button>
                <button class="tab" onclick="openTab(event, 'add-account')"><?php echo $edit_account ? 'Edit Account' : 'Add New Account'; ?></button>
                <button class="tab" onclick="openTab(event, 'balance-history')">Balance History</button>
                <button class="tab" onclick="openTab(event, 'reports')">Reports</button>
            </div>

            <!-- Accounts Overview Tab -->
            <div id="accounts-overview" class="tab-content active">
                <!-- Account Cards View -->
                <div class="account-cards">
                    <?php if (empty($accounts)): ?>
                        <div class="card">
                            <div class="card-body" style="text-align: center; padding: 3rem;">
                                <i class="fas fa-piggy-bank" style="font-size: 3rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                                <h3 style="color: var(--dark-gray); margin-bottom: 1rem;">No Bank Accounts Found</h3>
                                <p style="color: var(--dark-gray); margin-bottom: 1.5rem;">Get started by adding your first bank account.</p>
                                <button class="btn btn-primary" onclick="openTab(event, 'add-account')">
                                    <i class="fas fa-plus"></i> Add First Account
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($accounts as $account): 
                            // Get user names for authorized signatories
                            $signatory_names = [];
                            if (!empty($account['authorized_signatories'])) {
                                $signatory_ids = json_decode($account['authorized_signatories'], true);
                                foreach ($signatory_ids as $signatory_id) {
                                    foreach ($authorized_users as $user) {
                                        if ($user['id'] == $signatory_id) {
                                            $signatory_names[] = $user['full_name'];
                                            break;
                                        }
                                    }
                                }
                            }
                        ?>
                            <div class="account-card <?php echo $account['account_type']; ?>">
                                <div class="account-card-header">
                                    <div class="account-name"><?php echo htmlspecialchars($account['account_name']); ?></div>
                                    <span class="account-status status-<?php echo $account['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $account['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                
                                <div class="account-details">
                                    <div class="account-detail">
                                        <span class="account-detail-label">Account Number:</span>
                                        <span class="account-detail-value"><?php echo htmlspecialchars($account['account_number']); ?></span>
                                    </div>
                                    <div class="account-detail">
                                        <span class="account-detail-label">Bank:</span>
                                        <span class="account-detail-value"><?php echo htmlspecialchars($account['bank_name']); ?></span>
                                    </div>
                                    <div class="account-detail">
                                        <span class="account-detail-label">Type:</span>
                                        <span class="status-badge account-type-<?php echo $account['account_type']; ?>">
                                            <?php echo ucfirst($account['account_type']); ?>
                                        </span>
                                    </div>
                                    <div class="account-detail">
                                        <span class="account-detail-label">Transactions:</span>
                                        <span class="account-detail-value"><?php echo $account['transaction_count']; ?></span>
                                    </div>
                                    <?php if (!empty($signatory_names)): ?>
                                        <div class="account-detail">
                                            <span class="account-detail-label">Signatories:</span>
                                            <div class="signatory-list">
                                                <?php foreach ($signatory_names as $name): ?>
                                                    <span class="signatory-badge"><?php echo htmlspecialchars($name); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="account-balance">
                                    RWF <?php echo number_format($account['current_balance'], 2); ?>
                                </div>
                                
                                <div class="account-actions">
                                    <button class="btn btn-sm btn-secondary" onclick="viewAccountDetails(<?php echo $account['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <a href="accounts.php?edit_account=<?php echo $account['id']; ?>" class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button class="btn btn-sm btn-primary" onclick="updateBalance(<?php echo $account['id']; ?>, <?php echo $account['current_balance']; ?>)">
                                        <i class="fas fa-sync"></i> Balance
                                    </button>
                                    <?php if ($account['is_active']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                            <button type="submit" name="deactivate_account" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Are you sure you want to deactivate this account?')">
                                                <i class="fas fa-ban"></i> Deactivate
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                            <button type="submit" name="activate_account" class="btn btn-sm btn-success">
                                                <i class="fas fa-check"></i> Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Accounts Table View -->
                <div class="card">
                    <div class="card-header">
                        <h3>All Accounts Summary</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="exportAccounts()" title="Export Data">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="card-header-btn" onclick="printAccounts()" title="Print">
                                <i class="fas fa-print"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($accounts)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                No bank accounts found.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Account Name</th>
                                            <th>Account Number</th>
                                            <th>Bank Name</th>
                                            <th>Type</th>
                                            <th>Current Balance</th>
                                            <th>Transactions</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($accounts as $account): 
                                            $signatory_names = [];
                                            if (!empty($account['authorized_signatories'])) {
                                                $signatory_ids = json_decode($account['authorized_signatories'], true);
                                                foreach ($signatory_ids as $signatory_id) {
                                                    foreach ($authorized_users as $user) {
                                                        if ($user['id'] == $signatory_id) {
                                                            $signatory_names[] = $user['full_name'];
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($account['account_name']); ?></strong>
                                                    <?php if (!empty($signatory_names)): ?>
                                                        <br>
                                                        <div class="signatory-list">
                                                            <?php foreach ($signatory_names as $name): ?>
                                                                <span class="signatory-badge"><?php echo htmlspecialchars($name); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($account['account_number']); ?></td>
                                                <td><?php echo htmlspecialchars($account['bank_name']); ?></td>
                                                <td>
                                                    <span class="status-badge account-type-<?php echo $account['account_type']; ?>">
                                                        <?php echo ucfirst($account['account_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="amount">RWF <?php echo number_format($account['current_balance'], 2); ?></td>
                                                <td><?php echo $account['transaction_count']; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $account['is_active'] ? 'active' : 'inactive'; ?>">
                                                        <?php echo $account['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="account-actions">
                                                        <button class="btn btn-sm btn-secondary" onclick="viewAccountDetails(<?php echo $account['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="accounts.php?edit_account=<?php echo $account['id']; ?>" class="btn btn-sm btn-warning">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button class="btn btn-sm btn-primary" onclick="updateBalance(<?php echo $account['id']; ?>, <?php echo $account['current_balance']; ?>)">
                                                            <i class="fas fa-sync"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Add/Edit Account Tab -->
            <div id="add-account" class="tab-content <?php echo $edit_account ? 'active' : ''; ?>">
                <div class="content-grid">
                    <div class="left-column">
                        <div class="card">
                            <div class="card-header">
                                <h3><?php echo $edit_account ? 'Edit Bank Account' : 'Add New Bank Account'; ?></h3>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="accountForm">
                                    <?php if ($edit_account): ?>
                                        <input type="hidden" name="account_id" value="<?php echo $edit_account['id']; ?>">
                                    <?php endif; ?>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="account_name">Account Name *</label>
                                            <input type="text" class="form-control" id="account_name" name="account_name" 
                                                   required placeholder="e.g., RPSU Main Account"
                                                   value="<?php echo $edit_account ? htmlspecialchars($edit_account['account_name']) : ''; ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="account_number">Account Number *</label>
                                            <input type="text" class="form-control" id="account_number" name="account_number" 
                                                   required placeholder="e.g., 00123456789"
                                                   value="<?php echo $edit_account ? htmlspecialchars($edit_account['account_number']) : ''; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="bank_name">Bank Name *</label>
                                            <input type="text" class="form-control" id="bank_name" name="bank_name" 
                                                   required placeholder="e.g., Bank of Kigali"
                                                   value="<?php echo $edit_account ? htmlspecialchars($edit_account['bank_name']) : ''; ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="account_type">Account Type *</label>
                                            <select class="form-control" id="account_type" name="account_type" required>
                                                <option value="">Select account type</option>
                                                <option value="main" <?php echo $edit_account && $edit_account['account_type'] == 'main' ? 'selected' : ''; ?>>Main Account</option>
                                                <option value="savings" <?php echo $edit_account && $edit_account['account_type'] == 'savings' ? 'selected' : ''; ?>>Savings Account</option>
                                                <option value="project" <?php echo $edit_account && $edit_account['account_type'] == 'project' ? 'selected' : ''; ?>>Project Account</option>
                                                <option value="emergency" <?php echo $edit_account && $edit_account['account_type'] == 'emergency' ? 'selected' : ''; ?>>Emergency Fund</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="current_balance">Current Balance (RWF)</label>
                                        <input type="number" class="form-control" id="current_balance" name="current_balance" 
                                               step="0.01" min="0" placeholder="Enter current balance"
                                               value="<?php echo $edit_account ? $edit_account['current_balance'] : '0'; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Authorized Signatories</label>
                                        <div style="border: 1px solid var(--medium-gray); border-radius: var(--border-radius); padding: 1rem;">
                                            <?php 
                                            $selected_signatories = [];
                                            if ($edit_account && !empty($edit_account['authorized_signatories'])) {
                                                $selected_signatories = json_decode($edit_account['authorized_signatories'], true);
                                            }
                                            ?>
                                            <?php foreach ($authorized_users as $user): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="authorized_signatories[]" 
                                                           value="<?php echo $user['id']; ?>" id="signatory_<?php echo $user['id']; ?>"
                                                           <?php echo in_array($user['id'], $selected_signatories) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="signatory_<?php echo $user['id']; ?>">
                                                        <?php echo htmlspecialchars($user['full_name']); ?> 
                                                        <small style="color: var(--dark-gray);">(<?php echo $user['role']; ?>)</small>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <small style="color: var(--dark-gray);">Select users authorized to operate this account</small>
                                    </div>
                                    
                                    <?php if ($edit_account): ?>
                                        <div class="form-group">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                                       <?php echo $edit_account['is_active'] ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="is_active">
                                                    Account is active
                                                </label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="form-group">
                                        <?php if ($edit_account): ?>
                                            <button type="submit" name="update_account" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Update Account
                                            </button>
                                            <a href="accounts.php" class="btn btn-secondary">
                                                <i class="fas fa-times"></i> Cancel
                                            </a>
                                        <?php else: ?>
                                            <button type="submit" name="add_account" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Save Account
                                            </button>
                                            <button type="reset" class="btn btn-secondary">
                                                <i class="fas fa-redo"></i> Reset Form
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="right-column">
                        <!-- Account Types Guide -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Account Types Guide</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div class="alert alert-info">
                                        <strong>Main Account</strong><br>
                                        Primary operating account for daily transactions and expenses.
                                    </div>
                                    <div class="alert alert-success">
                                        <strong>Savings Account</strong><br>
                                        For accumulating funds and earning interest on surplus money.
                                    </div>
                                    <div class="alert alert-warning">
                                        <strong>Project Account</strong><br>
                                        Dedicated account for specific projects or initiatives.
                                    </div>
                                    <div class="alert alert-danger">
                                        <strong>Emergency Fund</strong><br>
                                        Reserved for unexpected expenses and emergency situations.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="quick-actions">
                                    <a href="transactions.php" class="action-btn">
                                        <i class="fas fa-exchange-alt"></i>
                                        <span class="action-label">View Transactions</span>
                                    </a>
                                    <a href="financial_reports.php" class="action-btn">
                                        <i class="fas fa-chart-bar"></i>
                                        <span class="action-label">Account Reports</span>
                                    </a>
                                    <a href="#" class="action-btn" onclick="printAccountSummary()">
                                        <i class="fas fa-print"></i>
                                        <span class="action-label">Print Summary</span>
                                    </a>
                                    <a href="#" class="action-btn" onclick="exportAccountDetails()">
                                        <i class="fas fa-download"></i>
                                        <span class="action-label">Export Data</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Balance History Tab -->
            <div id="balance-history" class="tab-content">
                <div class="content-grid">
                    <div class="left-column">
                        <div class="card">
                            <div class="card-header">
                                <h3>Balance Update History</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($balance_history)): ?>
                                    <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        <i class="fas fa-history" style="font-size: 3rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                                        <h3>No Balance History Available</h3>
                                        <p>Balance updates will appear here once you start updating account balances.</p>
                                    </div>
                                <?php else: ?>
                                    <div style="overflow-x: auto;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Account</th>
                                                    <th>Previous Balance</th>
                                                    <th>New Balance</th>
                                                    <th>Difference</th>
                                                    <th>Updated By</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($balance_history as $history): ?>
                                                    <tr>
                                                        <td><?php echo date('M j, Y H:i', strtotime($history['created_at'])); ?></td>
                                                        <td><?php echo htmlspecialchars($history['account_name']); ?></td>
                                                        <td class="amount">RWF <?php echo number_format($history['previous_balance'], 2); ?></td>
                                                        <td class="amount">RWF <?php echo number_format($history['new_balance'], 2); ?></td>
                                                        <td class="amount <?php echo ($history['new_balance'] - $history['previous_balance']) >= 0 ? 'income' : 'expense'; ?>">
                                                            RWF <?php echo number_format($history['new_balance'] - $history['previous_balance'], 2); ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($history['updated_by_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($history['notes'] ?? 'N/A'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="right-column">
                        <!-- Recent Transactions -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Recent Transactions</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_transactions)): ?>
                                    <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                        No recent transactions
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <div style="display: flex; justify-content: between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--medium-gray);">
                                            <div style="flex: 1;">
                                                <div style="font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                                <div style="font-size: 0.75rem; color: var(--dark-gray);">
                                                    <?php echo date('M j', strtotime($transaction['transaction_date'])); ?> • 
                                                    <?php echo htmlspecialchars($transaction['account_name']); ?>
                                                </div>
                                            </div>
                                            <div class="amount <?php echo $transaction['transaction_type']; ?>">
                                                RWF <?php echo number_format($transaction['amount'], 2); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports Tab -->
            <div id="reports" class="tab-content">
                <div class="content-grid">
                    <div class="left-column">
                        <div class="card">
                            <div class="card-header">
                                <h3>Account Reports</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        Generate comprehensive account reports for analysis and auditing.
                                    </div>
                                    
                                    <div class="quick-actions">
                                        <a href="financial_reports.php?type=account_summary" class="action-btn">
                                            <i class="fas fa-file-alt"></i>
                                            <span class="action-label">Account Summary</span>
                                        </a>
                                        <a href="financial_reports.php?type=balance_sheet" class="action-btn">
                                            <i class="fas fa-balance-scale"></i>
                                            <span class="action-label">Balance Sheet</span>
                                        </a>
                                        <a href="financial_reports.php?type=transaction_analysis" class="action-btn">
                                            <i class="fas fa-chart-line"></i>
                                            <span class="action-label">Transaction Analysis</span>
                                        </a>
                                        <a href="#" class="action-btn" onclick="generateCustomAccountReport()">
                                            <i class="fas fa-cog"></i>
                                            <span class="action-label">Custom Report</span>
                                        </a>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Report Period</label>
                                        <select class="form-control" id="account_report_period">
                                            <option value="current_month">Current Month</option>
                                            <option value="last_month">Last Month</option>
                                            <option value="current_quarter">Current Quarter</option>
                                            <option value="current_year">Current Year (<?php echo $current_academic_year; ?>)</option>
                                            <option value="custom">Custom Period</option>
                                        </select>
                                    </div>

                                    <div class="form-group" id="account_custom_period" style="display: none;">
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                            <div>
                                                <label class="form-label">Start Date</label>
                                                <input type="date" class="form-control" id="account_start_date">
                                            </div>
                                            <div>
                                                <label class="form-label">End Date</label>
                                                <input type="date" class="form-control" id="account_end_date">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Report Format</label>
                                        <select class="form-control" id="account_report_format">
                                            <option value="pdf">PDF Document</option>
                                            <option value="excel">Excel Spreadsheet</option>
                                            <option value="csv">CSV File</option>
                                        </select>
                                    </div>

                                    <button class="btn btn-primary" onclick="generateAccountReport()">
                                        <i class="fas fa-download"></i> Generate Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="right-column">
                        <div class="card">
                            <div class="card-header">
                                <h3>Report Templates</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div class="alert alert-success">
                                        <i class="fas fa-file-pdf"></i>
                                        <strong>Account Summary Report</strong><br>
                                        Comprehensive overview of all accounts with balances and transactions.
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <i class="fas fa-balance-scale"></i>
                                        <strong>Balance Sheet Report</strong><br>
                                        Detailed financial position showing assets, liabilities, and equity.
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-chart-bar"></i>
                                        <strong>Transaction Analysis</strong><br>
                                        Analysis of transaction patterns and cash flow across accounts.
                                    </div>
                                    
                                    <div class="alert alert-secondary">
                                        <i class="fas fa-cogs"></i>
                                        <strong>Custom Report Builder</strong><br>
                                        Create customized reports with specific parameters and filters.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- View Account Details Modal -->
    <div id="viewAccountModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Account Details</h3>
                <button class="card-header-btn" onclick="closeViewAccountModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewAccountModalBody">
                <!-- Account details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeViewAccountModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Update Balance Modal -->
    <div id="updateBalanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Account Balance</h3>
                <button class="card-header-btn" onclick="closeUpdateBalanceModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="updateBalanceForm">
                    <input type="hidden" id="balance_account_id" name="account_id">
                    <input type="hidden" id="current_balance_value" name="current_balance">
                    <div class="form-group">
                        <label class="form-label" for="new_balance">New Balance (RWF) *</label>
                        <input type="number" class="form-control" id="new_balance" name="new_balance" 
                               step="0.01" min="0" required placeholder="Enter new balance">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="balance_notes">Update Notes (Optional)</label>
                        <textarea class="form-control" id="balance_notes" name="balance_notes" rows="3" 
                                  placeholder="Add notes about this balance update"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="updateBalanceForm" name="update_balance" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Balance
                </button>
                <button class="btn btn-secondary" onclick="closeUpdateBalanceModal()">Cancel</button>
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

        // Tab functionality
        function openTab(evt, tabName) {
            const tabcontent = document.getElementsByClassName("tab-content");
            for (let i = 0; i < tabcontent.length; i++) {
                tabcontent[i].classList.remove("active");
            }
            
            const tablinks = document.getElementsByClassName("tab");
            for (let i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }
            
            document.getElementById(tabName).classList.add("active");
            if (evt && evt.currentTarget) {
                evt.currentTarget.classList.add("active");
            }
        }

        // Auto-open edit tab if editing
        <?php if ($edit_account): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openTab(null, 'add-account');
            });
        <?php endif; ?>

        // Report period toggle
        document.getElementById('account_report_period').addEventListener('change', function() {
            const customPeriod = document.getElementById('account_custom_period');
            customPeriod.style.display = this.value === 'custom' ? 'block' : 'none';
        });

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Account Types Chart
            const typesCtx = document.getElementById('accountTypesChart').getContext('2d');
            const typesChart = new Chart(typesCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($account_types, 'account_type')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($account_types, 'total_balance')); ?>,
                        backgroundColor: [
                            '#1976D2', '#28a745', '#ffc107', '#dc3545'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    return `${label}: RWF ${value.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });

            // Balance Trends Chart
            const trendsCtx = document.getElementById('balanceTrendsChart').getContext('2d');
            const trendsChart = new Chart(trendsCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($trend) {
                        return date('M Y', strtotime($trend['month'] . '-01'));
                    }, $balance_trends)); ?>,
                    datasets: [{
                        label: 'Total Balance',
                        data: <?php echo json_encode(array_column($balance_trends, 'balance')); ?>,
                        borderColor: '#1976D2',
                        backgroundColor: 'rgba(25, 118, 210, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: function(value) {
                                    return 'RWF ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        });

        // View Account Details Function
        function viewAccountDetails(accountId) {
            // Show loading state
            document.getElementById('viewAccountModalBody').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--finance-primary);"></i>
                    <p>Loading account details...</p>
                </div>
            `;
            document.getElementById('viewAccountModal').style.display = 'block';
            
            // Fetch account details via AJAX
            fetch(`get_account_details.php?account_id=${accountId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const account = data.account;
                        const transactions = data.transactions || [];
                        
                        let transactionsHtml = '';
                        if (transactions.length > 0) {
                            transactionsHtml = `
                                <div class="transaction-history">
                                    <h4>Recent Transactions</h4>
                                    ${transactions.map(transaction => `
                                        <div class="transaction-item">
                                            <div class="transaction-info">
                                                <div class="transaction-description">${transaction.description}</div>
                                                <div class="transaction-meta">
                                                    ${transaction.transaction_date} • ${transaction.category_name}
                                                </div>
                                            </div>
                                            <div class="amount ${transaction.transaction_type}">
                                                RWF ${parseFloat(transaction.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            `;
                        } else {
                            transactionsHtml = '<p>No transactions found for this account.</p>';
                        }
                        
                        document.getElementById('viewAccountModalBody').innerHTML = `
                            <div class="account-detail-grid">
                                <div class="detail-group">
                                    <div class="detail-label">Account Name</div>
                                    <div class="detail-value">${account.account_name}</div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Account Number</div>
                                    <div class="detail-value">${account.account_number}</div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Bank Name</div>
                                    <div class="detail-value">${account.bank_name}</div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Account Type</div>
                                    <div class="detail-value">
                                        <span class="status-badge account-type-${account.account_type}">
                                            ${account.account_type.charAt(0).toUpperCase() + account.account_type.slice(1)}
                                        </span>
                                    </div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Current Balance</div>
                                    <div class="detail-value amount">RWF ${parseFloat(account.current_balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value">
                                        <span class="status-badge status-${account.is_active ? 'active' : 'inactive'}">
                                            ${account.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            ${transactionsHtml}
                        `;
                    } else {
                        document.getElementById('viewAccountModalBody').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i> Error loading account details: ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('viewAccountModalBody').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> Error loading account details: ${error}
                        </div>
                    `;
                });
        }

        function closeViewAccountModal() {
            document.getElementById('viewAccountModal').style.display = 'none';
        }

        function updateBalance(accountId, currentBalance) {
            document.getElementById('balance_account_id').value = accountId;
            document.getElementById('current_balance_value').value = currentBalance;
            document.getElementById('new_balance').value = currentBalance;
            document.getElementById('updateBalanceModal').style.display = 'block';
        }

        function closeUpdateBalanceModal() {
            document.getElementById('updateBalanceModal').style.display = 'none';
        }

        // Export and print functions
        function exportAccounts() {
            alert('Export functionality would generate a CSV/Excel file of all accounts.');
        }

        function printAccounts() {
            window.print();
        }

        function printAccountSummary() {
            alert('Account summary report would be generated for printing.');
        }

        function exportAccountDetails() {
            alert('Account details would be exported in the selected format.');
        }

        function generateCustomAccountReport() {
            alert('Custom account report builder would open with advanced filtering options.');
        }

        function generateAccountReport() {
            const period = document.getElementById('account_report_period').value;
            const format = document.getElementById('account_report_format').value;
            alert(`Generating ${format} report for ${period} period...`);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['viewAccountModal', 'updateBalanceModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'viewAccountModal') closeViewAccountModal();
                    if (modalId === 'updateBalanceModal') closeUpdateBalanceModal();
                }
            });
        }

        // Auto-refresh account data
        setInterval(() => {
            // You could add auto-refresh logic for account balances
            console.log('Auto-refresh check for account updates');
        }, 300000); // 5 minutes
    </script>
</body>
</html>