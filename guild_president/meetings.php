<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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

// Get dashboard statistics for sidebar (PostgreSQL syntax)
try {
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (Exception $e) {
        $pending_reports = 0;
    }
    
    // Unread messages - PostgreSQL syntax
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    } catch (Exception $e) {
        $unread_messages = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (Exception $e) {
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $open_tickets = $pending_reports = $unread_messages = $pending_docs = 0;
}

// Handle form actions
$action = $_GET['action'] ?? 'list';
$meeting_id = $_GET['id'] ?? null;
$message = '';
$message_type = '';

// Add/Edit Meeting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || $action === 'edit')) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $meeting_type = $_POST['meeting_type'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $meeting_date = $_POST['meeting_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $chairperson_id = $_POST['chairperson_id'] ?? null;
    $is_committee_meeting = isset($_POST['is_committee_meeting']) ? 1 : 0;
    $committee_role = $_POST['committee_role'] ?? null;
    
    try {
        if ($action === 'add') {
            $insert_stmt = $pdo->prepare("
                INSERT INTO meetings (title, description, meeting_type, committee_role, chairperson_id, 
                                    location, meeting_date, start_time, end_time, is_committee_meeting, 
                                    status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, CURRENT_TIMESTAMP)
            ");
            
            $insert_stmt->execute([
                $title, $description, $meeting_type, $committee_role, $chairperson_id,
                $location, $meeting_date, $start_time, $end_time, $is_committee_meeting, $user_id
            ]);
            
            $meeting_id = $pdo->lastInsertId();
            $message = "Meeting scheduled successfully!";
            $message_type = 'success';
            $action = 'list';
        } 
        elseif ($action === 'edit' && $meeting_id) {
            $update_stmt = $pdo->prepare("
                UPDATE meetings 
                SET title = ?, description = ?, meeting_type = ?, committee_role = ?, chairperson_id = ?,
                    location = ?, meeting_date = ?, start_time = ?, end_time = ?, is_committee_meeting = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $update_stmt->execute([
                $title, $description, $meeting_type, $committee_role, $chairperson_id,
                $location, $meeting_date, $start_time, $end_time, $is_committee_meeting, $meeting_id
            ]);
            
            $message = "Meeting updated successfully!";
            $message_type = 'success';
            $action = 'list';
        }
    } catch (PDOException $e) {
        error_log("Meeting operation error: " . $e->getMessage());
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Delete Meeting
if ($action === 'delete' && $meeting_id) {
    try {
        // Delete related records first
        $pdo->prepare("DELETE FROM meeting_agenda_items WHERE meeting_id = ?")->execute([$meeting_id]);
        $pdo->prepare("DELETE FROM meeting_attendance WHERE meeting_id = ?")->execute([$meeting_id]);
        
        $delete_stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ?");
        $delete_stmt->execute([$meeting_id]);
        
        $message = "Meeting deleted successfully!";
        $message_type = 'success';
        $action = 'list';
    } catch (PDOException $e) {
        error_log("Delete meeting error: " . $e->getMessage());
        $message = "Error deleting meeting: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Update Meeting Status (Complete)
if ($action === 'complete' && $meeting_id) {
    try {
        $update_stmt = $pdo->prepare("UPDATE meetings SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update_stmt->execute([$meeting_id]);
        
        $message = "Meeting marked as completed!";
        $message_type = 'success';
        $action = 'list';
    } catch (PDOException $e) {
        error_log("Complete meeting error: " . $e->getMessage());
        $message = "Error completing meeting: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Record Attendance
if ($action === 'record_attendance' && $meeting_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get all committee members
        $committee_stmt = $pdo->query("SELECT id FROM committee_members WHERE status = 'active'");
        $committee_members = $committee_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $records_updated = 0;
        $records_created = 0;
        
        foreach ($committee_members as $member) {
            $member_id = $member['id'];
            $attendance_status = $_POST['attendance_' . $member_id] ?? 'absent';
            $notes = $_POST['notes_' . $member_id] ?? '';
            
            // Check if attendance record already exists
            $check_stmt = $pdo->prepare("SELECT id FROM meeting_attendance WHERE meeting_id = ? AND committee_member_id = ?");
            $check_stmt->execute([$meeting_id, $member_id]);
            
            if ($check_stmt->fetch()) {
                $update_stmt = $pdo->prepare("
                    UPDATE meeting_attendance 
                    SET attendance_status = ?, notes = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE meeting_id = ? AND committee_member_id = ?
                ");
                $update_stmt->execute([$attendance_status, $notes, $user_id, $meeting_id, $member_id]);
                $records_updated++;
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO meeting_attendance (meeting_id, committee_member_id, attendance_status, notes, recorded_by, created_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $insert_stmt->execute([$meeting_id, $member_id, $attendance_status, $notes, $user_id]);
                $records_created++;
            }
        }
        
        $message = "Attendance recorded successfully! ($records_created new, $records_updated updated)";
        $message_type = 'success';
        $action = 'view';
        
    } catch (PDOException $e) {
        error_log("Attendance recording error: " . $e->getMessage());
        $message = "Error recording attendance: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get meeting data for editing/viewing/attendance
$meeting_data = [];
$agenda_items = [];
$attendance_records = [];
if (($action === 'edit' || $action === 'view' || $action === 'attendance') && $meeting_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name as chairperson_name 
            FROM meetings m 
            LEFT JOIN users u ON m.chairperson_id = u.id 
            WHERE m.id = ?
        ");
        $stmt->execute([$meeting_id]);
        $meeting_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$meeting_data) {
            $message = "Meeting not found!";
            $message_type = 'error';
            $action = 'list';
        } else {
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
        error_log("Fetch meeting error: " . $e->getMessage());
        $message = "Error loading meeting data";
        $message_type = 'error';
        $action = 'list';
    }
}

// Get committee members and users for dropdowns
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

// Get meetings list with filtering and pagination (PostgreSQL syntax)
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query for meetings list (PostgreSQL compatible)
$query = "
    SELECT m.*, u.full_name as chairperson_name,
           (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id = m.id) as total_attendees,
           (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id = m.id AND ma.attendance_status = 'present') as present_count,
           (SELECT COUNT(*) FROM committee_members cm WHERE cm.status = 'active') as total_committee_members
    FROM meetings m 
    LEFT JOIN users u ON m.chairperson_id = u.id 
    WHERE 1=1
";

$count_query = "SELECT COUNT(*) as total FROM meetings m WHERE 1=1";
$params = [];
$count_params = [];

if ($search) {
    $query .= " AND (m.title ILIKE ? OR m.description ILIKE ? OR m.location ILIKE ?)";
    $count_query .= " AND (m.title ILIKE ? OR m.description ILIKE ? OR m.location ILIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $count_params = array_merge($count_params, [$search_term, $search_term, $search_term]);
}

if ($status_filter) {
    $query .= " AND m.status = ?";
    $count_query .= " AND m.status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
}

if ($type_filter) {
    $query .= " AND m.meeting_type = ?";
    $count_query .= " AND m.meeting_type = ?";
    $params[] = $type_filter;
    $count_params[] = $type_filter;
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

$query .= " ORDER BY m.meeting_date DESC, m.start_time DESC LIMIT $limit OFFSET $offset";

try {
    // Get total count
    $count_stmt = $pdo->prepare($count_query);
    $count_stmt->execute($count_params);
    $filtered_total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $total_pages = ceil($filtered_total / $limit);
    
    // Get meetings
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Fetch meetings error: " . $e->getMessage());
    $meetings = [];
    $filtered_total = 0;
    $total_pages = 1;
    $message = "Database error: " . $e->getMessage();
    $message_type = 'error';
}

// Get statistics for dashboard cards (PostgreSQL syntax)
try {
    // Total meetings
    $total_meetings = $pdo->query("SELECT COUNT(*) as count FROM meetings")->fetch()['count'] ?? 0;
    
    // Upcoming meetings (PostgreSQL uses CURRENT_DATE)
    $upcoming_meetings = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'")->fetch()['count'] ?? 0;
    
    // Completed meetings this month (PostgreSQL EXTRACT)
    $completed_this_month = $pdo->query("
        SELECT COUNT(*) as count FROM meetings 
        WHERE status = 'completed' 
        AND EXTRACT(MONTH FROM meeting_date) = EXTRACT(MONTH FROM CURRENT_DATE)
        AND EXTRACT(YEAR FROM meeting_date) = EXTRACT(YEAR FROM CURRENT_DATE)
    ")->fetch()['count'] ?? 0;
    
    // Average attendance rate
    $attendance_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_meetings,
            COALESCE(SUM(present_count), 0) as total_present,
            COALESCE(SUM(total_attendees), 0) as total_expected
        FROM (
            SELECT 
                m.id,
                (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id = m.id) as total_attendees,
                (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id = m.id AND ma.attendance_status = 'present') as present_count
            FROM meetings m
            WHERE m.status = 'completed'
        ) as meeting_stats
    ");
    $attendance_stats = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
    
    $average_attendance = 0;
    if (($attendance_stats['total_expected'] ?? 0) > 0) {
        $average_attendance = round(($attendance_stats['total_present'] / $attendance_stats['total_expected']) * 100);
    }
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_meetings = $upcoming_meetings = $completed_this_month = $average_attendance = 0;
}

// Get new student registrations for sidebar badge (PostgreSQL)
try {
    $new_students_stmt = $pdo->prepare("
        SELECT COUNT(*) as new_students 
        FROM users 
        WHERE role = 'student' 
        AND status = 'active' 
        AND created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $new_students_stmt->execute();
    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
} catch (PDOException $e) {
    $new_students = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Meeting Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* All CSS styles from previous response - keeping it compact */
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
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 2px 8px rgba(0,0,0,0.12);
            --border-radius: 8px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; line-height: 1.5; color: var(--text-dark); background: var(--light-gray); font-size: 0.875rem; }
        .header { background: var(--white); box-shadow: var(--shadow-sm); padding: 0.75rem 0; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--medium-gray); }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 1.5rem; }
        .logo-section { display: flex; align-items: center; gap: 0.75rem; }
        .logos { display: flex; gap: 0.75rem; align-items: center; }
        .logo { height: 40px; width: auto; }
        .brand-text h1 { font-size: 1.25rem; font-weight: 700; color: var(--primary-blue); }
        .mobile-menu-toggle { display: none; background: none; border: none; font-size: 1.2rem; cursor: pointer; padding: 0.5rem; border-radius: var(--border-radius); }
        .user-menu { display: flex; align-items: center; gap: 1rem; }
        .user-info { display: flex; align-items: center; gap: 0.75rem; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; font-size: 0.9rem; }
        .user-role { font-size: 0.75rem; color: var(--dark-gray); }
        .header-actions { display: flex; align-items: center; gap: 0.75rem; }
        .icon-btn { width: 40px; height: 40px; border: 1px solid var(--medium-gray); background: var(--white); border-radius: 50%; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; position: relative; transition: var(--transition); }
        .icon-btn:hover { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .notification-badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.6rem; display: flex; align-items: center; justify-content: center; }
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
        .menu-badge { background: var(--danger); color: white; border-radius: 10px; padding: 0.1rem 0.4rem; font-size: 0.7rem; margin-left: auto; }
        .main-content { flex: 1; padding: 1.5rem; overflow-y: auto; margin-left: var(--sidebar-width); transition: var(--transition); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); position: fixed; top: 0; height: 100vh; z-index: 1000; }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle { display: none; }
            .main-content { margin-left: 0 !important; }
            .mobile-menu-toggle { display: flex; align-items: center; justify-content: center; }
            .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(2px); z-index: 999; }
            .overlay.active { display: block; }
        }
        @media (max-width: 768px) {
            .nav-container { padding: 0 1rem; }
            .user-details { display: none; }
            .main-content { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .logo { height: 32px; }
            .brand-text h1 { font-size: 0.9rem; }
        }
        .card { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; overflow: hidden; }
        .card-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
        .card-header h3 { font-size: 1rem; font-weight: 600; }
        .card-body { padding: 1.25rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); padding: 1rem; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); border-left: 4px solid var(--primary-blue); display: flex; align-items: center; gap: 1rem; }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-icon { width: 45px; height: 45px; border-radius: 50%; background: var(--light-blue); display: flex; align-items: center; justify-content: center; color: var(--primary-blue); }
        .stat-number { font-size: 1.4rem; font-weight: 700; }
        .stat-label { font-size: 0.75rem; color: var(--dark-gray); }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .table th, .table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--medium-gray); }
        .table th { background: var(--light-gray); font-weight: 600; }
        .status-badge { padding: 0.25rem 0.5rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
        .status-scheduled { background: var(--light-blue); color: var(--primary-blue); }
        .status-ongoing { background: var(--warning); color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-postponed { background: var(--dark-gray); color: white; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: var(--border-radius); font-size: 0.8rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: var(--transition); }
        .btn-primary { background: var(--gradient-primary); color: white; }
        .btn-outline { background: transparent; border: 1px solid var(--primary-blue); color: var(--primary-blue); }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.7rem; }
        .alert { padding: 0.75rem 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; border-left: 4px solid; display: flex; align-items: center; gap: 0.75rem; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-danger { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        .form-control { width: 100%; padding: 0.6rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 1rem; margin-bottom: 1rem; }
        .empty-state { text-align: center; padding: 2rem; color: var(--dark-gray); }
        .pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .page-link { padding: 0.5rem 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); text-decoration: none; color: var(--text-dark); }
        .page-link.active { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .filters { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="overlay" id="mobileOverlay"></div>
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
                <div class="logos"><img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo"></div>
                <div class="brand-text"><h1>Isonga - President</h1></div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn"><i class="fas fa-chevron-left"></i></button>
                    <a href="messages.php" class="icon-btn"><i class="fas fa-envelope"></i><?php if ($unread_messages > 0): ?><span class="notification-badge"><?php echo $unread_messages; ?></span><?php endif; ?></a>
                </div>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Guild President</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Performance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="manage_committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php" >
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                       
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php" >
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
                    </a>
                </li>
                 <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Reports</span>
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

        <main class="main-content" id="mainContent">
            <div style="margin-bottom:1.5rem">
                <h1 style="font-size:1.5rem">Meeting Management</h1>
                <div style="margin-top:1rem; display:flex; gap:0.75rem; flex-wrap:wrap">
                    <a href="?action=add" class="btn btn-primary"><i class="fas fa-calendar-plus"></i> Schedule Meeting</a>
                    <a href="reports.php?type=meetings" class="btn btn-outline"><i class="fas fa-download"></i> Meeting Reports</a>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <?php if ($action === 'list'): ?>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="stat-number"><?php echo $total_meetings ?? 0; ?></div><div class="stat-label">Total Meetings</div></div></div>
                <div class="stat-card success"><div class="stat-icon"><i class="fas fa-calendar-week"></i></div><div><div class="stat-number"><?php echo $upcoming_meetings ?? 0; ?></div><div class="stat-label">Upcoming</div></div></div>
                <div class="stat-card warning"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div><div class="stat-number"><?php echo $completed_this_month ?? 0; ?></div><div class="stat-label">Completed This Month</div></div></div>
                <div class="stat-card danger"><div class="stat-icon"><i class="fas fa-chart-line"></i></div><div><div class="stat-number"><?php echo $average_attendance ?? 0; ?>%</div><div class="stat-label">Avg. Attendance</div></div></div>
            </div>
            <?php endif; ?>

            <!-- Meeting Form (Add/Edit) -->
            <?php if ($action === 'add' || $action === 'edit'): ?>
            <div class="card">
                <div class="card-header"><h3><?php echo $action === 'add' ? 'Schedule New Meeting' : 'Edit Meeting'; ?></h3></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group"><label class="form-label">Meeting Title *</label><input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars($meeting_data['title'] ?? ''); ?>" required></div>
                            <div class="form-group"><label class="form-label">Meeting Type *</label><select class="form-control" name="meeting_type" required><option value="general" <?php echo (($meeting_data['meeting_type'] ?? '') == 'general') ? 'selected' : ''; ?>>General</option><option value="executive" <?php echo (($meeting_data['meeting_type'] ?? '') == 'executive') ? 'selected' : ''; ?>>Executive</option><option value="committee" <?php echo (($meeting_data['meeting_type'] ?? '') == 'committee') ? 'selected' : ''; ?>>Committee</option><option value="emergency" <?php echo (($meeting_data['meeting_type'] ?? '') == 'emergency') ? 'selected' : ''; ?>>Emergency</option></select></div>
                        </div>
                        <div class="form-group"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($meeting_data['description'] ?? ''); ?></textarea></div>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label">Location *</label><input type="text" class="form-control" name="location" value="<?php echo htmlspecialchars($meeting_data['location'] ?? ''); ?>" required></div>
                            <div class="form-group"><label class="form-label">Chairperson</label><select class="form-control" name="chairperson_id"><option value="">Select Chairperson</option><?php foreach ($users as $u): ?><option value="<?php echo $u['id']; ?>" <?php echo (($meeting_data['chairperson_id'] ?? '') == $u['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['full_name']); ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label">Meeting Date *</label><input type="date" class="form-control" name="meeting_date" value="<?php echo htmlspecialchars($meeting_data['meeting_date'] ?? ''); ?>" required></div>
                            <div class="form-group"><label class="form-label">Start Time *</label><input type="time" class="form-control" name="start_time" value="<?php echo htmlspecialchars($meeting_data['start_time'] ?? ''); ?>" required></div>
                            <div class="form-group"><label class="form-label">End Time *</label><input type="time" class="form-control" name="end_time" value="<?php echo htmlspecialchars($meeting_data['end_time'] ?? ''); ?>" required></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label><input type="checkbox" name="is_committee_meeting" value="1" <?php echo ($meeting_data['is_committee_meeting'] ?? 0) ? 'checked' : ''; ?>> Committee Meeting</label></div>
                            <div class="form-group"><label class="form-label">Committee Role</label><select class="form-control" name="committee_role"><option value="">Select Role</option><option value="guild_president" <?php echo (($meeting_data['committee_role'] ?? '') == 'guild_president') ? 'selected' : ''; ?>>Guild President</option><option value="vice_guild_academic" <?php echo (($meeting_data['committee_role'] ?? '') == 'vice_guild_academic') ? 'selected' : ''; ?>>Vice Guild Academic</option></select></div>
                        </div>
                        <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Schedule Meeting' : 'Update Meeting'; ?></button> <a href="meetings.php" class="btn btn-outline">Cancel</a></div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Meeting View Details -->
            <?php if ($action === 'view' && $meeting_data): ?>
            <div class="card">
                <div class="card-header"><h3>Meeting Details</h3><div><a href="?action=edit&id=<?php echo $meeting_id; ?>" class="btn btn-outline btn-sm">Edit</a> <a href="?action=attendance&id=<?php echo $meeting_id; ?>" class="btn btn-primary btn-sm">Record Attendance</a></div></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Title</label><div><?php echo htmlspecialchars($meeting_data['title']); ?></div></div>
                        <div class="form-group"><label class="form-label">Status</label><div><span class="status-badge status-<?php echo $meeting_data['status']; ?>"><?php echo ucfirst($meeting_data['status']); ?></span></div></div>
                        <div class="form-group"><label class="form-label">Date</label><div><?php echo date('F j, Y', strtotime($meeting_data['meeting_date'])); ?></div></div>
                        <div class="form-group"><label class="form-label">Time</label><div><?php echo date('g:i A', strtotime($meeting_data['start_time'])); ?> - <?php echo date('g:i A', strtotime($meeting_data['end_time'])); ?></div></div>
                        <div class="form-group"><label class="form-label">Location</label><div><?php echo htmlspecialchars($meeting_data['location']); ?></div></div>
                        <div class="form-group"><label class="form-label">Chairperson</label><div><?php echo htmlspecialchars($meeting_data['chairperson_name'] ?? 'N/A'); ?></div></div>
                    </div>
                    <?php if (!empty($meeting_data['description'])): ?><div class="form-group"><label class="form-label">Description</label><div><?php echo nl2br(htmlspecialchars($meeting_data['description'])); ?></div></div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Meetings List with Filters -->
            <?php if ($action === 'list'): ?>
            <div class="card">
                <div class="card-header"><h3>Filters</h3></div>
                <div class="card-body">
                    <form method="GET" action="">
                        <div class="filters">
                            <input type="text" class="form-control" name="search" placeholder="Search by title, location..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                            <select class="form-control" name="status"><option value="">All Status</option><option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option><option value="ongoing" <?php echo $status_filter == 'ongoing' ? 'selected' : ''; ?>>Ongoing</option><option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option><option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option></select>
                            <select class="form-control" name="type"><option value="">All Types</option><option value="general" <?php echo $type_filter == 'general' ? 'selected' : ''; ?>>General</option><option value="executive" <?php echo $type_filter == 'executive' ? 'selected' : ''; ?>>Executive</option><option value="committee" <?php echo $type_filter == 'committee' ? 'selected' : ''; ?>>Committee</option></select>
                            <input type="date" class="form-control" name="date_from" placeholder="From" value="<?php echo htmlspecialchars($date_from ?? ''); ?>">
                            <input type="date" class="form-control" name="date_to" placeholder="To" value="<?php echo htmlspecialchars($date_to ?? ''); ?>">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                            <a href="meetings.php" class="btn btn-outline">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3>Meetings List (<?php echo $filtered_total ?? 0; ?> meetings)</h3></div>
                <div class="card-body">
                    <?php if (empty($meetings)): ?>
                        <div class="empty-state"><i class="fas fa-calendar-times" style="font-size:2rem"></i><p>No meetings found.</p><a href="?action=add" class="btn btn-primary">Schedule First Meeting</a></div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead><tr><th>Title</th><th>Date & Time</th><th>Location</th><th>Type</th><th>Status</th><th>Attendance</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($meetings as $meeting): 
                                        $attendance_rate = ($meeting['total_attendees'] ?? 0) > 0 ? round(($meeting['present_count'] ?? 0) / ($meeting['total_attendees'] ?? 1) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($meeting['title']); ?></strong></td>
                                        <td><?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?><br><small><?php echo date('g:i A', strtotime($meeting['start_time'])); ?></small></td>
                                        <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                        <td><?php echo ucfirst($meeting['meeting_type']); ?></td>
                                        <td><span class="status-badge status-<?php echo $meeting['status']; ?>"><?php echo ucfirst($meeting['status']); ?></span></td>
                                        <td><?php if($meeting['status'] === 'completed' && ($meeting['total_attendees'] ?? 0) > 0): ?><?php echo $attendance_rate; ?>% (<?php echo $meeting['present_count']; ?>/<?php echo $meeting['total_attendees']; ?>)<?php else: ?>—<?php endif; ?></td>
                                        <td class="action-buttons">
                                            <a href="?action=view&id=<?php echo $meeting['id']; ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a>
                                            <a href="?action=edit&id=<?php echo $meeting['id']; ?>" class="btn btn-outline btn-sm"><i class="fas fa-edit"></i></a>
                                            <?php if ($meeting['status'] !== 'completed'): ?>
                                                <a href="?action=attendance&id=<?php echo $meeting['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-clipboard-check"></i></a>
                                                <a href="?action=complete&id=<?php echo $meeting['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('Mark as completed?')"><i class="fas fa-check"></i></a>
                                            <?php endif; ?>
                                            <a href="?action=delete&id=<?php echo $meeting['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete meeting?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php for ($i=1; $i<=$total_pages; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$i])); ?>" class="page-link <?php echo ($page==$i)?'active':''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const savedState = localStorage.getItem('sidebarCollapsed');
        if (savedState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if(sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
            if(sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if(sidebarToggle) sidebarToggle.innerHTML = icon;
            if(sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }
        if(sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if(sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
        
        // Mobile menu
        const mobileToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        if(mobileToggle) {
            mobileToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        if(mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if(mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }
        window.addEventListener('resize', () => {
            if(window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if(mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>