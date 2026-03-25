<?php
// system/email_config.php
// System-wide email functions for all roles

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send notification to specific role
 */
function sendRoleNotification($role, $subject, $message, $link = null) {
    $role_email = getRoleEmail($role);
    
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
                <h2>📢 System Notification</h2>
            </div>
            <div class="content">
                <p>Dear ' . $role_email['name'] . ',</p>
                <p>' . nl2br(htmlspecialchars($message)) . '</p>
                ' . $link_html . '
            </div>
            <div class="footer">
                <p>Isonga - RPSU Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmailCore($role_email['email'], $subject, $body);
}

/**
 * Send notification to multiple roles
 */
function sendBulkRoleNotification($roles, $subject, $message, $link = null) {
    $results = [];
    foreach ($roles as $role) {
        $results[$role] = sendRoleNotification($role, $subject, $message, $link);
    }
    return $results;
}

/**
 * Send notification to all executive roles
 */
function sendToAllExecutives($subject, $message, $link = null) {
    $executive_roles = [
        'guild_president',
        'vice_guild_academic',
        'vice_guild_finance',
        'general_secretary'
    ];
    return sendBulkRoleNotification($executive_roles, $subject, $message, $link);
}

/**
 * Send notification to all ministers
 */
function sendToAllMinisters($subject, $message, $link = null) {
    $minister_roles = [
        'minister_sports',
        'minister_environment',
        'minister_public_relations',
        'minister_health',
        'minister_culture',
        'minister_gender'
    ];
    return sendBulkRoleNotification($minister_roles, $subject, $message, $link);
}

/**
 * Send notification to all committee members
 */
function sendToAllCommitteeMembers($subject, $message, $link = null) {
    $committee_roles = [
        'president_representative_board',
        'vice_president_representative_board',
        'secretary_representative_board',
        'president_arbitration',
        'vice_president_arbitration',
        'secretary_arbitration',
        'advisor_arbitration'
    ];
    return sendBulkRoleNotification($committee_roles, $subject, $message, $link);
}

/**
 * Send system notification to all active users
 */
function sendToAllUsers($subject, $message, $link = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT id, email, full_name FROM users WHERE status = 'active'");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($users as $user) {
            $results[] = sendEmailCore($user['email'], $subject, str_replace('{name}', $user['full_name'], $message), '');
        }
        
        return $results;
    } catch (PDOException $e) {
        error_log("Failed to send to all users: " . $e->getMessage());
        return false;
    }
}
?>