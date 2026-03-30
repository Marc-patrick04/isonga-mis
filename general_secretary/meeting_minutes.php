<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Get sidebar statistics
try {
    // Pending tickets count
    $ticketStmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE status IN ('open', 'in_progress') 
        AND (assigned_to = ? OR assigned_to IS NULL)
    ");
    $ticketStmt->execute([$user_id]);
    $pending_tickets = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    
    // New students count
    $new_students_stmt = $pdo->prepare("
        SELECT COUNT(*) as new_students 
        FROM users 
        WHERE role = 'student' 
        AND status = 'active' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $new_students_stmt->execute();
    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    
    // Upcoming meetings count
    $upcoming_meetings = $pdo->query("
        SELECT COUNT(*) as count FROM meetings 
        WHERE meeting_date >= CURDATE() AND status = 'scheduled'
    ")->fetch()['count'] ?? 0;
    
    // Pending minutes count
    $pending_minutes = $pdo->query("
        SELECT COUNT(*) as count FROM meeting_minutes 
        WHERE approval_status = 'submitted'
    ")->fetch()['count'] ?? 0;
    
    // Pending reports
    $pending_reports = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'")->fetch()['pending_reports'] ?? 0;
    
    // Unread messages
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    } catch (PDOException $e) {
        $unread_messages = 0;
    }
    
} catch (PDOException $e) {
    $pending_tickets = $new_students = $upcoming_meetings = $pending_minutes = $pending_reports = $unread_messages = 0;
}

// Handle form actions
$action = $_GET['action'] ?? 'list';
$meeting_id = $_GET['meeting_id'] ?? null;
$minute_id = $_GET['minute_id'] ?? null;
$message = '';
$message_type = '';

// Add/Edit Meeting Minutes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || $action === 'edit')) {
    $meeting_id = $_POST['meeting_id'] ?? null;
    $content = $_POST['content'] ?? '';
    $attachments = $_POST['attachments'] ?? [];
    $approval_status = $_POST['approval_status'] ?? 'draft';
    
    try {
        if ($action === 'add' && $meeting_id) {
            // Check if minutes already exist for this meeting
            $check_stmt = $pdo->prepare("SELECT id FROM meeting_minutes WHERE meeting_id = ?");
            $check_stmt->execute([$meeting_id]);
            
            if ($check_stmt->fetch()) {
                $message = "Meeting minutes already exist for this meeting. Please edit the existing minutes.";
                $message_type = 'error';
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO meeting_minutes (meeting_id, minute_taker_id, content, attachments, approval_status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $attachments_json = !empty($attachments) ? json_encode($attachments) : null;
                $insert_stmt->execute([$meeting_id, $user_id, $content, $attachments_json, $approval_status]);
                
                $minute_id = $pdo->lastInsertId();
                
                // Add action items if provided
                if (isset($_POST['action_titles']) && is_array($_POST['action_titles'])) {
                    foreach ($_POST['action_titles'] as $index => $action_title) {
                        if (!empty(trim($action_title ?? ''))) {
                            $action_desc = $_POST['action_descriptions'][$index] ?? '';
                            $assigned_to = $_POST['action_assignees'][$index] ?? null;
                            $due_date = $_POST['action_due_dates'][$index] ?? null;
                            $priority = $_POST['action_priorities'][$index] ?? 'medium';
                            
                            $action_stmt = $pdo->prepare("
                                INSERT INTO meeting_action_items (meeting_id, minute_id, title, description, assigned_to, due_date, priority, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $action_stmt->execute([
                                $meeting_id, 
                                $minute_id, 
                                $action_title, 
                                $action_desc, 
                                $assigned_to, 
                                $due_date, 
                                $priority, 
                                $user_id
                            ]);
                        }
                    }
                }
                
                $message = "Meeting minutes created successfully!";
                $message_type = 'success';
                $action = 'view';
            }
        } 
        elseif ($action === 'edit' && $minute_id) {
            $update_stmt = $pdo->prepare("
                UPDATE meeting_minutes 
                SET content = ?, attachments = ?, approval_status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $attachments_json = !empty($attachments) ? json_encode($attachments) : null;
            $update_stmt->execute([$content, $attachments_json, $approval_status, $minute_id]);
            
            // Update existing action items
            if (isset($_POST['action_ids']) && is_array($_POST['action_ids'])) {
                foreach ($_POST['action_ids'] as $index => $action_id) {
                    $action_title = $_POST['action_titles'][$index] ?? '';
                    $action_desc = $_POST['action_descriptions'][$index] ?? '';
                    $assigned_to = $_POST['action_assignees'][$index] ?? null;
                    $due_date = $_POST['action_due_dates'][$index] ?? null;
                    $priority = $_POST['action_priorities'][$index] ?? 'medium';
                    $status = $_POST['action_statuses'][$index] ?? 'pending';
                    
                    if ($action_id > 0) {
                        // Update existing action item
                        $update_action_stmt = $pdo->prepare("
                            UPDATE meeting_action_items 
                            SET title = ?, description = ?, assigned_to = ?, due_date = ?, priority = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $update_action_stmt->execute([
                            $action_title, $action_desc, $assigned_to, $due_date, $priority, $status, $action_id
                        ]);
                    } else {
                        // Add new action item
                        $insert_action_stmt = $pdo->prepare("
                            INSERT INTO meeting_action_items (meeting_id, minute_id, title, description, assigned_to, due_date, priority, status, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insert_action_stmt->execute([
                            $meeting_id, $minute_id, $action_title, $action_desc, $assigned_to, $due_date, $priority, $status, $user_id
                        ]);
                    }
                }
            }
            
            $message = "Meeting minutes updated successfully!";
            $message_type = 'success';
            $action = 'view';
        }
    } catch (PDOException $e) {
        error_log("Meeting minutes operation error: " . $e->getMessage());
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Submit for Approval
if ($action === 'submit_approval' && $minute_id) {
    try {
        $update_stmt = $pdo->prepare("UPDATE meeting_minutes SET approval_status = 'submitted' WHERE id = ?");
        $update_stmt->execute([$minute_id]);
        
        $message = "Meeting minutes submitted for approval!";
        $message_type = 'success';
        $action = 'view';
    } catch (PDOException $e) {
        error_log("Submit approval error: " . $e->getMessage());
        $message = "Error submitting for approval: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Approve/Reject Minutes
if (($action === 'approve' || $action === 'reject') && $minute_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $approval_notes = $_POST['approval_notes'] ?? '';
    
    try {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $update_stmt = $pdo->prepare("
            UPDATE meeting_minutes 
            SET approval_status = ?, approved_by = ?, approval_notes = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->execute([$status, $user_id, $approval_notes, $minute_id]);
        
        $message = "Meeting minutes " . $status . " successfully!";
        $message_type = 'success';
        $action = 'view';
    } catch (PDOException $e) {
        error_log("Approval action error: " . $e->getMessage());
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Delete Meeting Minutes
if ($action === 'delete' && $minute_id) {
    try {
        // Delete related action items first
        $pdo->prepare("DELETE FROM meeting_action_items WHERE minute_id = ?")->execute([$minute_id]);
        
        // Delete the minutes
        $delete_stmt = $pdo->prepare("DELETE FROM meeting_minutes WHERE id = ?");
        $delete_stmt->execute([$minute_id]);
        
        $message = "Meeting minutes deleted successfully!";
        $message_type = 'success';
        $action = 'list';
    } catch (PDOException $e) {
        error_log("Delete minutes error: " . $e->getMessage());
        $message = "Error deleting minutes: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Update Action Item Status
if ($action === 'update_action_status' && isset($_POST['action_item_id'])) {
    $action_item_id = $_POST['action_item_id'];
    $status = $_POST['status'] ?? 'pending';
    $completion_notes = $_POST['completion_notes'] ?? '';
    
    try {
        $update_stmt = $pdo->prepare("
            UPDATE meeting_action_items 
            SET status = ?, completion_notes = ?, completed_at = " . ($status === 'completed' ? "NOW()" : "NULL") . "
            WHERE id = ?
        ");
        $update_stmt->execute([$status, $completion_notes, $action_item_id]);
        
        $message = "Action item status updated successfully!";
        $message_type = 'success';
        
        // Redirect back to the view page
        $redirect_minute_id = $_POST['redirect_minute_id'] ?? null;
        if ($redirect_minute_id) {
            $action = 'view';
            $minute_id = $redirect_minute_id;
        }
    } catch (PDOException $e) {
        error_log("Update action status error: " . $e->getMessage());
        $message = "Error updating action item: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get meeting minutes data
$minutes_data = [];
$action_items = [];
$meeting_data = [];
$agenda_items = [];
$attendance_records = [];

if (($action === 'edit' || $action === 'view' || $action === 'approve' || $action === 'reject') && $minute_id) {
    try {
        // Get minutes details
        $stmt = $pdo->prepare("
            SELECT mm.*, 
                   m.title as meeting_title, m.meeting_date, m.location, m.meeting_type,
                   u1.full_name as minute_taker_name,
                   u2.full_name as approver_name
            FROM meeting_minutes mm
            LEFT JOIN meetings m ON mm.meeting_id = m.id
            LEFT JOIN users u1 ON mm.minute_taker_id = u1.id
            LEFT JOIN users u2 ON mm.approved_by = u2.id
            WHERE mm.id = ?
        ");
        $stmt->execute([$minute_id]);
        $minutes_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$minutes_data) {
            $message = "Meeting minutes not found!";
            $message_type = 'error';
            $action = 'list';
        } else {
            $meeting_id = $minutes_data['meeting_id'];
            
            // Get meeting details
            $meeting_stmt = $pdo->prepare("
                SELECT m.*, u.full_name as chairperson_name 
                FROM meetings m 
                LEFT JOIN users u ON m.chairperson_id = u.id 
                WHERE m.id = ?
            ");
            $meeting_stmt->execute([$meeting_id]);
            $meeting_data = $meeting_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get action items
            $action_stmt = $pdo->prepare("
                SELECT mai.*, u.full_name as assignee_name
                FROM meeting_action_items mai
                LEFT JOIN users u ON mai.assigned_to = u.id
                WHERE mai.minute_id = ?
                ORDER BY mai.priority = 'urgent' DESC, 
                         mai.priority = 'high' DESC, 
                         mai.priority = 'medium' DESC,
                         mai.created_at
            ");
            $action_stmt->execute([$minute_id]);
            $action_items = $action_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get agenda items
            $agenda_stmt = $pdo->prepare("SELECT * FROM meeting_agenda_items WHERE meeting_id = ? ORDER BY order_index");
            $agenda_stmt->execute([$meeting_id]);
            $agenda_items = $agenda_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get attendance records
            $attendance_stmt = $pdo->prepare("
                SELECT ma.*, cm.name, cm.role 
                FROM meeting_attendance ma 
                JOIN committee_members cm ON ma.committee_member_id = cm.id 
                WHERE ma.meeting_id = ?
            ");
            $attendance_stmt->execute([$meeting_id]);
            $attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Fetch minutes error: " . $e->getMessage());
        $message = "Error loading meeting minutes data";
        $message_type = 'error';
        $action = 'list';
    }
}

// Get completed AND ongoing meetings without minutes for the "add" action
$available_meetings = [];
if ($action === 'add') {
    try {
        $meetings_stmt = $pdo->prepare("
            SELECT m.id, m.title, m.meeting_date, m.meeting_type, m.location, m.status
            FROM meetings m
            WHERE (m.status = 'completed' OR m.status = 'ongoing')
            AND m.id NOT IN (SELECT meeting_id FROM meeting_minutes)
            ORDER BY m.meeting_date DESC
        ");
        $meetings_stmt->execute();
        $available_meetings = $meetings_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fetch available meetings error: " . $e->getMessage());
        $available_meetings = [];
    }
}

// Get committee members for assignment dropdowns
try {
    $committee_members_stmt = $pdo->query("
        SELECT cm.*, u.full_name, u.email 
        FROM committee_members cm 
        LEFT JOIN users u ON cm.user_id = u.id 
        WHERE cm.status = 'active' 
        ORDER BY cm.role_order
    ");
    $committee_members = $committee_members_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $users_stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE status = 'active' AND role != 'student' 
        ORDER BY full_name
    ");
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch committee/users error: " . $e->getMessage());
    $committee_members = $users = [];
}

// Get meeting minutes list with filtering and pagination
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query for minutes list
$query = "
    SELECT mm.*, 
           m.title as meeting_title, m.meeting_date, m.meeting_type,
           u1.full_name as minute_taker_name,
           u2.full_name as approver_name,
           (SELECT COUNT(*) FROM meeting_action_items mai WHERE mai.minute_id = mm.id) as action_items_count
    FROM meeting_minutes mm
    LEFT JOIN meetings m ON mm.meeting_id = m.id
    LEFT JOIN users u1 ON mm.minute_taker_id = u1.id
    LEFT JOIN users u2 ON mm.approved_by = u2.id
    WHERE 1=1
";

$count_query = "SELECT COUNT(*) as total FROM meeting_minutes mm WHERE 1=1";
$params = [];
$count_params = [];

if ($search) {
    $query .= " AND (m.title LIKE ? OR mm.content LIKE ?)";
    $count_query .= " AND (m.title LIKE ? OR mm.content LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term]);
    $count_params = array_merge($count_params, [$search_term, $search_term]);
}

if ($status_filter) {
    $query .= " AND mm.approval_status = ?";
    $count_query .= " AND mm.approval_status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
}

if ($date_from) {
    $query .= " AND m.meeting_date >= ?";
    $count_query .= " AND m.meeting_date >= ?";
    $params[] = $date_from;
    $count_params[] = $date_from;
}

if ($date_to) {
    $query .= " AND m.meeting_date <= ?";
    $count_query .= " AND m.meeting_date <= ?";
    $params[] = $date_to;
    $count_params[] = $date_to;
}

$query .= " ORDER BY m.meeting_date DESC, mm.created_at DESC LIMIT $limit OFFSET $offset";

try {
    // Get total count
    $count_stmt = $pdo->prepare($count_query);
    $count_stmt->execute($count_params);
    $filtered_total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($filtered_total / $limit);
    
    // Get minutes
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $minutes_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Fetch minutes list error: " . $e->getMessage());
    $minutes_list = [];
    $filtered_total = 0;
    $total_pages = 1;
    $message = "Database error: " . $e->getMessage();
    $message_type = 'error';
}

// Get statistics for dashboard cards
try {
    // Total minutes
    $total_minutes = $pdo->query("SELECT COUNT(*) as count FROM meeting_minutes")->fetch()['count'] ?? 0;
    
    // Draft minutes
    $draft_minutes = $pdo->query("SELECT COUNT(*) as count FROM meeting_minutes WHERE approval_status = 'draft'")->fetch()['count'] ?? 0;
    
    // Approved minutes
    $approved_minutes = $pdo->query("SELECT COUNT(*) as count FROM meeting_minutes WHERE approval_status = 'approved'")->fetch()['count'] ?? 0;
    
    // Pending approval minutes
    $pending_minutes_count = $pdo->query("SELECT COUNT(*) as count FROM meeting_minutes WHERE approval_status = 'submitted'")->fetch()['count'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_minutes = $draft_minutes = $approved_minutes = $pending_minutes_count = 0;
}

// Helper function for priority badge
function getPriorityClass($priority) {
    $classes = [
        'low' => 'priority-low',
        'medium' => 'priority-medium',
        'high' => 'priority-high',
        'urgent' => 'priority-urgent'
    ];
    return $classes[$priority] ?? 'priority-medium';
}

// Helper function for status badge
function getActionStatusClass($status) {
    $classes = [
        'pending' => 'status-pending',
        'in_progress' => 'status-progress',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled'
    ];
    return $classes[$status] ?? 'status-pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Meeting Minutes - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
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

        .dark-mode {
            --primary-blue: #1e88e5;
            --secondary-blue: #64b5f6;
            --accent-blue: #1565c0;
            --light-blue: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #4dd0e1;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item {
            margin-bottom: 0.25rem;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .menu-item i {
            width: 20px;
        }

        .menu-badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.85rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.7rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-blue);
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Filters */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .form-control, .form-select {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        .table tbody tr:hover {
            background: var(--light-blue);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #e2e3e5;
            color: #6c757d;
        }

        .status-submitted {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background: #e2e3e5;
            color: #6c757d;
        }

        .status-progress {
            background: #cce7ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        /* Priority Badges */
        .priority-low {
            background: #d4edda;
            color: #155724;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }

        .priority-high {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-urgent {
            background: #dc3545;
            color: white;
        }

        /* Meeting Details */
        .meeting-details {
            background: var(--light-blue);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            font-size: 0.85rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        /* Agenda Items */
        .agenda-item {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            border-left: 3px solid var(--primary-blue);
        }

        /* Action Items */
        .action-item {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-blue);
        }

        .action-item.priority-low {
            border-left-color: var(--success);
        }

        .action-item.priority-medium {
            border-left-color: var(--warning);
        }

        .action-item.priority-high {
            border-left-color: var(--danger);
        }

        .action-item.priority-urgent {
            border-left-color: #dc3545;
            background: #fff5f5;
        }

        .action-item.status-completed {
            opacity: 0.7;
        }

        .action-form {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            position: relative;
        }

        .remove-action {
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: var(--transition);
        }

        .remove-action:hover {
            transform: scale(1.05);
        }

        /* Attendance Summary */
        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .attendance-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-top: 4px solid var(--primary-blue);
        }

        .attendance-card.present {
            border-top-color: var(--success);
        }

        .attendance-card.absent {
            border-top-color: var(--danger);
        }

        .attendance-card.excused {
            border-top-color: var(--warning);
        }

        .attendance-count {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        /* Minutes Content */
        .minutes-content {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            min-height: 300px;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        /* Editor Toolbar */
        .editor-toolbar {
            background: var(--light-gray);
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-bottom: none;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .editor-btn {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .editor-btn:hover {
            background: var(--primary-blue);
            color: white;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .page-link:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .page-link.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }

            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(2px);
                z-index: 999;
            }

            .overlay.active {
                display: block;
            }

            #sidebarToggleBtn {
                display: none;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>
    
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - General Secretary</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">General Secretary</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Student Tickets</span>
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php" class="active">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes_count > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="page-header">
                <div class="page-title">
                    <h1>Meeting Minutes Management 📝</h1>
                    <p>Create, edit, and manage meeting minutes and action items</p>
                </div>
                <div class="page-actions">
                    <?php if ($action === 'list'): ?>
                        <a href="?action=add" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create Minutes
                        </a>
                    <?php else: ?>
                        <a href="meeting_minutes.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Meeting Minutes Statistics -->
            <?php if ($action === 'list'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_minutes; ?></div>
                        <div class="stat-label">Total Minutes</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $draft_minutes; ?></div>
                        <div class="stat-label">Draft Minutes</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $approved_minutes; ?></div>
                        <div class="stat-label">Approved Minutes</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_minutes_count; ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Create Minutes Form -->
            <?php if ($action === 'add'): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Create Meeting Minutes</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($available_meetings)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-check"></i>
                            <p>No meetings available for creating minutes.</p>
                            <p>All meetings already have minutes or there are no ongoing/completed meetings.</p>
                            <a href="meetings.php" class="btn btn-primary">View Meetings</a>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label class="filter-label">Select Meeting *</label>
                                <select class="form-select" name="meeting_id" required>
                                    <option value="">Select a meeting</option>
                                    <?php foreach ($available_meetings as $meeting): ?>
                                        <option value="<?php echo $meeting['id']; ?>">
                                            <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?> - 
                                            <?php echo htmlspecialchars($meeting['title']); ?> 
                                            (<?php echo ucfirst($meeting['meeting_type']); ?>)
                                            - <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                                <?php echo ucfirst($meeting['status']); ?>
                                            </span>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: var(--dark-gray);">You can create minutes for both ongoing and completed meetings</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="filter-label">Minutes Content *</label>
                                <div class="editor-toolbar">
                                    <button type="button" class="editor-btn" onclick="formatText('bold')"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="editor-btn" onclick="formatText('italic')"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="editor-btn" onclick="formatText('underline')"><i class="fas fa-underline"></i></button>
                                    <button type="button" class="editor-btn" onclick="insertBulletList()"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" class="editor-btn" onclick="insertNumberedList()"><i class="fas fa-list-ol"></i></button>
                                </div>
                                <textarea class="form-control" id="content" name="content" rows="15" placeholder="Enter detailed meeting minutes here..." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="filter-label">Action Items</label>
                                <div id="action-items">
                                    <div class="action-form">
                                        <input type="hidden" name="action_ids[]" value="0">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="filter-label">Action Title</label>
                                                <input type="text" class="form-control" name="action_titles[]" placeholder="Enter action item title">
                                            </div>
                                            <div class="form-group">
                                                <label class="filter-label">Assigned To</label>
                                                <select class="form-select" name="action_assignees[]">
                                                    <option value="">Select assignee</option>
                                                    <?php foreach ($users as $user_item): ?>
                                                        <option value="<?php echo $user_item['id']; ?>">
                                                            <?php echo htmlspecialchars($user_item['full_name'] . ' (' . $user_item['role'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="filter-label">Due Date</label>
                                                <input type="date" class="form-control" name="action_due_dates[]">
                                            </div>
                                            <div class="form-group">
                                                <label class="filter-label">Priority</label>
                                                <select class="form-select" name="action_priorities[]">
                                                    <option value="low">Low</option>
                                                    <option value="medium" selected>Medium</option>
                                                    <option value="high">High</option>
                                                    <option value="urgent">Urgent</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="filter-label">Description</label>
                                            <textarea class="form-control" name="action_descriptions[]" rows="2" placeholder="Detailed description of the action item"></textarea>
                                        </div>
                                        <button type="button" class="remove-action" onclick="this.parentElement.remove()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline btn-sm" onclick="addActionItem()">
                                    <i class="fas fa-plus"></i> Add Action Item
                                </button>
                            </div>
                            
                            <div class="form-group">
                                <label class="filter-label">Approval Status</label>
                                <select class="form-select" name="approval_status">
                                    <option value="draft" selected>Draft</option>
                                    <option value="submitted">Submit for Approval</option>
                                </select>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Create Minutes
                                </button>
                                <a href="meeting_minutes.php" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Edit Minutes Form -->
            <?php if ($action === 'edit' && $minutes_data): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Edit Meeting Minutes - <?php echo htmlspecialchars($minutes_data['meeting_title']); ?></h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="meeting_id" value="<?php echo $minutes_data['meeting_id']; ?>">
                        
                        <div class="meeting-details">
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Meeting Date</span>
                                    <span class="detail-value"><?php echo date('F j, Y', strtotime($minutes_data['meeting_date'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Location</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($meeting_data['location'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Meeting Type</span>
                                    <span class="detail-value"><?php echo ucfirst($minutes_data['meeting_type']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Minute Taker</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($minutes_data['minute_taker_name']); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="filter-label">Minutes Content *</label>
                            <div class="editor-toolbar">
                                <button type="button" class="editor-btn" onclick="formatText('bold')"><i class="fas fa-bold"></i></button>
                                <button type="button" class="editor-btn" onclick="formatText('italic')"><i class="fas fa-italic"></i></button>
                                <button type="button" class="editor-btn" onclick="formatText('underline')"><i class="fas fa-underline"></i></button>
                                <button type="button" class="editor-btn" onclick="insertBulletList()"><i class="fas fa-list-ul"></i></button>
                                <button type="button" class="editor-btn" onclick="insertNumberedList()"><i class="fas fa-list-ol"></i></button>
                            </div>
                            <textarea class="form-control" id="content" name="content" rows="15" required><?php echo htmlspecialchars($minutes_data['content'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="filter-label">Action Items</label>
                            <div id="action-items">
                                <?php if (!empty($action_items)): ?>
                                    <?php foreach ($action_items as $index => $action): ?>
                                        <div class="action-form">
                                            <input type="hidden" name="action_ids[]" value="<?php echo $action['id']; ?>">
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label class="filter-label">Action Title</label>
                                                    <input type="text" class="form-control" name="action_titles[]" 
                                                           value="<?php echo htmlspecialchars($action['title']); ?>" placeholder="Enter action item title">
                                                </div>
                                                <div class="form-group">
                                                    <label class="filter-label">Assigned To</label>
                                                    <select class="form-select" name="action_assignees[]">
                                                        <option value="">Select assignee</option>
                                                        <?php foreach ($users as $user_item): ?>
                                                            <option value="<?php echo $user_item['id']; ?>" 
                                                                <?php echo $action['assigned_to'] == $user_item['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($user_item['full_name'] . ' (' . $user_item['role'] . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label class="filter-label">Due Date</label>
                                                    <input type="date" class="form-control" name="action_due_dates[]" 
                                                           value="<?php echo htmlspecialchars($action['due_date']); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label class="filter-label">Priority</label>
                                                    <select class="form-select" name="action_priorities[]">
                                                        <option value="low" <?php echo $action['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                                        <option value="medium" <?php echo $action['priority'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                                        <option value="high" <?php echo $action['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                                        <option value="urgent" <?php echo $action['priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label class="filter-label">Status</label>
                                                    <select class="form-select" name="action_statuses[]">
                                                        <option value="pending" <?php echo $action['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="in_progress" <?php echo $action['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="completed" <?php echo $action['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="cancelled" <?php echo $action['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label class="filter-label">Description</label>
                                                    <textarea class="form-control" name="action_descriptions[]" rows="2" placeholder="Detailed description of the action item"><?php echo htmlspecialchars($action['description']); ?></textarea>
                                                </div>
                                            </div>
                                            <button type="button" class="remove-action" onclick="this.parentElement.remove()">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="action-form">
                                        <input type="hidden" name="action_ids[]" value="0">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="filter-label">Action Title</label>
                                                <input type="text" class="form-control" name="action_titles[]" placeholder="Enter action item title">
                                            </div>
                                            <div class="form-group">
                                                <label class="filter-label">Assigned To</label>
                                                <select class="form-select" name="action_assignees[]">
                                                    <option value="">Select assignee</option>
                                                    <?php foreach ($users as $user_item): ?>
                                                        <option value="<?php echo $user_item['id']; ?>">
                                                            <?php echo htmlspecialchars($user_item['full_name'] . ' (' . $user_item['role'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="filter-label">Due Date</label>
                                                <input type="date" class="form-control" name="action_due_dates[]">
                                            </div>
                                            <div class="form-group">
                                                <label class="filter-label">Priority</label>
                                                <select class="form-select" name="action_priorities[]">
                                                    <option value="low">Low</option>
                                                    <option value="medium" selected>Medium</option>
                                                    <option value="high">High</option>
                                                    <option value="urgent">Urgent</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="filter-label">Status</label>
                                                <select class="form-select" name="action_statuses[]">
                                                    <option value="pending" selected>Pending</option>
                                                    <option value="in_progress">In Progress</option>
                                                    <option value="completed">Completed</option>
                                                    <option value="cancelled">Cancelled</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="filter-label">Description</label>
                                                <textarea class="form-control" name="action_descriptions[]" rows="2" placeholder="Detailed description of the action item"></textarea>
                                            </div>
                                        </div>
                                        <button type="button" class="remove-action" onclick="this.parentElement.remove()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addActionItem()">
                                <i class="fas fa-plus"></i> Add Action Item
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <label class="filter-label">Approval Status</label>
                            <select class="form-select" name="approval_status">
                                <option value="draft" <?php echo $minutes_data['approval_status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="submitted" <?php echo $minutes_data['approval_status'] == 'submitted' ? 'selected' : ''; ?>>Submit for Approval</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Minutes
                            </button>
                            <a href="?action=view&minute_id=<?php echo $minute_id; ?>" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- View Minutes -->
            <?php if ($action === 'view' && $minutes_data): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Meeting Minutes - <?php echo htmlspecialchars($minutes_data['meeting_title']); ?></h3>
                    <div class="action-buttons">
                        <span class="status-badge status-<?php echo $minutes_data['approval_status']; ?>">
                            <?php echo ucfirst($minutes_data['approval_status']); ?>
                        </span>
                        <?php if ($minutes_data['approval_status'] === 'draft' || $minutes_data['approval_status'] === 'rejected'): ?>
                            <a href="?action=edit&minute_id=<?php echo $minute_id; ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="?action=submit_approval&minute_id=<?php echo $minute_id; ?>" class="btn btn-primary btn-sm" 
                               onclick="return confirm('Are you sure you want to submit these minutes for approval?')">
                                <i class="fas fa-paper-plane"></i> Submit
                            </a>
                        <?php elseif ($minutes_data['approval_status'] === 'submitted'): ?>
                            <a href="?action=approve&minute_id=<?php echo $minute_id; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-check"></i> Approve
                            </a>
                            <a href="?action=reject&minute_id=<?php echo $minute_id; ?>" class="btn btn-danger btn-sm">
                                <i class="fas fa-times"></i> Reject
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="meeting-details">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Meeting Date</span>
                                <span class="detail-value"><?php echo date('F j, Y', strtotime($minutes_data['meeting_date'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Location</span>
                                <span class="detail-value"><?php echo htmlspecialchars($meeting_data['location'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Meeting Type</span>
                                <span class="detail-value"><?php echo ucfirst($minutes_data['meeting_type']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Chairperson</span>
                                <span class="detail-value"><?php echo htmlspecialchars($meeting_data['chairperson_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Minute Taker</span>
                                <span class="detail-value"><?php echo htmlspecialchars($minutes_data['minute_taker_name']); ?></span>
                            </div>
                            <?php if ($minutes_data['approval_status'] === 'approved' || $minutes_data['approval_status'] === 'rejected'): ?>
                                <div class="detail-item">
                                    <span class="detail-label"><?php echo $minutes_data['approval_status'] === 'approved' ? 'Approved By' : 'Rejected By'; ?></span>
                                    <span class="detail-value"><?php echo htmlspecialchars($minutes_data['approver_name']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><?php echo $minutes_data['approval_status'] === 'approved' ? 'Approved On' : 'Rejected On'; ?></span>
                                    <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($minutes_data['approved_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Attendance Summary -->
                    <?php if (!empty($attendance_records)): ?>
                    <div class="form-group">
                        <label class="filter-label">Attendance Summary</label>
                        <div class="attendance-summary">
                            <?php 
                            $present_count = 0;
                            $absent_count = 0;
                            $excused_count = 0;
                            
                            foreach ($attendance_records as $record) {
                                switch ($record['attendance_status']) {
                                    case 'present': $present_count++; break;
                                    case 'absent': $absent_count++; break;
                                    case 'excused': $excused_count++; break;
                                }
                            }
                            $total_attendance = count($attendance_records);
                            $attendance_rate = $total_attendance > 0 ? round(($present_count / $total_attendance) * 100) : 0;
                            ?>
                            
                            <div class="attendance-card">
                                <div class="attendance-count"><?php echo $attendance_rate; ?>%</div>
                                <div>Overall Attendance</div>
                            </div>
                            <div class="attendance-card present">
                                <div class="attendance-count"><?php echo $present_count; ?></div>
                                <div>Present</div>
                            </div>
                            <div class="attendance-card absent">
                                <div class="attendance-count"><?php echo $absent_count; ?></div>
                                <div>Absent</div>
                            </div>
                            <div class="attendance-card excused">
                                <div class="attendance-count"><?php echo $excused_count; ?></div>
                                <div>Excused</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Agenda Items -->
                    <?php if (!empty($agenda_items)): ?>
                    <div class="form-group">
                        <label class="filter-label">Meeting Agenda</label>
                        <?php foreach ($agenda_items as $agenda): ?>
                            <div class="agenda-item">
                                <h4 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($agenda['title']); ?></h4>
                                <?php if (!empty($agenda['description'])): ?>
                                    <p style="margin-bottom: 0.5rem; color: var(--dark-gray);"><?php echo htmlspecialchars($agenda['description']); ?></p>
                                <?php endif; ?>
                                <small style="color: var(--dark-gray);">Duration: <?php echo $agenda['duration_minutes']; ?> minutes</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Minutes Content -->
                    <div class="form-group">
                        <label class="filter-label">Minutes Content</label>
                        <div class="minutes-content">
                            <?php echo nl2br(htmlspecialchars($minutes_data['content'])); ?>
                        </div>
                    </div>

                    <!-- Action Items -->
                    <?php if (!empty($action_items)): ?>
                    <div class="form-group">
                        <label class="filter-label">Action Items</label>
                        <?php foreach ($action_items as $action): ?>
                            <div class="action-item priority-<?php echo $action['priority']; ?> status-<?php echo $action['status']; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                                    <h4 style="margin: 0;"><?php echo htmlspecialchars($action['title']); ?></h4>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <span class="status-badge status-<?php echo getActionStatusClass($action['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $action['status'])); ?>
                                        </span>
                                        <span class="status-badge <?php echo getPriorityClass($action['priority']); ?>">
                                            <?php echo ucfirst($action['priority']); ?> Priority
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($action['description'])): ?>
                                    <p style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($action['description']); ?></p>
                                <?php endif; ?>
                                
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem;">
                                    <div>
                                        <strong>Assigned To:</strong> 
                                        <?php echo $action['assignee_name'] ? htmlspecialchars($action['assignee_name']) : 'Not assigned'; ?>
                                    </div>
                                    <div>
                                        <strong>Due Date:</strong> 
                                        <?php echo $action['due_date'] ? date('M j, Y', strtotime($action['due_date'])) : 'Not set'; ?>
                                    </div>
                                </div>
                                
                                <?php if ($action['status'] === 'completed' && !empty($action['completion_notes'])): ?>
                                    <div style="background: var(--success); color: white; padding: 0.5rem; border-radius: var(--border-radius); margin-top: 0.5rem;">
                                        <strong>Completion Notes:</strong> <?php echo htmlspecialchars($action['completion_notes']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($action['status'] !== 'completed' && $action['status'] !== 'cancelled'): ?>
                                    <form method="POST" action="?action=update_action_status&minute_id=<?php echo $minute_id; ?>" style="margin-top: 1rem;">
                                        <input type="hidden" name="action_item_id" value="<?php echo $action['id']; ?>">
                                        <input type="hidden" name="redirect_minute_id" value="<?php echo $minute_id; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="filter-label">Update Status</label>
                                                <select class="form-select" name="status" required>
                                                    <option value="pending" <?php echo $action['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="in_progress" <?php echo $action['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                    <option value="completed">Completed</option>
                                                    <option value="cancelled">Cancelled</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="filter-label">Completion Notes (if applicable)</label>
                                                <textarea class="form-control" name="completion_notes" rows="2" placeholder="Notes about completion"><?php echo htmlspecialchars($action['completion_notes'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-save"></i> Update Status
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Approval Notes -->
                    <?php if (!empty($minutes_data['approval_notes'])): ?>
                    <div class="form-group">
                        <label class="filter-label">Approval Notes</label>
                        <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius);">
                            <?php echo nl2br(htmlspecialchars($minutes_data['approval_notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Approve/Reject Forms -->
            <?php if (($action === 'approve' || $action === 'reject') && $minutes_data): ?>
            <div class="card">
                <div class="card-header">
                    <h3><?php echo $action === 'approve' ? 'Approve' : 'Reject'; ?> Meeting Minutes</h3>
                </div>
                <div class="card-body">
                    <div class="meeting-details">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Meeting</span>
                                <span class="detail-value"><?php echo htmlspecialchars($minutes_data['meeting_title']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Date</span>
                                <span class="detail-value"><?php echo date('F j, Y', strtotime($minutes_data['meeting_date'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Minute Taker</span>
                                <span class="detail-value"><?php echo htmlspecialchars($minutes_data['minute_taker_name']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label class="filter-label">
                                <?php echo $action === 'approve' ? 'Approval Notes' : 'Rejection Reason'; ?> *
                            </label>
                            <textarea class="form-control" name="approval_notes" rows="4" 
                                      placeholder="<?php echo $action === 'approve' ? 'Enter any additional notes for approval...' : 'Explain why these minutes are being rejected...'; ?>" required></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                            <button type="submit" class="btn <?php echo $action === 'approve' ? 'btn-success' : 'btn-danger'; ?>">
                                <i class="fas fa-<?php echo $action === 'approve' ? 'check' : 'times'; ?>"></i> 
                                <?php echo $action === 'approve' ? 'Approve Minutes' : 'Reject Minutes'; ?>
                            </button>
                            <a href="?action=view&minute_id=<?php echo $minute_id; ?>" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Minutes List -->
            <?php if ($action === 'list'): ?>
            <!-- Filters -->
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="">
                        <div class="filters">
                            <div class="filter-group">
                                <label class="filter-label">Search Minutes</label>
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Search by meeting title or content..."
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Approval Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="submitted" <?php echo $status_filter == 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="filter-group" style="justify-content: flex-end;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="meeting_minutes.php" class="btn btn-outline">Clear</a>
                            </div>
                        </div>
                        <div class="filters" style="margin-top: 1rem;">
                            <div class="filter-group">
                                <label class="filter-label">From Date</label>
                                <input type="date" class="form-control" name="date_from" 
                                       value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">To Date</label>
                                <input type="date" class="form-control" name="date_to" 
                                       value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="filter-group" style="justify-content: flex-end;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-calendar-alt"></i> Apply Date Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Minutes Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Meeting Minutes List (<?php echo $filtered_total; ?> minutes)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($minutes_list)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <p>No meeting minutes found matching your criteria.</p>
                            <?php if ($search || $status_filter || $date_from || $date_to): ?>
                                <a href="meeting_minutes.php" class="btn btn-primary">Clear Filters</a>
                            <?php else: ?>
                                <a href="?action=add" class="btn btn-primary">Create First Minutes</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Meeting</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Minute Taker</th>
                                        <th>Action Items</th>
                                        <th>Status</th>
                                        <th>Approved By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($minutes_list as $minute): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($minute['meeting_title']); ?></strong>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($minute['meeting_date'])); ?></td>
                                            <td><?php echo ucfirst($minute['meeting_type']); ?></td>
                                            <td><?php echo htmlspecialchars($minute['minute_taker_name']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $minute['action_items_count'] > 0 ? 'status-approved' : 'status-pending'; ?>">
                                                    <?php echo $minute['action_items_count']; ?> items
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $minute['approval_status']; ?>">
                                                    <?php echo ucfirst($minute['approval_status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($minute['approver_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="?action=view&minute_id=<?php echo $minute['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($minute['approval_status'] === 'draft' || $minute['approval_status'] === 'rejected'): ?>
                                                        <a href="?action=edit&minute_id=<?php echo $minute['id']; ?>" 
                                                           class="btn btn-outline btn-sm" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="?action=delete&minute_id=<?php echo $minute['id']; ?>" 
                                                           class="btn btn-danger btn-sm" 
                                                           onclick="return confirm('Are you sure you want to delete these minutes? This action cannot be undone.')"
                                                           title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars</i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Action Items Management
        function addActionItem() {
            const actionContainer = document.getElementById('action-items');
            const newAction = document.createElement('div');
            newAction.className = 'action-form';
            newAction.innerHTML = `
                <input type="hidden" name="action_ids[]" value="0">
                <div class="form-row">
                    <div class="form-group">
                        <label class="filter-label">Action Title</label>
                        <input type="text" class="form-control" name="action_titles[]" placeholder="Enter action item title">
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Assigned To</label>
                        <select class="form-select" name="action_assignees[]">
                            <option value="">Select assignee</option>
                            <?php foreach ($users as $user_item): ?>
                                <option value="<?php echo $user_item['id']; ?>">
                                    <?php echo htmlspecialchars($user_item['full_name'] . ' (' . $user_item['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="filter-label">Due Date</label>
                        <input type="date" class="form-control" name="action_due_dates[]">
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Priority</label>
                        <select class="form-select" name="action_priorities[]">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="filter-label">Status</label>
                        <select class="form-select" name="action_statuses[]">
                            <option value="pending" selected>Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Description</label>
                        <textarea class="form-control" name="action_descriptions[]" rows="2" placeholder="Detailed description of the action item"></textarea>
                    </div>
                </div>
                <button type="button" class="remove-action" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            actionContainer.appendChild(newAction);
        }

        // Text Formatting Functions for Editor
        function formatText(command) {
            document.execCommand(command, false, null);
            document.getElementById('content').focus();
        }

        function insertBulletList() {
            document.execCommand('insertUnorderedList', false, null);
            document.getElementById('content').focus();
        }

        function insertNumberedList() {
            document.execCommand('insertOrderedList', false, null);
            document.getElementById('content').focus();
        }

        // Auto-save draft functionality
        let autoSaveTimer;
        const contentTextarea = document.getElementById('content');
        
        if (contentTextarea) {
            contentTextarea.addEventListener('input', () => {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(() => {
                    console.log('Auto-saving draft...');
                }, 2000);
            });
        }
    </script>
</body>
</html>