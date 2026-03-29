<?php
// vice_guild_finance/email_config.php
// Finance department-specific email functions

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send notification about new student aid request
 */
function sendFinanceNewAidRequest($request_id, $student_name, $student_reg, $amount, $urgency, $purpose) {
    $subject = "⚠️ URGENT: New Student Aid Request #$request_id";
    
    $urgency_colors = [
        'low' => '#28a745',
        'medium' => '#ffc107',
        'high' => '#fd7e14',
        'emergency' => '#dc3545'
    ];
    $color = $urgency_colors[$urgency] ?? '#6c757d';
    
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
                    <p><strong>Student:</strong> ' . htmlspecialchars($student_name) . ' (' . htmlspecialchars($student_reg) . ')</p>
                    <p><strong>Amount:</strong> <strong style="color: #dc3545;">RWF ' . number_format($amount, 2) . '</strong></p>
                    <p><strong>Urgency Level:</strong> <span class="urgency-badge">' . strtoupper($urgency) . '</span></p>
                    <p><strong>Purpose:</strong> ' . htmlspecialchars($purpose) . '</p>
                </div>
                <p><a href="http://localhost/isonga-mis/vice_guild_finance/view_student_aid.php?id=' . $request_id . '" style="display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 5px;">Review Request</a></p>
                <p style="margin-top: 15px;">Please review and take appropriate action.</p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('vice_guild_finance', $subject, $body);
}

/**
 * Send notification about new committee budget request
 */
function sendFinanceNewBudgetRequest($request_id, $committee, $amount, $purpose, $requested_by) {
    $subject = "💰 New Committee Budget Request #$request_id from $committee";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #ffc107; color: #333; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
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
                <p><strong>Amount:</strong> <strong style="color: #dc3545;">RWF ' . number_format($amount, 2) . '</strong></p>
                <p><strong>Requested by:</strong> ' . htmlspecialchars($requested_by) . '</p>
                <p><strong>Purpose:</strong> ' . htmlspecialchars($purpose) . '</p>
                <p><a href="http://localhost/isonga-mis/vice_guild_finance/committee_requests.php?view=' . $request_id . '">Review Request</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('vice_guild_finance', $subject, $body);
}

/**
 * Send notification when transaction is ready for president approval
 */
function sendFinanceTransactionReadyForPresident($transaction_id, $description, $amount, $requester) {
    $subject = "📋 Transaction #$transaction_id Ready for President Approval";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #17a2b8; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📋 Transaction Ready for Approval</h2>
            </div>
            <div class="content">
                <p>Dear President,</p>
                <p>Transaction #' . $transaction_id . ' has been reviewed by Finance and is ready for your approval.</p>
                <p><strong>Description:</strong> ' . htmlspecialchars($description) . '</p>
                <p><strong>Amount:</strong> RWF ' . number_format($amount, 2) . '</p>
                <p><strong>Requested by:</strong> ' . htmlspecialchars($requester) . '</p>
                <p><a href="http://localhost/isonga-mis/vice_guild_finance/transactions.php?approve=' . $transaction_id . '">Approve or Reject</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('guild_president', $subject, $body);
}

/**
 * Send budget alert when nearing limit
 */
function sendFinanceBudgetAlert($category, $allocated, $spent, $percentage) {
    $subject = "⚠️ Budget Alert: $category at $percentage% Utilization";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #ffc107; color: #333; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>⚠️ Budget Alert</h2>
            </div>
            <div class="content">
                <p>Dear Finance Officer,</p>
                <p>The budget for <strong>' . htmlspecialchars($category) . '</strong> has reached <strong>' . $percentage . '%</strong> utilization.</p>
                <p><strong>Allocated:</strong> RWF ' . number_format($allocated, 2) . '</p>
                <p><strong>Spent:</strong> RWF ' . number_format($spent, 2) . '</p>
                <p><strong>Remaining:</strong> RWF ' . number_format($allocated - $spent, 2) . '</p>
                <p><a href="http://localhost/isonga-mis/vice_guild_finance/budget_management.php">Review Budget</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('vice_guild_finance', $subject, $body);
}

/**
 * Send notification to Guild President for aid approval
 */
function sendPresidentAidApprovalRequest($request_id, $student_name, $student_reg, $amount, $review_notes, $finance_officer) {
    $subject = "📋 Action Required: Student Aid Request #$request_id Pending Your Approval";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1976D2; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .highlight { background: #f0f8ff; padding: 15px; margin: 15px 0; border-left: 4px solid #1976D2; }
            .button { display: inline-block; padding: 10px 20px; background: #1976D2; color: white; text-decoration: none; border-radius: 5px; margin: 10px 0; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>🎓 Student Aid Request - President Approval Required</h2>
            </div>
            <div class="content">
                <p>Dear Guild President,</p>
                <p>The Vice Guild Finance has reviewed and approved a student aid request. Your final approval is required before disbursement.</p>
                
                <div class="highlight">
                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                    <p><strong>Student:</strong> ' . htmlspecialchars($student_name) . ' (' . htmlspecialchars($student_reg) . ')</p>
                    <p><strong>Approved Amount:</strong> <strong style="color: #1976D2;">RWF ' . number_format($amount, 2) . '</strong></p>
                    <p><strong>Reviewed by Finance:</strong> ' . htmlspecialchars($finance_officer) . '</p>
                    <p><strong>Finance Comments:</strong> ' . nl2br(htmlspecialchars($review_notes)) . '</p>
                </div>
                
                <p><a href="http://localhost/isonga-mis/guild_president/pending_aid_requests.php?view=' . $request_id . '" class="button">Review Request</a></p>
                <p><a href="http://localhost/isonga-mis/guild_president/pending_aid_requests.php?approve=' . $request_id . '" class="button" style="background: #28a745;">Approve</a>
                <a href="http://localhost/isonga-mis/guild_president/pending_aid_requests.php?reject=' . $request_id . '" class="button" style="background: #dc3545;">Reject</a></p>
                
                <p>Please review this request at your earliest convenience.</p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('guild_president', $subject, $body);
}

/**
 * Send notification to student about disbursement
 */
function sendFinancialAidDisbursementNotification($student_email, $student_name, $request_id, $title, $amount, $notes, $finance_officer) {
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
                <p>Dear ' . htmlspecialchars($student_name) . ',</p>
                <p>We are pleased to inform you that your financial aid request has been processed and the funds have been disbursed.</p>
                
                <div class="highlight">
                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                    <p><strong>Request Title:</strong> ' . htmlspecialchars($title) . '</p>
                    <p><strong>Amount Disbursed:</strong> <strong style="color: #28a745;">RWF ' . number_format($amount, 2) . '</strong></p>
                    <p><strong>Processed By:</strong> ' . htmlspecialchars($finance_officer) . '</p>
                    <p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($notes)) . '</p>
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
    
    return sendEmail($student_email, $subject, $body);
}

/**
 * Send notification to student about rejection
 */
function sendFinancialAidRejectionNotification($student_email, $student_name, $request_id, $title, $reason, $finance_officer) {
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
                <h2>📋 Update on Your Financial Aid Request</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($student_name) . ',</p>
                <p>Thank you for submitting your financial aid request. After careful review, we regret to inform you that your request could not be approved at this time.</p>
                
                <div class="highlight">
                    <p><strong>Request ID:</strong> #' . $request_id . '</p>
                    <p><strong>Request Title:</strong> ' . htmlspecialchars($title) . '</p>
                    <p><strong>Reason for Rejection:</strong></p>
                    <p>' . nl2br(htmlspecialchars($reason)) . '</p>
                    <p><strong>Reviewed By:</strong> ' . htmlspecialchars($finance_officer) . '</p>
                </div>
                
                <p>If you have questions about this decision or wish to reapply with additional documentation, please contact the Vice Guild Finance office.</p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmail($student_email, $subject, $body);
}
?>