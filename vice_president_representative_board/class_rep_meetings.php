<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice President of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_president_representative_board') {
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

            // Insert meeting
            $stmt = $pdo->prepare("
                INSERT INTO rep_meetings 
                (title, description, meeting_type, organizer_id, location, meeting_date, start_time, end_time, agenda, required_attendees, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title, $description, $meeting_type, $user_id, $location, $meeting_date, 
                $start_time, $end_time, $agenda, $required_attendees, $user_id
            ]);

            $meeting_id = $pdo->lastInsertId();

            // Add agenda items if provided
            if (isset($_POST['agenda_items']) && is_array($_POST['agenda_items'])) {
                foreach ($_POST['agenda_items'] as $agenda_item) {
                    if (!empty(trim($agenda_item))) {
                        $stmt = $pdo->prepare("
                            INSERT INTO rep_meeting_agenda_items 
                            (meeting_id, title, order_index) 
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$meeting_id, trim($agenda_item), 0]);
                    }
                }
            }

            $message = "Meeting scheduled successfully!";
            $message_type = "success";

        } elseif (isset($_POST['update_meeting_status'])) {
            $meeting_id = $_POST['meeting_id'];
            $status = $_POST['status'];

            $stmt = $pdo->prepare("UPDATE rep_meetings SET status = ? WHERE id = ?");
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
                    // Update existing
                    $stmt = $pdo->prepare("
                        UPDATE rep_meeting_attendance 
                        SET attendance_status = ?, recorded_by = ?, updated_at = NOW() 
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

// Get all class representative meetings
try {
    // Main meetings query
    $stmt = $pdo->query("
        SELECT 
            rm.*,
            u.full_name as organizer_name,
            COUNT(rma.id) as attendance_count,
            SUM(CASE WHEN rma.attendance_status = 'present' THEN 1 ELSE 0 END) as present_count
        FROM rep_meetings rm
        JOIN users u ON rm.organizer_id = u.id
        LEFT JOIN rep_meeting_attendance rma ON rm.id = rma.meeting_id
        GROUP BY rm.id
        ORDER BY rm.meeting_date DESC, rm.start_time DESC
    ");
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get class representatives for attendee selection
    $stmt = $pdo->query("
        SELECT u.id, u.full_name, u.reg_number, d.name as department_name, p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.is_class_rep = 1 AND u.status = 'active'
        ORDER BY u.full_name
    ");
    $class_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_meetings FROM rep_meetings");
    $total_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['total_meetings'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURDATE() AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as completed_meetings FROM rep_meetings WHERE status = 'completed'");
    $completed_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['completed_meetings'] ?? 0;

    // Get specific meeting details for edit/view
    $edit_meeting = null;
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

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = 1 AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM class_rep_reports WHERE status = 'submitted'");
    $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURDATE() AND status = 'scheduled'");
    $sidebar_upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_messages FROM conversation_messages cm JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
} catch (PDOException $e) {
    $sidebar_reps_count = $pending_reports = $sidebar_upcoming_meetings = $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            position: relative;
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

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar */
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

        .menu-divider {
            height: 1px;
            background: var(--medium-gray);
            margin: 1rem 1.5rem;
        }

        .menu-section {
            padding: 0.75rem 1.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

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
            border-left: 3px solid var(--primary-blue);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
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

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #cce7ff;
            color: var(--info);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
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

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
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
            font-size: 0.75rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Attendee List */
        .attendee-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 0.75rem;
        }

        .attendee-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .attendee-item:last-child {
            border-bottom: none;
        }

        .attendee-item input[type="checkbox"] {
            margin-right: 0.5rem;
        }

        .attendee-item label {
            flex: 1;
            cursor: pointer;
        }

        /* Overlay for mobile */
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

            .main-content {
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
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none !important;
            }
            
            .sidebar.mobile-open {
                display: flex !important;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }
            
            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .brand-text h1 {
                font-size: 1rem;
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
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Class Representative Meetings</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
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
                        <div class="user-role">Vice President - Representative Board</div>
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
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_reps.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Management</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_meetings.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
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
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
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
                        <div class="stat-number"><?php echo $total_meetings; ?></div>
                        <div class="stat-label">Total Meetings</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_meetings; ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $completed_meetings; ?></div>
                        <div class="stat-label">Completed Meetings</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($class_reps); ?></div>
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
                                                   value="<?php echo $edit_meeting['title'] ?? ''; ?>" required>
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
                                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo $edit_meeting['description'] ?? ''; ?></textarea>
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
                                               value="<?php echo $edit_meeting['location'] ?? ''; ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="agenda">Agenda</label>
                                        <textarea class="form-control" id="agenda" name="agenda" rows="3"><?php echo $edit_meeting['agenda'] ?? ''; ?></textarea>
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
                                        <button type="button" class="btn btn-sm" onclick="addAgendaItem()" style="margin-top: 0.5rem;">
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
                                    
                                    <div style="overflow-x: auto;">
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
                                    <div style="overflow-x: auto;">
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
                                                                <?php echo $meeting['meeting_type']; ?>
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
                                                                <a href="?action=view&id=<?php echo $meeting['id']; ?>" class="btn btn-sm" title="View">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                                <a href="?action=attendance&id=<?php echo $meeting['id']; ?>" class="btn btn-sm" title="Attendance">
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
                                    </div>
                                <?php endif; ?>
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
                                <a href="class_rep_reports.php" class="btn btn-info">
                                    <i class="fas fa-file-alt"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const sidebar = document.getElementById('sidebar');
        
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
            if (meetingForm) {
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
        });
    </script>
</body>
</html>