<?php
// general_secretary/email_config.php
// General Secretary-specific email functions

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send meeting invitation
 */
function sendSecretaryMeetingInvite($attendee_email, $attendee_name, $meeting_title, $meeting_date, $meeting_time, $location, $agenda) {
    $subject = "Meeting Invitation: $meeting_title";
    
    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0056b3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; }
            .meeting-details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #0056b3; }
            .agenda { background: #fff3cd; padding: 15px; margin: 15px 0; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📅 Meeting Invitation</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($attendee_name) . ',</p>
                <p>You are invited to attend the following meeting:</p>
                <div class="meeting-details">
                    <p><strong>Title:</strong> ' . htmlspecialchars($meeting_title) . '</p>
                    <p><strong>Date:</strong> ' . date('l, F j, Y', strtotime($meeting_date)) . '</p>
                    <p><strong>Time:</strong> ' . htmlspecialchars($meeting_time) . '</p>
                    <p><strong>Location:</strong> ' . htmlspecialchars($location) . '</p>
                </div>
                <div class="agenda">
                    <p><strong>Agenda:</strong></p>
                    <p>' . nl2br(htmlspecialchars($agenda)) . '</p>
                </div>
                <p>Please confirm your attendance by replying to this email or through the system.</p>
                <p><a href="http://localhost/isonga-mis/meetings.php?confirm=1" style="display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;">Confirm Attendance</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
                <p>RP Musanze College Student Union</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($attendee_email, $subject, $body);
}

/**
 * Send meeting minutes
 */
function sendSecretaryMeetingMinutes($attendee_email, $attendee_name, $meeting_title, $meeting_date, $minutes, $action_items) {
    $subject = "Meeting Minutes: $meeting_title - $meeting_date";
    
    $action_items_html = '';
    if ($action_items && count($action_items) > 0) {
        $action_items_html = '<div class="action-items"><p><strong>Action Items:</strong></p><ul>';
        foreach ($action_items as $item) {
            $action_items_html .= '<li>' . htmlspecialchars($item['task']) . ' - Assigned to: ' . htmlspecialchars($item['assigned_to']) . ' (Due: ' . $item['due_date'] . ')</li>';
        }
        $action_items_html .= '</ul></div>';
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
            .minutes { background: #f8f9fa; padding: 15px; margin: 15px 0; }
            .action-items { background: #fff3cd; padding: 15px; margin: 15px 0; }
            .footer { padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📝 Meeting Minutes</h2>
            </div>
            <div class="content">
                <p>Dear ' . htmlspecialchars($attendee_name) . ',</p>
                <p>Please find attached the minutes from the meeting:</p>
                <p><strong>Meeting:</strong> ' . htmlspecialchars($meeting_title) . '</p>
                <p><strong>Date:</strong> ' . date('l, F j, Y', strtotime($meeting_date)) . '</p>
                <div class="minutes">
                    <p><strong>Minutes:</strong></p>
                    <p>' . nl2br(htmlspecialchars($minutes)) . '</p>
                </div>
                ' . $action_items_html . '
                <p><a href="http://localhost/isonga-mis/meetings.php?view=minutes" style="display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 5px;">View Full Minutes</a></p>
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($attendee_email, $subject, $body);
}
?>