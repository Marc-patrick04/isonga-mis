<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
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

// Get committee member ID for the current user
try {
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $committee_member_id = $committee_member['id'] ?? null;
} catch (PDOException $e) {
    $committee_member_id = null;
    error_log("Committee member lookup error: " . $e->getMessage());
}

// Handle meeting actions (confirm/decline attendance)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['meeting_id'])) {
        $meeting_id = (int)$_POST['meeting_id'];
        $action = $_POST['action'];
        
        try {
            if ($committee_member_id) {
                if ($action === 'confirm') {
                    $stmt = $pdo->prepare("
                        INSERT INTO meeting_attendance (meeting_id, committee_member_id, attendance_status, recorded_by, created_at)
                        VALUES (?, ?, 'present', ?, CURRENT_TIMESTAMP)
                        ON CONFLICT (meeting_id, committee_member_id)
                        DO UPDATE SET attendance_status = 'present', updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$meeting_id, $committee_member_id, $user_id]);
                    $_SESSION['success_message'] = "Attendance confirmed successfully!";
                } elseif ($action === 'decline') {
                    $stmt = $pdo->prepare("
                        INSERT INTO meeting_attendance (meeting_id, committee_member_id, attendance_status, recorded_by, created_at)
                        VALUES (?, ?, 'absent', ?, CURRENT_TIMESTAMP)
                        ON CONFLICT (meeting_id, committee_member_id)
                        DO UPDATE SET attendance_status = 'absent', updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$meeting_id, $committee_member_id, $user_id]);
                    $_SESSION['success_message'] = "Attendance declined successfully!";
                }
            }
            
            header("Location: academic_meetings.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating attendance: " . $e->getMessage();
            error_log("Attendance update error: " . $e->getMessage());
        }
    }
}

// Handle filters and pagination
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query for meetings list
$query = "
    SELECT m.*, u.full_name as chairperson_name,
           (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id = m.id) as total_attendees,
           (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id = m.id AND ma.attendance_status = 'present') as present_count
    FROM meetings m 
    LEFT JOIN users u ON m.chairperson_id = u.id 
    WHERE 1=1
";

$count_query = "SELECT COUNT(*) as total FROM meetings m WHERE 1=1";
$params = [];
$count_params = [];

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
    $all_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's attendance for each meeting
    if ($committee_member_id && !empty($all_meetings)) {
        $meeting_ids = array_column($all_meetings, 'id');
        $placeholders = str_repeat('?,', count($meeting_ids) - 1) . '?';
        
        $attendance_stmt = $pdo->prepare("
            SELECT meeting_id, attendance_status, check_in_time, notes 
            FROM meeting_attendance 
            WHERE meeting_id IN ($placeholders) AND committee_member_id = ?
        ");
        
        $attendance_params = array_merge($meeting_ids, [$committee_member_id]);
        $attendance_stmt->execute($attendance_params);
        $attendance_data = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge attendance data with meetings
        $attendance_map = [];
        foreach ($attendance_data as $attendance) {
            $attendance_map[$attendance['meeting_id']] = $attendance;
        }
        
        foreach ($all_meetings as &$meeting) {
            if (isset($attendance_map[$meeting['id']])) {
                $meeting['attendance_status'] = $attendance_map[$meeting['id']]['attendance_status'];
                $meeting['check_in_time'] = $attendance_map[$meeting['id']]['check_in_time'];
                $meeting['attendance_notes'] = $attendance_map[$meeting['id']]['notes'];
            } else {
                $meeting['attendance_status'] = null;
                $meeting['check_in_time'] = null;
                $meeting['attendance_notes'] = null;
            }
        }
        unset($meeting);
    }
    
    // Separate upcoming and past meetings
    $upcoming_meetings = [];
    $past_meetings = [];
    foreach ($all_meetings as $meeting) {
        if (strtotime($meeting['meeting_date']) >= strtotime(date('Y-m-d'))) {
            $upcoming_meetings[] = $meeting;
        } else {
            $past_meetings[] = $meeting;
        }
    }
    
} catch (PDOException $e) {
    error_log("Fetch meetings error: " . $e->getMessage());
    $all_meetings = $upcoming_meetings = $past_meetings = [];
    $filtered_total = 0;
    $total_pages = 1;
}

// Get statistics for dashboard cards
try {
    // Total meetings
    $total_meetings = $pdo->query("SELECT COUNT(*) as count FROM meetings")->fetch()['count'];
    
    // Upcoming meetings
    $upcoming_meetings_count = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'")->fetch()['count'];
    
    // Today's meetings
    $todays_meetings = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE meeting_date = CURRENT_DATE AND status IN ('scheduled', 'ongoing')")->fetch()['count'];
    
    // User's attendance statistics
    if ($committee_member_id) {
        $attendance_stmt = $pdo->prepare("
            SELECT 
                COUNT(ma.id) as total_invited,
                SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as attended,
                SUM(CASE WHEN ma.attendance_status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN ma.attendance_status = 'excused' THEN 1 ELSE 0 END) as excused
            FROM meeting_attendance ma
            JOIN meetings m ON ma.meeting_id = m.id
            WHERE ma.committee_member_id = ? AND m.meeting_date >= CURRENT_DATE - INTERVAL '3 months'
        ");
        $attendance_stmt->execute([$committee_member_id]);
        $attendance_stats = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
        
        $attendance_rate = $attendance_stats['total_invited'] > 0 
            ? round(($attendance_stats['attended'] / $attendance_stats['total_invited']) * 100) 
            : 0;
            
        $confirmed_meetings = $attendance_stats['attended'] + $attendance_stats['excused'];
    } else {
        $attendance_stats = ['total_invited' => 0, 'attended' => 0, 'absent' => 0, 'excused' => 0];
        $attendance_rate = 0;
        $confirmed_meetings = 0;
    }
    
    // Get meeting types for filter
    $stmt = $pdo->query("SELECT DISTINCT meeting_type FROM meetings WHERE meeting_type IS NOT NULL");
    $meeting_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_meetings = $upcoming_meetings_count = $todays_meetings = 0;
    $attendance_rate = 0;
    $confirmed_meetings = 0;
    $attendance_stats = ['total_invited' => 0, 'attended' => 0, 'absent' => 0, 'excused' => 0];
    $meeting_types = [];
}

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Meetings - Isonga RPSU</title>
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
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
            --academic-primary: #4CAF50;
            --academic-secondary: #66BB6A;
            --academic-accent: #2E7D32;
            --academic-light: #1B3E1B;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
            color: var(--academic-primary);
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
            border-color: var(--academic-primary);
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
            background: var(--academic-primary);
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
            background: var(--academic-light);
            border-left-color: var(--academic-primary);
            color: var(--academic-primary);
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

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .page-description {
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
            border-left: 3px solid var(--academic-primary);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card .stat-icon {
            background: var(--academic-light);
            color: var(--academic-primary);
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
            grid-template-columns: 1fr;
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

        /* Meeting Cards */
        .meeting-card {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .meeting-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .meeting-card.upcoming {
            border-left: 4px solid var(--academic-primary);
        }

        .meeting-card.past {
            border-left: 4px solid var(--dark-gray);
            opacity: 0.8;
        }

        .meeting-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .meeting-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .meeting-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .meeting-type {
            background: var(--academic-light);
            color: var(--academic-primary);
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .meeting-description {
            color: var(--text-dark);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .meeting-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--academic-primary);
            font-size: 0.8rem;
        }

        .detail-content {
            flex: 1;
        }

        .detail-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.1rem;
        }

        .detail-value {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .meeting-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
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
            background: var(--academic-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--academic-accent);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-ongoing {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
        }

        .attendance-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .attendance-present {
            background: #d4edda;
            color: var(--success);
        }

        .attendance-absent {
            background: #f8d7da;
            color: var(--danger);
        }

        .attendance-excused {
            background: #fff3cd;
            color: var(--warning);
        }

        .attendance-pending {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
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

        .empty-state p {
            margin-bottom: 1rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            font-weight: 500;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .tab.active {
            color: var(--academic-primary);
            border-bottom-color: var(--academic-primary);
        }

        .tab:hover {
            color: var(--academic-primary);
            background: var(--academic-light);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Filters */
        .filters {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .filter-select, .filter-input {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            min-width: 150px;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--academic-primary);
        }

        .filter-btn {
            background: var(--academic-primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background: var(--academic-accent);
        }

        .reset-btn {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .reset-btn:hover {
            background: var(--medium-gray);
        }

        /* =============================================
           RESPONSIVE — mobile-first breakpoints
        ============================================= */

        /* Hamburger button (hidden on desktop) */
        .hamburger {
            display: none;
            flex-direction: column;
            justify-content: center;
            gap: 5px;
            width: 44px;
            height: 44px;
            background: var(--light-gray);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            padding: 10px;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .hamburger span {
            display: block;
            height: 2px;
            background: var(--text-dark);
            border-radius: 2px;
            transition: var(--transition);
        }
        .hamburger:hover { background: var(--academic-primary); }
        .hamburger:hover span { background: #fff; }

        /* Sidebar overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
        }
        .sidebar-overlay.active { display: block; }

        /* ── 1280 px ── */
        @media (max-width: 1280px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* ── 1024 px ── */
        @media (max-width: 1024px) {
            .dashboard-container { grid-template-columns: 200px 1fr; }
            .brand-text h1 { font-size: 1.05rem; }
        }

        /* ── 768 px ── tablet */
        @media (max-width: 768px) {
            .hamburger { display: flex; }

            .dashboard-container { grid-template-columns: 1fr; }

            /* Sidebar becomes slide-in drawer */
            .sidebar {
                position: fixed;
                top: 80px;
                left: 0;
                width: 260px;
                height: calc(100vh - 80px);
                z-index: 200;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                box-shadow: var(--shadow-lg);
            }
            .sidebar.open { transform: translateX(0); }

            .main-content { height: auto; overflow-y: visible; }

            .stats-grid { grid-template-columns: repeat(2, 1fr); }

            .meeting-header { flex-direction: column; gap: 0.75rem; }
            .meeting-meta { flex-wrap: wrap; gap: 0.5rem; }
            .meeting-details { grid-template-columns: 1fr 1fr; }
            .meeting-actions { flex-wrap: wrap; }
            .meeting-actions .btn { flex: 1; justify-content: center; }

            .nav-container { padding: 0 1rem; }
            .user-details { display: none; }

            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-group { width: 100%; }
            .filter-select, .filter-input { min-width: unset; width: 100%; }
            .filter-btn, .reset-btn { width: 100%; text-align: center; justify-content: center; }

            .header { height: 70px; }
            .dashboard-container { min-height: calc(100vh - 70px); }
            .sidebar { top: 70px; height: calc(100vh - 70px); }
        }

        /* ── 480 px ── large phone */
        @media (max-width: 480px) {
            .header { height: 64px; }
            .dashboard-container { min-height: calc(100vh - 64px); }
            .sidebar { top: 64px; height: calc(100vh - 64px); }

            .stats-grid { grid-template-columns: 1fr 1fr; }
            .main-content { padding: 0.875rem; }

            .brand-text h1 { font-size: 0.9rem; }

            .page-title { font-size: 1.15rem; }

            .meeting-card { padding: 1rem; }
            .meeting-title { font-size: 0.95rem; }
            .meeting-details { grid-template-columns: 1fr; }
            .meeting-actions { flex-direction: column; }
            .meeting-actions .btn { width: 100%; justify-content: center; }

            /* Tabs scroll horizontally instead of stacking */
            .tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                flex-wrap: nowrap;
                padding-bottom: 2px;
            }
            .tab {
                white-space: nowrap;
                flex-shrink: 0;
                padding: 0.65rem 1rem;
            }

            .stat-card { padding: 0.75rem; gap: 0.75rem; }
            .stat-number { font-size: 1.25rem; }
        }

        /* ── 360 px ── small phone */
        @media (max-width: 360px) {
            .stats-grid { grid-template-columns: 1fr; }
            .brand-text h1 { display: none; }
        }
    </style>
</head>
<body>
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                    <span></span><span></span><span></span>
                </button>
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Meetings</h1>
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
                        <div class="user-role">Vice Guild Academic</div>
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
                    <a href="academic_meetings.php" class="active">
                        <i class="fas fa-calendar-check"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings_count > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_tickets.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academic Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Academic Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
                    </a>
                </li>
                                                <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="performance_tracking.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Performance Tracking</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="innovation_projects.php">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_calendar.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Academic Meetings</h1>
                <p class="page-description">View your meeting schedule and manage attendance</p>
            </div>

            <!-- Display Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Meeting Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_meetings; ?></div>
                        <div class="stat-label">Total Meetings</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_meetings_count; ?></div>
                        <div class="stat-label">Upcoming</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $confirmed_meetings; ?></div>
                        <div class="stat-label">Confirmed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $attendance_rate; ?>%</div>
                        <div class="stat-label">Attendance Rate</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" action="academic_meetings.php">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Meeting Type</label>
                            <select name="type" class="filter-select">
                                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <?php foreach ($meeting_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Date From</label>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Date To</label>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="filter-btn">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                        <div class="filter-group">
                            <a href="academic_meetings.php" class="reset-btn">
                                <i class="fas fa-times"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="upcoming">Upcoming Meetings (<?php echo count($upcoming_meetings); ?>)</button>
                <button class="tab" data-tab="past">Past Meetings (<?php echo count($past_meetings); ?>)</button>
            </div>

            <!-- Upcoming Meetings Tab -->
            <div class="tab-content active" id="upcoming-tab">
                <?php if (empty($upcoming_meetings)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Upcoming Meetings</h3>
                        <p>You don't have any upcoming meetings scheduled.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_meetings as $meeting): ?>
                        <div class="meeting-card upcoming">
                            <div class="meeting-header">
                                <div>
                                    <h3 class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></h3>
                                    <div class="meeting-meta">
                                        <span class="meeting-type"><?php echo ucfirst($meeting['meeting_type']); ?></span>
                                        <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                            <?php echo ucfirst($meeting['status']); ?>
                                        </span>
                                        <span class="attendance-badge attendance-<?php echo $meeting['attendance_status'] ?? 'pending'; ?>">
                                            <?php echo ucfirst($meeting['attendance_status'] ?? 'Pending'); ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <?php if (!$meeting['attendance_status'] || $meeting['attendance_status'] === 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                            <input type="hidden" name="action" value="confirm">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-check"></i> Confirm
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                            <input type="hidden" name="action" value="decline">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to decline this meeting?')">
                                                <i class="fas fa-times"></i> Decline
                                            </button>
                                        </form>
                                    <?php elseif ($meeting['attendance_status'] === 'present'): ?>
                                        <span class="attendance-badge attendance-present">
                                            <i class="fas fa-check"></i> Confirmed
                                        </span>
                                    <?php elseif ($meeting['attendance_status'] === 'absent'): ?>
                                        <span class="attendance-badge attendance-absent">
                                            <i class="fas fa-times"></i> Declined
                                        </span>
                                    <?php elseif ($meeting['attendance_status'] === 'excused'): ?>
                                        <span class="attendance-badge attendance-excused">
                                            <i class="fas fa-user-clock"></i> Excused
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($meeting['description'])): ?>
                                <div class="meeting-description">
                                    <?php echo nl2br(htmlspecialchars($meeting['description'])); ?>
                                </div>
                            <?php endif; ?>

                            <div class="meeting-details">
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-calendar"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Date</div>
                                        <div class="detail-value">
                                            <?php echo date('F j, Y', strtotime($meeting['meeting_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Time</div>
                                        <div class="detail-value">
                                            <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Location</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['location']); ?></div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Chairperson</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['chairperson_name']); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="meeting-details">
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Attendees</div>
                                        <div class="detail-value">
                                            <?php echo $meeting['present_count']; ?> confirmed of <?php echo $meeting['total_attendees']; ?> invited
                                        </div>
                                    </div>
                                </div>
                                <?php if ($meeting['check_in_time']): ?>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Response Date</div>
                                        <div class="detail-value">
                                            <?php echo date('M j, g:i A', strtotime($meeting['check_in_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Get agenda items for this meeting
                            try {
                                $stmt = $pdo->prepare("
                                    SELECT mai.*, u.full_name as presenter_name
                                    FROM meeting_agenda_items mai
                                    LEFT JOIN users u ON mai.presenter_id = u.id
                                    WHERE mai.meeting_id = ?
                                    ORDER BY mai.order_index
                                ");
                                $stmt->execute([$meeting['id']]);
                                $agenda_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (PDOException $e) {
                                $agenda_items = [];
                            }
                            ?>

                            <?php if (!empty($agenda_items)): ?>
                                <div class="meeting-agenda">
                                    <h4 style="margin-bottom: 0.75rem; font-size: 0.9rem; color: var(--text-dark);">
                                        <i class="fas fa-list-ol"></i> Agenda Items
                                    </h4>
                                    <div style="background: var(--light-gray); padding: 0.75rem; border-radius: var(--border-radius);">
                                        <?php foreach ($agenda_items as $item): ?>
                                            <div style="display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                                <div style="background: var(--academic-primary); color: white; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 600; flex-shrink: 0;">
                                                    <?php echo $item['order_index']; ?>
                                                </div>
                                                <div style="flex: 1;">
                                                    <div style="font-weight: 600; font-size: 0.8rem; color: var(--text-dark);">
                                                        <?php echo htmlspecialchars($item['title']); ?>
                                                    </div>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <div style="font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars($item['description']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['presenter_name'])): ?>
                                                        <div style="font-size: 0.7rem; color: var(--academic-primary); margin-top: 0.25rem;">
                                                            Presented by: <?php echo htmlspecialchars($item['presenter_name']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); flex-shrink: 0;">
                                                    <?php echo $item['duration_minutes']; ?> min
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Past Meetings Tab -->
            <div class="tab-content" id="past-tab">
                <?php if (empty($past_meetings)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Past Meetings</h3>
                        <p>You don't have any past meetings in your record.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($past_meetings as $meeting): ?>
                        <div class="meeting-card past">
                            <div class="meeting-header">
                                <div>
                                    <h3 class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></h3>
                                    <div class="meeting-meta">
                                        <span class="meeting-type"><?php echo ucfirst($meeting['meeting_type']); ?></span>
                                        <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                            <?php echo ucfirst($meeting['status']); ?>
                                        </span>
                                        <span class="attendance-badge attendance-<?php echo $meeting['attendance_status'] ?? 'pending'; ?>">
                                            <?php echo ucfirst($meeting['attendance_status'] ?? 'Pending'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($meeting['description'])): ?>
                                <div class="meeting-description">
                                    <?php echo nl2br(htmlspecialchars($meeting['description'])); ?>
                                </div>
                            <?php endif; ?>

                            <div class="meeting-details">
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-calendar"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Date</div>
                                        <div class="detail-value">
                                            <?php echo date('F j, Y', strtotime($meeting['meeting_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Time</div>
                                        <div class="detail-value">
                                            <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Location</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['location']); ?></div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Chairperson</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['chairperson_name']); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="meeting-details">
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Attendance</div>
                                        <div class="detail-value">
                                            <?php echo $meeting['present_count']; ?> attended of <?php echo $meeting['total_attendees']; ?> invited
                                        </div>
                                    </div>
                                </div>
                                <?php if ($meeting['check_in_time']): ?>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Response Date</div>
                                        <div class="detail-value">
                                            <?php echo date('M j, g:i A', strtotime($meeting['check_in_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Get meeting minutes
                            try {
                                $stmt = $pdo->prepare("SELECT content FROM meeting_minutes WHERE meeting_id = ?");
                                $stmt->execute([$meeting['id']]);
                                $minutes = $stmt->fetch(PDO::FETCH_ASSOC);
                            } catch (PDOException $e) {
                                $minutes = null;
                            }
                            ?>

                            <?php if (!empty($minutes['content'])): ?>
                                <div class="meeting-minutes">
                                    <h4 style="margin-bottom: 0.75rem; font-size: 0.9rem; color: var(--text-dark);">
                                        <i class="fas fa-file-alt"></i> Meeting Minutes
                                    </h4>
                                    <div style="background: var(--light-gray); padding: 0.75rem; border-radius: var(--border-radius); font-size: 0.8rem; line-height: 1.5;">
                                        <?php echo nl2br(htmlspecialchars($minutes['content'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // ── Dark Mode Toggle ──────────────────────────────────────
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        const savedTheme = localStorage.getItem('theme') ||
            (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
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

        // ── Hamburger / Sidebar Toggle (mobile) ───────────────────
        const hamburgerBtn   = document.getElementById('hamburgerBtn');
        const sidebar        = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function openSidebar() {
            sidebar.classList.add('open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeSidebar() {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        if (hamburgerBtn) hamburgerBtn.addEventListener('click', () =>
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar()
        );
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

        document.querySelectorAll('.sidebar .menu-item a').forEach(link =>
            link.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); })
        );

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('open');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // ── Tab functionality ─────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const tabId = tab.getAttribute('data-tab');
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                });
            });
        });

        // ── Auto-refresh every 5 minutes ──────────────────────────
        setInterval(() => {
            console.log('Academic meetings page auto-refresh triggered');
        }, 300000);
    </script>
</body>
</html>