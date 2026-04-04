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
$current_academic_year = getCurrentAcademicYear();

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

// Sidebar badge counts
$pending_approvals = $pending_budget_requests = $pending_aid_requests = 0;
try {
    $r = $pdo->query("SELECT COUNT(*) as c FROM financial_transactions WHERE status = 'approved_by_finance'");
    $pending_approvals = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;

    $r = $pdo->query("SELECT COUNT(*) as c FROM committee_budget_requests WHERE status IN ('submitted','under_review')");
    $pending_budget_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;

    $r = $pdo->query("SELECT COUNT(*) as c FROM student_financial_aid WHERE status IN ('submitted','under_review')");
    $pending_aid_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
} catch (PDOException $e) { /* silent */ }

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

        $stmt = $pdo->prepare("UPDATE rpsu_account SET current_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE is_active = true");
        $stmt->execute([$new_balance]);

        $stmt = $pdo->prepare("INSERT INTO financial_audit_trail (action_type, table_name, record_id, new_values, performed_by, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'BALANCE_UPDATE', 'rpsu_account', 1,
            json_encode(['new_balance' => $new_balance, 'balance_date' => $balance_date, 'notes' => $notes]),
            $user_id, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']
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
    $bank_balance        = $_POST['bank_balance'];
    $system_balance      = $_POST['system_balance'];
    $difference          = $_POST['difference'];
    $reconciliation_notes = trim($_POST['reconciliation_notes']);
    $status              = $_POST['status'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO bank_reconciliations (reconciliation_date, bank_balance, system_balance, difference, notes, status, reconciled_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$reconciliation_date, $bank_balance, $system_balance, $difference, $reconciliation_notes, $status, $user_id]);
        $reconciliation_id = $pdo->lastInsertId();

        if ($status === 'reconciled' && $difference == 0) {
            $stmt = $pdo->prepare("UPDATE rpsu_account SET current_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE is_active = true");
            $stmt->execute([$bank_balance]);
        }

        $stmt = $pdo->prepare("INSERT INTO financial_audit_trail (action_type, table_name, record_id, new_values, performed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'RECONCILIATION', 'bank_reconciliations', $reconciliation_id,
            json_encode(['reconciliation_date' => $reconciliation_date, 'bank_balance' => $bank_balance, 'system_balance' => $system_balance, 'difference' => $difference, 'status' => $status]),
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
    $stmt = $pdo->query("SELECT * FROM rpsu_account WHERE is_active = true LIMIT 1");
    $rpsu_account = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rpsu_account = [];
    error_log("RPSU account error: " . $e->getMessage());
}

// Recent transactions (last 30 days) — PostgreSQL compatible
try {
    $stmt = $pdo->query("
        SELECT ft.*, bc.category_name,
               u_req.full_name as requested_by_name,
               u_finance.full_name as approved_by_finance_name,
               u_president.full_name as approved_by_president_name
        FROM financial_transactions ft
        LEFT JOIN budget_categories bc ON ft.category_id = bc.id
        LEFT JOIN users u_req ON ft.requested_by = u_req.id
        LEFT JOIN users u_finance ON ft.approved_by_finance = u_finance.id
        LEFT JOIN users u_president ON ft.approved_by_president = u_president.id
        WHERE ft.transaction_date >= CURRENT_DATE - INTERVAL '30 days'
        AND ft.status = 'completed'
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
    ");
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_transactions = [];
    error_log("Recent transactions error: " . $e->getMessage());
}

// Reconciliation history
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

// Statistics — PostgreSQL compatible
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_transactions,
            COALESCE(SUM(CASE WHEN transaction_type = 'income'  THEN amount ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expenses
        FROM financial_transactions
        WHERE EXTRACT(MONTH FROM transaction_date) = EXTRACT(MONTH FROM CURRENT_DATE)
          AND EXTRACT(YEAR  FROM transaction_date) = EXTRACT(YEAR  FROM CURRENT_DATE)
          AND status = 'completed'
    ");
    $monthly_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT COUNT(*) as pending_count
        FROM financial_transactions
        WHERE status IN ('pending_approval','approved_by_finance')
    ");
    $pending_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT COUNT(*) as total_reconciliations,
               MAX(reconciliation_date) as last_reconciliation
        FROM bank_reconciliations
        WHERE status = 'reconciled'
    ");
    $reconciliation_stats = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $monthly_stats = $pending_stats = $reconciliation_stats = [];
    error_log("Reconciliation statistics error: " . $e->getMessage());
}

$system_expected_balance = $rpsu_account['current_balance'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Bank Reconciliation - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
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
            --info: #17a2b8;
            --finance-primary: #1976D2;
            --finance-secondary: #2196F3;
            --finance-accent: #0D47A1;
            --finance-light: #E3F2FD;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 2px 8px rgba(0,0,0,0.12);
            --shadow-lg: 0 4px 16px rgba(0,0,0,0.15);
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
            --info: #4dd0e1;
            --finance-primary: #2196F3;
            --finance-secondary: #64B5F6;
            --finance-accent: #1976D2;
            --finance-light: #0D1B2A;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* ── Header ── */
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

        .logo-section { display: flex; align-items: center; gap: 0.75rem; }
        .logo { height: 40px; width: auto; }

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
        }

        .user-menu { display: flex; align-items: center; gap: 1rem; }
        .header-actions { display: flex; align-items: center; gap: 0.75rem; }
        .user-info { display: flex; align-items: center; gap: 0.75rem; }

        .user-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 1rem;
            overflow: hidden;
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; font-size: 0.9rem; }
        .user-role { font-size: 0.75rem; color: var(--dark-gray); }

        .icon-btn {
            width: 40px; height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex; align-items: center; justify-content: center;
            position: relative; text-decoration: none; font-size: 0.95rem;
        }

        .icon-btn:hover { background: var(--finance-primary); color: white; border-color: var(--finance-primary); }

        .notification-badge {
            position: absolute; top: -2px; right: -2px;
            background: var(--danger); color: white;
            border-radius: 50%; width: 18px; height: 18px;
            font-size: 0.6rem; display: flex; align-items: center; justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary); color: white;
            padding: 0.5rem 1rem; border-radius: 6px;
            text-decoration: none; font-size: 0.85rem; font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }

        /* ── Layout ── */
        .dashboard-container { display: flex; min-height: calc(100vh - 73px); }

        /* ── Sidebar ── */
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

        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge { display: none; }
        .sidebar.collapsed .menu-item a { justify-content: center; padding: 0.75rem; }
        .sidebar.collapsed .menu-item i { margin: 0; font-size: 1.25rem; }

        .sidebar-toggle {
            position: absolute; right: -12px; top: 20px;
            width: 24px; height: 24px;
            background: var(--finance-primary); border: none; border-radius: 50%;
            color: white; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; z-index: 100;
        }

        .sidebar-menu { list-style: none; }
        .menu-item { margin-bottom: 0.25rem; }

        .menu-item a {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-dark); text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent; font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
        }

        .menu-item i { width: 20px; }

        .menu-badge {
            background: var(--danger); color: white;
            border-radius: 10px; padding: 0.1rem 0.4rem;
            font-size: 0.7rem; font-weight: 600; margin-left: auto;
        }

        /* ── Main Content ── */
        .main-content {
            flex: 1; padding: 1.5rem; overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        .dashboard-header { margin-bottom: 1.5rem; }
        .welcome-section h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; color: var(--text-dark); }
        .welcome-section p { color: var(--dark-gray); font-size: 0.9rem; }

        /* ── Stats Grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white); padding: 1rem;
            border-radius: var(--border-radius); box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
            display: flex; align-items: center; gap: 1rem;
        }

        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger  { border-left-color: var(--danger); }

        .stat-icon {
            width: 45px; height: 45px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
            background: var(--finance-light); color: var(--finance-primary);
        }

        .stat-card.success .stat-icon { background: #d4edda; color: var(--success); }
        .stat-card.warning .stat-icon { background: #fff3cd; color: var(--warning); }
        .stat-card.danger  .stat-icon { background: #f8d7da; color: var(--danger); }

        .stat-content { flex: 1; }
        .stat-number { font-size: 1.3rem; font-weight: 700; margin-bottom: 0.25rem; color: var(--text-dark); }
        .stat-label { color: var(--dark-gray); font-size: 0.75rem; font-weight: 500; }
        .stat-trend { display: flex; align-items: center; gap: 0.25rem; font-size: 0.7rem; font-weight: 600; margin-top: 0.25rem; }
        .trend-positive { color: var(--success); }
        .trend-negative { color: var(--danger); }

        /* ── Cards ── */
        .card {
            background: var(--white); border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.25rem; border-bottom: 1px solid var(--medium-gray);
            display: flex; justify-content: space-between; align-items: center;
            background: var(--finance-light);
        }

        .card-header h3 { font-size: 1rem; font-weight: 600; color: var(--text-dark); }
        .card-header h4 { font-size: 0.9rem; font-weight: 600; color: var(--text-dark); }
        .card-header-actions { display: flex; gap: 0.5rem; }

        .card-header-btn {
            background: none; border: none; color: var(--dark-gray);
            cursor: pointer; padding: 0.25rem; border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover { background: var(--light-gray); color: var(--text-dark); }
        .card-body { padding: 1.25rem; }

        /* ── Balance Comparison ── */
        .balance-comparison {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }

        .balance-card {
            background: var(--white); padding: 1.5rem;
            border-radius: var(--border-radius); box-shadow: var(--shadow-sm);
            text-align: center; border-top: 4px solid var(--finance-primary);
            transition: var(--transition);
        }

        .balance-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .balance-card.system  { border-top-color: var(--success); }
        .balance-card.bank    { border-top-color: var(--warning); }
        .balance-card.difference { border-top-color: var(--danger); }

        .balance-label { font-size: 0.78rem; color: var(--dark-gray); margin-bottom: 0.5rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; }
        .balance-amount { font-size: 1.35rem; font-weight: 700; color: var(--text-dark); font-family: 'Courier New', monospace; }
        .balance-sub { font-size: 0.72rem; color: var(--dark-gray); margin-top: 0.4rem; }

        /* ── Reconciliation Steps ── */
        .reconciliation-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }

        .step-card {
            background: var(--white); padding: 1.25rem;
            border-radius: var(--border-radius); box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
        }

        .step-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        .step-number {
            width: 28px; height: 28px;
            background: var(--finance-primary); color: white;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.8rem; margin-bottom: 0.75rem;
        }

        .step-title { font-weight: 600; margin-bottom: 0.4rem; color: var(--text-dark); font-size: 0.9rem; }
        .step-description { font-size: 0.78rem; color: var(--dark-gray); line-height: 1.5; }

        /* ── Tables ── */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        .table { width: 100%; border-collapse: collapse; font-size: 0.8rem; white-space: nowrap; }

        .table th, .table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--medium-gray); }

        .table th {
            background: var(--light-gray); font-weight: 600; color: var(--text-dark);
            font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        }

        .table tbody tr:hover { background: var(--finance-light); }

        .amount { font-weight: 600; font-family: 'Courier New', monospace; }
        .amount.income { color: var(--success); }
        .amount.expense { color: var(--danger); }

        /* ── Status Badges ── */
        .status-badge {
            padding: 0.2rem 0.55rem; border-radius: 20px;
            font-size: 0.68rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.03em;
        }

        .status-reconciled   { background: #d4edda; color: #155724; }
        .status-pending      { background: #fff3cd; color: #856404; }
        .status-discrepancy  { background: #f8d7da; color: #721c24; }
        .status-income       { background: #d4edda; color: var(--success); }
        .status-expense      { background: #f8d7da; color: var(--danger); }
        .status-completed    { background: #d4edda; color: #155724; }

        /* ── Buttons ── */
        .btn {
            padding: 0.65rem 1.25rem; border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem; font-weight: 600; cursor: pointer;
            transition: var(--transition); text-decoration: none;
            display: inline-flex; align-items: center; gap: 0.5rem;
            font-family: inherit;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--finance-primary); color: white; }
        .btn-primary:hover { background: var(--finance-accent); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: var(--text-dark); }
        .btn-outline { background: transparent; border: 1px solid var(--medium-gray); color: var(--text-dark); }
        .btn-outline:hover { background: var(--light-gray); }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; }

        /* ── Quick Actions bar ── */
        .quick-actions-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; }

        /* ── Forms ── */
        .form-group { margin-bottom: 1rem; }

        .form-label { display: block; margin-bottom: 0.45rem; font-weight: 600; color: var(--text-dark); font-size: 0.8rem; }

        .form-control, .form-select {
            width: 100%; padding: 0.65rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white); color: var(--text-dark);
            font-size: 0.85rem; transition: var(--transition); font-family: inherit;
        }

        .form-control:focus, .form-select:focus {
            outline: none; border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25,118,210,0.1);
        }

        .form-control[readonly] { background: var(--light-gray); cursor: not-allowed; }

        /* ── Alerts ── */
        .alert {
            padding: 0.75rem 1rem; border-radius: var(--border-radius);
            margin-bottom: 1rem; border-left: 4px solid;
            font-size: 0.85rem; display: flex; align-items: flex-start; gap: 0.5rem;
        }

        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-warning { background: #fff3cd; color: #856404; border-left-color: var(--warning); }
        .alert-error   { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }

        /* ── Empty State ── */
        .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--dark-gray); }
        .empty-state i { font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.4; display: block; }
        .empty-state p { font-size: 0.85rem; }

        /* ── Modals ── */
        .modal {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.55); z-index: 1000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal.active { display: flex; }

        .modal-content {
            background: var(--white); border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            width: 92%; max-width: 520px; max-height: 90vh; overflow-y: auto;
        }

        .modal-content.wide { max-width: 760px; }

        .modal-header {
            padding: 1rem 1.5rem; border-bottom: 1px solid var(--medium-gray);
            display: flex; justify-content: space-between; align-items: center;
            background: var(--finance-light);
        }

        .modal-header h3 { font-size: 1.05rem; font-weight: 600; }

        .modal-body { padding: 1.5rem; }

        .modal-footer {
            padding: 1rem 1.5rem; border-top: 1px solid var(--medium-gray);
            display: flex; justify-content: flex-end; gap: 0.5rem;
        }

        .close {
            background: none; border: none; font-size: 1.4rem;
            cursor: pointer; color: var(--dark-gray); line-height: 1;
            transition: var(--transition);
        }

        .close:hover { color: var(--danger); }

        /* ── Difference message ── */
        #differenceMessage {
            display: block; margin-top: 0.4rem;
            font-size: 0.8rem; font-weight: 500;
        }

        /* ── Mobile overlay ── */
        .overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); backdrop-filter: blur(2px); z-index: 98;
        }

        .overlay.active { display: block; }

        /* ── Responsive ── */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%); position: fixed;
                top: 0; height: 100vh; z-index: 1000; padding-top: 1rem;
            }

            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle { display: none; }
            .main-content { margin-left: 0 !important; }

            .mobile-menu-toggle {
                display: flex; align-items: center; justify-content: center;
                width: 40px; height: 40px; border-radius: 50%;
                background: var(--light-gray); transition: var(--transition);
            }

            .mobile-menu-toggle:hover { background: var(--finance-primary); color: white; }
            #sidebarToggleBtn { display: none; }
        }

        @media (max-width: 768px) {
            .nav-container { padding: 0 1rem; gap: 0.5rem; }
            .brand-text h1 { font-size: 1rem; }
            .user-details { display: none; }
            .main-content { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .balance-comparison, .reconciliation-steps { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .main-content { padding: 0.75rem; }
            .logo { height: 32px; }
            .brand-text h1 { font-size: 0.9rem; }
            .stat-card { padding: 0.75rem; }
            .stat-number { font-size: 1.1rem; }
        }
    </style>
</head>
<body>
    <!-- Mobile overlay -->
    <div class="overlay" id="mobileOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Finance</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="budget_management.php">
                        <i class="fas fa-money-bill-wave"></i><span>Budget Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i><span>Transactions</span>
                        <?php if ($pending_approvals > 0): ?>
                            <span class="menu-badge"><?php echo $pending_approvals; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i><span>Committee Requests</span>
                        <?php if ($pending_budget_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_budget_requests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i><span>Student Financial Aid</span>
                        <?php if ($pending_aid_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_aid_requests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="rental_management.php">
                        <i class="fas fa-home"></i><span>Rental Properties</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="allowances.php">
                        <i class="fas fa-money-check"></i><span>Allowances</span>
                    </a>
                </li>
                 <li class="menu-item">
                    <a href="accounts.php" >
                        <i class="fas fa-piggy-bank"></i>
                        <span>Bank Accounts</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="bank_reconciliation.php" class="active">
                        <i class="fas fa-university"></i><span>Bank Reconciliation</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-bar"></i><span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i><span>Official Documents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i><span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i><span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i><span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Bank Reconciliation</h1>
                </div>
            </div>

            <!-- Flash messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-university"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($rpsu_account['current_balance'] ?? 0, 0); ?></div>
                        <div class="stat-label">Current System Balance</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-sync-alt"></i>
                            Updated <?php echo $rpsu_account ? date('M j', strtotime($rpsu_account['updated_at'])) : 'Never'; ?>
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $monthly_stats['total_transactions'] ?? 0; ?></div>
                        <div class="stat-label">Transactions This Month</div>
                        <div class="stat-trend trend-positive"><i class="fas fa-chart-line"></i> Completed</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_stats['pending_count'] ?? 0; ?></div>
                        <div class="stat-label">Pending Approvals</div>
                        <div class="stat-trend trend-negative"><i class="fas fa-exclamation-circle"></i> Needs Attention</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $reconciliation_stats['total_reconciliations'] ?? 0; ?></div>
                        <div class="stat-label">Total Reconciliations</div>
                        <?php if (!empty($reconciliation_stats['last_reconciliation'])): ?>
                            <div class="stat-trend trend-positive">
                                <i class="fas fa-check-circle"></i>
                                Last: <?php echo date('M j, Y', strtotime($reconciliation_stats['last_reconciliation'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt" style="color:var(--finance-primary);margin-right:.5rem;"></i>Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="quick-actions-bar">
                        <button class="btn btn-primary" onclick="openModal('updateBalanceModal')">
                            <i class="fas fa-edit"></i> Update Bank Balance
                        </button>
                        <button class="btn btn-success" onclick="openModal('reconcileModal')">
                            <i class="fas fa-sync-alt"></i> Perform Reconciliation
                        </button>
                        <button class="btn btn-warning" onclick="openModal('viewStatementModal')">
                            <i class="fas fa-file-invoice"></i> View Statement Summary
                        </button>
                        <a href="financial_reports.php" class="btn btn-outline">
                            <i class="fas fa-download"></i> Reconciliation Report
                        </a>
                    </div>
                </div>
            </div>

            <!-- Balance Comparison -->
            <div class="balance-comparison">
                <div class="balance-card system">
                    <div class="balance-label">System Balance</div>
                    <div class="balance-amount">RWF <?php echo number_format($system_expected_balance, 0); ?></div>
                    <div class="balance-sub">
                        Last updated: <?php echo $rpsu_account ? date('M j, Y', strtotime($rpsu_account['updated_at'])) : 'Never'; ?>
                    </div>
                </div>
                <div class="balance-card bank">
                    <div class="balance-label">Bank Statement Balance</div>
                    <div class="balance-amount" id="bankBalanceDisplay">—</div>
                    <div class="balance-sub">Enter current bank balance above</div>
                </div>
                <div class="balance-card difference">
                    <div class="balance-label">Difference</div>
                    <div class="balance-amount" id="differenceDisplay">—</div>
                    <div class="balance-sub">Should be 0 when reconciled</div>
                </div>
            </div>

            <!-- Reconciliation History -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history" style="color:var(--finance-primary);margin-right:.5rem;"></i>Recent Reconciliation History</h3>
                    <div class="card-header-actions">
                        <button class="card-header-btn" title="Refresh" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
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
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fas fa-clipboard-check"></i>
                                                <p>No reconciliation history yet. Perform your first reconciliation above.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($reconciliation_history as $r): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($r['reconciliation_date'])); ?></td>
                                            <td class="amount">RWF <?php echo number_format($r['bank_balance'], 0); ?></td>
                                            <td class="amount">RWF <?php echo number_format($r['system_balance'], 0); ?></td>
                                            <td class="amount <?php echo $r['difference'] == 0 ? 'income' : 'expense'; ?>">
                                                RWF <?php echo number_format(abs($r['difference']), 0); ?>
                                                <?php if ($r['difference'] != 0): ?>
                                                    <br><small style="color:var(--danger);font-size:0.68rem;">Discrepancy</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $r['status']; ?>">
                                                    <?php echo ucfirst($r['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($r['reconciled_by_name'] ?? '—'); ?></td>
                                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                title="<?php echo htmlspecialchars($r['notes'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($r['notes'] ?? '—'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list-alt" style="color:var(--finance-primary);margin-right:.5rem;"></i>Recent Transactions (Last 30 Days)</h3>
                    <div class="card-header-actions">
                        <a href="financial_reports.php" class="card-header-btn" title="Export">
                            <i class="fas fa-download"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
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
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fas fa-inbox"></i>
                                                <p>No completed transactions in the last 30 days.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_transactions as $t): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($t['transaction_date'])); ?></td>
                                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                title="<?php echo htmlspecialchars($t['description']); ?>">
                                                <?php echo htmlspecialchars($t['description']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($t['category_name'] ?? '—'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $t['transaction_type']; ?>">
                                                    <?php echo ucfirst($t['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td class="amount <?php echo $t['transaction_type']; ?>">
                                                RWF <?php echo number_format($t['amount'], 0); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($t['reference_number'] ?? '—'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $t['status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $t['status'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ═══ Update Balance Modal ═══ -->
    <div id="updateBalanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color:var(--finance-primary);margin-right:.4rem;"></i>Update Bank Balance</h3>
                <button class="close" onclick="closeModal('updateBalanceModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="updateBalanceForm">
                    <input type="hidden" name="action" value="update_balance">
                    <div class="form-group">
                        <label class="form-label">Current System Balance</label>
                        <input type="text" class="form-control" value="RWF <?php echo number_format($system_expected_balance, 0); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Bank Balance (RWF) *</label>
                        <input type="number" class="form-control" name="new_balance" step="0.01" min="0" required placeholder="Enter actual bank balance">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Balance Date *</label>
                        <input type="date" class="form-control" name="balance_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Reason for balance update..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('updateBalanceModal')">Cancel</button>
                <button type="submit" form="updateBalanceForm" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Balance
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ Reconcile Modal ═══ -->
    <div id="reconcileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sync-alt" style="color:var(--success);margin-right:.4rem;"></i>Perform Bank Reconciliation</h3>
                <button class="close" onclick="closeModal('reconcileModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="reconcileForm">
                    <input type="hidden" name="action" value="reconcile">
                    <div class="form-group">
                        <label class="form-label">Reconciliation Date *</label>
                        <input type="date" class="form-control" name="reconciliation_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">System Balance (RWF)</label>
                        <input type="number" class="form-control" name="system_balance" value="<?php echo $system_expected_balance; ?>" step="0.01" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bank Statement Balance (RWF) *</label>
                        <input type="number" class="form-control" name="bank_balance" id="bankBalanceInput" step="0.01" required oninput="calculateDifference()" placeholder="Enter bank statement amount">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Difference (RWF)</label>
                        <input type="number" class="form-control" name="difference" id="differenceInput" step="0.01" readonly placeholder="Auto-calculated">
                        <small id="differenceMessage"></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reconciliation Status *</label>
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
                <button type="button" class="btn btn-outline" onclick="closeModal('reconcileModal')">Cancel</button>
                <button type="submit" form="reconcileForm" class="btn btn-primary">
                    <i class="fas fa-check"></i> Save Reconciliation
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ View Statement Modal ═══ -->
    <div id="viewStatementModal" class="modal">
        <div class="modal-content wide">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice" style="color:var(--finance-primary);margin-right:.4rem;"></i>Recent Bank Statement Summary</h3>
                <button class="close" onclick="closeModal('viewStatementModal')">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Account Info -->
                <div class="card" style="margin-bottom:1rem;">
                    <div class="card-header"><h4>Account Information</h4></div>
                    <div class="card-body">
                        <?php if ($rpsu_account): ?>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;font-size:0.85rem;">
                                <div><strong>Account Name:</strong><br><?php echo htmlspecialchars($rpsu_account['account_name']); ?></div>
                                <div><strong>Account Number:</strong><br><?php echo htmlspecialchars($rpsu_account['account_number']); ?></div>
                                <div><strong>Bank:</strong><br><?php echo htmlspecialchars($rpsu_account['bank_name']); ?></div>
                                <div><strong>Branch:</strong><br><?php echo htmlspecialchars($rpsu_account['branch_name']); ?></div>
                            </div>
                        <?php else: ?>
                            <p style="text-align:center;color:var(--dark-gray);">No bank account configured.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Summary -->
                <div class="card">
                    <div class="card-header"><h4>This Month's Summary</h4></div>
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;font-size:0.85rem;">
                            <div>
                                <strong>Total Income:</strong><br>
                                <span class="amount income">RWF <?php echo number_format($monthly_stats['total_income'] ?? 0, 0); ?></span>
                            </div>
                            <div>
                                <strong>Total Expenses:</strong><br>
                                <span class="amount expense">RWF <?php echo number_format($monthly_stats['total_expenses'] ?? 0, 0); ?></span>
                            </div>
                            <?php $net = ($monthly_stats['total_income'] ?? 0) - ($monthly_stats['total_expenses'] ?? 0); ?>
                            <div>
                                <strong>Net Flow:</strong><br>
                                <span class="amount <?php echo $net >= 0 ? 'income' : 'expense'; ?>">
                                    RWF <?php echo number_format($net, 0); ?>
                                </span>
                            </div>
                            <div>
                                <strong>Transactions:</strong><br>
                                <?php echo $monthly_stats['total_transactions'] ?? 0; ?> completed
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewStatementModal')">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Summary
                </button>
            </div>
        </div>
    </div>

    <script>
        
        // ── Sidebar Collapse ──
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

        // ── Mobile Menu ──
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay    = document.getElementById('mobileOverlay');

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

        // ── Modals ──
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            if (id === 'reconcileModal') {
                document.getElementById('bankBalanceInput').value = '';
                document.getElementById('differenceInput').value = '';
                document.getElementById('differenceMessage').textContent = '';
                document.getElementById('differenceMessage').style.color = '';
                document.getElementById('reconciliationStatus').value = 'pending';
                document.getElementById('reconciliationNotes').value = '';
            }
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) closeModal(this.id);
            });
        });

        // Escape key closes modals
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
            }
        });

        // ── Reconciliation Logic ──
        const systemBalance = <?php echo json_encode((float) $system_expected_balance); ?>;

        function calculateDifference() {
            const bankBalance = parseFloat(document.getElementById('bankBalanceInput').value) || 0;
            const difference  = bankBalance - systemBalance;

            document.getElementById('differenceInput').value = difference.toFixed(2);

            // Update live balance comparison cards
            document.getElementById('bankBalanceDisplay').textContent =
                'RWF ' + bankBalance.toLocaleString('en-US', { minimumFractionDigits: 0 });
            document.getElementById('differenceDisplay').textContent =
                'RWF ' + Math.abs(difference).toLocaleString('en-US', { minimumFractionDigits: 0 });

            const msg = document.getElementById('differenceMessage');
            if (difference === 0) {
                msg.textContent = '✓ Perfect match — accounts are reconciled.';
                msg.style.color = 'var(--success)';
                document.getElementById('reconciliationStatus').value = 'reconciled';
            } else if (difference > 0) {
                msg.textContent = '↑ Bank balance is higher than system balance.';
                msg.style.color = 'var(--warning)';
                document.getElementById('reconciliationStatus').value = 'discrepancy';
            } else {
                msg.textContent = '↓ System balance is higher than bank balance.';
                msg.style.color = 'var(--danger)';
                document.getElementById('reconciliationStatus').value = 'discrepancy';
            }

            updateStatusMessage();
        }

        function updateStatusMessage() {
            const status     = document.getElementById('reconciliationStatus').value;
            const difference = parseFloat(document.getElementById('differenceInput').value) || 0;
            const notes      = document.getElementById('reconciliationNotes');

            switch (status) {
                case 'reconciled':
                    notes.value = difference !== 0
                        ? 'Accounts reconciled after adjusting for differences.'
                        : 'Accounts perfectly reconciled with no differences.';
                    break;
                case 'discrepancy':
                    notes.value = 'Discrepancy found between bank and system records. Investigation needed.';
                    break;
                case 'pending':
                    notes.value = 'Reconciliation pending review and verification.';
                    break;
            }
        }

        // Set today's date on all empty date inputs
        document.addEventListener('DOMContentLoaded', () => {
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) input.value = today;
            });
        });
    </script>
</body>
</html>