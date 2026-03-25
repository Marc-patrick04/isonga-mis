<?php
session_start();
require_once '../config/database.php';
require_once '../config/email_config.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: student_aid.php');
    exit();
}

$request_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Helper function to sanitize academic year
function sanitizeAcademicYear($year) {
    if (empty($year)) return 'Year 1';
    
    // Trim and clean the value
    $year = trim($year);
    
    // Valid academic year patterns
    $valid_patterns = [
        '/^Year [1-3]$/',           // Year 1, Year 2, Year 3
        '/^Year\s+[1-3]$/',         // Year 1 (with multiple spaces)
        '/^B-Tech$/i',              // B-Tech (case insensitive)
        '/^M-Tech$/i',              // M-Tech (case insensitive)
        '/^\d{4}-\d{4}$/',          // 2025-2026 format
        '/^\d{4}$/',                // Just year
        '/^[A-Za-z\s]+$/'           // Any text (like "First Year")
    ];
    
    foreach ($valid_patterns as $pattern) {
        if (preg_match($pattern, $year)) {
            // Standardize common formats
            if (preg_match('/^Year\s*[1-3]$/i', $year)) {
                return 'Year ' . preg_replace('/[^0-9]/', '', $year);
            }
            if (strtoupper($year) === 'B-TECH') return 'B-Tech';
            if (strtoupper($year) === 'M-TECH') return 'M-Tech';
            return $year;
        }
    }
    
    // Default fallback
    return 'Year 1';
}

// Helper function to format academic year for display
function formatAcademicYear($year) {
    if (empty($year)) return 'Not specified';
    
    $year = trim($year);
    
    // If it's already in format like "2025-2026"
    if (preg_match('/^\d{4}-\d{4}$/', $year)) {
        return $year;
    }
    
    // If it's like "Year 1", "Year 2", etc.
    if (preg_match('/^Year \d+$/i', $year)) {
        return $year;
    }
    
    // If it's like "B-Tech", "M-Tech"
    if (in_array(strtoupper($year), ['B-TECH', 'M-TECH'])) {
        return ucfirst(strtolower($year));
    }
    
    // If it's just a number (1,2,3)
    if (is_numeric($year) && $year <= 3) {
        return "Year $year";
    }
    
    // Default: just return the original, truncated if too long
    return substr($year, 0, 20);
}

// Get financial aid request details
try {
    $stmt = $pdo->prepare("
        SELECT 
            sfa.*,
            u.full_name as student_name,
            u.email as student_email,
            u.phone as student_phone,
            u.reg_number as registration_number,
            u.academic_year,
            u.department_id,
            u.program_id,
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
    
    // Sanitize academic year
    if ($request && isset($request['academic_year'])) {
        $request['academic_year'] = sanitizeAcademicYear($request['academic_year']);
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error_message = "Failed to fetch request details.";
}

if (!$request) {
    header('Location: student_aid.php');
    exit();
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'update_status') {
            $status = $_POST['status'];
            $amount_approved = floatval($_POST['amount_approved'] ?? 0);
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
                try {
                    $transaction_id = recordStudentAidTransaction($request_id, $amount_approved, $user_id);
                    $update_data['transaction_id'] = $transaction_id;
                } catch (Exception $e) {
                    error_log("Transaction recording failed: " . $e->getMessage());
                    // Continue even if transaction recording fails
                }
            }
            
            // Handle approval letter upload
            if (isset($_FILES['approval_letter']) && $_FILES['approval_letter']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/student_appr_letters/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['approval_letter']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = 'approval_' . $request['registration_number'] . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['approval_letter']['tmp_name'], $file_path)) {
                        $update_data['approval_letter_path'] = $file_path;
                    } else {
                        throw new Exception("Failed to upload approval letter.");
                    }
                } else {
                    throw new Exception("Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG");
                }
            }
            
            // Build dynamic UPDATE query (only update fields that exist in student_financial_aid)
            $allowed_fields = ['status', 'reviewed_by', 'review_date', 'review_notes', 'amount_approved', 'disbursement_date', 'transaction_id', 'approval_letter_path'];
            $sql = "UPDATE student_financial_aid SET ";
            $params = [];
            $updates = [];
            
            foreach ($update_data as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $updates[] = "$key = ?";
                    $params[] = $value;
                }
            }
            
            if (empty($updates)) {
                throw new Exception("No valid fields to update.");
            }
            
            $sql .= implode(', ', $updates) . " WHERE id = ?";
            $params[] = $request_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Send email notification to student
            $email_sent = false;
            $email_message = "";
            
            if (!empty($request['student_email'])) {
                $status_updated_by = $_SESSION['full_name'] . " (Vice Guild Finance)";
                
                // Check if email function exists
                if (function_exists('sendFinancialAidStatusUpdate')) {
                    $email_result = sendFinancialAidStatusUpdate(
                        $request['student_email'],
                        $request['student_name'],
                        $request_id,
                        $request['request_title'],
                        floatval($request['amount_requested']),
                        $amount_approved,
                        $status,
                        $review_notes,
                        $status_updated_by
                    );
                    
                    if ($email_result['success']) {
                        $email_sent = true;
                        $email_message = " A notification email has been sent to the student.";
                    } else {
                        error_log("Failed to send status update email: " . ($email_result['message'] ?? 'Unknown error'));
                        $email_message = " Status updated but email notification failed to send.";
                    }
                } else {
                    error_log("sendFinancialAidStatusUpdate function not found in email_config.php");
                    $email_message = " Status updated but email function not available.";
                }
            } else {
                $email_message = " Status updated but no email address found for the student.";
            }
            
            $message = "Request updated successfully!" . $email_message;
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
                    u.department_id,
                    u.program_id,
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
            
            // Sanitize academic year again
            if ($request && isset($request['academic_year'])) {
                $request['academic_year'] = sanitizeAcademicYear($request['academic_year']);
            }
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $message = "Database error: " . $e->getMessage();
        $message_type = "error";
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Helper functions
function safe_display($data) {
    return $data ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : '';
}

function getStatusBadge($status) {
    $badges = [
        'submitted' => 'status-submitted',
        'under_review' => 'status-under_review',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'disbursed' => 'status-disbursed'
    ];
    return $badges[$status] ?? 'status-submitted';
}

function getUrgencyBadge($urgency) {
    $badges = [
        'low' => 'urgency-low',
        'medium' => 'urgency-medium',
        'high' => 'urgency-high',
        'emergency' => 'urgency-emergency'
    ];
    return $badges[$urgency] ?? 'urgency-medium';
}

function getStatusText($status) {
    $texts = [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'disbursed' => 'Disbursed'
    ];
    return $texts[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function getUrgencyText($urgency) {
    $texts = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'emergency' => 'Emergency'
    ];
    return $texts[$urgency] ?? ucfirst($urgency);
}

// Record student aid transaction function
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
        $stmt = $pdo->prepare("SELECT id FROM budget_categories WHERE category_name = 'Student Financial Aid' AND is_active = true LIMIT 1");
        $stmt->execute();
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            // Create the category if it doesn't exist
            $stmt = $pdo->prepare("
                INSERT INTO budget_categories (category_name, category_type, description, is_active) 
                VALUES ('Student Financial Aid', 'expense', 'Student financial aid disbursements', true)
                RETURNING id
            ");
            $stmt->execute();
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Record the transaction
        $stmt = $pdo->prepare("
            INSERT INTO financial_transactions (
                transaction_type, category_id, amount, description, transaction_date,
                payee_payer, payment_method, status, requested_by, approved_by_finance
            ) VALUES (
                'expense', ?, ?, ?, CURRENT_DATE,
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

// Fix any problematic academic year data
function fixAcademicYearData() {
    global $pdo;
    try {
        // Update any users with invalid academic year
        $stmt = $pdo->prepare("
            UPDATE users 
            SET academic_year = 'Year 1' 
            WHERE academic_year IS NULL 
            OR academic_year = '' 
            OR LENGTH(academic_year) > 20
        ");
        $stmt->execute();
        
        // Normalize academic year formats
        $stmt = $pdo->prepare("
            UPDATE users 
            SET academic_year = 'Year 1' 
            WHERE academic_year LIKE '%1%' AND academic_year NOT LIKE '%Year%'
        ");
        $stmt->execute();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET academic_year = 'Year 2' 
            WHERE academic_year LIKE '%2%' AND academic_year NOT LIKE '%Year%'
        ");
        $stmt->execute();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET academic_year = 'Year 3' 
            WHERE academic_year LIKE '%3%' AND academic_year NOT LIKE '%Year%'
        ");
        $stmt->execute();
        
    } catch (PDOException $e) {
        error_log("Failed to fix academic year data: " . $e->getMessage());
    }
}

// Run the fix function
fixAcademicYearData();
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
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
            line-height: 1.5;
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
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
        }

        .icon-btn:hover {
            background: var(--finance-primary);
            color: white;
            border-color: var(--finance-primary);
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
            width: 250px;
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
        }

        .sidebar-menu {
            list-style: none;
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
        }

        .menu-item a:hover,
        .menu-item a.active {
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
        }

        .menu-item i {
            width: 20px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

        /* Dashboard Header */
        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: var(--info);
        }

        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--medium-gray);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--finance-light);
        }

        .detail-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--finance-primary);
        }

        .detail-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.85rem;
        }

        .detail-value {
            text-align: right;
            font-size: 0.85rem;
        }

        /* Status Badges */
        .status-badge,
        .urgency-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-submitted { background: #fff3cd; color: #856404; }
        .status-under_review { background: #cce5ff; color: #004085; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-disbursed { background: #d1ecf1; color: #0c5460; }

        .urgency-low { background: #d4edda; color: #155724; }
        .urgency-medium { background: #fff3cd; color: #856404; }
        .urgency-high { background: #ffe5b4; color: #e65100; }
        .urgency-emergency { background: #f8d7da; color: #721c24; }

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border: 1px solid var(--medium-gray);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: var(--finance-light);
            border-bottom: 1px solid var(--medium-gray);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Documents Grid */
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .document-card {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--medium-gray);
        }

        .document-type {
            font-weight: 600;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }

        .no-document {
            color: var(--dark-gray);
            font-style: italic;
            font-size: 0.8rem;
        }

        /* Action Section */
        .action-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid var(--medium-gray);
        }

        .action-section h3 {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--finance-primary);
            background: var(--finance-light);
        }

        .file-upload input {
            display: none;
        }

        .file-upload label {
            cursor: pointer;
            display: block;
        }

        .file-upload i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--dark-gray);
        }

        .file-list {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--success);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
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
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .detail-value {
                text-align: left;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Student Aid Request Details</h1>
                </div>
            </div>
            <div class="user-menu">
                <button class="icon-btn" id="themeToggle">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo safe_display($_SESSION['full_name']); ?></div>
                        <div class="user-role">Vice Guild Finance</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
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
                    <a href="student_aid.php" class="active">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                    </a>
                </li>
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
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <?php echo safe_display($message); ?>
                </div>
            <?php endif; ?>

            <!-- Email Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-envelope"></i>
                <div>
                    <strong>Email Notifications</strong><br>
                    The student will receive an email notification when you update this request status.
                    Student email: <?php echo !empty($request['student_email']) ? safe_display($request['student_email']) : '<span style="color: var(--danger);">Not available</span>'; ?>
                </div>
            </div>

            <!-- Request Details -->
            <div class="detail-grid">
                <!-- Student Information -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3 class="detail-title">Student Information</h3>
                    </div>
                    <div class="detail-content">
                        <div class="detail-row">
                            <span class="detail-label">Full Name:</span>
                            <span class="detail-value"><?php echo safe_display($request['student_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Registration Number:</span>
                            <span class="detail-value"><?php echo safe_display($request['registration_number']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Email:</span>
                            <span class="detail-value"><?php echo safe_display($request['student_email']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Phone:</span>
                            <span class="detail-value"><?php echo safe_display($request['student_phone']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Department:</span>
                            <span class="detail-value"><?php echo safe_display($request['department_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Program:</span>
                            <span class="detail-value"><?php echo safe_display($request['program_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Academic Year:</span>
                            <span class="detail-value"><?php echo formatAcademicYear($request['academic_year']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Request Information -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3 class="detail-title">Request Information</h3>
                        <span class="status-badge <?php echo getStatusBadge($request['status']); ?>">
                            <?php echo getStatusText($request['status']); ?>
                        </span>
                    </div>
                    <div class="detail-content">
                        <div class="detail-row">
                            <span class="detail-label">Request Title:</span>
                            <span class="detail-value"><?php echo safe_display($request['request_title']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Amount Requested:</span>
                            <span class="detail-value"><strong>RWF <?php echo number_format($request['amount_requested'], 2); ?></strong></span>
                        </div>
                        <?php if ($request['amount_approved'] > 0): ?>
                        <div class="detail-row">
                            <span class="detail-label">Amount Approved:</span>
                            <span class="detail-value"><strong style="color: var(--success);">RWF <?php echo number_format($request['amount_approved'], 2); ?></strong></span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="detail-label">Urgency Level:</span>
                            <span class="detail-value">
                                <span class="urgency-badge <?php echo getUrgencyBadge($request['urgency_level']); ?>">
                                    <?php echo getUrgencyText($request['urgency_level']); ?>
                                </span>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Date Submitted:</span>
                            <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></span>
                        </div>
                        <?php if ($request['reviewer_name']): ?>
                        <div class="detail-row">
                            <span class="detail-label">Reviewed By:</span>
                            <span class="detail-value"><?php echo safe_display($request['reviewer_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Review Date:</span>
                            <span class="detail-value"><?php echo $request['review_date'] ? date('F j, Y g:i A', strtotime($request['review_date'])) : 'N/A'; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($request['disbursement_date']): ?>
                        <div class="detail-row">
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
                            <div class="document-card">
                                <div class="document-type">Student's Request Letter</div>
                                <?php if ($request['request_letter_path']): ?>
                                    <a href="../<?php echo $request['request_letter_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No request letter uploaded</div>
                                <?php endif; ?>
                            </div>

                            <div class="document-card">
                                <div class="document-type">Supporting Documents</div>
                                <?php if ($request['supporting_docs_path']): ?>
                                    <a href="../<?php echo $request['supporting_docs_path']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No supporting documents uploaded</div>
                                <?php endif; ?>
                            </div>

                            <div class="document-card">
                                <div class="document-type">Approval Letter</div>
                                <?php if ($request['approval_letter_path']): ?>
                                    <a href="../<?php echo $request['approval_letter_path']; ?>" target="_blank" class="btn btn-success btn-sm">
                                        <i class="fas fa-download"></i> Download
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
                <h3>Update Request Status</h3>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_status">
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" required id="statusSelect">
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
                               step="0.01" min="0">
                        <small style="color: var(--dark-gray);">
                            Original requested amount: RWF <?php echo number_format($request['amount_requested'], 2); ?>
                        </small>
                    </div>

                    <div class="form-group" id="approvalLetterGroup">
                        <label class="form-label">Upload Approval Letter</label>
                        <div class="file-upload">
                            <input type="file" name="approval_letter" id="approval_letter" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <label for="approval_letter">
                                <i class="fas fa-upload"></i>
                                <p>Click to upload approval letter</p>
                                <p style="font-size: 0.75rem; color: var(--dark-gray);">PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</p>
                            </label>
                            <div id="approval_letter_name" class="file-list"></div>
                        </div>
                        <?php if ($request['approval_letter_path']): ?>
                            <small style="color: var(--success); margin-top: 0.5rem; display: block;">
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
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Request
                        </button>
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
        const fileInput = document.getElementById('approval_letter');
        const fileNameDisplay = document.getElementById('approval_letter_name');
        
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const fileName = e.target.files[0]?.name || '';
                if (fileName) {
                    fileNameDisplay.textContent = `Selected: ${fileName}`;
                    fileNameDisplay.style.color = 'var(--success)';
                } else {
                    fileNameDisplay.textContent = '';
                }
            });
        }

        // Show/hide amount and approval letter fields based on status
        const statusSelect = document.getElementById('statusSelect');
        const amountGroup = document.getElementById('amountApprovedGroup');
        const approvalLetterGroup = document.getElementById('approvalLetterGroup');

        function toggleFields() {
            if (!statusSelect) return;
            
            const status = statusSelect.value;
            
            if (status === 'approved' || status === 'disbursed') {
                if (amountGroup) amountGroup.style.display = 'block';
                if (approvalLetterGroup) approvalLetterGroup.style.display = 'block';
                if (amountGroup) {
                    const amountInput = amountGroup.querySelector('input');
                    if (amountInput && !amountInput.value) {
                        amountInput.value = <?php echo $request['amount_requested']; ?>;
                    }
                }
            } else {
                if (amountGroup) amountGroup.style.display = 'none';
                if (approvalLetterGroup) approvalLetterGroup.style.display = 'none';
            }
        }

        if (statusSelect) {
            statusSelect.addEventListener('change', toggleFields);
            // Initialize on page load
            toggleFields();
        }

        // Confirm before updating status if it's a critical change
        const updateForm = document.querySelector('form');
        if (updateForm) {
            updateForm.addEventListener('submit', function(e) {
                const status = statusSelect ? statusSelect.value : '';
                if (status === 'rejected') {
                    if (!confirm('Are you sure you want to reject this request? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                } else if (status === 'disbursed') {
                    if (!confirm('Confirm disbursement: Have you processed the payment and uploaded the approval letter?')) {
                        e.preventDefault();
                    }
                }
            });
        }

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 300);
            }, 5000);
        });
    </script>
</body>
</html>