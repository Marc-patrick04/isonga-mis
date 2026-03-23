<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php'; // Add this line

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get current academic year dynamically
$current_academic_year = getCurrentAcademicYear();
$academic_year_options = getAcademicYearOptions();

// Handle academic year change
if (isset($_POST['change_academic_year'])) {
    $new_academic_year = $_POST['academic_year'];
    if (setCurrentAcademicYear($new_academic_year)) {
        $current_academic_year = $new_academic_year;
        $message = "Academic year changed to $current_academic_year";
        $message_type = "success";
    } else {
        $message = "Error changing academic year";
        $message_type = "error";
    }
}



// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Add new budget category
if ($action === 'add_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name']);
    $category_type = $_POST['category_type'];
    $description = trim($_POST['description']);
    $parent_category_id = $_POST['parent_category_id'] ?: null;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO budget_categories (category_name, category_type, description, parent_category_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$category_name, $category_type, $description, $parent_category_id]);
        $message = "Budget category added successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error adding category: " . $e->getMessage();
        $message_type = "error";
    }
}

// Edit budget category
if ($action === 'edit_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = $_POST['category_id'];
    $category_name = trim($_POST['category_name']);
    $category_type = $_POST['category_type'];
    $description = trim($_POST['description']);
    $parent_category_id = $_POST['parent_category_id'] ?: null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE budget_categories SET category_name = ?, category_type = ?, description = ?, parent_category_id = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$category_name, $category_type, $description, $parent_category_id, $is_active, $category_id]);
        $message = "Budget category updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error updating category: " . $e->getMessage();
        $message_type = "error";
    }
}

// Delete budget category
if ($action === 'delete_category' && isset($_GET['id'])) {
    $category_id = $_GET['id'];
    
    try {
        // Check if category has transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $transaction_count = $stmt->fetchColumn();
        
        if ($transaction_count > 0) {
            $message = "Cannot delete category: It has associated transactions. Please deactivate it instead.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("DELETE FROM budget_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $message = "Budget category deleted successfully!";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Error deleting category: " . $e->getMessage();
        $message_type = "error";
    }
}

// Add monthly budget allocation
if ($action === 'add_budget' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $academic_year = $_POST['academic_year'];
    $month_year = $_POST['month_year'];
    $category_id = $_POST['category_id'];
    $allocated_amount = $_POST['allocated_amount'];
    $notes = trim($_POST['notes']);
    
    try {
        // Check if budget already exists for this category and period
        $stmt = $pdo->prepare("SELECT id FROM monthly_budgets WHERE academic_year = ? AND month_year = ? AND category_id = ?");
        $stmt->execute([$academic_year, $month_year, $category_id]);
        
        if ($stmt->fetch()) {
            $message = "Budget already exists for this category and period!";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("INSERT INTO monthly_budgets (academic_year, month_year, category_id, allocated_amount, allocated_by, allocation_date, notes) VALUES (?, ?, ?, ?, ?, CURDATE(), ?)");
            $stmt->execute([$academic_year, $month_year, $category_id, $allocated_amount, $user_id, $notes]);
            $message = "Budget allocation added successfully!";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Error adding budget allocation: " . $e->getMessage();
        $message_type = "error";
    }
}

// Edit monthly budget allocation
if ($action === 'edit_budget' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $budget_id = $_POST['budget_id'];
    $allocated_amount = $_POST['allocated_amount'];
    $notes = trim($_POST['notes']);
    
    try {
        $stmt = $pdo->prepare("UPDATE monthly_budgets SET allocated_amount = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$allocated_amount, $notes, $budget_id]);
        $message = "Budget allocation updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error updating budget allocation: " . $e->getMessage();
        $message_type = "error";
    }
}

// Delete monthly budget allocation
if ($action === 'delete_budget' && isset($_GET['budget_id'])) {
    $budget_id = $_GET['budget_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM monthly_budgets WHERE id = ?");
        $stmt->execute([$budget_id]);
        $message = "Budget allocation deleted successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error deleting budget allocation: " . $e->getMessage();
        $message_type = "error";
    }
}



// Get all budget categories
try {
    $stmt = $pdo->query("
        SELECT bc.*, parent.category_name as parent_category_name 
        FROM budget_categories bc 
        LEFT JOIN budget_categories parent ON bc.parent_category_id = parent.id 
        ORDER BY bc.category_type, bc.category_name
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    error_log("Categories error: " . $e->getMessage());
}

// Get monthly budgets with category details and utilization
try {
    $stmt = $pdo->prepare("
        SELECT 
            mb.*,
            bc.category_name,
            bc.category_type,
            COALESCE(SUM(CASE WHEN ft.transaction_type = 'expense' AND ft.status = 'completed' THEN ft.amount ELSE 0 END), 0) as spent_amount,
            (mb.allocated_amount - COALESCE(SUM(CASE WHEN ft.transaction_type = 'expense' AND ft.status = 'completed' THEN ft.amount ELSE 0 END), 0)) as remaining_amount,
            CASE 
                WHEN mb.allocated_amount > 0 THEN 
                    ROUND((COALESCE(SUM(CASE WHEN ft.transaction_type = 'expense' AND ft.status = 'completed' THEN ft.amount ELSE 0 END), 0) / mb.allocated_amount) * 100, 2)
                ELSE 0 
            END as utilization_rate,
            u.full_name as allocated_by_name
        FROM monthly_budgets mb
        LEFT JOIN budget_categories bc ON mb.category_id = bc.id
        LEFT JOIN financial_transactions ft ON bc.id = ft.category_id 
            AND ft.transaction_date BETWEEN DATE(CONCAT(mb.month_year, '-01')) AND LAST_DAY(DATE(CONCAT(mb.month_year, '-01')))
        LEFT JOIN users u ON mb.allocated_by = u.id
        WHERE mb.academic_year = ?
        GROUP BY mb.id, mb.allocated_amount, bc.category_name, bc.category_type, u.full_name
        ORDER BY mb.month_year DESC, bc.category_name
    ");
    $stmt->execute([$current_academic_year]);
    $monthly_budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $monthly_budgets = [];
    error_log("Monthly budgets error: " . $e->getMessage());
}

// Get budget summary
try {
    // Total allocated budget
    $stmt = $pdo->prepare("SELECT SUM(allocated_amount) as total_allocated FROM monthly_budgets WHERE academic_year = ?");
    $stmt->execute([$current_academic_year]);
    $total_allocated = $stmt->fetch(PDO::FETCH_ASSOC)['total_allocated'] ?? 0;

    // Total spent
    $stmt = $pdo->query("
        SELECT SUM(amount) as total_spent 
        FROM financial_transactions 
        WHERE transaction_type = 'expense' 
        AND status = 'completed'
        AND YEAR(transaction_date) = YEAR(CURDATE())
    ");
    $total_spent = $stmt->fetch(PDO::FETCH_ASSOC)['total_spent'] ?? 0;

    // Utilization percentage
    $utilization_percentage = $total_allocated > 0 ? round(($total_spent / $total_allocated) * 100, 1) : 0;
    
    // Budget by category type
    $stmt = $pdo->prepare("
        SELECT 
            bc.category_type,
            SUM(mb.allocated_amount) as total_allocated,
            COALESCE(SUM(CASE WHEN ft.transaction_type = 'expense' AND ft.status = 'completed' THEN ft.amount ELSE 0 END), 0) as total_spent
        FROM monthly_budgets mb
        LEFT JOIN budget_categories bc ON mb.category_id = bc.id
        LEFT JOIN financial_transactions ft ON bc.id = ft.category_id 
            AND ft.transaction_date BETWEEN DATE(CONCAT(mb.month_year, '-01')) AND LAST_DAY(DATE(CONCAT(mb.month_year, '-01')))
        WHERE mb.academic_year = ?
        GROUP BY bc.category_type
    ");
    $stmt->execute([$current_academic_year]);
    $budget_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_allocated = $total_spent = $utilization_percentage = 0;
    $budget_by_type = [];
    error_log("Budget summary error: " . $e->getMessage());
}

// Get parent categories for dropdown
$parent_categories = array_filter($categories, function($cat) {
    return $cat['category_type'] === 'expense'; // Only expense categories can be parents
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse all the CSS from dashboard.php */
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

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #e2e3e5;
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

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
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
            border-bottom: 3px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        .tab.active {
            color: var(--finance-primary);
            border-bottom-color: var(--finance-primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Progress bars */
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
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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
            box-shadow: var(--shadow-lg);
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
                    <h1>Isonga - Budget Management</h1>
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
                    <a href="budget_management.php" class="active">
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
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
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
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Budget Management 💰</h1>
                    <p>Manage budget categories and allocations for <?php echo $current_academic_year; ?> academic year</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Budget Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_allocated, 2); ?></div>
                        <div class="stat-label">Total Budget Allocated</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-line"></i> Current Year
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_spent, 2); ?></div>
                        <div class="stat-label">Total Amount Spent</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-check-circle"></i> Tracked
                        </div>
                    </div>
                </div>
                <div class="stat-card <?php echo $utilization_percentage <= 80 ? 'success' : ($utilization_percentage <= 95 ? 'warning' : 'danger'); ?>">
                    <div class="stat-icon">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $utilization_percentage; ?>%</div>
                        <div class="stat-label">Budget Utilization</div>
                        <div class="stat-trend <?php echo $utilization_percentage <= 80 ? 'trend-positive' : 'trend-negative'; ?>">
                            <i class="fas fa-<?php echo $utilization_percentage <= 80 ? 'check' : 'exclamation'; ?>-circle"></i>
                            <?php echo $utilization_percentage <= 80 ? 'Good' : 'Monitor'; ?>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($categories); ?></div>
                        <div class="stat-label">Budget Categories</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-tags"></i> Active
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('budgets-tab')">Monthly Budgets</button>
                <button class="tab" onclick="openTab('categories-tab')">Budget Categories</button>
                <button class="tab" onclick="openTab('allocation-tab')">Budget Allocation</button>
            </div>

           <!-- Monthly Budgets Tab -->
<div id="budgets-tab" class="tab-content active">
    <div class="card">
        <div class="card-header">
            <h3>Monthly Budget Allocations</h3>
            <div class="card-header-actions">
                <button class="card-header-btn" onclick="openModal('addBudgetModal')" title="Add New Budget">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Category</th>
                        <th>Allocated Amount</th>
                        <th>Spent Amount</th>
                        <th>Remaining</th>
                        <th>Utilization</th>
                        <th>Allocated By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_budgets)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                No budget allocations found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthly_budgets as $budget): ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($budget['month_year'] . '-01')); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($budget['category_name'] ?? ''); ?></strong>
                                    <br><small class="status-badge status-<?php echo ($budget['category_type'] ?? 'expense') === 'income' ? 'active' : 'inactive'; ?>">
                                        <?php echo ucfirst($budget['category_type'] ?? 'expense'); ?>
                                    </small>
                                </td>
                                <td class="amount">RWF <?php echo number_format($budget['allocated_amount'] ?? 0, 2); ?></td>
                                <td class="amount expense">RWF <?php echo number_format($budget['spent_amount'] ?? 0, 2); ?></td>
                                <td class="amount">RWF <?php echo number_format($budget['remaining_amount'] ?? 0, 2); ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill 
                                            <?php 
                                            $utilization_rate = $budget['utilization_rate'] ?? 0;
                                            echo $utilization_rate < 60 ? 'progress-low' : 
                                                  ($utilization_rate < 85 ? 'progress-medium' : 'progress-high'); ?>"
                                            style="width: <?php echo min(100, $utilization_rate); ?>%">
                                        </div>
                                    </div>
                                    <div class="progress-text">
                                        <?php echo $utilization_rate; ?>%
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($budget['allocated_by_name'] ?? 'System'); ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.25rem;">
                                        <button class="btn btn-warning btn-sm" onclick="editBudget(<?php echo $budget['id']; ?>, <?php echo htmlspecialchars(json_encode($budget)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?action=delete_budget&budget_id=<?php echo $budget['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this budget allocation?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

            <!-- Budget Categories Tab -->
            <div id="categories-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Budget Categories</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="openModal('addCategoryModal')" title="Add New Category">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Parent Category</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categories)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                            No budget categories found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categories as $category): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $category['category_type'] === 'income' ? 'active' : 'inactive'; ?>">
                                                    <?php echo ucfirst($category['category_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($category['description']); ?></td>
                                            <td><?php echo htmlspecialchars($category['parent_category_name'] ?? '-'); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $category['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.25rem;">
                                                    <button class="btn btn-warning btn-sm" onclick="editCategory(<?php echo $category['id']; ?>, <?php echo htmlspecialchars(json_encode($category)); ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?action=delete_category&id=<?php echo $category['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this category?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Budget Allocation Tab -->
            <div id="allocation-tab" class="tab-content">
                <div class="content-grid">
                    <!-- Budget by Type -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Budget by Category Type</h3>
                        </div>
                        <div class="card-body">
                            <div id="budgetTypeChart" style="height: 300px;"></div>
                        </div>
                    </div>

                    <!-- Quick Allocation -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Budget Allocation</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_budget">
                                
                                <div class="form-group">
                                    <label class="form-label">Academic Year</label>
                                    <input type="text" class="form-control" name="academic_year" value="<?php echo $current_academic_year; ?>" required readonly>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Month</label>
                                    <input type="month" class="form-control" name="month_year" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category_id" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <?php if ($category['is_active']): ?>
                                                <option value="<?php echo $category['id']; ?>">
                                                    <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo ucfirst($category['category_type']); ?>)
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Allocated Amount (RWF)</label>
                                    <input type="number" class="form-control" name="allocated_amount" step="0.01" min="0" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Optional notes about this allocation"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Allocate Budget
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Category Modal -->
    <div id="addCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Budget Category</h3>
                <button class="close" onclick="closeModal('addCategoryModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="categoryForm">
                    <input type="hidden" name="action" value="add_category">
                    <input type="hidden" name="category_id" id="editCategoryId">
                    
                    <div class="form-group">
                        <label class="form-label">Category Name</label>
                        <input type="text" class="form-control" name="category_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category Type</label>
                        <select class="form-select" name="category_type" required>
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Parent Category (Optional)</label>
                        <select class="form-select" name="parent_category_id">
                            <option value="">No Parent Category</option>
                            <?php foreach ($parent_categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="activeField" style="display: none;">
                        <label class="form-label">
                            <input type="checkbox" name="is_active" id="isActive" value="1" checked> Active
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addCategoryModal')">Cancel</button>
                <button type="submit" form="categoryForm" class="btn btn-primary">Save Category</button>
            </div>
        </div>
    </div>

    <!-- Add Budget Modal -->
    <div id="addBudgetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Budget Allocation</h3>
                <button class="close" onclick="closeModal('addBudgetModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="budgetForm">
                    <input type="hidden" name="action" value="add_budget">
                    <input type="hidden" name="budget_id" id="editBudgetId">
                    
                    <div class="form-group">
                        <label class="form-label">Academic Year</label>
                        <input type="text" class="form-control" name="academic_year" value="<?php echo $current_academic_year; ?>" required readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Month</label>
                        <input type="month" class="form-control" name="month_year" id="budgetMonth" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id" id="budgetCategory" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <?php if ($category['is_active']): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo ucfirst($category['category_type']); ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Allocated Amount (RWF)</label>
                        <input type="number" class="form-control" name="allocated_amount" id="budgetAmount" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="budgetNotes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addBudgetModal')">Cancel</button>
                <button type="submit" form="budgetForm" class="btn btn-primary">Save Allocation</button>
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
        function openTab(tabName) {
            const tabs = document.querySelectorAll('.tab-content');
            const tabButtons = document.querySelectorAll('.tab');
            
            tabs.forEach(tab => tab.classList.remove('active'));
            tabButtons.forEach(button => button.classList.remove('active'));
            
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            // Reset form
            document.getElementById('categoryForm').reset();
            document.getElementById('budgetForm').reset();
            document.getElementById('editCategoryId').value = '';
            document.getElementById('editBudgetId').value = '';
            document.getElementById('activeField').style.display = 'none';
        }

        // Edit category
        function editCategory(id, category) {
            document.getElementById('editCategoryId').value = id;
            document.querySelector('input[name="category_name"]').value = category.category_name;
            document.querySelector('select[name="category_type"]').value = category.category_type;
            document.querySelector('textarea[name="description"]').value = category.description || '';
            document.querySelector('select[name="parent_category_id"]').value = category.parent_category_id || '';
            document.getElementById('isActive').checked = category.is_active;
            document.getElementById('activeField').style.display = 'block';
            
            // Change form action to edit
            document.querySelector('#categoryForm input[name="action"]').value = 'edit_category';
            document.querySelector('.modal-header h3').textContent = 'Edit Budget Category';
            
            openModal('addCategoryModal');
        }

        // Edit budget
        function editBudget(id, budget) {
            document.getElementById('editBudgetId').value = id;
            document.getElementById('budgetMonth').value = budget.month_year;
            document.getElementById('budgetCategory').value = budget.category_id;
            document.getElementById('budgetAmount').value = budget.allocated_amount;
            document.getElementById('budgetNotes').value = budget.notes || '';
            
            // Change form action to edit
            document.querySelector('#budgetForm input[name="action"]').value = 'edit_budget';
            document.querySelector('#addBudgetModal .modal-header h3').textContent = 'Edit Budget Allocation';
            
            openModal('addBudgetModal');
        }

        // Initialize chart
        document.addEventListener('DOMContentLoaded', function() {
            const budgetByType = <?php echo json_encode($budget_by_type); ?>;
            
            if (budgetByType.length > 0) {
                const ctx = document.getElementById('budgetTypeChart').getContext('2d');
                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: budgetByType.map(item => item.category_type.toUpperCase()),
                        datasets: [
                            {
                                label: 'Allocated Budget',
                                data: budgetByType.map(item => item.total_allocated),
                                backgroundColor: '#1976D2',
                                borderColor: '#0D47A1',
                                borderWidth: 1
                            },
                            {
                                label: 'Amount Spent',
                                data: budgetByType.map(item => item.total_spent),
                                backgroundColor: '#FF9800',
                                borderColor: '#F57C00',
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
        });

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>