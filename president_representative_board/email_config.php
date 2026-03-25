<?php
// representative_board/email_config.php
// Representative Board email functions

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send notification about new class rep report
 */
function sendRepBoardNewReport($report_id, $class_rep_name, $class, $report_type) {
    $subject = "New Class Representative Report Submitted - #$report_id";
    
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
                <h2>📊 New Class Rep Report</h2>
            </div>
            <div class="content">
                <p>Dear Representative Board Member,</p>
                <p>A new report has been submitted by Class Representative.</p>
                <p><strong>Report ID:</strong> #' . $report_id . '</p>
                <p><strong>Class Rep:</strong> ' . htmlspecialchars($class_rep_name) . '</p>
                <p><strong>Class:</strong> ' . htmlspecialchars($class) . '</p>
                <p><strong>Report Type:</strong> ' . htmlspecialchars($report_type) . '</p>
                <p><a href="http://localhost/isonga-mis/representative/reports.php?view=' . $report_id . '">Review Report</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRoles([
        'president_representative_board',
        'vice_president_representative_board',
        'secretary_representative_board'
    ], $subject, $body);
}
?>