<?php
session_start();
require_once '../config/database.php';
require_once '../config/email_config_base.php';

// Check if email_config exists before requiring
if (file_exists('email_config.php')) {
    require_once 'email_config.php';
} else {
    // Create a fallback email function if file doesn't exist
    if (!function_exists('sendEmail')) {
        function sendEmail($to, $subject, $body) {
            error_log("Email would be sent to: $to - Subject: $subject");
            return true;
        }
    }
}

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$student_email = $_SESSION['email'] ?? '';

// Get email from database if not in session
if (empty($student_email)) {
    try {
        $email_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $email_stmt->execute([$student_id]);
        $student_email = $email_stmt->fetchColumn();
        $_SESSION['email'] = $student_email;
    } catch (PDOException $e) {
        error_log("Failed to fetch email: " . $e->getMessage());
    }
}

$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: tickets.php');
    exit();
}

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$student_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Handle new ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $category_id = $_POST['category_id'] ?: null;
    $subject = trim($_POST['subject']);
    $description = trim($_POST['description']);
    $priority = $_POST['priority'] ?? 'medium';
    $preferred_contact = $_POST['preferred_contact'] ?? 'email';
    
    if (empty($subject) || empty($description)) {
        $error_message = "Subject and description are required.";
    } elseif (empty($category_id)) {
        $error_message = "Please select a category.";
    } else {
        $ticket_id = null;
        $assignee = null;
        $category_name = '';
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // First, get student details from users table
            $user_stmt = $pdo->prepare("
                SELECT reg_number, full_name, email, phone, department_id, program_id, academic_year 
                FROM users WHERE id = ?
            ");
            $user_stmt->execute([$student_id]);
            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user_data) {
                throw new Exception("Student data not found");
            }
            
            // Insert ticket
            $stmt = $pdo->prepare("
                INSERT INTO tickets (
                    reg_number, name, email, phone, department_id, program_id, 
                    academic_year, category_id, subject, description, priority, 
                    preferred_contact, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())
                RETURNING id
            ");
            
            $stmt->execute([
                $user_data['reg_number'],
                $user_data['full_name'],
                $user_data['email'],
                $user_data['phone'],
                $user_data['department_id'],
                $user_data['program_id'],
                $user_data['academic_year'],
                $category_id,
                $subject,
                $description,
                $priority,
                $preferred_contact
            ]);
            
            // Get the ticket ID (PostgreSQL way)
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $ticket_id = $result['id'];
            
            // Get category name for email
            $category_stmt = $pdo->prepare("SELECT name, assigned_role FROM issue_categories WHERE id = ?");
            $category_stmt->execute([$category_id]);
            $category_data = $category_stmt->fetch(PDO::FETCH_ASSOC);
            $category_name = $category_data['name'] ?? 'General';
            $assigned_role = $category_data['assigned_role'] ?? null;
            
            // Find staff to assign based on category's assigned_role or fallback to admin
            $assigned_to = null;
            $assignee = null;
            
            if (!empty($assigned_role)) {
                // Try to find staff with the specific role
                $findAssigneeStmt = $pdo->prepare("
                    SELECT u.id, u.email, u.full_name 
                    FROM users u
                    WHERE u.role = ? AND u.status = 'active'
                    LIMIT 1
                ");
                $findAssigneeStmt->execute([$assigned_role]);
                $assignee = $findAssigneeStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // If no staff found with specific role, assign to admin
            if (!$assignee) {
                $findAssigneeStmt = $pdo->prepare("
                    SELECT u.id, u.email, u.full_name 
                    FROM users u
                    WHERE u.role = 'admin' AND u.status = 'active'
                    LIMIT 1
                ");
                $findAssigneeStmt->execute();
                $assignee = $findAssigneeStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // If still no assignee, try committee members
            if (!$assignee) {
                $findAssigneeStmt = $pdo->prepare("
                    SELECT cm.user_id as id, u.email, u.full_name 
                    FROM committee_members cm
                    JOIN users u ON cm.user_id = u.id
                    WHERE cm.status = 'active'
                    LIMIT 1
                ");
                $findAssigneeStmt->execute();
                $assignee = $findAssigneeStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($assignee) {
                $assigned_to = $assignee['id'];
                $updateTicketStmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
                $updateTicketStmt->execute([$assigned_to, $ticket_id]);
                
                $assignStmt = $pdo->prepare("
                    INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                    VALUES (?, ?, ?, NOW(), 'Auto-assigned based on issue category')
                ");
                $assignStmt->execute([$ticket_id, $assigned_to, $student_id]);
            }
            
            // Commit the transaction BEFORE sending emails
            $pdo->commit();
            
            // ============================================
            // EMAIL SENDING - OUTSIDE TRANSACTION
            // ============================================
            // Get base URL for email links
            $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
            $base_url .= str_replace('student/tickets.php', '', $_SERVER['SCRIPT_NAME']);
            
            // 1. Send email to STUDENT who raised the ticket
            if (!empty($student_email) && function_exists('sendEmail')) {
                try {
                    $student_subject = "Ticket #$ticket_id: Support Request Received - Isonga RPSU";
                    $student_body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ticket Confirmation</title></head><body>
                    <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
                        <h2 style="color: #003b95;">Ticket #' . $ticket_id . ' Received</h2>
                        <p>Dear <strong>' . htmlspecialchars($student_name) . '</strong>,</p>
                        <p>Thank you for submitting your support request. Your ticket has been created and will be processed shortly.</p>
                        <div style="background: #f7f7f7; padding: 15px; border-radius: 8px; margin: 15px 0;">
                            <p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>
                            <p><strong>Category:</strong> ' . htmlspecialchars($category_name) . '</p>
                            <p><strong>Priority:</strong> ' . ucfirst($priority) . '</p>
                            <p><strong>Status:</strong> Open</p>
                        </div>
                        <p><a href="' . $base_url . 'student/tickets.php?view=' . $ticket_id . '" style="background: #003b95; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">View Your Ticket</a></p>
                        <hr style="margin: 20px 0;">
                        <p style="color: #666; font-size: 12px;">This is an automated message from Isonga RPSU Management System.</p>
                    </div></body></html>';
                    sendEmail($student_email, $student_subject, $student_body);
                    error_log("Student email sent to: $student_email for ticket #$ticket_id");
                } catch (Exception $e) {
                    error_log("Student email sending failed (non-critical): " . $e->getMessage());
                }
            }
            
            // 2. Send email to ASSIGNED STAFF member
            if ($assignee && !empty($assignee['email']) && function_exists('sendEmail')) {
                try {
                    $staff_subject = "New Support Ticket Assigned - #$ticket_id - Isonga RPSU";
                    $staff_body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>New Ticket Assignment</title></head><body>
                    <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
                        <h2 style="color: #003b95;">New Ticket Assigned: #' . $ticket_id . '</h2>
                        <p>Dear <strong>' . htmlspecialchars($assignee['full_name']) . '</strong>,</p>
                        <p>A new support ticket has been automatically assigned to you.</p>
                        <div style="background: #f7f7f7; padding: 15px; border-radius: 8px; margin: 15px 0;">
                            <p><strong>Ticket ID:</strong> #' . $ticket_id . '</p>
                            <p><strong>Student:</strong> ' . htmlspecialchars($student_name) . ' (' . htmlspecialchars($reg_number) . ')</p>
                            <p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>
                            <p><strong>Category:</strong> ' . htmlspecialchars($category_name) . '</p>
                            <p><strong>Priority:</strong> ' . ucfirst($priority) . '</p>
                            <p><strong>Description:</strong></p>
                            <p style="background: white; padding: 10px; border-radius: 5px;">' . nl2br(htmlspecialchars($description)) . '</p>
                        </div>
                        <p><a href="' . $base_url . 'admin/tickets.php?action=view&id=' . $ticket_id . '" style="background: #003b95; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">View & Respond to Ticket</a></p>
                        <hr style="margin: 20px 0;">
                        <p style="color: #666; font-size: 12px;">This is an automated message from Isonga RPSU Management System.</p>
                    </div></body></html>';
                    sendEmail($assignee['email'], $staff_subject, $staff_body);
                    error_log("Staff email sent to: {$assignee['email']} for ticket #$ticket_id");
                } catch (Exception $e) {
                    error_log("Staff email sending failed (non-critical): " . $e->getMessage());
                }
            } else {
                error_log("No assignee found for ticket #$ticket_id. Category: $category_name, Role: $assigned_role");
                
                // Try to send to general admin email as fallback
                $admin_email = "admin@isonga.rw";
                if (!empty($admin_email) && function_exists('sendEmail')) {
                    try {
                        $admin_subject = "New Ticket #$ticket_id - No Assignee Found";
                        $admin_body = '<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                            <h2>New Ticket Requires Manual Assignment</h2>
                            <p>A new ticket has been created but no automatic assignee was found.</p>
                            <div style="background: #f7f7f7; padding: 15px; border-radius: 8px;">
                                <p><strong>Ticket ID:</strong> #' . $ticket_id . '</p>
                                <p><strong>Student:</strong> ' . htmlspecialchars($student_name) . '</p>
                                <p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>
                                <p><strong>Category:</strong> ' . htmlspecialchars($category_name) . '</p>
                            </div>
                            <p>Please assign this ticket manually in the admin panel.</p>
                        </div>';
                        sendEmail($admin_email, $admin_subject, $admin_body);
                        error_log("Fallback admin email sent for ticket #$ticket_id");
                    } catch (Exception $e) {
                        error_log("Fallback admin email failed: " . $e->getMessage());
                    }
                }
            }
            
            $_SESSION['success_message'] = "✅ Ticket #$ticket_id submitted successfully!";
            header('Location: tickets.php');
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Ticket submission error: " . $e->getMessage());
            $error_message = "Failed to submit ticket: " . $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Database error in ticket submission: " . $e->getMessage());
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle adding comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $ticket_id = $_POST['ticket_id'];
    $comment = trim($_POST['comment']);
    $is_internal = 0;
    
    if (empty($comment)) {
        $error_message = "Comment cannot be empty.";
    } else {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$ticket_id, $student_id, $comment, $is_internal]);
            
            // Get ticket details for email notification
            $ticket_stmt = $pdo->prepare("
                SELECT t.*, u.email as assigned_to_email, u.full_name as assigned_to_name
                FROM tickets t
                LEFT JOIN users u ON t.assigned_to = u.id
                WHERE t.id = ?
            ");
            $ticket_stmt->execute([$ticket_id]);
            $ticket_info = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
            
            $pdo->commit();
            
            // Send email notification OUTSIDE transaction
            $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
            $base_url .= str_replace('student/tickets.php', '', $_SERVER['SCRIPT_NAME']);
            
            if (!empty($ticket_info['assigned_to_email']) && function_exists('sendEmail')) {
                try {
                    $staff_subject = "New Comment on Ticket #$ticket_id - Isonga RPSU";
                    $staff_body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>New Comment</title></head><body>
                    <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
                        <h2 style="color: #003b95;">New Comment on Ticket #' . $ticket_id . '</h2>
                        <p>Dear <strong>' . htmlspecialchars($ticket_info['assigned_to_name']) . '</strong>,</p>
                        <p><strong>' . htmlspecialchars($student_name) . '</strong> added a new comment on ticket #' . $ticket_id . ':</p>
                        <div style="background: #f7f7f7; padding: 15px; border-radius: 8px; margin: 15px 0;">' . nl2br(htmlspecialchars($comment)) . '</div>
                        <p><a href="' . $base_url . 'admin/tickets.php?action=view&id=' . $ticket_id . '" style="background: #003b95; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">View Ticket</a></p>
                        <hr style="margin: 20px 0;">
                        <p style="color: #666; font-size: 12px;">This is an automated message from Isonga RPSU Management System.</p>
                    </div></body></html>';
                    sendEmail($ticket_info['assigned_to_email'], $staff_subject, $staff_body);
                    error_log("Comment notification sent to: {$ticket_info['assigned_to_email']} for ticket #$ticket_id");
                } catch (Exception $e) {
                    error_log("Comment email sending failed: " . $e->getMessage());
                }
            }
            
            $_SESSION['success_message'] = "Comment added successfully!";
            header('Location: tickets.php?view=' . $ticket_id);
            exit();
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Failed to add comment: " . $e->getMessage());
            $error_message = "Failed to add comment: " . $e->getMessage();
        }
    }
}

// Handle filters and search
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$view_ticket_id = isset($_GET['view']) ? $_GET['view'] : null;

// Build query for tickets - PostgreSQL compatible
// Build query for tickets - PostgreSQL compatible
$query = "
    SELECT t.*, ic.name as category_name, 
           u.full_name as assigned_to_name, t.status as ticket_status,
           (CURRENT_DATE - t.created_at::date) as days_old
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.reg_number = ?
";

$params = [$reg_number];

if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($category_filter !== 'all') {
    $query .= " AND t.category_id = ?";
    $params[] = $category_filter;
}

if (!empty($search_query)) {
    $query .= " AND (t.subject ILIKE ? OR t.description ILIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY t.created_at DESC";

$tickets_stmt = $pdo->prepare($query);
$tickets_stmt->execute($params);
$tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get specific ticket for viewing
// Get specific ticket for viewing
$view_ticket = null;
$ticket_comments = [];
if ($view_ticket_id) {
    $view_stmt = $pdo->prepare("
        SELECT t.*, ic.name as category_name, 
               u.full_name as assigned_to_name, t.status as ticket_status,
               (CURRENT_DATE - t.created_at::date) as days_old
        FROM tickets t
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.id = ? AND t.reg_number = ?
    ");
    $view_stmt->execute([$view_ticket_id, $reg_number]);
    $view_ticket = $view_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($view_ticket) {
        $comments_stmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.role,
                   CASE WHEN tc.user_id = ? THEN 'me' ELSE 'them' END as comment_type
            FROM ticket_comments tc
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ");
        $comments_stmt->execute([$student_id, $view_ticket_id]);
        $ticket_comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get ticket statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
    FROM tickets 
    WHERE reg_number = ?
");
$stats_stmt->execute([$reg_number]);
$ticket_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get categories for filter and form
$categories_stmt = $pdo->prepare("SELECT * FROM issue_categories ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

// Convert days_old to integer for comparison in display
foreach ($tickets as &$ticket) {
    if (isset($ticket['days_old'])) {
        $ticket['days_old'] = (int)$ticket['days_old'];
    }
}
if ($view_ticket && isset($view_ticket['days_old'])) {
    $view_ticket['days_old'] = (int)$view_ticket['days_old'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Tickets - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #3B82F6;
            --secondary-blue: #60A5FA;
            --accent-blue: #1D4ED8;
            --light-blue: #EFF6FF;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        [data-theme="dark"] {
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; line-height: 1.5; color: var(--text-dark); background: var(--light-gray); min-height: 100vh; font-size: 0.875rem; transition: var(--transition); }
        .header { background: var(--white); box-shadow: var(--shadow-sm); padding: 0.75rem 0; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--medium-gray); }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 1.5rem; }
        .logo-section { display: flex; align-items: center; gap: 0.75rem; }
        .logo { height: 40px; width: auto; }
        .brand-text h1 { font-size: 1.25rem; font-weight: 700; color: var(--primary-blue); }
        .mobile-menu-toggle { display: none; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-dark); padding: 0.5rem; border-radius: var(--border-radius); line-height: 1; }
        .user-menu { display: flex; align-items: center; gap: 1rem; }
        .user-info { display: flex; align-items: center; gap: 0.75rem; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1rem; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; font-size: 0.9rem; }
        .user-role { font-size: 0.75rem; color: var(--dark-gray); }
        .icon-btn { width: 40px; height: 40px; border: 1px solid var(--medium-gray); background: var(--white); border-radius: 50%; cursor: pointer; color: var(--text-dark); transition: var(--transition); display: inline-flex; align-items: center; justify-content: center; position: relative; }
        .icon-btn:hover { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .notification-badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.6rem; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .logout-btn { background: var(--gradient-primary); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 500; transition: var(--transition); }
        .logout-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
        .dashboard-container { display: flex; min-height: calc(100vh - 73px); }
        .sidebar { width: var(--sidebar-width); background: var(--white); border-right: 1px solid var(--medium-gray); padding: 1.5rem 0; transition: var(--transition); position: fixed; height: calc(100vh - 73px); overflow-y: auto; z-index: 99; }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar.collapsed .menu-item span, .sidebar.collapsed .menu-badge { display: none; }
        .sidebar.collapsed .menu-item a { justify-content: center; padding: 0.75rem; }
        .sidebar.collapsed .menu-item i { margin: 0; font-size: 1.25rem; }
        .sidebar-toggle { position: absolute; right: -12px; top: 20px; width: 24px; height: 24px; background: var(--primary-blue); border: none; border-radius: 50%; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; z-index: 100; }
        .sidebar-menu { list-style: none; }
        .menu-item { margin-bottom: 0.25rem; }
        .menu-item a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1.5rem; color: var(--text-dark); text-decoration: none; transition: var(--transition); border-left: 3px solid transparent; font-size: 0.85rem; }
        .menu-item a:hover, .menu-item a.active { background: var(--light-blue); border-left-color: var(--primary-blue); color: var(--primary-blue); }
        .menu-item i { width: 20px; }
        .menu-badge { background: var(--danger); color: white; border-radius: 10px; padding: 0.1rem 0.4rem; font-size: 0.7rem; font-weight: 600; margin-left: auto; }
        .main-content { flex: 1; padding: 1.5rem; overflow-y: auto; margin-left: var(--sidebar-width); transition: var(--transition); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }
        .page-header { background: var(--white); border-radius: var(--border-radius-lg); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
        .page-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .page-title i { color: var(--primary-blue); }
        .page-description { color: var(--dark-gray); margin-bottom: 1rem; }
        .header-actions-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-top: 1rem; }
        .stats-summary { display: flex; gap: 1.5rem; }
        .stat-item { display: flex; flex-direction: column; gap: 0.25rem; }
        .stat-label { color: var(--dark-gray); font-size: 0.75rem; }
        .stat-value { font-weight: 600; font-size: 1rem; }
        .stat-value.total { color: var(--primary-blue); }
        .stat-value.approved { color: var(--success); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); border-radius: var(--border-radius); padding: 1rem; display: flex; align-items: center; gap: 1rem; cursor: pointer; transition: var(--transition); border: 1px solid var(--medium-gray); }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-card.active { border-color: var(--primary-blue); background: var(--light-blue); }
        .stat-icon-mini { width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        .stat-icon-mini.total { background: var(--light-blue); color: var(--primary-blue); }
        .stat-icon-mini.open { background: #d4edda; color: var(--success); }
        .stat-icon-mini.progress { background: #fff3cd; color: #856404; }
        .stat-icon-mini.resolved { background: #e2e3e5; color: var(--dark-gray); }
        .stat-content-mini h3 { font-size: 1.3rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-content-mini p { font-size: 0.75rem; color: var(--dark-gray); }
        .filters-card { background: var(--white); padding: 1.25rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-label { font-weight: 600; margin-bottom: 0.5rem; font-size: 0.8rem; color: var(--text-dark); }
        .filter-select, .search-input { padding: 0.6rem 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; transition: var(--transition); }
        .filter-select:focus, .search-input:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .search-box { position: relative; }
        .search-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--dark-gray); }
        .search-input { padding-left: 2.5rem; }
        .filter-actions { display: flex; gap: 0.5rem; }
        .tickets-table-card { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .table-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .table-title { font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        .table-title i { color: var(--primary-blue); }
        .table-count { font-size: 0.8rem; color: var(--dark-gray); }
        .table-container { overflow-x: auto; }
        .tickets-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .tickets-table th { padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: var(--dark-gray); border-bottom: 1px solid var(--medium-gray); background: var(--light-gray); }
        .tickets-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--medium-gray); vertical-align: middle; }
        .tickets-table tr:hover { background: var(--light-blue); }
        .status-badge { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem; }
        .status-open { background: #d4edda; color: #155724; }
        .status-in_progress { background: #fff3cd; color: #856404; }
        .status-resolved { background: #d1ecf1; color: #0c5460; }
        .status-closed { background: #e2e3e5; color: #383d41; }
        .priority-badge { padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
        .priority-low { background: #d4edda; color: #155724; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-high { background: #f8d7da; color: #721c24; }
        .priority-urgent { background: #dc3545; color: white; }
        .action-btn { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--light-gray); color: var(--dark-gray); border: 1px solid var(--medium-gray); cursor: pointer; transition: var(--transition); }
        .action-btn:hover { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .btn { padding: 0.6rem 1.2rem; border-radius: var(--border-radius); font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: var(--transition); border: none; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .btn-primary { background: var(--gradient-primary); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
        .btn-outline { background: var(--white); color: var(--text-dark); border: 1px solid var(--medium-gray); }
        .btn-outline:hover { background: var(--light-gray); }
        .alert { padding: 0.75rem 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; border-left: 4px solid; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-error { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }
        .alert-info { background: #d1ecf1; color: #0c5460; border-left-color: var(--primary-blue); }
        .empty-state { text-align: center; padding: 3rem; color: var(--dark-gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        .empty-state h3 { font-size: 1.1rem; margin-bottom: 0.5rem; color: var(--text-dark); }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 2000; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px); }
        .modal-content { background: var(--white); border-radius: var(--border-radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow: hidden; box-shadow: var(--shadow-lg); }
        .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 1.1rem; font-weight: 600; color: var(--text-dark); }
        .modal-close { background: none; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--dark-gray); transition: var(--transition); }
        .modal-close:hover { background: var(--light-gray); }
        .modal-body { padding: 1.25rem; overflow-y: auto; max-height: calc(90vh - 120px); }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem; color: var(--text-dark); }
        .form-control { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; transition: var(--transition); }
        .form-control:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        textarea.form-control { min-height: 100px; resize: vertical; }
        .form-actions { display: flex; gap: 0.75rem; margin-top: 1.25rem; }
        .ticket-details-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .ticket-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 0.25rem; }
        .ticket-id { color: var(--dark-gray); font-size: 0.85rem; margin-bottom: 0.5rem; }
        .ticket-status-large { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.8rem; border-radius: 20px; font-weight: 600; font-size: 0.8rem; }
        .ticket-details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .detail-card { background: var(--light-gray); border-radius: var(--border-radius); padding: 1rem; }
        .detail-label { font-size: 0.7rem; color: var(--dark-gray); margin-bottom: 0.25rem; text-transform: uppercase; }
        .detail-value { font-size: 0.9rem; font-weight: 600; }
        .ticket-description { background: var(--light-gray); border-radius: var(--border-radius); padding: 1rem; margin-bottom: 1.5rem; }
        .description-title { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; }
        .comments-section { margin-top: 1.5rem; }
        .section-title { font-size: 0.95rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .comments-list { display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem; max-height: 400px; overflow-y: auto; }
        .comment { display: flex; gap: 0.75rem; }
        .comment-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-blue); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; flex-shrink: 0; }
        .comment.me .comment-avatar { background: var(--success); }
        .comment-content { flex: 1; background: var(--light-gray); border-radius: var(--border-radius); padding: 0.75rem; }
        .comment-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem; }
        .comment-author { font-weight: 600; font-size: 0.8rem; }
        .comment-date { font-size: 0.7rem; color: var(--dark-gray); }
        .comment-text { font-size: 0.85rem; line-height: 1.5; }
        .comment-form { margin-top: 1rem; }
        .primary-action-btn { position: fixed; bottom: 2rem; right: 2rem; background: var(--gradient-primary); color: white; width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; text-decoration: none; box-shadow: var(--shadow-lg); transition: var(--transition); z-index: 90; border: none; cursor: pointer; }
        .primary-action-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(2px); z-index: 999; }
        .overlay.active { display: block; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); position: fixed; top: 0; height: 100vh; z-index: 1000; padding-top: 1rem; } .sidebar.mobile-open { transform: translateX(0); } .sidebar-toggle { display: none; } .main-content { margin-left: 0 !important; } .mobile-menu-toggle { display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 50%; background: var(--light-gray); transition: var(--transition); } .mobile-menu-toggle:hover { background: var(--primary-blue); color: white; } }
        @media (max-width: 768px) { .nav-container { padding: 0 1rem; gap: 0.5rem; } .brand-text h1 { font-size: 1rem; } .user-details { display: none; } .main-content { padding: 1rem; } .stats-grid { grid-template-columns: repeat(2, 1fr); } .filter-grid { grid-template-columns: 1fr; } .header-actions-row { flex-direction: column; align-items: flex-start; } .stats-summary { width: 100%; justify-content: space-between; } .ticket-details-header { flex-direction: column; } }
        @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } .main-content { padding: 0.75rem; } .logo { height: 32px; } .brand-text h1 { font-size: 0.9rem; } .primary-action-btn { bottom: 1rem; right: 1rem; width: 48px; height: 48px; } .form-actions { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="overlay" id="mobileOverlay"></div>

    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
                <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo">
                <div class="brand-text"><h1>Isonga RPSU</h1></div>
            </div>
            <div class="user-menu">
                <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;"><i class="fas fa-envelope"></i><?php if ($unread_messages > 0): ?><span class="notification-badge"><?php echo $unread_messages; ?></span><?php endif; ?></a>
                <div class="user-info"><div class="user-avatar"><?php echo strtoupper(substr($student_name, 0, 1)); ?></div><div class="user-details"><div class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></div><div class="user-role">Student</div></div></div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-chevron-left"></i></button>
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
                <li class="menu-item"><a href="tickets.php" class="active"><i class="fas fa-ticket-alt"></i><span>My Tickets</span><?php if (($ticket_stats['open'] ?? 0) > 0): ?><span class="menu-badge"><?php echo $ticket_stats['open']; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="financial_aid.php"><i class="fas fa-hand-holding-usd"></i><span>Financial Aid</span></a></li>
                <li class="menu-item"><a href="announcements.php"><i class="fas fa-bullhorn"></i><span>Announcements</span></a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i><span>Events</span></a></li>
                <li class="menu-item"><a href="news.php"><i class="fas fa-newspaper"></i><span>News</span></a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i><span>Gallery</span></a></li>
                <li class="menu-item"><a href="messages.php"><i class="fas fa-comments"></i><span>Messages</span><?php if ($unread_messages > 0): ?><span class="menu-badge"><?php echo $unread_messages; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="profile.php"><i class="fas fa-user-cog"></i><span>Profile & Settings</span></a></li>
                <?php if ($is_class_rep): ?><li class="menu-item"><a href="class_rep_dashboard.php"><i class="fas fa-users"></i><span>Class Rep Dashboard</span></a></li><?php endif; ?>
            </ul>
        </nav>

        <main class="main-content" id="mainContent">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-ticket-alt"></i> My Support Tickets</h1>
                <p class="page-description">Track and manage your support requests</p>
                <div class="header-actions-row">
                    <div class="stats-summary">
                        <div class="stat-item"><span class="stat-label">Total Tickets</span><span class="stat-value total"><?php echo $ticket_stats['total'] ?? 0; ?></span></div>
                        <div class="stat-item"><span class="stat-label">Open</span><span class="stat-value"><?php echo $ticket_stats['open'] ?? 0; ?></span></div>
                        <div class="stat-item"><span class="stat-label">In Progress</span><span class="stat-value"><?php echo $ticket_stats['in_progress'] ?? 0; ?></span></div>
                        <div class="stat-item"><span class="stat-label">Resolved</span><span class="stat-value approved"><?php echo ($ticket_stats['resolved'] ?? 0) + ($ticket_stats['closed'] ?? 0); ?></span></div>
                    </div>
                    <button class="btn btn-primary" onclick="openNewTicketModal()"><i class="fas fa-plus"></i> New Ticket</button>
                </div>
            </div>

            <?php if (empty($student_email)): ?>
                <div class="alert alert-info"><i class="fas fa-envelope"></i><div>Please <a href="profile.php" style="color: var(--primary-blue);">update your email address</a> to receive ticket notifications.</div></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=all&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon-mini total"><i class="fas fa-ticket-alt"></i></div>
                    <div class="stat-content-mini"><h3><?php echo $ticket_stats['total']; ?></h3><p>Total Tickets</p></div>
                </div>
                <div class="stat-card <?php echo $status_filter === 'open' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=open&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon-mini open"><i class="fas fa-clock"></i></div>
                    <div class="stat-content-mini"><h3><?php echo $ticket_stats['open']; ?></h3><p>Open</p></div>
                </div>
                <div class="stat-card <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=in_progress&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon-mini progress"><i class="fas fa-spinner"></i></div>
                    <div class="stat-content-mini"><h3><?php echo $ticket_stats['in_progress']; ?></h3><p>In Progress</p></div>
                </div>
                <div class="stat-card <?php echo ($status_filter === 'resolved' || $status_filter === 'closed') ? 'active' : ''; ?>" onclick="window.location.href='tickets.php?status=resolved&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon-mini resolved"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content-mini"><h3><?php echo ($ticket_stats['resolved'] ?? 0) + ($ticket_stats['closed'] ?? 0); ?></h3><p>Resolved</p></div>
                </div>
            </div>

            <div class="filters-card">
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="filter-group"><label class="filter-label">Status</label><select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select></div>
                        <div class="filter-group"><label class="filter-label">Category</label><select name="category" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>><?php echo safe_display($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="filter-group search-box"><label class="filter-label">Search</label><div style="position: relative;"><i class="fas fa-search search-icon"></i><input type="text" name="search" class="search-input" placeholder="Search tickets..." value="<?php echo safe_display($search_query); ?>" onkeypress="if(event.key === 'Enter') document.getElementById('filterForm').submit()"></div></div>
                        <div class="filter-group filter-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button><a href="tickets.php" class="btn btn-outline"><i class="fas fa-redo"></i> Reset</a></div>
                    </div>
                </form>
            </div>

            <div class="tickets-table-card">
                <div class="table-header"><h3 class="table-title"><i class="fas fa-list"></i> All Tickets</h3><span class="table-count"><?php echo count($tickets); ?> tickets found</span></div>
                <?php if (empty($tickets)): ?>
                    <div class="empty-state"><i class="fas fa-ticket-alt"></i><h3>No tickets found</h3><p><?php echo ($status_filter !== 'all' || $category_filter !== 'all' || !empty($search_query)) ? 'Try adjusting your filters or search terms.' : 'You haven\'t submitted any support tickets yet.'; ?></p><button class="btn btn-primary" onclick="openNewTicketModal()"><i class="fas fa-plus"></i> Submit Your First Ticket</button></div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="tickets-table">
                            <thead>
                                <tr><th>ID</th><th>Subject</th><th>Category</th><th>Status</th><th>Priority</th><th>Assigned To</th><th>Created</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td><strong>#<?php echo $ticket['id']; ?></strong><?php if (($ticket['days_old'] ?? 0) <= 1): ?><div style="font-size: 0.7rem; color: var(--success); margin-top: 0.25rem;">NEW</div><?php endif; ?></td>
                                        <td><div style="font-weight: 500;"><?php echo safe_display($ticket['subject']); ?></div><div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></div></td>
                                        <td><?php echo safe_display($ticket['category_name']); ?></td>
                                        <td><span class="status-badge status-<?php echo str_replace('_', '-', $ticket['status']); ?>"><i class="fas fa-circle" style="font-size: 0.5rem;"></i> <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span></td>
                                        <td><span class="priority-badge priority-<?php echo $ticket['priority']; ?>"><?php echo ucfirst($ticket['priority']); ?></span></td>
                                        <td><?php echo $ticket['assigned_to_name'] ? safe_display($ticket['assigned_to_name']) : '<span style="color: var(--dark-gray);">Pending</span>'; ?></td>
                                        <td><div><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></div><div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo date('g:i A', strtotime($ticket['created_at'])); ?></div></td>
                                        <td><button class="action-btn" title="View Ticket" onclick="viewTicket(<?php echo $ticket['id']; ?>)"><i class="fas fa-eye"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <button class="primary-action-btn" onclick="openNewTicketModal()"><i class="fas fa-plus"></i></button>

    <div id="newTicketModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header"><h3 class="modal-title"><i class="fas fa-ticket-alt"></i> Submit New Ticket</h3><button class="modal-close" onclick="closeNewTicketModal()"><i class="fas fa-times"></i></button></div>
            <div class="modal-body">
                <form method="POST" action="tickets.php" id="newTicketForm">
                    <div class="form-group"><label class="form-label">Issue Category *</label><select name="category_id" class="form-control" required><option value="">Select a category</option><?php foreach ($categories as $category): ?><option value="<?php echo $category['id']; ?>"><?php echo safe_display($category['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Subject *</label><input type="text" name="subject" class="form-control" placeholder="Brief description of your issue" required></div>
                    <div class="form-group"><label class="form-label">Description *</label><textarea name="description" class="form-control" placeholder="Please provide detailed information about your issue..." rows="5" required></textarea></div>
                    <div class="form-group">
                        <label class="form-label">Priority *</label>
                        <select name="priority" class="form-control" required>
                            <!-- <option value="low">Low</option> -->
                            <option value="medium" selected>Priority</option>
                            <!-- <option value="high">High</option>
                            <option value="urgent">Urgent</option> -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Preferred Contact Method *</label>
                        <select name="preferred_contact" class="form-control" required>
                            <option value="email" selected>Email</option>
                            <!-- <option value="sms">SMS</option>
                            <option value="phone">Phone</option> -->
                        </select>
                    </div>
                    <div class="form-actions"><button type="button" class="btn btn-outline" onclick="closeNewTicketModal()">Cancel</button><button type="submit" name="submit_ticket" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Ticket</button></div>
                </form>
            </div>
        </div>
    </div>

    <div id="viewTicketModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header"><h3 class="modal-title">Ticket Details</h3><button class="modal-close" onclick="closeViewTicketModal()"><i class="fas fa-times"></i></button></div>
            <div class="modal-body">
                <?php if ($view_ticket): ?>
                    <div class="ticket-details-header"><div><h2 class="ticket-title"><?php echo safe_display($view_ticket['subject']); ?></h2><div class="ticket-id">Ticket #<?php echo $view_ticket['id']; ?></div><span class="ticket-status-large status-<?php echo str_replace('_', '-', $view_ticket['status']); ?>"><i class="fas fa-circle" style="font-size: 0.6rem;"></i> <?php echo ucfirst(str_replace('_', ' ', $view_ticket['status'])); ?></span></div></div>
                    <div class="ticket-details-grid"><div class="detail-card"><div class="detail-label">Category</div><div class="detail-value"><?php echo safe_display($view_ticket['category_name']); ?></div></div><div class="detail-card"><div class="detail-label">Priority</div><div class="detail-value"><span class="priority-badge priority-<?php echo $view_ticket['priority']; ?>"><?php echo ucfirst($view_ticket['priority']); ?></span></div></div><div class="detail-card"><div class="detail-label">Assigned To</div><div class="detail-value"><?php echo $view_ticket['assigned_to_name'] ? safe_display($view_ticket['assigned_to_name']) : '<span style="color: var(--dark-gray);">Pending assignment</span>'; ?></div></div><div class="detail-card"><div class="detail-label">Created</div><div class="detail-value"><?php echo date('F j, Y', strtotime($view_ticket['created_at'])); ?></div></div></div>
                    <div class="ticket-description"><h3 class="description-title">Description</h3><div><?php echo nl2br(safe_display($view_ticket['description'])); ?></div></div>
                    <div class="comments-section"><h3 class="section-title"><i class="fas fa-comments"></i> Comments (<?php echo count($ticket_comments); ?>)</h3>
                        <?php if (empty($ticket_comments)): ?><div class="empty-state" style="padding: 1.5rem;"><i class="fas fa-comment" style="font-size: 2rem;"></i><p>No comments yet.</p></div>
                        <?php else: ?><div class="comments-list"><?php foreach ($ticket_comments as $comment): ?><div class="comment <?php echo $comment['comment_type']; ?>"><div class="comment-avatar"><?php echo strtoupper(substr($comment['full_name'], 0, 1)); ?></div><div class="comment-content"><div class="comment-header"><div class="comment-author"><?php echo safe_display($comment['full_name']); ?></div><div class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></div></div><div class="comment-text"><?php echo nl2br(safe_display($comment['comment'])); ?></div></div></div><?php endforeach; ?></div><?php endif; ?>
                        <?php if ($view_ticket['status'] !== 'closed' && $view_ticket['status'] !== 'resolved'): ?>
                            <div class="comment-form"><form method="POST" action="tickets.php"><input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>"><div class="form-group"><label class="form-label">Add a Comment</label><textarea name="comment" class="form-control" placeholder="Type your comment here..." rows="3" required></textarea></div><div class="form-actions"><button type="submit" name="add_comment" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post Comment</button></div></form></div>
                        <?php else: ?><div style="text-align: center; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);"><i class="fas fa-info-circle"></i> This ticket is closed. No further comments can be added.</div><?php endif; ?>
                    </div>
                <?php else: ?><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Ticket Not Found</h3><p>The requested ticket could not be found.</p><button class="btn btn-primary" onclick="closeViewTicketModal()">Back to Tickets</button></div><?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') { sidebar.classList.add('collapsed'); mainContent.classList.add('sidebar-collapsed'); if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>'; }
        function toggleSidebar() { sidebar.classList.toggle('collapsed'); mainContent.classList.toggle('sidebar-collapsed'); const isCollapsed = sidebar.classList.contains('collapsed'); localStorage.setItem('sidebarCollapsed', isCollapsed); const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>'; if (sidebarToggle) sidebarToggle.innerHTML = icon; }
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        if (mobileMenuToggle) { mobileMenuToggle.addEventListener('click', () => { const isOpen = sidebar.classList.toggle('mobile-open'); mobileOverlay.classList.toggle('active', isOpen); mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>'; document.body.style.overflow = isOpen ? 'hidden' : ''; }); }
        if (mobileOverlay) { mobileOverlay.addEventListener('click', () => { sidebar.classList.remove('mobile-open'); mobileOverlay.classList.remove('active'); if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>'; document.body.style.overflow = ''; }); }
        window.addEventListener('resize', () => { if (window.innerWidth > 992) { sidebar.classList.remove('mobile-open'); if (mobileOverlay) mobileOverlay.classList.remove('active'); if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>'; document.body.style.overflow = ''; } });
        function openNewTicketModal() { document.getElementById('newTicketModal').style.display = 'flex'; document.body.style.overflow = 'hidden'; }
        function closeNewTicketModal() { document.getElementById('newTicketModal').style.display = 'none'; document.getElementById('newTicketForm').reset(); document.body.style.overflow = 'auto'; }
        function viewTicket(ticketId) { window.location.href = 'tickets.php?view=' + ticketId; }
        function closeViewTicketModal() { window.location.href = 'tickets.php'; }
        window.onclick = function(event) { if (event.target === document.getElementById('newTicketModal')) closeNewTicketModal(); if (event.target === document.getElementById('viewTicketModal')) closeViewTicketModal(); };
        document.addEventListener('keydown', function(event) { if (event.key === 'Escape') { if (document.getElementById('newTicketModal').style.display === 'flex') closeNewTicketModal(); if (document.getElementById('viewTicketModal').style.display === 'flex') closeViewTicketModal(); } });
        <?php if ($view_ticket_id): ?>document.addEventListener('DOMContentLoaded', function() { document.getElementById('viewTicketModal').style.display = 'flex'; document.body.style.overflow = 'hidden'; });<?php endif; ?>
        <?php if (isset($error_message) && isset($_POST['submit_ticket'])): ?>document.addEventListener('DOMContentLoaded', function() { setTimeout(() => { openNewTicketModal(); }, 500); });<?php endif; ?>
        if (window.history.replaceState) window.history.replaceState(null, null, window.location.href);
        setTimeout(() => { document.querySelectorAll('.alert').forEach(alert => { alert.style.opacity = '0'; alert.style.transition = 'opacity 0.5s'; setTimeout(() => { if (alert.parentNode) alert.remove(); }, 500); }); }, 5000);
    </script>
</body>
</html>