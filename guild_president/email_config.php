<?php
// guild_president/email_config.php
// Guild President-specific email functions

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send notification about pending approval
 */
function sendPresidentPendingApproval($transaction_id, $description, $amount, $requester, $finance_notes = null) {
    $subject = "⏰ ACTION REQUIRED: Transaction #$transaction_id Pending Your Approval";
    
    $finance_notes_html = '';
    if ($finance_notes) {
        $finance_notes_html = '<p><strong>Finance Notes:</strong> ' . htmlspecialchars($finance_notes) . '</p>';
    }
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
            .button { display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 10px; }
            .button-danger { background: #dc3545; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>⏰ Presidential Approval Required</h2>
            </div>
            <div class="content">
                <p>Dear President,</p>
                <div class="alert">
                    <p><strong>Transaction #' . $transaction_id . '</strong> requires your approval.</p>
                    <p><strong>Description:</strong> ' . htmlspecialchars($description) . '</p>
                    <p><strong>Amount:</strong> <strong style="color: #dc3545;">RWF ' . number_format($amount, 2) . '</strong></p>
                    <p><strong>Requested by:</strong> ' . htmlspecialchars($requester) . '</p>
                    ' . $finance_notes_html . '
                </div>
                <p>Please review this transaction:</p>
                <p>
                    <a href="http://localhost/isonga-mis/transactions.php?approve=' . $transaction_id . '" class="button">✅ Approve Transaction</a>
                    <a href="http://localhost/isonga-mis/transactions.php?reject=' . $transaction_id . '" class="button button-danger">❌ Reject Transaction</a>
                </p>
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
 * Send notification about new budget request
 */
function sendPresidentNewBudgetRequest($request_id, $committee, $amount, $purpose, $finance_approved_amount = null) {
    $subject = "📋 New Budget Request #$request_id Requires Your Review";
    
    $finance_html = '';
    if ($finance_approved_amount) {
        $finance_html = '<p><strong>Finance Recommended Amount:</strong> RWF ' . number_format($finance_approved_amount, 2) . '</p>';
    }
    
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
                <h2>📋 Budget Request for Review</h2>
            </div>
            <div class="content">
                <p>Dear President,</p>
                <p>A budget request requires your final approval:</p>
                <p><strong>Request ID:</strong> #' . $request_id . '</p>
                <p><strong>Committee:</strong> ' . htmlspecialchars($committee) . '</p>
                <p><strong>Amount Requested:</strong> RWF ' . number_format($amount, 2) . '</p>
                ' . $finance_html . '
                <p><strong>Purpose:</strong> ' . htmlspecialchars($purpose) . '</p>
                <p><a href="http://localhost/isonga-mis/committee_requests.php?view=' . $request_id . '">Review Request</a></p>
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
 * Send monthly report to president
 */
function sendPresidentMonthlyReport($month, $year, $summary_data) {
    $subject = "Monthly Financial Report - $month $year";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0056b3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .stats { background: #f8f9fa; padding: 15px; margin: 15px 0; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📊 Monthly Financial Report</h2>
                <p>' . $month . ' ' . $year . '</p>
            </div>
            <div class="content">
                <p>Dear President,</p>
                <p>Here is your monthly financial summary:</p>
                <div class="stats">
                    <p><strong>Total Income:</strong> RWF ' . number_format($summary_data['total_income'], 2) . '</p>
                    <p><strong>Total Expenses:</strong> RWF ' . number_format($summary_data['total_expenses'], 2) . '</p>
                    <p><strong>Net Balance:</strong> RWF ' . number_format($summary_data['net_balance'], 2) . '</p>
                    <p><strong>Pending Approvals:</strong> ' . $summary_data['pending_approvals'] . '</p>
                </div>
                <p><a href="http://localhost/isonga-mis/financial_reports.php">View Full Report</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole('guild_president', $subject, $body);
}
?>