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
$current_month = date('Y-m');

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Get unread messages count
$unread_messages = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'process_communication_allowances':
                processCommunicationAllowances();
                break;
            case 'process_mission_allowance':
                processMissionAllowance();
                break;
            case 'upload_receipt':
                uploadReceipt();
                break;
            case 'mark_as_paid':
                markAsPaid();
                break;
        }
    }
}

function processCommunicationAllowances() {
    global $pdo, $user_id, $current_academic_year, $current_month;
    
    try {
        $pdo->beginTransaction();
        
        $committee_members = $_POST['committee_members'] ?? [];
        $amount = floatval($_POST['amount']);
        $month_year = $_POST['month_year'];
        $notes = $_POST['notes'] ?? '';
        
        if (empty($committee_members)) {
            $_SESSION['error'] = "Please select at least one committee member.";
            return;
        }
        
        foreach ($committee_members as $member_id) {
            $check_stmt = $pdo->prepare("
                SELECT id FROM committee_communication_allowances 
                WHERE committee_member_id = ? AND month_year = ? AND academic_year = ?
            ");
            $check_stmt->execute([$member_id, $month_year, $current_academic_year]);
            
            if ($check_stmt->fetch()) {
                continue;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO committee_communication_allowances 
                (committee_member_id, academic_year, month_year, amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$member_id, $current_academic_year, $month_year, $amount, $notes, $user_id]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Communication allowances processed successfully for " . count($committee_members) . " members.";
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error processing communication allowances: " . $e->getMessage();
    }
}

function processMissionAllowance() {
    global $pdo, $user_id, $current_academic_year;
    
    try {
        $committee_member_id = intval($_POST['committee_member_id']);
        $mission_purpose = $_POST['mission_purpose'];
        $destination = $_POST['destination'];
        $mission_date = $_POST['mission_date'];
        $amount = floatval($_POST['amount']);
        $transport_mode = $_POST['transport_mode'];
        $notes = $_POST['notes'] ?? '';
        
        $stmt = $pdo->prepare("
            INSERT INTO mission_allowances 
            (committee_member_id, mission_purpose, destination, mission_date, amount, 
             transport_mode, academic_year, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $committee_member_id, $mission_purpose, $destination, $mission_date, 
            $amount, $transport_mode, $current_academic_year, $notes, $user_id
        ]);
        
        $_SESSION['success'] = "Mission allowance created successfully.";
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error creating mission allowance: " . $e->getMessage();
    }
}

function uploadReceipt() {
    global $pdo, $user_id;
    
    try {
        $allowance_type = $_POST['allowance_type'];
        $allowance_id = intval($_POST['allowance_id']);
        $recipient_id = intval($_POST['recipient_id']);
        $recipient_name = $_POST['recipient_name'];
        $amount = floatval($_POST['amount']);
        $purpose = $_POST['purpose'];
        $receipt_date = $_POST['receipt_date'];
        
        if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = "../assets/uploads/allowance_receipts/";
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $timestamp = time();
            $original_name = basename($_FILES['receipt_file']['name']);
            $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $file_name = "receipt_" . $allowance_type . "_" . $allowance_id . "_" . $timestamp . "." . $file_extension;
            $file_path = $upload_dir . $file_name;
            $file_size = $_FILES['receipt_file']['size'];
            $file_type = $_FILES['receipt_file']['type'];
            
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Only PDF, JPG, JPEG, PNG, and GIF files are allowed.");
            }
            
            $max_file_size = 5 * 1024 * 1024;
            if ($file_size > $max_file_size) {
                throw new Exception("File size must be less than 5MB.");
            }
            
            if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $file_path)) {
                $relative_path = "assets/uploads/allowance_receipts/" . $file_name;
                
                $check_stmt = $pdo->prepare("
                    SELECT id FROM allowance_receipts 
                    WHERE allowance_type = ? AND allowance_id = ?
                ");
                $check_stmt->execute([$allowance_type, $allowance_id]);
                $existing_receipt = $check_stmt->fetch();
                
                if ($existing_receipt) {
                    $stmt = $pdo->prepare("
                        UPDATE allowance_receipts 
                        SET recipient_id = ?, recipient_name = ?, amount = ?, purpose = ?,
                            receipt_date = ?, receipt_file_path = ?, file_name = ?, 
                            file_size = ?, file_type = ?, uploaded_by = ?, created_at = NOW()
                        WHERE allowance_type = ? AND allowance_id = ?
                    ");
                    $stmt->execute([
                        $recipient_id, $recipient_name, $amount, $purpose,
                        $receipt_date, $relative_path, $file_name, $file_size, $file_type, $user_id,
                        $allowance_type, $allowance_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO allowance_receipts 
                        (allowance_type, allowance_id, recipient_id, recipient_name, amount, 
                         purpose, receipt_date, receipt_file_path, file_name, file_size, file_type, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $allowance_type, $allowance_id, $recipient_id, $recipient_name, $amount,
                        $purpose, $receipt_date, $relative_path, $file_name, $file_size, $file_type, $user_id
                    ]);
                }
                
                if ($allowance_type === 'communication') {
                    $update_stmt = $pdo->prepare("UPDATE committee_communication_allowances SET receipt_path = ? WHERE id = ?");
                } else {
                    $update_stmt = $pdo->prepare("UPDATE mission_allowances SET receipt_path = ? WHERE id = ?");
                }
                $update_stmt->execute([$relative_path, $allowance_id]);
                
                $_SESSION['success'] = "Receipt uploaded successfully.";
                
            } else {
                throw new Exception("Failed to upload receipt file. Please try again.");
            }
        } else {
            $error_message = "Please select a valid receipt file.";
            if (isset($_FILES['receipt_file']['error']) && $_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
                switch ($_FILES['receipt_file']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = "File is too large. Maximum size is 5MB."; break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = "File was only partially uploaded."; break;
                    case UPLOAD_ERR_NO_FILE:
                        $error_message = "No file was selected."; break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error_message = "Missing temporary folder."; break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $error_message = "Failed to write file to disk."; break;
                    case UPLOAD_ERR_EXTENSION:
                        $error_message = "File upload stopped by extension."; break;
                }
            }
            throw new Exception($error_message);
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error uploading receipt: " . $e->getMessage();
    }
}

function markAsPaid() {
    global $pdo, $user_id, $current_academic_year;
    
    try {
        $pdo->beginTransaction();
        
        $allowance_type = $_POST['allowance_type'];
        $allowance_id = intval($_POST['allowance_id']);
        $category_id = intval($_POST['category_id']);
        
        if (empty($category_id)) {
            throw new Exception("Please select a budget category for this allowance.");
        }

        if ($allowance_type === 'communication') {
            $stmt = $pdo->prepare("
                SELECT cca.*, cm.name as member_name, cm.role 
                FROM committee_communication_allowances cca
                LEFT JOIN committee_members cm ON cca.committee_member_id = cm.id
                WHERE cca.id = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT ma.*, cm.name as member_name, cm.role 
                FROM mission_allowances ma
                LEFT JOIN committee_members cm ON ma.committee_member_id = cm.id
                WHERE ma.id = ?
            ");
        }
        $stmt->execute([$allowance_id]);
        $allowance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$allowance) {
            throw new Exception("Allowance not found.");
        }
        
        $random_suffix = substr(str_shuffle("0123456789ABCDEF"), 0, 4);
        $reference_number = 'ALLW-' . date('Ymd') . '-' . strtoupper(substr($allowance_type, 0, 3)) . '-' . $allowance_id . '-' . $random_suffix;
        
        $check_ref_stmt = $pdo->prepare("SELECT id FROM financial_transactions WHERE reference_number = ?");
        $check_ref_stmt->execute([$reference_number]);
        if ($check_ref_stmt->fetch()) {
            $reference_number = 'ALLW-' . date('Ymd-His') . '-' . strtoupper(substr($allowance_type, 0, 3)) . '-' . $allowance_id;
        }
        
        $transaction_stmt = $pdo->prepare("
            INSERT INTO financial_transactions 
            (transaction_type, category_id, amount, description, transaction_date, 
             reference_number, payee_payer, payment_method, status, requested_by, 
             approved_by_finance, approved_by_president, approved_at)
            VALUES ('expense', ?, ?, ?, CURRENT_DATE, ?, ?, 'cash', 'completed', ?, ?, ?, NOW())
        ");
        
        $description = $allowance_type === 'communication' 
            ? "Communication allowance for {$allowance['member_name']} ({$allowance['role']}) - {$allowance['month_year']}"
            : "Mission allowance for {$allowance['member_name']} - {$allowance['destination']}";
        
        $transaction_stmt->execute([
            $category_id, $allowance['amount'], $description,
            $reference_number, $allowance['member_name'],
            $user_id, $user_id, $user_id
        ]);
        
        $transaction_id = $pdo->lastInsertId();
        
        if ($allowance_type === 'communication') {
            $update_stmt = $pdo->prepare("
                UPDATE committee_communication_allowances 
                SET status = 'paid', payment_date = CURRENT_DATE, paid_by = ?, paid_at = NOW(),
                    category_id = ?, transaction_id = ?
                WHERE id = ?
            ");
        } else {
            $update_stmt = $pdo->prepare("
                UPDATE mission_allowances 
                SET status = 'paid', payment_date = CURRENT_DATE, paid_by = ?, paid_at = NOW(),
                    category_id = ?, transaction_id = ?
                WHERE id = ?
            ");
        }
        $update_stmt->execute([$user_id, $category_id, $transaction_id, $allowance_id]);
        
        $current_month = date('Y-m');
        $budget_update_stmt = $pdo->prepare("
            UPDATE monthly_budgets 
            SET actual_amount = actual_amount + ?
            WHERE category_id = ? AND month_year = ? AND academic_year = ?
        ");
        $budget_update_stmt->execute([$allowance['amount'], $category_id, $current_month, $current_academic_year]);
        
        $pdo->commit();
        $_SESSION['success'] = "Allowance marked as paid and transaction recorded successfully.";
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error updating allowance status: " . $e->getMessage();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
}

// Get budget categories for allowances
// Get budget categories for allowances
try {
    $stmt = $pdo->query("
        SELECT id, category_name 
        FROM budget_categories 
        WHERE category_type = 'expense' AND is_active = true
        ORDER BY category_name
    ");
    $allowance_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If still no categories, insert default ones
    if (empty($allowance_categories)) {
        $insertStmt = $pdo->prepare("
            INSERT INTO budget_categories (category_name, category_type, description, is_active) 
            VALUES (?, 'expense', ?, true)
        ");
        
        $defaultCategories = [
            'Committee Communication Allowance' => 'Monthly communication allowance for committee members',
            'Mission Allowance' => 'Allowance for official missions and travel',
            'Committee Transport Allowance' => 'Transport allowance for committee members',
            'Committee Meal Allowance' => 'Meal allowance for committee members',
            'Staff Communication Allowance' => 'Monthly communication allowance for staff',
            'Training Allowance' => 'Allowance for training and workshops',
            'Event Allowance' => 'Allowance for event-related expenses'
        ];
        
        foreach ($defaultCategories as $name => $desc) {
            $insertStmt->execute([$name, $desc]);
        }
        
        // Fetch again
        $stmt = $pdo->query("
            SELECT id, category_name 
            FROM budget_categories 
            WHERE category_type = 'expense' AND is_active = true
            ORDER BY category_name
        ");
        $allowance_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $allowance_categories = [];
    error_log("Allowance categories error: " . $e->getMessage());
}

// Get committee members
try {
    $stmt = $pdo->query("
        SELECT cm.*, u.full_name, u.email, d.name as department_name
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN departments d ON cm.department_id = d.id
        WHERE cm.status = 'active'
        ORDER BY cm.role_order ASC
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
    error_log("Committee members error: " . $e->getMessage());
}

// Get communication allowances for current academic year
try {
    $stmt = $pdo->prepare("
        SELECT cca.*, cm.name as member_name, cm.role, u.full_name as paid_by_name,
               ar.receipt_file_path, ar.file_name
        FROM committee_communication_allowances cca
        LEFT JOIN committee_members cm ON cca.committee_member_id = cm.id
        LEFT JOIN users u ON cca.paid_by = u.id
        LEFT JOIN allowance_receipts ar ON (ar.allowance_type = 'communication' AND ar.allowance_id = cca.id)
        WHERE cca.academic_year = ?
        ORDER BY cca.month_year DESC, cca.created_at DESC
    ");
    $stmt->execute([$current_academic_year]);
    $communication_allowances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $communication_allowances = [];
    error_log("Communication allowances error: " . $e->getMessage());
}

// Get mission allowances for current academic year
try {
    $stmt = $pdo->prepare("
        SELECT ma.*, cm.name as member_name, cm.role, u.full_name as paid_by_name,
               ar.receipt_file_path, ar.file_name
        FROM mission_allowances ma
        LEFT JOIN committee_members cm ON ma.committee_member_id = cm.id
        LEFT JOIN users u ON ma.paid_by = u.id
        LEFT JOIN allowance_receipts ar ON (ar.allowance_type = 'mission' AND ar.allowance_id = ma.id)
        WHERE ma.academic_year = ?
        ORDER BY ma.mission_date DESC, ma.created_at DESC
    ");
    $stmt->execute([$current_academic_year]);
    $mission_allowances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mission_allowances = [];
    error_log("Mission allowances error: " . $e->getMessage());
}

// Get allowance statistics
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count
        FROM committee_communication_allowances 
        WHERE academic_year = ? AND month_year = ? AND status = 'paid'
    ");
    $stmt->execute([$current_academic_year, $current_month]);
    $comm_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count
        FROM mission_allowances 
        WHERE academic_year = ? 
        AND EXTRACT(MONTH FROM mission_date) = EXTRACT(MONTH FROM CURRENT_DATE)
        AND EXTRACT(YEAR FROM mission_date) = EXTRACT(YEAR FROM CURRENT_DATE)
        AND status = 'paid'
    ");
    $stmt->execute([$current_academic_year]);
    $mission_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_count
        FROM (
            SELECT id FROM committee_communication_allowances 
            WHERE academic_year = ? AND status = 'pending'
            UNION ALL
            SELECT id FROM mission_allowances 
            WHERE academic_year = ? AND status = 'pending'
        ) as pending_allowances
    ");
    $stmt->execute([$current_academic_year, $current_academic_year]);
    $pending_stats = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $comm_stats = $mission_stats = $pending_stats = ['total_amount' => 0, 'total_count' => 0, 'pending_count' => 0];
    error_log("Allowance statistics error: " . $e->getMessage());
}

// Badge counts for sidebar (lightweight)
$pending_approvals = 0;
$pending_budget_requests = 0;
$pending_aid_requests = 0;
try {
    $r = $pdo->query("SELECT COUNT(*) as c FROM financial_transactions WHERE status = 'approved_by_finance'");
    $pending_approvals = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;

    $r = $pdo->query("SELECT COUNT(*) as c FROM committee_budget_requests WHERE status IN ('submitted','under_review')");
    $pending_budget_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;

    $r = $pdo->query("SELECT COUNT(*) as c FROM student_financial_aid WHERE status IN ('submitted','under_review')");
    $pending_aid_requests = $r->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;
} catch (PDOException $e) { /* silent */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Allowances Management - Isonga RPSU</title>
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
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 2px 8px rgba(0,0,0,0.12);
            --shadow-lg: 0 4px 16px rgba(0,0,0,0.15);
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

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* ── Header ── */
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

        .logo { height: 40px; width: auto; }

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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-details { text-align: right; }
        .user-name { font-weight: 600; font-size: 0.9rem; }
        .user-role { font-size: 0.75rem; color: var(--dark-gray); }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            text-decoration: none;
            font-size: 0.95rem;
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
        }

        .logout-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }

        /* ── Dashboard Container ── */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* ── Sidebar ── */
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

        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge { display: none; }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i { margin: 0; font-size: 1.25rem; }

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

        .sidebar-menu { list-style: none; }

        .menu-item { margin-bottom: 0.25rem; }

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

        .menu-item i { width: 20px; }

        .menu-badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
        }

        /* ── Main Content ── */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        /* ── Page Header ── */
        .dashboard-header { margin-bottom: 1.5rem; }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p { color: var(--dark-gray); font-size: 0.9rem; }

        /* ── Stats Grid ── */
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
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger  { border-left-color: var(--danger); }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            background: var(--finance-light);
            color: var(--finance-primary);
        }

        .stat-card.success .stat-icon { background: #d4edda; color: var(--success); }
        .stat-card.warning .stat-icon { background: #fff3cd; color: var(--warning); }
        .stat-card.danger  .stat-icon { background: #f8d7da; color: var(--danger); }

        .stat-content { flex: 1; }

        .stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label { color: var(--dark-gray); font-size: 0.75rem; font-weight: 500; }

        /* ── Tabs ── */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 0.4rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            gap: 0.25rem;
        }

        .tab {
            flex: 1;
            padding: 0.65rem 1rem;
            text-align: center;
            background: none;
            border: none;
            border-radius: calc(var(--border-radius) - 2px);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--dark-gray);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .tab.active { background: var(--finance-primary); color: white; }
        .tab:hover:not(.active) { background: var(--finance-light); color: var(--finance-primary); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ── Cards ── */
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

        .card-header h3 { font-size: 1rem; font-weight: 600; color: var(--text-dark); }

        .card-body { padding: 1.25rem; }

        /* ── Forms ── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group.full-width { grid-column: 1 / -1; }

        label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-dark); font-size: 0.85rem; }

        input, select, textarea {
            width: 100%;
            padding: 0.65rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
            font-family: inherit;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25,118,210,0.1);
        }

        input[readonly], textarea[readonly] {
            background: var(--light-gray);
            cursor: not-allowed;
        }

        /* ── Buttons ── */
        .btn {
            padding: 0.65rem 1.25rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-family: inherit;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary { background: var(--finance-primary); color: white; }
        .btn-primary:hover { background: var(--finance-accent); }

        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: var(--text-dark); }
        .btn-danger  { background: var(--danger); color: white; }

        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.75rem; }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover { background: var(--light-gray); }

        /* ── Tables ── */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            white-space: nowrap;
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
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .table tbody tr:hover { background: var(--finance-light); }

        .amount {
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        /* ── Status Badges ── */
        .status-badge {
            padding: 0.2rem 0.55rem;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-pending   { background: #fff3cd; color: #856404; }
        .status-paid      { background: #d4edda; color: var(--success); }
        .status-approved  { background: #cce5ff; color: #004085; }
        .status-cancelled { background: #f8d7da; color: var(--danger); }
        .status-completed { background: #d4edda; color: var(--success); }

        /* ── Action Buttons in Table ── */
        .action-buttons { display: flex; gap: 0.4rem; align-items: center; }

        /* ── Checkbox Grid ── */
        .select-all {
            background: var(--finance-light);
            padding: 0.65rem 0.75rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
            max-height: 280px;
            overflow-y: auto;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.5rem;
            border-radius: 6px;
            transition: var(--transition);
            font-size: 0.82rem;
        }

        .checkbox-item:hover { background: var(--light-gray); }

        .checkbox-item input[type="checkbox"] { width: auto; flex-shrink: 0; }

        /* ── Alerts ── */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.85rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-error   { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }

        /* ── Empty State ── */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--dark-gray);
        }

        .empty-state i { font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.4; display: block; }
        .empty-state p { font-size: 0.85rem; }

        /* ── Modals (Payment & Receipt) ── */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal-overlay.active { display: flex; }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            max-width: 500px;
            width: 92%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .modal-header h3 { font-size: 1.05rem; font-weight: 600; }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            color: var(--dark-gray);
            line-height: 1;
            transition: var(--transition);
        }

        .close-modal:hover { color: var(--danger); }

        /* ── Document Viewer Modal ── */
        .document-viewer-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .document-viewer-modal.active { display: flex; }

        .document-viewer-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 90%; height: 90%;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-lg);
        }

        .viewer-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--finance-light);
        }

        .viewer-actions { display: flex; gap: 0.5rem; }

        .viewer-body {
            flex: 1;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #documentFrame { width: 100%; height: 100%; border: none; }

        .image-viewer { max-width: 100%; max-height: 100%; object-fit: contain; }

        .unsupported-viewer { padding: 2rem; text-align: center; color: var(--dark-gray); }

        /* ── Mobile overlay ── */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(2px);
            z-index: 98;
        }

        .overlay.active { display: block; }

        /* ── Responsive ── */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open { transform: translateX(0); }

            .sidebar-toggle { display: none; }

            .main-content { margin-left: 0 !important; }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover { background: var(--finance-primary); color: white; }

            #sidebarToggleBtn { display: none; }
        }

        @media (max-width: 768px) {
            .nav-container { padding: 0 1rem; gap: 0.5rem; }
            .brand-text h1 { font-size: 1rem; }
            .user-details { display: none; }
            .main-content { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-grid { grid-template-columns: 1fr; }
            .tabs { flex-direction: column; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .main-content { padding: 0.75rem; }
            .logo { height: 32px; }
            .brand-text h1 { font-size: 0.9rem; }
            .stat-card { padding: 0.75rem; }
            .stat-number { font-size: 1.1rem; }
        }
    </style>
</head>
<body>
    <!-- Mobile overlay -->
    <div class="overlay" id="mobileOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Finance</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    
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
                        <?php if ($pending_approvals > 0): ?>
                            <span class="menu-badge"><?php echo $pending_approvals; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                        <?php if ($pending_budget_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_budget_requests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                        <?php if ($pending_aid_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_aid_requests; ?></span>
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
                    <a href="allowances.php" class="active">
                        <i class="fas fa-money-check"></i>
                        <span>Allowances</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="accounts.php">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Bank Accounts</span>
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
           

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-comments"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($comm_stats['total_amount'], 0); ?></div>
                        <div class="stat-label">Communication Allowances (This Month)</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-road"></i></div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($mission_stats['total_amount'], 0); ?></div>
                        <div class="stat-label">Mission Allowances (This Month)</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_stats['pending_count']; ?></div>
                        <div class="stat-label">Pending Allowances</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($committee_members); ?></div>
                        <div class="stat-label">Active Committee Members</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs" role="tablist">
                <button class="tab active" onclick="switchTab('communication', this)" role="tab">
                    <i class="fas fa-comments"></i> Communication Allowances
                </button>
                <button class="tab" onclick="switchTab('mission', this)" role="tab">
                    <i class="fas fa-road"></i> Mission Allowances
                </button>
                <button class="tab" onclick="switchTab('records', this)" role="tab">
                    <i class="fas fa-history"></i> Allowance Records
                </button>
            </div>

            <!-- ═══ Communication Allowances Tab ═══ -->
            <div id="communication-tab" class="tab-content active">
                <!-- Process Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-paper-plane" style="color:var(--finance-primary);margin-right:.5rem;"></i>Process Communication Allowances</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="communicationForm">
                            <input type="hidden" name="action" value="process_communication_allowances">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="month_year">Month *</label>
                                    <input type="month" id="month_year" name="month_year" value="<?php echo $current_month; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="amount">Amount per Member (RWF) *</label>
                                    <input type="number" id="amount" name="amount" step="0.01" min="0" value="3000" required>
                                </div>
                                <div class="form-group full-width">
                                    <label>Select Committee Members *</label>
                                    <div class="select-all">
                                        <input type="checkbox" id="selectAllMembers" onchange="toggleAllMembers()">
                                        <label for="selectAllMembers" style="margin:0;font-weight:600;cursor:pointer;">Select All Committee Members</label>
                                    </div>
                                    <div class="checkbox-grid" id="membersGrid">
                                        <?php if (empty($committee_members)): ?>
                                            <p style="color:var(--dark-gray);font-size:0.82rem;">No active committee members found.</p>
                                        <?php else: ?>
                                            <?php foreach ($committee_members as $member): ?>
                                                <div class="checkbox-item">
                                                    <input type="checkbox" name="committee_members[]"
                                                           value="<?php echo $member['id']; ?>"
                                                           id="member_<?php echo $member['id']; ?>">
                                                    <label for="member_<?php echo $member['id']; ?>" style="margin:0;cursor:pointer;">
                                                        <?php echo htmlspecialchars($member['name'] ?? $member['full_name'] ?? ''); ?>
                                                        <small style="color:var(--dark-gray);">(<?php echo htmlspecialchars($member['role']); ?>)</small>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-group full-width">
                                    <label for="notes">Notes</label>
                                    <textarea id="notes" name="notes" rows="3" placeholder="Optional notes about this allowance batch..."></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Process Allowances
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Communication Allowances History -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history" style="color:var(--finance-primary);margin-right:.5rem;"></i>Communication Allowances History</h3>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Role</th>
                                        <th>Month</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Payment Date</th>
                                        <th>Receipt</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($communication_allowances)): ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <i class="fas fa-inbox"></i>
                                                    <p>No communication allowances recorded yet.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($communication_allowances as $allowance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($allowance['member_name']); ?></td>
                                                <td><?php echo htmlspecialchars($allowance['role']); ?></td>
                                                <td><?php echo date('F Y', strtotime($allowance['month_year'] . '-01')); ?></td>
                                                <td class="amount">RWF <?php echo number_format($allowance['amount'], 0); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $allowance['status']; ?>">
                                                        <?php echo ucfirst($allowance['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $allowance['payment_date'] ? date('M j, Y', strtotime($allowance['payment_date'])) : '—'; ?></td>
                                                <td>
                                                    <?php if (!empty($allowance['receipt_file_path'])): ?>
                                                        <?php
                                                        $ext = pathinfo($allowance['receipt_file_path'], PATHINFO_EXTENSION);
                                                        $fname = !empty($allowance['file_name']) ? $allowance['file_name']
                                                               : "Receipt_" . $allowance['member_name'] . "_" . date('F_Y', strtotime($allowance['month_year'] . '-01')) . "." . $ext;
                                                        ?>
                                                        <button class="btn btn-sm btn-success"
                                                                onclick="openDocumentViewer('<?php echo $allowance['receipt_file_path']; ?>', '<?php echo htmlspecialchars($fname); ?>')">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-warning"
                                                                onclick="openReceiptModal('communication', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>, 'Communication Allowance for <?php echo date('F Y', strtotime($allowance['month_year'] . '-01')); ?>')">
                                                            <i class="fas fa-upload"></i> Upload
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="action-buttons">
                                                    <?php if ($allowance['status'] === 'pending'): ?>
                                                        <button class="btn btn-sm btn-success"
                                                                onclick="openPaymentModal('communication', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>)">
                                                            <i class="fas fa-check"></i> Mark Paid
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color:var(--dark-gray);font-size:0.75rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Mission Allowances Tab ═══ -->
            <div id="mission-tab" class="tab-content">
                <!-- Create Mission Allowance Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-plus-circle" style="color:var(--finance-primary);margin-right:.5rem;"></i>Create Mission Allowance</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="missionForm">
                            <input type="hidden" name="action" value="process_mission_allowance">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="committee_member_id">Committee Member *</label>
                                    <select id="committee_member_id" name="committee_member_id" required>
                                        <option value="">Select Committee Member</option>
                                        <?php foreach ($committee_members as $member): ?>
                                            <option value="<?php echo $member['id']; ?>">
                                                <?php echo htmlspecialchars($member['name'] ?? $member['full_name'] ?? ''); ?> (<?php echo htmlspecialchars($member['role']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="mission_date">Mission Date *</label>
                                    <input type="date" id="mission_date" name="mission_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="amount_mission">Amount (RWF) *</label>
                                    <input type="number" id="amount_mission" name="amount" step="0.01" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label for="transport_mode">Transport Mode</label>
                                    <select id="transport_mode" name="transport_mode">
                                        <option value="public">Public Transport</option>
                                        <option value="private">Private Vehicle</option>
                                        <option value="college_vehicle">College Vehicle</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label for="destination">Destination *</label>
                                    <input type="text" id="destination" name="destination" placeholder="Where is the mission taking place?" required>
                                </div>
                                <div class="form-group full-width">
                                    <label for="mission_purpose">Mission Purpose *</label>
                                    <textarea id="mission_purpose" name="mission_purpose" rows="3" placeholder="Describe the purpose of this mission..." required></textarea>
                                </div>
                                <div class="form-group full-width">
                                    <label for="notes_mission">Additional Notes</label>
                                    <textarea id="notes_mission" name="notes" rows="2" placeholder="Optional additional notes..."></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Create Mission Allowance
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Mission Allowances History -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history" style="color:var(--finance-primary);margin-right:.5rem;"></i>Mission Allowances History</h3>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Destination</th>
                                        <th>Purpose</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Receipt</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($mission_allowances)): ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <i class="fas fa-inbox"></i>
                                                    <p>No mission allowances recorded yet.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($mission_allowances as $allowance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($allowance['member_name']); ?></td>
                                                <td><?php echo htmlspecialchars($allowance['destination']); ?></td>
                                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                    title="<?php echo htmlspecialchars($allowance['mission_purpose']); ?>">
                                                    <?php echo htmlspecialchars($allowance['mission_purpose']); ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($allowance['mission_date'])); ?></td>
                                                <td class="amount">RWF <?php echo number_format($allowance['amount'], 0); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $allowance['status']; ?>">
                                                        <?php echo ucfirst($allowance['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $receipt_path = $allowance['receipt_file_path'] ?? $allowance['receipt_path'] ?? '';
                                                    if ($receipt_path): ?>
                                                        <?php
                                                        $ext = pathinfo($receipt_path, PATHINFO_EXTENSION);
                                                        $fname = !empty($allowance['file_name']) ? $allowance['file_name']
                                                               : "Mission_Receipt_" . $allowance['member_name'] . "_" . date('M_j_Y', strtotime($allowance['mission_date'])) . "." . $ext;
                                                        ?>
                                                        <button class="btn btn-sm btn-success"
                                                                onclick="openDocumentViewer('<?php echo $receipt_path; ?>', '<?php echo htmlspecialchars($fname); ?>')">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-warning"
                                                                onclick="openReceiptModal('mission', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>, 'Mission to <?php echo htmlspecialchars($allowance['destination']); ?> on <?php echo date('M j, Y', strtotime($allowance['mission_date'])); ?>')">
                                                            <i class="fas fa-upload"></i> Upload
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="action-buttons">
                                                    <?php if ($allowance['status'] === 'pending'): ?>
                                                        <button class="btn btn-sm btn-success"
                                                                onclick="openPaymentModal('mission', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>)">
                                                            <i class="fas fa-check"></i> Mark Paid
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color:var(--dark-gray);font-size:0.75rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Allowance Records Tab ═══ -->
            <div id="records-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list-alt" style="color:var(--finance-primary);margin-right:.5rem;"></i>All Allowance Records — <?php echo $current_academic_year; ?></h3>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Member</th>
                                        <th>Details</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Paid By</th>
                                        <th>Payment Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $all_records = [];

                                    foreach ($communication_allowances as $a) {
                                        $all_records[] = [
                                            'type'         => 'communication',
                                            'label'        => 'Communication',
                                            'member_name'  => $a['member_name'],
                                            'details'      => date('F Y', strtotime($a['month_year'] . '-01')),
                                            'amount'       => $a['amount'],
                                            'status'       => $a['status'],
                                            'paid_by_name' => $a['paid_by_name'] ?? '—',
                                            'payment_date' => $a['payment_date'],
                                            'sort_date'    => $a['payment_date'] ?? $a['created_at'],
                                        ];
                                    }

                                    foreach ($mission_allowances as $a) {
                                        $all_records[] = [
                                            'type'         => 'mission',
                                            'label'        => 'Mission',
                                            'member_name'  => $a['member_name'],
                                            'details'      => htmlspecialchars($a['destination']) . ' · ' . date('M j, Y', strtotime($a['mission_date'])),
                                            'amount'       => $a['amount'],
                                            'status'       => $a['status'],
                                            'paid_by_name' => $a['paid_by_name'] ?? '—',
                                            'payment_date' => $a['payment_date'],
                                            'sort_date'    => $a['payment_date'] ?? $a['created_at'],
                                        ];
                                    }

                                    usort($all_records, fn($a, $b) => strcmp($b['sort_date'] ?? '', $a['sort_date'] ?? ''));

                                    if (empty($all_records)):
                                    ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fas fa-inbox"></i>
                                                    <p>No allowance records found for this academic year.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_records as $rec): ?>
                                            <tr>
                                                <td>
                                                    <span style="
                                                        background: <?php echo $rec['type'] === 'communication' ? 'var(--finance-light)' : '#e8f5e9'; ?>;
                                                        color: <?php echo $rec['type'] === 'communication' ? 'var(--finance-primary)' : 'var(--success)'; ?>;
                                                        padding: 0.2rem 0.55rem;
                                                        border-radius: 20px;
                                                        font-size: 0.68rem;
                                                        font-weight: 600;">
                                                        <i class="fas <?php echo $rec['type'] === 'communication' ? 'fa-comments' : 'fa-road'; ?>"></i>
                                                        <?php echo $rec['label']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($rec['member_name']); ?></td>
                                                <td style="font-size:0.78rem;color:var(--dark-gray);"><?php echo $rec['details']; ?></td>
                                                <td class="amount">RWF <?php echo number_format($rec['amount'], 0); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $rec['status']; ?>">
                                                        <?php echo ucfirst($rec['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($rec['paid_by_name'] ?? '—'); ?></td>
                                                <td><?php echo $rec['payment_date'] ? date('M j, Y', strtotime($rec['payment_date'])) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- ═══ Payment Modal ═══ -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle" style="color:var(--success);margin-right:.4rem;"></i>Mark Allowance as Paid</h3>
                <button class="close-modal" onclick="closePaymentModal()">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="mark_as_paid">
                <input type="hidden" name="allowance_type" id="payment_allowance_type">
                <input type="hidden" name="allowance_id" id="payment_allowance_id">

                <div class="form-group">
                    <label>Recipient</label>
                    <input type="text" id="payment_recipient" readonly>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="text" id="payment_amount" readonly>
                </div>
                <div class="form-group">
                    <label for="category_id">Budget Category *</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Select Budget Category</option>
                        <?php foreach ($allowance_categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($allowance_categories)): ?>
                        <small style="color:var(--danger);display:block;margin-top:.35rem;">
                            No budget categories found. Please create categories for communication and mission allowances first.
                        </small>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.25rem;">
                    <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ Receipt Upload Modal ═══ -->
    <div class="modal-overlay" id="receiptModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-upload" style="color:var(--finance-primary);margin-right:.4rem;"></i>Upload Receipt</h3>
                <button class="close-modal" onclick="closeReceiptModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="receiptForm">
                <input type="hidden" name="action" value="upload_receipt">
                <input type="hidden" name="allowance_type" id="modal_allowance_type">
                <input type="hidden" name="allowance_id" id="modal_allowance_id">
                <input type="hidden" name="recipient_id" id="modal_recipient_id">
                <input type="hidden" name="recipient_name" id="modal_recipient_name">
                <input type="hidden" name="amount" id="modal_amount">
                <input type="hidden" name="purpose" id="modal_purpose">

                <div class="form-group">
                    <label>Recipient</label>
                    <input type="text" id="display_recipient" readonly>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="text" id="display_amount" readonly>
                </div>
                <div class="form-group">
                    <label>Purpose</label>
                    <textarea id="display_purpose" rows="2" readonly></textarea>
                </div>
                <div class="form-group">
                    <label for="receipt_date">Receipt Date *</label>
                    <input type="date" id="receipt_date" name="receipt_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="receipt_file">Receipt File (PDF / Image) *</label>
                    <input type="file" id="receipt_file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,.gif" required>
                    <small id="fileNameDisplay" style="color:var(--dark-gray);display:block;margin-top:.4rem;">
                        Max 5 MB · PDF, JPG, PNG, GIF
                    </small>
                </div>
                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.25rem;">
                    <button type="button" class="btn btn-outline" onclick="closeReceiptModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ Document Viewer Modal ═══ -->
    <div class="document-viewer-modal" id="documentViewer">
        <div class="document-viewer-content">
            <div class="viewer-header">
                <h3 id="documentFileName">Document Viewer</h3>
                <div class="viewer-actions">
                    <a href="#" id="downloadDocument" class="btn btn-sm btn-primary">
                        <i class="fas fa-download"></i> Download
                    </a>
                    <button class="btn btn-sm btn-outline" onclick="closeDocumentViewer()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
            <div class="viewer-body">
                <iframe id="documentFrame" style="display:none;"></iframe>
                <img id="imageViewer" class="image-viewer" style="display:none;" alt="Document Preview">
                <div id="unsupportedViewer" class="unsupported-viewer" style="display:none;"></div>
            </div>
        </div>
    </div>

    <script>
       

        // ── Sidebar Collapse/Expand ──
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

        // ── Mobile Menu ──
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

        // ── Tabs ──
        function switchTab(tabName, btn) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            if (btn) btn.classList.add('active');
        }

        // ── Select All Committee Members ──
        function toggleAllMembers() {
            const selectAll = document.getElementById('selectAllMembers');
            document.querySelectorAll('input[name="committee_members[]"]').forEach(cb => {
                cb.checked = selectAll.checked;
            });
        }

        // ── Communication Form Validation ──
        document.getElementById('communicationForm').addEventListener('submit', function(e) {
            const checked = document.querySelectorAll('input[name="committee_members[]"]:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Please select at least one committee member.');
            }
        });

        // ── Transport Mode → Default Amount ──
        document.getElementById('transport_mode').addEventListener('change', function() {
            const field = document.getElementById('amount_mission');
            switch (this.value) {
                case 'public':          field.value = '5000'; break;
                case 'private':         field.value = '8000'; break;
                case 'college_vehicle': field.value = '3000'; break;
                default:                field.value = '5000';
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('transport_mode').dispatchEvent(new Event('change'));
        });

        // ── Payment Modal ──
        function openPaymentModal(type, id, recipientName, amount) {
            document.getElementById('payment_allowance_type').value = type;
            document.getElementById('payment_allowance_id').value = id;
            document.getElementById('payment_recipient').value = recipientName;
            document.getElementById('payment_amount').value = 'RWF ' + Number(amount).toLocaleString();
            document.getElementById('paymentModal').classList.add('active');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
            document.getElementById('paymentForm').reset();
        }

        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) closePaymentModal();
        });

        // ── Receipt Modal ──
        function openReceiptModal(type, id, recipientName, amount, purpose) {
            document.getElementById('modal_allowance_type').value = type;
            document.getElementById('modal_allowance_id').value = id;
            document.getElementById('modal_recipient_id').value = 1; // placeholder; set dynamically if needed
            document.getElementById('modal_recipient_name').value = recipientName;
            document.getElementById('modal_amount').value = amount;
            document.getElementById('modal_purpose').value = purpose;

            document.getElementById('display_recipient').value = recipientName;
            document.getElementById('display_amount').value = 'RWF ' + Number(amount).toLocaleString();
            document.getElementById('display_purpose').value = purpose;

            document.getElementById('receiptModal').classList.add('active');
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.remove('active');
            document.getElementById('receiptForm').reset();
            document.getElementById('fileNameDisplay').textContent = 'Max 5 MB · PDF, JPG, PNG, GIF';
        }

        document.getElementById('receiptModal').addEventListener('click', function(e) {
            if (e.target === this) closeReceiptModal();
        });

        // ── File validation ──
        document.getElementById('receipt_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const maxSize = 5 * 1024 * 1024;
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            const display = document.getElementById('fileNameDisplay');

            if (file) {
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a PDF, JPG, JPEG, PNG, or GIF file.');
                    this.value = '';
                    return;
                }
                if (file.size > maxSize) {
                    alert('File size must be less than 5MB.');
                    this.value = '';
                    return;
                }
                display.textContent = 'Selected: ' + file.name;
                display.style.color = 'var(--success)';
            }
        });

        // ── Document Viewer ──
        function openDocumentViewer(filePath, fileName) {
            const ext = filePath.split('.').pop().toLowerCase();
            const viewer = document.getElementById('documentViewer');
            const frame = document.getElementById('documentFrame');
            const imgViewer = document.getElementById('imageViewer');
            const unsupported = document.getElementById('unsupportedViewer');
            const dlBtn = document.getElementById('downloadDocument');

            dlBtn.href = '../' + filePath;
            dlBtn.download = fileName;

            frame.style.display = 'none';
            imgViewer.style.display = 'none';
            unsupported.style.display = 'none';

            if (ext === 'pdf') {
                frame.style.display = 'block';
                frame.src = `document_viewer.php?file=${encodeURIComponent(filePath)}`;
            } else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                imgViewer.style.display = 'block';
                imgViewer.src = '../' + filePath;
                imgViewer.alt = fileName;
            } else {
                unsupported.style.display = 'block';
                unsupported.innerHTML = `
                    <i class="fas fa-file fa-3x" style="margin-bottom:1rem;opacity:.4;"></i>
                    <h4>Preview Not Available</h4>
                    <p style="font-size:.85rem;margin:.5rem 0 1rem;">This file type cannot be previewed in the browser.</p>
                    <button class="btn btn-primary btn-sm" onclick="downloadDocument('${filePath}', '${fileName}')">
                        <i class="fas fa-download"></i> Download File
                    </button>`;
            }

            document.getElementById('documentFileName').textContent = fileName;
            viewer.classList.add('active');
        }

        function closeDocumentViewer() {
            document.getElementById('documentViewer').classList.remove('active');
            document.getElementById('documentFrame').src = '';
            document.getElementById('imageViewer').src = '';
        }

        function downloadDocument(filePath, fileName) {
            const link = document.createElement('a');
            link.href = '../' + filePath;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        document.getElementById('documentViewer').addEventListener('click', function(e) {
            if (e.target === this) closeDocumentViewer();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDocumentViewer();
                closePaymentModal();
                closeReceiptModal();
            }
        });
    </script>
</body>
</html>