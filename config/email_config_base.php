<?php
// config/email_config_base.php
// Base email configuration for all roles

// Global email settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'marcpatrick004@gmail.com');
define('SMTP_FROM_NAME', 'Isonga RPSU Management System');
define('SMTP_USER', 'marcpatrick004@gmail.com');
define('SMTP_PASSWORD', 'mcgjkmjmccphpsvz'); // App password without spaces

// PHPMailer includes - Check multiple possible paths
$phpmailer_paths = [
    __DIR__ . '/../includes/PHPMailer/src/Exception.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php',
    __DIR__ . '/../lib/PHPMailer/src/Exception.php'
];

$phpmailer_found = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        require_once str_replace('Exception.php', 'Exception.php', $path);
        require_once str_replace('Exception.php', 'PHPMailer.php', $path);
        require_once str_replace('Exception.php', 'SMTP.php', $path);
        $phpmailer_found = true;
        break;
    }
}

if (!$phpmailer_found) {
    // Fallback: try to include if using composer autoload
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $phpmailer_found = true;
    }
}

if (!$phpmailer_found) {
    error_log("PHPMailer not found! Please install via composer or download manually.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Core email sending function (renamed from sendEmailCore for compatibility)
 */
function sendEmail($to, $subject, $body, $altBody = '') {
    // Check if PHPMailer is available
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer not available, falling back to mail() function");
        return sendEmailWithMail($to, $subject, $body, $altBody);
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Add timeout and keepalive settings
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = true;
        
        // Debug mode (set to 0 for production, 2 for debugging)
        $mail->SMTPDebug = 0;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);
        
        $mail->send();
        error_log("Email sent successfully to: $to - Subject: $subject");
        return ['success' => true, 'message' => 'Email sent successfully', 'to' => $to];
        
    } catch (Exception $e) {
        error_log("Email sending failed to $to: " . $mail->ErrorInfo);
        // Fallback to mail() function
        return sendEmailWithMail($to, $subject, $body, $altBody);
    }
}

/**
 * Fallback email function using PHP mail()
 */
function sendEmailWithMail($to, $subject, $body, $altBody = '') {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">" . "\r\n";
    
    $result = mail($to, $subject, $body, $headers);
    
    if ($result) {
        error_log("Mail sent successfully to: $to using mail() function");
        return ['success' => true, 'message' => 'Email sent via mail()', 'to' => $to];
    } else {
        error_log("Mail failed to send to: $to using mail() function");
        return ['success' => false, 'message' => 'mail() function returned false', 'to' => $to];
    }
}

/**
 * Get role email from database
 */
function getRoleEmailFromDB($role) {
    global $pdo;
    
    try {
        // First try to get from users table (active users with that role)
        $stmt = $pdo->prepare("
            SELECT email, full_name 
            FROM users 
            WHERE role = ? AND status = 'active' AND email IS NOT NULL AND email != ''
            LIMIT 1
        ");
        $stmt->execute([$role]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && !empty($user['email'])) {
            return [
                'email' => $user['email'],
                'name' => $user['full_name']
            ];
        }
        
        // If no user found, try committee_members table
        $stmt = $pdo->prepare("
            SELECT email, name 
            FROM committee_members 
            WHERE role = ? AND status = 'active' AND email IS NOT NULL AND email != ''
            LIMIT 1
        ");
        $stmt->execute([$role]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member && !empty($member['email'])) {
            return [
                'email' => $member['email'],
                'name' => $member['name']
            ];
        }
        
        // Fallback to default
        error_log("No email found for role: $role, using default");
        return [
            'email' => SMTP_FROM_EMAIL,
            'name' => ucwords(str_replace('_', ' ', $role))
        ];
        
    } catch (PDOException $e) {
        error_log("Failed to get role email: " . $e->getMessage());
        return [
            'email' => SMTP_FROM_EMAIL,
            'name' => ucwords(str_replace('_', ' ', $role))
        ];
    }
}

/**
 * Get all users with specific role
 */
function getUsersByRole($role) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, full_name 
            FROM users 
            WHERE role = ? AND status = 'active' AND email IS NOT NULL AND email != ''
        ");
        $stmt->execute([$role]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to get users by role: " . $e->getMessage());
        return [];
    }
}

/**
 * Send email to specific role (fetches email from database)
 */
function sendEmailToRole($role, $subject, $body, $altBody = '') {
    $role_email = getRoleEmailFromDB($role);
    return sendEmail($role_email['email'], $subject, $body, $altBody);
}

/**
 * Send email to all users with specific role
 */
function sendEmailToAllRole($role, $subject, $body, $altBody = '') {
    $users = getUsersByRole($role);
    $results = [];
    
    if (empty($users)) {
        // If no users found, send to default email
        $default_email = getRoleEmailFromDB($role);
        $results['default'] = sendEmail($default_email['email'], $subject, $body, $altBody);
    } else {
        foreach ($users as $user) {
            if (!empty($user['email'])) {
                $result = sendEmail($user['email'], $subject, $body, $altBody);
                $results[$user['id']] = $result;
            }
        }
    }
    
    return $results;
}

/**
 * Send email to multiple roles
 */
function sendEmailToRoles($roles, $subject, $body, $altBody = '') {
    $results = [];
    foreach ($roles as $role) {
        $result = sendEmailToAllRole($role, $subject, $body, $altBody);
        $results[$role] = $result;
    }
    return $results;
}

/**
 * Send email to specific user by ID
 */
function sendEmailToUser($user_id, $subject, $body, $altBody = '') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && !empty($user['email'])) {
            return sendEmail($user['email'], $subject, $body, $altBody);
        }
        
        return ['success' => false, 'message' => 'User not found or no email'];
        
    } catch (PDOException $e) {
        error_log("Failed to send email to user: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create system notification
 */
function createNotification($user_id, $title, $message, $type = 'info', $related_id = null, $related_table = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_notifications (user_id, notification_type, title, message, related_id, related_table, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL '30 days')
        ");
        $stmt->execute([$user_id, $type, $title, $message, $related_id, $related_table]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for all users with specific role
 */
function createRoleNotification($role, $title, $message, $type = 'info', $related_id = null, $related_table = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = ? AND status = 'active'");
        $stmt->execute([$role]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($users as $user) {
            $results[] = createNotification($user['id'], $title, $message, $type, $related_id, $related_table);
        }
        
        return $results;
        
    } catch (PDOException $e) {
        error_log("Failed to create role notifications: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email to student with dynamic content
 */
function sendEmailToStudent($student_id, $subject, $message_body, $link = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student || empty($student['email'])) {
            return ['success' => false, 'message' => 'Student not found or no email'];
        }
        
        $link_html = '';
        if ($link) {
            $link_html = '<p><a href="' . htmlspecialchars($link) . '" style="display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 5px;">View Details</a></p>';
        }
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0056b3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
                .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>📢 Isonga Notification</h2>
                </div>
                <div class="content">
                    <p>Dear ' . htmlspecialchars($student['full_name']) . ',</p>
                    <p>' . nl2br(htmlspecialchars($message_body)) . '</p>
                    ' . $link_html . '
                </div>
                <div class="footer">
                    <p>Isonga - RPSU Management System</p>
                    <p>RP Musanze College Student Union</p>
                </div>
            </div>
        </body>
        </html>';
        
        return sendEmail($student['email'], $subject, $body);
        
    } catch (PDOException $e) {
        error_log("Failed to send email to student: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>