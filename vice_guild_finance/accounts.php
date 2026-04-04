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

// Get unread messages count
$unread_messages = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
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
                (account_name, account_number, bank_name, account_type, current_balance, authorized_signatories, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, '1', ?)
            ");
            $stmt->execute([
                $account_name, 
                $account_number, 
                $bank_name, 
                $account_type, 
                $current_balance, 
                $authorized_signatories,
                $user_id
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
            $is_active = isset($_POST['is_active']) ? '1' : '0';
            
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
            
            // Get current balance
            $stmt = $pdo->prepare("SELECT current_balance FROM financial_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $current_balance = $stmt->fetch(PDO::FETCH_ASSOC)['current_balance'];
            
            $stmt = $pdo->prepare("
                UPDATE financial_accounts 
                SET current_balance = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$new_balance, $account_id]);
            
            // Create account_balance_history table if it doesn't exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS account_balance_history (
                    id SERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL,
                    previous_balance DECIMAL(15,2) NOT NULL,
                    new_balance DECIMAL(15,2) NOT NULL,
                    updated_by INTEGER NOT NULL,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Log balance update
            $stmt = $pdo->prepare("
                INSERT INTO account_balance_history 
                (account_id, previous_balance, new_balance, updated_by, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
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
                SET is_active = '0', updated_at = CURRENT_TIMESTAMP
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
                SET is_active = '1', updated_at = CURRENT_TIMESTAMP
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
        if ($edit_account) {
            $edit_account['authorized_signatories_array'] = json_decode($edit_account['authorized_signatories'] ?? '[]', true);
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error loading account for editing: " . $e->getMessage();
    }
}

// Get accounts data - PostgreSQL compatible with string is_active
try {
    // All bank accounts with correct counting
    $stmt = $pdo->query("
        SELECT fa.*, 
               COALESCE((
                   SELECT COUNT(*) FROM financial_transactions ft 
                   WHERE ft.account_id = fa.id AND ft.status = 'completed'
               ), 0) as transaction_count,
               COALESCE((
                   SELECT SUM(amount) FROM financial_transactions ft 
                   WHERE ft.account_id = fa.id AND ft.transaction_type = 'income' AND ft.status = 'completed'
               ), 0) as total_income,
               COALESCE((
                   SELECT SUM(amount) FROM financial_transactions ft 
                   WHERE ft.account_id = fa.id AND ft.transaction_type = 'expense' AND ft.status = 'completed'
               ), 0) as total_expenses
        FROM financial_accounts fa
        ORDER BY fa.is_active DESC, fa.account_type, fa.account_name
    ");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Account statistics - Using string values for is_active
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_accounts,
            COUNT(CASE WHEN is_active = '1' THEN 1 END) as active_accounts,
            COUNT(CASE WHEN is_active = '0' THEN 1 END) as inactive_accounts,
            COALESCE(SUM(current_balance), 0) as total_balance,
            COALESCE(AVG(current_balance), 0) as average_balance,
            COALESCE(MAX(current_balance), 0) as highest_balance,
            COALESCE(MIN(current_balance), 0) as lowest_balance
        FROM financial_accounts
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

    // Balance history
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

    // Account type distribution - Using string for is_active
    $stmt = $pdo->query("
        SELECT 
            account_type,
            COUNT(*) as account_count,
            COALESCE(SUM(current_balance), 0) as total_balance
        FROM financial_accounts 
        WHERE is_active = '1'
        GROUP BY account_type
        ORDER BY total_balance DESC
    ");
    $account_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly balance trends - PostgreSQL compatible
    $balance_trends = [];
    $stmt = $pdo->query("
        SELECT 
            TO_CHAR(transaction_date, 'YYYY-MM') as month,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net_flow
        FROM financial_transactions 
        WHERE status = 'completed'
        GROUP BY TO_CHAR(transaction_date, 'YYYY-MM')
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
    $accounts = [];
    $recent_transactions = [];
    $balance_history = [];
    $authorized_users = [];
    $account_types = [];
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
    round(($account_stats['total_balance'] / 10000000) * 100, 1) : 0;

// Calculate growth rate
$growth_rate = 0;
if (count($balance_trends) >= 2) {
    $current = end($balance_trends)['balance'];
    $previous = $balance_trends[count($balance_trends)-2]['balance'];
    if ($previous > 0) {
        $growth_rate = round((($current - $previous) / $previous) * 100, 1);
    }
}

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

// Badge counts for sidebar
$pending_approvals = 0;
$pending_budget_requests = 0;
$pending_aid_requests = 0;
try {
    $r = $pdo->query("SELECT COUNT(*) as c FROM financial_transactions WHERE status = 'approved_by_finance'");
    $pending_approvals = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
    $r = $pdo->query("SELECT COUNT(*) as c FROM committee_budget_requests WHERE status IN ('submitted','under_review')");
    $pending_budget_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
    $r = $pdo->query("SELECT COUNT(*) as c FROM student_financial_aid WHERE status IN ('submitted','under_review')");
    $pending_aid_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
} catch (PDOException $e) { /* silent */ }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Bank Accounts Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ... keep all existing CSS styles ... */
        /* (same CSS as before - no changes needed) */
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--finance-primary);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
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
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--finance-primary);
            color: white;
            border-color: var(--finance-primary);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--finance-primary);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
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
            width: 20px;
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            background: var(--finance-light);
            color: var(--finance-primary);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
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
            padding: 1rem;
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
            height: 250px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.6rem 1.2rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.85rem;
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
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .account-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
        }

        .account-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .account-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .account-name {
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-dark);
        }

        .account-status {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #e2e3e5;
            color: #383d41;
        }

        .account-details {
            margin-bottom: 0.75rem;
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
            font-size: 1.3rem;
            font-weight: 700;
            text-align: center;
            margin: 0.75rem 0;
            color: var(--text-dark);
            font-family: monospace;
        }

        .account-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--finance-light);
        }

        .card-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
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
            margin-bottom: 0.5rem;
        }

        .form-check-input {
            width: 16px;
            height: 16px;
        }

        .form-check-label {
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

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
            font-size: 0.75rem;
        }

        .table tbody tr:hover {
            background: var(--finance-light);
        }

        .amount {
            font-weight: 600;
            font-family: monospace;
        }

        .amount.income {
            color: var(--success);
        }

        .amount.expense {
            color: var(--danger);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .account-type-main { background: #cce7ff; color: #004085; }
        .account-type-savings { background: #d4edda; color: #155724; }
        .account-type-project { background: #fff3cd; color: #856404; }
        .account-type-emergency { background: #f8d7da; color: #721c24; }

        /* Signatory List */
        .signatory-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 0.25rem;
        }

        .signatory-badge {
            background: var(--finance-light);
            color: var(--finance-primary);
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: var(--info);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
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
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
            color: var(--finance-primary);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.7rem;
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
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background-color: var(--white);
            border-radius: var(--border-radius);
            width: 500px;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--finance-light);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        .modal-body {
            padding: 1rem;
        }

        .modal-footer {
            padding: 1rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        /* Overlay */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(2px);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--finance-primary);
                color: white;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .account-cards {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .account-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Bank Accounts</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo safe_display($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo safe_display($_SESSION['full_name']); ?></div>
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
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
                        <?php if ($pending_approvals > 0): ?>
                            <span class="menu-badge"><?php echo $pending_approvals; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                        <?php if ($pending_budget_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_budget_requests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                        <?php if ($pending_aid_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_aid_requests; ?></span>
                        <?php endif; ?>
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
                    <a href="accounts.php" class="active">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Bank Accounts</span>
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Bank Accounts Management 🏦</h1>
                    <p>Manage all organization bank accounts, balances, and authorized signatories</p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo safe_display($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo safe_display($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Accounts Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-piggy-bank"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $account_stats['total_accounts']; ?></div>
                        <div class="stat-label">Total Accounts</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['total_balance'], 0); ?></div>
                        <div class="stat-label">Total Balance</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $account_stats['active_accounts']; ?></div>
                        <div class="stat-label">Active Accounts</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $utilization_rate; ?>%</div>
                        <div class="stat-label">Funds Utilization</div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['average_balance'], 0); ?></div>
                        <div class="stat-label">Average Balance</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['highest_balance'], 0); ?></div>
                        <div class="stat-label">Highest Balance</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($account_stats['lowest_balance'], 0); ?></div>
                        <div class="stat-label">Lowest Balance</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-ban"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $account_stats['inactive_accounts']; ?></div>
                        <div class="stat-label">Inactive Accounts</div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Account Types Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="accountTypesChart"></canvas>
                    </div>
                </div>
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
            </div>

            <!-- Accounts Overview Tab -->
            <div id="accounts-overview" class="tab-content active">
                <div class="account-cards">
                    <?php if (empty($accounts)): ?>
                        <div class="card">
                            <div class="card-body" style="text-align: center; padding: 2rem;">
                                <i class="fas fa-piggy-bank" style="font-size: 2.5rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                                <h3 style="color: var(--dark-gray);">No Bank Accounts Found</h3>
                                <p style="color: var(--dark-gray); margin-bottom: 1rem;">Get started by adding your first bank account.</p>
                                <button class="btn btn-primary" onclick="openTab(event, 'add-account')">
                                    <i class="fas fa-plus"></i> Add First Account
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($accounts as $account): 
                            $signatory_names = [];
                            if (!empty($account['authorized_signatories'])) {
                                $signatory_ids = json_decode($account['authorized_signatories'], true);
                                if (is_array($signatory_ids)) {
                                    foreach ($signatory_ids as $signatory_id) {
                                        foreach ($authorized_users as $user) {
                                            if ($user['id'] == $signatory_id) {
                                                $signatory_names[] = $user['full_name'];
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        ?>
                            <div class="account-card">
                                <div class="account-card-header">
                                    <div class="account-name"><?php echo safe_display($account['account_name']); ?></div>
                                    <span class="account-status status-<?php echo $account['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $account['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <div class="account-details">
                                    <div class="account-detail">
                                        <span class="account-detail-label">Account Number:</span>
                                        <span class="account-detail-value"><?php echo safe_display($account['account_number']); ?></span>
                                    </div>
                                    <div class="account-detail">
                                        <span class="account-detail-label">Bank:</span>
                                        <span class="account-detail-value"><?php echo safe_display($account['bank_name']); ?></span>
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
                                                    <span class="signatory-badge"><?php echo safe_display($name); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="account-balance">
                                    RWF <?php echo number_format($account['current_balance'], 0); ?>
                                </div>
                                <div class="account-actions">
                                    <button class="btn btn-sm btn-secondary" onclick="viewAccountDetails(<?php echo $account['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <a href="?edit_account=<?php echo $account['id']; ?>" class="btn btn-sm btn-warning">
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
            </div>

            <!-- Add/Edit Account Tab -->
            <div id="add-account" class="tab-content <?php echo $edit_account ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><?php echo $edit_account ? 'Edit Bank Account' : 'Add New Bank Account'; ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php if ($edit_account): ?>
                                <input type="hidden" name="account_id" value="<?php echo $edit_account['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Account Name *</label>
                                    <input type="text" class="form-control" name="account_name" required
                                           value="<?php echo $edit_account ? safe_display($edit_account['account_name']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Account Number *</label>
                                    <input type="text" class="form-control" name="account_number" required
                                           value="<?php echo $edit_account ? safe_display($edit_account['account_number']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Bank Name *</label>
                                    <input type="text" class="form-control" name="bank_name" required
                                           value="<?php echo $edit_account ? safe_display($edit_account['bank_name']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Account Type *</label>
                                    <select class="form-select" name="account_type" required>
                                        <option value="">Select type</option>
                                        <option value="main" <?php echo ($edit_account && $edit_account['account_type'] == 'main') ? 'selected' : ''; ?>>Main Account</option>
                                        <option value="savings" <?php echo ($edit_account && $edit_account['account_type'] == 'savings') ? 'selected' : ''; ?>>Savings Account</option>
                                        <option value="project" <?php echo ($edit_account && $edit_account['account_type'] == 'project') ? 'selected' : ''; ?>>Project Account</option>
                                        <option value="emergency" <?php echo ($edit_account && $edit_account['account_type'] == 'emergency') ? 'selected' : ''; ?>>Emergency Fund</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Current Balance (RWF)</label>
                                <input type="number" class="form-control" name="current_balance" step="0.01" min="0"
                                       value="<?php echo $edit_account ? $edit_account['current_balance'] : '0'; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Authorized Signatories</label>
                                <div style="border: 1px solid var(--medium-gray); border-radius: var(--border-radius); padding: 0.75rem;">
                                    <?php 
                                    $selected_signatories = [];
                                    if ($edit_account && !empty($edit_account['authorized_signatories'])) {
                                        $selected_signatories = json_decode($edit_account['authorized_signatories'], true);
                                        if (!is_array($selected_signatories)) $selected_signatories = [];
                                    }
                                    ?>
                                    <?php foreach ($authorized_users as $user): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="authorized_signatories[]" 
                                                   value="<?php echo $user['id']; ?>" id="signatory_<?php echo $user['id']; ?>"
                                                   <?php echo in_array($user['id'], $selected_signatories) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="signatory_<?php echo $user['id']; ?>">
                                                <?php echo safe_display($user['full_name']); ?> 
                                                <small style="color: var(--dark-gray);">(<?php echo safe_display($user['role']); ?>)</small>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php if ($edit_account): ?>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                               <?php echo $edit_account['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Account is active</label>
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
                                        <i class="fas fa-redo"></i> Reset
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Balance History Tab -->
            <div id="balance-history" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Balance Update History</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($balance_history)): ?>
                            <div class="empty-state" style="text-align: center; padding: 2rem;">
                                <i class="fas fa-history" style="font-size: 2rem; color: var(--dark-gray); margin-bottom: 0.5rem;"></i>
                                <p>No balance history available</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Account</th>
                                            <th>Previous Balance</th>
                                            <th>New Balance</th>
                                            <th>Change</th>
                                            <th>Updated By</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($balance_history as $history): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y H:i', strtotime($history['created_at'])); ?></td>
                                                <td><?php echo safe_display($history['account_name']); ?></td>
                                                <td class="amount">RWF <?php echo number_format($history['previous_balance'], 0); ?></td>
                                                <td class="amount">RWF <?php echo number_format($history['new_balance'], 0); ?></td>
                                                <td class="amount <?php echo ($history['new_balance'] - $history['previous_balance']) >= 0 ? 'income' : 'expense'; ?>">
                                                    <?php echo ($history['new_balance'] - $history['previous_balance']) >= 0 ? '+' : ''; ?>
                                                    RWF <?php echo number_format($history['new_balance'] - $history['previous_balance'], 0); ?>
                                                </td>
                                                <td><?php echo safe_display($history['updated_by_name']); ?></td>
                                                <td><?php echo safe_display($history['notes'] ?? '—'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- View Account Modal -->
    <div id="viewAccountModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Account Details</h3>
                <button class="modal-close" onclick="closeViewAccountModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewAccountModalBody">
                <div style="text-align: center; padding: 1rem;">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
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
                <button class="modal-close" onclick="closeUpdateBalanceModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="updateBalanceForm">
                    <input type="hidden" name="account_id" id="balance_account_id">
                    <input type="hidden" name="current_balance" id="current_balance_value">
                    
                    <div class="form-group">
                        <label class="form-label">New Balance (RWF) *</label>
                        <input type="number" class="form-control" name="new_balance" id="new_balance" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Update Notes</label>
                        <textarea class="form-control" name="balance_notes" id="balance_notes" rows="3" placeholder="Reason for balance update..."></textarea>
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

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }

        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);

        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }

        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
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

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Account Types Chart
            const accountTypes = <?php echo json_encode($account_types); ?>;
            if (accountTypes.length > 0) {
                const typesCtx = document.getElementById('accountTypesChart').getContext('2d');
                new Chart(typesCtx, {
                    type: 'doughnut',
                    data: {
                        labels: accountTypes.map(t => t.account_type.charAt(0).toUpperCase() + t.account_type.slice(1)),
                        datasets: [{
                            data: accountTypes.map(t => parseFloat(t.total_balance)),
                            backgroundColor: ['#1976D2', '#28a745', '#ffc107', '#dc3545'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.label}: RWF ${context.raw.toLocaleString()}`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Balance Trends Chart
            const balanceTrends = <?php echo json_encode($balance_trends); ?>;
            if (balanceTrends.length > 0) {
                const trendsCtx = document.getElementById('balanceTrendsChart').getContext('2d');
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: balanceTrends.map(t => {
                            const [year, month] = t.month.split('-');
                            return new Date(year, month - 1).toLocaleDateString('en-US', {month: 'short', year: 'numeric'});
                        }),
                        datasets: [{
                            label: 'Total Balance',
                            data: balanceTrends.map(t => parseFloat(t.balance)),
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
            }
        });

        // View Account Details Function
        function viewAccountDetails(accountId) {
            document.getElementById('viewAccountModalBody').innerHTML = `
                <div style="text-align: center; padding: 1rem;">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            `;
            document.getElementById('viewAccountModal').style.display = 'flex';
            
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
                                                <div class="transaction-meta">${transaction.transaction_date}</div>
                                            </div>
                                            <div class="amount ${transaction.transaction_type}">
                                                RWF ${parseFloat(transaction.amount).toLocaleString()}
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
                                    <div class="detail-value">${account.account_type}</div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Current Balance</div>
                                    <div class="detail-value amount">RWF ${parseFloat(account.current_balance).toLocaleString()}</div>
                                </div>
                                <div class="detail-group">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value">${account.is_active ? 'Active' : 'Inactive'}</div>
                                </div>
                            </div>
                            ${transactionsHtml}
                        `;
                    } else {
                        document.getElementById('viewAccountModalBody').innerHTML = `
                            <div class="alert alert-danger">Error: ${data.message}</div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('viewAccountModalBody').innerHTML = `
                        <div class="alert alert-danger">Error loading account details</div>
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
            document.getElementById('updateBalanceModal').style.display = 'flex';
        }

        function closeUpdateBalanceModal() {
            document.getElementById('updateBalanceModal').style.display = 'none';
            document.getElementById('updateBalanceForm').reset();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target === document.getElementById('viewAccountModal')) {
                closeViewAccountModal();
            }
            if (event.target === document.getElementById('updateBalanceModal')) {
                closeUpdateBalanceModal();
            }
        }
    </script>
</body>
</html>