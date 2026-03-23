<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: student_aid.php');
    exit();
}

$request_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Get financial aid request details
$stmt = $pdo->prepare("
    SELECT 
        sfa.*,
        u.full_name as student_name,
        u.email as student_email,
        u.phone as student_phone,
        u.reg_number as registration_number,
        u.academic_year,
        ur.full_name as reviewer_name,
        d.name as department_name,
        p.name as program_name
    FROM student_financial_aid sfa
    LEFT JOIN users u ON sfa.student_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    LEFT JOIN users ur ON sfa.reviewed_by = ur.id
    WHERE sfa.id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: student_aid.php');
    exit();
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    
    try {
        if ($action === 'update_status') {
            $status = $_POST['status'];
            $amount_approved = $_POST['amount_approved'] ?? 0;
            $review_notes = trim($_POST['review_notes']);
            
            $update_data = [
                'status' => $status,
                'reviewed_by' => $user_id,
                'review_date' => date('Y-m-d H:i:s'),
                'review_notes' => $review_notes
            ];
            
            if ($status === 'approved' || $status === 'disbursed') {
                $update_data['amount_approved'] = $amount_approved;
            }
            
            if ($status === 'disbursed') {
                $update_data['disbursement_date'] = date('Y-m-d');
            }
            
            // Handle approval letter upload
            if (isset($_FILES['approval_letter']) && $_FILES['approval_letter']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/student_appr_letters/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['approval_letter']['name'], PATHINFO_EXTENSION);
                $file_name = 'approval_' . $request['registration_number'] . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['approval_letter']['tmp_name'], $file_path)) {
                    $update_data['approval_letter_path'] = $file_path;
                } else {
                    throw new Exception("Failed to upload approval letter.");
                }
            }
            
            $sql = "UPDATE student_financial_aid SET ";
            $params = [];
            foreach ($update_data as $key => $value) {
                $sql .= "$key = ?, ";
                $params[] = $value;
            }
            $sql = rtrim($sql, ', ') . " WHERE id = ?";
            $params[] = $request_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Request updated successfully!";
            $message_type = "success";
            
            // Refresh request data
            $stmt = $pdo->prepare("
                SELECT 
                    sfa.*,
                    u.full_name as student_name,
                    u.email as student_email,
                    u.phone as student_phone,
                    u.reg_number as registration_number,
                    u.academic_year,
                    ur.full_name as reviewer_name,
                    d.name as department_name,
                    p.name as program_name
                FROM student_financial_aid sfa
                LEFT JOIN users u ON sfa.student_id = u.id
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN programs p ON u.program_id = p.id
                LEFT JOIN users ur ON sfa.reviewed_by = ur.id
                WHERE sfa.id = ?
            ");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

function getStatusBadge($status) {
    $badges = [
        'submitted' => 'status-open',
        'under_review' => 'status-progress',
        'approved' => 'status-success',
        'rejected' => 'status-error',
        'disbursed' => 'status-resolved'
    ];
    return $badges[$status] ?? 'status-open';
}

function getUrgencyBadge($urgency) {
    $badges = [
        'low' => 'status-resolved',
        'medium' => 'status-open',
        'high' => 'status-progress',
        'emergency' => 'status-error'
    ];
    return $badges[$urgency] ?? 'status-open';
}

// Add this function for student aid transactions
function recordStudentAidTransaction($aid_request_id, $approved_amount, $user_id) {
    global $pdo;
    
    try {
        // Get student aid request details
        $stmt = $pdo->prepare("
            SELECT sfa.*, u.full_name as student_name, u.reg_number as registration_number
            FROM student_financial_aid sfa
            LEFT JOIN users u ON sfa.student_id = u.id
            WHERE sfa.id = ?
        ");
        $stmt->execute([$aid_request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception("Student aid request not found");
        }
        
        // Get the student aid category ID
        $stmt = $pdo->prepare("SELECT id FROM budget_categories WHERE category_name = 'Student Financial Aid' AND is_active = 1");
        $stmt->execute();
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            throw new Exception("Student Financial Aid category not found");
        }
        
        // Record the transaction
        $stmt = $pdo->prepare("
            INSERT INTO financial_transactions (
                transaction_type, category_id, amount, description, transaction_date,
                payee_payer, payment_method, status, requested_by, approved_by_finance
            ) VALUES (
                'expense', ?, ?, ?, CURDATE(),
                ?, 'bank_transfer', 'approved_by_finance', ?, ?
            )
        ");
        
        $description = "Student Financial Aid: " . $request['request_title'] . " - " . $request['student_name'];
        
        $stmt->execute([
            $category['id'],
            $approved_amount,
            $description,
            $request['student_name'] . " (" . $request['registration_number'] . ")",
            $request['student_id'],
            $user_id
        ]);
        
        return $pdo->lastInsertId();
        
    } catch (PDOException $e) {
        error_log("Student aid transaction recording error: " . $e->getMessage());
        throw new Exception("Failed to record student aid transaction: " . $e->getMessage());
    }
}

// In view_student_aid.php, update the status update section:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    
    try {
        if ($action === 'update_status') {
            $status = $_POST['status'];
            $amount_approved = $_POST['amount_approved'] ?? 0;
            $review_notes = trim($_POST['review_notes']);
            
            $update_data = [
                'status' => $status,
                'reviewed_by' => $user_id,
                'review_date' => date('Y-m-d H:i:s'),
                'review_notes' => $review_notes
            ];
            
            if ($status === 'approved' || $status === 'disbursed') {
                $update_data['amount_approved'] = $amount_approved;
            }
            
            if ($status === 'disbursed') {
                $update_data['disbursement_date'] = date('Y-m-d');
                
                // Record transaction when marking as disbursed
                $transaction_id = recordStudentAidTransaction($request_id, $amount_approved, $user_id);
                $update_data['transaction_id'] = $transaction_id;
            }
            
            // Handle approval letter upload (existing code remains the same)
            if (isset($_FILES['approval_letter']) && $_FILES['approval_letter']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/student_appr_letters/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['approval_letter']['name'], PATHINFO_EXTENSION);
                $file_name = 'approval_' . $request['registration_number'] . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['approval_letter']['tmp_name'], $file_path)) {
                    $update_data['approval_letter_path'] = $file_path;
                } else {
                    throw new Exception("Failed to upload approval letter.");
                }
            }
            
            $sql = "UPDATE student_financial_aid SET ";
            $params = [];
            foreach ($update_data as $key => $value) {
                $sql .= "$key = ?, ";
                $params[] = $value;
            }
            $sql = rtrim($sql, ', ') . " WHERE id = ?";
            $params[] = $request_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Request updated successfully!" . ($status === 'disbursed' ? " Transaction recorded." : "");
            $message_type = "success";
            
            // Refresh request data
            $stmt = $pdo->prepare("
                SELECT 
                    sfa.*,
                    u.full_name as student_name,
                    u.email as student_email,
                    u.phone as student_phone,
                    u.reg_number as registration_number,
                    u.academic_year,
                    ur.full_name as reviewer_name,
                    d.name as department_name,
                    p.name as program_name
                FROM student_financial_aid sfa
                LEFT JOIN users u ON sfa.student_id = u.id
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN programs p ON u.program_id = p.id
                LEFT JOIN users ur ON sfa.reviewed_by = ur.id
                WHERE sfa.id = ?
            ");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Student Aid Request - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Reuse the same CSS styles from student_aid.php */
        /* All your existing CSS remains the same, just simplified the filters section */
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

        .amount.requested {
            color: var(--warning);
        }

        .amount.approved {
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

        .status-submitted {
            background: #fff3cd;
            color: #856404;
        }

        .status-under_review {
            background: #cce7ff;
            color: #004085;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-disbursed {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* Urgency badges */
        .urgency-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
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

        /* Request Details */
        .request-details {
            display: grid;
            gap: 1rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 120px;
        }

        .detail-value {
            flex: 1;
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
            
            .filters {
                flex-direction: column;
                align-items: stretch;
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
        /* ... (all your existing CSS styles remain exactly the same) ... */

        /* Simplified Filters */
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-width: 200px;
        }

        .filter-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
        }

     
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .detail-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .detail-content {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 150px;
        }

        .detail-value {
            flex: 1;
            color: var(--dark-gray);
            text-align: right;
        }

        .action-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-top: 2rem;
        }

        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--finance-primary);
        }

        .file-upload input {
            display: none;
        }

        .file-upload label {
            cursor: pointer;
            display: block;
        }

        .file-list {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--dark-gray);
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .document-card {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--medium-gray);
        }

        .document-type {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .no-document {
            color: var(--dark-gray);
            font-style: italic;
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
                    <h1>Student Aid Request Details</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
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
        <!-- Sidebar (same as student_aid.php) -->
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
                    <a href="student_aid.php" class="active">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                    </a>
                </li>
                <!-- ... other menu items ... -->
            </ul>
        </nav>

    <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Student Aid Request #<?php echo $request_id; ?></h1>
                    <p>
                        <a href="student_aid.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Requests
                        </a>
                    </p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Request Details -->
            <div class="detail-grid">
                <!-- Student Information -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3 class="detail-title">Student Information</h3>
                    </div>
                    <div class="detail-content">
                        <div class="detail-item">
                            <span class="detail-label">Full Name:</span>
                            <span class="detail-value"><?php echo safe_display($request['student_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Registration Number:</span>
                            <span class="detail-value"><?php echo safe_display($request['registration_number']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email:</span>
                            <span class="detail-value"><?php echo safe_display($request['student_email']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Phone:</span>
                            <span class="detail-value"><?php echo safe_display($request['student_phone']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Department:</span>
                            <span class="detail-value"><?php echo safe_display($request['department_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Program:</span>
                            <span class="detail-value"><?php echo safe_display($request['program_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Academic Year:</span>
                            <span class="detail-value"><?php echo safe_display($request['academic_year']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Request Information -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3 class="detail-title">Request Information</h3>
                        <span class="status-badge status-<?php echo $request['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                        </span>
                    </div>
                    <div class="detail-content">
                        <div class="detail-item">
                            <span class="detail-label">Request Title:</span>
                            <span class="detail-value"><?php echo safe_display($request['request_title']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Amount Requested:</span>
                            <span class="detail-value amount requested">RWF <?php echo number_format($request['amount_requested'], 2); ?></span>
                        </div>
                        <?php if ($request['amount_approved'] > 0): ?>
                        <div class="detail-item">
                            <span class="detail-label">Amount Approved:</span>
                            <span class="detail-value amount approved">RWF <?php echo number_format($request['amount_approved'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <span class="detail-label">Urgency Level:</span>
                            <span class="detail-value">
                                <span class="urgency-badge urgency-<?php echo $request['urgency_level']; ?>">
                                    <?php echo ucfirst($request['urgency_level']); ?>
                                </span>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date Submitted:</span>
                            <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></span>
                        </div>
                        <?php if ($request['reviewer_name']): ?>
                        <div class="detail-item">
                            <span class="detail-label">Reviewed By:</span>
                            <span class="detail-value"><?php echo safe_display($request['reviewer_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Review Date:</span>
                            <span class="detail-value"><?php echo $request['review_date'] ? date('F j, Y g:i A', strtotime($request['review_date'])) : 'N/A'; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($request['disbursement_date']): ?>
                        <div class="detail-item">
                            <span class="detail-label">Disbursement Date:</span>
                            <span class="detail-value"><?php echo date('F j, Y', strtotime($request['disbursement_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Purpose and Documents -->
            <div class="card">
                <div class="card-header">
                    <h3>Purpose & Supporting Documents</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Purpose and Justification</label>
                        <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">
                            <?php echo safe_display($request['purpose']); ?>
                        </div>
                    </div>

                    <?php if ($request['review_notes']): ?>
                    <div class="form-group">
                        <label class="form-label">Review Notes</label>
                        <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">
                            <?php echo safe_display($request['review_notes']); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Attached Documents</label>
                        <div class="documents-grid">
                            <!-- Student's Request Letter -->
                            <div class="document-card">
                                <div class="document-type">Student's Request Letter</div>
                                <?php if ($request['request_letter_path']): ?>
                                    <a href="../<?php echo $request['request_letter_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download Letter
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No request letter uploaded</div>
                                <?php endif; ?>
                            </div>

                            <!-- Supporting Documents -->
                            <div class="document-card">
                                <div class="document-type">Supporting Documents</div>
                                <?php if ($request['supporting_docs_path']): ?>
                                    <a href="../<?php echo $request['supporting_docs_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download Documents
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No supporting documents uploaded</div>
                                <?php endif; ?>
                            </div>

                            <!-- Approval Letter (Vice Guild Finance) -->
                            <div class="document-card">
                                <div class="document-type">Approval Letter</div>
                                <?php if ($request['approval_letter_path']): ?>
                                    <a href="../<?php echo $request['approval_letter_path']; ?>" target="_blank" class="btn btn-success btn-sm">
                                        <i class="fas fa-download"></i> Download Approval
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No approval letter uploaded yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Section -->
            <div class="action-section">
                <h3 style="margin-bottom: 1.5rem;">Update Request Status</h3>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_status">
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control" required id="statusSelect">
                            <option value="under_review" <?php echo $request['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="approved" <?php echo $request['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $request['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="disbursed" <?php echo $request['status'] === 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                        </select>
                    </div>

                    <div class="form-group" id="amountApprovedGroup">
                        <label class="form-label">Amount to Approve (RWF)</label>
                        <input type="number" class="form-control" name="amount_approved" 
                               value="<?php echo $request['amount_approved'] ? $request['amount_approved'] : $request['amount_requested']; ?>" 
                               step="0.01" min="0" required>
                        <small style="color: var(--dark-gray);">
                            Original requested amount: RWF <?php echo number_format($request['amount_requested'], 2); ?>
                        </small>
                    </div>

                    <div class="form-group" id="approvalLetterGroup">
                        <label class="form-label">Upload Approval Letter</label>
                        <div class="file-upload">
                            <input type="file" name="approval_letter" id="approval_letter" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <label for="approval_letter">
                                <i class="fas fa-upload" style="font-size: 2rem; margin-bottom: 0.5rem; color: var(--dark-gray);"></i>
                                <p>Click to upload approval letter</p>
                                <p style="font-size: 0.8rem; color: var(--dark-gray);">PDF, DOC, Images (Max: 5MB)</p>
                            </label>
                            <div id="approval_letter_name" class="file-list"></div>
                        </div>
                        <?php if ($request['approval_letter_path']): ?>
                            <small style="color: var(--success);">
                                <i class="fas fa-check-circle"></i> Approval letter already uploaded
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Review Notes</label>
                        <textarea class="form-control" name="review_notes" rows="4" placeholder="Enter your review comments and decision rationale..." required><?php echo safe_display($request['review_notes']); ?></textarea>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <a href="student_aid.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Request</button>
                    </div>
                </form>
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

        // File input display
        document.getElementById('approval_letter').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'No file chosen';
            document.getElementById('approval_letter_name').textContent = fileName;
        });

        // Show/hide amount field based on status
        const statusSelect = document.getElementById('statusSelect');
        const amountGroup = document.getElementById('amountApprovedGroup');
        const approvalLetterGroup = document.getElementById('approvalLetterGroup');

        function toggleFields() {
            const status = statusSelect.value;
            
            if (status === 'approved' || status === 'disbursed') {
                amountGroup.style.display = 'block';
                approvalLetterGroup.style.display = 'block';
            } else {
                amountGroup.style.display = 'none';
                approvalLetterGroup.style.display = 'none';
            }
        }

        statusSelect.addEventListener('change', toggleFields);
        
        // Initialize on page load
        toggleFields();
    </script>
</body>
</html>