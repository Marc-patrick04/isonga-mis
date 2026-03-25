<?php
// arbitration/email_config.php
// Arbitration committee email functions

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send notification about new arbitration case
 */
function sendArbitrationNewCase($case_id, $case_title, $complainant, $respondent, $description) {
    $subject = "New Arbitration Case #$case_id: $case_title";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .case-details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #dc3545; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>⚖️ New Arbitration Case</h2>
            </div>
            <div class="content">
                <p>Dear Arbitration Committee Member,</p>
                <div class="case-details">
                    <p><strong>Case ID:</strong> #' . $case_id . '</p>
                    <p><strong>Title:</strong> ' . htmlspecialchars($case_title) . '</p>
                    <p><strong>Complainant:</strong> ' . htmlspecialchars($complainant) . '</p>
                    <p><strong>Respondent:</strong> ' . htmlspecialchars($respondent) . '</p>
                    <p><strong>Description:</strong></p>
                    <p>' . nl2br(htmlspecialchars($description)) . '</p>
                </div>
                <p><a href="http://localhost/isonga-mis/arbitration/cases.php?view=' . $case_id . '">Review Case</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    // Send to all arbitration roles
    return sendEmailToRoles([
        'president_arbitration',
        'vice_president_arbitration',
        'secretary_arbitration',
        'advisor_arbitration'
    ], $subject, $body);
}

/**
 * Send hearing notification to involved parties
 */
function sendArbitrationHearingNotice($case_id, $case_title, $hearing_date, $hearing_time, $location, $parties) {
    $subject = "Arbitration Hearing Scheduled - Case #$case_id";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0056b3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .hearing-details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #0056b3; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>⚖️ Hearing Scheduled</h2>
            </div>
            <div class="content">
                <p>Dear Parties,</p>
                <div class="hearing-details">
                    <p><strong>Case ID:</strong> #' . $case_id . '</p>
                    <p><strong>Case Title:</strong> ' . htmlspecialchars($case_title) . '</p>
                    <p><strong>Hearing Date:</strong> ' . date('l, F j, Y', strtotime($hearing_date)) . '</p>
                    <p><strong>Hearing Time:</strong> ' . htmlspecialchars($hearing_time) . '</p>
                    <p><strong>Location:</strong> ' . htmlspecialchars($location) . '</p>
                </div>
                <p>Please ensure your presence at the scheduled hearing.</p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    // Send to all parties involved
    foreach ($parties as $party) {
        sendEmailCore($party['email'], $subject, $body);
    }
    
    return true;
}
?>