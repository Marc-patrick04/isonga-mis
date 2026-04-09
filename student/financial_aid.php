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

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$student_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
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
                        <div class="header"><h2>✅ Financial Aid Request Received</h2></div>
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
                            <p><a href="http://localhost/isonga-mis/student/financial_aid">View your request</a></p>
                        </div>
                        <div class="footer"><p>Isonga - RPSU Management System</p></div>
                    </div>
                </body>
                </html>';
                
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
            
            // 2. Send notification to finance officers
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
                            <div class="header"><h2>🚨 New Student Aid Request</h2></div>
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
                                <p><a href="http://localhost/isonga-mis/admin/student_aid?view=' . $request_id . '" class="btn">🔍 Review Request</a></p>
                                <p>Please review this request and take appropriate action.</p>
                            </div>
                            <div class="footer"><p>Isonga - RPSU Management System</p></div>
                        </div>
                    </body>
                    </html>';
                    
                    $sent_count = 0;
                    foreach ($finance_officers as $officer) {
                        if (!empty($officer['email'])) {
                            $result = sendEmail($officer['email'], $subject, $body);
                            if ($result['success']) {
                                $sent_count++;
                            }
                        }
                    }
                    $finance_message = $sent_count > 0 ? " Notification sent to $sent_count finance officer(s)." : " Could not send notification.";
                } else {
                    $finance_message = " No finance officers found to notify.";
                }
            } catch (PDOException $e) {
                error_log("Failed to get finance officers: " . $e->getMessage());
                $finance_message = " Could not notify finance officers.";
            }
            
            // 3. Create system notifications
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
            } catch (PDOException $e) {
                error_log("Failed to create system notifications: " . $e->getMessage());
            }
            
            $_SESSION['success_message'] = "✅ Financial aid request submitted successfully! Request ID: #$request_id." . $email_message . $finance_message;
            header('Location: financial_aid');
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

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Financial Aid - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #3B82F6;
            --secondary-blue: #60A5FA;
            --accent-blue: #1D4ED8;
            --light-blue: #EFF6FF;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        [data-theme="dark"] {
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
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
            color: var(--primary-blue);
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
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
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
            background: var(--primary-blue);
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
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
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

        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--primary-blue);
        }

        .page-description {
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .header-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }

        .stats-summary {
            display: flex;
            gap: 1.5rem;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
        }

        .stat-value {
            font-weight: 600;
            font-size: 1rem;
        }

        .stat-value.total {
            color: var(--primary-blue);
        }

        .stat-value.approved {
            color: var(--success);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-icon.total { background: var(--light-blue); color: var(--primary-blue); }
        .stat-icon.submitted { background: #d4edda; color: var(--success); }
        .stat-icon.review { background: #fff3cd; color: #856404; }
        .stat-icon.approved { background: #cce7ff; color: var(--primary-blue); }

        .stat-content h3 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Dashboard Card */
        .dashboard-card {
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
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-dark);
        }

        .card-title i {
            color: var(--primary-blue);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Requests List */
        .requests-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .request-item {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-blue);
            padding: 1rem;
            transition: var(--transition);
        }

        .request-item:hover {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            transform: translateX(2px);
        }

        .request-item.urgent {
            border-left-color: var(--danger);
        }

        .request-item.approved {
            border-left-color: var(--success);
        }

        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .request-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .request-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--dark-gray);
            flex-wrap: wrap;
        }

        .request-status {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-submitted { background: #d4edda; color: #155724; }
        .status-under_review { background: #fff3cd; color: #856404; }
        .status-approved { background: #cce7ff; color: #004085; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-disbursed { background: #e2e3e5; color: #383d41; }
        .status-low { background: #d4edda; color: #155724; }
        .status-medium { background: #fff3cd; color: #856404; }
        .status-high { background: #f8d7da; color: #721c24; }
        .status-emergency { background: #dc3545; color: white; }

        .request-details {
            margin-top: 0.75rem;
        }

        .request-purpose {
            color: var(--dark-gray);
            font-size: 0.8rem;
            line-height: 1.5;
            margin-bottom: 0.75rem;
        }

        .request-amounts {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }

        .amount-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .amount-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .amount-value {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .amount-value.requested {
            color: var(--danger);
        }

        .amount-value.approved {
            color: var(--success);
        }

        .request-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        /* Primary Action Button */
        .primary-action-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--gradient-primary);
            color: white;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            text-decoration: none;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            z-index: 90;
            border: none;
            cursor: pointer;
        }

        .primary-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
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
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 550px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
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
            color: var(--dark-gray);
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--light-gray);
        }

        .modal-body {
            padding: 1.25rem;
            overflow-y: auto;
            max-height: calc(90vh - 120px);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .file-upload i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--dark-gray);
        }

        .file-upload p {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .file-upload small {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .file-list {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--primary-blue);
        }

        .file-input {
            display: none;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.8rem;
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
            border-left-color: var(--primary-blue);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .empty-state p {
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        /* Overlay */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(2px);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        /* Responsive */
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
                background: var(--primary-blue);
                color: white;
            }
        }

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

            .header-actions-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-summary {
                width: 100%;
                justify-content: space-between;
            }

            .request-header {
                flex-direction: column;
            }
        }

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

            .primary-action-btn {
                bottom: 1rem;
                right: 1rem;
                width: 48px;
                height: 48px;
            }

            .form-actions {
                flex-direction: column;
            }

            .request-amounts {
                flex-direction: column;
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
                <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo">
                <div class="brand-text">
                    <h1>Isonga RPSU</h1>
                </div>
            </div>
            <div class="user-menu">
                <form method="POST" style="margin: 0;">
                    <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                        <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                    </button>
                </form>
                <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                    <i class="fas fa-envelope"></i>
                    <?php if ($unread_messages > 0): ?>
                        <span class="notification-badge"><?php echo $unread_messages; ?></span>
                    <?php endif; ?>
                </a>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></div>
                        <div class="user-role">Student</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>My Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_aid.php" class="active">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Financial Aid</span>
                        <?php if (($financial_stats['submitted'] ?? 0) > 0): ?>
                            <span class="menu-badge"><?php echo $financial_stats['submitted']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
                <?php if ($is_class_rep): ?>
                <li class="menu-item">
                    <a href="class_rep_dashboard.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Dashboard</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-hand-holding-usd"></i> Financial Aid Requests</h1>
                <p class="page-description">Apply for financial assistance and track your requests</p>
                
                <div class="header-actions-row">
                    <div class="stats-summary">
                        <div class="stat-item">
                            <span class="stat-label">Total Requests</span>
                            <span class="stat-value total"><?php echo $financial_stats['total'] ?? 0; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total Requested</span>
                            <span class="stat-value"><?php echo number_format($financial_stats['total_requested'] ?? 0, 0); ?> Rwf</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total Approved</span>
                            <span class="stat-value approved"><?php echo number_format($financial_stats['total_approved'] ?? 0, 0); ?> Rwf</span>
                        </div>
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
            <?php if (empty($student_email)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-envelope"></i>
                    <div>Please <a href="profile.php" style="color: var(--primary-blue);">update your email address</a> to receive notifications about your financial aid requests.</div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-content"><h3><?php echo $financial_stats['total'] ?? 0; ?></h3><p>Total Requests</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon submitted"><i class="fas fa-clock"></i></div>
                    <div class="stat-content"><h3><?php echo $financial_stats['submitted'] ?? 0; ?></h3><p>Submitted</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon review"><i class="fas fa-spinner"></i></div>
                    <div class="stat-content"><h3><?php echo $financial_stats['under_review'] ?? 0; ?></h3><p>Under Review</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon approved"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content"><h3><?php echo ($financial_stats['approved'] ?? 0) + ($financial_stats['disbursed'] ?? 0); ?></h3><p>Approved/Disbursed</p></div>
                </div>
            </div>

            <!-- Requests List -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-history"></i> My Financial Aid Requests</h3>
                </div>
                <div class="card-body">
                    <div class="requests-list">
                        <?php if (empty($financial_aid_requests)): ?>
                            <div class="empty-state">
                                <i class="fas fa-hand-holding-usd"></i>
                                <h4>No financial aid requests yet</h4>
                                <p>Submit your first financial aid request to get started</p>
                                <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> Submit First Request</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($financial_aid_requests as $request): ?>
                                <div class="request-item <?php echo $request['urgency_level'] === 'emergency' || $request['urgency_level'] === 'high' ? 'urgent' : ''; ?> <?php echo $request['status'] === 'approved' ? 'approved' : ''; ?>">
                                    <div class="request-header">
                                        <div>
                                            <div class="request-title"><?php echo safe_display($request['request_title']); ?></div>
                                            <div class="request-meta">
                                                <span>Submitted: <?php echo date('M j, Y', strtotime($request['created_at'])); ?></span>
                                                <span>Urgency: <span class="request-status status-<?php echo str_replace('_', '-', $request['urgency_level']); ?>"><?php echo ucfirst($request['urgency_level']); ?></span></span>
                                            </div>
                                        </div>
                                        <div class="request-status status-<?php echo str_replace('_', '-', $request['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                        </div>
                                    </div>
                                    <div class="request-details">
                                        <p class="request-purpose"><?php echo safe_display(substr($request['purpose'], 0, 150)) . (strlen($request['purpose']) > 150 ? '...' : ''); ?></p>
                                        <div class="request-amounts">
                                            <div class="amount-item">
                                                <span class="amount-label">Amount Requested</span>
                                                <span class="amount-value requested"><?php echo number_format($request['amount_requested'], 0); ?> Rwf</span>
                                            </div>
                                            <?php if ($request['amount_approved']): ?>
                                            <div class="amount-item">
                                                <span class="amount-label">Amount Approved</span>
                                                <span class="amount-value approved"><?php echo number_format($request['amount_approved'], 0); ?> Rwf</span>
                                            </div>
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
    </div>

    <!-- Primary Action Button -->
    <button class="primary-action-btn" onclick="openRequestModal()"><i class="fas fa-plus"></i></button>

    <!-- Request Modal -->
    <div id="requestModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-hand-holding-usd"></i> Submit Financial Aid Request</h3>
                <button class="modal-close" onclick="closeRequestModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="financialAidForm">
                    <div class="form-group">
                        <label class="form-label">Request Title *</label>
                        <input type="text" name="request_title" class="form-control" placeholder="e.g., Tuition Fee Assistance" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Requested (Rwf) *</label>
                        <input type="number" name="amount_requested" class="form-control" step="1000" min="0" placeholder="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Urgency Level *</label>
                        <select name="urgency_level" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purpose and Justification *</label>
                        <textarea name="purpose" class="form-control" placeholder="Explain why you need financial assistance..." required rows="4"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Request Letter (Optional)</label>
                        <div class="file-upload" onclick="document.getElementById('request_letter').click()">
                            <i class="fas fa-upload"></i>
                            <p>Click to upload request letter</p>
                            <small>PDF, DOC, DOCX, TXT (Max: 5MB)</small>
                            <input type="file" name="request_letter" id="request_letter" class="file-input" accept=".pdf,.doc,.docx,.txt">
                            <div id="request_letter_name" class="file-list"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Supporting Documents (Optional)</label>
                        <div class="file-upload" onclick="document.getElementById('supporting_docs').click()">
                            <i class="fas fa-file-upload"></i>
                            <p>Click to upload supporting documents</p>
                            <small>PDF, Images, DOC (Max: 10MB)</small>
                            <input type="file" name="supporting_docs" id="supporting_docs" class="file-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div id="supporting_docs_name" class="file-list"></div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeRequestModal()">Cancel</button>
                        <button type="submit" name="submit_financial_aid" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
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
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Modal functions
        function openRequestModal() {
            document.getElementById('requestModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeRequestModal() {
            document.getElementById('requestModal').style.display = 'none';
            document.getElementById('financialAidForm').reset();
            document.getElementById('request_letter_name').textContent = '';
            document.getElementById('supporting_docs_name').textContent = '';
            document.body.style.overflow = 'auto';
        }

        // File upload handlers
        document.getElementById('request_letter').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            document.getElementById('request_letter_name').textContent = fileName ? `Selected: ${fileName}` : '';
        });

        document.getElementById('supporting_docs').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            document.getElementById('supporting_docs_name').textContent = fileName ? `Selected: ${fileName}` : '';
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') closeRequestModal();
        });

        // Close modal when clicking outside
        document.getElementById('requestModal').addEventListener('click', function(event) {
            if (event.target === this) closeRequestModal();
        });

        <?php if (isset($error_message)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(() => { openRequestModal(); }, 500);
            });
        <?php endif; ?>

        if (window.history.replaceState) window.history.replaceState(null, null, window.location.href);

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>