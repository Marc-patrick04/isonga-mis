<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_representative_board') {
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
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Handle form actions
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Add/Edit Meeting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_meeting'])) {
            $title = $_POST['title'];
            $description = $_POST['description'];
            $meeting_type = $_POST['meeting_type'];
            $location = $_POST['location'];
            $meeting_date = $_POST['meeting_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $agenda = $_POST['agenda'];
            $required_attendees = isset($_POST['attendees']) ? json_encode($_POST['attendees']) : json_encode([]);

            // Validate date and time
            if (strtotime($meeting_date) < strtotime(date('Y-m-d'))) {
                throw new Exception("Meeting date cannot be in the past.");
            }

            if (strtotime($end_time) <= strtotime($start_time)) {
                throw new Exception("End time must be after start time.");
            }

            // Insert meeting (PostgreSQL compatible - using NOW() instead of NOW())
            $stmt = $pdo->prepare("
                INSERT INTO rep_meetings 
                (title, description, meeting_type, organizer_id, location, meeting_date, start_time, end_time, agenda, required_attendees, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $title, $description, $meeting_type, $user_id, $location, $meeting_date, 
                $start_time, $end_time, $agenda, $required_attendees, $user_id
            ]);

            $meeting_id = $pdo->lastInsertId();

            // Add agenda items if provided
            if (isset($_POST['agenda_items']) && is_array($_POST['agenda_items'])) {
                foreach ($_POST['agenda_items'] as $index => $agenda_item) {
                    if (!empty(trim($agenda_item))) {
                        $stmt = $pdo->prepare("
                            INSERT INTO rep_meeting_agenda_items 
                            (meeting_id, title, order_index) 
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$meeting_id, trim($agenda_item), $index]);
                    }
                }
            }

            $message = "Meeting scheduled successfully!";
            $message_type = "success";

        } elseif (isset($_POST['update_meeting_status'])) {
            $meeting_id = $_POST['meeting_id'];
            $status = $_POST['status'];

            $stmt = $pdo->prepare("UPDATE rep_meetings SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $meeting_id]);

            $message = "Meeting status updated successfully!";
            $message_type = "success";

        } elseif (isset($_POST['record_attendance'])) {
            $meeting_id = $_POST['meeting_id'];
            $attendances = $_POST['attendance'];

            foreach ($attendances as $user_id_att => $status) {
                // Check if attendance record exists
                $stmt = $pdo->prepare("SELECT id FROM rep_meeting_attendance WHERE meeting_id = ? AND user_id = ?");
                $stmt->execute([$meeting_id, $user_id_att]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Update existing (PostgreSQL uses CURRENT_TIMESTAMP)
                    $stmt = $pdo->prepare("
                        UPDATE rep_meeting_attendance 
                        SET attendance_status = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE meeting_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$status, $user_id, $meeting_id, $user_id_att]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("
                        INSERT INTO rep_meeting_attendance 
                        (meeting_id, user_id, attendance_status, recorded_by) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$meeting_id, $user_id_att, $status, $user_id]);
                }
            }

            $message = "Attendance recorded successfully!";
            $message_type = "success";
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Delete Meeting
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $meeting_id = $_GET['id'];
        
        // Delete related records first
        $pdo->prepare("DELETE FROM rep_meeting_attendance WHERE meeting_id = ?")->execute([$meeting_id]);
        $pdo->prepare("DELETE FROM rep_meeting_agenda_items WHERE meeting_id = ?")->execute([$meeting_id]);
        $pdo->prepare("DELETE FROM rep_meeting_action_items WHERE meeting_id = ?")->execute([$meeting_id]);
        
        // Delete meeting
        $stmt = $pdo->prepare("DELETE FROM rep_meetings WHERE id = ?");
        $stmt->execute([$meeting_id]);
        
        $message = "Meeting deleted successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Get all class representative meetings (PostgreSQL compatible)
try {
    // Main meetings query (PostgreSQL uses GROUP BY properly with all non-aggregated columns)
    $stmt = $pdo->query("
        SELECT 
            rm.*,
            u.full_name as organizer_name,
            COUNT(rma.id) as attendance_count,
            SUM(CASE WHEN rma.attendance_status = 'present' THEN 1 ELSE 0 END) as present_count
        FROM rep_meetings rm
        JOIN users u ON rm.organizer_id = u.id
        LEFT JOIN rep_meeting_attendance rma ON rm.id = rma.meeting_id
        GROUP BY rm.id, u.full_name
        ORDER BY rm.meeting_date DESC, rm.start_time DESC
    ");
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get class representatives for attendee selection (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("
        SELECT u.id, u.full_name, u.reg_number, d.name as department_name, p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.is_class_rep = true AND u.status = 'active'
        ORDER BY u.full_name
    ");
    $class_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistics (PostgreSQL uses CURRENT_DATE instead of CURDATE())
    $stmt = $pdo->query("SELECT COUNT(*) as total_meetings FROM rep_meetings");
    $total_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['total_meetings'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as completed_meetings FROM rep_meetings WHERE status = 'completed'");
    $completed_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['completed_meetings'] ?? 0;

    // Get specific meeting details for edit/view
    $edit_meeting = null;
    $agenda_items = [];
    $attendance = [];
    
    if (isset($_GET['id']) && ($action === 'edit' || $action === 'view' || $action === 'attendance')) {
        $meeting_id = $_GET['id'];
        $stmt = $pdo->prepare("
            SELECT rm.*, u.full_name as organizer_name
            FROM rep_meetings rm
            JOIN users u ON rm.organizer_id = u.id
            WHERE rm.id = ?
        ");
        $stmt->execute([$meeting_id]);
        $edit_meeting = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get agenda items
        if ($edit_meeting) {
            $stmt = $pdo->prepare("SELECT * FROM rep_meeting_agenda_items WHERE meeting_id = ? ORDER BY order_index");
            $stmt->execute([$meeting_id]);
            $agenda_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get attendance
            $stmt = $pdo->prepare("
                SELECT rma.*, u.full_name, u.reg_number, d.name as department_name
                FROM rep_meeting_attendance rma
                JOIN users u ON rma.user_id = u.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE rma.meeting_id = ?
                ORDER BY u.full_name
            ");
            $stmt->execute([$meeting_id]);
            $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (PDOException $e) {
    $meetings = [];
    $class_reps = [];
    $total_meetings = $upcoming_meetings = $completed_meetings = 0;
    error_log("Class rep meetings data error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar (PostgreSQL compatible)
try {
    // Total reps count (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    // Pending minutes - check using rep_meetings table (PostgreSQL)
    $pending_minutes = 0;
    try {
        // Check if rep_meetings table exists via information_schema (PostgreSQL)
        $stmt = $pdo->query("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_name = 'rep_meetings'
            ) as table_exists
        ");
        $table_exists = $stmt->fetch(PDO::FETCH_ASSOC)['table_exists'] ?? false;
        
        if ($table_exists) {
            // Count meetings without minutes that are completed
            $stmt = $pdo->query("
                SELECT COUNT(*) as pending_minutes 
                FROM rep_meetings 
                WHERE (minutes IS NULL OR minutes = '') AND status = 'completed'
            ");
            $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'] ?? 0;
        } else {
            // Fallback: try meeting_minutes table
            $stmt = $pdo->query("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables 
                    WHERE table_name = 'meeting_minutes'
                ) as minutes_table_exists
            ");
            $minutes_table_exists = $stmt->fetch(PDO::FETCH_ASSOC)['minutes_table_exists'] ?? false;
            
            if ($minutes_table_exists) {
                $stmt = $pdo->query("SELECT COUNT(*) as pending_minutes FROM meeting_minutes WHERE approval_status = 'draft'");
                $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'] ?? 0;
            }
        }
    } catch (Exception $e) {
        error_log("Pending minutes query error: " . $e->getMessage());
    }
    
    // Upcoming meetings for sidebar (PostgreSQL uses CURRENT_DATE)
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_meetings 
        FROM rep_meetings 
        WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
    ");
    $sidebar_upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    // Unread messages (PostgreSQL compatible)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    
} catch (PDOException $e) {
    $sidebar_reps_count = 0;
    $pending_minutes = 0;
    $sidebar_upcoming_meetings = 0;
    $unread_messages = 0;
    error_log("Sidebar stats error: " . $e->getMessage());
}

// Set variables for sidebar display
$total_reps = $sidebar_reps_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Class Representative Meetings - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #007bff;
            --secondary-blue: #0056b3;
            --accent-blue: #0069d9;
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
            --primary-blue: #4dabf7;
            --secondary-blue: #339af0;
            --accent-blue: #228be6;
            --light-blue: #1a365d;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            position: relative;
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

        .menu-divider {
            height: 1px;
            background: var(--medium-gray);
            margin: 1rem 1.5rem;
        }

        .menu-section {
            padding: 0.75rem 1.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        /* Dashboard Header */
        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
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

        .stat-card.info {
            border-left-color: var(--info);
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
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

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
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
        .table-container {
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
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

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.7rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
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

        .attendee-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
        }

        .attendee-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: 4px;
        }

        .attendee-item input[type="checkbox"] {
            margin: 0;
        }

        .attendee-item label {
            cursor: pointer;
            font-size: 0.8rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            margin-bottom: 0.5rem;
            color: var(--text-dark);
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
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-number {
                font-size: 1.1rem;
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

            .welcome-section h1 {
                font-size: 1.2rem;
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
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Class Representative Meetings</h1>
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
                        <div class="user-role">Secretary - Representative Board</div>
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
                    <a href="class_reps.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Management</span>
                        <?php if ($total_reps > 0): ?>
                            <span class="menu-badge"><?php echo $total_reps; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_meetings.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_performance.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Class Rep Performance</span>
                    </a>
                </li>
                
                <li class="menu-divider"></li>
                <li class="menu-section">Other Features</li>
                
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-handshake"></i>
                        <span>Meetings</span>
                        <?php if ($sidebar_upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $sidebar_upcoming_meetings; ?></span>
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
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Class Representative Meetings</h1>
                    <p>Schedule and manage meetings with class representatives</p>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i> 
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_meetings); ?></div>
                        <div class="stat-label">Total Meetings</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($upcoming_meetings); ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($completed_meetings); ?></div>
                        <div class="stat-label">Completed Meetings</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format(count($class_reps)); ?></div>
                        <div class="stat-label">Class Representatives</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <?php if ($action === 'add' || $action === 'edit'): ?>
                        <!-- Add/Edit Meeting Form -->
                        <div class="card">
                            <div class="card-header">
                                <h3><?php echo $action === 'add' ? 'Schedule New Meeting' : 'Edit Meeting'; ?></h3>
                                <div class="card-header-actions">
                                    <a href="class_rep_meetings.php" class="card-header-btn" title="Back to Meetings">
                                        <i class="fas fa-arrow-left"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                        <div class="form-group">
                                            <label class="form-label" for="title">Meeting Title *</label>
                                            <input type="text" class="form-control" id="title" name="title" 
                                                   value="<?php echo htmlspecialchars($edit_meeting['title'] ?? ''); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="meeting_type">Meeting Type *</label>
                                            <select class="form-control" id="meeting_type" name="meeting_type" required>
                                                <option value="general" <?php echo ($edit_meeting['meeting_type'] ?? '') === 'general' ? 'selected' : ''; ?>>General</option>
                                                <option value="emergency" <?php echo ($edit_meeting['meeting_type'] ?? '') === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                                <option value="planning" <?php echo ($edit_meeting['meeting_type'] ?? '') === 'planning' ? 'selected' : ''; ?>>Planning</option>
                                                <option value="review" <?php echo ($edit_meeting['meeting_type'] ?? '') === 'review' ? 'selected' : ''; ?>>Review</option>
                                                <option value="training" <?php echo ($edit_meeting['meeting_type'] ?? '') === 'training' ? 'selected' : ''; ?>>Training</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="description">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($edit_meeting['description'] ?? ''); ?></textarea>
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                                        <div class="form-group">
                                            <label class="form-label" for="meeting_date">Meeting Date *</label>
                                            <input type="date" class="form-control" id="meeting_date" name="meeting_date" 
                                                   value="<?php echo $edit_meeting['meeting_date'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="start_time">Start Time *</label>
                                            <input type="time" class="form-control" id="start_time" name="start_time" 
                                                   value="<?php echo $edit_meeting['start_time'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="end_time">End Time *</label>
                                            <input type="time" class="form-control" id="end_time" name="end_time" 
                                                   value="<?php echo $edit_meeting['end_time'] ?? ''; ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="location">Location *</label>
                                        <input type="text" class="form-control" id="location" name="location" 
                                               value="<?php echo htmlspecialchars($edit_meeting['location'] ?? ''); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="agenda">Agenda</label>
                                        <textarea class="form-control" id="agenda" name="agenda" rows="3"><?php echo htmlspecialchars($edit_meeting['agenda'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Required Attendees</label>
                                        <div class="attendee-list">
                                            <?php foreach ($class_reps as $rep): ?>
                                                <div class="attendee-item">
                                                    <input type="checkbox" id="attendee_<?php echo $rep['id']; ?>" 
                                                           name="attendees[]" value="<?php echo $rep['id']; ?>"
                                                           <?php 
                                                           if (isset($edit_meeting['required_attendees'])) {
                                                               $attendees = json_decode($edit_meeting['required_attendees'], true);
                                                               if (is_array($attendees) && in_array($rep['id'], $attendees)) {
                                                                   echo 'checked';
                                                               }
                                                           } else {
                                                               echo 'checked'; // Default: all reps are required
                                                           }
                                                           ?>>
                                                    <label for="attendee_<?php echo $rep['id']; ?>">
                                                        <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                        <br>
                                                        <small><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($rep['program_name'] ?? 'N/A'); ?></small>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Agenda Items</label>
                                        <div id="agenda-items-container">
                                            <?php if (isset($agenda_items) && !empty($agenda_items)): ?>
                                                <?php foreach ($agenda_items as $item): ?>
                                                    <input type="text" class="form-control" name="agenda_items[]" 
                                                           value="<?php echo htmlspecialchars($item['title']); ?>" 
                                                           style="margin-bottom: 0.5rem;" placeholder="Agenda item">
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <input type="text" class="form-control" name="agenda_items[]" 
                                                       style="margin-bottom: 0.5rem;" placeholder="Agenda item">
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm" onclick="addAgendaItem()" style="margin-top: 0.5rem; background: var(--medium-gray);">
                                            <i class="fas fa-plus"></i> Add Agenda Item
                                        </button>
                                    </div>

                                    <button type="submit" name="add_meeting" class="btn btn-primary">
                                        <i class="fas fa-calendar-plus"></i> 
                                        <?php echo $action === 'add' ? 'Schedule Meeting' : 'Update Meeting'; ?>
                                    </button>
                                    <a href="class_rep_meetings.php" class="btn" style="background: var(--medium-gray);">Cancel</a>
                                </form>
                            </div>
                        </div>

                    <?php elseif ($action === 'attendance' && $edit_meeting): ?>
                        <!-- Record Attendance -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Record Attendance - <?php echo htmlspecialchars($edit_meeting['title']); ?></h3>
                                <div class="card-header-actions">
                                    <a href="class_rep_meetings.php" class="card-header-btn" title="Back to Meetings">
                                        <i class="fas fa-arrow-left"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="meeting_id" value="<?php echo $edit_meeting['id']; ?>">
                                    
                                    <div class="table-container">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Class Representative</th>
                                                    <th>Department</th>
                                                    <th>Program</th>
                                                    <th>Attendance Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $required_attendees = json_decode($edit_meeting['required_attendees'] ?? '[]', true);
                                                foreach ($class_reps as $rep): 
                                                    if (in_array($rep['id'], $required_attendees)):
                                                        $current_attendance = null;
                                                        foreach ($attendance ?? [] as $att) {
                                                            if ($att['user_id'] == $rep['id']) {
                                                                $current_attendance = $att;
                                                                break;
                                                            }
                                                        }
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                            <br>
                                                            <small><?php echo htmlspecialchars($rep['reg_number']); ?></small>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($rep['program_name'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <select class="form-control" name="attendance[<?php echo $rep['id']; ?>]" required>
                                                                <option value="present" <?php echo ($current_attendance['attendance_status'] ?? '') === 'present' ? 'selected' : ''; ?>>Present</option>
                                                                <option value="absent" <?php echo ($current_attendance['attendance_status'] ?? '') === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                                <option value="excused" <?php echo ($current_attendance['attendance_status'] ?? '') === 'excused' ? 'selected' : ''; ?>>Excused</option>
                                                                <option value="late" <?php echo ($current_attendance['attendance_status'] ?? '') === 'late' ? 'selected' : ''; ?>>Late</option>
                                                            </select>
                                                        </td>
                                                    </tr>
                                                <?php endif; endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <button type="submit" name="record_attendance" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Attendance
                                    </button>
                                    <a href="class_rep_meetings.php" class="btn" style="background: var(--medium-gray);">Cancel</a>
                                </form>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Meetings List -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Class Representative Meetings</h3>
                                <div class="card-header-actions">
                                    <a href="?action=add" class="card-header-btn" title="Add Meeting">
                                        <i class="fas fa-plus"></i>
                                    </a>
                                    <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-container">
                                    <?php if (empty($meetings)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-calendar-times"></i>
                                            <h4>No Meetings Scheduled</h4>
                                            <p>No class representative meetings have been scheduled yet.</p>
                                            <a href="?action=add" class="btn btn-primary" style="margin-top: 1rem;">
                                                <i class="fas fa-calendar-plus"></i> Schedule First Meeting
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Meeting</th>
                                                    <th>Date & Time</th>
                                                    <th>Location</th>
                                                    <th>Type</th>
                                                    <th>Status</th>
                                                    <th>Attendance</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($meetings as $meeting): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                            <?php if ($meeting['description']): ?>
                                                                <br>
                                                                <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($meeting['description']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?>
                                                            <br>
                                                            <small style="color: var(--dark-gray);">
                                                                <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                                                <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                                            </small>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                                        <td>
                                                            <span class="status-badge" style="text-transform: capitalize;">
                                                                <?php echo ucfirst($meeting['meeting_type']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                                                <?php echo ucfirst($meeting['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($meeting['attendance_count'] > 0): ?>
                                                                <?php echo $meeting['present_count']; ?>/<?php echo $meeting['attendance_count']; ?> present
                                                            <?php else: ?>
                                                                <small style="color: var(--dark-gray);">Not recorded</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                                <a href="?action=view&id=<?php echo $meeting['id']; ?>" class="btn btn-sm" style="background: var(--light-gray);" title="View">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                                <a href="?action=attendance&id=<?php echo $meeting['id']; ?>" class="btn btn-sm" style="background: var(--light-gray);" title="Attendance">
                                                                    <i class="fas fa-clipboard-check"></i>
                                                                </a>
                                                                <?php if ($meeting['status'] === 'scheduled'): ?>
                                                                    <form method="POST" action="" style="display: inline;">
                                                                        <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                                                        <input type="hidden" name="status" value="completed">
                                                                        <button type="submit" name="update_meeting_status" class="btn btn-sm btn-success" title="Mark Completed">
                                                                            <i class="fas fa-check"></i>
                                                                        </button>
                                                                    </form>
                                                                    <form method="POST" action="" style="display: inline;">
                                                                        <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                                                        <input type="hidden" name="status" value="cancelled">
                                                                        <button type="submit" name="update_meeting_status" class="btn btn-sm btn-danger" title="Cancel" onclick="return confirm('Are you sure you want to cancel this meeting?')">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <a href="?action=delete&id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this meeting?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <a href="?action=add" class="btn btn-primary">
                                    <i class="fas fa-calendar-plus"></i> Schedule Meeting
                                </a>
                                <a href="class_reps.php" class="btn btn-success">
                                    <i class="fas fa-users"></i> Manage Representatives
                                </a>
                                <a href="meeting_minutes.php" class="btn btn-info">
                                    <i class="fas fa-file-alt"></i> Meeting Minutes
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Meetings</h3>
                        </div>
                        <div class="card-body">
                            <?php 
                            $upcoming = array_filter($meetings, function($meeting) {
                                return $meeting['status'] === 'scheduled' && strtotime($meeting['meeting_date']) >= strtotime(date('Y-m-d'));
                            });
                            $upcoming = array_slice($upcoming, 0, 5);
                            ?>
                            
                            <?php if (empty($upcoming)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <p>No upcoming meetings</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming as $meeting): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                            <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                            <span class="status-badge status-scheduled" style="font-size: 0.6rem;">
                                                <?php echo ucfirst($meeting['status']); ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                            <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?>
                                            <br>
                                            <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                            <br>
                                            <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($meeting['location']); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Meeting Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Meeting Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-overview">
                                <div class="overview-item">
                                    <span class="overview-label">Total Meetings</span>
                                    <span class="overview-value"><?php echo $total_meetings; ?></span>
                                </div>
                                <div class="overview-item">
                                    <span class="overview-label">Upcoming</span>
                                    <span class="overview-value"><?php echo $upcoming_meetings; ?></span>
                                </div>
                                <div class="overview-item">
                                    <span class="overview-label">Completed</span>
                                    <span class="overview-value"><?php echo $completed_meetings; ?></span>
                                </div>
                                <div class="overview-item">
                                    <span class="overview-label">Cancelled</span>
                                    <span class="overview-value">
                                        <?php 
                                        $cancelled = array_filter($meetings, function($meeting) {
                                            return $meeting['status'] === 'cancelled';
                                        });
                                        echo count($cancelled);
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <style>
        .quick-overview {
            display: grid;
            gap: 0.75rem;
        }
        .overview-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }
        .overview-item:last-child {
            border-bottom: none;
        }
        .overview-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
        }
        .overview-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }
    </style>

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
                    : '<i class="fas fa-bars"></i>';
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

        // Add Agenda Item
        function addAgendaItem() {
            const container = document.getElementById('agenda-items-container');
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.name = 'agenda_items[]';
            input.placeholder = 'Agenda item';
            input.style.marginBottom = '0.5rem';
            container.appendChild(input);
        }

        // Form validation for date and time
        document.addEventListener('DOMContentLoaded', function() {
            const meetingForm = document.querySelector('form');
            if (meetingForm && meetingForm.querySelector('#meeting_date')) {
                meetingForm.addEventListener('submit', function(e) {
                    const meetingDate = document.getElementById('meeting_date').value;
                    const startTime = document.getElementById('start_time').value;
                    const endTime = document.getElementById('end_time').value;
                    
                    if (meetingDate && new Date(meetingDate) < new Date().setHours(0,0,0,0)) {
                        e.preventDefault();
                        alert('Meeting date cannot be in the past.');
                        return false;
                    }
                    
                    if (startTime && endTime && startTime >= endTime) {
                        e.preventDefault();
                        alert('End time must be after start time.');
                        return false;
                    }
                });
            }

            // Add loading animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animation = 'fadeInUp 0.4s ease forwards';
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 500);
        });
    </script>
</body>
</html>