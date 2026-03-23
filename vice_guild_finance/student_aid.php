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

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Delete student aid request
if ($action === 'delete_request' && isset($_GET['id'])) {
    $aid_id = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM student_financial_aid WHERE id = ?");
        $stmt->execute([$aid_id]);
        
        $message = "Student aid request deleted successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error deleting request: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get filter parameters (simplified)
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query for student aid requests
$query = "
    SELECT 
        sfa.*,
        u.full_name as student_name,
        u.email as student_email,
        u.phone as student_phone,
        u.reg_number as registration_number,
        ur.full_name as reviewer_name,
        d.name as department_name,
        p.name as program_name
    FROM student_financial_aid sfa
    LEFT JOIN users u ON sfa.student_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    LEFT JOIN users ur ON sfa.reviewed_by = ur.id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND sfa.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $query .= " AND (u.full_name LIKE ? OR u.reg_number LIKE ? OR sfa.request_title LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY 
    CASE 
        WHEN sfa.urgency_level = 'emergency' THEN 1
        WHEN sfa.urgency_level = 'high' THEN 2
        WHEN sfa.urgency_level = 'medium' THEN 3
        ELSE 4
    END,
    sfa.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $aid_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $aid_requests = [];
    error_log("Student aid requests error: " . $e->getMessage());
}

// Get statistics
try {
    // Total requests
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM student_financial_aid");
    $total_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Pending requests
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM student_financial_aid WHERE status = 'submitted'");
    $pending_requests = $stmt->fetch(PDO::FETCH_ASSOC)['pending'] ?? 0;

    // Approved requests
    $stmt = $pdo->query("SELECT COUNT(*) as approved FROM student_financial_aid WHERE status = 'approved'");
    $approved_requests = $stmt->fetch(PDO::FETCH_ASSOC)['approved'] ?? 0;

    // Total amount requested
    $stmt = $pdo->query("SELECT SUM(amount_requested) as total_requested FROM student_financial_aid WHERE status IN ('submitted', 'under_review', 'approved')");
    $total_requested = $stmt->fetch(PDO::FETCH_ASSOC)['total_requested'] ?? 0;

    // Total amount approved
    $stmt = $pdo->query("SELECT SUM(amount_approved) as total_approved FROM student_financial_aid WHERE status IN ('approved', 'disbursed')");
    $total_approved = $stmt->fetch(PDO::FETCH_ASSOC)['total_approved'] ?? 0;

} catch (PDOException $e) {
    $total_requests = $pending_requests = $approved_requests = 0;
    $total_requested = $total_approved = 0;
    error_log("Student aid statistics error: " . $e->getMessage());
}

// Get current academic year (fallback if function doesn't exist)
try {
    require_once '../config/academic_year.php';
    $current_academic_year = getCurrentAcademicYear();
} catch (Exception $e) {
    $current_academic_year = date('Y') . '/' . (date('Y') + 1);
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
    <title>Student Financial Aid - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
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
                    <h1>Isonga - Student Financial Aid</h1>
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
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php" class="active">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                        <?php if ($pending_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_requests; ?></span>
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
                    <h1>Student Financial Aid 💰</h1>
                    <p>Manage and review student financial assistance requests for <?php echo $current_academic_year; ?> academic year</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Student Aid Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
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
                        <div class="stat-number"><?php echo $pending_requests; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approved_requests; ?></div>
                        <div class="stat-label">Approved Requests</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_approved, 2); ?></div>
                        <div class="stat-label">Total Approved</div>
                    </div>
                </div>
            </div>

            <!-- Simple Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="form-select" onchange="applyFilters()" id="statusFilter">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="disbursed" <?php echo $status_filter === 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                    </select>
                </div>
                <div class="filter-group" style="flex: 1;">
                    <label class="filter-label">Search</label>
                    <input type="text" class="form-control" placeholder="Search by name, registration number..." 
                           value="<?php echo htmlspecialchars($search); ?>" id="searchInput" onkeyup="applyFilters()">
                </div>
                <div class="filter-group">
                    <label class="filter-label" style="visibility: hidden;">Actions</label>
                    <button class="btn btn-primary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>

            <!-- Student Aid Requests Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Student Financial Aid Requests</h3>
                    <div class="card-header-actions">
                        <span class="filter-label" style="margin-right: 1rem;">
                            Showing <?php echo count($aid_requests); ?> request(s)
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($aid_requests)): ?>
                        <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No student aid requests found matching your criteria.</p>
                            <?php if ($status_filter !== 'all' || !empty($search)): ?>
                                <button class="btn btn-primary" onclick="resetFilters()" style="margin-top: 1rem;">
                                    <i class="fas fa-redo"></i> Reset Filters
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Request Details</th>
                                    <th>Amount</th>
                                    <th>Urgency</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aid_requests as $request): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($request['student_name']); ?></strong>
                                            <br>
                                            <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($request['registration_number']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($request['request_title']); ?></strong>
                                            <br>
                                            <small style="color: var(--dark-gray);">
                                                <?php echo htmlspecialchars(substr($request['purpose'], 0, 100)); ?>
                                                <?php echo strlen($request['purpose']) > 100 ? '...' : ''; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="amount requested">RWF <?php echo number_format($request['amount_requested'], 2); ?></div>
                                            <?php if ($request['amount_approved'] > 0): ?>
                                                <div class="amount approved">RWF <?php echo number_format($request['amount_approved'], 2); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="urgency-badge urgency-<?php echo $request['urgency_level']; ?>">
                                                <?php echo ucfirst($request['urgency_level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem;">
                                                <a href="view_student_aid.php?id=<?php echo $request['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="?action=delete_request&id=<?php echo $request['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this request?')">
                                                    <i class="fas fa-trash"></i>
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

        // Filter functionality
        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('searchInput').value;
            
            let url = 'student_aid.php?';
            const params = [];
            
            if (status !== 'all') params.push(`status=${status}`);
            if (search) params.push(`search=${encodeURIComponent(search)}`);
            
            window.location.href = url + params.join('&');
        }

        function resetFilters() {
            window.location.href = 'student_aid.php';
        }

        // Auto-focus search input
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });
    </script>
</body>
</html>