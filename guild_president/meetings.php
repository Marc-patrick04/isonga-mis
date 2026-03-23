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

// Get dashboard statistics for sidebar
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'];
    } catch (Exception $e) {
        error_log("Reports table query error: " . $e->getMessage());
        $pending_reports = 0;
    }
    
    // Unread messages
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    } catch (Exception $e) {
        error_log("Messages query error: " . $e->getMessage());
        $unread_messages = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];
    } catch (Exception $e) {
        error_log("Documents table error: " . $e->getMessage());
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_tickets = $open_tickets = $pending_reports = $unread_messages = $pending_docs = 0;
}

// Handle form actions
$action = $_GET['action'] ?? 'list';
$meeting_id = $_GET['id'] ?? null;
$message = '';
$message_type = '';

// Add/Edit Meeting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || $action === 'edit')) {
    // Only process meeting form data for add/edit actions
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $meeting_type = $_POST['meeting_type'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $meeting_date = $_POST['meeting_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $chairperson_id = $_POST['chairperson_id'] ?? '';
    $is_committee_meeting = isset($_POST['is_committee_meeting']) ? 1 : 0;
    $committee_role = $_POST['committee_role'] ?? null;
    
    try {
        if ($action === 'add') {
            $insert_stmt = $pdo->prepare("
                INSERT INTO meetings (title, description, meeting_type, committee_role, chairperson_id, 
                                    location, meeting_date, start_time, end_time, is_committee_meeting, 
                                    status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
            ");
            
            $insert_stmt->execute([
                $title, $description, $meeting_type, $committee_role, $chairperson_id,
                $location, $meeting_date, $start_time, $end_time, $is_committee_meeting, $user_id
            ]);
            
            $meeting_id = $pdo->lastInsertId();
            
            // Add agenda items if provided
            if (isset($_POST['agenda_titles']) && is_array($_POST['agenda_titles'])) {
                foreach ($_POST['agenda_titles'] as $index => $agenda_title) {
                    if (!empty(trim($agenda_title ?? ''))) {
                        $agenda_desc = $_POST['agenda_descriptions'][$index] ?? '';
                        $duration = $_POST['agenda_durations'][$index] ?? 15;
                        $order_index = $index + 1;
                        
                        $agenda_stmt = $pdo->prepare("
                            INSERT INTO meeting_agenda_items (meeting_id, title, description, duration_minutes, order_index)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $agenda_stmt->execute([$meeting_id, $agenda_title, $agenda_desc, $duration, $order_index]);
                    }
                }
            }
            
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
        
        // Delete the meeting
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

// Update Meeting Status
if ($action === 'update_status' && $meeting_id) {
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['scheduled', 'ongoing', 'completed', 'cancelled', 'postponed'])) {
        try {
            $update_stmt = $pdo->prepare("UPDATE meetings SET status = ? WHERE id = ?");
            $update_stmt->execute([$status, $meeting_id]);
            
            $message = "Meeting status updated successfully!";
            $message_type = 'success';
            $action = 'list';
        } catch (PDOException $e) {
            error_log("Update status error: " . $e->getMessage());
            $message = "Error updating status: " . $e->getMessage();
            $message_type = 'error';
        }
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
                // Update existing record
                $update_stmt = $pdo->prepare("
                    UPDATE meeting_attendance 
                    SET attendance_status = ?, notes = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE meeting_id = ? AND committee_member_id = ?
                ");
                $update_stmt->execute([$attendance_status, $notes, $user_id, $meeting_id, $member_id]);
                $records_updated++;
            } else {
                // Insert new record
                $insert_stmt = $pdo->prepare("
                    INSERT INTO meeting_attendance (meeting_id, committee_member_id, attendance_status, notes, recorded_by, created_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $insert_stmt->execute([$meeting_id, $member_id, $attendance_status, $notes, $user_id]);
                $records_created++;
            }
        }
        
        $message = "Attendance recorded successfully! ($records_created new records, $records_updated updated)";
        $message_type = 'success';
        $action = 'view';
        
    } catch (PDOException $e) {
        error_log("Attendance recording error: " . $e->getMessage());
        $message = "Error recording attendance: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get meeting data for editing/viewing
$meeting_data = [];
$agenda_items = [];
$attendance_records = [];
if (($action === 'edit' || $action === 'view' || $action === 'attendance') && $meeting_id) {
    try {
        // Get meeting details
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

// Get committee members for dropdowns
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

// Get meetings list with filtering and pagination
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query for meetings list
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
    $query .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.location LIKE ?)";
    $count_query .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.location LIKE ?)";
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
    $filtered_total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
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

// Get statistics for dashboard cards
try {
    // Total meetings
    $total_meetings = $pdo->query("SELECT COUNT(*) as count FROM meetings")->fetch()['count'];
    
    // Upcoming meetings
    $upcoming_meetings = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE meeting_date >= CURDATE() AND status = 'scheduled'")->fetch()['count'];
    
    // Completed meetings this month
    $completed_this_month = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE status = 'completed' AND MONTH(meeting_date) = MONTH(CURRENT_DATE()) AND YEAR(meeting_date) = YEAR(CURRENT_DATE())")->fetch()['count'];
    
    // Average attendance rate
    $attendance_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_meetings,
            SUM(present_count) as total_present,
            SUM(total_attendees) as total_expected
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
    if ($attendance_stats['total_expected'] > 0) {
        $average_attendance = round(($attendance_stats['total_present'] / $attendance_stats['total_expected']) * 100);
    }
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_meetings = $upcoming_meetings = $completed_this_month = $average_attendance = 0;
}

// Complete Meeting (Change status to completed)
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --border-radius: 8px;
            --transition: all 0.2s ease;
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
            height: 80px;
            display: flex;
            align-items: center;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--primary-blue);
            transform: scale(1.05);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1.1rem;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 80px;
            height: calc(100vh - 80px);
            overflow-y: auto;
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
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .committee-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-left: 4px solid var(--primary-blue);
        }

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.5rem;
        }

        .performance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .performance-table th,
        .performance-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .performance-table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
        }

        .performance-table tr:hover {
            background: var(--light-blue);
        }

        .btn {
            padding: 0.5rem 1rem;
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

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-info {
            background: var(--light-blue);
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

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled { background: var(--light-blue); color: var(--primary-blue); }
        .status-ongoing { background: var(--warning); color: black; }
        .status-completed { background: var(--success); color: white; }
        .status-cancelled { background: var(--danger); color: white; }
        .status-postponed { background: var(--dark-gray); color: white; }

        /* Table Styles */
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

        .table tr:hover {
            background: var(--light-blue);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        /* Filters */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
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

        /* Meeting Details */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            color: var(--text-dark);
            font-weight: 500;
        }

        /* Agenda Items */
        .agenda-form {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            position: relative;
        }

        .remove-agenda {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.7rem;
        }

        .agenda-item {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 3px solid var(--primary-blue);
        }

        /* Attendance Grid */
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .attendance-card {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            transition: var(--transition);
        }

        .attendance-card:hover {
            box-shadow: var(--shadow-sm);
        }

        .attendance-present {
            border-left: 4px solid var(--success);
        }

        .attendance-absent {
            border-left: 4px solid var(--danger);
        }

        .attendance-excused {
            border-left: 4px solid var(--warning);
        }

        .attendance-rate {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            text-align: center;
            margin-bottom: 0.5rem;
        }

        /* Card Header Actions */
        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }

            .attendance-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .committee-stats {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - President</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
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
                        <div class="user-role">Guild President</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar">
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
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php 
                        // Get new student registrations count (last 7 days)
                        try {
                            $new_students_stmt = $pdo->prepare("
                                SELECT COUNT(*) as new_students 
                                FROM users 
                                WHERE role = 'student' 
                                AND status = 'active' 
                                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            ");
                            $new_students_stmt->execute();
                            $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'];
                        } catch (PDOException $e) {
                            $new_students = 0;
                        }
                        ?>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
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
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
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

        <main class="main-content">
            <div class="container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Meeting Management</h1>
                        <p>Schedule meetings, track attendance, and manage committee gatherings</p>
                    </div>
                    <div style="display: flex; gap: 0.75rem;">
                        <?php if ($action === 'list'): ?>
                            <a href="?action=add" class="btn btn-primary">
                                <i class="fas fa-calendar-plus"></i> Schedule Meeting
                            </a>
                            <a href="reports.php?type=meetings" class="btn btn-outline">
                                <i class="fas fa-download"></i> Meeting Reports
                            </a>
                        <?php else: ?>
                            <a href="meetings.php" class="btn btn-outline">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Message Alert -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Meeting Statistics -->
                <?php if ($action === 'list'): ?>
                <div class="committee-stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_meetings; ?></div>
                        <div class="stat-label">Total Meetings</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-number"><?php echo $upcoming_meetings; ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-number"><?php echo $completed_this_month; ?></div>
                        <div class="stat-label">Completed This Month</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-number"><?php echo $average_attendance; ?>%</div>
                        <div class="stat-label">Avg. Attendance</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meeting Form (Add/Edit) -->
                <?php if ($action === 'add' || $action === 'edit'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><?php echo $action === 'add' ? 'Schedule New Meeting' : 'Edit Meeting'; ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="title">Meeting Title *</label>
                                    <input type="text" class="form-control" id="title" name="title" 
                                           value="<?php echo htmlspecialchars($meeting_data['title'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="meeting_type">Meeting Type *</label>
                                    <select class="form-control" id="meeting_type" name="meeting_type" required>
                                        <option value="general" <?php echo ($meeting_data['meeting_type'] ?? '') == 'general' ? 'selected' : ''; ?>>General</option>
                                        <option value="executive" <?php echo ($meeting_data['meeting_type'] ?? '') == 'executive' ? 'selected' : ''; ?>>Executive</option>
                                        <option value="committee" <?php echo ($meeting_data['meeting_type'] ?? '') == 'committee' ? 'selected' : ''; ?>>Committee</option>
                                        <option value="emergency" <?php echo ($meeting_data['meeting_type'] ?? '') == 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                        <option value="planning" <?php echo ($meeting_data['meeting_type'] ?? '') == 'planning' ? 'selected' : ''; ?>>Planning</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="description">Meeting Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($meeting_data['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="location">Location *</label>
                                    <input type="text" class="form-control" id="location" name="location" 
                                           value="<?php echo htmlspecialchars($meeting_data['location'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="chairperson_id">Chairperson *</label>
                                    <select class="form-control" id="chairperson_id" name="chairperson_id" required>
                                        <option value="">Select Chairperson</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" 
                                                <?php echo ($meeting_data['chairperson_id'] ?? '') == $user['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['role'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="meeting_date">Meeting Date *</label>
                                    <input type="date" class="form-control" id="meeting_date" name="meeting_date" 
                                           value="<?php echo htmlspecialchars($meeting_data['meeting_date'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="start_time">Start Time *</label>
                                    <input type="time" class="form-control" id="start_time" name="start_time" 
                                           value="<?php echo htmlspecialchars($meeting_data['start_time'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="end_time">End Time *</label>
                                    <input type="time" class="form-control" id="end_time" name="end_time" 
                                           value="<?php echo htmlspecialchars($meeting_data['end_time'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <input type="checkbox" id="is_committee_meeting" name="is_committee_meeting" value="1" 
                                               <?php echo ($meeting_data['is_committee_meeting'] ?? 0) ? 'checked' : ''; ?>>
                                        Committee Meeting
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="committee_role">Committee Role (if applicable)</label>
                                    <select class="form-control" id="committee_role" name="committee_role">
                                        <option value="">Select Committee Role</option>
                                        <option value="guild_president" <?php echo ($meeting_data['committee_role'] ?? '') == 'guild_president' ? 'selected' : ''; ?>>Guild President</option>
                                        <option value="vice_guild_academic" <?php echo ($meeting_data['committee_role'] ?? '') == 'vice_guild_academic' ? 'selected' : ''; ?>>Vice Guild Academic</option>
                                        <option value="vice_guild_finance" <?php echo ($meeting_data['committee_role'] ?? '') == 'vice_guild_finance' ? 'selected' : ''; ?>>Vice Guild Finance</option>
                                        <option value="general_secretary" <?php echo ($meeting_data['committee_role'] ?? '') == 'general_secretary' ? 'selected' : ''; ?>>General Secretary</option>
                                        <!-- Add other committee roles as needed -->
                                    </select>
                                </div>
                            </div>

                            <!-- Agenda Items Section -->
                            <div class="form-group">
                                <label class="form-label">Meeting Agenda</label>
                                <div id="agenda-items">
                                    <?php if (!empty($agenda_items)): ?>
                                        <?php foreach ($agenda_items as $index => $agenda): ?>
                                            <div class="agenda-form">
                                                <div class="form-row">
                                                    <div class="form-group">
                                                        <label class="form-label">Agenda Title</label>
                                                        <input type="text" class="form-control" name="agenda_titles[]" 
                                                               value="<?php echo htmlspecialchars($agenda['title']); ?>" placeholder="Enter agenda item title">
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label">Duration (minutes)</label>
                                                        <input type="number" class="form-control" name="agenda_durations[]" 
                                                               value="<?php echo htmlspecialchars($agenda['duration_minutes']); ?>" min="5" max="120" value="15">
                                                    </div>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label">Description</label>
                                                    <textarea class="form-control" name="agenda_descriptions[]" rows="2" placeholder="Brief description of this agenda item"><?php echo htmlspecialchars($agenda['description']); ?></textarea>
                                                </div>
                                                <button type="button" class="remove-agenda" onclick="this.parentElement.remove()">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="agenda-form">
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label class="form-label">Agenda Title</label>
                                                    <input type="text" class="form-control" name="agenda_titles[]" placeholder="Enter agenda item title">
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label">Duration (minutes)</label>
                                                    <input type="number" class="form-control" name="agenda_durations[]" min="5" max="120" value="15">
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="agenda_descriptions[]" rows="2" placeholder="Brief description of this agenda item"></textarea>
                                            </div>
                                            <button type="button" class="remove-agenda" onclick="this.parentElement.remove()">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-outline btn-sm" onclick="addAgendaItem()">
                                    <i class="fas fa-plus"></i> Add Agenda Item
                                </button>
                            </div>
                            
                            <div class="form-group" style="margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'add' ? 'Schedule Meeting' : 'Update Meeting'; ?>
                                </button>
                                <a href="meetings.php" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meeting Details View -->
                <?php if ($action === 'view' && $meeting_data): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Meeting Details</h3>
                        <div class="card-header-actions">
                            <a href="?action=edit&id=<?php echo $meeting_id; ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="?action=attendance&id=<?php echo $meeting_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-clipboard-check"></i> Record Attendance
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="meeting-details">
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Status</span>
                                    <span class="status-badge status-<?php echo $meeting_data['status']; ?>">
                                        <?php echo ucfirst($meeting_data['status']); ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Date</span>
                                    <span class="detail-value"><?php echo date('F j, Y', strtotime($meeting_data['meeting_date'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Time</span>
                                    <span class="detail-value">
                                        <?php echo date('g:i A', strtotime($meeting_data['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($meeting_data['end_time'])); ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Location</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($meeting_data['location']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Chairperson</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($meeting_data['chairperson_name']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Type</span>
                                    <span class="detail-value"><?php echo ucfirst($meeting_data['meeting_type']); ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($meeting_data['description'])): ?>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius);">
                                <?php echo nl2br(htmlspecialchars($meeting_data['description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Agenda Items -->
                        <?php if (!empty($agenda_items)): ?>
                        <div class="form-group">
                            <label class="form-label">Meeting Agenda</label>
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

                        <!-- Attendance Summary -->
                        <?php if (!empty($attendance_records)): ?>
                        <div class="form-group">
                            <label class="form-label">Attendance Summary</label>
                            <div class="attendance-grid">
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
                                    <div class="attendance-rate"><?php echo $attendance_rate; ?>%</div>
                                    <div>Overall Attendance Rate</div>
                                </div>
                                <div class="attendance-card attendance-present">
                                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);"><?php echo $present_count; ?></div>
                                    <div>Present</div>
                                </div>
                                <div class="attendance-card attendance-absent">
                                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--danger);"><?php echo $absent_count; ?></div>
                                    <div>Absent</div>
                                </div>
                                <div class="attendance-card attendance-excused">
                                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning);"><?php echo $excused_count; ?></div>
                                    <div>Excused</div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Attendance Recording -->
                <?php if ($action === 'attendance' && $meeting_data): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Record Attendance - <?php echo htmlspecialchars($meeting_data['title']); ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?action=record_attendance&id=<?php echo $meeting_id; ?>">
                            <div class="attendance-grid">
                                <?php foreach ($committee_members as $member): 
                                    $existing_record = null;
                                    foreach ($attendance_records as $record) {
                                        if ($record['committee_member_id'] == $member['id']) {
                                            $existing_record = $record;
                                            break;
                                        }
                                    }
                                ?>
                                    <div class="attendance-card attendance-<?php echo $existing_record['attendance_status'] ?? 'absent'; ?>">
                                        <div style="margin-bottom: 1rem;">
                                            <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                                            <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                                <?php echo htmlspecialchars(str_replace('_', ' ', $member['role'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Attendance</label>
                                            <select class="form-control" name="attendance_<?php echo $member['id']; ?>">
                                                <option value="present" <?php echo ($existing_record['attendance_status'] ?? '') == 'present' ? 'selected' : ''; ?>>Present</option>
                                                <option value="absent" <?php echo ($existing_record['attendance_status'] ?? '') == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                <option value="excused" <?php echo ($existing_record['attendance_status'] ?? '') == 'excused' ? 'selected' : ''; ?>>Excused</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" name="notes_<?php echo $member['id']; ?>" rows="2" placeholder="Optional notes"><?php echo htmlspecialchars($existing_record['notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="form-group" style="margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Attendance
                                </button>
                                <a href="?action=view&id=<?php echo $meeting_id; ?>" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meetings List -->
                <?php if ($action === 'list'): ?>
                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="filters">
                                <div class="filter-group">
                                    <label class="filter-label" for="search">Search Meetings</label>
                                    <input type="text" class="form-control" id="search" name="search" 
                                           placeholder="Search by title, description, or location..."
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label" for="status">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="">All Status</option>
                                        <option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="ongoing" <?php echo $status_filter == 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        <option value="postponed" <?php echo $status_filter == 'postponed' ? 'selected' : ''; ?>>Postponed</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label" for="type">Type</label>
                                    <select class="form-control" id="type" name="type">
                                        <option value="">All Types</option>
                                        <option value="general" <?php echo $type_filter == 'general' ? 'selected' : ''; ?>>General</option>
                                        <option value="executive" <?php echo $type_filter == 'executive' ? 'selected' : ''; ?>>Executive</option>
                                        <option value="committee" <?php echo $type_filter == 'committee' ? 'selected' : ''; ?>>Committee</option>
                                        <option value="emergency" <?php echo $type_filter == 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                        <option value="planning" <?php echo $type_filter == 'planning' ? 'selected' : ''; ?>>Planning</option>
                                    </select>
                                </div>
                            </div>
                            <div class="filters" style="margin-top: 1rem;">
                                <div class="filter-group">
                                    <label class="filter-label" for="date_from">From Date</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($date_from); ?>">
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label" for="date_to">To Date</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($date_to); ?>">
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label" style="visibility: hidden;">Apply</label>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter"></i> Apply Filters
                                        </button>
                                        <a href="meetings.php" class="btn btn-outline">Clear Filters</a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Meetings Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>Meetings List (<?php echo $filtered_total; ?> meetings)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($meetings)): ?>
                            <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No meetings found matching your criteria.</p>
                                <?php if ($search || $status_filter || $type_filter || $date_from || $date_to): ?>
                                    <a href="meetings.php" class="btn btn-primary">Clear Filters</a>
                                <?php else: ?>
                                    <a href="?action=add" class="btn btn-primary">Schedule First Meeting</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Type</th>
                                            <th>Chairperson</th>
                                            <th>Status</th>
                                            <th>Attendance</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($meetings as $meeting): 
                                            $attendance_rate = 0;
                                            if ($meeting['total_attendees'] > 0) {
                                                $attendance_rate = round(($meeting['present_count'] / $meeting['total_attendees']) * 100);
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                    <?php if ($meeting['is_committee_meeting']): ?>
                                                        <br><small style="color: var(--dark-gray);">Committee Meeting</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($meeting['start_time'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                                <td><?php echo ucfirst($meeting['meeting_type']); ?></td>
                                                <td><?php echo htmlspecialchars($meeting['chairperson_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                                        <?php echo ucfirst($meeting['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $total_committee = $meeting['total_committee_members'] ?? 0;
                                                    $total_attended = $meeting['present_count'] ?? 0;
                                                    
                                                    if ($meeting['status'] === 'completed' && $total_committee > 0): 
                                                        $attendance_rate = round(($total_attended / $total_committee) * 100);
                                                    ?>
                                                        <div style="font-weight: 600; color: var(--success);"><?php echo $attendance_rate; ?>%</div>
                                                        <small><?php echo $total_attended; ?>/<?php echo $total_committee; ?> present</small>
                                                    <?php elseif ($meeting['status'] === 'completed'): ?>
                                                        <div style="font-weight: 600; color: var(--warning);">No data</div>
                                                        <small>Committee members not set</small>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?action=view&id=<?php echo $meeting['id']; ?>" 
                                                           class="btn btn-outline btn-sm" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="?action=edit&id=<?php echo $meeting['id']; ?>" 
                                                           class="btn btn-outline btn-sm" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($meeting['status'] === 'scheduled' || $meeting['status'] === 'ongoing'): ?>
                                                            <a href="?action=attendance&id=<?php echo $meeting['id']; ?>" 
                                                               class="btn btn-primary btn-sm" title="Record Attendance">
                                                                <i class="fas fa-clipboard-check"></i>
                                                            </a>
                                                            <a href="?action=complete&id=<?php echo $meeting['id']; ?>" 
                                                               class="btn btn-success btn-sm" 
                                                               onclick="return confirm('Mark this meeting as completed?')"
                                                               title="Complete Meeting">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="?action=delete&id=<?php echo $meeting['id']; ?>" 
                                                           class="btn btn-danger btn-sm" 
                                                           onclick="return confirm('Are you sure you want to delete this meeting? This action cannot be undone.')"
                                                           title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
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
            </div>
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

        // Agenda Items Management
        function addAgendaItem() {
            const agendaContainer = document.getElementById('agenda-items');
            const newAgenda = document.createElement('div');
            newAgenda.className = 'agenda-form';
            newAgenda.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Agenda Title</label>
                        <input type="text" class="form-control" name="agenda_titles[]" placeholder="Enter agenda item title">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Duration (minutes)</label>
                        <input type="number" class="form-control" name="agenda_durations[]" min="5" max="120" value="15">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="agenda_descriptions[]" rows="2" placeholder="Brief description of this agenda item"></textarea>
                </div>
                <button type="button" class="remove-agenda" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            agendaContainer.appendChild(newAgenda);
        }

        // Committee Meeting Checkbox Toggle
        const committeeCheckbox = document.getElementById('is_committee_meeting');
        const committeeRoleSelect = document.getElementById('committee_role');
        
        if (committeeCheckbox && committeeRoleSelect) {
            function toggleCommitteeRole() {
                committeeRoleSelect.disabled = !committeeCheckbox.checked;
                if (!committeeCheckbox.checked) {
                    committeeRoleSelect.value = '';
                }
            }
            
            committeeCheckbox.addEventListener('change', toggleCommitteeRole);
            // Initial state
            toggleCommitteeRole();
        }

        // Date Validation
        const meetingDateInput = document.getElementById('meeting_date');
        if (meetingDateInput) {
            // Set min date to today
            const today = new Date().toISOString().split('T')[0];
            meetingDateInput.min = today;
        }

        // Time Validation
        const startTimeInput = document.getElementById('start_time');
        const endTimeInput = document.getElementById('end_time');
        
        if (startTimeInput && endTimeInput) {
            function validateTimes() {
                if (startTimeInput.value && endTimeInput.value) {
                    if (startTimeInput.value >= endTimeInput.value) {
                        endTimeInput.setCustomValidity('End time must be after start time');
                    } else {
                        endTimeInput.setCustomValidity('');
                    }
                }
            }
            
            startTimeInput.addEventListener('change', validateTimes);
            endTimeInput.addEventListener('change', validateTimes);
        }
    </script>
</body>
</html>