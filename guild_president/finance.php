<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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
$current_academic_year = getCurrentAcademicYear();

// Handle actions (approve/reject transactions AND budget requests)
$action = $_GET['action'] ?? '';
$transaction_id = $_GET['id'] ?? 0;
$budget_request_id = $_GET['budget_id'] ?? 0;

if ($action && $transaction_id) {
    handleTransactionAction($action, $transaction_id, $user_id);
}

// FIXED: Use the correct parameter name
if ($action && $budget_request_id) {
    handleBudgetRequestAction($action, $budget_request_id, $user_id);
}

// Get all the data
$financial_stats = getFinancialStatistics();
$pending_approvals = getPendingApprovals();
$recent_transactions = getRecentTransactions();
$budget_requests = getBudgetRequests();
$student_aid_requests = getStudentAidRequests();
$rental_income = getRentalIncome();
$allowances_data = getAllowancesData();

// ADD THIS LINE - Get pending budget requests for president
$pending_budget_requests = getPendingBudgetRequests();

// Handle transaction actions
function handleTransactionAction($action, $transaction_id, $user_id) {
    global $pdo;
    
    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'completed', 
                    approved_by_president = ?, 
                    approved_at = NOW() 
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute([$user_id, $transaction_id]);
            
            // Update RPSU account balance if it's an expense
            $stmt = $pdo->prepare("SELECT transaction_type, amount FROM financial_transactions WHERE id = ?");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($transaction && $transaction['transaction_type'] === 'expense') {
                $stmt = $pdo->prepare("UPDATE rpsu_account SET current_balance = current_balance - ? WHERE is_active = 1");
                $stmt->execute([$transaction['amount']]);
            }
            
            $_SESSION['success_message'] = "Transaction approved successfully!";
            
        } elseif ($action === 'reject') {
            $reason = $_POST['rejection_reason'] ?? 'Rejected by President';
            $stmt = $pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'rejected', 
                    rejection_reason = ? 
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute([$reason, $transaction_id]);
            $_SESSION['success_message'] = "Transaction rejected successfully!";
        }
        
        header('Location: finance.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Transaction action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing transaction: " . $e->getMessage();
    }
}

// Get financial statistics
function getFinancialStatistics() {
    global $pdo, $current_academic_year;
    
// In getFinancialStatistics() function, make sure all stats are initialized:
$stats = [
    'bank_balance' => 0.00,
    'monthly_income' => 0.00,
    'monthly_expenses' => 0.00,
    'pending_approvals' => 0,
    'total_budget_requests' => 0,
    'pending_student_aid' => 0,
    'monthly_rental_income' => 0.00,
    'pending_budget_requests' => 0  // ADD THIS LINE
];
    
    try {
        // Bank balance
        $stmt = $pdo->query("SELECT COALESCE(current_balance, 0) as current_balance FROM rpsu_account WHERE is_active = 1 LIMIT 1");
        $bank_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['bank_balance'] = (float)$bank_data['current_balance'];
        
        // Total income (current month)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_income 
            FROM financial_transactions 
            WHERE transaction_type = 'income' 
            AND status = 'completed'
            AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
            AND YEAR(transaction_date) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute();
        $income_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['monthly_income'] = (float)$income_data['total_income'];
        
        // Total expenses (current month)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_expenses 
            FROM financial_transactions 
            WHERE transaction_type = 'expense' 
            AND status = 'completed'
            AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
            AND YEAR(transaction_date) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute();
        $expense_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['monthly_expenses'] = (float)$expense_data['total_expenses'];
        
        // Pending approvals count
        $stmt = $pdo->query("
            SELECT COALESCE(COUNT(*), 0) as pending_count 
            FROM financial_transactions 
            WHERE status = 'approved_by_finance'
        ");
        $approval_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending_approvals'] = (int)$approval_data['pending_count'];
        
// In getFinancialStatistics() function, update the budget requests count:
// Pending budget requests for president approval
try {
    $stmt = $pdo->query("
        SELECT COALESCE(COUNT(*), 0) as pending_budget_requests 
        FROM committee_budget_requests 
        WHERE status = 'approved_by_finance'
    ");
    $budget_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_budget_requests'] = (int)$budget_data['pending_budget_requests'];
} catch (PDOException $e) {
    error_log("Pending budget requests count error: " . $e->getMessage());
    $stats['pending_budget_requests'] = 0;
}
        
        // Pending student aid requests
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(COUNT(*), 0) as pending_aid 
                FROM student_financial_aid 
                WHERE status IN ('submitted', 'under_review')
            ");
            $aid_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['pending_student_aid'] = (int)$aid_data['pending_aid'];
        } catch (PDOException $e) {
            error_log("Student aid count error: " . $e->getMessage());
            $stats['pending_student_aid'] = 0;
        }
        
        // Monthly rental income
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) as rental_income 
                FROM rental_payments 
                WHERE status = 'verified'
                AND MONTH(payment_date) = MONTH(CURRENT_DATE())
                AND YEAR(payment_date) = YEAR(CURRENT_DATE())
            ");
            $stmt->execute();
            $rental_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['monthly_rental_income'] = (float)$rental_data['rental_income'];
        } catch (PDOException $e) {
            error_log("Rental income error: " . $e->getMessage());
            $stats['monthly_rental_income'] = 0.00;
        }
        
    } catch (PDOException $e) {
        error_log("Financial statistics error: " . $e->getMessage());
    }
    
    return $stats;
}


// Get pending approvals
function getPendingApprovals() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT ft.*, bc.category_name, u.full_name as requested_by_name
            FROM financial_transactions ft
            LEFT JOIN budget_categories bc ON ft.category_id = bc.id
            LEFT JOIN users u ON ft.requested_by = u.id
            WHERE ft.status = 'approved_by_finance'
            ORDER BY ft.created_at DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Pending approvals error: " . $e->getMessage());
        return [];
    }
}

// Get recent transactions
function getRecentTransactions() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT ft.*, bc.category_name, bc.category_type,
                   u1.full_name as requested_by_name,
                   u2.full_name as finance_approver_name
            FROM financial_transactions ft
            LEFT JOIN budget_categories bc ON ft.category_id = bc.id
            LEFT JOIN users u1 ON ft.requested_by = u1.id
            LEFT JOIN users u2 ON ft.approved_by_finance = u2.id
            WHERE ft.status IN ('completed', 'rejected')
            ORDER BY ft.created_at DESC
            LIMIT 15
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Recent transactions error: " . $e->getMessage());
        return [];
    }
}

// Get budget requests - CORRECTED VERSION
function getBudgetRequests() {
    global $pdo, $current_academic_year;
    
    try {
        $stmt = $pdo->prepare("
            SELECT cbr.*, 
                   u.full_name as requested_by_name,
                   cm.name as committee_name
            FROM committee_budget_requests cbr
            LEFT JOIN users u ON cbr.requested_by = u.id
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            WHERE cbr.academic_year = ?
            ORDER BY cbr.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$current_academic_year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Budget requests error: " . $e->getMessage());
        return [];
    }
}

// Get student aid requests - CORRECTED VERSION
function getStudentAidRequests() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT sfa.*, u.full_name as student_name, u.registration_number
            FROM student_financial_aid sfa
            LEFT JOIN users u ON sfa.student_id = u.id
            WHERE sfa.status IN ('submitted', 'under_review', 'approved_by_finance')
            ORDER BY sfa.created_at DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Student aid requests error: " . $e->getMessage());
        // If the table doesn't exist, return empty array
        return [];
    }
}

// Get rental income data
function getRentalIncome() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT rp.property_name, 
                   COALESCE(COUNT(rpm.id), 0) as total_payments,
                   COALESCE(SUM(rpm.amount), 0) as total_income,
                   COALESCE(AVG(rpm.amount), 0) as avg_payment
            FROM rental_properties rp
            LEFT JOIN rental_payments rpm ON rp.id = rpm.property_id AND rpm.status = 'verified'
            WHERE rp.is_active = 1
            GROUP BY rp.id, rp.property_name
            ORDER BY total_income DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Rental income error: " . $e->getMessage());
        return [];
    }
}


// Add these functions after the existing functions in your PHP code

// Handle budget request actions - CORRECTED VERSION
function handleBudgetRequestAction($action, $request_id, $user_id) {
    global $pdo;
    
    try {
        if ($action === 'approve_budget') {
            $stmt = $pdo->prepare("
                UPDATE committee_budget_requests 
                SET status = 'approved_by_president'
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute([$request_id]);
            
            $_SESSION['success_message'] = "Budget request approved successfully!";
            
        } elseif ($action === 'reject_budget') {
            $reason = $_POST['rejection_reason'] ?? 'Rejected by President';
            $stmt = $pdo->prepare("
                UPDATE committee_budget_requests 
                SET status = 'rejected', 
                    rejection_reason = ?
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute([$reason, $request_id]);
            $_SESSION['success_message'] = "Budget request rejected successfully!";
        }
        
        header('Location: finance.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Budget request action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing budget request: " . $e->getMessage();
    }
}

// Get pending budget requests for president approval - CORRECTED VERSION
function getPendingBudgetRequests() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT cbr.*, 
                   u.full_name as requested_by_name,
                   cm.name as committee_name,
                   cm.role as committee_role
            FROM committee_budget_requests cbr
            LEFT JOIN users u ON cbr.requested_by = u.id
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            WHERE cbr.status = 'approved_by_finance'
            ORDER BY cbr.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Pending budget requests error: " . $e->getMessage());
        return [];
    }
}

// Get allowances data
function getAllowancesData() {
    global $pdo, $current_academic_year;
    
    $data = [];
    
    try {
        // Mission allowances
        $stmt = $pdo->prepare("
            SELECT ma.*, u.full_name as committee_member_name
            FROM mission_allowances ma
            LEFT JOIN users u ON ma.committee_member_id = u.id
            WHERE ma.academic_year = ?
            ORDER BY ma.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$current_academic_year]);
        $data['mission_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Communication allowances
        $stmt = $pdo->prepare("
            SELECT cca.*, u.full_name as committee_member_name
            FROM committee_communication_allowances cca
            LEFT JOIN users u ON cca.committee_member_id = u.id
            WHERE cca.academic_year = ?
            ORDER BY cca.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$current_academic_year]);
        $data['communication_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Allowances data error: " . $e->getMessage());
    }
    
    return $data;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Overview - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--finance-primary);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
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
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-approved {
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

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn {
            padding: 0.4rem 0.75rem;
            border: none;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.65rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-primary {
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
        }

        /* Alert Messages */
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

        /* Charts */
        .chart-container {
            height: 250px;
            margin-bottom: 1rem;
            position: relative;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
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
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
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
                    <h1>Isonga - Financial Overview</h1>
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
                        <div class="user-role">Guild President</div>
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php" class="active">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
                        <?php if ($financial_stats['pending_approvals'] > 0): ?>
                            <span class="menu-badge"><?php echo $financial_stats['pending_approvals']; ?></span>
                        <?php endif; ?>
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
                    <h1>Financial Overview 💰</h1>
                    <p>Monitor and approve all financial transactions and activities</p>
                </div>
            </div>

            <!-- Alert Messages -->
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

<!-- Statistics Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-university"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number">RWF <?php echo number_format($financial_stats['bank_balance'] ?? 0, 2); ?></div>
            <div class="stat-label">Bank Balance</div>
        </div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon">
            <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number">RWF <?php echo number_format($financial_stats['monthly_income'] ?? 0, 2); ?></div>
            <div class="stat-label">Monthly Income</div>
        </div>
    </div>
    <div class="stat-card danger">
        <div class="stat-icon">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number">RWF <?php echo number_format($financial_stats['monthly_expenses'] ?? 0, 2); ?></div>
            <div class="stat-label">Monthly Expenses</div>
        </div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number"><?php echo $financial_stats['pending_approvals'] ?? 0; ?></div>
            <div class="stat-label">Pending Approvals</div>
        </div>
    </div>
</div>

<!-- Additional Stats -->
<div class="stats-grid" style="margin-top: 1rem;">
<!-- In the statistics grid, add this card: -->
<div class="stat-card warning">
    <div class="stat-icon">
        <i class="fas fa-file-alt"></i>
    </div>
    <div class="stat-content">
        <div class="stat-number"><?php echo $financial_stats['pending_budget_requests'] ?? 0; ?></div>
        <div class="stat-label">Pending Budget Approvals</div>
    </div>
</div>
    <div class="stat-card warning">
        <div class="stat-icon">
            <i class="fas fa-hand-holding-heart"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number"><?php echo $financial_stats['pending_student_aid'] ?? 0; ?></div>
            <div class="stat-label">Student Aid Requests</div>
        </div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon">
            <i class="fas fa-home"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number">RWF <?php echo number_format((float)($financial_stats['monthly_rental_income'] ?? 0), 2); ?></div>
            <div class="stat-label">Rental Income</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <div class="stat-number">RWF <?php 
                $monthly_income = (float)($financial_stats['monthly_income'] ?? 0);
                $monthly_expenses = (float)($financial_stats['monthly_expenses'] ?? 0);
                echo number_format($monthly_income - $monthly_expenses, 2); 
            ?></div>
            <div class="stat-label">Net Cash Flow</div>
        </div>
    </div>
</div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Pending Approvals -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Pending Presidential Approval</h3>
                            <div class="card-header-actions">
                                <a href="financial_transactions.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_approvals)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No pending approvals</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Requested By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_approvals as $transaction): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $transaction['transaction_type'] === 'income' ? 'status-approved' : 'status-pending'; ?>">
                                                        <?php echo ucfirst($transaction['transaction_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="amount <?php echo $transaction['transaction_type']; ?>">
                                                    RWF <?php echo number_format($transaction['amount'], 2); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['requested_by_name']); ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="finance.php?action=approve&id=<?php echo $transaction['id']; ?>" 
                                                           class="btn btn-success btn-sm" 
                                                           onclick="return confirm('Approve this transaction?')">
                                                            <i class="fas fa-check"></i> Approve
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" 
                                                                onclick="showRejectionForm(<?php echo $transaction['id']; ?>)">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Transactions</h3>
                            <div class="card-header-actions">
                                <a href="financial_transactions.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_transactions)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-exchange-alt" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No recent transactions</p>
                                </div>
                            <?php else: ?>
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
                                                <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['category_name']); ?></td>
                                                <td class="amount <?php echo $transaction['category_type']; ?>">
                                                    RWF <?php echo number_format($transaction['amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                                        <?php echo str_replace('_', ' ', $transaction['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

<!-- Budget Requests for Approval -->
<div class="card">
    <div class="card-header">
        <h3>Budget Requests for Approval</h3>

    </div>
    <div class="card-body">
        <?php if (empty($pending_budget_requests)): ?>
            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                <i class="fas fa-file-invoice-dollar" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>No budget requests pending presidential approval</p>
                <small style="color: var(--dark-gray);">
                    All budget requests have been processed or are awaiting finance approval.
                </small>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Committee</th>
                        <th>Requested By</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_budget_requests as $request): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($request['request_title']); ?></div>
                                <?php if (!empty($request['purpose'])): ?>
                                    <small style="color: var(--dark-gray); display: block; margin-top: 0.25rem;">
                                        <?php echo htmlspecialchars(substr($request['purpose'], 0, 50)); ?>
                                        <?php if (strlen($request['purpose']) > 50): ?>...<?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($request['committee_name'] ?? 'General Committee'); ?></div>
                                <small style="color: var(--dark-gray);">
                                    <?php echo htmlspecialchars($request['committee_role'] ?? 'Committee'); ?>
                                </small>
                            </td>
                            <td><?php echo htmlspecialchars($request['requested_by_name']); ?></td>
                            <td class="amount expense">
                                RWF <?php echo number_format($request['requested_amount'], 2); ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="finance.php?action=approve_budget&budget_id=<?php echo $request['id']; ?>" 
                                       class="btn btn-success btn-sm" 
                                       onclick="return confirm('Approve this budget request of RWF <?php echo number_format($request['requested_amount'], 2); ?>?')"
                                       title="Approve Request">
                                        <i class="fas fa-check"></i> Approve
                                    </a>
                                    <button class="btn btn-danger btn-sm" 
                                            onclick="showBudgetRejectionForm(<?php echo $request['id']; ?>)"
                                            title="Reject Request">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                    <?php if (!empty($request['action_plan_file_path'])): ?>
                                        <a href="../<?php echo $request['action_plan_file_path']; ?>" 
                                           class="btn btn-primary btn-sm" 
                                           target="_blank"
                                           title="View Action Plan">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
<!-- Student Financial Aid -->
<div class="card">
    <div class="card-header">
        <h3>Student Financial Aid Requests</h3>

    </div>
    <div class="card-body">
        <?php if (empty($student_aid_requests)): ?>
            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                <i class="fas fa-hand-holding-heart" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>No student aid requests pending review</p>
                <small style="color: var(--dark-gray);">
                    All student aid requests have been processed or no requests are currently pending.
                </small>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Registration</th>
                        <th>Amount</th>
                        <th>Purpose</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student_aid_requests as $request): ?>
                        <tr>
                            <td>
                                <div><?php echo htmlspecialchars($request['student_name'] ?? 'Unknown Student'); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($request['registration_number'] ?? 'N/A'); ?></td>
                            <td class="amount expense">
                                RWF <?php echo number_format($request['amount_requested'] ?? 0, 2); ?>
                            </td>
                            <td>
                                <?php if (!empty($request['purpose'])): ?>
                                    <?php echo htmlspecialchars(substr($request['purpose'], 0, 50)); ?>
                                    <?php if (strlen($request['purpose']) > 50): ?>...<?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--dark-gray); font-style: italic;">No purpose specified</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $request['status'] ?? 'unknown'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'] ?? 'unknown')); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Rental Income -->
<div class="card">
    <div class="card-header">
        <h3>Rental Properties Income</h3>
    </div>
    <div class="card-body">
        <?php if (empty($rental_income)): ?>
            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                <i class="fas fa-home" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>No rental properties found</p>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Property</th>
                        <th>Payments</th>
                        <th>Total Income</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rental_income as $property): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($property['property_name']); ?></td>
                            <td><?php echo $property['total_payments']; ?></td>
                            <td class="amount <?php echo $property['total_income'] > 0 ? 'income' : ''; ?>">
                                <?php if ($property['total_income'] > 0): ?>
                                    RWF <?php echo number_format($property['total_income'], 2); ?>
                                <?php else: ?>
                                    <span style="color: var(--dark-gray); font-style: italic;">No payments yet</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($property['total_payments'] > 0): ?>
                                    <span class="status-badge status-completed">Active</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">No Payments</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

                    <!-- Allowances -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Committee Allowances</h3>
                        </div>
                        <div class="card-body">
                            <h4 style="margin-bottom: 0.75rem; font-size: 0.9rem;">Mission Allowances</h4>
                            <?php if (empty($allowances_data['mission_allowances'])): ?>
                                <p style="color: var(--dark-gray); font-size: 0.8rem; margin-bottom: 1rem;">No mission allowances</p>
                            <?php else: ?>
                                <table class="table" style="margin-bottom: 1rem;">
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Purpose</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allowances_data['mission_allowances'] as $allowance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($allowance['committee_member_name']); ?></td>
                                                <td><?php echo htmlspecialchars(substr($allowance['mission_purpose'], 0, 30)) . '...'; ?></td>
                                                <td class="amount expense">
                                                    RWF <?php echo number_format($allowance['amount'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <h4 style="margin-bottom: 0.75rem; font-size: 0.9rem;">Communication Allowances</h4>
                            <?php if (empty($allowances_data['communication_allowances'])): ?>
                                <p style="color: var(--dark-gray); font-size: 0.8rem;">No communication allowances</p>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Period</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allowances_data['communication_allowances'] as $allowance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($allowance['committee_member_name']); ?></td>
                                                <td><?php echo htmlspecialchars($allowance['month_year']); ?></td>
                                                <td class="amount expense">
                                                    RWF <?php echo number_format($allowance['amount'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Rejection Form Modal -->
<!-- Budget Rejection Form Modal -->
<div id="budgetRejectionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--white); padding: 2rem; border-radius: var(--border-radius); width: 90%; max-width: 400px;">
        <h3 style="margin-bottom: 1rem;">Reject Budget Request</h3>
        <form id="budgetRejectionForm" method="POST">
            <input type="hidden" name="budget_request_id" id="rejectBudgetRequestId">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Reason for Rejection:</label>
                <textarea name="rejection_reason" style="width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius);" rows="4" required></textarea>
            </div>
            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                <button type="button" onclick="hideBudgetRejectionForm()" style="padding: 0.5rem 1rem; border: 1px solid var(--medium-gray); background: var(--light-gray); border-radius: var(--border-radius); cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 0.5rem 1rem; border: none; background: var(--danger); color: white; border-radius: var(--border-radius); cursor: pointer;">Reject</button>
            </div>
        </form>
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

        // Rejection Form Functions
        function showRejectionForm(transactionId) {
            document.getElementById('rejectTransactionId').value = transactionId;
            document.getElementById('rejectionForm').action = 'finance.php?action=reject&id=' + transactionId;
            document.getElementById('rejectionModal').style.display = 'flex';
        }

function hideRejectionForm() {
    document.getElementById('rejectionModal').style.display = 'none';
}


        // Auto-refresh every 2 minutes
        setInterval(() => {
            window.location.reload();
        }, 120000);


        // Add these functions to your JavaScript
function showBudgetRejectionForm(budgetRequestId) {
    document.getElementById('rejectBudgetRequestId').value = budgetRequestId;
    document.getElementById('budgetRejectionForm').action = 'finance.php?action=reject_budget&budget_id=' + budgetRequestId;
    document.getElementById('budgetRejectionModal').style.display = 'flex';
}

function hideBudgetRejectionForm() {
    document.getElementById('budgetRejectionModal').style.display = 'none';
}

// Close modals when clicking outside
window.addEventListener('click', function(e) {
    const transactionModal = document.getElementById('rejectionModal');
    const budgetModal = document.getElementById('budgetRejectionModal');
    
    if (e.target === transactionModal) {
        hideRejectionForm();
    }
    if (e.target === budgetModal) {
        hideBudgetRejectionForm();
    }
});


    </script>
</body>
</html>