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
                <p><a href="http://localhost/isonga-mis/student_aid.php?view=' . $request_id . '" style="display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 5px;">Review Request</a></p>
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
                <p><a href="http://localhost/isonga-mis/committee_requests.php?view=' . $request_id . '">Review Request</a></p>
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
                <p><a href="http://localhost/isonga-mis/transactions.php?approve=' . $transaction_id . '">Approve or Reject</a></p>
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
                <p><a href="http://localhost/isonga-mis/budget_management.php">Review Budget</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('vice_guild_finance', $subject, $body);
}
?>