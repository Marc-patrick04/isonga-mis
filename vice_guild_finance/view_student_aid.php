<?php
session_start();
require_once '../config/database.php';
require_once '../config/email_config.php';
require_once '../config/academic_year.php';

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
$current_academic_year = getCurrentAcademicYear();

// Helper function to get correct file URL
function getFileUrl($path) {
    if (empty($path)) return '';
    $path = ltrim($path, './');
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }
    return '/isonga-mis/' . $path;
}

// Helper function to sanitize academic year
function sanitizeAcademicYear($year) {
    if (empty($year)) return 'Year 1';
    $year = trim($year);
    if (preg_match('/^Year\s*[1-3]$/i', $year)) {
        return 'Year ' . preg_replace('/[^0-9]/', '', $year);
    }
    if (strtoupper($year) === 'B-TECH') return 'B-Tech';
    if (strtoupper($year) === 'M-TECH') return 'M-Tech';
    if (preg_match('/^\d{4}-\d{4}$/', $year)) return $year;
    if (is_numeric($year) && $year <= 3) return "Year $year";
    return substr($year, 0, 20);
}

function formatAcademicYear($year) {
    if (empty($year)) return 'Not specified';
    $year = trim($year);
    if (preg_match('/^\d{4}-\d{4}$/', $year)) return $year;
    if (preg_match('/^Year \d+$/i', $year)) return $year;
    if (in_array(strtoupper($year), ['B-TECH', 'M-TECH'])) return ucfirst(strtolower($year));
    if (is_numeric($year) && $year <= 3) return "Year $year";
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
        if ($action === 'send_to_president') {
            $status = 'pending_president';
            $amount_approved = floatval($_POST['amount_approved'] ?? 0);
            $review_notes = trim($_POST['review_notes']);
            
            $update_data = [
                'status' => $status,
                'reviewed_by' => $user_id,
                'review_date' => date('Y-m-d H:i:s'),
                'review_notes' => $review_notes,
                'amount_approved' => $amount_approved
            ];
            
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
                    $file_path = 'assets/uploads/student_appr_letters/' . $file_name;
                    $full_path = '../' . $file_path;
                    
                    if (move_uploaded_file($_FILES['approval_letter']['tmp_name'], $full_path)) {
                        $update_data['approval_letter_path'] = $file_path;
                    }
                }
            }
            
            $allowed_fields = ['status', 'reviewed_by', 'review_date', 'review_notes', 'amount_approved', 'approval_letter_path'];
            $sql = "UPDATE student_financial_aid SET ";
            $params = [];
            $updates = [];
            
            foreach ($update_data as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $updates[] = "$key = ?";
                    $params[] = $value;
                }
            }
            
            if (!empty($updates)) {
                $sql .= implode(', ', $updates) . " WHERE id = ?";
                $params[] = $request_id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            
            $message = "Request sent to Guild President for final approval!";
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
            if ($request && isset($request['academic_year'])) {
                $request['academic_year'] = sanitizeAcademicYear($request['academic_year']);
            }
            
        } elseif ($action === 'process_disbursement') {
            $status = 'disbursed';
            $review_notes = trim($_POST['review_notes']);
            
            $update_data = [
                'status' => $status,
                'disbursement_date' => date('Y-m-d'),
                'review_notes' => $review_notes,
                'reviewed_by' => $user_id,
                'review_date' => date('Y-m-d H:i:s')
            ];
            
            // Record transaction
            try {
                $transaction_id = recordStudentAidTransaction($request_id, $request['amount_approved'], $user_id);
                $update_data['transaction_id'] = $transaction_id;
            } catch (Exception $e) {
                error_log("Transaction recording failed: " . $e->getMessage());
            }
            
            $allowed_fields = ['status', 'disbursement_date', 'review_notes', 'reviewed_by', 'review_date', 'transaction_id'];
            $sql = "UPDATE student_financial_aid SET ";
            $params = [];
            $updates = [];
            
            foreach ($update_data as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $updates[] = "$key = ?";
                    $params[] = $value;
                }
            }
            
            if (!empty($updates)) {
                $sql .= implode(', ', $updates) . " WHERE id = ?";
                $params[] = $request_id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            
            // Send email notification to student
            $email_sent = false;
            $email_error = '';
            
            if (!empty($request['student_email'])) {
                if (function_exists('sendEmail')) {
                    $subject = "✅ Financial Aid Disbursement Confirmation - Request #$request_id";
                    $body = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
                            .highlight { background: #d4edda; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; }
                            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <div class="header">
                                <h2>💰 Financial Aid Disbursement Confirmed</h2>
                            </div>
                            <div class="content">
                                <p>Dear ' . htmlspecialchars($request['student_name']) . ',</p>
                                <p>We are pleased to inform you that your financial aid request has been processed and the funds have been disbursed.</p>
                                <div class="highlight">
                                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                                    <p><strong>Request Title:</strong> ' . htmlspecialchars($request['request_title']) . '</p>
                                    <p><strong>Amount Disbursed:</strong> <strong style="color: #28a745;">RWF ' . number_format($request['amount_approved'], 2) . '</strong></p>
                                    <p><strong>Processed By:</strong> ' . htmlspecialchars($_SESSION['full_name']) . '</p>
                                    <p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($review_notes)) . '</p>
                                </div>
                                <p>Please check your bank account or contact the finance office for details on the disbursement method.</p>
                                <p>If you have any questions, please contact the Vice Guild Finance office.</p>
                            </div>
                            <div class="footer">
                                <p>Isonga - RPSU Management System</p>
                            </div>
                        </div>
                    </body>
                    </html>';
                    
                    $email_result = sendEmail($request['student_email'], $subject, $body);
                    if ($email_result['success']) {
                        $email_sent = true;
                    } else {
                        $email_error = $email_result['message'];
                        error_log("Email failed: " . $email_error);
                    }
                } else {
                    error_log("sendEmail function not available");
                    $email_error = "Email function not available";
                }
            } else {
                $email_error = "No student email address found";
                error_log($email_error);
            }
            
            $message = "Funds disbursed successfully!" . ($email_sent ? " Student has been notified." : " Student notification failed to send: " . $email_error);
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
            if ($request && isset($request['academic_year'])) {
                $request['academic_year'] = sanitizeAcademicYear($request['academic_year']);
            }
            
        } elseif ($action === 'reject_request') {
            $status = 'rejected';
            $rejection_reason = trim($_POST['rejection_reason']);
            
            $update_data = [
                'status' => $status,
                'review_notes' => $rejection_reason,
                'reviewed_by' => $user_id,
                'review_date' => date('Y-m-d H:i:s')
            ];
            
            $allowed_fields = ['status', 'review_notes', 'reviewed_by', 'review_date'];
            $sql = "UPDATE student_financial_aid SET ";
            $params = [];
            $updates = [];
            
            foreach ($update_data as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $updates[] = "$key = ?";
                    $params[] = $value;
                }
            }
            
            if (!empty($updates)) {
                $sql .= implode(', ', $updates) . " WHERE id = ?";
                $params[] = $request_id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            
            // Send rejection email
            if (!empty($request['student_email']) && function_exists('sendEmail')) {
                $subject = "📋 Update on Your Financial Aid Request #$request_id";
                $body = '
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                        .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
                        .highlight { background: #f8d7da; padding: 15px; margin: 15px 0; border-left: 4px solid #dc3545; }
                        .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h2>📋 Financial Aid Request Update</h2>
                        </div>
                        <div class="content">
                            <p>Dear ' . htmlspecialchars($request['student_name']) . ',</p>
                            <p>Thank you for your financial aid request submission. After careful review, your request could not be approved at this time.</p>
                            <div class="highlight">
                                <p><strong>Request ID:</strong> #' . $request_id . '</p>
                                <p><strong>Request Title:</strong> ' . htmlspecialchars($request['request_title']) . '</p>
                                <p><strong>Reason for Rejection:</strong></p>
                                <p>' . nl2br(htmlspecialchars($rejection_reason)) . '</p>
                                <p><strong>Reviewed By:</strong> ' . htmlspecialchars($_SESSION['full_name']) . '</p>
                            </div>
                            <p>If you have questions about this decision, please contact the Vice Guild Finance office.</p>
                        </div>
                        <div class="footer">
                            <p>Isonga - RPSU Management System</p>
                        </div>
                    </div>
                </body>
                </html>';
                
                sendEmail($request['student_email'], $subject, $body);
            }
            
            $message = "Request rejected successfully! Student has been notified.";
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
        'pending_president' => 'status-pending-president',
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
        'pending_president' => 'Pending President',
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

function recordStudentAidTransaction($aid_request_id, $approved_amount, $user_id) {
    global $pdo;
    
    try {
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
        
        $stmt = $pdo->prepare("SELECT id FROM budget_categories WHERE category_name = 'Student Financial Aid' AND is_active = true LIMIT 1");
        $stmt->execute();
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            $stmt = $pdo->prepare("
                INSERT INTO budget_categories (category_name, category_type, description, is_active) 
                VALUES ('Student Financial Aid', 'expense', 'Student financial aid disbursements', true)
                RETURNING id
            ");
            $stmt->execute();
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
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

// Get counts for sidebar
$pending_requests = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM student_financial_aid WHERE status IN ('submitted', 'under_review')");
    $pending_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    error_log("Failed to get pending count: " . $e->getMessage());
}

// Get user profile for header
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>View Student Aid Request - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary: #0056b3;
            --primary-dark: #003d82;
            --primary-light: #4d8be6;
            --secondary: #1e88e5;
            --accent: #0d47a1;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #343a40;
            --gray-900: #212529;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -3px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: 0.3s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            line-height: 1.5;
            font-size: 0.875rem;
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--gray-200);
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
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-800);
            padding: 0.5rem;
            border-radius: var(--border-radius);
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
            color: var(--gray-900);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--gray-200);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--gray-800);
            transition: var(--transition);
        }

        .icon-btn:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
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
            box-shadow: var(--shadow-md);
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
            border-right: 1px solid var(--gray-200);
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
            background: var(--primary);
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
            color: var(--gray-800);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover,
        .menu-item a.active {
            background: var(--gray-100);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .menu-item i {
            width: 20px;
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

        /* Workflow Steps */
        .workflow-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1rem;
            border: 1px solid var(--gray-200);
            flex-wrap: wrap;
        }

        .workflow-step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            position: relative;
        }

        .workflow-step:not(:last-child):after {
            content: '→';
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
        }

        .step-icon {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
        }

        .workflow-step.completed .step-icon {
            background: var(--success);
            color: white;
        }

        .workflow-step.active .step-icon {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.2);
        }

        .step-label {
            font-size: 0.75rem;
            font-weight: 600;
        }

        .step-role {
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        @media (max-width: 768px) {
            .workflow-steps {
                flex-direction: column;
                gap: 0.5rem;
            }
            .workflow-step:not(:last-child):after {
                content: '↓';
                right: auto;
                left: 50%;
                top: auto;
                bottom: -15px;
                transform: translateX(-50%);
            }
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
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left-color: var(--danger);
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
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
            border: 1px solid var(--gray-200);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--gray-200);
        }

        .detail-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
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
            border-bottom: 1px solid var(--gray-200);
        }

        .detail-label {
            font-weight: 600;
            color: var(--gray-600);
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

        .status-submitted { background: #fef3c7; color: #92400e; }
        .status-under_review { background: #dbeafe; color: #1e40af; }
        .status-pending-president { background: #fed7aa; color: #9a3412; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-disbursed { background: #cffafe; color: #0e7490; }

        .urgency-low { background: #d1fae5; color: #065f46; }
        .urgency-medium { background: #fef3c7; color: #92400e; }
        .urgency-high { background: #fed7aa; color: #9a3412; }
        .urgency-emergency { background: #fee2e2; color: #991b1b; }

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: var(--gray-100);
            border-bottom: 1px solid var(--gray-200);
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
            background: var(--gray-100);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: var(--transition);
        }

        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .document-type {
            font-weight: 600;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }

        .no-document {
            color: var(--gray-600);
            font-style: italic;
            font-size: 0.8rem;
        }

        /* Action Section */
        .action-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid var(--gray-200);
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
            color: var(--gray-800);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--gray-900);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--gray-300);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: var(--gray-100);
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
            color: var(--gray-600);
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
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-800);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            max-width: 500px;
            width: 90%;
            padding: 1.5rem;
        }

        .modal-header {
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1000;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .main-content.sidebar-collapsed {
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }
            
            .overlay.active {
                display: block;
            }
        }

        @media (max-width: 768px) {
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
            
            .button-group {
                flex-direction: column;
            }
            
            .button-group .btn {
                width: 100%;
                justify-content: center;
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Finance</h1>
                </div>
            </div>
            <div class="user-menu">
                <button class="icon-btn" id="themeToggle">
                    <i class="fas fa-moon"></i>
                </button>
                <button class="icon-btn" id="sidebarToggleBtn">
                    <i class="fas fa-chevron-left"></i>
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;">Student Aid Request #<?php echo $request_id; ?></h1>
                    <p>
                        <a href="student_aid.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Requests
                        </a>
                    </p>
                </div>
            </div>

            <!-- Workflow Steps -->
            <div class="workflow-steps">
                <div class="workflow-step <?php echo in_array($request['status'], ['submitted', 'under_review', 'pending_president', 'approved', 'disbursed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'submitted' || $request['status'] == 'under_review' ? 'active' : ''; ?>">
                    <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="step-label">Student Submission</div>
                    <div class="step-role">Student</div>
                </div>
                <div class="workflow-step <?php echo in_array($request['status'], ['pending_president', 'approved', 'disbursed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'under_review' ? 'active' : ''; ?>">
                    <div class="step-icon"><i class="fas fa-check-double"></i></div>
                    <div class="step-label">Finance Review</div>
                    <div class="step-role">Vice Guild Finance</div>
                </div>
                <div class="workflow-step <?php echo in_array($request['status'], ['approved', 'disbursed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'pending_president' ? 'active' : ''; ?>">
                    <div class="step-icon"><i class="fas fa-user-check"></i></div>
                    <div class="step-label">President Approval</div>
                    <div class="step-role">Guild President</div>
                </div>
                <div class="workflow-step <?php echo $request['status'] == 'disbursed' ? 'completed active' : ''; ?> <?php echo $request['status'] == 'approved' ? 'active' : ''; ?>">
                    <div class="step-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="step-label">Disbursement</div>
                    <div class="step-role">Vice Guild Finance</div>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <?php echo safe_display($message); ?>
                </div>
            <?php endif; ?>

            <!-- Status Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Current Status: <?php echo getStatusText($request['status']); ?></strong><br>
                    <?php
                    $status_messages = [
                        'submitted' => 'This request has been submitted by the student and is awaiting your review.',
                        'under_review' => 'You are currently reviewing this request.',
                        'pending_president' => 'This request has been approved by Finance and is awaiting final approval from the Guild President.',
                        'approved' => 'This request has been approved by the President. You can now process the disbursement.',
                        'rejected' => 'This request has been rejected. The student has been notified.',
                        'disbursed' => 'Funds have been disbursed to the student.'
                    ];
                    echo $status_messages[$request['status']] ?? 'Status update pending.';
                    ?>
                </div>
            </div>

            <!-- Request Details -->
            <div class="detail-grid">
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
                        <div style="background: var(--gray-100); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">
                            <?php echo safe_display($request['purpose']); ?>
                        </div>
                    </div>

                    <?php if ($request['review_notes']): ?>
                    <div class="form-group">
                        <label class="form-label">Review Notes</label>
                        <div style="background: var(--gray-100); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">
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
                                    <a href="<?php echo getFileUrl($request['request_letter_path']); ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No request letter uploaded</div>
                                <?php endif; ?>
                            </div>

                            <div class="document-card">
                                <div class="document-type">Supporting Documents</div>
                                <?php if ($request['supporting_docs_path']): ?>
                                    <a href="<?php echo getFileUrl($request['supporting_docs_path']); ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php else: ?>
                                    <div class="no-document">No supporting documents uploaded</div>
                                <?php endif; ?>
                            </div>

                            <div class="document-card">
                                <div class="document-type">Approval Letter</div>
                                <?php if ($request['approval_letter_path']): ?>
                                    <a href="<?php echo getFileUrl($request['approval_letter_path']); ?>" target="_blank" class="btn btn-success btn-sm">
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
                <h3>
                    <?php
                    if ($request['status'] == 'submitted' || $request['status'] == 'under_review') {
                        echo 'Review and Approve Request';
                    } elseif ($request['status'] == 'approved') {
                        echo 'Process Disbursement';
                    } elseif ($request['status'] == 'rejected') {
                        echo 'Request Rejected';
                    } elseif ($request['status'] == 'disbursed') {
                        echo 'Disbursement Completed';
                    } elseif ($request['status'] == 'pending_president') {
                        echo 'Waiting for President Approval';
                    }
                    ?>
                </h3>
                
                <?php if ($request['status'] == 'submitted' || $request['status'] == 'under_review'): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="send_to_president">
                        
                        <div class="form-group">
                            <label class="form-label">Amount to Approve (RWF)</label>
                            <input type="number" class="form-control" name="amount_approved" 
                                   value="<?php echo $request['amount_approved'] ? $request['amount_approved'] : $request['amount_requested']; ?>" 
                                   step="0.01" min="0" required>
                            <small style="color: var(--gray-600);">
                                Original requested amount: RWF <?php echo number_format($request['amount_requested'], 2); ?>
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Upload Approval Letter (Recommended)</label>
                            <div class="file-upload">
                                <input type="file" name="approval_letter" id="approval_letter" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <label for="approval_letter">
                                    <i class="fas fa-upload"></i>
                                    <p>Click to upload approval letter</p>
                                    <p style="font-size: 0.75rem; color: var(--gray-600);">PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</p>
                                </label>
                                <div id="approval_letter_name" class="file-list"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Review Notes / Comments</label>
                            <textarea class="form-control" name="review_notes" rows="4" placeholder="Enter your review comments and approval rationale..." required><?php echo safe_display($request['review_notes']); ?></textarea>
                        </div>

                        <div class="button-group">
                            <a href="student_aid.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" onclick="return confirm('Send this request to Guild President for final approval?')">
                                <i class="fas fa-paper-plane"></i> Send to President
                            </button>
                            <button type="button" class="btn btn-danger" onclick="showRejectModal()">
                                <i class="fas fa-times-circle"></i> Reject Request
                            </button>
                        </div>
                    </form>

                <?php elseif ($request['status'] == 'approved'): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="process_disbursement">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            This request has been approved by the Guild President. You can now process the disbursement.
                        </div>

                        <div class="form-group">
                            <label class="form-label">Amount to Disburse</label>
                            <input type="text" class="form-control" value="RWF <?php echo number_format($request['amount_approved'], 2); ?>" readonly disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Disbursement Notes</label>
                            <textarea class="form-control" name="review_notes" rows="4" placeholder="Enter disbursement details (payment method, reference number, etc.)" required></textarea>
                        </div>

                        <div class="button-group">
                            <a href="student_aid.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-success" onclick="return confirm('Confirm disbursement: Have you processed the payment? This will notify the student.')">
                                <i class="fas fa-money-bill-wave"></i> Confirm Disbursement
                            </button>
                        </div>
                    </form>

                <?php elseif ($request['status'] == 'pending_president'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-clock"></i>
                        This request has been sent to the Guild President for final approval. Please wait for their decision.
                    </div>
                    <div class="button-group">
                        <a href="student_aid.php" class="btn btn-secondary">Back to Requests</a>
                    </div>

                <?php elseif ($request['status'] == 'rejected'): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle"></i>
                        This request has been rejected. The student has been notified.
                    </div>
                    <div class="button-group">
                        <a href="student_aid.php" class="btn btn-secondary">Back to Requests</a>
                    </div>

                <?php elseif ($request['status'] == 'disbursed'): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        Funds have been successfully disbursed to the student. Transaction recorded.
                    </div>
                    <div class="button-group">
                        <a href="student_aid.php" class="btn btn-secondary">Back to Requests</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Request</h3>
                <button type="button" class="icon-btn" onclick="closeRejectModal()" style="position: absolute; right: 1rem; top: 1rem;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject_request">
                <div class="form-group">
                    <label class="form-label">Rejection Reason</label>
                    <textarea class="form-control" name="rejection_reason" rows="4" placeholder="Please provide a clear reason for rejection..." required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
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
                sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active');
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
            });
        }

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

        // Reject Modal Functions
        function showRejectModal() {
            const modal = document.getElementById('rejectModal');
            if (modal) modal.classList.add('active');
        }
        
        function closeRejectModal() {
            const modal = document.getElementById('rejectModal');
            if (modal) modal.classList.remove('active');
        }

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert:not(.alert-info)');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.3s ease';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 300);
            }, 5000);
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target === modal) {
                closeRejectModal();
            }
        }
    </script>
</body>
</html>