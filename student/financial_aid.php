<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/database.php';
require_once '../config/email_config_base.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Redirect class representatives to their dedicated dashboard
if ($_SESSION['is_class_rep'] ?? 0) {
    header('Location: class_rep_dashboard.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;
$student_email = $_SESSION['email'] ?? '';

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: financial_aid.php');
    exit();
}

// Handle financial aid request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_financial_aid'])) {
    $request_title = trim($_POST['request_title']);
    $amount_requested = $_POST['amount_requested'];
    $urgency_level = $_POST['urgency_level'];
    $purpose = trim($_POST['purpose']);
    
    // Get student email from database if not in session
    if (empty($student_email)) {
        try {
            $email_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $email_stmt->execute([$student_id]);
            $student_email = $email_stmt->fetchColumn();
            $_SESSION['email'] = $student_email;
        } catch (PDOException $e) {
            error_log("Failed to fetch email: " . $e->getMessage());
        }
    }
    
    // File upload handling
    $supporting_docs_path = null;
    $request_letter_path = null;
    
    if (empty($request_title) || empty($amount_requested) || empty($purpose)) {
        $error_message = "All fields are required.";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Handle supporting documents upload
            if (isset($_FILES['supporting_docs']) && $_FILES['supporting_docs']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/supporting_docs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['supporting_docs']['name'], PATHINFO_EXTENSION);
                $file_name = 'support_' . $reg_number . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['supporting_docs']['tmp_name'], $file_path)) {
                    $supporting_docs_path = $file_path;
                } else {
                    throw new Exception("Failed to upload supporting documents.");
                }
            }
            
            // Handle request letter upload
            if (isset($_FILES['request_letter']) && $_FILES['request_letter']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/request_letters/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['request_letter']['name'], PATHINFO_EXTENSION);
                $file_name = 'letter_' . $reg_number . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['request_letter']['tmp_name'], $file_path)) {
                    $request_letter_path = $file_path;
                } else {
                    throw new Exception("Failed to upload request letter.");
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO student_financial_aid 
                (student_id, request_title, request_letter_path, amount_requested, urgency_level, purpose, supporting_docs_path, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted')
            ");
            
            $stmt->execute([
                $student_id, 
                $request_title, 
                $request_letter_path, 
                $amount_requested, 
                $urgency_level, 
                $purpose, 
                $supporting_docs_path
            ]);
            
            $request_id = $pdo->lastInsertId();
            
            // Commit transaction
            $pdo->commit();
            
            // In financial_aid.php, replace the email sending section with:

// 1. Send confirmation email to student
$email_sent = false;
$email_message = "";

if (!empty($student_email)) {
    $subject = "Financial Aid Request Received - #$request_id";
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>✅ Financial Aid Request Received</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($student_name) . ',</p>
                <p>Thank you for submitting your financial aid request.</p>
                <div class="details">
                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                    <p><strong>Amount:</strong> RWF ' . number_format($amount_requested, 2) . '</p>
                    <p><strong>Urgency:</strong> ' . ucfirst($urgency_level) . '</p>
                    <p><strong>Submission Date:</strong> ' . date('F j, Y') . '</p>
                </div>
                <p>Our team will review your request and get back to you within 3-5 business days.</p>
                <p><a href="http://localhost/isonga-mis/student/financial_aid.php">View your request</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    // Use sendEmail function instead of sendEmailCore
    if (function_exists('sendEmail')) {
        $email_result = sendEmail($student_email, $subject, $body);
        if ($email_result['success']) {
            $email_sent = true;
            $email_message = " A confirmation email has been sent to $student_email";
        } else {
            error_log("Failed to send email to student: " . ($email_result['message'] ?? 'Unknown error'));
            $email_message = " However, we couldn't send the confirmation email.";
        }
    } else {
        error_log("sendEmail function not available");
        $email_message = " Email notification not available.";
    }
} else {
    $email_message = " Please update your email address in your profile to receive notifications.";
}
            
            // 2. Send notification to finance officers about new request
            $finance_notification_sent = false;
            $finance_message = "";
            
            // Get all finance officers from database
            try {
                $finance_stmt = $pdo->prepare("
                    SELECT id, email, full_name 
                    FROM users 
                    WHERE role = 'vice_guild_finance' AND status = 'active'
                ");
                $finance_stmt->execute();
                $finance_officers = $finance_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($finance_officers)) {
                    $urgency_colors = [
                        'low' => '#28a745',
                        'medium' => '#ffc107',
                        'high' => '#fd7e14',
                        'emergency' => '#dc3545'
                    ];
                    $color = $urgency_colors[$urgency_level] ?? '#6c757d';
                    
                    $subject = "⚠️ URGENT: New Student Aid Request #$request_id";
                    
                    $body = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
                            .alert { background: #fff3cd; border-left: 4px solid ' . $color . '; padding: 15px; margin: 15px 0; }
                            .urgency-badge { display: inline-block; padding: 3px 8px; background: ' . $color . '; color: white; border-radius: 3px; font-size: 12px; font-weight: bold; }
                            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
                            .btn { display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <div class="header">
                                <h2>🚨 New Student Aid Request</h2>
                            </div>
                            <div class="content">
                                <p>Dear Finance Officer,</p>
                                <div class="alert">
                                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                                    <p><strong>Student:</strong> ' . htmlspecialchars($student_name) . ' (' . htmlspecialchars($reg_number) . ')</p>
                                    <p><strong>Amount:</strong> <strong style="color: #dc3545;">RWF ' . number_format($amount_requested, 2) . '</strong></p>
                                    <p><strong>Urgency Level:</strong> <span class="urgency-badge">' . strtoupper($urgency_level) . '</span></p>
                                    <p><strong>Purpose:</strong> ' . htmlspecialchars(substr($purpose, 0, 200)) . '</p>
                                    <p><strong>Submitted:</strong> ' . date('F j, Y g:i a') . '</p>
                                </div>
                                <p><a href="http://localhost/isonga-mis/student_aid.php?view=' . $request_id . '" class="btn">🔍 Review Request</a></p>
                                <p>Please review this request and take appropriate action.</p>
                            </div>
                            <div class="footer">
                                <p>Isonga - RPSU Management System</p>
                                <p>RP Musanze College Student Union</p>
                            </div>
                        </div>
                    </body>
                    </html>';
                    
                    // Send to each finance officer
                    $sent_count = 0;
                    foreach ($finance_officers as $officer) {
                        if (!empty($officer['email'])) {
                            $result = sendEmail($officer['email'], $subject, $body);
                            if ($result['success']) {
                                $sent_count++;
                                error_log("Finance notification sent to: " . $officer['email']);
                            } else {
                                error_log("Failed to send to finance officer: " . $officer['email'] . " - " . ($result['message'] ?? 'Unknown'));
                            }
                        }
                    }
                    
                    if ($sent_count > 0) {
                        $finance_notification_sent = true;
                        $finance_message = " Notification sent to $sent_count finance officer(s).";
                    } else {
                        $finance_message = " Could not send notification to finance officers.";
                    }
                } else {
                    error_log("No active finance officers found in database");
                    $finance_message = " No finance officers found to notify.";
                }
            } catch (PDOException $e) {
                error_log("Failed to get finance officers: " . $e->getMessage());
                $finance_message = " Could not notify finance officers due to system error.";
            }
            
            // 3. Create system notification for finance officers
            try {
                $notify_stmt = $pdo->prepare("
                    INSERT INTO system_notifications (user_id, notification_type, title, message, related_id, related_table, created_at, expires_at)
                    SELECT id, 'urgent', 'New Student Aid Request', 
                    CONCAT('Student ', ?, ' has submitted a new financial aid request (ID: #', ?, ') for RWF ', ?),
                    ?, 'student_financial_aid', NOW(), NOW() + INTERVAL '30 days'
                    FROM users 
                    WHERE role = 'vice_guild_finance' AND status = 'active'
                ");
                $notify_stmt->execute([
                    $student_name,
                    $request_id,
                    number_format($amount_requested, 2),
                    $request_id
                ]);
                $notification_count = $notify_stmt->rowCount();
                error_log("Created $notification_count system notifications for finance officers");
            } catch (PDOException $e) {
                error_log("Failed to create system notifications: " . $e->getMessage());
            }
            
            $_SESSION['success_message'] = "✅ Financial aid request submitted successfully! Request ID: #$request_id." . $email_message . $finance_message;
            header('Location: financial_aid.php');
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Database error: " . $e->getMessage());
            $error_message = "Failed to submit financial aid request. Please try again.";
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error: " . $e->getMessage());
            $error_message = $e->getMessage();
        }
    }
}

// Get student's financial aid requests
try {
    $requests_stmt = $pdo->prepare("
        SELECT * FROM student_financial_aid 
        WHERE student_id = ? 
        ORDER BY created_at DESC
    ");
    $requests_stmt->execute([$student_id]);
    $financial_aid_requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch requests: " . $e->getMessage());
    $financial_aid_requests = [];
}

// Get financial aid statistics
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'disbursed' THEN 1 ELSE 0 END) as disbursed,
            SUM(amount_requested) as total_requested,
            SUM(amount_approved) as total_approved
        FROM student_financial_aid 
        WHERE student_id = ?
    ");
    $stats_stmt->execute([$student_id]);
    $financial_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch stats: " . $e->getMessage());
    $financial_stats = [
        'total' => 0,
        'submitted' => 0,
        'under_review' => 0,
        'approved' => 0,
        'rejected' => 0,
        'disbursed' => 0,
        'total_requested' => 0,
        'total_approved' => 0
    ];
}

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Aid - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* All CSS styles remain the same as in the previous version */
        :root {
            --booking-blue: #003b95;
            --booking-blue-light: #006ce4;
            --booking-green: #00a699;
            --booking-orange: #ff5a5f;
            --booking-yellow: #ffb400;
            --booking-gray-50: #f7f7f7;
            --booking-gray-100: #ebebeb;
            --booking-gray-200: #d8d8d8;
            --booking-gray-300: #b0b0b0;
            --booking-gray-400: #717171;
            --booking-gray-500: #2d2d2d;
            --booking-white: #ffffff;
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.16);
            --transition: all 0.2s ease;
        }

        [data-theme="dark"] {
            --booking-gray-50: #1a1a1a;
            --booking-gray-100: #2d2d2d;
            --booking-gray-200: #404040;
            --booking-gray-300: #666666;
            --booking-gray-400: #999999;
            --booking-gray-500: #ffffff;
            --booking-white: #2d2d2d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
            padding: 0 2rem;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }

        .logo-image {
            height: 36px;
            width: auto;
            object-fit: contain;
        }

        .logo-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--booking-blue);
            letter-spacing: -0.5px;
        }

        [data-theme="dark"] .logo-text {
            color: white;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--booking-gray-50);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .theme-toggle-btn {
            background: none;
            border: 1px solid var(--booking-gray-200);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .theme-toggle-btn:hover {
            border-color: var(--booking-blue);
            color: var(--booking-blue);
        }

        .logout-btn {
            background: none;
            border: 1px solid var(--booking-gray-200);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
            text-decoration: none;
        }

        .logout-btn:hover {
            border-color: var(--booking-orange);
            color: var(--booking-orange);
        }

        /* Navigation */
        .nav-container {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .main-nav {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .nav-links {
            display: flex;
            gap: 0;
            list-style: none;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--booking-gray-500);
            font-weight: 500;
            font-size: 0.9rem;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .nav-link:hover {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue-light);
        }

        .nav-link.active {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue);
            font-weight: 600;
        }

        .nav-link i {
            font-size: 0.85rem;
            width: 18px;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Page Header */
        .page-header {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--booking-blue);
        }

        .page-description {
            color: var(--booking-gray-400);
            margin-bottom: 1.5rem;
        }

        .header-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
        }

        .stats-summary {
            display: flex;
            gap: 1.5rem;
            font-size: 0.9rem;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .stat-label {
            color: var(--booking-gray-400);
            font-size: 0.8rem;
        }

        .stat-value {
            font-weight: 600;
            color: var(--booking-gray-500);
        }

        .stat-value.total {
            color: var(--booking-blue);
        }

        .stat-value.approved {
            color: var(--booking-green);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--booking-gray-200);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.total { background: #e6f2ff; color: var(--booking-blue); }
        .stat-icon.submitted { background: #e6ffe6; color: var(--booking-green); }
        .stat-icon.review { background: #fff8e6; color: var(--booking-orange); }
        .stat-icon.approved { background: #e6fff6; color: #00b894; }
        .stat-icon.disbursed { background: #f0f0f0; color: var(--booking-gray-400); }

        .stat-content h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
            font-weight: 500;
        }

        /* Dashboard Grid */
        .dashboard-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            padding: 1.5rem 1.5rem 0;
            border-bottom: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--booking-blue);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Requests List */
        .requests-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .request-item {
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--booking-blue);
            padding: 1.25rem;
            transition: var(--transition);
        }

        .request-item:hover {
            background: var(--booking-white);
            box-shadow: var(--shadow-sm);
            transform: translateX(2px);
        }

        .request-item.urgent {
            border-left-color: var(--booking-orange);
        }

        .request-item.approved {
            border-left-color: var(--booking-green);
        }

        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .request-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-500);
        }

        .request-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        .request-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-submitted { background: #e6ffe6; color: var(--booking-green); }
        .status-under_review { background: #fff8e6; color: var(--booking-orange); }
        .status-approved { background: #e6fff6; color: #00b894; }
        .status-rejected { background: #ffe6e6; color: var(--booking-orange); }
        .status-disbursed { background: #f0f0f0; color: var(--booking-gray-400); }

        .request-details {
            margin: 1rem 0;
        }

        .request-purpose {
            color: var(--booking-gray-400);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .request-amounts {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .amount-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .amount-label {
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        .amount-value {
            font-weight: 600;
            font-size: 1rem;
        }

        .amount-value.requested {
            color: var(--booking-orange);
        }

        .amount-value.approved {
            color: var(--booking-green);
        }

        .request-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--booking-blue);
            color: white;
            border: 1px solid var(--booking-blue);
        }

        .btn-primary:hover {
            background: var(--booking-blue-light);
            border-color: var(--booking-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 107, 228, 0.2);
        }

        .btn-secondary {
            background: var(--booking-white);
            color: var(--booking-gray-500);
            border: 1px solid var(--booking-gray-200);
        }

        .btn-secondary:hover {
            background: var(--booking-gray-50);
            border-color: var(--booking-gray-300);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Primary Action Button */
        .primary-action-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--booking-blue);
            color: white;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 107, 228, 0.3);
            transition: var(--transition);
            z-index: 90;
        }

        .primary-action-btn:hover {
            background: var(--booking-blue-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 107, 228, 0.4);
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--booking-gray-500);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            max-height: calc(90vh - 120px);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--booking-gray-500);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--booking-gray-200);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--booking-blue-light);
            background: rgba(0, 107, 228, 0.02);
        }

        .file-upload i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-300);
        }

        .file-upload p {
            margin-bottom: 0.25rem;
            color: var(--booking-gray-400);
        }

        .file-upload small {
            font-size: 0.75rem;
            color: var(--booking-gray-300);
        }

        .file-list {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--booking-blue);
        }

        .file-input {
            display: none;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid;
            background: var(--booking-white);
        }

        .alert-success {
            border-color: var(--booking-green);
            background: #f0fffc;
            color: var(--booking-green);
        }

        .alert-error {
            border-color: var(--booking-orange);
            background: #fff5f5;
            color: var(--booking-orange);
        }

        .alert-info {
            border-color: var(--booking-blue);
            background: #e6f2ff;
            color: var(--booking-blue);
        }

        .alert i {
            font-size: 1rem;
            margin-top: 0.125rem;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--booking-gray-400);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h4 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-400);
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header { padding: 0 1rem; }
            .main-nav { padding: 0 1rem; }
            .nav-links { overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 0.5rem; }
            .nav-link { padding: 1rem; font-size: 0.85rem; }
            .main-content { padding: 1rem; }
            .page-header { padding: 1.5rem; }
            .page-title { font-size: 1.5rem; }
            .header-actions-row { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .stats-summary { width: 100%; justify-content: space-between; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 1rem; }
            .request-header { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .request-meta { flex-wrap: wrap; }
            .request-amounts { flex-direction: column; gap: 0.75rem; }
            .request-actions { flex-wrap: wrap; }
            .primary-action-btn { bottom: 1rem; right: 1rem; width: 48px; height: 48px; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .user-name, .user-role { display: none; }
            .form-actions { flex-direction: column; }
            .request-actions .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="dashboard.php" class="logo">
            <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo-image">
            <div class="logo-text">Isonga</div>
        </a>
        
        <div class="header-actions">
            <form method="POST" style="margin: 0;">
                <button type="submit" name="toggle_theme" class="theme-toggle-btn" title="Toggle Theme">
                    <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                </button>
            </form>
            
            <a href="../auth/logout.php" class="logout-btn" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
            
            <div class="user-menu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></span>
                    <span class="user-role">Student</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="nav-container">
        <div class="main-nav">
            <ul class="nav-links">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></li>
                <li class="nav-item"><a href="tickets.php" class="nav-link"><i class="fas fa-ticket-alt"></i> My Tickets</a></li>
                <li class="nav-item"><a href="financial_aid.php" class="nav-link active"><i class="fas fa-hand-holding-usd"></i> Financial Aid</a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
                <li class="nav-item"><a href="announcements.php" class="nav-link"><i class="fas fa-bullhorn"></i> Announcements</a></li>
                <?php if ($is_class_rep): ?>
                <li class="nav-item"><a href="class_rep_dashboard.php" class="nav-link"><i class="fas fa-users"></i> Class Rep</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-hand-holding-usd"></i> Financial Aid Requests</h1>
            <p class="page-description">Submit and track your financial aid applications. You'll receive email confirmation for every request submitted.</p>
            
            <div class="header-actions-row">
                <div class="stats-summary">
                    <div class="stat-item"><span class="stat-label">Total Requests</span><span class="stat-value total"><?php echo $financial_stats['total'] ?? 0; ?></span></div>
                    <div class="stat-item"><span class="stat-label">Total Requested</span><span class="stat-value"><?php echo number_format($financial_stats['total_requested'] ?? 0, 2); ?> Rwf</span></div>
                    <div class="stat-item"><span class="stat-label">Total Approved</span><span class="stat-value approved"><?php echo number_format($financial_stats['total_approved'] ?? 0, 2); ?> Rwf</span></div>
                </div>
                <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> New Request</button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Email Info Alert -->
        <div class="alert alert-info">
            <i class="fas fa-envelope"></i>
            <div>
                <strong>Email Notifications</strong><br>
                You will receive a confirmation email for every request you submit. Please ensure your email address 
                (<?php echo !empty($student_email) ? safe_display($student_email) : 'Not set'; ?>) is correct.
                <?php if (empty($student_email)): ?>
                    <a href="profile.php" style="color: var(--booking-blue);">Update your email here</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-header"><div class="stat-icon total"><i class="fas fa-file-alt"></i></div><div class="stat-content"><h3><?php echo $financial_stats['total'] ?? 0; ?></h3><p>Total Requests</p></div></div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-icon submitted"><i class="fas fa-clock"></i></div><div class="stat-content"><h3><?php echo $financial_stats['submitted'] ?? 0; ?></h3><p>Submitted</p></div></div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-icon review"><i class="fas fa-spinner"></i></div><div class="stat-content"><h3><?php echo $financial_stats['under_review'] ?? 0; ?></h3><p>Under Review</p></div></div></div>
            <div class="stat-card"><div class="stat-header"><div class="stat-icon approved"><i class="fas fa-check-circle"></i></div><div class="stat-content"><h3><?php echo $financial_stats['approved'] ?? 0; ?></h3><p>Approved</p></div></div></div>
        </div>

        <!-- Requests List -->
        <div class="dashboard-card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-history"></i> My Financial Aid Requests</h3></div>
            <div class="card-body">
                <div class="requests-list">
                    <?php if (empty($financial_aid_requests)): ?>
                        <div class="empty-state"><i class="fas fa-hand-holding-usd"></i><h4>No financial aid requests yet</h4><p>Submit your first financial aid request to get started</p><button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> Submit First Request</button></div>
                    <?php else: ?>
                        <?php foreach ($financial_aid_requests as $request): ?>
                            <div class="request-item <?php echo $request['urgency_level'] === 'emergency' || $request['urgency_level'] === 'high' ? 'urgent' : ''; ?>">
                                <div class="request-header">
                                    <div>
                                        <div class="request-title"><?php echo safe_display($request['request_title']); ?></div>
                                        <div class="request-meta">
                                            <span>Submitted: <?php echo date('M j, Y', strtotime($request['created_at'])); ?></span>
                                            <span>Urgency: <span class="request-status status-<?php echo str_replace('_', '-', $request['urgency_level']); ?>"><?php echo ucfirst($request['urgency_level']); ?></span></span>
                                        </div>
                                    </div>
                                    <div class="request-status status-<?php echo str_replace('_', '-', $request['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?></div>
                                </div>
                                <div class="request-details">
                                    <p class="request-purpose"><?php echo safe_display($request['purpose']); ?></p>
                                    <div class="request-amounts">
                                        <div class="amount-item"><span class="amount-label">Amount Requested</span><span class="amount-value requested"><?php echo number_format($request['amount_requested'], 2); ?> Rwf</span></div>
                                        <?php if ($request['amount_approved']): ?>
                                        <div class="amount-item"><span class="amount-label">Amount Approved</span><span class="amount-value approved"><?php echo number_format($request['amount_approved'], 2); ?> Rwf</span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="request-actions">
                                        <a href="view_financial_aid.php?id=<?php echo $request['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View Details</a>
                                        <?php if ($request['status'] === 'approved'): ?>
                                            <a href="generate_approval_letter.php?id=<?php echo $request['id']; ?>" class="btn btn-success btn-sm"><i class="fas fa-download"></i> Approval Letter</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Primary Action Button -->
    <a href="#" class="primary-action-btn" onclick="openRequestModal(event)"><i class="fas fa-plus"></i></a>

    <!-- Request Modal -->
    <div id="requestModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-hand-holding-usd"></i> Submit Financial Aid Request</h3>
                <button class="modal-close" onclick="closeRequestModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="financialAidForm">
                    <div class="form-group"><label class="form-label">Request Title *</label><input type="text" name="request_title" class="form-control" placeholder="e.g., Tuition Fee Assistance" required></div>
                    <div class="form-group"><label class="form-label">Amount Requested (Rwf) *</label><input type="number" name="amount_requested" class="form-control" step="0.01" min="0" placeholder="0.00" required></div>
                    <div class="form-group"><label class="form-label">Urgency Level *</label><select name="urgency_level" class="form-control" required><option value="low">Low - Can wait up to 2 weeks</option><option value="medium" selected>Medium - Within 1 week</option><option value="high">High - Within 3 days</option><option value="emergency">Emergency - Immediate attention needed</option></select></div>
                    <div class="form-group"><label class="form-label">Purpose and Justification *</label><textarea name="purpose" class="form-control" placeholder="Explain why you need financial assistance..." required rows="4"></textarea></div>
                    <div class="form-group"><label class="form-label">Request Letter (Optional)</label><div class="file-upload" onclick="document.getElementById('request_letter').click()"><input type="file" name="request_letter" id="request_letter" class="file-input" accept=".pdf,.doc,.docx,.txt"><i class="fas fa-upload"></i><p>Click to upload request letter</p><small>PDF, DOC, DOCX, TXT (Max: 5MB)</small><div id="request_letter_name" class="file-list"></div></div></div>
                    <div class="form-group"><label class="form-label">Supporting Documents (Optional)</label><div class="file-upload" onclick="document.getElementById('supporting_docs').click()"><input type="file" name="supporting_docs" id="supporting_docs" class="file-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"><i class="fas fa-file-upload"></i><p>Click to upload supporting documents</p><small>PDF, Images, DOC (Max: 10MB)</small><div id="supporting_docs_name" class="file-list"></div></div></div>
                    <div class="form-actions"><button type="button" class="btn btn-secondary" onclick="closeRequestModal()">Cancel</button><button type="submit" name="submit_financial_aid" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button></div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openRequestModal(e) { if(e) e.preventDefault(); document.getElementById('requestModal').style.display = 'flex'; document.body.style.overflow = 'hidden'; }
        function closeRequestModal() { document.getElementById('requestModal').style.display = 'none'; document.getElementById('financialAidForm').reset(); document.getElementById('request_letter_name').textContent = ''; document.getElementById('supporting_docs_name').textContent = ''; document.body.style.overflow = 'auto'; }
        document.getElementById('request_letter').addEventListener('change', function(e) { const fileName = e.target.files[0]?.name || ''; document.getElementById('request_letter_name').textContent = fileName ? `Selected: ${fileName}` : ''; });
        document.getElementById('supporting_docs').addEventListener('change', function(e) { const fileName = e.target.files[0]?.name || ''; document.getElementById('supporting_docs_name').textContent = fileName ? `Selected: ${fileName}` : ''; });
        document.addEventListener('keydown', function(event) { if (event.key === 'Escape') closeRequestModal(); });
        document.getElementById('requestModal').addEventListener('click', function(event) { if (event.target === this) closeRequestModal(); });
        <?php if (isset($error_message)): ?> document.addEventListener('DOMContentLoaded', function() { setTimeout(() => { openRequestModal(); }, 500); }); <?php endif; ?>
        if (window.history.replaceState) window.history.replaceState(null, null, window.location.href);
    </script>
</body>
</html>