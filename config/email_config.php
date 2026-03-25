<?php
// config/email_config.php
// Main email configuration that includes base and role-specific functions

require_once __DIR__ . '/email_config_base.php';

/**
 * Student Financial Aid - Notification Functions
 */
function sendStudentAidRequestNotification($student_name, $student_email, $request_id, $amount, $urgency) {
    $subject = "Financial Aid Request Received - #$request_id";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0056b3; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #0056b3; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Financial Aid Request Received</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($student_name) . ',</p>
                <p>Your financial aid request has been received successfully.</p>
                <div class="details">
                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                    <p><strong>Amount:</strong> RWF ' . number_format($amount, 2) . '</p>
                    <p><strong>Urgency:</strong> ' . strtoupper($urgency) . '</p>
                </div>
                <p>Our team will review your request and get back to you within 3-5 business days.</p>
                <p>You can track your request status from your dashboard.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($student_email, $subject, $body);
}

function sendNewAidRequestToFinance($request_id, $student_name, $amount, $urgency, $purpose) {
    $subject = "New Student Aid Request #$request_id - Action Required";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .alert { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>⚠️ New Student Aid Request</h2>
            </div>
            <div class="content">
                <p>Dear Finance Officer,</p>
                <div class="alert">
                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                    <p><strong>Student:</strong> ' . htmlspecialchars($student_name) . '</p>
                    <p><strong>Amount:</strong> RWF ' . number_format($amount, 2) . '</p>
                    <p><strong>Urgency:</strong> ' . strtoupper($urgency) . '</p>
                    <p><strong>Purpose:</strong> ' . htmlspecialchars(substr($purpose, 0, 100)) . '...</p>
                </div>
                <p><a href="http://localhost/isonga-mis/student_aid.php?view=' . $request_id . '">Click here to review this request</a></p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('vice_guild_finance', $subject, $body);
}

function sendAidStatusUpdateToStudent($student_email, $student_name, $request_id, $status, $notes, $amount_approved = null) {
    $subject = "Financial Aid Request #$request_id - Status Updated to " . ucfirst($status);
    
    $status_colors = [
        'approved' => '#28a745',
        'rejected' => '#dc3545',
        'disbursed' => '#17a2b8',
        'under_review' => '#ffc107'
    ];
    $color = $status_colors[$status] ?? '#6c757d';
    
    $amount_html = '';
    if ($amount_approved) {
        $amount_html = '<p><strong>Amount Approved:</strong> RWF ' . number_format($amount_approved, 2) . '</p>';
    }
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: ' . $color . '; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .notes { background: #f8f9fa; padding: 15px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Request Status Updated</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($student_name) . ',</p>
                <p>Your financial aid request #' . $request_id . ' has been <strong>' . strtoupper($status) . '</strong>.</p>
                ' . $amount_html . '
                <div class="notes">
                    <p><strong>Review Notes:</strong></p>
                    <p>' . nl2br(htmlspecialchars($notes)) . '</p>
                </div>
                <p><a href="http://localhost/isonga-mis/student/financial_aid.php">View your request</a></p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($student_email, $subject, $body);
}

/**
 * Committee Budget Request Functions
 */
function sendNewBudgetRequestToFinance($request_id, $committee, $amount, $purpose) {
    $subject = "New Budget Request #$request_id from $committee - Action Required";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #ffc107; color: #333; padding: 20px; text-align: center; }
            .content { padding: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>💰 New Budget Request</h2>
            </div>
            <div class="content">
                <p>Dear Finance Officer,</p>
                <p>A new budget request requires your review:</p>
                <p><strong>Request ID:</strong> #' . $request_id . '</p>
                <p><strong>Committee:</strong> ' . htmlspecialchars($committee) . '</p>
                <p><strong>Amount:</strong> RWF ' . number_format($amount, 2) . '</p>
                <p><strong>Purpose:</strong> ' . htmlspecialchars($purpose) . '</p>
                <p><a href="http://localhost/isonga-mis/committee_requests.php?view=' . $request_id . '">Review Request</a></p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('vice_guild_finance', $subject, $body);
}

/**
 * Transaction Approval Functions
 */
function sendTransactionPendingApproval($transaction_id, $description, $amount, $requester) {
    $subject = "Transaction #$transaction_id Requires Approval";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>⏰ Pending Approval Required</h2>
            </div>
            <div class="content">
                <p>A transaction requires your approval:</p>
                <p><strong>Transaction ID:</strong> #' . $transaction_id . '</p>
                <p><strong>Description:</strong> ' . htmlspecialchars($description) . '</p>
                <p><strong>Amount:</strong> RWF ' . number_format($amount, 2) . '</p>
                <p><strong>Requested by:</strong> ' . htmlspecialchars($requester) . '</p>
                <p><a href="http://localhost/isonga-mis/transactions.php?approve=' . $transaction_id . '">Approve/Reject</a></p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('guild_president', $subject, $body);
}

/**
 * General Notification Functions
 */
function sendWelcomeEmail($user_email, $user_name, $role) {
    $subject = "Welcome to Isonga RPSU Management System";
    
    $role_display = ucfirst(str_replace('_', ' ', $role));
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0056b3; color: white; padding: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Welcome to Isonga!</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($user_name) . ',</p>
                <p>Welcome to the Isonga RPSU Management System. Your account has been created with the role: <strong>' . $role_display . '</strong>.</p>
                <p>You can now log in and access your dashboard.</p>
                <p><a href="http://localhost/isonga-mis/auth/login.php">Click here to log in</a></p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($user_email, $subject, $body);
}

function sendPasswordResetEmail($user_email, $user_name, $reset_token) {
    $subject = "Password Reset Request - Isonga RPSU";
    
    $reset_link = "http://localhost/isonga-mis/auth/reset_password.php?token=" . $reset_token;
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #ffc107; color: #333; padding: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Password Reset Request</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($user_name) . ',</p>
                <p>We received a request to reset your password. Click the link below to create a new password:</p>
                <p><a href="' . $reset_link . '">' . $reset_link . '</a></p>
                <p>This link will expire in 24 hours.</p>
                <p>If you did not request this, please ignore this email.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($user_email, $subject, $body);
}

// Export functions for use in other files
function getEmailFunctions() {
    return [
        'sendStudentAidRequestNotification',
        'sendNewAidRequestToFinance',
        'sendAidStatusUpdateToStudent',
        'sendNewBudgetRequestToFinance',
        'sendTransactionPendingApproval',
        'sendWelcomeEmail',
        'sendPasswordResetEmail',
        'sendEmailCore',
        'sendEmailToRole',
        'sendEmailToRoles',
        'createNotification'
    ];
}
?>