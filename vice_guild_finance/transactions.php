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

// Get all budget categories
try {
    $stmt = $pdo->query("SELECT * FROM budget_categories WHERE is_active = true ORDER BY category_type, category_name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    error_log("Categories error: " . $e->getMessage());
}

// Build UNION query to combine all financial transactions from multiple sources
$query = "
    -- Manual financial transactions
    SELECT 
        ft.id,
        ft.transaction_type,
        ft.category_id,
        bc.category_name,
        ft.amount,
        ft.description,
        ft.transaction_date,
        ft.reference_number,
        ft.payee_payer,
        ft.payment_method,
        ft.status,
        ft.requested_by,
        u_req.full_name as requested_by_name,
        ft.created_at,
        'manual' as source,
        NULL::text as source_id
    FROM financial_transactions ft
    LEFT JOIN budget_categories bc ON ft.category_id = bc.id
    LEFT JOIN users u_req ON ft.requested_by = u_req.id
    WHERE ft.id IS NOT NULL
    
    UNION ALL
    
    -- Committee Budget Requests (approved_by_president or funded)
    SELECT 
        cbr.id,
        'expense' as transaction_type,
        NULL as category_id,
        'Committee Budget Request' as category_name,
        cbr.approved_amount as amount,
        CONCAT('Committee Budget: ', cbr.request_title) as description,
        COALESCE(cbr.president_approval_date, cbr.finance_approval_date, cbr.request_date, cbr.created_at) as transaction_date,
        CONCAT('CBR-', cbr.id) as reference_number,
        u.full_name as payee_payer,
        'bank_transfer' as payment_method,
        CASE 
            WHEN cbr.status = 'funded' THEN 'completed'
            WHEN cbr.status = 'approved_by_president' THEN 'approved_by_president'
            ELSE cbr.status
        END as status,
        cbr.requested_by,
        u.full_name as requested_by_name,
        cbr.created_at,
        'committee_request' as source,
        CAST(cbr.id AS text) as source_id
    FROM committee_budget_requests cbr
    LEFT JOIN users u ON cbr.requested_by = u.id
    WHERE cbr.status IN ('approved_by_president', 'funded')
    
    UNION ALL
    
    -- Student Financial Aid (approved or disbursed)
    SELECT 
        sfa.id,
        'expense' as transaction_type,
        NULL as category_id,
        'Student Financial Aid' as category_name,
        sfa.amount_approved as amount,
        CONCAT('Student Aid: ', sfa.request_title) as description,
        COALESCE(sfa.disbursement_date, sfa.review_date, sfa.created_at) as transaction_date,
        CONCAT('SFA-', sfa.id) as reference_number,
        u.full_name as payee_payer,
        'bank_transfer' as payment_method,
        CASE 
            WHEN sfa.status = 'disbursed' THEN 'completed'
            WHEN sfa.status = 'approved' THEN 'approved_by_president'
            ELSE sfa.status
        END as status,
        sfa.student_id as requested_by,
        u.full_name as requested_by_name,
        sfa.created_at,
        'student_aid' as source,
        CAST(sfa.id AS text) as source_id
    FROM student_financial_aid sfa
    LEFT JOIN users u ON sfa.student_id = u.id
    WHERE sfa.status IN ('approved', 'disbursed')
    
    UNION ALL
    
    -- Communication Allowances (paid)
    SELECT 
        cca.id,
        'expense' as transaction_type,
        cca.category_id,
        bc.category_name,
        cca.amount,
        CONCAT('Communication Allowance: ', COALESCE(cm.name, 'Member'), ' - ', cca.month_year) as description,
        COALESCE(cca.payment_date, cca.created_at) as transaction_date,
        CONCAT('COMM-', cca.id) as reference_number,
        COALESCE(cm.name, 'Committee Member') as payee_payer,
        'cash' as payment_method,
        CASE 
            WHEN cca.status = 'paid' THEN 'completed'
            ELSE cca.status
        END as status,
        cca.created_by as requested_by,
        u.full_name as requested_by_name,
        cca.created_at,
        'allowance' as source,
        CAST(cca.id AS text) as source_id
    FROM committee_communication_allowances cca
    LEFT JOIN committee_members cm ON cca.committee_member_id = cm.id
    LEFT JOIN budget_categories bc ON cca.category_id = bc.id
    LEFT JOIN users u ON cca.created_by = u.id
    WHERE cca.status = 'paid'
    
    UNION ALL
    
    -- Mission Allowances (paid)
    SELECT 
        ma.id,
        'expense' as transaction_type,
        ma.category_id,
        bc.category_name,
        ma.amount,
        CONCAT('Mission Allowance: ', COALESCE(cm.name, 'Member'), ' - ', ma.destination) as description,
        COALESCE(ma.payment_date, ma.mission_date, ma.created_at) as transaction_date,
        CONCAT('MISS-', ma.id) as reference_number,
        COALESCE(cm.name, 'Committee Member') as payee_payer,
        COALESCE(ma.transport_mode, 'public') as payment_method,
        CASE 
            WHEN ma.status = 'paid' THEN 'completed'
            ELSE ma.status
        END as status,
        ma.created_by as requested_by,
        u.full_name as requested_by_name,
        ma.created_at,
        'allowance' as source,
        CAST(ma.id AS text) as source_id
    FROM mission_allowances ma
    LEFT JOIN committee_members cm ON ma.committee_member_id = cm.id
    LEFT JOIN budget_categories bc ON ma.category_id = bc.id
    LEFT JOIN users u ON ma.created_by = u.id
    WHERE ma.status = 'paid'
    
    UNION ALL
    
    -- Rental Payments (verified)
    SELECT 
        rpm.id,
        'income' as transaction_type,
        NULL as category_id,
        'Rental Income' as category_name,
        rpm.amount,
        CONCAT('Rental Payment: ', COALESCE(rp.property_name, 'Property')) as description,
        rpm.payment_date as transaction_date,
        rpm.receipt_number as reference_number,
        rpm.paid_by as payee_payer,
        'bank_transfer' as payment_method,
        'completed' as status,
        rpm.received_by as requested_by,
        u.full_name as requested_by_name,
        rpm.created_at,
        'rental' as source,
        CAST(rpm.id AS text) as source_id
    FROM rental_payments rpm
    LEFT JOIN rental_properties rp ON rpm.property_id = rp.id
    LEFT JOIN users u ON rpm.received_by = u.id
    WHERE rpm.status = 'verified'
";

$params = [];

// Apply filters
$where_clauses = [];

if ($filter_type) {
    $where_clauses[] = "transaction_type = ?";
    $params[] = $filter_type;
}

if ($filter_status) {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
}

if ($filter_category) {
    $where_clauses[] = "category_id = ?";
    $params[] = $filter_category;
}

if ($filter_month) {
    $where_clauses[] = "TO_CHAR(transaction_date, 'YYYY-MM') = ?";
    $params[] = $filter_month;
}

if ($search) {
    $where_clauses[] = "(description ILIKE ? OR reference_number ILIKE ? OR payee_payer ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Wrap the UNION query and apply filters
if (!empty($where_clauses)) {
    $full_query = "SELECT * FROM (" . $query . ") AS all_transactions WHERE " . implode(" AND ", $where_clauses);
} else {
    $full_query = $query;
}

$full_query .= " ORDER BY transaction_date DESC, created_at DESC";

try {
    $stmt = $pdo->prepare($full_query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log the count of transactions from each source
    $source_counts = [];
    foreach ($transactions as $t) {
        $source = $t['source'];
        $source_counts[$source] = ($source_counts[$source] ?? 0) + 1;
    }
    error_log("Transaction sources: " . print_r($source_counts, true));
    
} catch (PDOException $e) {
    $transactions = [];
    error_log("Transactions UNION query error: " . $e->getMessage());
}

// Get transaction statistics from the combined results
$total_count = count($transactions);
$pending_approvals = 0;
$monthly_income = 0;
$monthly_expenses = 0;
$current_month = date('m');
$current_year = date('Y');

foreach ($transactions as $t) {
    // Count pending approvals
    if ($t['status'] === 'approved_by_finance' || $t['status'] === 'pending_approval' || $t['status'] === 'approved') {
        $pending_approvals++;
    }
    
    $t_date = strtotime($t['transaction_date']);
    $t_month = date('m', $t_date);
    $t_year = date('Y', $t_date);
    
    if ($t_year == $current_year && $t_month == $current_month) {
        if ($t['transaction_type'] === 'income' && ($t['status'] === 'completed' || $t['status'] === 'verified')) {
            $monthly_income += floatval($t['amount']);
        } elseif ($t['transaction_type'] === 'expense' && ($t['status'] === 'completed' || $t['status'] === 'paid')) {
            $monthly_expenses += floatval($t['amount']);
        }
    }
}

// Recent transactions for chart (last 6 months)
$monthly_trends = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $income = 0;
    $expenses = 0;
    
    foreach ($transactions as $t) {
        $t_date = strtotime($t['transaction_date']);
        $t_month = date('Y-m', $t_date);
        if ($t_month == $month) {
            if ($t['transaction_type'] === 'income' && ($t['status'] === 'completed' || $t['status'] === 'verified')) {
                $income += floatval($t['amount']);
            } elseif ($t['transaction_type'] === 'expense' && ($t['status'] === 'completed' || $t['status'] === 'paid')) {
                $expenses += floatval($t['amount']);
            }
        }
    }
    
    $monthly_trends[] = [
        'month' => $month,
        'income' => $income,
        'expenses' => $expenses
    ];
}

// Get counts from source tables for debugging
$committee_count = 0;
$student_aid_count = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM committee_budget_requests WHERE status IN ('approved_by_president', 'funded')");
    $committee_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM student_financial_aid WHERE status IN ('approved', 'disbursed')");
    $student_aid_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    error_log("Committee requests (approved/funded): " . $committee_count);
    error_log("Student aid (approved/disbursed): " . $student_aid_count);
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
}

// Badge counts for sidebar
$pending_approvals_badge = $pending_approvals;
$pending_budget_requests = 0;
$pending_aid_requests = 0;
try {
    $r = $pdo->query("SELECT COUNT(*) as c FROM committee_budget_requests WHERE status IN ('submitted','under_review')");
    $pending_budget_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
    $r = $pdo->query("SELECT COUNT(*) as c FROM student_financial_aid WHERE status IN ('submitted','under_review')");
    $pending_aid_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
} catch (PDOException $e) { /* silent */ }

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Transactions - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ... keep all your existing CSS styles ... */
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

        /* Chart Card */
        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
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

        /* Source Badge */
        .source-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .source-manual { background: #e2e3e5; color: #383d41; }
        .source-committee_request { background: #cce7ff; color: #004085; }
        .source-student_aid { background: #d4edda; color: #155724; }
        .source-allowance { background: #fff3cd; color: #856404; }
        .source-rental { background: #d1ecf1; color: #0c5460; }

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control, .form-select {
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

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
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

        /* Card */
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
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: #e2e3e5; color: #383d41; }
        .status-pending_approval { background: #fff3cd; color: #856404; }
        .status-approved_by_finance { background: #cce7ff; color: #004085; }
        .status-approved_by_president { background: #d4edda; color: #155724; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-disbursed { background: #d4edda; color: #155724; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-verified { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-income { background: #d4edda; color: #155724; }
        .status-expense { background: #f8d7da; color: #721c24; }

        /* Modal */
        .modal-overlay {
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
            backdrop-filter: blur(2px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
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
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            opacity: 0.5;
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

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .chart-container {
                height: 220px;
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
                    <h1>Isonga - Transactions</h1>
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
                    <a href="transactions.php" class="active">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                        <?php if ($pending_approvals_badge > 0): ?>
                            <span class="menu-badge"><?php echo $pending_approvals_badge; ?></span>
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
                    <a href="accounts.php" >
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
                    <h1>Financial Transactions</h1>
                   
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo safe_display($message); ?>
                </div>
            <?php endif; ?>

            <!-- Transaction Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_count; ?></div>
                        <div class="stat-label">Total Transactions</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($monthly_income, 0); ?></div>
                        <div class="stat-label">Monthly Income</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($monthly_expenses, 0); ?></div>
                        <div class="stat-label">Monthly Expenses</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_approvals; ?></div>
                        <div class="stat-label">Pending Approvals</div>
                    </div>
                </div>
            </div>

            <!-- Monthly Trends Chart -->
            <?php if (!empty($monthly_trends)): ?>
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Monthly Income vs Expenses</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyTrendsChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="transactions.php">
                    <div class="filter-grid">
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
                                <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="funded" <?php echo $filter_status === 'funded' ? 'selected' : ''; ?>>Funded</option>
                                <option value="disbursed" <?php echo $filter_status === 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                                <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="verified" <?php echo $filter_status === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo safe_display($category['category_name']); ?>
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
                            <input type="text" class="form-control" name="search" value="<?php echo safe_display($search); ?>" placeholder="Description, Reference, Payee...">
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="transactions.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Reset
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
                        <button class="card-header-btn" onclick="openAddTransactionModal()" title="Add New Transaction">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Payee/Payer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fas fa-exchange-alt"></i>
                                                <p>No transactions found</p>
                                                <?php if ($committee_count > 0 || $student_aid_count > 0): ?>
                                                    <p style="margin-top: 0.5rem; font-size: 0.75rem;">
                                                        Note: <?php echo $committee_count; ?> committee requests and <?php echo $student_aid_count; ?> student aid requests exist but may need to be marked as "funded" or "disbursed" to appear here.
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                            <td>
                                                <span class="source-badge source-<?php echo $transaction['source']; ?>">
                                                    <i class="fas 
                                                        <?php echo $transaction['source'] === 'manual' ? 'fa-pen' : 
                                                              ($transaction['source'] === 'committee_request' ? 'fa-clipboard-list' : 
                                                              ($transaction['source'] === 'student_aid' ? 'fa-hand-holding-heart' : 
                                                              ($transaction['source'] === 'allowance' ? 'fa-money-check' : 'fa-home'))); ?>">
                                                    </i>
                                                    <?php echo ucfirst(str_replace('_', ' ', $transaction['source'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $transaction['transaction_type']; ?>">
                                                    <?php echo ucfirst($transaction['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td style="max-width: 250px;">
                                                <div style="font-weight: 500;"><?php echo safe_display($transaction['description']); ?></div>
                                                <?php if ($transaction['reference_number']): ?>
                                                    <small style="color: var(--dark-gray);">Ref: <?php echo safe_display($transaction['reference_number']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo safe_display($transaction['payee_payer']); ?></td>
                                            <td class="amount <?php echo $transaction['transaction_type']; ?>">
                                                RWF <?php echo number_format($transaction['amount'], 0); ?>
                                            </td>
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
            </div>
        </main>
    </div>

    <!-- Add Transaction Modal -->
    <div id="addTransactionModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Transaction</h3>
                <button class="modal-close" onclick="closeAddTransactionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="transactionForm">
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
                                    <?php echo safe_display($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Amount (RWF) *</label>
                        <input type="number" class="form-control" name="amount" id="transactionAmount" step="1000" min="0" required>
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
                            <option value="transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile">Mobile Money</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddTransactionModal()">Cancel</button>
                <button type="submit" form="transactionForm" class="btn btn-primary">Save Transaction</button>
            </div>
        </div>
    </div>

    <script>
       

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

        // Modal functions
        function openAddTransactionModal() {
            document.getElementById('addTransactionModal').classList.add('active');
            document.getElementById('transactionDate').valueAsDate = new Date();
        }

        function closeAddTransactionModal() {
            document.getElementById('addTransactionModal').classList.remove('active');
            document.getElementById('transactionForm').reset();
        }

        // Transaction type change handler
        const transactionType = document.getElementById('transactionType');
        if (transactionType) {
            transactionType.addEventListener('change', function() {
                const type = this.value;
                const label = document.getElementById('payeePayerLabel');
                label.textContent = type === 'income' ? 'Payer *' : 'Payee *';
                
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
        }

        // Initialize chart
        document.addEventListener('DOMContentLoaded', function() {
            const monthlyTrends = <?php echo json_encode($monthly_trends); ?>;
            
            if (monthlyTrends.length > 0 && monthlyTrends.some(t => t.income > 0 || t.expenses > 0)) {
                const ctx = document.getElementById('monthlyTrendsChart');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: monthlyTrends.map(item => {
                                const [year, month] = item.month.split('-');
                                return new Date(year, month - 1).toLocaleDateString('en-US', {month: 'short', year: 'numeric'});
                            }),
                            datasets: [
                                {
                                    label: 'Income',
                                    data: monthlyTrends.map(item => parseFloat(item.income)),
                                    backgroundColor: '#28a745',
                                    borderColor: '#1e7e34',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Expenses',
                                    data: monthlyTrends.map(item => parseFloat(item.expenses)),
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
            } else {
                const chartCard = document.querySelector('.chart-card');
                if (chartCard) chartCard.style.display = 'none';
            }
        });

        // Close modals on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal-overlay');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>