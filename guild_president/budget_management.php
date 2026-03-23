<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get current academic year dynamically
function getCurrentAcademicYear() {
    $current_year = date('Y');
    $current_month = date('n');
    
    // Academic year runs from September to August
    // If current month is September (9) or later, academic year is current_year - next_year
    // If current month is August (8) or earlier, academic year is previous_year - current_year
    
    if ($current_month >= 9) { // September to December
        $academic_year = $current_year . '-' . ($current_year + 1);
    } else { // January to August
        $academic_year = ($current_year - 1) . '-' . $current_year;
    }
    
    return $academic_year;
}

$current_academic_year = getCurrentAcademicYear();

// Also get next academic year for planning
function getNextAcademicYear() {
    $current_year = date('Y');
    $current_month = date('n');
    
    if ($current_month >= 9) { // September to December
        $academic_year = ($current_year + 1) . '-' . ($current_year + 2);
    } else { // January to August
        $academic_year = $current_year . '-' . ($current_year + 1);
    }
    
    return $academic_year;
}

$next_academic_year = getNextAcademicYear();

// Get user profile data for sidebar
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
}

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_messages FROM messages WHERE recipient_id = ? AND read_status = 0");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    
    $pending_reports = 0;
    $pending_docs = 0;
    
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $unread_messages = $pending_reports = $pending_docs = 0;
}



// Get budget data
try {
    // Get all budget allocations for current academic year
    $budgetStmt = $pdo->prepare("
        SELECT * FROM budget_allocations 
        WHERE academic_year = ? 
        ORDER BY category_name
    ");
    $budgetStmt->execute([$current_academic_year]);
    $budget_allocations = $budgetStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $total_allocated = 0;
    $total_remaining = 0;
    $total_spent = 0;
    
    foreach ($budget_allocations as $budget) {
        $total_allocated += $budget['allocated_amount'];
        $total_remaining += $budget['remaining_amount'];
        $total_spent += ($budget['allocated_amount'] - $budget['remaining_amount']);
    }
    
    // Get guild account balance
    $accountStmt = $pdo->query("SELECT current_balance FROM financial_accounts WHERE is_active = TRUE LIMIT 1");
    $guild_account = $accountStmt->fetch(PDO::FETCH_ASSOC);
    $available_balance = $guild_account ? $guild_account['current_balance'] : 0;
    
    // Get recent budget transactions
    $transactionsStmt = $pdo->prepare("
        SELECT ft.*, ba.category_name, u.full_name as requester_name
        FROM financial_transactions ft
        LEFT JOIN budget_allocations ba ON ft.category_id = ba.id
        JOIN users u ON ft.requested_by = u.id
        WHERE ba.academic_year = ? OR ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY ft.created_at DESC
        LIMIT 15
    ");
    $transactionsStmt->execute([$current_academic_year]);
    $recent_transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Budget management query error: " . $e->getMessage());
    $budget_allocations = $recent_transactions = [];
    $total_allocated = $total_remaining = $total_spent = $available_balance = 0;
}




// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_budget_category':
                $category_name = trim($_POST['category_name']);
                $allocated_amount = $_POST['allocated_amount'] ?? 0;
                
                if (empty($category_name) || $allocated_amount <= 0) {
                    $_SESSION['error'] = "Please provide valid category name and amount";
                    break;
                }
                
                // Check if category already exists for this academic year
                $checkStmt = $pdo->prepare("
                    SELECT id FROM budget_allocations 
                    WHERE academic_year = ? AND category_name = ?
                ");
                $checkStmt->execute([$current_academic_year, $category_name]);
                
                if ($checkStmt->fetch()) {
                    $_SESSION['error'] = "Budget category already exists for this academic year";
                    break;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO budget_allocations (academic_year, category_name, allocated_amount, remaining_amount, created_by) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$current_academic_year, $category_name, $allocated_amount, $allocated_amount, $user_id]);
                
                $_SESSION['success'] = "Budget category added successfully";
                break;
                
            case 'update_budget_category':
                $budget_id = $_POST['budget_id'] ?? '';
                $new_amount = $_POST['new_amount'] ?? 0;
                
                if ($new_amount <= 0) {
                    $_SESSION['error'] = "Please provide a valid amount";
                    break;
                }
                
                // Get current budget
                $currentStmt = $pdo->prepare("SELECT * FROM budget_allocations WHERE id = ?");
                $currentStmt->execute([$budget_id]);
                $current_budget = $currentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$current_budget) {
                    $_SESSION['error'] = "Budget category not found";
                    break;
                }
                
                // Calculate new remaining amount
                $spent_amount = $current_budget['allocated_amount'] - $current_budget['remaining_amount'];
                $new_remaining = max(0, $new_amount - $spent_amount);
                
                $stmt = $pdo->prepare("
                    UPDATE budget_allocations 
                    SET allocated_amount = ?, remaining_amount = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_amount, $new_remaining, $budget_id]);
                
                $_SESSION['success'] = "Budget category updated successfully";
                break;
                
            case 'delete_budget_category':
                $budget_id = $_POST['budget_id'] ?? '';
                
                // Check if there are transactions linked to this category
                $checkTxStmt = $pdo->prepare("SELECT COUNT(*) as tx_count FROM financial_transactions WHERE category_id = ?");
                $checkTxStmt->execute([$budget_id]);
                $tx_count = $checkTxStmt->fetch(PDO::FETCH_ASSOC)['tx_count'];
                
                if ($tx_count > 0) {
                    $_SESSION['error'] = "Cannot delete budget category with existing transactions";
                    break;
                }
                
                $stmt = $pdo->prepare("DELETE FROM budget_allocations WHERE id = ?");
                $stmt->execute([$budget_id]);
                
                $_SESSION['success'] = "Budget category deleted successfully";
                break;
                
            case 'transfer_budget':
                $from_category = $_POST['from_category'] ?? '';
                $to_category = $_POST['to_category'] ?? '';
                $transfer_amount = $_POST['transfer_amount'] ?? 0;
                $transfer_reason = trim($_POST['transfer_reason']) ?? '';
                
                if (empty($from_category) || empty($to_category) || $transfer_amount <= 0) {
                    $_SESSION['error'] = "Please fill all required fields with valid amounts";
                    break;
                }
                
                if ($from_category === $to_category) {
                    $_SESSION['error'] = "Cannot transfer to the same category";
                    break;
                }
                
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Get source category
                    $fromStmt = $pdo->prepare("SELECT * FROM budget_allocations WHERE id = ? FOR UPDATE");
                    $fromStmt->execute([$from_category]);
                    $from_budget = $fromStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$from_budget) {
                        throw new Exception("Source category not found");
                    }
                    
                    if ($from_budget['remaining_amount'] < $transfer_amount) {
                        throw new Exception("Insufficient funds in source category");
                    }
                    
                    // Get destination category
                    $toStmt = $pdo->prepare("SELECT * FROM budget_allocations WHERE id = ? FOR UPDATE");
                    $toStmt->execute([$to_category]);
                    $to_budget = $toStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$to_budget) {
                        throw new Exception("Destination category not found");
                    }
                    
                    // Update source category
                    $updateFromStmt = $pdo->prepare("
                        UPDATE budget_allocations 
                        SET remaining_amount = remaining_amount - ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateFromStmt->execute([$transfer_amount, $from_category]);
                    
                    // Update destination category
                    $updateToStmt = $pdo->prepare("
                        UPDATE budget_allocations 
                        SET allocated_amount = allocated_amount + ?, remaining_amount = remaining_amount + ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateToStmt->execute([$transfer_amount, $transfer_amount, $to_category]);
                    
                    // Log the transfer
                    $logStmt = $pdo->prepare("
                        INSERT INTO budget_transfers (from_category_id, to_category_id, amount, reason, transferred_by) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $logStmt->execute([$from_category, $to_category, $transfer_amount, $transfer_reason, $user_id]);
                    
                    $pdo->commit();
                    $_SESSION['success'] = "Budget transfer completed successfully";
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
        }
        
        header("Location: budget_management.php");
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Include all CSS from finance.php and add these additional styles */        :root {
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
            --income: #28a745;
            --expense: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --border-radius: 8px;
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
            font-size: 0.875rem;
            transition: var(--transition);
            overflow-x: hidden;
        }

  /* Header */
.header {
    background: var(--white);
    box-shadow: var(--shadow-sm);
    padding: 1rem 0; /* Increased from 0.75rem */
    position: sticky;
    top: 0;
    z-index: 100;
    border-bottom: 1px solid var(--medium-gray);
    height: 80px; /* Added fixed height */
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
    width: 100%; /* Ensure full width */
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
    height: 40px; /* Increased from 32px */
    width: auto;
}

.brand-text h1 {
    font-size: 1.3rem; /* Increased from 1.1rem */
    font-weight: 700;
    color: var(--primary-blue);
}

.user-menu {
    display: flex;
    align-items: center;
    gap: 1.5rem; /* Increased gap */
}

.user-info {
    display: flex;
    align-items: center;
    gap: 1rem; /* Increased from 0.75rem */
}

.user-avatar {
    width: 50px; /* Increased from 36px */
    height: 50px; /* Increased from 36px */
    border-radius: 50%;
    background: var(--gradient-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 1.1rem; /* Increased from 0.8rem */
    border: 3px solid var(--medium-gray); /* Thicker border */
    overflow: hidden;
    position: relative;
    transition: var(--transition);
}

.user-avatar:hover {
    border-color: var(--primary-blue);
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
    font-size: 0.95rem; /* Slightly larger */
}

.user-role {
    font-size: 0.8rem; /* Slightly larger */
    color: var(--dark-gray);
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem; /* Increased from 0.5rem */
}

.icon-btn {
    width: 44px; /* Increased from 36px */
    height: 44px; /* Increased from 36px */
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
    font-size: 1.1rem; /* Larger icons */
}

.icon-btn:hover {
    background: var(--primary-blue);
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
    width: 20px; /* Slightly larger */
    height: 20px; /* Slightly larger */
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
    padding: 0.6rem 1.2rem; /* Slightly larger */
    border-radius: 20px;
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
    font-size: 0.85rem; /* Slightly larger */
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
    min-height: calc(100vh - 80px); /* Changed from 60px to 80px */
}

/* Sidebar */
.sidebar {
    background: var(--white);
    border-right: 1px solid var(--medium-gray);
    padding: 1.5rem 0;
    position: sticky;
    top: 80px; /* Changed from 60px to 80px */
    height: calc(100vh - 80px); /* Changed from 60px to 80px */
    overflow-y: auto;
}

/* Main Content */
.main-content {
    padding: 1.5rem;
    overflow-y: auto;
    height: calc(100vh - 80px); /* Changed from 60px to 80px */
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
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
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


        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .filter-select {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-left: 3px solid var(--primary-blue);
        }

        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.danger { border-left-color: var(--danger); }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Report Cards */
        .report-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
        }

        .report-card:hover {
            box-shadow: var(--shadow-md);
        }

        .report-card.submitted { border-left-color: var(--warning); }
        .report-card.reviewed { border-left-color: var(--success); }
        .report-card.approved { border-left-color: var(--success); }
        .report-card.rejected { border-left-color: var(--danger); }

        .report-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-body {
            padding: 1.5rem;
        }

        .report-meta {
            display: flex;
            gap: 2rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .report-content {
            line-height: 1.6;
        }

        .report-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .team-contributions {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 1rem;
        }

        .contribution-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .contribution-item:last-child {
            border-bottom: none;
        }

        /* Badges */
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open { background: #fff3cd; color: var(--warning); }
        .status-in_progress { background: #cce7ff; color: var(--primary-blue); }
        .status-resolved { background: #d4edda; color: var(--success); }
        .status-closed { background: #e2e3e5; color: var(--dark-gray); }

        .priority-high { background: #f8d7da; color: var(--danger); }
        .priority-medium { background: #fff3cd; color: var(--warning); }
        .priority-low { background: #d4edda; color: var(--success); }

        .sla-urgent { background: var(--danger); color: white; }
        .sla-warning { background: var(--warning); color: black; }
        .sla-ok { background: var(--success); color: white; }

        /* Status badges for reports */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: #e2e3e5; color: var(--dark-gray); }
        .status-submitted { background: #fff3cd; color: var(--warning); }
        .status-reviewed { background: #cce7ff; color: var(--primary-blue); }
        .status-approved { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--light-gray);
        }

        .page-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            color: var(--text-dark);
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            text-decoration: none;
        }

        .page-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .page-btn:hover:not(.active) {
            background: var(--light-blue);
        }

        /* Report Specific Styles */
        .report-section {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .report-section h4 {
            margin-bottom: 0.75rem;
            color: var(--primary-blue);
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 0.5rem;
        }

        .report-field {
            margin-bottom: 1rem;
        }

        .report-field strong {
            display: block;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .report-field-content {
            background: var(--white);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            border-left: 3px solid var(--primary-blue);
        }

        .media-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .media-item {
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
        }

        .media-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .media-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .media-item .file-icon {
            width: 100%;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--light-blue);
            color: var(--primary-blue);
            font-size: 2rem;
        }

        .media-info {
            padding: 0.5rem;
            font-size: 0.8rem;
        }

        .media-info .file-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .team-contribution {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .contribution-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .contribution-author {
            font-weight: 600;
            color: var(--primary-blue);
        }

        .contribution-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .contribution-content {
            line-height: 1.6;
        }

        .feedback-content {
            background: var(--light-blue);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-blue);
        }

        /* Modal Styles - FIXED */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 2% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s;
            position: relative;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 1.2rem;
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            position: sticky;
            bottom: 0;
            background: var(--white);
            padding: 1rem 0 0;
        }

        /* Checkbox Styles */
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: normal;
            margin-bottom: 0;
        }

        .checkbox-label input[type="checkbox"] {
            margin-right: 0.5rem;
        }

        /* Toast Styles */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 600;
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            background-color: var(--success);
        }

        .toast.error {
            background-color: var(--danger);
        }

        .toast.warning {
            background-color: var(--warning);
            color: black;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Horizontal Layout for Better Workflow */
        .reports-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .reports-container {
                grid-template-columns: 1fr;
            }
        }

        /* Dark mode support for modals */
        .dark-mode .modal-content {
            background-color: var(--white);
            color: var(--text-dark);
        }

        .dark-mode .form-control {
            background-color: var(--light-gray);
            color: var(--text-dark);
            border-color: var(--medium-gray);
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
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .table {
                font-size: 0.7rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .report-actions {
                flex-direction: column;
            }
        }




        .financial-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-left: 4px solid var(--primary-blue);
        }

        .stat-card.income { border-left-color: var(--income); }
        .stat-card.expense { border-left-color: var(--expense); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.success { border-left-color: var(--success); }

        .stat-amount {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-amount.income { color: var(--income); }
        .stat-amount.expense { color: var(--expense); }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .accounts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .account-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            border-top: 4px solid var(--primary-blue);
        }

        .account-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .account-name {
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }

        .account-balance {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
            margin: 0.5rem 0;
        }

        .account-meta {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .progress-bar {
            background: var(--light-gray);
            border-radius: 10px;
            height: 8px;
            margin: 0.5rem 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: var(--transition);
        }

        .progress-low { background: var(--success); }
        .progress-medium { background: var(--warning); }
        .progress-high { background: var(--danger); }

        .utilization-rate {
            font-size: 0.8rem;
            color: var(--dark-gray);
            text-align: right;
        }

        .transaction-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .transaction-item:hover {
            background: var(--light-blue);
        }

        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1rem;
        }

        .transaction-icon.income { background: #d4edda; color: var(--income); }
        .transaction-icon.expense { background: #f8d7da; color: var(--expense); }

        .transaction-details {
            flex: 1;
        }

        .transaction-description {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .transaction-meta {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .transaction-amount {
            font-weight: 700;
            text-align: right;
        }

        .transaction-amount.income { color: var(--income); }
        .transaction-amount.expense { color: var(--expense); }

        .approval-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending_approval { background: #fff3cd; color: var(--warning); }
        .status-approved { background: #d4edda; color: var(--success); }
        .status-completed { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        .contribution-progress {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .progress-percentage {
            font-weight: 600;
            color: var(--primary-blue);
        }
        
        .balance-update-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-blue);
        }
        
        .balance-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .balance-form {
                grid-template-columns: 1fr;
            }
        }
        
        .director-approval-badge {
            background: #e7f3ff;
            color: var(--primary-blue);
            border: 1px solid var(--primary-blue);
        }
        
        .signature-section {
            background: var(--light-blue);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 1rem;
            border-left: 4px solid var(--warning);
        }
        
        .signature-line {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px dashed var(--medium-gray);
        }
        
        .signature-box {
            text-align: center;
            min-width: 200px;
        }
        
        .signature-name {
            font-weight: 600;
            margin-top: 2rem;
            border-top: 1px solid var(--text-dark);
            padding-top: 0.5rem;
        }
        
        .supporting-docs {
            margin-top: 1rem;
        }
        
        .doc-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }        
        .budget-summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-top: 4px solid var(--primary-blue);
        }
        
        .summary-card.allocated { border-top-color: var(--primary-blue); }
        .summary-card.spent { border-top-color: var(--expense); }
        .summary-card.remaining { border-top-color: var(--success); }
        .summary-card.available { border-top-color: var(--warning); }
        
        .summary-amount {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }
        
        .budget-category-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
        }
        
        .budget-category-card:hover {
            box-shadow: var(--shadow-md);
        }
        
        .category-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .category-body {
            padding: 1.5rem;
        }
        
        .category-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }
        
        .category-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .utilization-warning {
            color: var(--danger);
            font-weight: 600;
        }
        
        .utilization-good {
            color: var(--success);
            font-weight: 600;
        }
        
        .empty-budget-state {
            text-align: center;
            padding: 3rem;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }
        
        .empty-budget-state i {
            font-size: 4rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .transfer-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .transfer-form {
                grid-template-columns: 1fr;
            }
            
            .category-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="nav-container">
        <div class="logo-section">
            <div class="logos">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
            </div>
            <div class="brand-text">
                <h1>Isonga - President</h1>
            </div>
        </div>
        <div class="user-menu">
            <div class="header-actions">
                <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                    <i class="fas fa-moon"></i>
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
                    <a href="tickets.php" >
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" >
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php" >
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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

        <main class="main-content">
            <div class="container">
<!-- Page Header -->
<div class="page-header">
    <div class="page-title">
        <h1>Budget Management</h1>
        <p>Manage RPSU budget allocations for 
            <select id="academicYearSelect" style="padding: 0.25rem; border-radius: 4px; border: 1px solid var(--medium-gray);">
                <option value="<?php echo $current_academic_year; ?>" selected><?php echo $current_academic_year; ?></option>
                <option value="<?php echo $next_academic_year; ?>"><?php echo $next_academic_year; ?> (Planning)</option>
                <!-- Add previous years if needed -->
                <option value="2023-2024">2023-2024</option>
                <option value="2022-2023">2022-2023</option>
            </select>
        </p>
    </div>
    <div class="action-buttons">
        <button class="btn btn-primary" id="addCategoryBtn">
            <i class="fas fa-plus"></i> Add Budget Category
        </button>
        <button class="btn btn-success" id="transferBudgetBtn">
            <i class="fas fa-exchange-alt"></i> Transfer Budget
        </button>
        <button class="btn btn-info" id="copyBudgetBtn">
            <i class="fas fa-copy"></i> Copy to Next Year
        </button>
    </div>
</div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <!-- Budget Summary -->
                <div class="budget-summary-cards">
                    <div class="summary-card allocated">
                        <div class="summary-amount">RWF <?php echo number_format($total_allocated, 2); ?></div>
                        <div class="summary-label">Total Allocated</div>
                    </div>
                    <div class="summary-card spent">
                        <div class="summary-amount">RWF <?php echo number_format($total_spent, 2); ?></div>
                        <div class="summary-label">Total Spent</div>
                    </div>
                    <div class="summary-card remaining">
                        <div class="summary-amount">RWF <?php echo number_format($total_remaining, 2); ?></div>
                        <div class="summary-label">Total Remaining</div>
                    </div>
                    <div class="summary-card available">
                        <div class="summary-amount">RWF <?php echo number_format($available_balance, 2); ?></div>
                        <div class="summary-label">Available Balance</div>
                    </div>
                </div>

                <!-- Budget Transfer Section -->
                <div class="card">
                    <div class="card-header">
                        <h3>Transfer Between Budget Categories</h3>
                    </div>
                    <div class="card-body">
                        <form class="transfer-form" method="POST">
                            <input type="hidden" name="action" value="transfer_budget">
                            
                            <div class="form-group">
                                <label>From Category:</label>
                                <select name="from_category" class="form-control" required>
                                    <option value="">Select Source Category</option>
                                    <?php foreach ($budget_allocations as $budget): ?>
                                        <?php if ($budget['remaining_amount'] > 0): ?>
                                            <option value="<?php echo $budget['id']; ?>">
                                                <?php echo htmlspecialchars($budget['category_name']); ?> 
                                                (RWF <?php echo number_format($budget['remaining_amount'], 2); ?> available)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>To Category:</label>
                                <select name="to_category" class="form-control" required>
                                    <option value="">Select Destination Category</option>
                                    <?php foreach ($budget_allocations as $budget): ?>
                                        <option value="<?php echo $budget['id']; ?>">
                                            <?php echo htmlspecialchars($budget['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Amount:</label>
                                <input type="number" name="transfer_amount" class="form-control" 
                                       step="0.01" min="0.01" placeholder="Enter amount" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Reason:</label>
                                <input type="text" name="transfer_reason" class="form-control" 
                                       placeholder="Reason for transfer" required>
                            </div>
                            
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-exchange-alt"></i> Transfer
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Budget Categories -->
                <div class="card">
                    <div class="card-header">
                        <h3>Budget Categories - <?php echo $current_academic_year; ?></h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($budget_allocations)): ?>
                            <div class="empty-budget-state">
                                <i class="fas fa-chart-pie"></i>
                                <h3>No Budget Categories</h3>
                                <p>Start by adding your first budget category to manage RPSU finances.</p>
                                <button class="btn btn-primary" id="addFirstCategoryBtn">
                                    <i class="fas fa-plus"></i> Add First Category
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($budget_allocations as $budget): ?>
                                <?php
                                $spent_amount = $budget['allocated_amount'] - $budget['remaining_amount'];
                                $utilization_rate = $budget['allocated_amount'] > 0 ? 
                                    ($spent_amount / $budget['allocated_amount']) * 100 : 0;
                                ?>
                                <div class="budget-category-card">
                                    <div class="category-header">
                                        <h4 style="margin: 0;"><?php echo htmlspecialchars($budget['category_name']); ?></h4>
                                        <div>
                                            <span class="badge" style="background: var(--primary-blue); color: white;">
                                                <?php echo number_format($utilization_rate, 1); ?>% Utilized
                                            </span>
                                        </div>
                                    </div>
                                    <div class="category-body">
                                        <div class="category-stats">
                                            <div class="stat-item">
                                                <div class="stat-value" style="color: var(--primary-blue);">
                                                    RWF <?php echo number_format($budget['allocated_amount'], 2); ?>
                                                </div>
                                                <div class="stat-label">Allocated</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value" style="color: var(--expense);">
                                                    RWF <?php echo number_format($spent_amount, 2); ?>
                                                </div>
                                                <div class="stat-label">Spent</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value" style="color: var(--success);">
                                                    RWF <?php echo number_format($budget['remaining_amount'], 2); ?>
                                                </div>
                                                <div class="stat-label">Remaining</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value <?php echo $utilization_rate > 80 ? 'utilization-warning' : 'utilization-good'; ?>">
                                                    <?php echo number_format($utilization_rate, 1); ?>%
                                                </div>
                                                <div class="stat-label">Utilization</div>
                                            </div>
                                        </div>
                                        
                                        <div class="progress-bar">
                                            <div class="progress-fill 
                                                <?php 
                                                if ($utilization_rate < 50) echo 'progress-low';
                                                elseif ($utilization_rate < 80) echo 'progress-medium';
                                                else echo 'progress-high';
                                                ?>" 
                                                style="width: <?php echo min(100, $utilization_rate); ?>%">
                                            </div>
                                        </div>
                                        
                                        <div class="category-actions">
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="openUpdateModal(<?php echo $budget['id']; ?>, '<?php echo htmlspecialchars($budget['category_name']); ?>', <?php echo $budget['allocated_amount']; ?>)">
                                                <i class="fas fa-edit"></i> Update
                                            </button>
                                            <?php if ($spent_amount == 0): ?>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="openDeleteModal(<?php echo $budget['id']; ?>, '<?php echo htmlspecialchars($budget['category_name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-danger btn-sm" disabled title="Cannot delete category with transactions">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Budget Transactions -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Budget Transactions</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_transactions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-exchange-alt"></i>
                                <p>No recent transactions</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_transactions as $transaction): ?>
                                <div class="transaction-item">
                                    <div class="transaction-icon <?php echo $transaction['transaction_type']; ?>">
                                        <i class="fas fa-<?php echo $transaction['transaction_type'] === 'income' ? 'plus' : 'minus'; ?>"></i>
                                    </div>
                                    <div class="transaction-details">
                                        <div class="transaction-description">
                                            <?php echo htmlspecialchars($transaction['description']); ?>
                                            <?php if ($transaction['category_name']): ?>
                                                <span class="badge" style="background: var(--light-gray); color: var(--dark-gray); margin-left: 0.5rem;">
                                                    <?php echo htmlspecialchars($transaction['category_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="transaction-meta">
                                            <?php echo htmlspecialchars($transaction['requester_name']); ?> • 
                                            <?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?>
                                        </div>
                                    </div>
                                    <div class="transaction-amount <?php echo $transaction['transaction_type']; ?>">
                                        RWF <?php echo number_format($transaction['amount'], 2); ?>
                                    </div>
                                    <span class="approval-badge status-<?php echo $transaction['status']; ?>">
                                        <?php echo str_replace('_', ' ', $transaction['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <!-- Add Budget Category Modal -->
    <div id="addCategoryModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Add Budget Category</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addCategoryForm">
                    <input type="hidden" name="action" value="add_budget_category">
                    
                    <div class="form-group">
                        <label for="category_name">Category Name:</label>
                        <input type="text" id="category_name" name="category_name" class="form-control" 
                               placeholder="e.g., Sports & Entertainment, Academic Activities..." required>
                    </div>
                    
                    <div class="form-group">
                        <label for="allocated_amount">Allocated Amount (RWF):</label>
                        <input type="number" id="allocated_amount" name="allocated_amount" class="form-control" 
                               step="0.01" min="0.01" placeholder="Enter allocated amount" required>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Add Category</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Budget Category Modal -->
    <div id="updateCategoryModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Update Budget Category</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="updateCategoryForm">
                    <input type="hidden" name="action" value="update_budget_category">
                    <input type="hidden" name="budget_id" id="update_budget_id">
                    
                    <div class="form-group">
                        <label>Category Name:</label>
                        <input type="text" id="update_category_name" class="form-control" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_amount">New Allocated Amount (RWF):</label>
                        <input type="number" id="new_amount" name="new_amount" class="form-control" 
                               step="0.01" min="0.01" placeholder="Enter new amount" required>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-warning">Update Category</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Budget Category Modal -->
    <div id="deleteCategoryModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Delete Budget Category</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the budget category "<strong id="delete_category_name"></strong>"?</p>
                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                
                <form id="deleteCategoryForm">
                    <input type="hidden" name="action" value="delete_budget_category">
                    <input type="hidden" name="budget_id" id="delete_budget_id">
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-danger">Delete Category</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Budget Management JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const addModal = document.getElementById('addCategoryModal');
            const updateModal = document.getElementById('updateCategoryModal');
            const deleteModal = document.getElementById('deleteCategoryModal');

            // Open modal buttons
            document.getElementById('addCategoryBtn').addEventListener('click', () => addModal.style.display = 'block');
            document.getElementById('addFirstCategoryBtn')?.addEventListener('click', () => addModal.style.display = 'block');
            document.getElementById('transferBudgetBtn').addEventListener('click', () => {
                document.querySelector('.transfer-form').scrollIntoView({ behavior: 'smooth' });
            });

            // Close modals
            document.querySelectorAll('.close, .close-modal').forEach(btn => {
                btn.addEventListener('click', closeAllModals);
            });

            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeAllModals();
                }
            });

            // Form submissions
            document.getElementById('addCategoryForm')?.addEventListener('submit', handleFormSubmit);
            document.getElementById('updateCategoryForm')?.addEventListener('submit', handleFormSubmit);
            document.getElementById('deleteCategoryForm')?.addEventListener('submit', handleFormSubmit);

            function closeAllModals() {
                addModal.style.display = 'none';
                updateModal.style.display = 'none';
                deleteModal.style.display = 'none';
            }

            function openUpdateModal(budgetId, categoryName, currentAmount) {
                document.getElementById('update_budget_id').value = budgetId;
                document.getElementById('update_category_name').value = categoryName;
                document.getElementById('new_amount').value = currentAmount;
                updateModal.style.display = 'block';
            }

            function openDeleteModal(budgetId, categoryName) {
                document.getElementById('delete_budget_id').value = budgetId;
                document.getElementById('delete_category_name').textContent = categoryName;
                deleteModal.style.display = 'block';
            }

            function handleFormSubmit(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                fetch('budget_management.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                    } else {
                        return response.text();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }

            // Make functions globally available
            window.openUpdateModal = openUpdateModal;
            window.openDeleteModal = openDeleteModal;

            // Dark mode toggle
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;

            const savedTheme = localStorage.getItem('theme') || 'light';
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
        });


// Academic year selector functionality
document.getElementById('academicYearSelect')?.addEventListener('change', function() {
    const selectedYear = this.value;
    const url = new URL(window.location.href);
    url.searchParams.set('academic_year', selectedYear);
    window.location.href = url.toString();
});

// Copy budget to next year
document.getElementById('copyBudgetBtn')?.addEventListener('click', function() {
    if (confirm('Copy current budget structure to next academic year?')) {
        fetch('copy_budget.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                from_year: '<?php echo $current_academic_year; ?>',
                to_year: '<?php echo $next_academic_year; ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Budget copied successfully to <?php echo $next_academic_year; ?>');
                // Optionally refresh the page or update the UI
            } else {
                alert('Error copying budget: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error copying budget');
        });
    }
});
    </script>
</body>
</html>