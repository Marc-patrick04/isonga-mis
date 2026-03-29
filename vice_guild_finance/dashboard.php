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

$current_academic_year = getCurrentAcademicYear();

// Get RPSU main account details
try {
    $stmt = $pdo->query("SELECT * FROM rpsu_account WHERE is_active = true LIMIT 1");
    $rpsu_account = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rpsu_account = [];
    error_log("RPSU account error: " . $e->getMessage());
}

// Get comprehensive financial statistics (PostgreSQL compatible)
try {
    // Total budget for current academic year
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(allocated_amount), 0) as total_budget 
        FROM monthly_budgets 
        WHERE academic_year = ?
    ");
    $stmt->execute([$current_academic_year]);
    $total_budget = $stmt->fetch(PDO::FETCH_ASSOC)['total_budget'] ?? 0;

    // Total expenses (approved and completed)
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(amount), 0) as total_expenses 
        FROM financial_transactions 
        WHERE transaction_type = 'expense' 
        AND status = 'completed'
    ");
    $total_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total_expenses'] ?? 0;

    // Total income
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(amount), 0) as total_income 
        FROM financial_transactions 
        WHERE transaction_type = 'income' 
        AND status = 'completed'
    ");
    $total_income = $stmt->fetch(PDO::FETCH_ASSOC)['total_income'] ?? 0;

    // Pending approvals (needs president approval)
    $stmt = $pdo->query("
        SELECT COUNT(*) as pending_approvals 
        FROM financial_transactions 
        WHERE status = 'approved_by_finance'
    ");
    $pending_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['pending_approvals'] ?? 0;

    // Pending budget requests from committees
    $stmt = $pdo->query("
        SELECT COUNT(*) as pending_budget_requests 
        FROM committee_budget_requests 
        WHERE status IN ('submitted', 'under_review')
    ");
    $pending_budget_requests = $stmt->fetch(PDO::FETCH_ASSOC)['pending_budget_requests'] ?? 0;

    // Pending student financial aid requests
    $stmt = $pdo->query("
        SELECT COUNT(*) as pending_aid_requests 
        FROM student_financial_aid 
        WHERE status IN ('submitted', 'under_review')
    ");
    $pending_aid_requests = $stmt->fetch(PDO::FETCH_ASSOC)['pending_aid_requests'] ?? 0;

    // Rental income this month (PostgreSQL compatible)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as rental_income 
        FROM rental_payments 
        WHERE EXTRACT(MONTH FROM payment_date) = EXTRACT(MONTH FROM CURRENT_DATE)
        AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)
        AND status = 'verified'
    ");
    $stmt->execute();
    $rental_income = $stmt->fetch(PDO::FETCH_ASSOC)['rental_income'] ?? 0;

    // Monthly expense trends (PostgreSQL compatible)
    $stmt = $pdo->prepare("
        SELECT 
            TO_CHAR(transaction_date, 'YYYY-MM') as month,
            COALESCE(SUM(amount), 0) as monthly_expenses,
            COUNT(*) as transaction_count
        FROM financial_transactions 
        WHERE transaction_type = 'expense' 
        AND status = 'completed'
        AND transaction_date >= CURRENT_DATE - INTERVAL '6 months'
        GROUP BY TO_CHAR(transaction_date, 'YYYY-MM')
        ORDER BY month DESC
        LIMIT 6
    ");
    $stmt->execute();
    $expense_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Reverse to show chronological order
    $expense_trends = array_reverse($expense_trends);

    // Budget utilization by category (PostgreSQL compatible)
    $stmt = $pdo->prepare("
        SELECT 
            bc.category_name,
            COALESCE(SUM(mb.allocated_amount), 0) as allocated_amount,
            COALESCE((
                SELECT SUM(amount) 
                FROM financial_transactions ft 
                WHERE ft.category_id = bc.id 
                AND ft.transaction_type = 'expense' 
                AND ft.status = 'completed'
                AND ft.transaction_date >= DATE_TRUNC('month', CURRENT_DATE)
                AND ft.transaction_date < DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
            ), 0) as spent_amount,
            COALESCE(SUM(mb.allocated_amount), 0) - COALESCE((
                SELECT SUM(amount) 
                FROM financial_transactions ft 
                WHERE ft.category_id = bc.id 
                AND ft.transaction_type = 'expense' 
                AND ft.status = 'completed'
                AND ft.transaction_date >= DATE_TRUNC('month', CURRENT_DATE)
                AND ft.transaction_date < DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
            ), 0) as remaining_amount,
            CASE 
                WHEN COALESCE(SUM(mb.allocated_amount), 0) > 0 THEN 
                    ROUND((COALESCE((
                        SELECT SUM(amount) 
                        FROM financial_transactions ft 
                        WHERE ft.category_id = bc.id 
                        AND ft.transaction_type = 'expense' 
                        AND ft.status = 'completed'
                        AND ft.transaction_date >= DATE_TRUNC('month', CURRENT_DATE)
                        AND ft.transaction_date < DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
                    ), 0) / COALESCE(SUM(mb.allocated_amount), 0)) * 100, 2)
                ELSE 0 
            END as utilization_rate
        FROM budget_categories bc
        LEFT JOIN monthly_budgets mb ON bc.id = mb.category_id AND mb.academic_year = ?
        WHERE bc.category_type = 'expense'
        AND bc.is_active = true
        GROUP BY bc.id, bc.category_name
        HAVING COALESCE(SUM(mb.allocated_amount), 0) > 0
        ORDER BY spent_amount DESC
        LIMIT 5
    ");
    $stmt->execute([$current_academic_year]);
    $budget_utilization = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent transactions (PostgreSQL compatible)
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
        ORDER BY ft.created_at DESC 
        LIMIT 10
    ");
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming rental payments due (PostgreSQL compatible)
    $stmt = $pdo->query("
        SELECT rp.property_name, rp.tenant_name, rp.monthly_rent,
               COALESCE(MAX(rpm.payment_date) + INTERVAL '1 month', rp.lease_start_date) as next_payment_due
        FROM rental_properties rp
        LEFT JOIN rental_payments rpm ON rp.id = rpm.property_id
        WHERE rp.is_active = true
        GROUP BY rp.id, rp.property_name, rp.tenant_name, rp.monthly_rent, rp.lease_start_date
        HAVING COALESCE(MAX(rpm.payment_date) + INTERVAL '1 month', rp.lease_start_date) <= CURRENT_DATE + INTERVAL '15 days'
        OR MAX(rpm.payment_date) IS NULL
        LIMIT 5
    ");
    $upcoming_rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent student aid requests
    $stmt = $pdo->query("
        SELECT sfa.*, u.full_name as student_name
        FROM student_financial_aid sfa
        LEFT JOIN users u ON sfa.student_id = u.id
        ORDER BY sfa.created_at DESC
        LIMIT 5
    ");
    $recent_aid_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    } catch (PDOException $e) {
        $unread_messages = 0;
    }

} catch (PDOException $e) {
    error_log("Financial stats error: " . $e->getMessage());
    // Set default values
    $total_budget = $total_expenses = $total_income = $pending_approvals = 0;
    $pending_budget_requests = $pending_aid_requests = $rental_income = $unread_messages = 0;
    $expense_trends = $budget_utilization = $recent_transactions = [];
    $upcoming_rentals = $recent_aid_requests = [];
}

// Calculate additional metrics
$available_balance = $rpsu_account['current_balance'] ?? 0;
$utilization_percentage = $total_budget > 0 ? round(($total_expenses / $total_budget) * 100, 1) : 0;
$net_cash_flow = $total_income - $total_expenses;

// Financial health indicators
$financial_health = 'excellent';
if ($utilization_percentage > 90) {
    $financial_health = 'critical';
} elseif ($utilization_percentage > 75) {
    $financial_health = 'warning';
} elseif ($available_balance < 100000) {
    $financial_health = 'warning';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Finance Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --info: #4dd0e1;
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
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
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

        /* Dashboard Header */
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
            font-size: 1.4rem;
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
            font-size: 0.7rem;
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

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
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

        .table tbody tr:hover {
            background: var(--finance-light);
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

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-pending_approval {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved_by_finance {
            background: #cce5ff;
            color: #004085;
        }

        .status-approved_by_president {
            background: #d4edda;
            color: var(--success);
        }

        .status-rejected {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-draft {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .status-submitted {
            background: #fff3cd;
            color: #856404;
        }

        .status-under_review {
            background: #cce5ff;
            color: #004085;
        }

        .status-verified {
            background: #d4edda;
            color: var(--success);
        }

        .status-disputed {
            background: #f8d7da;
            color: var(--danger);
        }

        /* Budget Items */
        .budget-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .budget-item:last-child {
            border-bottom: none;
        }

        .budget-info {
            flex: 1;
        }

        .budget-category {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .budget-amounts {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--dark-gray);
            flex-wrap: wrap;
        }

        .budget-progress {
            width: 100px;
        }

        .progress-bar {
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-low {
            background: var(--success);
        }

        .progress-medium {
            background: var(--warning);
        }

        .progress-high {
            background: var(--danger);
        }

        .progress-text {
            font-size: 0.65rem;
            color: var(--dark-gray);
            text-align: center;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-title {
            font-size: 0.9rem;
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
            margin-bottom: 0.5rem;
            color: var(--finance-primary);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.7rem;
        }

        /* Meeting/Item Items */
        .meeting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .meeting-item:last-child {
            border-bottom: none;
        }

        .meeting-info h4 {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }

        .meeting-info p {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .meeting-date {
            font-weight: 600;
            color: var(--text-dark);
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        /* Account Items */
        .account-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .account-item:last-child {
            border-bottom: none;
        }

        .account-info h4 {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }

        .account-info p {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .account-balance {
            font-weight: 700;
            color: var(--text-dark);
            font-family: 'Courier New', monospace;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert a {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .main-content.sidebar-collapsed {
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }
            
            .overlay.active {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .main-content {
                padding: 1rem;
            }
            
            .budget-amounts {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .budget-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .budget-progress {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Finance</h1>
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
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="active">
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
                    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 💰</h1>
                    <p>Complete financial oversight and management for <?php echo $current_academic_year; ?> academic year</p>
                </div>
            </div>

            <!-- Financial Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_budget, 0); ?></div>
                        <div class="stat-label">Total Budget</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-arrow-up"></i> <?php echo $current_academic_year; ?>
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_expenses, 0); ?></div>
                        <div class="stat-label">Total Expenses</div>
                        <div class="stat-trend <?php echo $utilization_percentage <= 80 ? 'trend-positive' : 'trend-negative'; ?>">
                            <i class="fas fa-chart-line"></i> <?php echo $utilization_percentage; ?>% of budget
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_income, 0); ?></div>
                        <div class="stat-label">Total Income</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-arrow-up"></i> All time
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
                        <div class="stat-trend <?php echo $pending_approvals > 0 ? 'trend-negative' : 'trend-positive'; ?>">
                            <i class="fas fa-exclamation-circle"></i> Needs attention
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics -->
            <div class="stats-grid">
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($rental_income, 0); ?></div>
                        <div class="stat-label">Rental Income (This Month)</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_budget_requests; ?></div>
                        <div class="stat-label">Committee Budget Requests</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_aid_requests; ?></div>
                        <div class="stat-label">Student Aid Requests</div>
                    </div>
                </div>
                <div class="stat-card <?php echo $available_balance > 500000 ? 'success' : 'warning'; ?>">
                    <div class="stat-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($available_balance, 0); ?></div>
                        <div class="stat-label">Bank Balance</div>
                        <div class="stat-trend <?php echo $available_balance > 500000 ? 'trend-positive' : 'trend-negative'; ?>">
                            <i class="fas fa-<?php echo $available_balance > 500000 ? 'check' : 'exclamation'; ?>-circle"></i>
                            <?php echo $available_balance > 500000 ? 'Healthy' : 'Low Balance'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <!-- Budget Utilization Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Budget Utilization by Category</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="budgetChart"></canvas>
                    </div>
                </div>

                <!-- Expense Trends Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Monthly Expense Trends</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="expenseTrendsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Transactions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Transactions</h3>
                            <div class="card-header-actions">
                                <a href="transactions.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_transactions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-exchange-alt"></i>
                                    <p>No recent transactions</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Description</th>
                                                <th>Category</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_transactions as $transaction): ?>
                                                <tr>
                                                    <td><?php echo date('M j', strtotime($transaction['transaction_date'])); ?></td>
                                                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                        <?php echo htmlspecialchars(substr($transaction['description'], 0, 40)); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($transaction['category_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <span class="amount <?php echo $transaction['transaction_type']; ?>">
                                                            RWF <?php echo number_format($transaction['amount'], 0); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $transaction['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Budget Utilization -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Budget Utilization (This Month)</h3>
                            <div class="card-header-actions">
                                <a href="budget_management.php" class="card-header-btn" title="View Details">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($budget_utilization)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-pie"></i>
                                    <p>No budget utilization data available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($budget_utilization as $budget): ?>
                                    <div class="budget-item">
                                        <div class="budget-info">
                                            <div class="budget-category"><?php echo htmlspecialchars($budget['category_name']); ?></div>
                                            <div class="budget-amounts">
                                                <span>Allocated: RWF <?php echo number_format($budget['allocated_amount'], 0); ?></span>
                                                <span>Spent: RWF <?php echo number_format($budget['spent_amount'], 0); ?></span>
                                            </div>
                                        </div>
                                        <div class="budget-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill 
                                                    <?php echo $budget['utilization_rate'] < 60 ? 'progress-low' : 
                                                          ($budget['utilization_rate'] < 85 ? 'progress-medium' : 'progress-high'); ?>"
                                                    style="width: <?php echo min(100, $budget['utilization_rate']); ?>%">
                                                </div>
                                            </div>
                                            <div class="progress-text">
                                                <?php echo $budget['utilization_rate']; ?>%
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- RPSU Bank Account -->
                    <div class="card">
                        <div class="card-header">
                            <h3>RPSU Bank Account</h3>
                            <div class="card-header-actions">
                                <a href="bank_reconciliation.php" class="card-header-btn" title="Reconcile">
                                    <i class="fas fa-sync-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($rpsu_account): ?>
                                <div class="account-item">
                                    <div class="account-info">
                                        <h4><?php echo htmlspecialchars($rpsu_account['account_number']); ?></h4>
                                        <p><?php echo htmlspecialchars($rpsu_account['bank_name']); ?></p>
                                    </div>
                                    <div class="account-balance" style="color: var(--success);">
                                        RWF <?php echo number_format($rpsu_account['current_balance'], 0); ?>
                                    </div>
                                </div>
                                <div style="font-size: 0.7rem; color: var(--dark-gray); text-align: center; margin-top: 0.5rem;">
                                    Last updated: <?php echo date('M j, Y', strtotime($rpsu_account['updated_at'])); ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-university"></i>
                                    <p>No bank account configured</p>
                                    <a href="accounts.php" class="logout-btn" style="padding: 0.5rem 1rem; font-size: 0.8rem; display: inline-block; margin-top: 0.5rem;">
                                        Configure Account
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Upcoming Rental Payments -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Rental Payments Due</h3>
                            <div class="card-header-actions">
                                <a href="rental_management.php" class="card-header-btn" title="Manage Rentals">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_rentals)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-home"></i>
                                    <p>No upcoming rental payments</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming_rentals as $rental): ?>
                                    <div class="meeting-item">
                                        <div class="meeting-info">
                                            <h4><?php echo htmlspecialchars($rental['property_name']); ?></h4>
                                            <p><?php echo htmlspecialchars($rental['tenant_name']); ?></p>
                                        </div>
                                        <div class="meeting-date">
                                            <div style="font-weight: 600; color: var(--warning);">
                                                RWF <?php echo number_format($rental['monthly_rent'], 0); ?>
                                            </div>
                                            <div style="font-size: 0.65rem; color: var(--dark-gray);">
                                                <?php echo $rental['next_payment_due'] ? date('M j', strtotime($rental['next_payment_due'])) : 'Pending'; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Student Aid Requests -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Student Aid Requests</h3>
                            <div class="card-header-actions">
                                <a href="student_aid.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_aid_requests)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-hand-holding-heart"></i>
                                    <p>No recent aid requests</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_aid_requests as $request): ?>
                                    <div class="meeting-item">
                                        <div class="meeting-info">
                                            <h4><?php echo htmlspecialchars($request['student_name']); ?></h4>
                                            <p style="font-size: 0.7rem; color: var(--dark-gray);">
                                                <?php echo htmlspecialchars(substr($request['purpose'], 0, 50)); ?>...
                                            </p>
                                        </div>
                                        <div class="meeting-date">
                                            <div style="font-weight: 600; color: var(--<?php echo $request['urgency_level'] === 'emergency' ? 'danger' : 'warning'; ?>);">
                                                RWF <?php echo number_format($request['amount_requested'], 0); ?>
                                            </div>
                                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <a href="transactions.php?action=new" class="action-btn">
                                    <i class="fas fa-plus-circle"></i>
                                    <span class="action-label">New Transaction</span>
                                </a>
                                <a href="committee_requests.php" class="action-btn">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span class="action-label">Review Requests</span>
                                </a>
                                <a href="student_aid.php" class="action-btn">
                                    <i class="fas fa-hand-holding-heart"></i>
                                    <span class="action-label">Student Aid</span>
                                </a>
                                <a href="rental_management.php" class="action-btn">
                                    <i class="fas fa-home"></i>
                                    <span class="action-label">Rentals</span>
                                </a>
                                <a href="allowances.php" class="action-btn">
                                    <i class="fas fa-money-check"></i>
                                    <span class="action-label">Allowances</span>
                                </a>
                                <a href="financial_reports.php" class="action-btn">
                                    <i class="fas fa-download"></i>
                                    <span class="action-label">Generate Report</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Approvals Alerts -->
                    <?php if ($pending_approvals > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Action Required:</strong> You have 
                            <a href="transactions.php?status=approved_by_finance"><?php echo $pending_approvals; ?> transactions pending president approval</a>.
                        </div>
                    <?php endif; ?>

                    <?php if ($pending_budget_requests > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-clipboard-list"></i> 
                            <strong>Committee Requests:</strong> You have 
                            <a href="committee_requests.php"><?php echo $pending_budget_requests; ?> budget requests to review</a>.
                        </div>
                    <?php endif; ?>

                    <?php if ($pending_aid_requests > 0): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-hand-holding-heart"></i> 
                            <strong>Student Aid Urgent:</strong> You have 
                            <a href="student_aid.php"><?php echo $pending_aid_requests; ?> student aid requests pending review</a>.
                        </div>
                    <?php endif; ?>

                    <!-- Financial Health Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Financial Health</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.75rem;">Budget Health</span>
                                    <strong style="color: <?php echo $utilization_percentage <= 80 ? 'var(--success)' : ($utilization_percentage <= 95 ? 'var(--warning)' : 'var(--danger)'); ?>;">
                                        <?php echo $utilization_percentage <= 80 ? 'Excellent' : ($utilization_percentage <= 95 ? 'Good' : 'Critical'); ?>
                                    </strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.75rem;">Cash Flow</span>
                                    <strong style="color: <?php echo $net_cash_flow >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                        <?php echo $net_cash_flow >= 0 ? 'Positive' : 'Negative'; ?>
                                    </strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.75rem;">Approval Backlog</span>
                                    <strong style="color: <?php echo $pending_approvals == 0 ? 'var(--success)' : ($pending_approvals <= 5 ? 'var(--warning)' : 'var(--danger)'); ?>;">
                                        <?php echo $pending_approvals == 0 ? 'Clear' : ($pending_approvals <= 5 ? 'Moderate' : 'High'); ?>
                                    </strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.75rem;">Month Progress</span>
                                    <strong style="color: var(--text-dark);">
                                        <?php echo date('j'); ?>/<?php echo date('t'); ?> days
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
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
                sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active');
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
            });
        }

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Budget Utilization Chart
            const budgetCtx = document.getElementById('budgetChart');
            if (budgetCtx) {
                new Chart(budgetCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode(array_column($budget_utilization, 'category_name')); ?>,
                        datasets: [{
                            data: <?php echo json_encode(array_column($budget_utilization, 'utilization_rate')); ?>,
                            backgroundColor: ['#1976D2', '#2196F3', '#64B5F6', '#90CAF9', '#BBDEFB', '#FF9800', '#FFB74D', '#FFCC80', '#4CAF50', '#81C784'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 10,
                                    font: { size: 10 }
                                }
                            }
                        }
                    }
                });
            }

            // Expense Trends Chart
            const trendsCtx = document.getElementById('expenseTrendsChart');
            if (trendsCtx) {
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_map(function($trend) {
                            return date('M Y', strtotime($trend['month'] . '-01'));
                        }, $expense_trends)); ?>,
                        datasets: [{
                            label: 'Monthly Expenses',
                            data: <?php echo json_encode(array_column($expense_trends, 'monthly_expenses')); ?>,
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
        });
    </script>
</body>
</html>