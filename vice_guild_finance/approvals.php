
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

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_transaction'])) {
        try {
            $transaction_id = $_POST['transaction_id'];
            $comments = $_POST['comments'] ?? '';
            
            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $transaction_id]);
            
            // Record approval
            $stmt = $pdo->prepare("
                INSERT INTO transaction_approvals 
                (transaction_id, approver_id, status, comments, approved_at)
                VALUES (?, ?, 'approved', ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$transaction_id, $user_id, $comments]);
            
            $_SESSION['success_message'] = "Transaction approved successfully!";
            header('Location: approvals.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error approving transaction: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_transaction'])) {
        try {
            $transaction_id = $_POST['transaction_id'];
            $rejection_reason = $_POST['rejection_reason'];
            
            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'rejected', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$rejection_reason, $transaction_id]);
            
            // Record rejection
            $stmt = $pdo->prepare("
                INSERT INTO transaction_approvals 
                (transaction_id, approver_id, status, comments, approved_at)
                VALUES (?, ?, 'rejected', ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$transaction_id, $user_id, $rejection_reason]);
            
            $_SESSION['success_message'] = "Transaction rejected successfully!";
            header('Location: approvals.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error rejecting transaction: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['bulk_approve'])) {
        try {
            $transaction_ids = $_POST['transaction_ids'] ?? [];
            $comments = $_POST['bulk_comments'] ?? '';
            
            if (!empty($transaction_ids)) {
                $placeholders = str_repeat('?,', count($transaction_ids) - 1) . '?';
                
                // Update transactions status
                $stmt = $pdo->prepare("
                    UPDATE financial_transactions 
                    SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$user_id], $transaction_ids));
                
                // Record approvals
                foreach ($transaction_ids as $transaction_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO transaction_approvals 
                        (transaction_id, approver_id, status, comments, approved_at)
                        VALUES (?, ?, 'approved', ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$transaction_id, $user_id, $comments]);
                }
                
                $_SESSION['success_message'] = count($transaction_ids) . " transactions approved successfully!";
            }
            header('Location: approvals.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error bulk approving transactions: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['request_revision'])) {
        try {
            $transaction_id = $_POST['transaction_id'];
            $revision_notes = $_POST['revision_notes'];
            
            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'draft', revision_notes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$revision_notes, $transaction_id]);
            
            $_SESSION['success_message'] = "Revision requested successfully!";
            header('Location: approvals.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error requesting revision: " . $e->getMessage();
        }
    }
}

// Handle filters
$filter_type = $_GET['type'] ?? 'all';
$filter_urgency = $_GET['urgency'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query for pending approvals
$query = "
    SELECT ft.*, 
           bc.category_name,
           fa.account_name,
           fa.account_number,
           u_req.full_name as requested_by_name,
           u_req.department as requester_department,
           DATEDIFF(CURDATE(), ft.created_at) as days_pending,
           CASE 
               WHEN DATEDIFF(CURDATE(), ft.created_at) > 7 THEN 'high'
               WHEN DATEDIFF(CURDATE(), ft.created_at) > 3 THEN 'medium'
               ELSE 'low'
           END as urgency_level
    FROM financial_transactions ft
    LEFT JOIN budget_categories bc ON ft.category_id = bc.id
    LEFT JOIN financial_accounts fa ON ft.account_id = fa.id
    LEFT JOIN users u_req ON ft.requested_by = u_req.id
    WHERE ft.status = 'pending_approval'
";

$params = [];

if ($filter_type !== 'all') {
    $query .= " AND ft.transaction_type = ?";
    $params[] = $filter_type;
}

if ($filter_urgency !== 'all') {
    if ($filter_urgency === 'high') {
        $query .= " AND DATEDIFF(CURDATE(), ft.created_at) > 7";
    } elseif ($filter_urgency === 'medium') {
        $query .= " AND DATEDIFF(CURDATE(), ft.created_at) BETWEEN 4 AND 7";
    } else {
        $query .= " AND DATEDIFF(CURDATE(), ft.created_at) <= 3";
    }
}

if ($filter_category !== 'all') {
    $query .= " AND ft.category_id = ?";
    $params[] = $filter_category;
}

if (!empty($search)) {
    $query .= " AND (ft.description LIKE ? OR ft.reference_number LIKE ? OR u_req.full_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY 
    CASE 
        WHEN DATEDIFF(CURDATE(), ft.created_at) > 7 THEN 1
        WHEN DATEDIFF(CURDATE(), ft.created_at) > 3 THEN 2
        ELSE 3
    END, ft.created_at ASC";

// Get pending approvals data
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $pending_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get approval statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_pending,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) > 7 THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 4 AND 7 THEN 1 ELSE 0 END) as medium_priority,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) <= 3 THEN 1 ELSE 0 END) as low_priority,
            SUM(CASE WHEN transaction_type = 'expense' THEN 1 ELSE 0 END) as expense_count,
            SUM(CASE WHEN transaction_type = 'income' THEN 1 ELSE 0 END) as income_count,
            SUM(amount) as total_amount_pending
        FROM financial_transactions 
        WHERE status = 'pending_approval'
    ");
    $stmt->execute();
    $approval_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get recent approval history
    $stmt = $pdo->query("
        SELECT 
            ta.*,
            ft.description,
            ft.amount,
            ft.transaction_type,
            u_app.full_name as approver_name,
            u_req.full_name as requester_name
        FROM transaction_approvals ta
        LEFT JOIN financial_transactions ft ON ta.transaction_id = ft.id
        LEFT JOIN users u_app ON ta.approver_id = u_app.id
        LEFT JOIN users u_req ON ft.requested_by = u_req.id
        ORDER BY ta.approved_at DESC 
        LIMIT 10
    ");
    $approval_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get filter options
    $stmt = $pdo->query("SELECT DISTINCT transaction_type FROM financial_transactions WHERE status = 'pending_approval'");
    $transaction_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Budget categories
    $stmt = $pdo->query("
        SELECT id, category_name 
        FROM budget_categories 
        WHERE is_active = 1 
        ORDER BY category_name
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // User approval statistics
    $stmt = $pdo->query("
        SELECT 
            u.full_name,
            COUNT(ta.id) as approvals_count,
            SUM(CASE WHEN ta.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN ta.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
        FROM transaction_approvals ta
        LEFT JOIN users u ON ta.approver_id = u.id
        WHERE ta.approved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY u.id, u.full_name
        ORDER BY approvals_count DESC
        LIMIT 5
    ");
    $user_approval_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly approval trends
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(approved_at, '%Y-%m') as month,
            COUNT(*) as approval_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
        FROM transaction_approvals 
        WHERE approved_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(approved_at, '%Y-%m')
        ORDER BY month DESC
    ");
    $approval_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Approvals error: " . $e->getMessage());
    $pending_approvals = $approval_history = $user_approval_stats = $approval_trends = [];
    $approval_stats = [
        'total_pending' => 0,
        'overdue' => 0,
        'medium_priority' => 0,
        'low_priority' => 0,
        'expense_count' => 0,
        'income_count' => 0,
        'total_amount_pending' => 0
    ];
    $transaction_types = $categories = [];
}

// Calculate additional metrics
$approval_rate = $approval_stats['total_pending'] > 0 ? 
    round(($approval_stats['total_pending'] / ($approval_stats['total_pending'] + count($approval_history))) * 100, 1) : 0;
$avg_processing_time = 2.5; // Simulated average processing time in days
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approvals Management - Isonga RPSU</title>
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

        .status-pending_approval {
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

        .status-draft {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .urgency-high {
            background: #f8d7da;
            color: var(--danger);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .urgency-medium {
            background: #fff3cd;
            color: var(--warning);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .urgency-low {
            background: #d4edda;
            color: var(--success);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
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

        /* Filters */
        .filters {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
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

        /* Approval Actions */
        .approval-actions {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: var(--finance-light);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: none;
        }

        .bulk-actions.active {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .selected-count {
            font-weight: 600;
            color: var(--finance-primary);
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-input {
            width: 16px;
            height: 16px;
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
            
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .approval-actions {
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

        /* Transaction Details */
        .transaction-details {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-dark);
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
                    <h1>Isonga - Approvals</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($approval_stats['total_pending'] > 0): ?>
                            <span class="notification-badge"><?php echo $approval_stats['total_pending']; ?></span>
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
                    <a href="approvals.php" class="active">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Approvals</span>
                        <?php if ($approval_stats['total_pending'] > 0): ?>
                            <span class="menu-badge"><?php echo $approval_stats['total_pending']; ?></span>
                        <?php endif; ?>
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
                    <h1>Approvals Management ✅</h1>
                    <p>Review and approve pending financial transactions and requests</p>
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

            <!-- Approvals Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approval_stats['total_pending']; ?></div>
                        <div class="stat-label">Pending Approvals</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-exclamation-circle"></i> Needs attention
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approval_stats['overdue']; ?></div>
                        <div class="stat-label">Overdue (>7 days)</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-arrow-up"></i> High priority
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($approval_stats['total_amount_pending'], 2); ?></div>
                        <div class="stat-label">Total Amount Pending</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-balance-scale"></i> Awaiting approval
                        </div>
                    </div>
                </div>
                <div class="stat-card <?php echo $avg_processing_time > 3 ? 'danger' : 'success'; ?>">
                    <div class="stat-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $avg_processing_time; ?> days</div>
                        <div class="stat-label">Avg. Processing Time</div>
                        <div class="stat-trend <?php echo $avg_processing_time > 3 ? 'trend-negative' : 'trend-positive'; ?>">
                            <i class="fas fa-<?php echo $avg_processing_time > 3 ? 'exclamation' : 'check'; ?>-circle"></i>
                            <?php echo $avg_processing_time > 3 ? 'Slow' : 'Good'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approval_stats['expense_count']; ?></div>
                        <div class="stat-label">Expense Requests</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-file-invoice"></i> Payments pending
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approval_stats['income_count']; ?></div>
                        <div class="stat-label">Income Records</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-donate"></i> Revenue pending
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approval_rate; ?>%</div>
                        <div class="stat-label">Approval Rate</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-percentage"></i> Efficiency
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approval_stats['medium_priority']; ?></div>
                        <div class="stat-label">Medium Priority</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-clock"></i> 4-7 days old
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <!-- Approval Trends Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Approval Trends</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="approvalTrendsChart"></canvas>
                    </div>
                </div>

                <!-- Priority Distribution Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Priority Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="priorityChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab(event, 'pending-approvals')">
                    Pending Approvals
                    <?php if ($approval_stats['total_pending'] > 0): ?>
                        <span style="background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; margin-left: 0.5rem;">
                            <?php echo $approval_stats['total_pending']; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="tab" onclick="openTab(event, 'approval-history')">Approval History</button>
                <button class="tab" onclick="openTab(event, 'team-performance')">Team Performance</button>
                <button class="tab" onclick="openTab(event, 'reports')">Reports</button>
            </div>

            <!-- Bulk Actions -->
            <div id="bulkActions" class="bulk-actions">
                <div class="selected-count" id="selectedCount">0 items selected</div>
                <div class="filter-actions">
                    <button class="btn btn-success btn-sm" onclick="bulkApprove()">
                        <i class="fas fa-check"></i> Approve Selected
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times"></i> Clear Selection
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Transaction Type</label>
                            <select class="form-control" name="type" onchange="document.getElementById('filterForm').submit()">
                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <?php foreach ($transaction_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Urgency Level</label>
                            <select class="form-control" name="urgency" onchange="document.getElementById('filterForm').submit()">
                                <option value="all" <?php echo $filter_urgency === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="high" <?php echo $filter_urgency === 'high' ? 'selected' : ''; ?>>High (Overdue)</option>
                                <option value="medium" <?php echo $filter_urgency === 'medium' ? 'selected' : ''; ?>>Medium (4-7 days)</option>
                                <option value="low" <?php echo $filter_urgency === 'low' ? 'selected' : ''; ?>>Low (1-3 days)</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Category</label>
                            <select class="form-control" name="category" onchange="document.getElementById('filterForm').submit()">
                                <option value="all" <?php echo $filter_category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search description, reference, or requester...">
                        </div>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group" style="justify-content: flex-end;">
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="approvals.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Pending Approvals Tab -->
            <div id="pending-approvals" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Pending Approvals (<?php echo count($pending_approvals); ?>)</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="selectAllTransactions()" title="Select All">
                                <i class="fas fa-check-square"></i>
                            </button>
                            <button class="card-header-btn" onclick="exportApprovals()" title="Export Data">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_approvals)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success); margin-bottom: 1rem;"></i>
                                <h3>No Pending Approvals</h3>
                                <p>All transactions have been processed. Great work!</p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Action Required:</strong> You have <?php echo count($pending_approvals); ?> transactions waiting for your approval. 
                                Please review and take action promptly.
                            </div>
                            
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30px;">
                                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                            </th>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Requester</th>
                                            <th>Category</th>
                                            <th>Amount</th>
                                            <th>Days Pending</th>
                                            <th>Priority</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_approvals as $approval): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="transaction-checkbox" value="<?php echo $approval['id']; ?>" onchange="updateBulkActions()">
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($approval['transaction_date'])); ?></td>
                                                <td>
                                                    <div style="max-width: 200px;">
                                                        <strong><?php echo htmlspecialchars($approval['description']); ?></strong>
                                                        <?php if (!empty($approval['reference_number'])): ?>
                                                            <br><small style="color: var(--dark-gray);">Ref: <?php echo htmlspecialchars($approval['reference_number']); ?></small>
                                                        <?php endif; ?>
                                                        <br><small style="color: var(--dark-gray);"><?php echo htmlspecialchars($approval['account_name']); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($approval['requested_by_name']); ?>
                                                    <br><small style="color: var(--dark-gray);"><?php echo htmlspecialchars($approval['requester_department']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($approval['category_name']); ?></td>
                                                <td>
                                                    <span class="amount <?php echo $approval['transaction_type']; ?>">
                                                        RWF <?php echo number_format($approval['amount'], 2); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span style="font-weight: 600; color: <?php echo $approval['days_pending'] > 7 ? 'var(--danger)' : ($approval['days_pending'] > 3 ? 'var(--warning)' : 'var(--success)'); ?>;">
                                                        <?php echo $approval['days_pending']; ?> days
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="urgency-<?php echo $approval['urgency_level']; ?>">
                                                        <?php echo ucfirst($approval['urgency_level']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="approval-actions">
                                                        <button class="btn btn-sm btn-secondary" onclick="viewTransaction(<?php echo $approval['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                        <button class="btn btn-sm btn-success" onclick="approveTransaction(<?php echo $approval['id']; ?>)">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="rejectTransaction(<?php echo $approval['id']; ?>)">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                        <button class="btn btn-sm btn-warning" onclick="requestRevision(<?php echo $approval['id']; ?>)">
                                                            <i class="fas fa-edit"></i> Revise
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

            <!-- Approval History Tab -->
            <div id="approval-history" class="tab-content">
                <div class="content-grid">
                    <div class="left-column">
                        <div class="card">
                            <div class="card-header">
                                <h3>Approval History</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($approval_history)): ?>
                                    <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        <i class="fas fa-history" style="font-size: 3rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                                        <h3>No Approval History</h3>
                                        <p>Approval decisions will appear here once you start processing requests.</p>
                                    </div>
                                <?php else: ?>
                                    <div style="overflow-x: auto;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Transaction</th>
                                                    <th>Requester</th>
                                                    <th>Approver</th>
                                                    <th>Amount</th>
                                                    <th>Decision</th>
                                                    <th>Comments</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($approval_history as $history): ?>
                                                    <tr>
                                                        <td><?php echo date('M j, Y H:i', strtotime($history['approved_at'])); ?></td>
                                                        <td style="max-width: 200px;">
                                                            <?php echo htmlspecialchars($history['description']); ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($history['requester_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($history['approver_name']); ?></td>
                                                        <td class="amount <?php echo $history['transaction_type']; ?>">
                                                            RWF <?php echo number_format($history['amount'], 2); ?>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $history['status']; ?>">
                                                                <?php echo ucfirst($history['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($history['comments'] ?? 'N/A'); ?></td>
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
                        <!-- Quick Stats -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Approval Statistics</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div class="detail-item">
                                        <span class="detail-label">Total Processed</span>
                                        <span class="detail-value"><?php echo count($approval_history); ?> transactions</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Approval Rate</span>
                                        <span class="detail-value"><?php echo $approval_rate; ?>%</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Avg. Processing Time</span>
                                        <span class="detail-value"><?php echo $avg_processing_time; ?> days</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Current Backlog</span>
                                        <span class="detail-value"><?php echo $approval_stats['total_pending']; ?> pending</span>
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
                                    <a href="#pending-approvals" class="action-btn" onclick="openTab(event, 'pending-approvals')">
                                        <i class="fas fa-clipboard-list"></i>
                                        <span class="action-label">Pending Items</span>
                                    </a>
                                    <a href="transactions.php" class="action-btn">
                                        <i class="fas fa-exchange-alt"></i>
                                        <span class="action-label">All Transactions</span>
                                    </a>
                                    <a href="#" class="action-btn" onclick="printApprovalReport()">
                                        <i class="fas fa-print"></i>
                                        <span class="action-label">Print Report</span>
                                    </a>
                                    <a href="#" class="action-btn" onclick="exportApprovalHistory()">
                                        <i class="fas fa-download"></i>
                                        <span class="action-label">Export Data</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Team Performance Tab -->
            <div id="team-performance" class="tab-content">
                <div class="content-grid">
                    <div class="left-column">
                        <div class="card">
                            <div class="card-header">
                                <h3>Team Approval Performance</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($user_approval_stats)): ?>
                                    <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                        <i class="fas fa-users" style="font-size: 3rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                                        <h3>No Team Data Available</h3>
                                        <p>Team performance data will appear here as approvals are processed.</p>
                                    </div>
                                <?php else: ?>
                                    <div style="overflow-x: auto;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Approver</th>
                                                    <th>Total Actions</th>
                                                    <th>Approved</th>
                                                    <th>Rejected</th>
                                                    <th>Approval Rate</th>
                                                    <th>Last Activity</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($user_approval_stats as $user): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                        <td><?php echo $user['approvals_count']; ?></td>
                                                        <td>
                                                            <span style="color: var(--success); font-weight: 600;">
                                                                <?php echo $user['approved_count']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span style="color: var(--danger); font-weight: 600;">
                                                                <?php echo $user['rejected_count']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $user_approval_rate = $user['approvals_count'] > 0 ? 
                                                                round(($user['approved_count'] / $user['approvals_count']) * 100, 1) : 0;
                                                            ?>
                                                            <span style="font-weight: 600; color: <?php echo $user_approval_rate > 80 ? 'var(--success)' : ($user_approval_rate > 60 ? 'var(--warning)' : 'var(--danger)'); ?>;">
                                                                <?php echo $user_approval_rate; ?>%
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y'); ?></td>
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
                        <!-- Performance Metrics -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Performance Metrics</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div class="alert alert-success">
                                        <i class="fas fa-tachometer-alt"></i>
                                        <strong>Processing Efficiency</strong><br>
                                        Monitor and improve approval processing times
                                    </div>
                                    <div class="alert alert-info">
                                        <i class="fas fa-chart-line"></i>
                                        <strong>Quality Metrics</strong><br>
                                        Track approval accuracy and decision quality
                                    </div>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-balance-scale"></i>
                                        <strong>Workload Distribution</strong><br>
                                        Ensure fair distribution of approval tasks
                                    </div>
                                </div>
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
                                <h3>Approval Reports</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 1rem;">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        Generate comprehensive approval reports for analysis and auditing.
                                    </div>
                                    
                                    <div class="quick-actions">
                                        <a href="financial_reports.php?type=approval_summary" class="action-btn">
                                            <i class="fas fa-file-alt"></i>
                                            <span class="action-label">Approval Summary</span>
                                        </a>
                                        <a href="financial_reports.php?type=performance" class="action-btn">
                                            <i class="fas fa-chart-bar"></i>
                                            <span class="action-label">Performance Report</span>
                                        </a>
                                        <a href="financial_reports.php?type=backlog" class="action-btn">
                                            <i class="fas fa-clock"></i>
                                            <span class="action-label">Backlog Analysis</span>
                                        </a>
                                        <a href="#" class="action-btn" onclick="generateCustomApprovalReport()">
                                            <i class="fas fa-cog"></i>
                                            <span class="action-label">Custom Report</span>
                                        </a>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Report Period</label>
                                        <select class="form-control" id="approval_report_period">
                                            <option value="current_week">Current Week</option>
                                            <option value="current_month">Current Month</option>
                                            <option value="current_quarter">Current Quarter</option>
                                            <option value="current_year">Current Year</option>
                                            <option value="custom">Custom Period</option>
                                        </select>
                                    </div>

                                    <div class="form-group" id="approval_custom_period" style="display: none;">
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                            <div>
                                                <label class="form-label">Start Date</label>
                                                <input type="date" class="form-control" id="approval_start_date">
                                            </div>
                                            <div>
                                                <label class="form-label">End Date</label>
                                                <input type="date" class="form-control" id="approval_end_date">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Report Format</label>
                                        <select class="form-control" id="approval_report_format">
                                            <option value="pdf">PDF Document</option>
                                            <option value="excel">Excel Spreadsheet</option>
                                            <option value="csv">CSV File</option>
                                        </select>
                                    </div>

                                    <button class="btn btn-primary" onclick="generateApprovalReport()">
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
                                        <strong>Approval Summary Report</strong><br>
                                        Comprehensive overview of all approval activities and decisions.
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <i class="fas fa-chart-line"></i>
                                        <strong>Performance Report</strong><br>
                                        Analysis of approval efficiency and team performance.
                                    </div>
                                    
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Backlog Analysis</strong><br>
                                        Detailed analysis of pending approvals and bottlenecks.
                                    </div>
                                    
                                    <div class="alert alert-secondary">
                                        <i class="fas fa-cogs"></i>
                                        <strong>Custom Report Builder</strong><br>
                                        Create customized reports with specific parameters.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- View Transaction Modal -->
    <div id="viewTransactionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Transaction Details</h3>
                <button class="card-header-btn" onclick="closeViewTransactionModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewTransactionModalBody">
                <!-- Transaction details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeViewTransactionModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Approve Transaction Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Approve Transaction</h3>
                <button class="card-header-btn" onclick="closeApproveModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="approveForm">
                    <input type="hidden" id="approve_transaction_id" name="transaction_id">
                    <div class="form-group">
                        <label class="form-label" for="comments">Approval Comments (Optional)</label>
                        <textarea class="form-control" id="comments" name="comments" rows="3" 
                                  placeholder="Add any comments about this approval"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="approveForm" name="approve_transaction" class="btn btn-success">
                    <i class="fas fa-check"></i> Approve Transaction
                </button>
                <button class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Reject Transaction Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Transaction</h3>
                <button class="card-header-btn" onclick="closeRejectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="rejectForm">
                    <input type="hidden" id="reject_transaction_id" name="transaction_id">
                    <div class="form-group">
                        <label class="form-label" for="rejection_reason">Rejection Reason *</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" 
                                  required placeholder="Please provide a reason for rejecting this transaction"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="rejectForm" name="reject_transaction" class="btn btn-danger">
                    <i class="fas fa-times"></i> Reject Transaction
                </button>
                <button class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Request Revision Modal -->
    <div id="revisionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Request Revision</h3>
                <button class="card-header-btn" onclick="closeRevisionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="revisionForm">
                    <input type="hidden" id="revision_transaction_id" name="transaction_id">
                    <div class="form-group">
                        <label class="form-label" for="revision_notes">Revision Notes *</label>
                        <textarea class="form-control" id="revision_notes" name="revision_notes" rows="3" 
                                  required placeholder="Please provide specific instructions for revision"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="revisionForm" name="request_revision" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Request Revision
                </button>
                <button class="btn btn-secondary" onclick="closeRevisionModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Bulk Approve Modal -->
    <div id="bulkApproveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Bulk Approve Transactions</h3>
                <button class="card-header-btn" onclick="closeBulkApproveModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="bulkApproveForm">
                    <input type="hidden" id="bulk_transaction_ids" name="transaction_ids[]">
                    <div class="form-group">
                        <label class="form-label" for="bulk_comments">Approval Comments (Optional)</label>
                        <textarea class="form-control" id="bulk_comments" name="bulk_comments" rows="3" 
                                  placeholder="Add comments for all selected approvals"></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        You are about to approve <span id="bulkCount">0</span> transactions. This action cannot be undone.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="bulkApproveForm" name="bulk_approve" class="btn btn-success">
                    <i class="fas fa-check"></i> Approve All Selected
                </button>
                <button class="btn btn-secondary" onclick="closeBulkApproveModal()">Cancel</button>
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

        // Report period toggle
        document.getElementById('approval_report_period').addEventListener('change', function() {
            const customPeriod = document.getElementById('approval_custom_period');
            customPeriod.style.display = this.value === 'custom' ? 'block' : 'none';
        });

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Approval Trends Chart
            const trendsCtx = document.getElementById('approvalTrendsChart').getContext('2d');
            const trendsChart = new Chart(trendsCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_map(function($trend) {
                        return date('M Y', strtotime($trend['month'] . '-01'));
                    }, $approval_trends)); ?>,
                    datasets: [
                        {
                            label: 'Approved',
                            data: <?php echo json_encode(array_column($approval_trends, 'approved_count')); ?>,
                            backgroundColor: '#28a745'
                        },
                        {
                            label: 'Rejected',
                            data: <?php echo json_encode(array_column($approval_trends, 'rejected_count')); ?>,
                            backgroundColor: '#dc3545'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true
                        }
                    }
                }
            });

            // Priority Distribution Chart
            const priorityCtx = document.getElementById('priorityChart').getContext('2d');
            const priorityChart = new Chart(priorityCtx, {
                type: 'doughnut',
                data: {
                    labels: ['High Priority', 'Medium Priority', 'Low Priority'],
                    datasets: [{
                        data: [
                            <?php echo $approval_stats['overdue']; ?>,
                            <?php echo $approval_stats['medium_priority']; ?>,
                            <?php echo $approval_stats['low_priority']; ?>
                        ],
                        backgroundColor: ['#dc3545', '#ffc107', '#28a745'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });

        // Bulk Actions functionality
        function toggleSelectAll() {
            const checkboxes = document.getElementsByClassName('transaction-checkbox');
            const selectAll = document.getElementById('selectAll').checked;
            
            for (let checkbox of checkboxes) {
                checkbox.checked = selectAll;
            }
            updateBulkActions();
        }

        function selectAllTransactions() {
            document.getElementById('selectAll').checked = true;
            toggleSelectAll();
        }

        function updateBulkActions() {
            const checkboxes = document.getElementsByClassName('transaction-checkbox');
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            const bulkActions = document.getElementById('bulkActions');
            const selectedCountElement = document.getElementById('selectedCount');
            
            selectedCountElement.textContent = selectedCount + ' items selected';
            
            if (selectedCount > 0) {
                bulkActions.classList.add('active');
            } else {
                bulkActions.classList.remove('active');
            }
        }

        function clearSelection() {
            const checkboxes = document.getElementsByClassName('transaction-checkbox');
            for (let checkbox of checkboxes) {
                checkbox.checked = false;
            }
            document.getElementById('selectAll').checked = false;
            updateBulkActions();
        }

        function bulkApprove() {
            const checkboxes = document.getElementsByClassName('transaction-checkbox');
            const selectedIds = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);
            
            if (selectedIds.length === 0) {
                alert('Please select at least one transaction to approve.');
                return;
            }
            
            document.getElementById('bulk_transaction_ids').value = selectedIds.join(',');
            document.getElementById('bulkCount').textContent = selectedIds.length;
            document.getElementById('bulkApproveModal').style.display = 'block';
        }

        // Modal functions
        function viewTransaction(transactionId) {
            document.getElementById('viewTransactionModalBody').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--finance-primary);"></i>
                    <p>Loading transaction details...</p>
                </div>
            `;
            document.getElementById('viewTransactionModal').style.display = 'block';
            
            // Simulate AJAX call
            setTimeout(() => {
                document.getElementById('viewTransactionModalBody').innerHTML = `
                    <div class="transaction-details">
                        <div class="detail-row">
                            <div class="detail-item">
                                <span class="detail-label">Transaction ID</span>
                                <span class="detail-value">#${transactionId}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Amount</span>
                                <span class="detail-value">RWF 50,000.00</span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-item">
                                <span class="detail-label">Type</span>
                                <span class="detail-value">Expense</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Category</span>
                                <span class="detail-value">Sports Events</span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Description</span>
                            <span class="detail-value">Detailed transaction description would appear here with all relevant information including supporting documents and approval history.</span>
                        </div>
                        <div class="detail-row">
                            <div class="detail-item">
                                <span class="detail-label">Requested By</span>
                                <span class="detail-value">Eric Nshuti</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Department</span>
                                <span class="detail-value">Electrical Engineering</span>
                            </div>
                        </div>
                    </div>
                `;
            }, 1000);
        }

        function closeViewTransactionModal() {
            document.getElementById('viewTransactionModal').style.display = 'none';
        }

        function approveTransaction(transactionId) {
            document.getElementById('approve_transaction_id').value = transactionId;
            document.getElementById('approveModal').style.display = 'block';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function rejectTransaction(transactionId) {
            document.getElementById('reject_transaction_id').value = transactionId;
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        function requestRevision(transactionId) {
            document.getElementById('revision_transaction_id').value = transactionId;
            document.getElementById('revisionModal').style.display = 'block';
        }

        function closeRevisionModal() {
            document.getElementById('revisionModal').style.display = 'none';
        }

        function closeBulkApproveModal() {
            document.getElementById('bulkApproveModal').style.display = 'none';
        }

        // Export and print functions
        function exportApprovals() {
            alert('Export functionality would generate a CSV/Excel file of pending approvals.');
        }

        function printApprovalReport() {
            window.print();
        }

        function exportApprovalHistory() {
            alert('Approval history would be exported in the selected format.');
        }

        function generateCustomApprovalReport() {
            alert('Custom approval report builder would open with advanced filtering options.');
        }

        function generateApprovalReport() {
            const period = document.getElementById('approval_report_period').value;
            const format = document.getElementById('approval_report_format').value;
            alert(`Generating ${format} report for ${period} period...`);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['viewTransactionModal', 'approveModal', 'rejectModal', 'revisionModal', 'bulkApproveModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'viewTransactionModal') closeViewTransactionModal();
                    if (modalId === 'approveModal') closeApproveModal();
                    if (modalId === 'rejectModal') closeRejectModal();
                    if (modalId === 'revisionModal') closeRevisionModal();
                    if (modalId === 'bulkApproveModal') closeBulkApproveModal();
                }
            });
        }

        // Auto-refresh pending approvals count
        setInterval(() => {
            // You could add auto-refresh logic for pending approvals
            console.log('Auto-refresh check for new approvals');
        }, 300000); // 5 minutes
    </script>
</body>
</html>