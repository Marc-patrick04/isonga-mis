<?php
// minister_template.php
// Template for all minister email configurations
// Copy this to each minister directory and customize as needed

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send notification about new initiative proposal
 */
function sendMinisterNewInitiative($initiative_id, $title, $description, $budget) {
    $subject = "New Initiative Proposal: $title";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .initiative-details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>🎯 New Initiative Proposal</h2>
            </div>
            <div class="content">
                <p>Dear Minister,</p>
                <div class="initiative-details">
                    <p><strong>Initiative ID:</strong> #' . $initiative_id . '</p>
                    <p><strong>Title:</strong> ' . htmlspecialchars($title) . '</p>
                    <p><strong>Budget Request:</strong> RWF ' . number_format($budget, 2) . '</p>
                    <p><strong>Description:</strong></p>
                    <p>' . nl2br(htmlspecialchars($description)) . '</p>
                </div>
                <p><a href="http://localhost/isonga-mis/initiatives.php?view=' . $initiative_id . '">Review Initiative</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole(str_replace('minister_', '', basename(dirname(__FILE__))), $subject, $body);
}

/**
 * Send notification about event approval
 */
function sendMinisterEventApproval($event_id, $event_name, $event_date, $status, $feedback) {
    $subject = "Event Approval Status: $event_name";
    
    $status_colors = [
        'approved' => '#28a745',
        'rejected' => '#dc3545',
        'pending' => '#ffc107'
    ];
    $color = $status_colors[$status] ?? '#6c757d';
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: ' . $color . '; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📅 Event Approval Update</h2>
            </div>
            <div class="content">
                <p>Dear Minister,</p>
                <p>Your event request has been <strong>' . strtoupper($status) . '</strong>.</p>
                <p><strong>Event ID:</strong> #' . $event_id . '</p>
                <p><strong>Event Name:</strong> ' . htmlspecialchars($event_name) . '</p>
                <p><strong>Event Date:</strong> ' . date('F j, Y', strtotime($event_date)) . '</p>
                <p><strong>Feedback:</strong> ' . nl2br(htmlspecialchars($feedback)) . '</p>
                <p><a href="http://localhost/isonga-mis/events.php?view=' . $event_id . '">View Event Details</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailToRole(str_replace('minister_', '', basename(dirname(__FILE__))), $subject, $body);
}
?>