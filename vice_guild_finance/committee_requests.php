<?php
session_start();
require_once '../config/database.php';
require_once '../config/financial_logic.php';

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

// Handle actions
$action = $_GET['action'] ?? '';
$request_id = $_GET['id'] ?? '';

// Function to record committee budget transaction
function recordCommitteeBudgetTransaction($request_id, $approved_amount, $user_id) {
    global $pdo;
    
    try {
        // Get request details
        $stmt = $pdo->prepare("
            SELECT cbr.*, cm.name as committee_name, u.full_name as requested_by_name
            FROM committee_budget_requests cbr
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            LEFT JOIN users u ON cbr.requested_by = u.id
            WHERE cbr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception("Request not found");
        }
        
        // Get the committee budget category ID
        $stmt = $pdo->prepare("SELECT id FROM budget_categories WHERE category_name = 'Committee Budget Requests' AND is_active = 1");
        $stmt->execute();
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            throw new Exception("Committee Budget Requests category not found");
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
        
        $description = "Committee Budget: " . $request['request_title'] . " - " . $request['committee_name'];
        
        $stmt->execute([
            $category['id'],
            $approved_amount,
            $description,
            $request['committee_name'],
            $request['requested_by'],
            $user_id
        ]);
        
        $transaction_id = $pdo->lastInsertId();
        error_log("Transaction recorded successfully. ID: " . $transaction_id . " for request: " . $request_id);
        
        return $transaction_id;
        
    } catch (PDOException $e) {
        error_log("Transaction recording error: " . $e->getMessage());
        throw new Exception("Failed to record transaction: " . $e->getMessage());
    }
}

// Approve as Finance
if ($action === 'approve_finance' && $request_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE committee_budget_requests 
            SET status = 'approved_by_finance', 
                finance_approval_date = NOW()
            WHERE id = ? AND status = 'submitted'
        ");
        $stmt->execute([$request_id]);
        
        $_SESSION['success_message'] = "Request approved successfully! Waiting for president approval.";
        header('Location: committee_requests.php');
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error approving request: " . $e->getMessage();
    }
}

// Update approved amount
if (isset($_POST['action']) && $_POST['action'] === 'update_amount' && isset($_POST['request_id'])) {
    try {
        $approved_amount = floatval($_POST['approved_amount']);
        $request_id = $_POST['request_id'];
        
        // Validate that approved amount is not more than requested amount
        $stmt = $pdo->prepare("SELECT requested_amount FROM committee_budget_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($approved_amount > $request_data['requested_amount']) {
            $_SESSION['error_message'] = "Approved amount cannot exceed requested amount!";
            header('Location: committee_requests.php?action=view&id=' . $request_id);
            exit();
        }
        
        $stmt = $pdo->prepare("
            UPDATE committee_budget_requests 
            SET approved_amount = ?, 
                finance_approval_notes = ?
            WHERE id = ? AND status IN ('submitted', 'approved_by_finance', 'approved_by_president')
        ");
        $stmt->execute([$approved_amount, $_POST['notes'] ?? '', $request_id]);
        
        $_SESSION['success_message'] = "Approved amount updated successfully!";
        header('Location: committee_requests.php?action=view&id=' . $request_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating amount: " . $e->getMessage();
    }
}

// Upload approval letter
if (isset($_POST['action']) && $_POST['action'] === 'upload_letter' && isset($_POST['request_id'])) {
    try {
        $request_id = $_POST['request_id'];
        
        // Handle file upload
        if (isset($_FILES['approval_letter']) && $_FILES['approval_letter']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/approval_letters/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Validate file type
            $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $file_extension = strtolower(pathinfo($_FILES['approval_letter']['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_extension, $allowed_types)) {
                $_SESSION['error_message'] = "Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX";
                header('Location: committee_requests.php?action=view&id=' . $request_id);
                exit();
            }
            
            $file_name = 'approval_letter_' . $request_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['approval_letter']['tmp_name'], $file_path)) {
                $stmt = $pdo->prepare("
                    UPDATE committee_budget_requests 
                    SET generated_letter_path = ?
                    WHERE id = ? AND status IN ('approved_by_finance', 'approved_by_president')
                ");
                $stmt->execute([$file_path, $request_id]);
                
                $_SESSION['success_message'] = "Approval letter uploaded successfully!";
            } else {
                $_SESSION['error_message'] = "Error uploading file.";
            }
        } else {
            $_SESSION['error_message'] = "Please select a valid file to upload.";
        }
        
        header('Location: committee_requests.php?action=view&id=' . $request_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error uploading letter: " . $e->getMessage();
    }
}

// Mark as funded - SINGLE CORRECTED VERSION
if ($action === 'mark_funded' && $request_id) {
    try {
        // Check if approval letter exists
        $stmt = $pdo->prepare("SELECT generated_letter_path, approved_amount, requested_amount FROM committee_budget_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (empty($request['generated_letter_path'])) {
            $_SESSION['error_message'] = "Cannot mark as funded without uploading approval letter!";
            header('Location: committee_requests.php?action=view&id=' . $request_id);
            exit();
        }
        
        // Determine the amount to use
        $amount_to_fund = $request['approved_amount'] ?: $request['requested_amount'];
        
        // Record the transaction
        $transaction_id = recordCommitteeBudgetTransaction($request_id, $amount_to_fund, $user_id);
        
        // Update the request status
        if (empty($request['approved_amount'])) {
            $stmt = $pdo->prepare("
                UPDATE committee_budget_requests 
                SET status = 'funded', approved_amount = ?
                WHERE id = ? AND status = 'approved_by_president' AND generated_letter_path IS NOT NULL
            ");
            $stmt->execute([$amount_to_fund, $request_id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE committee_budget_requests 
                SET status = 'funded'
                WHERE id = ? AND status = 'approved_by_president' AND generated_letter_path IS NOT NULL
            ");
            $stmt->execute([$request_id]);
        }
        
        $_SESSION['success_message'] = "Request marked as funded successfully! Transaction recorded (ID: $transaction_id).";
        header('Location: committee_requests.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error marking as funded: " . $e->getMessage();
        error_log("Mark as funded DB error: " . $e->getMessage());
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        error_log("Mark as funded exception: " . $e->getMessage());
    }
}

// Get all committee budget requests
try {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    $query = "
        SELECT cbr.*, 
               cm.name as committee_name,
               cm.role as committee_role,
               u.full_name as requested_by_name
        FROM committee_budget_requests cbr
        LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
        LEFT JOIN users u ON cbr.requested_by = u.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($search) {
        $query .= " AND (cbr.request_title LIKE ? OR cbr.purpose LIKE ? OR cm.name LIKE ?)";
        $search_term = "%$search%";
        $params = array_merge($params, [$search_term, $search_term, $search_term]);
    }
    
    if ($status_filter) {
        $query .= " AND cbr.status = ?";
        $params[] = $status_filter;
    }
    
    $query .= " ORDER BY cbr.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count requests by status
    $status_counts = [
        'submitted' => 0,
        'under_review' => 0,
        'approved_by_finance' => 0,
        'approved_by_president' => 0,
        'funded' => 0,
        'rejected' => 0
    ];
    
    foreach ($requests as $request) {
        if (isset($status_counts[$request['status']])) {
            $status_counts[$request['status']]++;
        }
    }
    
} catch (PDOException $e) {
    error_log("Committee requests error: " . $e->getMessage());
    $requests = [];
    $status_counts = [];
}

// Get specific request for view action
$current_request = null;
if ($action === 'view' && $request_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT cbr.*, 
                   cm.name as committee_name,
                   cm.role as committee_role,
                   cm.email as committee_email,
                   cm.phone as committee_phone,
                   u.full_name as requested_by_name
            FROM committee_budget_requests cbr
            LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
            LEFT JOIN users u ON cbr.requested_by = u.id
            WHERE cbr.id = ?
        ");
        $stmt->execute([$request_id]);
        $current_request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_request) {
            $_SESSION['error_message'] = "Request not found!";
            header('Location: committee_requests.php');
            exit();
        }
    } catch (PDOException $e) {
        error_log("Request details error: " . $e->getMessage());
        $current_request = null;
        $_SESSION['error_message'] = "Error loading request details: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Budget Requests - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Reuse the same CSS styles from dashboard.php */
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

        /* Header - Same as dashboard */
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

        /* Sidebar - Same as dashboard */
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        /* Filters */
        .filters-container {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.1);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn-primary {
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        /* Status Cards */
        .status-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .status-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
        }

        .status-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .status-card.submitted { border-left-color: var(--warning); }
        .status-card.under_review { border-left-color: var(--secondary-blue); }
        .status-card.approved_by_finance { border-left-color: var(--success); }
        .status-card.approved_by_president { border-left-color: var(--success); }
        .status-card.funded { border-left-color: var(--success); }
        .status-card.rejected { border-left-color: var(--danger); }

        .status-count {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .status-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Table */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

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

        .status-submitted { background: #fff3cd; color: var(--warning); }
        .status-under_review { background: #cce7ff; color: var(--primary-blue); }
        .status-approved_by_finance { background: #d4edda; color: var(--success); }
        .status-approved_by_president { background: #d4edda; color: var(--success); }
        .status-funded { background: #d1ecf1; color: #0c5460; }
        .status-rejected { background: #f8d7da; color: var(--danger); }
        .status-draft { background: #e2e3e5; color: var(--dark-gray); }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Request Details */
        .request-details {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .details-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--finance-light);
        }

        .details-header h2 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .request-meta {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .meta-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            font-weight: 600;
        }

        .meta-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .details-body {
            padding: 1.5rem;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .detail-section {
            margin-bottom: 1.5rem;
        }

        .detail-section h3 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: var(--text-dark);
            border-bottom: 1px solid var(--medium-gray);
            padding-bottom: 0.5rem;
        }

        .detail-content {
            line-height: 1.6;
        }

        .file-attachment {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }

        .file-attachment a {
            color: var(--finance-primary);
            text-decoration: none;
        }

        .file-attachment a:hover {
            text-decoration: underline;
        }

        /* Approval Actions */
        .approval-actions {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-top: 1.5rem;
        }

        .action-section {
            margin-bottom: 1.5rem;
        }

        .action-section:last-child {
            margin-bottom: 0;
        }

        .action-section h4 {
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .full-width {
            grid-column: 1 / -1;
        }

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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        /* ── Mobile Nav Overlay ── */
        .mobile-nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
            backdrop-filter: blur(2px);
        }
        .mobile-nav-overlay.active { display: block; }

        /* ── Hamburger Button ── */
        .hamburger-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .hamburger-btn:hover {
            background: var(--finance-primary);
            color: white;
        }

        /* ── Sidebar Drawer ── */
        .sidebar { transition: transform 0.3s ease; }

        /* ── Tablet: details grid collapses ── */
        @media (max-width: 1024px) {
            .details-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* ── Drawer threshold ── */
        @media (max-width: 900px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 260px;
                height: 100vh;
                z-index: 200;
                transform: translateX(-100%);
                padding-top: 1rem;
                box-shadow: var(--shadow-lg);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .hamburger-btn {
                display: flex;
            }

            .main-content {
                height: auto;
                min-height: calc(100vh - 80px);
            }
        }

        /* ── Mobile ── */
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

            /* Page header stacks */
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            /* Filters collapse to 1-col */
            .filters-form {
                grid-template-columns: 1fr;
            }

            /* Status cards 2-col */
            .status-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            /* Table scrolls horizontally */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table-container .table {
                white-space: nowrap;
            }

            /* Request details header meta wraps */
            .request-meta {
                gap: 1rem;
            }

            /* Approval actions form rows collapse */
            .form-row {
                grid-template-columns: 1fr;
            }

            /* Action button groups wrap */
            .action-buttons {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            /* Details body padding */
            .details-body {
                padding: 1rem;
            }

            /* Approval actions padding */
            .approval-actions {
                padding: 1rem;
            }
        }

        /* ── Small phones ── */
        @media (max-width: 480px) {
            .status-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .status-count {
                font-size: 1.5rem;
            }

            .request-meta {
                flex-direction: column;
                gap: 0.75rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .main-content {
                padding: 0.75rem;
            }

            .header {
                height: 68px;
            }

            .logos .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .details-header {
                padding: 1rem;
            }

            .details-header h2 {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger-btn" id="hamburgerBtn" title="Toggle Menu" aria-label="Open navigation menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Finance</h1>
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

    <!-- Mobile Nav Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay"></div>

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
                    <a href="committee_requests.php" class="active">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                        <?php if ($status_counts['submitted'] ?? 0 > 0): ?>
                            <span class="menu-badge"><?php echo $status_counts['submitted']; ?></span>
                        <?php endif; ?>
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

            <?php if ($action === 'view' && $current_request): ?>
                <!-- Request Details View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Committee Budget Request Details</h1>
                        <p>Review and manage budget request from committees</p>
                    </div>
                    <a href="committee_requests.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>

                <div class="request-details">
                    <div class="details-header">
                        <h2><?php echo htmlspecialchars($current_request['request_title']); ?></h2>
                        <div class="request-meta">
                            <div class="meta-item">
                                <span class="meta-label">Status</span>
                                <span class="status-badge status-<?php echo $current_request['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $current_request['status'])); ?>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Requested Amount</span>
                                <span class="meta-value">RWF <?php echo number_format($current_request['requested_amount'], 2); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Approved Amount</span>
                                <span class="meta-value">
                                    <?php echo $current_request['approved_amount'] ? 
                                        'RWF ' . number_format($current_request['approved_amount'], 2) : 'Not set'; ?>
                                </span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Request Date</span>
                                <span class="meta-value"><?php echo date('M j, Y', strtotime($current_request['request_date'])); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="details-body">
                        <div class="details-grid">
                            <div class="detail-section">
                                <h3>Committee Information</h3>
                                <div class="detail-content">
                                    <p><strong>Committee:</strong> <?php echo htmlspecialchars($current_request['committee_name']); ?></p>
                                    <p><strong>Role:</strong> <?php echo htmlspecialchars($current_request['committee_role']); ?></p>
                                    <?php if ($current_request['committee_email']): ?>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($current_request['committee_email']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($current_request['committee_phone']): ?>
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($current_request['committee_phone']); ?></p>
                                    <?php endif; ?>
                                    <p><strong>Requested By:</strong> <?php echo htmlspecialchars($current_request['requested_by_name']); ?></p>
                                </div>
                            </div>

                            <div class="detail-section">
                                <h3>Financial Information</h3>
                                <div class="detail-content">
                                    <p><strong>Requested Amount:</strong> RWF <?php echo number_format($current_request['requested_amount'], 2); ?></p>
                                    <p><strong>Approved Amount:</strong> 
                                        <?php echo $current_request['approved_amount'] ? 
                                            'RWF ' . number_format($current_request['approved_amount'], 2) : 'Pending approval'; ?>
                                    </p>
                                    <?php if ($current_request['finance_approval_date']): ?>
                                        <p><strong>Finance Approval:</strong> <?php echo date('M j, Y', strtotime($current_request['finance_approval_date'])); ?></p>
                                    <?php endif; ?>
                                    <?php if ($current_request['president_approval_date']): ?>
                                        <p><strong>President Approval:</strong> <?php echo date('M j, Y', strtotime($current_request['president_approval_date'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="detail-section full-width">
                                <h3>Purpose & Details</h3>
                                <div class="detail-content">
                                    <p><?php echo nl2br(htmlspecialchars($current_request['purpose'])); ?></p>
                                </div>
                            </div>
                            <?php if ($current_request['action_plan_file_path']): ?>
                            <div class="detail-section">
                                <h3>Action Plan Document</h3>
                                <div class="detail-content">
                                    <div class="file-attachment">
                                        <i class="fas fa-file-pdf"></i>
                                        <div style="flex: 1;">
                                            <a href="../<?php echo htmlspecialchars($current_request['action_plan_file_path']); ?>" target="_blank" class="file-link">
                                                View Action Plan Document
                                            </a>
                                            <div class="file-actions" style="margin-top: 0.5rem;">
                                                <a href="../<?php echo htmlspecialchars($current_request['action_plan_file_path']); ?>" 
                                                   class="btn btn-primary btn-sm" 
                                                   target="_blank"
                                                   style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                                    <i class="fas fa-eye"></i> Preview
                                                </a>
                                                <a href="../<?php echo htmlspecialchars($current_request['action_plan_file_path']); ?>" 
                                                   class="btn btn-secondary btn-sm" 
                                                   download
                                                   style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($current_request['generated_letter_path']): ?>
                            <div class="detail-section">
                                <h3>Approval Letter</h3>
                                <div class="detail-content">
                                    <div class="file-attachment">
                                        <i class="fas fa-file-signature"></i>
                                        <div style="flex: 1;">
                                            <a href="../<?php echo htmlspecialchars($current_request['generated_letter_path']); ?>" target="_blank" class="file-link">
                                                View Approval Letter
                                            </a>
                                            <div class="file-actions" style="margin-top: 0.5rem;">
                                                <a href="../<?php echo htmlspecialchars($current_request['generated_letter_path']); ?>" 
                                                   class="btn btn-primary btn-sm" 
                                                   target="_blank"
                                                   style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                                    <i class="fas fa-eye"></i> Preview
                                                </a>
                                                <a href="../<?php echo htmlspecialchars($current_request['generated_letter_path']); ?>" 
                                                   class="btn btn-secondary btn-sm" 
                                                   download
                                                   style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                <?php
                                                $file_extension = pathinfo($current_request['generated_letter_path'], PATHINFO_EXTENSION);
                                                if (in_array(strtolower($file_extension), ['jpg', 'jpeg', 'png', 'gif'])): 
                                                ?>
                                                <button type="button" 
                                                        class="btn btn-info btn-sm view-image-btn"
                                                        data-image="../<?php echo htmlspecialchars($current_request['generated_letter_path']); ?>"
                                                        style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                                    <i class="fas fa-expand"></i> Full Screen
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>




                            <?php if ($current_request['finance_approval_notes']): ?>
                            <div class="detail-section">
                                <h3>Finance Approval Notes</h3>
                                <div class="detail-content">
                                    <p><?php echo nl2br(htmlspecialchars($current_request['finance_approval_notes'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($current_request['president_approval_notes']): ?>
                            <div class="detail-section">
                                <h3>President Approval Notes</h3>
                                <div class="detail-content">
                                    <p><?php echo nl2br(htmlspecialchars($current_request['president_approval_notes'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Approval Actions -->
                        <div class="approval-actions">
                            <?php if ($current_request['status'] === 'submitted'): ?>
                                <div class="action-section">
                                    <h4>Finance Approval</h4>
                                    <p>This request is waiting for your approval. You can set the approved amount now or later.</p>
                                    
                                    <form method="POST" action="committee_requests.php" style="margin-bottom: 1rem;">
                                        <input type="hidden" name="action" value="update_amount">
                                        <input type="hidden" name="request_id" value="<?php echo $current_request['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="approved_amount">Approved Amount (RWF)</label>
                                                <input type="number" 
                                                       id="approved_amount" 
                                                       name="approved_amount" 
                                                       class="form-control" 
                                                       value="<?php echo $current_request['approved_amount'] ?: $current_request['requested_amount']; ?>"
                                                       min="0" 
                                                       max="<?php echo $current_request['requested_amount']; ?>"
                                                       step="0.01" 
                                                       required>
                                                <small>Max: RWF <?php echo number_format($current_request['requested_amount'], 2); ?></small>
                                            </div>
                                            <div class="form-group">
                                                <label for="notes">Finance Notes (Optional)</label>
                                                <textarea id="notes" 
                                                          name="notes" 
                                                          class="form-control" 
                                                          rows="3"
                                                          placeholder="Add any notes or conditions for this approval"><?php echo htmlspecialchars($current_request['finance_approval_notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Set Approved Amount
                                        </button>
                                    </form>
                                    
                                    <a href="committee_requests.php?action=approve_finance&id=<?php echo $current_request['id']; ?>" 
                                       class="btn btn-success" 
                                       onclick="return confirm('Are you sure you want to approve this request?')">
                                        <i class="fas fa-check"></i> Approve as Finance
                                    </a>
                                </div>
                                
                            <?php elseif ($current_request['status'] === 'approved_by_finance'): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> 
                                    This request has been approved by finance and is waiting for president approval.
                                </div>
                                
                                <div class="action-section">
                                    <h4>Update Approved Amount</h4>
                                    <form method="POST" action="committee_requests.php">
                                        <input type="hidden" name="action" value="update_amount">
                                        <input type="hidden" name="request_id" value="<?php echo $current_request['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="approved_amount">Approved Amount (RWF)</label>
                                                <input type="number" 
                                                       id="approved_amount" 
                                                       name="approved_amount" 
                                                       class="form-control" 
                                                       value="<?php echo $current_request['approved_amount'] ?: $current_request['requested_amount']; ?>"
                                                       min="0" 
                                                       max="<?php echo $current_request['requested_amount']; ?>"
                                                       step="0.01" 
                                                       required>
                                                <small>Max: RWF <?php echo number_format($current_request['requested_amount'], 2); ?></small>
                                            </div>
                                            <div class="form-group">
                                                <label for="notes">Finance Notes (Optional)</label>
                                                <textarea id="notes" 
                                                          name="notes" 
                                                          class="form-control" 
                                                          rows="3"
                                                          placeholder="Add any notes or conditions for this approval"><?php echo htmlspecialchars($current_request['finance_approval_notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Update Approved Amount
                                        </button>
                                    </form>
                                </div>
                                
                            <?php elseif ($current_request['status'] === 'approved_by_president'): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> 
                                    This request has been approved by both finance and president.
                                    <?php if (!$current_request['approved_amount']): ?>
                                        <br><strong>Note:</strong> No approved amount set. Will use requested amount when funded.
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!$current_request['generated_letter_path']): ?>
                                <div class="action-section">
                                    <h4>Upload Approval Letter</h4>
                                    <p>Upload the signed approval letter before marking as funded.</p>
                                    <form method="POST" action="committee_requests.php" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="upload_letter">
                                        <input type="hidden" name="request_id" value="<?php echo $current_request['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group full-width">
                                                <label for="approval_letter">Approval Letter (PDF/Image/DOC)</label>
                                                <input type="file" 
                                                       id="approval_letter" 
                                                       name="approval_letter" 
                                                       class="form-control" 
                                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                                       required>
                                                <small>Upload the signed approval letter (PDF, JPG, PNG, DOC, DOCX) - Max 10MB</small>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-upload"></i> Upload Approval Letter
                                        </button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <div class="action-section">
                                    <h4>Final Step: Mark as Funded</h4>
                                    <p>The approval letter has been uploaded. You can now mark this request as funded.</p>
                                    <div style="background: #e8f5e8; padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1rem;">
                                        <strong>Funding Details:</strong><br>
                                        Requested: RWF <?php echo number_format($current_request['requested_amount'], 2); ?><br>
                                        Approved: RWF <?php echo number_format($current_request['approved_amount'] ?: $current_request['requested_amount'], 2); ?><br>
                                        <?php if (!$current_request['approved_amount']): ?>
                                            <em>Approved amount not set - will use requested amount</em>
                                        <?php endif; ?>
                                    </div>
                                    <a href="committee_requests.php?action=mark_funded&id=<?php echo $current_request['id']; ?>" 
                                       class="btn btn-success"
                                       onclick="return confirm('Are you sure you want to mark this request as funded?\n\nAmount: RWF <?php echo number_format($current_request['approved_amount'] ?: $current_request['requested_amount'], 2); ?>\nThis action cannot be undone.')">
                                        <i class="fas fa-money-bill-wave"></i> Mark as Funded
                                    </a>
                                </div>
                                <?php endif; ?>
                                
                            <?php elseif ($current_request['status'] === 'funded'): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-double"></i> 
                                    This request has been completed and marked as funded.
                                    <br><strong>Final Amount:</strong> RWF <?php echo number_format($current_request['approved_amount'] ?: $current_request['requested_amount'], 2); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Main List View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Committee Budget Requests</h1>
                        <p>Manage and review budget requests from various committees</p>
                    </div>
                </div>

                <!-- Status Overview -->
                <div class="status-cards">
                    <div class="status-card submitted">
                        <div class="status-count"><?php echo $status_counts['submitted'] ?? 0; ?></div>
                        <div class="status-label">Submitted</div>
                    </div>
                    <div class="status-card under_review">
                        <div class="status-count"><?php echo $status_counts['under_review'] ?? 0; ?></div>
                        <div class="status-label">Under Review</div>
                    </div>
                    <div class="status-card approved_by_finance">
                        <div class="status-count"><?php echo $status_counts['approved_by_finance'] ?? 0; ?></div>
                        <div class="status-label">Finance Approved</div>
                    </div>
                    <div class="status-card approved_by_president">
                        <div class="status-count"><?php echo $status_counts['approved_by_president'] ?? 0; ?></div>
                        <div class="status-label">President Approved</div>
                    </div>
                    <div class="status-card funded">
                        <div class="status-count"><?php echo $status_counts['funded'] ?? 0; ?></div>
                        <div class="status-label">Funded</div>
                    </div>
                    <div class="status-card rejected">
                        <div class="status-count"><?php echo $status_counts['rejected'] ?? 0; ?></div>
                        <div class="status-label">Rejected</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-container">
                    <form method="GET" action="committee_requests.php" class="filters-form">
                        <div class="form-group">
                            <label for="search">Search Requests</label>
                            <input type="text" 
                                   id="search" 
                                   name="search" 
                                   class="form-control" 
                                   placeholder="Search by title, purpose, or committee..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="form-group">
                            <label for="status">Status Filter</label>
                            <select id="status" name="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="approved_by_finance" <?php echo $status_filter === 'approved_by_finance' ? 'selected' : ''; ?>>Finance Approved</option>
                                <option value="approved_by_president" <?php echo $status_filter === 'approved_by_president' ? 'selected' : ''; ?>>President Approved</option>
                                <option value="funded" <?php echo $status_filter === 'funded' ? 'selected' : ''; ?>>Funded</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="committee_requests.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

<!-- Requests Table -->
<div class="table-container">
    <!-- Debug information -->
    <?php if (empty($requests)): ?>
        <div style="padding: 1rem; background: #fff3cd; border-left: 4px solid #ffc107; margin: 1rem;">
            <h4>Debug Information</h4>
            <p><strong>Query:</strong> <?php echo htmlspecialchars($query ?? 'No query'); ?></p>
            <p><strong>Parameters:</strong> <?php echo implode(', ', $params ?? []); ?></p>
            <p><strong>Search:</strong> <?php echo htmlspecialchars($search); ?></p>
            <p><strong>Status Filter:</strong> <?php echo htmlspecialchars($status_filter); ?></p>
            <p><strong>Total records in table:</strong> <?php echo $test_result['count'] ?? 'Unknown'; ?></p>
        </div>
    <?php endif; ?>
    
    <table class="table">
        <thead>
            <tr>
                <th>Request Title</th>
                <th>Committee</th>
                <th>Requested Amount</th>
                <th>Approved Amount</th>
                <th>Status</th>
                <th>Request Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                        No committee budget requests found
                        <?php if ($search || $status_filter): ?>
                            <br>
                            <small>Try adjusting your search filters</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($request['request_title']); ?></strong>
                            <br>
                            <small style="color: var(--dark-gray);">
                                <?php echo substr(htmlspecialchars($request['purpose']), 0, 50); ?>...
                            </small>
                        </td>
                        <td><?php echo htmlspecialchars($request['committee_name'] ?? 'N/A'); ?></td>
                        <td class="amount">RWF <?php echo number_format($request['requested_amount'], 2); ?></td>
                        <td class="amount">
                            <?php echo $request['approved_amount'] ? 
                                'RWF ' . number_format($request['approved_amount'], 2) : '-'; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="committee_requests.php?action=view&id=<?php echo $request['id']; ?>" 
                                   class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <?php if ($request['status'] === 'submitted'): ?>
                                    <a href="committee_requests.php?action=approve_finance&id=<?php echo $request['id']; ?>" 
                                       class="btn btn-success btn-sm"
                                       onclick="return confirm('Approve this request?')">
                                        <i class="fas fa-check"></i> Approve
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // ── Mobile Nav (hamburger sidebar) ──
        (function() {
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const navSidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('mobileNavOverlay');

            function openNav() {
                navSidebar.classList.add('open');
                overlay.classList.add('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-times"></i>';
                document.body.style.overflow = 'hidden';
            }

            function closeNav() {
                navSidebar.classList.remove('open');
                overlay.classList.remove('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }

            hamburgerBtn.addEventListener('click', () => {
                navSidebar.classList.contains('open') ? closeNav() : openNav();
            });

            overlay.addEventListener('click', closeNav);

            window.addEventListener('resize', () => {
                if (window.innerWidth > 900) closeNav();
            });
        })();

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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

                // Image Viewer for approval letters
        document.addEventListener('DOMContentLoaded', function() {
            // Image full screen viewer
            const viewImageBtns = document.querySelectorAll('.view-image-btn');
            viewImageBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const imageUrl = this.getAttribute('data-image');
                    const modal = document.createElement('div');
                    modal.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0,0,0,0.9);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 10000;
                        cursor: zoom-out;
                    `;
                    
                    const img = document.createElement('img');
                    img.src = imageUrl;
                    img.style.cssText = `
                        max-width: 90%;
                        max-height: 90%;
                        object-fit: contain;
                    `;
                    
                    modal.appendChild(img);
                    document.body.appendChild(modal);
                    
                    modal.addEventListener('click', function() {
                        document.body.removeChild(modal);
                    });
                });
            });
            
            // File type icons
            const fileLinks = document.querySelectorAll('.file-link');
            fileLinks.forEach(link => {
                const href = link.getAttribute('href');
                if (href) {
                    const extension = href.split('.').pop().toLowerCase();
                    const icon = link.querySelector('i') || link.parentElement.querySelector('i');
                    if (icon) {
                        const iconMap = {
                            'pdf': 'file-pdf',
                            'doc': 'file-word',
                            'docx': 'file-word',
                            'jpg': 'file-image',
                            'jpeg': 'file-image',
                            'png': 'file-image',
                            'gif': 'file-image'
                        };
                        if (iconMap[extension]) {
                            icon.className = `fas fa-${iconMap[extension]}`;
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>