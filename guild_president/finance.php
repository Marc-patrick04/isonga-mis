<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';
require_once 'email_config.php'; // Guild President email functions

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

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

// Handle actions
$action = $_GET['action'] ?? '';
$transaction_id = (int)($_GET['id'] ?? 0);
$budget_request_id = (int)($_GET['budget_id'] ?? 0);
$aid_request_id = (int)($_GET['aid_id'] ?? 0);

// Process actions
if ($action && $transaction_id) {
    handleTransactionAction($action, $transaction_id, $user_id, $user_name);
}

if ($action && $budget_request_id) {
    handleBudgetRequestAction($action, $budget_request_id, $user_id, $user_name);
}

if ($action && $aid_request_id) {
    handleStudentAidAction($action, $aid_request_id, $user_id, $user_name);
}

// Get all the data
$financial_stats = getFinancialStatistics();
$pending_approvals = getPendingApprovals();
$recent_transactions = getRecentTransactions();
$budget_requests = getBudgetRequests();
$student_aid_requests = getStudentAidRequests();
$rental_income = getRentalIncome();
$allowances_data = getAllowancesData();
$pending_budget_requests = getPendingBudgetRequests();
$pending_student_aid_requests = getPendingStudentAidRequests(); // For president approval

/**
 * Handle transaction approval/rejection
 */
function handleTransactionAction($action, $transaction_id, $user_id, $user_name) {
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
            $stmt = $pdo->prepare("SELECT transaction_type, amount, description FROM financial_transactions WHERE id = ?");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($transaction && $transaction['transaction_type'] === 'expense') {
                $stmt = $pdo->prepare("UPDATE rpsu_account SET current_balance = current_balance - ? WHERE is_active = true");
                $stmt->execute([$transaction['amount']]);
            }
            
            $_SESSION['success_message'] = "Transaction approved successfully!";
            
        } elseif ($action === 'reject') {
            $reason = $_POST['rejection_reason'] ?? 'Rejected by President';
            $stmt = $pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'rejected', 
                    rejection_reason = ?,
                    approved_by_president = ?
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute([$reason, $user_id, $transaction_id]);
            $_SESSION['success_message'] = "Transaction rejected successfully!";
        }
        
        header('Location: finance.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Transaction action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing transaction: " . $e->getMessage();
        header('Location: finance.php');
        exit;
    }
}

/**
 * Handle budget request approval/rejection
 */
function handleBudgetRequestAction($action, $request_id, $user_id, $user_name) {
    global $pdo;
    
    try {
        // First get the request details for notification
        $stmt = $pdo->prepare("
            SELECT cbr.*, u.email as requester_email, u.full_name as requester_name,
                   cm.name as committee_name
            FROM committee_budget_requests cbr
            LEFT JOIN users u ON cbr.requested_by = u.id
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            WHERE cbr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($action === 'approve_budget') {
            $stmt = $pdo->prepare("
                UPDATE committee_budget_requests 
                SET status = 'approved_by_president',
                    president_approval_date = NOW(),
                    president_approval_notes = ?
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute(["Approved by President $user_name", $request_id]);
            
            // Send notification to requester
            if ($request && !empty($request['requester_email'])) {
                sendBudgetRequestApprovalNotification(
                    $request['requester_email'],
                    $request['requester_name'],
                    $request_id,
                    $request['request_title'],
                    $request['requested_amount'],
                    $user_name
                );
            }
            
            $_SESSION['success_message'] = "Budget request approved successfully!";
            
        } elseif ($action === 'reject_budget') {
            $reason = $_POST['rejection_reason'] ?? 'Rejected by President';
            $stmt = $pdo->prepare("
                UPDATE committee_budget_requests 
                SET status = 'rejected', 
                    rejection_reason = ?,
                    president_approval_notes = ?
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute([$reason, "Rejected by President $user_name: $reason", $request_id]);
            
            // Send rejection notification to requester
            if ($request && !empty($request['requester_email'])) {
                sendBudgetRequestRejectionNotification(
                    $request['requester_email'],
                    $request['requester_name'],
                    $request_id,
                    $request['request_title'],
                    $reason,
                    $user_name
                );
            }
            
            $_SESSION['success_message'] = "Budget request rejected successfully!";
        }
        
        header('Location: finance.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Budget request action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing budget request: " . $e->getMessage();
        header('Location: finance.php');
        exit;
    }
}

/**
 * Handle student aid request approval/rejection
 */
function handleStudentAidAction($action, $request_id, $user_id, $user_name) {
    global $pdo;
    
    try {
        // Get request details for notification
        $stmt = $pdo->prepare("
            SELECT sfa.*, u.email as student_email, u.full_name as student_name,
                   u.reg_number as registration_number
            FROM student_financial_aid sfa
            LEFT JOIN users u ON sfa.student_id = u.id
            WHERE sfa.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($action === 'approve_aid') {
            $stmt = $pdo->prepare("
                UPDATE student_financial_aid 
                SET status = 'approved',
                    reviewed_by = ?,
                    review_date = NOW()
                WHERE id = ? AND status = 'pending_president'
            ");
            $stmt->execute([$user_id, $request_id]);
            
            // Send approval notification to student
            if ($request && !empty($request['student_email'])) {
                sendStudentAidPresidentApproval(
                    $request['student_email'],
                    $request['student_name'],
                    $request_id,
                    $request['request_title'],
                    $request['amount_approved'],
                    $user_name
                );
            }
            
            $_SESSION['success_message'] = "Student aid request approved! Finance can now process disbursement.";
            
        } elseif ($action === 'reject_aid') {
            $reason = $_POST['rejection_reason'] ?? 'Rejected by President';
            $stmt = $pdo->prepare("
                UPDATE student_financial_aid 
                SET status = 'rejected',
                    review_notes = ?,
                    reviewed_by = ?,
                    review_date = NOW()
                WHERE id = ? AND status = 'pending_president'
            ");
            $stmt->execute([$reason, $user_id, $request_id]);
            
            // Send rejection notification to student
            if ($request && !empty($request['student_email'])) {
                sendStudentAidPresidentRejection(
                    $request['student_email'],
                    $request['student_name'],
                    $request_id,
                    $request['request_title'],
                    $reason,
                    $user_name
                );
            }
            
            $_SESSION['success_message'] = "Student aid request rejected! Student has been notified.";
        }
        
        header('Location: finance.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Student aid action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing student aid request: " . $e->getMessage();
        header('Location: finance.php');
        exit;
    }
}

/**
 * Get financial statistics
 */
function getFinancialStatistics() {
    global $pdo;
    
    $stats = [
        'bank_balance' => 0.00,
        'monthly_income' => 0.00,
        'monthly_expenses' => 0.00,
        'pending_approvals' => 0,
        'pending_budget_requests' => 0,
        'pending_student_aid' => 0,
        'monthly_rental_income' => 0.00
    ];
    
    try {
        // Bank balance
        $stmt = $pdo->query("SELECT COALESCE(current_balance, 0) as current_balance FROM rpsu_account WHERE is_active = true LIMIT 1");
        $bank_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['bank_balance'] = (float)($bank_data['current_balance'] ?? 0);
        
        // Total income (current month)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_income 
            FROM financial_transactions 
            WHERE transaction_type = 'income' 
            AND status = 'completed'
            AND EXTRACT(MONTH FROM transaction_date) = EXTRACT(MONTH FROM CURRENT_DATE)
            AND EXTRACT(YEAR FROM transaction_date) = EXTRACT(YEAR FROM CURRENT_DATE)
        ");
        $stmt->execute();
        $income_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['monthly_income'] = (float)($income_data['total_income'] ?? 0);
        
        // Total expenses (current month)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_expenses 
            FROM financial_transactions 
            WHERE transaction_type = 'expense' 
            AND status = 'completed'
            AND EXTRACT(MONTH FROM transaction_date) = EXTRACT(MONTH FROM CURRENT_DATE)
            AND EXTRACT(YEAR FROM transaction_date) = EXTRACT(YEAR FROM CURRENT_DATE)
        ");
        $stmt->execute();
        $expense_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['monthly_expenses'] = (float)($expense_data['total_expenses'] ?? 0);
        
        // Pending approvals count (transactions)
        $stmt = $pdo->query("
            SELECT COALESCE(COUNT(*), 0) as pending_count 
            FROM financial_transactions 
            WHERE status = 'approved_by_finance'
        ");
        $approval_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending_approvals'] = (int)($approval_data['pending_count'] ?? 0);
        
        // Pending budget requests
        $stmt = $pdo->query("
            SELECT COALESCE(COUNT(*), 0) as pending_budget 
            FROM committee_budget_requests 
            WHERE status = 'approved_by_finance'
        ");
        $budget_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending_budget_requests'] = (int)($budget_data['pending_budget'] ?? 0);
        
        // Pending student aid requests (waiting for president)
        $stmt = $pdo->query("
            SELECT COALESCE(COUNT(*), 0) as pending_aid 
            FROM student_financial_aid 
            WHERE status = 'pending_president'
        ");
        $aid_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending_student_aid'] = (int)($aid_data['pending_aid'] ?? 0);
        
        // Monthly rental income
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as rental_income 
            FROM rental_payments 
            WHERE status = 'verified'
            AND EXTRACT(MONTH FROM payment_date) = EXTRACT(MONTH FROM CURRENT_DATE)
            AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)
        ");
        $stmt->execute();
        $rental_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['monthly_rental_income'] = (float)($rental_data['rental_income'] ?? 0);
        
    } catch (PDOException $e) {
        error_log("Financial statistics error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get pending approvals (transactions)
 */
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

/**
 * Get recent transactions
 */
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

/**
 * Get budget requests
 */
function getBudgetRequests() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT cbr.*, 
                   u.full_name as requested_by_name,
                   COALESCE(cm.name, 'General Committee') as committee_name,
                   COALESCE(cm.role, 'Committee Member') as committee_role
            FROM committee_budget_requests cbr
            LEFT JOIN users u ON cbr.requested_by = u.id
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            ORDER BY cbr.created_at DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Budget requests error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get student aid requests
 */
function getStudentAidRequests() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT sfa.*, u.full_name as student_name, u.reg_number as registration_number
            FROM student_financial_aid sfa
            LEFT JOIN users u ON sfa.student_id = u.id
            ORDER BY 
                CASE WHEN sfa.status = 'pending_president' THEN 1 ELSE 2 END,
                sfa.created_at DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Student aid requests error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get pending budget requests for president approval
 */
function getPendingBudgetRequests() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT cbr.*, 
                   u.full_name as requested_by_name,
                   COALESCE(cm.name, 'General Committee') as committee_name,
                   COALESCE(cm.role, 'Committee Member') as committee_role
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

/**
 * Get pending student aid requests for president approval
 */
function getPendingStudentAidRequests() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT sfa.*, u.full_name as student_name, u.email as student_email,
                   u.reg_number as registration_number, u.phone as student_phone,
                   u.academic_year
            FROM student_financial_aid sfa
            LEFT JOIN users u ON sfa.student_id = u.id
            WHERE sfa.status = 'pending_president'
            ORDER BY 
                CASE sfa.urgency_level 
                    WHEN 'emergency' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    ELSE 4
                END,
                sfa.created_at ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Pending student aid requests error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get rental income data
 */
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
            WHERE rp.is_active = true
            GROUP BY rp.id, rp.property_name
            ORDER BY total_income DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Rental income error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get allowances data
 */
function getAllowancesData() {
    global $pdo;
    
    $data = [
        'mission_allowances' => [],
        'communication_allowances' => []
    ];
    
    try {
        // Mission allowances
        $stmt = $pdo->prepare("
            SELECT ma.*, u.full_name as committee_member_name
            FROM mission_allowances ma
            LEFT JOIN users u ON ma.committee_member_id = u.id
            ORDER BY ma.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $data['mission_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Communication allowances
        $stmt = $pdo->prepare("
            SELECT cca.*, u.full_name as committee_member_name
            FROM committee_communication_allowances cca
            LEFT JOIN users u ON cca.committee_member_id = u.id
            ORDER BY cca.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $data['communication_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Allowances data error: " . $e->getMessage());
    }
    
    return $data;
}

// Helper function to get urgency badge class
function getUrgencyClass($urgency) {
    $classes = [
        'low' => 'urgency-low',
        'medium' => 'urgency-medium',
        'high' => 'urgency-high',
        'emergency' => 'urgency-emergency'
    ];
    return $classes[$urgency] ?? 'urgency-medium';
}

// Helper function to get status badge class
function getStatusClass($status) {
    $classes = [
        'submitted' => 'status-submitted',
        'under_review' => 'status-under-review',
        'pending_president' => 'status-pending-president',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'disbursed' => 'status-disbursed'
    ];
    return $classes[$status] ?? 'status-pending';
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .full-width {
            grid-column: span 2;
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

        .card-body {
            padding: 1.25rem;
            overflow-x: auto;
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

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-submitted {
            background: #cce5ff;
            color: #004085;
        }

        .status-under-review {
            background: #cce5ff;
            color: #004085;
        }

        .status-pending-president {
            background: #ffe5b4;
            color: #e65100;
        }

        .status-disbursed {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* Urgency Badges */
        .urgency-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .urgency-emergency {
            background: #f8d7da;
            color: #721c24;
        }

        .urgency-high {
            background: #ffeaa7;
            color: #856404;
        }

        .urgency-medium {
            background: #d1ecf1;
            color: #0c5460;
        }

        .urgency-low {
            background: #d4edda;
            color: #155724;
        }

        /* Amount */
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

        /* Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
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

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: var(--info);
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

        /* Form Elements */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            color: var(--dark-gray);
            padding: 2rem;
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

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
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
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
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
                    <h1>Isonga - Financial Overview</h1>
                </div>
            </div>
            <div class="user-menu">
                <button class="icon-btn" id="themeToggle">
                    <i class="fas fa-moon"></i>
                </button>
                <button class="icon-btn" id="sidebarToggleBtn">
                    <i class="fas fa-chevron-left"></i>
                </button>
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
                        <?php if (($financial_stats['pending_approvals'] ?? 0) > 0): ?>
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Financial Overview 💰</h1>
                    <p>Monitor and approve all financial transactions, budget requests, and student aid applications</p>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
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
                        <div class="stat-label">Pending Transaction Approvals</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="stats-grid">
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $financial_stats['pending_budget_requests'] ?? 0; ?></div>
                        <div class="stat-label">Pending Budget Approvals</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $financial_stats['pending_student_aid'] ?? 0; ?></div>
                        <div class="stat-label">Pending Student Aid</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($financial_stats['monthly_rental_income'] ?? 0, 2); ?></div>
                        <div class="stat-label">Rental Income (This Month)</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php 
                            $income = (float)($financial_stats['monthly_income'] ?? 0);
                            $expenses = (float)($financial_stats['monthly_expenses'] ?? 0);
                            echo number_format($income - $expenses, 2); 
                        ?></div>
                        <div class="stat-label">Net Cash Flow</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Student Financial Aid Requests (Priority) -->
                    <div class="card">
                        <div class="card-header">
                            <h3>🎓 Student Financial Aid Requests</h3>
                            <?php if (($financial_stats['pending_student_aid'] ?? 0) > 0): ?>
                                <span class="status-badge status-pending-president"><?php echo $financial_stats['pending_student_aid']; ?> Pending</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_student_aid_requests)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No student aid requests pending your approval</p>
                                    <small>All requests have been processed or are awaiting finance review.</small>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Registration</th>
                                            <th>Title</th>
                                            <th>Amount</th>
                                            <th>Urgency</th>
                                            <th>Finance Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_student_aid_requests as $request): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($request['student_name'] ?? 'Unknown'); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($request['registration_number'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['request_title'] ?? 'N/A'); ?>
                                                    <?php if (!empty($request['purpose'])): ?>
                                                        <br><small style="color: var(--dark-gray);"><?php echo htmlspecialchars(substr($request['purpose'], 0, 40)); ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount expense">
                                                    RWF <?php echo number_format($request['amount_approved'] ?? $request['amount_requested'] ?? 0, 2); ?>
                                                    <?php if (($request['amount_approved'] ?? 0) != ($request['amount_requested'] ?? 0)): ?>
                                                        <br><small>(Requested: RWF <?php echo number_format($request['amount_requested'] ?? 0, 2); ?>)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="urgency-badge urgency-<?php echo $request['urgency_level'] ?? 'medium'; ?>">
                                                        <?php echo ucfirst($request['urgency_level'] ?? 'Medium'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($request['review_notes'])): ?>
                                                        <small><?php echo htmlspecialchars(substr($request['review_notes'], 0, 50)); ?>...</small>
                                                    <?php else: ?>
                                                        <small style="color: var(--dark-gray); font-style: italic;">No notes</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="finance.php?action=approve_aid&aid_id=<?php echo $request['id']; ?>" 
                                                           class="btn btn-success btn-sm" 
                                                           onclick="return confirm('Approve this student aid request of RWF <?php echo number_format($request['amount_approved'] ?? $request['amount_requested'] ?? 0, 2); ?>?')">
                                                            <i class="fas fa-check"></i> Approve
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" 
                                                                onclick="showAidRejectionForm(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['request_title']); ?>')">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                        <a href="../vice_guild_finance/view_student_aid.php?id=<?php echo $request['id']; ?>" 
                                                           class="btn btn-primary btn-sm" 
                                                           target="_blank">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending Transaction Approvals -->
                    <div class="card">
                        <div class="card-header">
                            <h3>💰 Pending Transaction Approvals</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_approvals)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No pending transaction approvals</p>
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
                                                    <span class="status-badge status-pending"><?php echo ucfirst($transaction['transaction_type']); ?></span>
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
                                                                onclick="showRejectionForm(<?php echo $transaction['id']; ?>, '<?php echo htmlspecialchars($transaction['description']); ?>')">
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

                    <!-- Budget Requests for Approval -->
                    <div class="card">
                        <div class="card-header">
                            <h3>📋 Budget Requests for Approval</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_budget_requests)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    <p>No budget requests pending your approval</p>
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
                                                    <strong><?php echo htmlspecialchars($request['request_title']); ?></strong>
                                                    <?php if (!empty($request['purpose'])): ?>
                                                        <br><small><?php echo htmlspecialchars(substr($request['purpose'], 0, 40)); ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['committee_name'] ?? 'General Committee'); ?>
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
                                                           onclick="return confirm('Approve this budget request of RWF <?php echo number_format($request['requested_amount'], 2); ?>?')">
                                                            <i class="fas fa-check"></i> Approve
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" 
                                                                onclick="showBudgetRejectionForm(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['request_title']); ?>')">
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
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Transactions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>📊 Recent Transactions</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_transactions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-exchange-alt"></i>
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
                                                <td><?php echo htmlspecialchars(substr($transaction['description'], 0, 40)); ?>...</td>
                                                <td><?php echo htmlspecialchars($transaction['category_name']); ?></td>
                                                <td class="amount <?php echo $transaction['category_type']; ?>">
                                                    RWF <?php echo number_format($transaction['amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo str_replace('_', '-', $transaction['status']); ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $transaction['status'])); ?>
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
                            <h3>🏠 Rental Properties Income</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($rental_income)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-home"></i>
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
                                                    RWF <?php echo number_format($property['total_income'], 2); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $property['total_payments'] > 0 ? 'status-completed' : 'status-pending'; ?>">
                                                        <?php echo $property['total_payments'] > 0 ? 'Active' : 'No Payments'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Committee Allowances -->
                    <div class="card">
                        <div class="card-header">
                            <h3>👥 Committee Allowances</h3>
                        </div>
                        <div class="card-body">
                            <h4 style="margin-bottom: 0.75rem; font-size: 0.9rem;">Mission Allowances</h4>
                            <?php if (empty($allowances_data['mission_allowances'])): ?>
                                <p style="color: var(--dark-gray); font-size: 0.8rem; margin-bottom: 1rem;">No mission allowances recorded</p>
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
                                                <td class="amount expense">RWF <?php echo number_format($allowance['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <h4 style="margin-bottom: 0.75rem; font-size: 0.9rem;">Communication Allowances</h4>
                            <?php if (empty($allowances_data['communication_allowances'])): ?>
                                <p style="color: var(--dark-gray); font-size: 0.8rem;">No communication allowances recorded</p>
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
                                                <td class="amount expense">RWF <?php echo number_format($allowance['amount'], 2); ?></td>
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

    <!-- Transaction Rejection Modal -->
    <div id="rejectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Transaction</h3>
                <button class="close" onclick="hideRejectionForm()">&times;</button>
            </div>
            <form method="POST" id="rejectionForm">
                <input type="hidden" name="transaction_id" id="rejectTransactionId">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Reason for Rejection:</label>
                        <textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a clear reason for rejecting this transaction..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideRejectionForm()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Budget Request Rejection Modal -->
    <div id="budgetRejectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Budget Request</h3>
                <button class="close" onclick="hideBudgetRejectionForm()">&times;</button>
            </div>
            <form method="POST" id="budgetRejectionForm">
                <input type="hidden" name="budget_request_id" id="rejectBudgetRequestId">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Reason for Rejection:</label>
                        <textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a clear reason for rejecting this budget request..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideBudgetRejectionForm()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Aid Rejection Modal -->
    <div id="aidRejectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Student Aid Request</h3>
                <button class="close" onclick="hideAidRejectionForm()">&times;</button>
            </div>
            <form method="POST" id="aidRejectionForm">
                <input type="hidden" name="aid_request_id" id="rejectAidRequestId">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Reason for Rejection:</label>
                        <textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a clear reason for rejecting this student aid request..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideAidRejectionForm()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
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

        // Transaction Rejection Functions
        function showRejectionForm(transactionId, description) {
            document.getElementById('rejectTransactionId').value = transactionId;
            document.getElementById('rejectionForm').action = 'finance.php?action=reject&id=' + transactionId;
            document.getElementById('rejectionModal').classList.add('active');
        }

        function hideRejectionForm() {
            document.getElementById('rejectionModal').classList.remove('active');
        }

        // Budget Request Rejection Functions
        function showBudgetRejectionForm(budgetRequestId, title) {
            document.getElementById('rejectBudgetRequestId').value = budgetRequestId;
            document.getElementById('budgetRejectionForm').action = 'finance.php?action=reject_budget&budget_id=' + budgetRequestId;
            document.getElementById('budgetRejectionModal').classList.add('active');
        }

        function hideBudgetRejectionForm() {
            document.getElementById('budgetRejectionModal').classList.remove('active');
        }

        // Student Aid Rejection Functions
        function showAidRejectionForm(aidRequestId, title) {
            document.getElementById('rejectAidRequestId').value = aidRequestId;
            document.getElementById('aidRejectionForm').action = 'finance.php?action=reject_aid&aid_id=' + aidRequestId;
            document.getElementById('aidRejectionModal').classList.add('active');
        }

        function hideAidRejectionForm() {
            document.getElementById('aidRejectionModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            const transactionModal = document.getElementById('rejectionModal');
            const budgetModal = document.getElementById('budgetRejectionModal');
            const aidModal = document.getElementById('aidRejectionModal');
            
            if (e.target === transactionModal) hideRejectionForm();
            if (e.target === budgetModal) hideBudgetRejectionForm();
            if (e.target === aidModal) hideAidRejectionForm();
        });
    </script>
</body>
</html>