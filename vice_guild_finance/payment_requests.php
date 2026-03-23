<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['vice_guild_finance', 'guild_president', 'minister_sports', 'minister_environment', 'minister_health', 'minister_culture', 'minister_gender'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_request'])) {
        // Submit new payment request
        try {
            $title = $_POST['title'];
            $description = $_POST['description'];
            $amount = $_POST['amount'];
            $category_id = $_POST['category_id'];
            $account_id = $_POST['account_id'];
            $payee_name = $_POST['payee_name'];
            $payee_details = $_POST['payee_details'];
            $due_date = $_POST['due_date'];
            $priority = $_POST['priority'];
            
            $status = $user_role === 'vice_guild_finance' ? 'submitted' : 'draft';
            
            $stmt = $pdo->prepare("
                INSERT INTO payment_requests 
                (requested_by, title, description, amount, category_id, account_id, payee_name, payee_details, due_date, priority, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $title, $description, $amount, $category_id, $account_id, $payee_name, $payee_details, $due_date, $priority, $status]);
            
            $_SESSION['success_message'] = "Payment request submitted successfully!";
            header('Location: payment_requests.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error submitting payment request: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_request'])) {
        // Update payment request
        try {
            $request_id = $_POST['request_id'];
            $title = $_POST['title'];
            $description = $_POST['description'];
            $amount = $_POST['amount'];
            $category_id = $_POST['category_id'];
            $account_id = $_POST['account_id'];
            $payee_name = $_POST['payee_name'];
            $payee_details = $_POST['payee_details'];
            $due_date = $_POST['due_date'];
            $priority = $_POST['priority'];
            
            $stmt = $pdo->prepare("
                UPDATE payment_requests 
                SET title = ?, description = ?, amount = ?, category_id = ?, account_id = ?, 
                    payee_name = ?, payee_details = ?, due_date = ?, priority = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND requested_by = ?
            ");
            $stmt->execute([$title, $description, $amount, $category_id, $account_id, $payee_name, $payee_details, $due_date, $priority, $request_id, $user_id]);
            
            $_SESSION['success_message'] = "Payment request updated successfully!";
            header('Location: payment_requests.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating payment request: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['submit_draft'])) {
        // Submit draft for approval
        try {
            $request_id = $_POST['request_id'];
            
            $stmt = $pdo->prepare("
                UPDATE payment_requests 
                SET status = 'submitted', updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND requested_by = ?
            ");
            $stmt->execute([$request_id, $user_id]);
            
            $_SESSION['success_message'] = "Payment request submitted for approval!";
            header('Location: payment_requests.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error submitting payment request: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['approve_request']) && in_array($user_role, ['vice_guild_finance', 'guild_president'])) {
        // Approve payment request
        try {
            $request_id = $_POST['request_id'];
            $notes = $_POST['approval_notes'] ?? '';
            
            $stmt = $pdo->prepare("
                UPDATE payment_requests 
                SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $request_id]);
            
            $_SESSION['success_message'] = "Payment request approved successfully!";
            header('Location: payment_requests.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error approving payment request: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_request']) && in_array($user_role, ['vice_guild_finance', 'guild_president'])) {
        // Reject payment request
        try {
            $request_id = $_POST['request_id'];
            $notes = $_POST['rejection_notes'] ?? '';
            
            $stmt = $pdo->prepare("
                UPDATE payment_requests 
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $request_id]);
            
            $_SESSION['success_message'] = "Payment request rejected!";
            header('Location: payment_requests.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error rejecting payment request: " . $e->getMessage();
        }
    }
}

// Get payment requests data
try {
    // Base query for payment requests
    if (in_array($user_role, ['vice_guild_finance', 'guild_president'])) {
        // Finance roles can see all requests
        $stmt = $pdo->prepare("
            SELECT pr.*, 
                   u.full_name as requester_name,
                   u.role as requester_role,
                   bc.category_name,
                   fa.account_name,
                   fa.bank_name,
                   approver.full_name as approver_name
            FROM payment_requests pr
            LEFT JOIN users u ON pr.requested_by = u.id
            LEFT JOIN budget_categories bc ON pr.category_id = bc.id
            LEFT JOIN financial_accounts fa ON pr.account_id = fa.id
            LEFT JOIN users approver ON pr.approved_by = approver.id
            ORDER BY 
                CASE 
                    WHEN pr.status = 'submitted' THEN 1
                    WHEN pr.status = 'under_review' THEN 2
                    WHEN pr.status = 'draft' THEN 3
                    WHEN pr.status = 'approved' THEN 4
                    WHEN pr.status = 'rejected' THEN 5
                    WHEN pr.status = 'paid' THEN 6
                    ELSE 7
                END,
                CASE pr.priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                END,
                pr.due_date ASC,
                pr.created_at DESC
        ");
        $stmt->execute();
    } else {
        // Other roles can only see their own requests
        $stmt = $pdo->prepare("
            SELECT pr.*, 
                   u.full_name as requester_name,
                   u.role as requester_role,
                   bc.category_name,
                   fa.account_name,
                   fa.bank_name,
                   approver.full_name as approver_name
            FROM payment_requests pr
            LEFT JOIN users u ON pr.requested_by = u.id
            LEFT JOIN budget_categories bc ON pr.category_id = bc.id
            LEFT JOIN financial_accounts fa ON pr.account_id = fa.id
            LEFT JOIN users approver ON pr.approved_by = approver.id
            WHERE pr.requested_by = ?
            ORDER BY 
                CASE 
                    WHEN pr.status = 'submitted' THEN 1
                    WHEN pr.status = 'under_review' THEN 2
                    WHEN pr.status = 'draft' THEN 3
                    WHEN pr.status = 'approved' THEN 4
                    WHEN pr.status = 'rejected' THEN 5
                    WHEN pr.status = 'paid' THEN 6
                    ELSE 7
                END,
                CASE pr.priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                END,
                pr.due_date ASC,
                pr.created_at DESC
        ");
        $stmt->execute([$user_id]);
    }
    $payment_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Budget categories for dropdown
    $stmt = $pdo->query("
        SELECT * FROM budget_categories 
        WHERE is_active = 1 AND parent_category_id IS NOT NULL
        ORDER BY parent_category_id, category_name
    ");
    $budget_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Financial accounts for dropdown
    $stmt = $pdo->query("
        SELECT * FROM financial_accounts 
        WHERE is_active = 1
        ORDER BY account_type, account_name
    ");
    $financial_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistics
    $total_requests = count($payment_requests);
    $pending_requests = array_filter($payment_requests, function($req) {
        return in_array($req['status'], ['submitted', 'under_review']);
    });
    $approved_requests = array_filter($payment_requests, function($req) {
        return $req['status'] === 'approved';
    });
    $total_amount_pending = array_sum(array_column($pending_requests, 'amount'));
    $total_amount_approved = array_sum(array_column($approved_requests, 'amount'));

} catch (PDOException $e) {
    error_log("Payment requests error: " . $e->getMessage());
    $payment_requests = $budget_categories = $financial_accounts = [];
    $total_requests = $pending_requests_count = $approved_requests_count = $total_amount_pending = $total_amount_approved = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Requests - Isonga RPSU</title>
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

        .status-submitted {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-under_review {
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

        .status-paid {
            background: #d1ecf1;
            color: #0c5460;
        }

        .priority-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .priority-urgent {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-high {
            background: #ffeaa7;
            color: #e17055;
        }

        .priority-medium {
            background: #a29bfe;
            color: white;
        }

        .priority-low {
            background: #dfe6e9;
            color: var(--dark-gray);
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
            box-shadow: var(--shadow-lg);
            max-height: 90vh;
            overflow-y: auto;
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
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .due-date {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .due-date.overdue {
            color: var(--danger);
            font-weight: 600;
        }

        .requester-info {
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
                    <h1>Isonga - Payment Requests</h1>
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
                        <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></div>
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
                    <a href="payment_requests.php" class="active">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Payment Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="accounts.php">
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
                    <h1>Payment Requests 💳</h1>
                    <p>Manage payment requests and approvals for various activities and expenses</p>
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

            <!-- Payment Request Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_requests; ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($pending_requests); ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($approved_requests); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_amount_pending, 2); ?></div>
                        <div class="stat-label">Pending Amount</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab(event, 'all-requests')">All Requests</button>
                <button class="tab" onclick="openTab(event, 'new-request')">New Request</button>
                <?php if (in_array($user_role, ['vice_guild_finance', 'guild_president'])): ?>
                <button class="tab" onclick="openTab(event, 'approval-queue')">Approval Queue</button>
                <?php endif; ?>
            </div>

            <!-- All Requests Tab -->
            <div id="all-requests" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>All Payment Requests</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="exportPaymentRequests()" title="Export Data">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($payment_requests)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                No payment requests found.
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Due Date</th>
                                        <th>Requester</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_requests as $request): ?>
                                        <tr>
                                            <td>#<?php echo $request['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['title']); ?></strong>
                                                <?php if ($request['description']): ?>
                                                    <br><small style="color: var(--dark-gray);"><?php echo substr(htmlspecialchars($request['description']), 0, 50); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="amount">RWF <?php echo number_format($request['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($request['category_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                    <?php echo ucfirst($request['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="due-date <?php echo (strtotime($request['due_date']) < time() && !in_array($request['status'], ['paid', 'rejected'])) ? 'overdue' : ''; ?>">
                                                    <?php echo date('M j, Y', strtotime($request['due_date'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="requester-info">
                                                    <?php echo htmlspecialchars($request['requester_name']); ?>
                                                    <br><small><?php echo ucfirst(str_replace('_', ' ', $request['requester_role'])); ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-primary btn-sm" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($request['requested_by'] == $user_id && $request['status'] == 'draft'): ?>
                                                        <button class="btn btn-warning btn-sm" onclick="editRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                            <button type="submit" name="submit_draft" class="btn btn-success btn-sm">
                                                                <i class="fas fa-paper-plane"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (in_array($user_role, ['vice_guild_finance', 'guild_president']) && $request['status'] == 'submitted'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="approveRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-times"></i>
                                                        </button>
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

            <!-- New Request Tab -->
            <div id="new-request" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Create New Payment Request</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="paymentRequestForm">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label" for="title">Request Title *</label>
                                    <input type="text" class="form-control" id="title" name="title" required 
                                           placeholder="Enter payment request title">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="amount">Amount (RWF) *</label>
                                    <input type="number" class="form-control" id="amount" name="amount" 
                                           step="0.01" min="0" required placeholder="Enter amount">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="description">Description *</label>
                                <textarea class="form-control" id="description" name="description" rows="3" 
                                          required placeholder="Describe the purpose of this payment"></textarea>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label" for="category_id">Budget Category *</label>
                                    <select class="form-control" id="category_id" name="category_id" required>
                                        <option value="">Select a category</option>
                                        <?php foreach ($budget_categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>">
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="account_id">Bank Account</label>
                                    <select class="form-control" id="account_id" name="account_id">
                                        <option value="">Select account (optional)</option>
                                        <?php foreach ($financial_accounts as $account): ?>
                                            <option value="<?php echo $account['id']; ?>">
                                                <?php echo htmlspecialchars($account['account_name']); ?> - <?php echo htmlspecialchars($account['bank_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label" for="payee_name">Payee Name *</label>
                                    <input type="text" class="form-control" id="payee_name" name="payee_name" 
                                           required placeholder="Enter recipient name">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="due_date">Due Date *</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" 
                                           required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="payee_details">Payee Details</label>
                                <textarea class="form-control" id="payee_details" name="payee_details" rows="2" 
                                          placeholder="Bank account details, contact information, etc."></textarea>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label" for="priority">Priority *</label>
                                    <select class="form-control" id="priority" name="priority" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" name="submit_request" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Submit Request
                                </button>
                                <button type="reset" class="btn" style="background: var(--light-gray);">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Approval Queue Tab (Finance roles only) -->
            <?php if (in_array($user_role, ['vice_guild_finance', 'guild_president'])): ?>
            <div id="approval-queue" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Approval Queue</h3>
                    </div>
                    <div class="card-body">
                        <?php 
                        $pending_approval = array_filter($payment_requests, function($req) {
                            return $req['status'] === 'submitted';
                        });
                        ?>
                        <?php if (empty($pending_approval)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                No pending requests for approval.
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Due Date</th>
                                        <th>Requester</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_approval as $request): ?>
                                        <tr>
                                            <td>#<?php echo $request['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['title']); ?></strong>
                                                <?php if ($request['description']): ?>
                                                    <br><small style="color: var(--dark-gray);"><?php echo substr(htmlspecialchars($request['description']), 0, 50); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="amount">RWF <?php echo number_format($request['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($request['category_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                    <?php echo ucfirst($request['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="due-date <?php echo (strtotime($request['due_date']) < time()) ? 'overdue' : ''; ?>">
                                                    <?php echo date('M j, Y', strtotime($request['due_date'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="requester-info">
                                                    <?php echo htmlspecialchars($request['requester_name']); ?>
                                                    <br><small><?php echo ucfirst(str_replace('_', ' ', $request['requester_role'])); ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-primary btn-sm" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-success btn-sm" onclick="approveRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?php echo $request['id']; ?>)">
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
            <?php endif; ?>
        </main>
    </div>

    <!-- View Request Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Payment Request Details</h3>
                <button onclick="closeViewModal()" class="card-header-btn">&times;</button>
            </div>
            <div class="card-body">
                <div id="requestDetails"></div>
            </div>
        </div>
    </div>

    <!-- Approve/Reject Modal -->
    <div id="actionModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3 id="actionModalTitle">Approve Request</h3>
                <button onclick="closeActionModal()" class="card-header-btn">&times;</button>
            </div>
            <div class="card-body">
                <form method="POST" id="actionForm">
                    <input type="hidden" id="action_request_id" name="request_id">
                    <div class="form-group">
                        <label class="form-label" id="actionNotesLabel">Approval Notes (Optional)</label>
                        <textarea class="form-control" id="action_notes" name="approval_notes" rows="3" 
                                  placeholder="Add any notes about this decision"></textarea>
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" id="actionSubmit" name="approve_request" class="btn btn-success">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn" style="background: var(--light-gray);" onclick="closeActionModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
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
            evt.currentTarget.classList.add("active");
        }

        // Modal functions
        function viewRequest(requestId) {
            // In real implementation, fetch request details via AJAX
            // For now, show a simple message
            document.getElementById('requestDetails').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-info-circle" style="font-size: 3rem; color: var(--finance-primary); margin-bottom: 1rem;"></i>
                    <p>Loading request details for #${requestId}...</p>
                    <p style="color: var(--dark-gray); font-size: 0.8rem;">This would show complete request information in a real implementation.</p>
                </div>
            `;
            document.getElementById('viewModal').style.display = 'block';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function approveRequest(requestId) {
            document.getElementById('actionModalTitle').textContent = 'Approve Payment Request';
            document.getElementById('actionNotesLabel').textContent = 'Approval Notes (Optional)';
            document.getElementById('actionSubmit').innerHTML = '<i class="fas fa-check"></i> Approve';
            document.getElementById('actionSubmit').name = 'approve_request';
            document.getElementById('action_request_id').value = requestId;
            document.getElementById('actionModal').style.display = 'block';
        }

        function rejectRequest(requestId) {
            document.getElementById('actionModalTitle').textContent = 'Reject Payment Request';
            document.getElementById('actionNotesLabel').textContent = 'Rejection Notes (Optional)';
            document.getElementById('actionSubmit').innerHTML = '<i class="fas fa-times"></i> Reject';
            document.getElementById('actionSubmit').name = 'reject_request';
            document.getElementById('action_request_id').value = requestId;
            document.getElementById('actionModal').style.display = 'block';
        }

        function closeActionModal() {
            document.getElementById('actionModal').style.display = 'none';
        }

        function editRequest(requestId) {
            alert(`Edit functionality for request #${requestId} would open here.`);
            // In real implementation, this would open an edit form with pre-filled data
        }

        function exportPaymentRequests() {
            alert('Export functionality would generate a CSV/Excel file of payment requests.');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewModal');
            const actionModal = document.getElementById('actionModal');
            
            if (event.target === viewModal) {
                closeViewModal();
            }
            if (event.target === actionModal) {
                closeActionModal();
            }
        }

        // Set minimum due date to today
        document.getElementById('due_date').min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>