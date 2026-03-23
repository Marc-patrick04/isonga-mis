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
            // Check if allowance already exists for this month
            $check_stmt = $pdo->prepare("
                SELECT id FROM committee_communication_allowances 
                WHERE committee_member_id = ? AND month_year = ? AND academic_year = ?
            ");
            $check_stmt->execute([$member_id, $month_year, $current_academic_year]);
            
            if ($check_stmt->fetch()) {
                continue; // Skip if already exists
            }
            
            // Insert new allowance
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
        
        // Handle file upload
        if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = "../assets/uploads/allowance_receipts/";
            
            // Create upload directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique file name
            $timestamp = time();
            $original_name = basename($_FILES['receipt_file']['name']);
            $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $file_name = "receipt_" . $allowance_type . "_" . $allowance_id . "_" . $timestamp . "." . $file_extension;
            $file_path = $upload_dir . $file_name;
            $file_size = $_FILES['receipt_file']['size'];
            $file_type = $_FILES['receipt_file']['type'];
            
            // Validate file type
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Only PDF, JPG, JPEG, PNG, and GIF files are allowed.");
            }
            
            // Validate file size (max 5MB)
            $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
            if ($file_size > $max_file_size) {
                throw new Exception("File size must be less than 5MB.");
            }
            
            if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $file_path)) {
                // Store relative path in database (without the ../)
                $relative_path = "assets/uploads/allowance_receipts/" . $file_name;
                
                // Check if receipt already exists for this allowance
                $check_stmt = $pdo->prepare("
                    SELECT id FROM allowance_receipts 
                    WHERE allowance_type = ? AND allowance_id = ?
                ");
                $check_stmt->execute([$allowance_type, $allowance_id]);
                $existing_receipt = $check_stmt->fetch();
                
                if ($existing_receipt) {
                    // Update existing receipt
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
                    // Insert new receipt record
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
                
                // Also update the main allowance table with receipt path for backward compatibility
                if ($allowance_type === 'communication') {
                    $update_stmt = $pdo->prepare("
                        UPDATE committee_communication_allowances 
                        SET receipt_path = ? 
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$relative_path, $allowance_id]);
                } else {
                    $update_stmt = $pdo->prepare("
                        UPDATE mission_allowances 
                        SET receipt_path = ? 
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$relative_path, $allowance_id]);
                }
                
                $_SESSION['success'] = "Receipt uploaded successfully.";
                
            } else {
                throw new Exception("Failed to upload receipt file. Please try again.");
            }
        } else {
            $error_message = "Please select a valid receipt file.";
            
            // Provide specific error messages based on upload error code
            if ($_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
                switch ($_FILES['receipt_file']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = "File is too large. Maximum size is 5MB.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = "File was only partially uploaded.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error_message = "No file was selected.";
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error_message = "Missing temporary folder.";
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $error_message = "Failed to write file to disk.";
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $error_message = "File upload stopped by extension.";
                        break;
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
        $category_id = intval($_POST['category_id']); // Get category_id from form
        
        // Validate category_id
        if (empty($category_id)) {
            throw new Exception("Please select a budget category for this allowance.");
        }

        // Get allowance details
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
        
        // Generate unique reference number
        $timestamp = time();
        $random_suffix = substr(str_shuffle("0123456789ABCDEF"), 0, 4);
        $reference_number = 'ALLW-' . date('Ymd') . '-' . strtoupper(substr($allowance_type, 0, 3)) . '-' . $allowance_id . '-' . $random_suffix;
        
        // Check if reference number already exists
        $check_ref_stmt = $pdo->prepare("SELECT id FROM financial_transactions WHERE reference_number = ?");
        $check_ref_stmt->execute([$reference_number]);
        
        if ($check_ref_stmt->fetch()) {
            // If duplicate, add timestamp to make it unique
            $reference_number = 'ALLW-' . date('Ymd-His') . '-' . strtoupper(substr($allowance_type, 0, 3)) . '-' . $allowance_id;
        }
        
        // Create transaction record
        $transaction_stmt = $pdo->prepare("
            INSERT INTO financial_transactions 
            (transaction_type, category_id, amount, description, transaction_date, 
             reference_number, payee_payer, payment_method, status, requested_by, 
             approved_by_finance, approved_by_president, approved_at)
            VALUES ('expense', ?, ?, ?, CURDATE(), ?, ?, 'cash', 'completed', ?, ?, ?, NOW())
        ");
        
        $description = $allowance_type === 'communication' 
            ? "Communication allowance for {$allowance['member_name']} ({$allowance['role']}) - {$allowance['month_year']}"
            : "Mission allowance for {$allowance['member_name']} - {$allowance['destination']}";
        
        $transaction_stmt->execute([
            $category_id,
            $allowance['amount'],
            $description,
            $reference_number,
            $allowance['member_name'],
            $user_id,
            $user_id,
            $user_id // Assuming finance vice can self-approve for allowances
        ]);
        
        $transaction_id = $pdo->lastInsertId();
        
        // Update the allowance record
        if ($allowance_type === 'communication') {
            $update_stmt = $pdo->prepare("
                UPDATE committee_communication_allowances 
                SET status = 'paid', payment_date = CURDATE(), paid_by = ?, paid_at = NOW(),
                    category_id = ?, transaction_id = ?
                WHERE id = ?
            ");
        } else {
            $update_stmt = $pdo->prepare("
                UPDATE mission_allowances 
                SET status = 'paid', payment_date = CURDATE(), paid_by = ?, paid_at = NOW(),
                    category_id = ?, transaction_id = ?
                WHERE id = ?
            ");
        }
        
        $update_stmt->execute([$user_id, $category_id, $transaction_id, $allowance_id]);
        
        // Update monthly budget actual amount
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
try {
    $stmt = $pdo->query("
        SELECT id, category_name 
        FROM budget_categories 
        WHERE category_type = 'expense' 
        AND (category_name LIKE '%communication%' OR category_name LIKE '%mission%' OR category_name LIKE '%allowance%' OR category_name LIKE '%committee%')
        ORDER BY category_name
    ");
    $allowance_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    // Total communication allowances paid this month
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count
        FROM committee_communication_allowances 
        WHERE academic_year = ? AND month_year = ? AND status = 'paid'
    ");
    $stmt->execute([$current_academic_year, $current_month]);
    $comm_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Total mission allowances paid this month
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count
        FROM mission_allowances 
        WHERE academic_year = ? AND MONTH(mission_date) = MONTH(CURDATE()) 
        AND YEAR(mission_date) = YEAR(CURDATE()) AND status = 'paid'
    ");
    $stmt->execute([$current_academic_year]);
    $mission_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Pending allowances
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allowances Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Your existing CSS styles remain the same */
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
        /* Document Viewer Modal */
.document-viewer-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}

.document-viewer-modal.active {
    display: flex;
}

.document-viewer-content {
    background: var(--white);
    border-radius: var(--border-radius);
    width: 90%;
    height: 90%;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-lg);
}

.viewer-header {
    padding: 1rem;
    border-bottom: 1px solid var(--medium-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--finance-light);
}

.viewer-body {
    flex: 1;
    padding: 0;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}

#documentFrame {
    width: 100%;
    height: 100%;
    border: none;
}

.viewer-actions {
    display: flex;
    gap: 0.5rem;
}

.image-viewer {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.unsupported-viewer {
    padding: 2rem;
    text-align: center;
    color: var(--dark-gray);
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

        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 0.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .tab {
            flex: 1;
            padding: 0.75rem 1rem;
            text-align: center;
            background: none;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--dark-gray);
        }

        .tab.active {
            background: var(--finance-primary);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

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

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
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

        .status-pending {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-approved {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-paid {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

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

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .checkbox-item:hover {
            background: var(--light-gray);
        }

        .select-all {
            background: var(--finance-light);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

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
            padding: 1.5rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

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
            
            .form-grid {
                grid-template-columns: 1fr;
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
        
        .modal-overlay {
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

        .modal-overlay.active {
            display: flex;
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
                        <?php if (false): // You can add message count logic here ?>
                            <span class="notification-badge">3</span>
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
                    <a href="allowances.php" class="active">
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
                    <h1>Allowances Management 💰</h1>
                    <p>Manage committee communications and mission allowances for <?php echo $current_academic_year; ?></p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($comm_stats['total_amount'], 2); ?></div>
                        <div class="stat-label">Communication Allowances (This Month)</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-road"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($mission_stats['total_amount'], 2); ?></div>
                        <div class="stat-label">Mission Allowances (This Month)</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_stats['pending_count']; ?></div>
                        <div class="stat-label">Pending Allowances</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($committee_members); ?></div>
                        <div class="stat-label">Committee Members</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('communication')">
                    <i class="fas fa-comments"></i> Communication Allowances
                </button>
                <button class="tab" onclick="switchTab('mission')">
                    <i class="fas fa-road"></i> Mission Allowances
                </button>
                <button class="tab" onclick="switchTab('records')">
                    <i class="fas fa-history"></i> Allowance Records
                </button>
            </div>

            <!-- Communication Allowances Tab -->
            <div id="communication-tab" class="tab-content active">
                <!-- Process Communication Allowances Form -->
                <div class="card">
                    <div class="card-header">
                        <h3>Process Communication Allowances</h3>
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
                                        <label>
                                            <input type="checkbox" id="selectAllMembers" onchange="toggleAllMembers()">
                                            <strong>Select All Committee Members</strong>
                                        </label>
                                    </div>
                                    <div class="checkbox-grid" id="membersGrid">
                                        <?php foreach ($committee_members as $member): ?>
                                            <div class="checkbox-item">
                                                <input type="checkbox" name="committee_members[]" value="<?php echo $member['id']; ?>" 
                                                       id="member_<?php echo $member['id']; ?>">
                                                <label for="member_<?php echo $member['id']; ?>">
                                                    <?php echo htmlspecialchars($member['name']); ?> 
                                                    <small>(<?php echo htmlspecialchars($member['role']); ?>)</small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
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

<!-- Communication Allowances List -->
<div class="card">
    <div class="card-header">
        <h3>Communication Allowances History</h3>
    </div>
    <div class="card-body">
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
                        <td colspan="8" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                            No communication allowances recorded yet
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($communication_allowances as $allowance): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($allowance['member_name']); ?></td>
                            <td><?php echo htmlspecialchars($allowance['role']); ?></td>
                            <td><?php echo date('F Y', strtotime($allowance['month_year'] . '-01')); ?></td>
                            <td class="amount">RWF <?php echo number_format($allowance['amount'], 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $allowance['status']; ?>">
                                    <?php echo ucfirst($allowance['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $allowance['payment_date'] ? date('M j, Y', strtotime($allowance['payment_date'])) : '-'; ?>
                            </td>
                            <td>
                                <?php if (!empty($allowance['receipt_file_path'])): ?>
                                    <?php
                                    $file_extension = pathinfo($allowance['receipt_file_path'], PATHINFO_EXTENSION);
                                    $file_name = !empty($allowance['file_name']) ? $allowance['file_name'] : "Receipt_" . $allowance['member_name'] . "_" . date('F_Y', strtotime($allowance['month_year'] . '-01')) . "." . $file_extension;
                                    ?>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="openDocumentViewer('<?php echo $allowance['receipt_file_path']; ?>', '<?php echo htmlspecialchars($file_name); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-warning" onclick="openReceiptModal('communication', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>, 'Communication Allowance for <?php echo date('F Y', strtotime($allowance['month_year'] . '-01')); ?>')">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <?php if ($allowance['status'] === 'pending'): ?>
                                    <button class="btn btn-sm btn-success" onclick="openPaymentModal('communication', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>)">
                                        <i class="fas fa-check"></i> Mark Paid
                                    </button>
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

            <!-- Mission Allowances Tab -->
            <div id="mission-tab" class="tab-content">
                <!-- Create Mission Allowance Form -->
                <div class="card">
                    <div class="card-header">
                        <h3>Create Mission Allowance</h3>
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
                                                <?php echo htmlspecialchars($member['name']); ?> (<?php echo htmlspecialchars($member['role']); ?>)
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
                                    <label for="notes_mission">Notes</label>
                                    <textarea id="notes_mission" name="notes" rows="2" placeholder="Additional notes..."></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Create Mission Allowance
                            </button>
                        </form>
                    </div>
                </div>

<!-- Mission Allowances List -->
<div class="card">
    <div class="card-header">
        <h3>Mission Allowances History</h3>
    </div>
    <div class="card-body">
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
                        <td colspan="8" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                            No mission allowances recorded yet
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mission_allowances as $allowance): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($allowance['member_name']); ?></td>
                            <td><?php echo htmlspecialchars($allowance['destination']); ?></td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo htmlspecialchars($allowance['mission_purpose']); ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($allowance['mission_date'])); ?></td>
                            <td class="amount">RWF <?php echo number_format($allowance['amount'], 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $allowance['status']; ?>">
                                    <?php echo ucfirst($allowance['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($allowance['receipt_path']): ?>
                                    <?php
                                    $file_extension = pathinfo($allowance['receipt_path'], PATHINFO_EXTENSION);
                                    $file_name = "Mission_Receipt_" . $allowance['member_name'] . "_" . date('M_j_Y', strtotime($allowance['mission_date'])) . "." . $file_extension;
                                    ?>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="openDocumentViewer('<?php echo $allowance['receipt_path']; ?>', '<?php echo htmlspecialchars($file_name); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-warning" onclick="openReceiptModal('mission', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>, 'Mission to <?php echo htmlspecialchars($allowance['destination']); ?> on <?php echo date('M j, Y', strtotime($allowance['mission_date'])); ?>')">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <?php if ($allowance['status'] === 'pending'): ?>
                                    <button class="btn btn-sm btn-success" onclick="openPaymentModal('mission', <?php echo $allowance['id']; ?>, '<?php echo htmlspecialchars($allowance['member_name']); ?>', <?php echo $allowance['amount']; ?>)">
                                        <i class="fas fa-check"></i> Mark Paid
                                    </button>
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

            <!-- Allowance Records Tab -->
            <div id="records-tab" class="tab-content">
                <!-- Your existing records tab content -->
            </div>
        </main>
    </div>

    <!-- Payment Modal -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Mark Allowance as Paid</h3>
                <button class="close-modal" onclick="closePaymentModal()">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="mark_as_paid">
                <input type="hidden" name="allowance_type" id="payment_allowance_type">
                <input type="hidden" name="allowance_id" id="payment_allowance_id">
                
                <div class="form-group">
                    <label for="payment_recipient">Recipient</label>
                    <input type="text" id="payment_recipient" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="payment_amount">Amount</label>
                    <input type="text" id="payment_amount" class="form-control" readonly>
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
                        <small style="color: var(--danger);">No budget categories found. Please create categories for communication and mission allowances first.</small>
                    <?php endif; ?>
                </div>
                
                <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem;">
                    <button type="button" class="btn" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Mark as Paid</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Receipt Upload Modal -->
    <div class="modal-overlay" id="receiptModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Receipt</h3>
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
                    <input type="text" id="display_recipient" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Amount</label>
                    <input type="text" id="display_amount" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Purpose</label>
                    <textarea id="display_purpose" class="form-control" rows="2" readonly></textarea>
                </div>
                
                <div class="form-group">
                    <label for="receipt_date">Receipt Date *</label>
                    <input type="date" id="receipt_date" name="receipt_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
<div class="form-group">
    <label for="receipt_file">Receipt File (PDF/Image) *</label>
    <input type="file" id="receipt_file" name="receipt_file" 
           accept=".pdf,.jpg,.jpeg,.png,.gif" required>
    <small id="fileNameDisplay" style="color: var(--dark-gray); display: block; margin-top: 0.5rem;">
        Maximum file size: 5MB. Allowed types: PDF, JPG, PNG, GIF
    </small>
</div>
                
                <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem;">
                    <button type="button" class="btn" onclick="closeReceiptModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Receipt</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Document Viewer Modal -->
<div class="document-viewer-modal" id="documentViewer">
    <div class="document-viewer-content">
        <div class="viewer-header">
            <h3 id="documentFileName">Document Viewer</h3>
            <div class="viewer-actions">
                <a href="#" id="downloadDocument" class="btn btn-sm btn-primary">
                    <i class="fas fa-download"></i> Download
                </a>
                <button class="btn btn-sm" onclick="closeDocumentViewer()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
        <div class="viewer-body">
            <!-- PDF Viewer -->
            <iframe id="documentFrame" style="display: none;"></iframe>
            
            <!-- Image Viewer -->
            <img id="imageViewer" class="image-viewer" style="display: none;" alt="Document Preview">
            
            <!-- Unsupported File Type -->
            <div id="unsupportedViewer" class="unsupported-viewer" style="display: none;"></div>
        </div>
    </div>
</div>

    <script>
        // Your existing JavaScript functions remain the same
        
        function openPaymentModal(type, id, recipientName, amount) {
            document.getElementById('payment_allowance_type').value = type;
            document.getElementById('payment_allowance_id').value = id;
            document.getElementById('payment_recipient').value = recipientName;
            document.getElementById('payment_amount').value = 'RWF ' + amount.toLocaleString();
            
            document.getElementById('paymentModal').classList.add('active');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
            document.getElementById('paymentForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePaymentModal();
            }
        });

         // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        // Check for saved theme preference or respect OS preference
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

        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        function toggleAllMembers() {
            const selectAll = document.getElementById('selectAllMembers');
            const checkboxes = document.querySelectorAll('input[name="committee_members[]"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        function openReceiptModal(type, id, recipientName, amount, purpose) {
            // For simplicity, we'll use the first committee member's user ID
            // In a real implementation, you'd want to get the actual recipient ID
            const recipientId = 1; // This should be dynamically set based on the committee member
            
            document.getElementById('modal_allowance_type').value = type;
            document.getElementById('modal_allowance_id').value = id;
            document.getElementById('modal_recipient_id').value = recipientId;
            document.getElementById('modal_recipient_name').value = recipientName;
            document.getElementById('modal_amount').value = amount;
            document.getElementById('modal_purpose').value = purpose;
            
            document.getElementById('display_recipient').value = recipientName;
            document.getElementById('display_amount').value = 'RWF ' + amount.toLocaleString();
            document.getElementById('display_purpose').value = purpose;
            
            document.getElementById('receiptModal').classList.add('active');
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.remove('active');
            document.getElementById('receiptForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('receiptModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReceiptModal();
            }
        });

        // Form validation
        document.getElementById('communicationForm').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="committee_members[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one committee member.');
                return false;
            }
        });

        // Set default mission amount based on transport mode
        document.getElementById('transport_mode').addEventListener('change', function() {
            const amountField = document.getElementById('amount_mission');
            switch(this.value) {
                case 'public':
                    amountField.value = '5000';
                    break;
                case 'private':
                    amountField.value = '8000';
                    break;
                case 'college_vehicle':
                    amountField.value = '3000';
                    break;
                default:
                    amountField.value = '5000';
            }
        });

        // Initialize mission amount
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('transport_mode').dispatchEvent(new Event('change'));
        });

        // Document Viewer Functions
function openDocumentViewer(filePath, fileName) {
    const extension = filePath.split('.').pop().toLowerCase();
    const viewer = document.getElementById('documentViewer');
    const frame = document.getElementById('documentFrame');
    const imageViewer = document.getElementById('imageViewer');
    const unsupportedViewer = document.getElementById('unsupportedViewer');
    const downloadBtn = document.getElementById('downloadDocument');
    
    // Set download link
    downloadBtn.href = '../' + filePath;
    downloadBtn.download = fileName;
    
    // Hide all viewers
    frame.style.display = 'none';
    imageViewer.style.display = 'none';
    unsupportedViewer.style.display = 'none';
    
    // Show appropriate viewer
    if (['pdf'].includes(extension)) {
        frame.style.display = 'block';
        frame.src = `document_viewer.php?file=${encodeURIComponent(filePath)}`;
    } else if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        imageViewer.style.display = 'block';
        imageViewer.src = '../' + filePath;
        imageViewer.alt = fileName;
    } else {
        unsupportedViewer.style.display = 'block';
        unsupportedViewer.innerHTML = `
            <i class="fas fa-file fa-3x mb-3"></i>
            <h4>Document Preview Not Available</h4>
            <p>This file type cannot be previewed in the browser.</p>
            <button class="btn btn-primary mt-2" onclick="downloadDocument('${filePath}', '${fileName}')">
                <i class="fas fa-download"></i> Download File
            </button>
        `;
    }
    
    document.getElementById('documentFileName').textContent = fileName;
    viewer.classList.add('active');
}

function closeDocumentViewer() {
    document.getElementById('documentViewer').classList.remove('active');
    // Clear frame src to stop loading
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

// Close viewer when clicking outside content
document.getElementById('documentViewer').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDocumentViewer();
    }
});

// Close viewer with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDocumentViewer();
    }
});

// Enhanced file upload validation
document.getElementById('receipt_file').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const maxSize = 5 * 1024 * 1024; // 5MB
    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    
    if (file) {
        // Check file type
        if (!allowedTypes.includes(file.type)) {
            alert('Please select a PDF, JPG, JPEG, PNG, or GIF file.');
            this.value = '';
            return;
        }
        
        // Check file size
        if (file.size > maxSize) {
            alert('File size must be less than 5MB.');
            this.value = '';
            return;
        }
        
        // Update UI to show selected file
        const fileNameDisplay = document.getElementById('fileNameDisplay');
        if (fileNameDisplay) {
            fileNameDisplay.textContent = 'Selected: ' + file.name;
        }
    }
});
    </script>
</body>
</html>