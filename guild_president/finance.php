<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';
require_once 'email_config.php';

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

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (Exception $e) {}
    
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    } catch (Exception $e) {}
    
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (Exception $e) {}
    
    $new_students = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as new_students FROM users WHERE role = 'student' AND status = 'active' AND created_at >= CURRENT_DATE - INTERVAL '7 days'");
        $new_students = $stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    } catch (Exception $e) {}
    
} catch (PDOException $e) {
    $open_tickets = $pending_reports = $unread_messages = $pending_docs = $new_students = 0;
}

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

// Get all the data (PostgreSQL syntax)
$financial_stats = getFinancialStatistics($pdo);
$pending_approvals = getPendingApprovals($pdo);
$recent_transactions = getRecentTransactions($pdo);
$budget_requests = getBudgetRequests($pdo);
$student_aid_requests = getStudentAidRequests($pdo);
$rental_income = getRentalIncome($pdo);
$allowances_data = getAllowancesData($pdo);
$pending_budget_requests = getPendingBudgetRequests($pdo);
$pending_student_aid_requests = getPendingStudentAidRequests($pdo);

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
                    approved_at = CURRENT_TIMESTAMP 
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
                    president_approval_date = CURRENT_TIMESTAMP,
                    president_approval_notes = ?
                WHERE id = ? AND status = 'approved_by_finance'
            ");
            $stmt->execute(["Approved by President $user_name", $request_id]);
            
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
                    review_date = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'pending_president'
            ");
            $stmt->execute([$user_id, $request_id]);
            
            $_SESSION['success_message'] = "Student aid request approved! Finance can now process disbursement.";
            
        } elseif ($action === 'reject_aid') {
            $reason = $_POST['rejection_reason'] ?? 'Rejected by President';
            $stmt = $pdo->prepare("
                UPDATE student_financial_aid 
                SET status = 'rejected',
                    review_notes = ?,
                    reviewed_by = ?,
                    review_date = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'pending_president'
            ");
            $stmt->execute([$reason, $user_id, $request_id]);
            
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
 * Get financial statistics (PostgreSQL)
 */
function getFinancialStatistics($pdo) {
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
        
        // Total income (current month) - PostgreSQL EXTRACT
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
        $stmt = $pdo->query("SELECT COALESCE(COUNT(*), 0) as pending_count FROM financial_transactions WHERE status = 'approved_by_finance'");
        $approval_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending_approvals'] = (int)($approval_data['pending_count'] ?? 0);
        
        // Pending budget requests
        $stmt = $pdo->query("SELECT COALESCE(COUNT(*), 0) as pending_budget FROM committee_budget_requests WHERE status = 'approved_by_finance'");
        $budget_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['pending_budget_requests'] = (int)($budget_data['pending_budget'] ?? 0);
        
        // Pending student aid requests
        $stmt = $pdo->query("SELECT COALESCE(COUNT(*), 0) as pending_aid FROM student_financial_aid WHERE status = 'pending_president'");
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
function getPendingApprovals($pdo) {
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
function getRecentTransactions($pdo) {
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
function getBudgetRequests($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT cbr.*, 
                   u.full_name as requested_by_name,
                   COALESCE(cm.name, 'General Committee') as committee_name
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
function getStudentAidRequests($pdo) {
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
 * Get pending budget requests
 */
function getPendingBudgetRequests($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT cbr.*, 
                   u.full_name as requested_by_name,
                   COALESCE(cm.name, 'General Committee') as committee_name
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
 * Get pending student aid requests
 */
function getPendingStudentAidRequests($pdo) {
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
function getRentalIncome($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT rp.property_name, 
                   COALESCE(COUNT(rpm.id), 0) as total_payments,
                   COALESCE(SUM(rpm.amount), 0) as total_income
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
function getAllowancesData($pdo) {
    $data = [
        'mission_allowances' => [],
        'communication_allowances' => []
    ];
    
    try {
        $stmt = $pdo->prepare("
            SELECT ma.*, u.full_name as committee_member_name
            FROM mission_allowances ma
            LEFT JOIN users u ON ma.committee_member_id = u.id
            ORDER BY ma.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $data['mission_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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

// Helper functions
function getUrgencyClass($urgency) {
    $classes = [
        'low' => 'urgency-low',
        'medium' => 'urgency-medium',
        'high' => 'urgency-high',
        'emergency' => 'urgency-emergency'
    ];
    return $classes[$urgency] ?? 'urgency-medium';
}

function getStatusClass($status) {
    $classes = [
        'submitted' => 'status-submitted',
        'under_review' => 'status-under-review',
        'pending_president' => 'status-pending-president',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'disbursed' => 'status-disbursed',
        'completed' => 'status-completed'
    ];
    return $classes[$status] ?? 'status-pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
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
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 2px 8px rgba(0,0,0,0.12);
            --border-radius: 8px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            font-size: 0.875rem;
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
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            background: var(--light-blue);
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

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--info); }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--light-blue);
            color: var(--finance-primary);
        }

        .stat-card.success .stat-icon { background: #d4edda; color: var(--success); }
        .stat-card.warning .stat-icon { background: #fff3cd; color: #856404; }
        .stat-card.danger .stat-icon { background: #f8d7da; color: var(--danger); }

        .stat-number {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
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
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            background: var(--light-gray);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
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
        }

        .table tr:hover {
            background: var(--light-blue);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved, .status-completed { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-pending-president { background: #ffe5b4; color: #e65100; }
        .status-submitted, .status-under-review { background: #cce5ff; color: #004085; }

        /* Urgency Badges */
        .urgency-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .urgency-emergency { background: #f8d7da; color: #721c24; }
        .urgency-high { background: #ffeaa7; color: #856404; }
        .urgency-medium { background: #d1ecf1; color: #0c5460; }
        .urgency-low { background: #d4edda; color: #155724; }

        /* Amount */
        .amount {
            font-weight: 600;
            font-family: monospace;
        }
        .amount.income, .amount.expense { font-weight: 700; }
        .amount.income { color: var(--success); }
        .amount.expense { color: var(--danger); }

        /* Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.4rem 0.75rem;
            border: none;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: var(--transition);
        }

        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.65rem; }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-primary { background: var(--finance-primary); color: white; }
        .btn-warning { background: var(--warning); color: var(--text-dark); }
        .btn-secondary { background: var(--dark-gray); color: white; }

        .btn-success:hover { background: #218838; }
        .btn-danger:hover { background: #c82333; }
        .btn-primary:hover { background: var(--accent-blue); }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }
        .alert-info { background: #d1ecf1; color: #0c5460; border-left-color: var(--info); }

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
        .modal.active { display: flex; }
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
        .modal-body { padding: 1.5rem; }
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

        /* Form */
        .form-group { margin-bottom: 1rem; }
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

        /* Dashboard Header */
        .dashboard-header {
            margin-bottom: 1.5rem;
        }
        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
            }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-menu-toggle { display: block; }
            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(2px);
                z-index: 999;
            }
            .overlay.active { display: block; }
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .nav-container { padding: 0 1rem; }
            .user-details { display: none; }
            .main-content { padding: 1rem; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .table th, .table td { padding: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="overlay" id="mobileOverlay"></div>
    
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text"><h1>Isonga - Financial Overview</h1></div>
            </div>
            <div class="user-menu">
                <button class="icon-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <button class="icon-btn" id="sidebarToggleBtn"><i class="fas fa-chevron-left"></i></button>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Guild President</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-chevron-left"></i></button>
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i><span>All Tickets</span><?php if ($open_tickets > 0): ?><span class="menu-badge"><?php echo $open_tickets; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-file-alt"></i><span>Committee Reports</span><?php if ($pending_reports > 0): ?><span class="menu-badge"><?php echo $pending_reports; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="documents.php"><i class="fas fa-file-contract"></i><span>Documents</span><?php if ($pending_docs > 0): ?><span class="menu-badge"><?php echo $pending_docs; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-users"></i><span>Committee Management</span></a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i><span>Student Management</span><?php if ($new_students > 0): ?><span class="menu-badge"><?php echo $new_students; ?> new</span><?php endif; ?></a></li>
                <li class="menu-item"><a href="messages.php"><i class="fas fa-comments"></i><span>Messages</span><?php if ($unread_messages > 0): ?><span class="menu-badge"><?php echo $unread_messages; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="meetings.php"><i class="fas fa-calendar-alt"></i><span>Meetings</span></a></li>
                <li class="menu-item"><a href="finance.php" class="active"><i class="fas fa-money-bill-wave"></i><span>Finance</span><?php if (($financial_stats['pending_approvals'] ?? 0) > 0): ?><span class="menu-badge"><?php echo $financial_stats['pending_approvals']; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="profile.php"><i class="fas fa-user-cog"></i><span>Profile & Settings</span></a></li>
            </ul>
        </nav>

        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Financial Overview </h1>
                </div>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon"><i class="fas fa-university"></i></div><div><div class="stat-number">RWF <?php echo number_format($financial_stats['bank_balance'] ?? 0, 2); ?></div><div class="stat-label">Bank Balance</div></div></div>
                <div class="stat-card success"><div class="stat-icon"><i class="fas fa-arrow-down"></i></div><div><div class="stat-number">RWF <?php echo number_format($financial_stats['monthly_income'] ?? 0, 2); ?></div><div class="stat-label">Monthly Income</div></div></div>
                <div class="stat-card danger"><div class="stat-icon"><i class="fas fa-arrow-up"></i></div><div><div class="stat-number">RWF <?php echo number_format($financial_stats['monthly_expenses'] ?? 0, 2); ?></div><div class="stat-label">Monthly Expenses</div></div></div>
                <div class="stat-card warning"><div class="stat-icon"><i class="fas fa-clock"></i></div><div><div class="stat-number"><?php echo $financial_stats['pending_approvals'] ?? 0; ?></div><div class="stat-label">Pending Approvals</div></div></div>
            </div>

            <div class="stats-grid">
                <div class="stat-card warning"><div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div><div><div class="stat-number"><?php echo $financial_stats['pending_budget_requests'] ?? 0; ?></div><div class="stat-label">Budget Requests</div></div></div>
                <div class="stat-card info"><div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div><div><div class="stat-number"><?php echo $financial_stats['pending_student_aid'] ?? 0; ?></div><div class="stat-label">Student Aid</div></div></div>
                <div class="stat-card success"><div class="stat-icon"><i class="fas fa-home"></i></div><div><div class="stat-number">RWF <?php echo number_format($financial_stats['monthly_rental_income'] ?? 0, 2); ?></div><div class="stat-label">Rental Income</div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fas fa-chart-line"></i></div><div><div class="stat-number">RWF <?php echo number_format(($financial_stats['monthly_income'] ?? 0) - ($financial_stats['monthly_expenses'] ?? 0), 2); ?></div><div class="stat-label">Net Cash Flow</div></div></div>
            </div>

            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Student Financial Aid Requests -->
                    <div class="card">
                        <div class="card-header"><h3> Student Financial Aid Requests</h3><?php if (($financial_stats['pending_student_aid'] ?? 0) > 0): ?><span class="status-badge status-pending-president"><?php echo $financial_stats['pending_student_aid']; ?> Pending</span><?php endif; ?></div>
                        <div class="card-body">
                            <?php if (empty($pending_student_aid_requests)): ?>
                                <div class="empty-state"><i class="fas fa-check-circle"></i><p>No student aid requests pending your approval</p></div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead><tr><th>Student</th><th>Title</th><th>Amount</th><th>Urgency</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($pending_student_aid_requests as $request): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($request['student_name'] ?? 'Unknown'); ?></strong><br><small><?php echo htmlspecialchars($request['registration_number'] ?? ''); ?></small></td>
                                                <td><?php echo htmlspecialchars($request['request_title'] ?? 'N/A'); ?></td>
                                                <td class="amount expense">RWF <?php echo number_format($request['amount_approved'] ?? $request['amount_requested'] ?? 0, 2); ?></td>
                                                <td><span class="urgency-badge urgency-<?php echo $request['urgency_level'] ?? 'medium'; ?>"><?php echo ucfirst($request['urgency_level'] ?? 'Medium'); ?></span></td>
                                                <td class="action-buttons">
                                                    <a href="finance.php?action=approve_aid&aid_id=<?php echo $request['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this student aid request?')"><i class="fas fa-check"></i> Approve</a>
                                                    <button class="btn btn-danger btn-sm" onclick="showAidRejectionForm(<?php echo $request['id']; ?>)"><i class="fas fa-times"></i> Reject</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending Transaction Approvals -->
                    <div class="card">
                        <div class="card-header"><h3> Pending Transaction Approvals</h3></div>
                        <div class="card-body">
                            <?php if (empty($pending_approvals)): ?>
                                <div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending transaction approvals</p></div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead><tr><th>Description</th><th>Type</th><th>Amount</th><th>Requested By</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($pending_approvals as $transaction): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td><span class="status-badge status-pending"><?php echo ucfirst($transaction['transaction_type']); ?></span></td>
                                                <td class="amount <?php echo $transaction['transaction_type']; ?>">RWF <?php echo number_format($transaction['amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['requested_by_name']); ?></td>
                                                <td class="action-buttons">
                                                    <a href="finance.php?action=approve&id=<?php echo $transaction['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this transaction?')"><i class="fas fa-check"></i> Approve</a>
                                                    <button class="btn btn-danger btn-sm" onclick="showRejectionForm(<?php echo $transaction['id']; ?>)"><i class="fas fa-times"></i> Reject</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Budget Requests -->
                    <div class="card">
                        <div class="card-header"><h3> Budget Requests for Approval</h3></div>
                        <div class="card-body">
                            <?php if (empty($pending_budget_requests)): ?>
                                <div class="empty-state"><i class="fas fa-file-invoice-dollar"></i><p>No budget requests pending your approval</p></div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead><tr><th>Title</th><th>Committee</th><th>Amount</th><th>Date</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($pending_budget_requests as $request): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($request['request_title']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($request['committee_name'] ?? 'General'); ?></td>
                                                <td class="amount expense">RWF <?php echo number_format($request['requested_amount'], 2); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                                                <td class="action-buttons">
                                                    <a href="finance.php?action=approve_budget&budget_id=<?php echo $request['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this budget request?')"><i class="fas fa-check"></i> Approve</a>
                                                    <button class="btn btn-danger btn-sm" onclick="showBudgetRejectionForm(<?php echo $request['id']; ?>)"><i class="fas fa-times"></i> Reject</button>
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

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Transactions -->
                    <div class="card">
                        <div class="card-header"><h3> Recent Transactions</h3></div>
                        <div class="card-body">
                            <?php if (empty($recent_transactions)): ?>
                                <div class="empty-state"><i class="fas fa-exchange-alt"></i><p>No recent transactions</p></div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($recent_transactions as $transaction): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($transaction['description'], 0, 35)); ?>...</td>
                                                <td><?php echo htmlspecialchars($transaction['category_name']); ?></td>
                                                <td class="amount <?php echo $transaction['category_type']; ?>">RWF <?php echo number_format($transaction['amount'], 2); ?></td>
                                                <td><span class="status-badge status-<?php echo str_replace('_', '-', $transaction['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $transaction['status'])); ?></span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Rental Income -->
                    <div class="card">
                        <div class="card-header"><h3> Rental Properties Income</h3></div>
                        <div class="card-body">
                            <?php if (empty($rental_income)): ?>
                                <div class="empty-state"><i class="fas fa-home"></i><p>No rental properties found</p></div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead><tr><th>Property</th><th>Payments</th><th>Total Income</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($rental_income as $property): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($property['property_name']); ?></td>
                                                <td><?php echo $property['total_payments']; ?></td>
                                                <td class="amount income">RWF <?php echo number_format($property['total_income'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Committee Allowances -->
                    <div class="card">
                        <div class="card-header"><h3>👥 Committee Allowances</h3></div>
                        <div class="card-body">
                            <h4 style="margin-bottom:0.75rem; font-size:0.9rem;">Mission Allowances</h4>
                            <?php if (empty($allowances_data['mission_allowances'])): ?>
                                <p style="color:var(--dark-gray); margin-bottom:1rem;">No mission allowances recorded</p>
                            <?php else: ?>
                                <table class="table" style="margin-bottom:1rem;">
                                    <thead><tr><th>Member</th><th>Purpose</th><th>Amount</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($allowances_data['mission_allowances'] as $allowance): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($allowance['committee_member_name']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($allowance['mission_purpose'], 0, 25)); ?>...</td>
                                            <td class="amount expense">RWF <?php echo number_format($allowance['amount'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <h4 style="margin-bottom:0.75rem; font-size:0.9rem;">Communication Allowances</h4>
                            <?php if (empty($allowances_data['communication_allowances'])): ?>
                                <p style="color:var(--dark-gray);">No communication allowances recorded</p>
                            <?php else: ?>
                                <table class="table">
                                    <thead><tr><th>Member</th><th>Period</th><th>Amount</th></tr></thead>
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

    <!-- Modals -->
    <div id="rejectionModal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Reject Transaction</h3><button class="close" onclick="hideRejectionForm()">&times;</button></div><form method="POST" id="rejectionForm"><input type="hidden" name="transaction_id" id="rejectTransactionId"><div class="modal-body"><div class="form-group"><label class="form-label">Reason for Rejection:</label><textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a clear reason..."></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideRejectionForm()">Cancel</button><button type="submit" class="btn btn-danger">Confirm Rejection</button></div></form></div></div>

    <div id="budgetRejectionModal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Reject Budget Request</h3><button class="close" onclick="hideBudgetRejectionForm()">&times;</button></div><form method="POST" id="budgetRejectionForm"><input type="hidden" name="budget_request_id" id="rejectBudgetRequestId"><div class="modal-body"><div class="form-group"><label class="form-label">Reason for Rejection:</label><textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a clear reason..."></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideBudgetRejectionForm()">Cancel</button><button type="submit" class="btn btn-danger">Confirm Rejection</button></div></form></div></div>

    <div id="aidRejectionModal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Reject Student Aid Request</h3><button class="close" onclick="hideAidRejectionForm()">&times;</button></div><form method="POST" id="aidRejectionForm"><input type="hidden" name="aid_request_id" id="rejectAidRequestId"><div class="modal-body"><div class="form-group"><label class="form-label">Reason for Rejection:</label><textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a clear reason..."></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideAidRejectionForm()">Cancel</button><button type="submit" class="btn btn-danger">Confirm Rejection</button></div></form></div></div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.documentElement.setAttribute('data-theme', isDark ? 'light' : 'dark');
            localStorage.setItem('theme', isDark ? 'light' : 'dark');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
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

        // Mobile Menu
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

        // Modal Functions
        function showRejectionForm(id) { document.getElementById('rejectTransactionId').value = id; document.getElementById('rejectionForm').action = 'finance.php?action=reject&id=' + id; document.getElementById('rejectionModal').classList.add('active'); }
        function hideRejectionForm() { document.getElementById('rejectionModal').classList.remove('active'); }
        function showBudgetRejectionForm(id) { document.getElementById('rejectBudgetRequestId').value = id; document.getElementById('budgetRejectionForm').action = 'finance.php?action=reject_budget&budget_id=' + id; document.getElementById('budgetRejectionModal').classList.add('active'); }
        function hideBudgetRejectionForm() { document.getElementById('budgetRejectionModal').classList.remove('active'); }
        function showAidRejectionForm(id) { document.getElementById('rejectAidRequestId').value = id; document.getElementById('aidRejectionForm').action = 'finance.php?action=reject_aid&aid_id=' + id; document.getElementById('aidRejectionModal').classList.add('active'); }
        function hideAidRejectionForm() { document.getElementById('aidRejectionModal').classList.remove('active'); }
        
        window.addEventListener('click', function(e) {
            if (e.target === document.getElementById('rejectionModal')) hideRejectionForm();
            if (e.target === document.getElementById('budgetRejectionModal')) hideBudgetRejectionForm();
            if (e.target === document.getElementById('aidRejectionModal')) hideAidRejectionForm();
        });
    </script>
</body>
</html>