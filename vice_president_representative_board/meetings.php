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
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Get committee member ID for the current user (Vice President of Representative Board)
try {
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'vice_president_representative_board' AND status = 'active'");
    $stmt->execute([$user_id]);
    $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $committee_member_id = $committee_member['id'] ?? null;
} catch (PDOException $e) {
    $committee_member_id = null;
    error_log("Committee member lookup error: " . $e->getMessage());
}

// Handle filters and pagination
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query for meetings list - VP can see ALL guild council meetings
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
    $filtered_total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($filtered_total / $limit);
    
    // Get meetings
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's attendance for each meeting
    if ($committee_member_id && !empty($meetings)) {
        $meeting_ids = array_column($meetings, 'id');
        $placeholders = implode(',', array_fill(0, count($meeting_ids), '?'));
        
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
        
        foreach ($meetings as &$meeting) {
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
    
} catch (PDOException $e) {
    error_log("Fetch meetings error: " . $e->getMessage());
    $meetings = [];
    $filtered_total = 0;
    $total_pages = 1;
}

// Get statistics for dashboard cards (PostgreSQL fixes)
try {
    // Total meetings
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM meetings");
    $total_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Upcoming meetings - PostgreSQL uses CURRENT_DATE
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Today's meetings - PostgreSQL uses CURRENT_DATE
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM meetings WHERE meeting_date = CURRENT_DATE AND status IN ('scheduled', 'ongoing')");
    $todays_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // User's attendance statistics - PostgreSQL uses CURRENT_DATE - INTERVAL
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
        
        $attendance_rate = ($attendance_stats['total_invited'] ?? 0) > 0 
            ? round((($attendance_stats['attended'] ?? 0) / ($attendance_stats['total_invited'] ?? 1)) * 100) 
            : 0;
    } else {
        $attendance_stats = ['total_invited' => 0, 'attended' => 0, 'absent' => 0, 'excused' => 0];
        $attendance_rate = 0;
    }
    
    // Get meeting types for filter
    $stmt = $pdo->query("SELECT DISTINCT meeting_type FROM meetings WHERE meeting_type IS NOT NULL");
    $meeting_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get upcoming important meetings (next 7 days) - PostgreSQL uses CURRENT_DATE + INTERVAL
    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name as chairperson_name
        FROM meetings m
        LEFT JOIN users u ON m.chairperson_id = u.id
        WHERE m.meeting_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'
        AND m.status = 'scheduled'
        ORDER BY m.meeting_date ASC, m.start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_important = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's recent meeting attendance
    if ($committee_member_id) {
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.title,
                m.meeting_date,
                m.start_time,
                ma.attendance_status,
                ma.check_in_time
            FROM meeting_attendance ma
            JOIN meetings m ON ma.meeting_id = m.id
            WHERE ma.committee_member_id = ?
            ORDER BY m.meeting_date DESC
            LIMIT 10
        ");
        $stmt->execute([$committee_member_id]);
        $user_attendance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $user_attendance_history = [];
    }
    
    // Get overall attendance statistics for all committee members (for VP oversight) - PostgreSQL uses CURRENT_DATE - INTERVAL
    $overall_attendance_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_meetings,
            COALESCE(AVG(present_count * 100.0 / total_attendees), 0) as avg_attendance_rate
        FROM (
            SELECT 
                m.id,
                COUNT(ma.id) as total_attendees,
                SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as present_count
            FROM meetings m
            LEFT JOIN meeting_attendance ma ON m.id = ma.meeting_id
            WHERE m.meeting_date >= CURRENT_DATE - INTERVAL '3 months'
            GROUP BY m.id
        ) as meeting_stats
        WHERE total_attendees > 0
    ");
    $overall_attendance = $overall_attendance_stmt->fetch(PDO::FETCH_ASSOC);
    $avg_attendance_rate = round($overall_attendance['avg_attendance_rate'] ?? 0);
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
    $total_meetings = $upcoming_meetings = $todays_meetings = 0;
    $attendance_rate = 0;
    $attendance_stats = ['total_invited' => 0, 'attended' => 0, 'absent' => 0, 'excused' => 0];
    $meeting_types = [];
    $upcoming_important = [];
    $user_attendance_history = [];
    $avg_attendance_rate = 0;
}

// Get unread messages count (PostgreSQL fix)
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
    error_log("Unread messages error: " . $e->getMessage());
}

// Ensure attendance_stats has proper values
$attendance_stats = $attendance_stats ?? ['total_invited' => 0, 'attended' => 0, 'absent' => 0, 'excused' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meetings - Vice President Representative Board - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/rp_logo.png">
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

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-info {
            background: #cce7ff;
            color: var(--primary-blue);
            border-left-color: var(--primary-blue);
        }

        /* Filters */
        .filters {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .filter-select, .filter-input {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .filter-btn {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background: var(--accent-blue);
        }

        .reset-btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--light-gray);
            color: var(--text-dark);
            text-decoration: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            text-align: center;
            transition: var(--transition);
        }

        .reset-btn:hover {
            background: var(--medium-gray);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        /* Cards */
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

        .table-responsive {
            overflow-x: auto;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-ongoing {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            color: var(--text-dark);
            text-decoration: none;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .pagination-btn:hover {
            background: var(--light-gray);
        }

        .pagination-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.7rem;
            flex-shrink: 0;
        }

        .activity-avatar.attendance-present {
            background: var(--success);
        }

        .activity-avatar.attendance-absent {
            background: var(--danger);
        }

        .activity-avatar.attendance-excused {
            background: var(--warning);
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Progress Bars */
        .attendance-progress {
            margin-top: 1rem;
        }

        .progress-bar {
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            background: var(--primary-blue);
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
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
            
            .filter-row {
                grid-template-columns: 1fr;
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

            .page-title {
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
                    <h1>Isonga - Meetings</h1>
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
                    <a href="dashboard.php">
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
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php" class="active">
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
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_meetings; ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $todays_meetings; ?></div>
                        <div class="stat-label">Today's Meetings</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $avg_attendance_rate; ?>%</div>
                        <div class="stat-label">Avg Committee Attendance</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" action="meetings.php">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Meeting Type</label>
                            <select name="type" class="filter-select">
                                <option value="" <?php echo $type_filter === '' ? 'selected' : ''; ?>>All Types</option>
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
                            <a href="meetings.php" class="reset-btn">
                                <i class="fas fa-times"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Meetings Table -->
                    <div class="card">
                        <div class="card-header">
                            <h3>All Guild Council Meetings</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($meetings)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No meetings found matching your criteria</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Meeting Title</th>
                                                <th>Date & Time</th>
                                                <th>Location</th>
                                                <th>Type</th>
                                                <th>Chairperson</th>
                                                <th>Attendance</th>
                                                <th>Status</th>
                                                <th>Your Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($meetings as $meeting): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                        <?php if ($meeting['description']): ?>
                                                            <br><small style="color: var(--dark-gray);"><?php echo htmlspecialchars(substr($meeting['description'], 0, 50)); ?>...</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?><br>
                                                        <small><?php echo date('g:i A', strtotime($meeting['start_time'])); ?> 
                                                        <?php if ($meeting['end_time']): ?>
                                                            - <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                                        <?php endif; ?>
                                                        </small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $meeting['meeting_type']))); ?></td>
                                                    <td><?php echo htmlspecialchars($meeting['chairperson_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <small>
                                                            <?php echo $meeting['present_count'] ?? 0; ?>/<?php echo $meeting['total_attendees'] ?? 0; ?> present
                                                            <?php if ($meeting['total_attendees'] > 0): ?>
                                                                <br>(<?php echo round(($meeting['present_count'] / $meeting['total_attendees']) * 100); ?>%)
                                                            <?php endif; ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                                            <?php echo ucfirst($meeting['status']); ?>
                                                        </span>
                                                    </                                                    </table>
                                                    <td>
                                                        <?php if ($meeting['attendance_status']): ?>
                                                            <span class="status-badge attendance-<?php echo $meeting['attendance_status']; ?>">
                                                                <?php echo ucfirst($meeting['attendance_status']); ?>
                                                            </span>
                                                            <?php if ($meeting['check_in_time']): ?>
                                                                <br><small>at <?php echo date('g:i A', strtotime($meeting['check_in_time'])); ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span style="color: var(--dark-gray); font-size: 0.8rem;">Not recorded</span>
                                                        <?php endif; ?>
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
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        <?php else: ?>
                                            <span class="pagination-btn disabled">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </span>
                                        <?php endif; ?>

                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                               class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="pagination-btn disabled">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Your Attendance Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Your Attendance Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div style="text-align: center; margin-bottom: 1.5rem;">
                                <div style="font-size: 2rem; font-weight: 700; color: var(--primary-blue); margin-bottom: 0.5rem;">
                                    <?php echo $attendance_rate; ?>%
                                </div>
                                <div style="font-size: 0.8rem; color: var(--dark-gray);">Your Attendance Rate</div>
                            </div>
                            
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Meetings Attended</span>
                                    <strong style="color: var(--success);"><?php echo $attendance_stats['attended']; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Absent</span>
                                    <strong style="color: var(--danger);"><?php echo $attendance_stats['absent']; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Excused</span>
                                    <strong style="color: var(--warning);"><?php echo $attendance_stats['excused']; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Total Invited</span>
                                    <strong style="color: var(--text-dark);"><?php echo $attendance_stats['total_invited']; ?></strong>
                                </div>
                            </div>

                            <div class="attendance-progress" style="margin-top: 1rem;">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $attendance_rate; ?>%"></div>
                                </div>
                                <div class="progress-text">
                                    <span>Last 3 months</span>
                                    <span><?php echo $attendance_rate; ?>%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Important Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming This Week</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_important)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No upcoming meetings this week</p>
                                </div>
                            <?php else: ?>
                                <ul class="activity-list">
                                    <?php foreach ($upcoming_important as $meeting): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar" style="background: var(--primary-blue);">
                                                <i class="fas fa-calendar" style="font-size: 0.8rem;"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('D, M j', strtotime($meeting['meeting_date'])); ?> 
                                                    at <?php echo date('g:i A', strtotime($meeting['start_time'])); ?>
                                                    • <?php echo htmlspecialchars($meeting['location']); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Your Recent Attendance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Your Recent Attendance</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($user_attendance_history)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No attendance records found</p>
                                </div>
                            <?php else: ?>
                                <ul class="activity-list">
                                    <?php foreach ($user_attendance_history as $attendance): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar 
                                                <?php echo $attendance['attendance_status'] === 'present' ? 'attendance-present' : 
                                                      ($attendance['attendance_status'] === 'absent' ? 'attendance-absent' : 'attendance-excused'); ?>">
                                                <i class="fas fa-<?php echo $attendance['attendance_status'] === 'present' ? 'check' : 
                                                                   ($attendance['attendance_status'] === 'absent' ? 'times' : 'user-clock'); ?>" 
                                                   style="font-size: 0.8rem;"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <?php echo htmlspecialchars($attendance['title']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, Y', strtotime($attendance['meeting_date'])); ?> 
                                                    • 
                                                    <span class="status-badge attendance-<?php echo $attendance['attendance_status']; ?>">
                                                        <?php echo ucfirst($attendance['attendance_status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
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

        // Auto-refresh page every 5 minutes
        setInterval(() => {
            console.log('Meetings page auto-refresh triggered');
        }, 300000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animation = `fadeInUp 0.4s ease forwards`;
                card.style.animationDelay = `${index * 0.1}s`;
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