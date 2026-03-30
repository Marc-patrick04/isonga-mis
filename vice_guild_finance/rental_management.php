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

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Handle file upload
function handleFileUpload($file, $upload_dir = '../uploads/rental_receipts/') {
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $file_name = 'receipt_' . time() . '_' . uniqid() . '.' . $file_extension;
    $file_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        return $file_path;
    }
    
    return false;
}

// Function to record rental income transaction
function recordRentalIncomeTransaction($property_id, $amount, $payment_date, $receipt_number, $paid_by, $user_id) {
    global $pdo;
    
    try {
        // Get property details
        $stmt = $pdo->prepare("SELECT property_name, tenant_name FROM rental_properties WHERE id = ?");
        $stmt->execute([$property_id]);
        $property = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$property) {
            throw new Exception("Property not found");
        }
        
        // Get the rental income category ID (you may need to create this category)
        $stmt = $pdo->prepare("SELECT id FROM budget_categories WHERE category_name ILIKE '%rental%' AND category_type = 'income' AND is_active = true LIMIT 1");
        $stmt->execute();
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            // Create rental income category if it doesn't exist
            $stmt = $pdo->prepare("INSERT INTO budget_categories (category_name, category_type, description, is_active) VALUES (?, 'income', 'Rental income from properties', true)");
            $stmt->execute(['Rental Income']);
            $category_id = $pdo->lastInsertId();
        } else {
            $category_id = $category['id'];
        }
        
        // Record the transaction
        $stmt = $pdo->prepare("
            INSERT INTO financial_transactions (
                transaction_type, category_id, amount, description, transaction_date,
                reference_number, payee_payer, payment_method, status, 
                requested_by, approved_by_finance, approved_by_president, approved_at
            ) VALUES (
                'income', ?, ?, ?, ?,
                ?, ?, 'bank_transfer', 'completed',
                ?, ?, ?, NOW()
            )
        ");
        
        $description = "Rental Income: " . $property['property_name'] . " - " . $property['tenant_name'];
        
        $stmt->execute([
            $category_id,
            $amount,
            $description,
            $payment_date,
            $receipt_number,
            $paid_by,
            $user_id, // requested_by
            $user_id, // approved_by_finance  
            $user_id  // approved_by_president
        ]);
        
        $transaction_id = $pdo->lastInsertId();
        error_log("Rental transaction recorded successfully. ID: " . $transaction_id . " for property: " . $property_id);
        
        return $transaction_id;
        
    } catch (PDOException $e) {
        error_log("Rental transaction recording error: " . $e->getMessage());
        throw new Exception("Failed to record rental transaction: " . $e->getMessage());
    }
}

// Add new rental property
if ($action === 'add_property' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $property_name = trim($_POST['property_name']);
    $property_location = trim($_POST['property_location']);
    $monthly_rent = $_POST['monthly_rent'];
    $tenant_name = trim($_POST['tenant_name']);
    $tenant_phone = trim($_POST['tenant_phone']);
    $tenant_email = trim($_POST['tenant_email'] ?? '');
    $lease_start_date = $_POST['lease_start_date'];
    $lease_end_date = $_POST['lease_end_date'];
    $contract_file = $_FILES['contract_file'] ?? null;
    
    try {
        $contract_file_path = null;
        if ($contract_file && $contract_file['error'] === UPLOAD_ERR_OK) {
            $contract_file_path = handleFileUpload($contract_file, '../uploads/rental_contracts/');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO rental_properties 
            (property_name, property_location, monthly_rent, tenant_name, tenant_phone, tenant_email, lease_start_date, lease_end_date, contract_file_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $property_name, $property_location, $monthly_rent, $tenant_name, 
            $tenant_phone, $tenant_email, $lease_start_date, $lease_end_date, $contract_file_path
        ]);
        
        $message = "Rental property added successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error adding property: " . $e->getMessage();
        $message_type = "error";
    }
}

// Update rental property
if ($action === 'update_property' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $property_id = $_POST['property_id'];
    $property_name = trim($_POST['property_name']);
    $property_location = trim($_POST['property_location']);
    $monthly_rent = $_POST['monthly_rent'];
    $tenant_name = trim($_POST['tenant_name']);
    $tenant_phone = trim($_POST['tenant_phone']);
    $tenant_email = trim($_POST['tenant_email'] ?? '');
    $lease_start_date = $_POST['lease_start_date'];
    $lease_end_date = $_POST['lease_end_date'];
    $is_active = isset($_POST['is_active']) ? true : false;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE rental_properties 
            SET property_name = ?, property_location = ?, monthly_rent = ?, tenant_name = ?, 
                tenant_phone = ?, tenant_email = ?, lease_start_date = ?, lease_end_date = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $property_name, $property_location, $monthly_rent, $tenant_name, 
            $tenant_phone, $tenant_email, $lease_start_date, $lease_end_date, $is_active, $property_id
        ]);
        
        $message = "Rental property updated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error updating property: " . $e->getMessage();
        $message_type = "error";
    }
}

// Record rental payment - CORRECTED VERSION
if ($action === 'record_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $property_id = $_POST['property_id'];
    $payment_date = $_POST['payment_date'];
    $amount = $_POST['amount'];
    $receipt_number = trim($_POST['receipt_number']);
    $paid_by = trim($_POST['paid_by']);
    $payment_period = $_POST['payment_period'];
    $notes = trim($_POST['notes'] ?? '');
    $receipt_file = $_FILES['receipt_file'] ?? null;
    
    try {
        $receipt_file_path = null;
        if ($receipt_file && $receipt_file['error'] === UPLOAD_ERR_OK) {
            $receipt_file_path = handleFileUpload($receipt_file);
        }
        
        // Insert rental payment record
        $stmt = $pdo->prepare("
            INSERT INTO rental_payments 
            (property_id, payment_date, amount, receipt_number, receipt_file_path, paid_by, received_by, payment_period, notes, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified')
        ");
        $stmt->execute([
            $property_id, $payment_date, $amount, $receipt_number, $receipt_file_path,
            $paid_by, $user_id, $payment_period, $notes
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Record financial transaction
        $transaction_id = recordRentalIncomeTransaction($property_id, $amount, $payment_date, $receipt_number, $paid_by, $user_id);
        
        // Update rental payment with transaction ID if you have that column
        try {
            $stmt = $pdo->prepare("UPDATE rental_payments SET transaction_id = ? WHERE id = ?");
            $stmt->execute([$transaction_id, $payment_id]);
        } catch (PDOException $e) {
            // If transaction_id column doesn't exist, just log it
            error_log("Note: transaction_id column may not exist in rental_payments table");
        }
        
        $message = "Rental payment recorded successfully! Transaction ID: " . $transaction_id;
        $message_type = "success";
        
    } catch (PDOException $e) {
        $message = "Error recording payment: " . $e->getMessage();
        $message_type = "error";
        error_log("Record payment error: " . $e->getMessage());
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
        error_log("Record payment exception: " . $e->getMessage());
    }
}

// Verify payment
if ($action === 'verify_payment' && isset($_GET['payment_id'])) {
    $payment_id = $_GET['payment_id'];
    $verification_notes = $_GET['notes'] ?? '';
    
    try {
        // Get payment details first
        $stmt = $pdo->prepare("SELECT property_id, amount, payment_date, receipt_number, paid_by FROM rental_payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment) {
            // Record financial transaction when verifying
            $transaction_id = recordRentalIncomeTransaction(
                $payment['property_id'], 
                $payment['amount'], 
                $payment['payment_date'], 
                $payment['receipt_number'], 
                $payment['paid_by'], 
                $user_id
            );
            
            // Update payment status and transaction ID
            $stmt = $pdo->prepare("UPDATE rental_payments SET status = 'verified', verification_notes = ?, transaction_id = ? WHERE id = ?");
            $stmt->execute([$verification_notes, $transaction_id, $payment_id]);
            
            $message = "Payment verified successfully! Transaction recorded (ID: " . $transaction_id . ")";
        } else {
            $message = "Payment not found!";
            $message_type = "error";
        }
        
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error verifying payment: " . $e->getMessage();
        $message_type = "error";
        error_log("Verify payment error: " . $e->getMessage());
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
        error_log("Verify payment exception: " . $e->getMessage());
    }
}

// Delete payment
if ($action === 'delete_payment' && isset($_GET['payment_id'])) {
    $payment_id = $_GET['payment_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM rental_payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        
        $message = "Payment record deleted successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error deleting payment: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get rental properties
try {
    $stmt = $pdo->query("
        SELECT rp.*, 
               COUNT(rpm.id) as payment_count,
               COALESCE(SUM(rpm.amount), 0) as total_collected,
               (rp.lease_end_date - CURRENT_DATE) as days_remaining
        FROM rental_properties rp
        LEFT JOIN rental_payments rpm ON rp.id = rpm.property_id AND rpm.status = 'verified'
        GROUP BY rp.id, rp.property_name, rp.property_location, rp.monthly_rent,
                 rp.tenant_name, rp.tenant_phone, rp.tenant_email,
                 rp.lease_start_date, rp.lease_end_date, rp.is_active,
                 rp.contract_file_path, rp.created_at
        ORDER BY rp.is_active DESC, rp.property_name
    ");
    $rental_properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rental_properties = [];
    error_log("Rental properties error: " . $e->getMessage());
}

// Get recent payments
try {
    $stmt = $pdo->query("
        SELECT rpm.*, rp.property_name, rp.tenant_name, u.full_name as received_by_name
        FROM rental_payments rpm
        LEFT JOIN rental_properties rp ON rpm.property_id = rp.id
        LEFT JOIN users u ON rpm.received_by = u.id
        ORDER BY rpm.payment_date DESC, rpm.created_at DESC
        LIMIT 20
    ");
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_payments = [];
    error_log("Recent payments error: " . $e->getMessage());
}

// Get payment statistics
try {
    // Total collected this year
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total_collected 
        FROM rental_payments 
        WHERE status = 'verified' 
        AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)
    ");
    $stmt->execute();
    $total_collected = $stmt->fetch(PDO::FETCH_ASSOC)['total_collected'] ?? 0;

    // Monthly average
    $stmt = $pdo->prepare("
        SELECT AVG(monthly_sum) as monthly_average 
        FROM (
            SELECT EXTRACT(MONTH FROM payment_date) as month, SUM(amount) as monthly_sum 
            FROM rental_payments 
            WHERE status = 'verified' 
            AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)
            GROUP BY EXTRACT(MONTH FROM payment_date)
        ) monthly_totals
    ");
    $stmt->execute();
    $monthly_average = $stmt->fetch(PDO::FETCH_ASSOC)['monthly_average'] ?? 0;

    // Pending verification
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM rental_payments WHERE status = 'pending'");
    $pending_verification = $stmt->fetch(PDO::FETCH_ASSOC)['pending'] ?? 0;

    // Active properties
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM rental_properties WHERE is_active = true");
    $active_properties = $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0;

    // Payment trends (last 6 months)
    $stmt = $pdo->query("
        SELECT 
            TO_CHAR(payment_date, 'YYYY-MM') as month,
            SUM(amount) as monthly_total,
            COUNT(*) as payment_count
        FROM rental_payments 
        WHERE status = 'verified'
        AND payment_date >= CURRENT_DATE - INTERVAL '6 months'
        GROUP BY TO_CHAR(payment_date, 'YYYY-MM')
        ORDER BY month DESC
    ");
    $payment_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_collected = $monthly_average = $pending_verification = $active_properties = 0;
    $payment_trends = [];
    error_log("Payment statistics error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Rental Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            overflow: hidden;
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
            color: var(--text-dark);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1rem;
            text-decoration: none;
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
            border: none;
            cursor: pointer;
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
            text-align: center;
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
            font-size: 1.4rem;
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

        .two-column-grid {
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

        /* Status badges */
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

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-verified {
            background: #d4edda;
            color: var(--success);
        }

        .status-disputed {
            background: #f8d7da;
            color: #721c24;
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
            transition: var(--transition);
        }

        .form-select:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        .form-file {
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

        /* Property Cards */
        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .property-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--success);
            transition: var(--transition);
            overflow: hidden;
        }

        .property-card.inactive {
            border-left-color: var(--dark-gray);
            opacity: 0.7;
        }

        .property-card.expiring {
            border-left-color: var(--warning);
        }

        .property-card-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--finance-light);
        }

        .property-card-body {
            padding: 1rem;
        }

        .property-card-footer {
            padding: 1rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .property-detail {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .property-label {
            font-weight: 600;
            color: var(--text-dark);
        }

        .property-value {
            color: var(--dark-gray);
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
            max-width: 600px;
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

        /* Charts */
        .chart-container {
            position: relative;
            height: 300px;
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

        /* ── Responsive ── */

        /* ── Tablet/desktop sidebar collapse boundary ── */
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

            .main-content.sidebar-collapsed {
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

            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                backdrop-filter: blur(2px);
                z-index: 999;
            }

            .overlay.active {
                display: block;
            }

            #sidebarToggleBtn {
                display: none;
            }
        }

        /* ── Tablet ── */
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

            .two-column-grid {
                grid-template-columns: 1fr;
            }

            .property-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                white-space: nowrap;
                flex-wrap: nowrap;
            }

            /* Tables scroll horizontally */
            .card-body .table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                white-space: nowrap;
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .stat-icon {
                width: 42px;
                height: 42px;
                font-size: 1.1rem;
            }

            .welcome-section h1 {
                font-size: 1.25rem;
            }

            .chart-container {
                height: 220px;
            }

            .btn-sm {
                padding: 0.2rem 0.4rem;
                font-size: 0.65rem;
            }
        }

        /* ── Small phones ── */
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

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .welcome-section h1 {
                font-size: 1.1rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .card-body {
                padding: 0.75rem;
            }

            .modal-content {
                width: 95%;
                max-height: 95vh;
            }

            .modal-body {
                padding: 1rem;
            }

            .chart-container {
                height: 160px;
            }

            .property-card-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
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
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Rental Management</h1>
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
                    <a href="rental_management.php" class="active">
                        <i class="fas fa-home"></i>
                        <span>Rental Properties</span>
                        <?php if ($pending_verification > 0): ?>
                            <span class="menu-badge"><?php echo $pending_verification; ?></span>
                        <?php endif; ?>
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
                    <h1>Rental Property Management 🏠</h1>
                    <p>Manage rental properties, contracts, and payment records for <?php echo $current_academic_year; ?> academic year</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Rental Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_properties; ?></div>
                        <div class="stat-label">Active Properties</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-building"></i> 4 Total Properties
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_collected, 2); ?></div>
                        <div class="stat-label">Total Collected (This Year)</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-arrow-up"></i> Revenue
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_verification; ?></div>
                        <div class="stat-label">Pending Verification</div>
                        <div class="stat-trend trend-negative">
                            <i class="fas fa-exclamation-circle"></i> Needs Attention
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($monthly_average, 2); ?></div>
                        <div class="stat-label">Monthly Average</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-bar"></i> Steady
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('properties-tab')">Rental Properties</button>
                <button class="tab" onclick="openTab('payments-tab')">Payment Records</button>
                <button class="tab" onclick="openTab('analytics-tab')">Analytics</button>
            </div>

            <!-- Properties Tab -->
            <div id="properties-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Rental Properties</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="openModal('addPropertyModal')" title="Add New Property">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="property-grid">
                            <?php if (empty($rental_properties)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem; grid-column: 1 / -1;">
                                    <i class="fas fa-home fa-3x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No rental properties found</p>
                                    <button class="btn btn-primary" onclick="openModal('addPropertyModal')">
                                        <i class="fas fa-plus"></i> Add First Property
                                    </button>
                                </div>
                            <?php else: ?>
                                <?php foreach ($rental_properties as $property): ?>
                                    <?php
                                    $card_class = 'property-card';
                                    if (!$property['is_active']) {
                                        $card_class .= ' inactive';
                                    } elseif ($property['days_remaining'] < 30 && $property['days_remaining'] > 0) {
                                        $card_class .= ' expiring';
                                    }
                                    ?>
                                    <div class="<?php echo $card_class; ?>">
                                        <div class="property-card-header">
                                            <h4><?php echo htmlspecialchars($property['property_name']); ?></h4>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <span class="status-badge status-<?php echo $property['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $property['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                                <?php if ($property['days_remaining'] < 30 && $property['days_remaining'] > 0): ?>
                                                    <span class="status-badge status-pending" title="Contract expiring soon">
                                                        <i class="fas fa-clock"></i> <?php echo $property['days_remaining']; ?>d
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="property-card-body">
                                            <div class="property-detail">
                                                <span class="property-label">Location:</span>
                                                <span class="property-value"><?php echo htmlspecialchars($property['property_location']); ?></span>
                                            </div>
                                            <div class="property-detail">
                                                <span class="property-label">Monthly Rent:</span>
                                                <span class="property-value amount income">RWF <?php echo number_format($property['monthly_rent'], 2); ?></span>
                                            </div>
                                            <div class="property-detail">
                                                <span class="property-label">Tenant:</span>
                                                <span class="property-value"><?php echo htmlspecialchars($property['tenant_name']); ?></span>
                                            </div>
                                            <div class="property-detail">
                                                <span class="property-label">Contact:</span>
                                                <span class="property-value"><?php echo htmlspecialchars($property['tenant_phone']); ?></span>
                                            </div>
                                            <div class="property-detail">
                                                <span class="property-label">Lease Period:</span>
                                                <span class="property-value">
                                                    <?php echo date('M j, Y', strtotime($property['lease_start_date'])); ?> - 
                                                    <?php echo date('M j, Y', strtotime($property['lease_end_date'])); ?>
                                                </span>
                                            </div>
                                            <div class="property-detail">
                                                <span class="property-label">Total Collected:</span>
                                                <span class="property-value amount income">RWF <?php echo number_format($property['total_collected'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="property-card-footer">
                                            <div style="display: flex; gap: 0.25rem;">
                                                <button class="btn btn-primary btn-sm" onclick="recordPayment(<?php echo $property['id']; ?>)">
                                                    <i class="fas fa-money-bill-wave"></i> Record Payment
                                                </button>
                                                <button class="btn btn-warning btn-sm" onclick="editProperty(<?php echo $property['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                            <?php if ($property['contract_file_path']): ?>
                                                <a href="../<?php echo $property['contract_file_path']; ?>" target="_blank" class="btn btn-sm" title="View Contract">
                                                    <i class="fas fa-file-contract"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payments Tab -->
            <div id="payments-tab" class="tab-content">
                <div class="two-column-grid">
                    <!-- Recent Payments -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Rental Payments</h3>
                            <div class="card-header-actions">
                                <span class="filter-label">
                                    <?php echo count($recent_payments); ?> payment(s)
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Tenant</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_payments)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                                No payment records found
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_payments as $payment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($payment['property_name']); ?></td>
                                                <td><?php echo htmlspecialchars($payment['tenant_name']); ?></td>
                                                <td class="amount income">RWF <?php echo number_format($payment['amount'], 2); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $payment['status']; ?>">
                                                        <?php echo ucfirst($payment['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 0.25rem;">
                                                        <?php if ($payment['receipt_file_path']): ?>
                                                            <a href="../<?php echo $payment['receipt_file_path']; ?>" target="_blank" class="btn btn-primary btn-sm" title="View Receipt">
                                                                <i class="fas fa-receipt"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($payment['status'] === 'pending'): ?>
                                                            <button class="btn btn-success btn-sm" onclick="verifyPayment(<?php echo $payment['id']; ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <a href="?action=delete_payment&payment_id=<?php echo $payment['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this payment record?')">
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

                    <!-- Record Payment -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Record New Payment</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data" id="paymentForm">
                                <input type="hidden" name="action" value="record_payment">
                                
                                <div class="form-group">
                                    <label class="form-label">Property</label>
                                    <select class="form-select" name="property_id" required>
                                        <option value="">Select Property</option>
                                        <?php foreach ($rental_properties as $property): ?>
                                            <?php if ($property['is_active']): ?>
                                                <option value="<?php echo $property['id']; ?>">
                                                    <?php echo htmlspecialchars($property['property_name']); ?> - <?php echo htmlspecialchars($property['tenant_name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Payment Date</label>
                                    <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Amount (RWF)</label>
                                    <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Receipt Number</label>
                                    <input type="text" class="form-control" name="receipt_number" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Paid By</label>
                                    <input type="text" class="form-control" name="paid_by" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Payment Period</label>
                                    <select class="form-select" name="payment_period" required>
                                        <option value="">Select Period</option>
                                        <option value="First Semester (5 months)">First Semester (5 months)</option>
                                        <option value="Second Semester (5 months)">Second Semester (5 months)</option>
                                        <option value="Monthly">Monthly</option>
                                        <option value="Full Year (10 months)">Full Year (10 months)</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank Receipt</label>
                                    <input type="file" class="form-file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small style="color: var(--dark-gray);">Upload scanned bank receipt (PDF, JPG, PNG)</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Notes (Optional)</label>
                                    <textarea class="form-control" name="notes" rows="2" placeholder="Any additional notes about this payment"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Record Payment
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics-tab" class="tab-content">
                <div class="two-column-grid">
                    <!-- Payment Trends -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Payment Trends (Last 6 Months)</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="paymentTrendsChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Property Performance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Property Performance</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="propertyPerformanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Property Modal -->
    <div id="addPropertyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Rental Property</h3>
                <button class="close" onclick="closeModal('addPropertyModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" enctype="multipart/form-data" id="propertyForm">
                    <input type="hidden" name="action" value="add_property">
                    <input type="hidden" name="property_id" id="editPropertyId">
                    
                    <div class="form-group">
                        <label class="form-label">Property Name</label>
                        <input type="text" class="form-control" name="property_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Property Location</label>
                        <input type="text" class="form-control" name="property_location" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Monthly Rent (RWF)</label>
                        <input type="number" class="form-control" name="monthly_rent" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tenant Name</label>
                        <input type="text" class="form-control" name="tenant_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tenant Phone</label>
                        <input type="tel" class="form-control" name="tenant_phone" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tenant Email (Optional)</label>
                        <input type="email" class="form-control" name="tenant_email">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lease Start Date</label>
                        <input type="date" class="form-control" name="lease_start_date" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lease End Date</label>
                        <input type="date" class="form-control" name="lease_end_date" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contract Document (Optional)</label>
                        <input type="file" class="form-file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png">
                        <small style="color: var(--dark-gray);">Upload scanned contract document</small>
                    </div>

                    <div class="form-group" id="activeField" style="display: none;">
                        <label class="form-label">
                            <input type="checkbox" name="is_active" id="isActive" value="1" checked> Active Property
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addPropertyModal')">Cancel</button>
                <button type="submit" form="propertyForm" class="btn btn-primary">Save Property</button>
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
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars"></i>';
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

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
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
            document.getElementById('propertyForm').reset();
            document.getElementById('editPropertyId').value = '';
            document.getElementById('activeField').style.display = 'none';
            document.querySelector('#addPropertyModal .modal-header h3').textContent = 'Add Rental Property';
            document.querySelector('#propertyForm input[name="action"]').value = 'add_property';
        }

        // Edit property
        function editProperty(propertyId) {
            const properties = <?php echo json_encode($rental_properties); ?>;
            const property = properties.find(p => p.id == propertyId);

            if (property) {
                document.getElementById('editPropertyId').value = property.id;
                document.querySelector('input[name="property_name"]').value = property.property_name;
                document.querySelector('input[name="property_location"]').value = property.property_location;
                document.querySelector('input[name="monthly_rent"]').value = property.monthly_rent;
                document.querySelector('input[name="tenant_name"]').value = property.tenant_name;
                document.querySelector('input[name="tenant_phone"]').value = property.tenant_phone;
                document.querySelector('input[name="tenant_email"]').value = property.tenant_email || '';
                document.querySelector('input[name="lease_start_date"]').value = property.lease_start_date;
                document.querySelector('input[name="lease_end_date"]').value = property.lease_end_date;
                document.getElementById('isActive').checked = property.is_active;
                document.getElementById('activeField').style.display = 'block';

                document.querySelector('#propertyForm input[name="action"]').value = 'update_property';
                document.querySelector('#addPropertyModal .modal-header h3').textContent = 'Edit Rental Property';

                openModal('addPropertyModal');
            }
        }

        // Record payment for specific property
        function recordPayment(propertyId) {
            document.querySelector('#paymentForm select[name="property_id"]').value = propertyId;
            // Switch to payments tab
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById('payments-tab').classList.add('active');
            document.querySelectorAll('.tab')[1].classList.add('active');
            document.querySelector('#paymentForm input[name="amount"]').focus();
        }

        // Verify payment
        function verifyPayment(paymentId) {
            const notes = prompt('Enter verification notes (optional):');
            if (notes !== null) {
                window.location.href = `?action=verify_payment&payment_id=${paymentId}&notes=${encodeURIComponent(notes)}`;
            }
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Payment Trends Chart
            const paymentTrends = <?php echo json_encode($payment_trends); ?>;
            if (paymentTrends.length > 0) {
                const trendsCtx = document.getElementById('paymentTrendsChart').getContext('2d');
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: paymentTrends.map(trend => {
                            const [year, month] = trend.month.split('-');
                            return new Date(year, month - 1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                        }).reverse(),
                        datasets: [{
                            label: 'Monthly Rental Income',
                            data: paymentTrends.map(trend => trend.monthly_total).reverse(),
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

            // Property Performance Chart
            const properties = <?php echo json_encode($rental_properties); ?>;
            const activeProperties = properties.filter(p => p.is_active);

            if (activeProperties.length > 0) {
                const performanceCtx = document.getElementById('propertyPerformanceChart').getContext('2d');
                new Chart(performanceCtx, {
                    type: 'bar',
                    data: {
                        labels: activeProperties.map(prop => prop.property_name),
                        datasets: [{
                            label: 'Total Collected',
                            data: activeProperties.map(prop => prop.total_collected),
                            backgroundColor: '#1976D2',
                            borderColor: '#0D47A1',
                            borderWidth: 1
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

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Auto-fill payment amount based on property selection
        document.querySelector('#paymentForm select[name="property_id"]').addEventListener('change', function() {
            const propertyId = this.value;
            const properties = <?php echo json_encode($rental_properties); ?>;
            const property = properties.find(p => p.id == propertyId);

            if (property) {
                document.querySelector('#paymentForm input[name="amount"]').value = property.monthly_rent;
            }
        });
    </script>
</body>
</html>