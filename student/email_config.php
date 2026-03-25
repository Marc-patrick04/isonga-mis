<?php
// student/email_config.php
// Student-specific email functions

require_once __DIR__ . '/../config/email_config_base.php';

/**
 * Send confirmation email to student after submitting request
 * @param int $student_id - The student's user ID
 * @param string $request_type - Type of request (Financial Aid, Ticket, etc.)
 * @param int $request_id - The request ID
 * @param array $details_array - Additional details to display
 * @return array - Result of email sending
 */
function sendStudentSubmissionConfirmation($student_id, $request_type, $request_id, $details_array = []) {
    global $pdo;
    
    try {
        // Get student details from database
        $stmt = $pdo->prepare("
            SELECT email, full_name, reg_number 
            FROM users 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student || empty($student['email'])) {
            error_log("Student not found or no email for ID: $student_id");
            return ['success' => false, 'message' => 'Student not found or no email'];
        }
        
        $subject = "Your $request_type Request Has Been Received - #$request_id";
        
        // Build details HTML
        $details_html = '';
        foreach ($details_array as $label => $value) {
            $details_html .= '<p><strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars($value) . '</p>';
        }
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Request Confirmation</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                .container {
                    max-width: 600px;
                    margin: 20px auto;
                    padding: 0;
                    background-color: #ffffff;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #0056b3 0%, #0d47a1 100%);
                    color: white;
                    padding: 30px 20px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 10px 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px 25px;
                }
                .greeting {
                    font-size: 18px;
                    margin-bottom: 20px;
                }
                .request-details {
                    background-color: #f8f9fa;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                    border-left: 4px solid #28a745;
                }
                .detail-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #e9ecef;
                }
                .detail-label {
                    font-weight: 600;
                    color: #495057;
                }
                .detail-value {
                    color: #212529;
                }
                .status-box {
                    background-color: #e8f5e9;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 20px 0;
                    text-align: center;
                }
                .status-box p {
                    margin: 0;
                    color: #2e7d32;
                }
                .footer {
                    background-color: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    font-size: 12px;
                    color: #6c757d;
                    border-top: 1px solid #e9ecef;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background-color: #0056b3;
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                    margin-top: 20px;
                }
                .btn:hover {
                    background-color: #003d82;
                }
                @media (max-width: 600px) {
                    .content {
                        padding: 20px;
                    }
                    .detail-row {
                        flex-direction: column;
                    }
                    .detail-value {
                        margin-top: 5px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>✅ Request Received</h1>
                    <p>Isonga - RPSU Management System</p>
                </div>
                
                <div class="content">
                    <div class="greeting">
                        Dear <strong>' . htmlspecialchars($student['full_name']) . '</strong>,
                    </div>
                    
                    <p>Thank you for submitting your ' . $request_type . ' request. We have successfully received your application and it is now being processed by our team.</p>
                    
                    <div class="request-details">
                        <h3 style="margin-top: 0; margin-bottom: 15px;">Request Details</h3>
                        <div class="detail-row">
                            <span class="detail-label">Request ID:</span>
                            <span class="detail-value"><strong>#' . $request_id . '</strong></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Type:</span>
                            <span class="detail-value">' . htmlspecialchars($request_type) . '</span>
                        </div>
                        ' . $details_html . '
                        <div class="detail-row">
                            <span class="detail-label">Submission Date:</span>
                            <span class="detail-value">' . date('F j, Y, g:i a') . '</span>
                        </div>
                    </div>
                    
                    <div class="status-box">
                        <p>✅ <strong>Status: Submitted</strong></p>
                        <p style="margin-top: 5px; font-size: 14px;">Your request is currently under review by the committee.</p>
                    </div>
                    
                    <p>If you have any questions or need to provide additional information, please contact the RPSU Office:</p>
                    <p>
                        📧 <a href="mailto:iprcmusanzesu@gmail.com">iprcmusanzesu@gmail.com</a><br>
                        📞 +250 788 123 456<br>
                        🏢 RPSU Office, RP Musanze College
                    </p>
                    
                    <div style="text-align: center;">
                        <a href="http://localhost/isonga-mis/student/financial_aid.php" class="btn">📊 Track Your Request</a>
                    </div>
                </div>
                
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' Isonga - RPSU Management System. All rights reserved.</p>
                    <p>Rwanda Polytechnic Musanze College Student Union</p>
                    <p style="font-size: 11px;">This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>';
        
        return sendEmailCore($student['email'], $subject, $body);
        
    } catch (PDOException $e) {
        error_log("Failed to send student confirmation: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send status update notification to student
 * @param int $student_id - The student's user ID
 * @param string $request_type - Type of request
 * @param int $request_id - The request ID
 * @param string $status - New status
 * @param string $notes - Review notes
 * @param float|null $amount_approved - Approved amount (if applicable)
 * @return array - Result of email sending
 */
function sendStudentStatusUpdate($student_id, $request_type, $request_id, $status, $notes, $amount_approved = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT email, full_name 
            FROM users 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student || empty($student['email'])) {
            error_log("Student not found or no email for ID: $student_id");
            return ['success' => false, 'message' => 'Student not found or no email'];
        }
        
        $subject = "Your $request_type Request #$request_id - Status: " . ucfirst(str_replace('_', ' ', $status));
        
        $status_colors = [
            'approved' => '#28a745',
            'rejected' => '#dc3545',
            'completed' => '#17a2b8',
            'in_progress' => '#ffc107',
            'under_review' => '#ffc107',
            'disbursed' => '#17a2b8'
        ];
        $color = $status_colors[$status] ?? '#6c757d';
        
        $status_icons = [
            'approved' => '✅',
            'rejected' => '❌',
            'completed' => '🎉',
            'in_progress' => '⏳',
            'under_review' => '📋',
            'disbursed' => '💰'
        ];
        $icon = $status_icons[$status] ?? '📢';
        
        $amount_html = '';
        if ($amount_approved) {
            $amount_html = '<div class="detail-row">
                <span class="detail-label">Amount Approved:</span>
                <span class="detail-value"><strong style="color: #28a745;">RWF ' . number_format($amount_approved, 2) . '</strong></span>
            </div>';
        }
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Request Status Update</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                .container {
                    max-width: 600px;
                    margin: 20px auto;
                    padding: 0;
                    background-color: #ffffff;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: ' . $color . ';
                    color: white;
                    padding: 30px 20px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .content {
                    padding: 30px 25px;
                }
                .greeting {
                    font-size: 18px;
                    margin-bottom: 20px;
                }
                .status-badge {
                    display: inline-block;
                    padding: 10px 20px;
                    border-radius: 25px;
                    font-size: 16px;
                    font-weight: 600;
                    text-align: center;
                    margin: 20px 0;
                    background-color: ' . $color . ';
                    color: white;
                }
                .request-details {
                    background-color: #f8f9fa;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                    border-left: 4px solid ' . $color . ';
                }
                .detail-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #e9ecef;
                }
                .detail-label {
                    font-weight: 600;
                    color: #495057;
                }
                .detail-value {
                    color: #212529;
                }
                .review-notes {
                    background-color: #fff3e0;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 20px 0;
                    border-left: 3px solid #ff9800;
                }
                .footer {
                    background-color: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    font-size: 12px;
                    color: #6c757d;
                    border-top: 1px solid #e9ecef;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background-color: #0056b3;
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                    margin-top: 20px;
                }
                @media (max-width: 600px) {
                    .content {
                        padding: 20px;
                    }
                    .detail-row {
                        flex-direction: column;
                    }
                    .detail-value {
                        margin-top: 5px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . $icon . ' Request ' . ucfirst(str_replace('_', ' ', $status)) . '</h1>
                </div>
                
                <div class="content">
                    <div class="greeting">
                        Dear <strong>' . htmlspecialchars($student['full_name']) . '</strong>,
                    </div>
                    
                    <p>Your ' . $request_type . ' request has been updated to <strong>' . strtoupper(str_replace('_', ' ', $status)) . '</strong> status.</p>
                    
                    <div style="text-align: center;">
                        <div class="status-badge">
                            ' . $icon . ' Status: ' . ucfirst(str_replace('_', ' ', $status)) . '
                        </div>
                    </div>
                    
                    <div class="request-details">
                        <h3 style="margin-top: 0; margin-bottom: 15px;">Request Details</h3>
                        <div class="detail-row">
                            <span class="detail-label">Request ID:</span>
                            <span class="detail-value"><strong>#' . $request_id . '</strong></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Type:</span>
                            <span class="detail-value">' . htmlspecialchars($request_type) . '</span>
                        </div>
                        ' . $amount_html . '
                        <div class="detail-row">
                            <span class="detail-label">Update Date:</span>
                            <span class="detail-value">' . date('F j, Y, g:i a') . '</span>
                        </div>
                    </div>
                    
                    <div class="review-notes">
                        <p><strong>📝 Review Notes:</strong></p>
                        <p style="margin-top: 10px;">' . nl2br(htmlspecialchars($notes)) . '</p>
                    </div>
                    
                    <p>You can track the status of your request from your student dashboard.</p>
                    
                    <div style="text-align: center;">
                        <a href="http://localhost/isonga-mis/student/financial_aid.php" class="btn">📊 Track Your Request</a>
                    </div>
                </div>
                
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' Isonga - RPSU Management System. All rights reserved.</p>
                    <p>Rwanda Polytechnic Musanze College Student Union</p>
                </div>
            </div>
        </body>
        </html>';
        
        return sendEmailCore($student['email'], $subject, $body);
        
    } catch (PDOException $e) {
        error_log("Failed to send status update: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>