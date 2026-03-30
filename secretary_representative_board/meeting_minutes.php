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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_minutes'])) {
            $meeting_id = $_POST['meeting_id'];
            $title = $_POST['title'];
            $meeting_date = $_POST['meeting_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $location = $_POST['location'];
            
            // Process arrays
            $attendees = isset($_POST['attendees']) ? json_encode($_POST['attendees']) : json_encode([]);
            $absentees = isset($_POST['absentees']) ? json_encode($_POST['absentees']) : json_encode([]);
            $agenda_items = isset($_POST['agenda_items']) ? json_encode(array_filter($_POST['agenda_items'])) : json_encode([]);
            $discussion_points = isset($_POST['discussion_points']) ? json_encode(array_filter($_POST['discussion_points'])) : json_encode([]);
            $decisions_made = isset($_POST['decisions_made']) ? json_encode(array_filter($_POST['decisions_made'])) : json_encode([]);
            $action_items = isset($_POST['action_items']) ? json_encode(array_filter($_POST['action_items'])) : json_encode([]);
            
            $next_meeting_date = $_POST['next_meeting_date'] ?? null;
            $next_meeting_agenda = $_POST['next_meeting_agenda'] ?? '';
            $additional_notes = $_POST['additional_notes'] ?? '';
            $status = $_POST['status'] ?? 'draft';

            // Create the minutes JSON structure
            $minutes_data = [
                'title' => $title,
                'meeting_date' => $meeting_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'location' => $location,
                'attendees' => json_decode($attendees, true),
                'absentees' => json_decode($absentees, true),
                'agenda_items' => json_decode($agenda_items, true),
                'discussion_points' => json_decode($discussion_points, true),
                'decisions_made' => json_decode($decisions_made, true),
                'action_items' => json_decode($action_items, true),
                'next_meeting_date' => $next_meeting_date,
                'next_meeting_agenda' => $next_meeting_agenda,
                'additional_notes' => $additional_notes,
                'status' => $status,
                'prepared_by' => $user_id,
                'prepared_at' => date('Y-m-d H:i:s'),
                'prepared_by_name' => $_SESSION['full_name']
            ];

            $minutes_json = json_encode($minutes_data, JSON_PRETTY_PRINT);

            // Update the meeting record with minutes (PostgreSQL uses CURRENT_TIMESTAMP)
            $stmt = $pdo->prepare("
                UPDATE rep_meetings 
                SET minutes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$minutes_json, $meeting_id]);
            
            $message = "Meeting minutes saved successfully!";
            $message_type = "success";

        } elseif (isset($_POST['update_status'])) {
            $meeting_id = $_POST['meeting_id'];
            $status = $_POST['status'];

            // Get existing minutes and update status
            $stmt = $pdo->prepare("SELECT minutes FROM rep_meetings WHERE id = ?");
            $stmt->execute([$meeting_id]);
            $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($meeting && !empty($meeting['minutes'])) {
                $minutes_data = json_decode($meeting['minutes'], true);
                $minutes_data['status'] = $status;
                $minutes_json = json_encode($minutes_data, JSON_PRETTY_PRINT);
                
                $stmt = $pdo->prepare("UPDATE rep_meetings SET minutes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$minutes_json, $meeting_id]);
                
                $message = "Minutes status updated successfully!";
                $message_type = "success";
            }
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Delete minutes (remove minutes from meeting)
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $meeting_id = $_GET['id'];
        $stmt = $pdo->prepare("UPDATE rep_meetings SET minutes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$meeting_id]);
        
        $message = "Meeting minutes deleted successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Get data for the page (PostgreSQL compatible)
try {
    // Get completed meetings for minutes creation (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("
        SELECT rm.*, u.full_name as organizer_name
        FROM rep_meetings rm
        JOIN users u ON rm.organizer_id = u.id
        WHERE rm.status = 'completed'
        ORDER BY rm.meeting_date DESC
    ");
    $completed_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all meetings that have minutes
    $stmt = $pdo->prepare("
        SELECT rm.*, u.full_name as organizer_name
        FROM rep_meetings rm
        JOIN users u ON rm.organizer_id = u.id
        WHERE rm.minutes IS NOT NULL AND rm.minutes != ''
        ORDER BY rm.meeting_date DESC
    ");
    $stmt->execute();
    $all_minutes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get class representatives for attendee selection (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("
        SELECT u.id, u.full_name, u.reg_number, d.name as department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.is_class_rep = true AND u.status = 'active'
        ORDER BY u.full_name
    ");
    $class_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_minutes FROM rep_meetings WHERE minutes IS NOT NULL AND minutes != ''");
    $total_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['total_minutes'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as total_completed FROM rep_meetings WHERE status = 'completed'");
    $total_completed = $stmt->fetch(PDO::FETCH_ASSOC)['total_completed'] ?? 0;

    // Get draft minutes count (need to parse JSON)
    $draft_minutes = 0;
    $published_minutes = 0;
    foreach ($all_minutes as $meeting) {
        if (!empty($meeting['minutes'])) {
            $minutes_data = json_decode($meeting['minutes'], true);
            if (isset($minutes_data['status'])) {
                if ($minutes_data['status'] === 'draft') {
                    $draft_minutes++;
                } elseif ($minutes_data['status'] === 'published') {
                    $published_minutes++;
                }
            }
        }
    }

    // Get specific meeting for edit/view
    $edit_meeting = null;
    $minutes_data = null;
    if (isset($_GET['id']) && ($action === 'edit' || $action === 'view')) {
        $meeting_id = $_GET['id'];
        $stmt = $pdo->prepare("
            SELECT rm.*, u.full_name as organizer_name
            FROM rep_meetings rm
            JOIN users u ON rm.organizer_id = u.id
            WHERE rm.id = ?
        ");
        $stmt->execute([$meeting_id]);
        $edit_meeting = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_meeting && !empty($edit_meeting['minutes'])) {
            $minutes_data = json_decode($edit_meeting['minutes'], true);
        }
    }

    // Get meeting details for new minutes
    if ($action === 'create' && isset($_GET['meeting_id'])) {
        $meeting_id = $_GET['meeting_id'];
        $stmt = $pdo->prepare("
            SELECT rm.*, u.full_name as organizer_name
            FROM rep_meetings rm
            JOIN users u ON rm.organizer_id = u.id
            WHERE rm.id = ?
        ");
        $stmt->execute([$meeting_id]);
        $edit_meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $completed_meetings = $all_minutes = $class_reps = [];
    $total_minutes = $draft_minutes = $published_minutes = 0;
    error_log("Meeting minutes data error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar (PostgreSQL compatible)
try {
    // Total reps count (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    // Count draft minutes for sidebar badge
    $pending_minutes = 0;
    foreach ($all_minutes as $meeting) {
        if (!empty($meeting['minutes'])) {
            $minutes_data = json_decode($meeting['minutes'], true);
            if (isset($minutes_data['status']) && $minutes_data['status'] === 'draft') {
                $pending_minutes++;
            }
        }
    }
    
    // Upcoming meetings for sidebar (PostgreSQL uses CURRENT_DATE)
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
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
$upcoming_meetings = $sidebar_upcoming_meetings;
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

        .status-draft {
            background: #cce7ff;
            color: #004085;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .status-archived {
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

        .form-textarea {
            min-height: 80px;
            resize: vertical;
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

        .dynamic-list {
            margin-bottom: 1rem;
        }

        .dynamic-item {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: flex-start;
        }

        .dynamic-item .form-control {
            flex: 1;
        }

        .dynamic-item-actions {
            display: flex;
            gap: 0.25rem;
            margin-top: 0.5rem;
        }

        .meeting-info {
            background: var(--light-blue);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-blue);
        }

        .meeting-info h4 {
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .meeting-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .meeting-info-item {
            display: flex;
            flex-direction: column;
        }

        .meeting-info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.7rem;
        }

        .meeting-info-value {
            color: var(--text-dark);
        }

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
                    <h1>Isonga - Meeting Minutes</h1>
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
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php" class="active">
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
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
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
                    <h1>Meeting Minutes</h1>
                    <p>Record and manage minutes for class representative meetings</p>
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
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_minutes); ?></div>
                        <div class="stat-label">Total Minutes</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($draft_minutes); ?></div>
                        <div class="stat-label">Draft Minutes</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($published_minutes); ?></div>
                        <div class="stat-label">Published Minutes</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format(count($completed_meetings)); ?></div>
                        <div class="stat-label">Completed Meetings</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <?php if ($action === 'create' || $action === 'edit'): ?>
                        <!-- Create/Edit Meeting Minutes Form -->
                        <div class="card">
                            <div class="card-header">
                                <h3><?php echo $action === 'create' ? 'Create Meeting Minutes' : 'Edit Meeting Minutes'; ?></h3>
                                <div class="card-header-actions">
                                    <a href="meeting_minutes.php" class="card-header-btn" title="Back to Minutes">
                                        <i class="fas fa-arrow-left"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($edit_meeting): ?>
                                    <div class="meeting-info">
                                        <h4>Meeting Information</h4>
                                        <div class="meeting-info-grid">
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-label">Meeting Title</span>
                                                <span class="meeting-info-value"><?php echo htmlspecialchars($edit_meeting['title']); ?></span>
                                            </div>
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-label">Date</span>
                                                <span class="meeting-info-value"><?php echo date('M j, Y', strtotime($edit_meeting['meeting_date'])); ?></span>
                                            </div>
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-label">Time</span>
                                                <span class="meeting-info-value">
                                                    <?php echo date('g:i A', strtotime($edit_meeting['start_time'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($edit_meeting['end_time'])); ?>
                                                </span>
                                            </div>
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-label">Location</span>
                                                <span class="meeting-info-value"><?php echo htmlspecialchars($edit_meeting['location']); ?></span>
                                            </div>
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-label">Organizer</span>
                                                <span class="meeting-info-value"><?php echo htmlspecialchars($edit_meeting['organizer_name']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="">
                                    <input type="hidden" name="meeting_id" value="<?php echo $edit_meeting['id'] ?? ''; ?>">
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="title">Minutes Title *</label>
                                        <input type="text" class="form-control" id="title" name="title" 
                                               value="<?php echo htmlspecialchars($minutes_data['title'] ?? $edit_meeting['title'] ?? ''); ?>" required>
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                                        <div class="form-group">
                                            <label class="form-label" for="meeting_date">Meeting Date *</label>
                                            <input type="date" class="form-control" id="meeting_date" name="meeting_date" 
                                                   value="<?php echo $minutes_data['meeting_date'] ?? $edit_meeting['meeting_date'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="start_time">Start Time *</label>
                                            <input type="time" class="form-control" id="start_time" name="start_time" 
                                                   value="<?php echo $minutes_data['start_time'] ?? $edit_meeting['start_time'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="end_time">End Time *</label>
                                            <input type="time" class="form-control" id="end_time" name="end_time" 
                                                   value="<?php echo $minutes_data['end_time'] ?? $edit_meeting['end_time'] ?? ''; ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="location">Location *</label>
                                        <input type="text" class="form-control" id="location" name="location" 
                                               value="<?php echo htmlspecialchars($minutes_data['location'] ?? $edit_meeting['location'] ?? ''); ?>" required>
                                    </div>

                                    <!-- Attendees and Absentees -->
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                        <div class="form-group">
                                            <label class="form-label">Attendees</label>
                                            <div class="attendee-list">
                                                <?php 
                                                $saved_attendees = $minutes_data['attendees'] ?? [];
                                                foreach ($class_reps as $rep): 
                                                ?>
                                                    <div class="attendee-item">
                                                        <input type="checkbox" id="attendee_<?php echo $rep['id']; ?>" 
                                                               name="attendees[]" value="<?php echo $rep['id']; ?>"
                                                               <?php echo in_array($rep['id'], $saved_attendees) ? 'checked' : ''; ?>>
                                                        <label for="attendee_<?php echo $rep['id']; ?>">
                                                            <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                            <br>
                                                            <small><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?></small>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Absentees</label>
                                            <div class="attendee-list">
                                                <?php 
                                                $saved_absentees = $minutes_data['absentees'] ?? [];
                                                foreach ($class_reps as $rep): 
                                                ?>
                                                    <div class="attendee-item">
                                                        <input type="checkbox" id="absentee_<?php echo $rep['id']; ?>" 
                                                               name="absentees[]" value="<?php echo $rep['id']; ?>"
                                                               <?php echo in_array($rep['id'], $saved_absentees) ? 'checked' : ''; ?>>
                                                        <label for="absentee_<?php echo $rep['id']; ?>">
                                                            <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                            <br>
                                                            <small><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?></small>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Agenda Items -->
                                    <div class="form-group">
                                        <label class="form-label">Agenda Items</label>
                                        <div class="dynamic-list" id="agenda-items-container">
                                            <?php 
                                            $saved_agenda = $minutes_data['agenda_items'] ?? [''];
                                            foreach ($saved_agenda as $item): 
                                                if (empty(trim($item))) continue;
                                            ?>
                                                <div class="dynamic-item">
                                                    <input type="text" class="form-control" name="agenda_items[]" 
                                                           value="<?php echo htmlspecialchars($item); ?>" placeholder="Agenda item">
                                                    <div class="dynamic-item-actions">
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm" onclick="addDynamicItem('agenda-items-container', 'agenda_items', false)" style="background: var(--medium-gray);">
                                            <i class="fas fa-plus"></i> Add Agenda Item
                                        </button>
                                    </div>

                                    <!-- Discussion Points -->
                                    <div class="form-group">
                                        <label class="form-label">Discussion Points</label>
                                        <div class="dynamic-list" id="discussion-points-container">
                                            <?php 
                                            $saved_discussion = $minutes_data['discussion_points'] ?? [''];
                                            foreach ($saved_discussion as $point): 
                                                if (empty(trim($point))) continue;
                                            ?>
                                                <div class="dynamic-item">
                                                    <textarea class="form-control form-textarea" name="discussion_points[]" 
                                                              placeholder="Discussion point"><?php echo htmlspecialchars($point); ?></textarea>
                                                    <div class="dynamic-item-actions">
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm" onclick="addDynamicItem('discussion-points-container', 'discussion_points', true)" style="background: var(--medium-gray);">
                                            <i class="fas fa-plus"></i> Add Discussion Point
                                        </button>
                                    </div>

                                    <!-- Decisions Made -->
                                    <div class="form-group">
                                        <label class="form-label">Decisions Made</label>
                                        <div class="dynamic-list" id="decisions-container">
                                            <?php 
                                            $saved_decisions = $minutes_data['decisions_made'] ?? [''];
                                            foreach ($saved_decisions as $decision): 
                                                if (empty(trim($decision))) continue;
                                            ?>
                                                <div class="dynamic-item">
                                                    <textarea class="form-control form-textarea" name="decisions_made[]" 
                                                              placeholder="Decision made"><?php echo htmlspecialchars($decision); ?></textarea>
                                                    <div class="dynamic-item-actions">
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm" onclick="addDynamicItem('decisions-container', 'decisions_made', true)" style="background: var(--medium-gray);">
                                            <i class="fas fa-plus"></i> Add Decision
                                        </button>
                                    </div>

                                    <!-- Action Items -->
                                    <div class="form-group">
                                        <label class="form-label">Action Items</label>
                                        <div class="dynamic-list" id="action-items-container">
                                            <?php 
                                            $saved_actions = $minutes_data['action_items'] ?? [''];
                                            foreach ($saved_actions as $action_item): 
                                                if (empty(trim($action_item))) continue;
                                            ?>
                                                <div class="dynamic-item">
                                                    <textarea class="form-control form-textarea" name="action_items[]" 
                                                              placeholder="Action item"><?php echo htmlspecialchars($action_item); ?></textarea>
                                                    <div class="dynamic-item-actions">
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm" onclick="addDynamicItem('action-items-container', 'action_items', true)" style="background: var(--medium-gray);">
                                            <i class="fas fa-plus"></i> Add Action Item
                                        </button>
                                    </div>

                                    <!-- Next Meeting -->
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                        <div class="form-group">
                                            <label class="form-label" for="next_meeting_date">Next Meeting Date</label>
                                            <input type="date" class="form-control" id="next_meeting_date" name="next_meeting_date" 
                                                   value="<?php echo $minutes_data['next_meeting_date'] ?? ''; ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="status">Status</label>
                                            <select class="form-control" id="status" name="status">
                                                <option value="draft" <?php echo ($minutes_data['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                                <option value="published" <?php echo ($minutes_data['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="next_meeting_agenda">Next Meeting Agenda Preview</label>
                                        <textarea class="form-control form-textarea" id="next_meeting_agenda" name="next_meeting_agenda" 
                                                  placeholder="Brief overview of agenda for next meeting"><?php echo htmlspecialchars($minutes_data['next_meeting_agenda'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="additional_notes">Additional Notes</label>
                                        <textarea class="form-control form-textarea" id="additional_notes" name="additional_notes" 
                                                  placeholder="Any additional notes or observations"><?php echo htmlspecialchars($minutes_data['additional_notes'] ?? ''); ?></textarea>
                                    </div>

                                    <button type="submit" name="save_minutes" class="btn btn-primary">
                                        <i class="fas fa-save"></i> 
                                        <?php echo $action === 'create' ? 'Save Minutes' : 'Update Minutes'; ?>
                                    </button>
                                    <a href="meeting_minutes.php" class="btn" style="background: var(--medium-gray);">Cancel</a>
                                </form>
                            </div>
                        </div>

                    <?php elseif ($action === 'view' && isset($edit_meeting) && isset($minutes_data)): ?>
                        <!-- View Meeting Minutes -->
                        <div class="card">
                            <div class="card-header">
                                <h3>View Meeting Minutes</h3>
                                <div class="card-header-actions">
                                    <a href="?action=edit&id=<?php echo $edit_meeting['id']; ?>" class="btn btn-sm" title="Edit" style="background: var(--light-gray);">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="meeting_minutes.php" class="card-header-btn" title="Back to Minutes">
                                        <i class="fas fa-arrow-left"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="meeting-info">
                                    <h4>Meeting Information</h4>
                                    <div class="meeting-info-grid">
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-label">Meeting Title</span>
                                            <span class="meeting-info-value"><?php echo htmlspecialchars($edit_meeting['title']); ?></span>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-label">Date</span>
                                            <span class="meeting-info-value"><?php echo date('M j, Y', strtotime($edit_meeting['meeting_date'])); ?></span>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-label">Time</span>
                                            <span class="meeting-info-value">
                                                <?php echo date('g:i A', strtotime($edit_meeting['start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($edit_meeting['end_time'])); ?>
                                            </span>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-label">Location</span>
                                            <span class="meeting-info-value"><?php echo htmlspecialchars($edit_meeting['location']); ?></span>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-label">Organizer</span>
                                            <span class="meeting-info-value"><?php echo htmlspecialchars($edit_meeting['organizer_name']); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div style="margin-top: 2rem;">
                                    <h4 style="margin-bottom: 1rem; color: var(--primary-blue);">Minutes Details</h4>
                                    
                                    <div style="background: var(--white); padding: 1.5rem; border-radius: var(--border-radius); border: 1px solid var(--medium-gray);">
                                        <h5 style="margin-bottom: 1rem; color: var(--text-dark);"><?php echo htmlspecialchars($minutes_data['title'] ?? 'Meeting Minutes'); ?></h5>
                                        
                                        <div style="margin-bottom: 1.5rem;">
                                            <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Attendees</h6>
                                            <?php if (!empty($minutes_data['attendees'])): ?>
                                                <ul style="list-style: none; padding-left: 0;">
                                                    <?php 
                                                    foreach ($minutes_data['attendees'] as $attendee_id): 
                                                        $attendee = array_filter($class_reps, function($rep) use ($attendee_id) {
                                                            return $rep['id'] == $attendee_id;
                                                        });
                                                        $attendee = reset($attendee);
                                                        if ($attendee):
                                                    ?>
                                                        <li style="padding: 0.25rem 0;">
                                                            <i class="fas fa-user-check" style="color: var(--success); margin-right: 0.5rem;"></i>
                                                            <?php echo htmlspecialchars($attendee['full_name']); ?>
                                                            <?php if (!empty($attendee['department_name'])): ?>
                                                                <small style="color: var(--dark-gray); margin-left: 0.5rem;">(<?php echo htmlspecialchars($attendee['department_name']); ?>)</small>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endif; endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p style="color: var(--dark-gray); font-style: italic;">No attendees recorded</p>
                                            <?php endif; ?>
                                        </div>

                                        <div style="margin-bottom: 1.5rem;">
                                            <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Absentees</h6>
                                            <?php if (!empty($minutes_data['absentees'])): ?>
                                                <ul style="list-style: none; padding-left: 0;">
                                                    <?php 
                                                    foreach ($minutes_data['absentees'] as $absentee_id): 
                                                        $absentee = array_filter($class_reps, function($rep) use ($absentee_id) {
                                                            return $rep['id'] == $absentee_id;
                                                        });
                                                        $absentee = reset($absentee);
                                                        if ($absentee):
                                                    ?>
                                                        <li style="padding: 0.25rem 0;">
                                                            <i class="fas fa-user-times" style="color: var(--danger); margin-right: 0.5rem;"></i>
                                                            <?php echo htmlspecialchars($absentee['full_name']); ?>
                                                            <?php if (!empty($absentee['department_name'])): ?>
                                                                <small style="color: var(--dark-gray); margin-left: 0.5rem;">(<?php echo htmlspecialchars($absentee['department_name']); ?>)</small>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endif; endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p style="color: var(--dark-gray); font-style: italic;">No absentees recorded</p>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($minutes_data['agenda_items']) && count(array_filter($minutes_data['agenda_items'])) > 0): ?>
                                            <div style="margin-bottom: 1.5rem;">
                                                <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Agenda Items</h6>
                                                <ol style="padding-left: 1.5rem;">
                                                    <?php foreach ($minutes_data['agenda_items'] as $item): if (!empty(trim($item))): ?>
                                                        <li style="padding: 0.25rem 0;"><?php echo htmlspecialchars($item); ?></li>
                                                    <?php endif; endforeach; ?>
                                                </ol>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($minutes_data['discussion_points']) && count(array_filter($minutes_data['discussion_points'])) > 0): ?>
                                            <div style="margin-bottom: 1.5rem;">
                                                <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Discussion Points</h6>
                                                <ul style="padding-left: 1.5rem;">
                                                    <?php foreach ($minutes_data['discussion_points'] as $point): if (!empty(trim($point))): ?>
                                                        <li style="padding: 0.25rem 0;"><?php echo htmlspecialchars($point); ?></li>
                                                    <?php endif; endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($minutes_data['decisions_made']) && count(array_filter($minutes_data['decisions_made'])) > 0): ?>
                                            <div style="margin-bottom: 1.5rem;">
                                                <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Decisions Made</h6>
                                                <ul style="padding-left: 1.5rem;">
                                                    <?php foreach ($minutes_data['decisions_made'] as $decision): if (!empty(trim($decision))): ?>
                                                        <li style="padding: 0.25rem 0;"><?php echo htmlspecialchars($decision); ?></li>
                                                    <?php endif; endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($minutes_data['action_items']) && count(array_filter($minutes_data['action_items'])) > 0): ?>
                                            <div style="margin-bottom: 1.5rem;">
                                                <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Action Items</h6>
                                                <ul style="padding-left: 1.5rem;">
                                                    <?php foreach ($minutes_data['action_items'] as $action_item): if (!empty(trim($action_item))): ?>
                                                        <li style="padding: 0.25rem 0;"><?php echo htmlspecialchars($action_item); ?></li>
                                                    <?php endif; endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($minutes_data['next_meeting_date'])): ?>
                                            <div style="margin-bottom: 1.5rem;">
                                                <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Next Meeting</h6>
                                                <p>
                                                    <strong>Date:</strong> <?php echo date('M j, Y', strtotime($minutes_data['next_meeting_date'])); ?>
                                                    <?php if (!empty($minutes_data['next_meeting_agenda'])): ?>
                                                        <br>
                                                        <strong>Agenda Preview:</strong> <?php echo htmlspecialchars($minutes_data['next_meeting_agenda']); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($minutes_data['additional_notes'])): ?>
                                            <div style="margin-bottom: 1.5rem;">
                                                <h6 style="color: var(--dark-gray); margin-bottom: 0.5rem;">Additional Notes</h6>
                                                <p style="white-space: pre-wrap;"><?php echo htmlspecialchars($minutes_data['additional_notes']); ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--medium-gray);">
                                            <p style="color: var(--dark-gray); font-size: 0.8rem;">
                                                <strong>Prepared by:</strong> <?php echo htmlspecialchars($minutes_data['prepared_by_name'] ?? 'Secretary'); ?>
                                                <br>
                                                <strong>Prepared on:</strong> <?php echo date('M j, Y g:i A', strtotime($minutes_data['prepared_at'] ?? 'now')); ?>
                                                <br>
                                                <strong>Status:</strong> 
                                                <span class="status-badge status-<?php echo $minutes_data['status'] ?? 'draft'; ?>">
                                                    <?php echo ucfirst($minutes_data['status'] ?? 'draft'); ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Meeting Minutes List -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Meeting Minutes</h3>
                                <div class="card-header-actions">
                                    <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-container">
                                    <?php if (empty($all_minutes)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-file-alt"></i>
                                            <h4>No Meeting Minutes</h4>
                                            <p>No meeting minutes have been recorded yet.</p>
                                            <?php if (!empty($completed_meetings)): ?>
                                                <a href="?action=create&meeting_id=<?php echo $completed_meetings[0]['id']; ?>" class="btn btn-primary" style="margin-top: 1rem;">
                                                    <i class="fas fa-plus"></i> Create First Minutes
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Meeting</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Prepared</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($all_minutes as $meeting): 
                                                    $minutes_data = !empty($meeting['minutes']) ? json_decode($meeting['minutes'], true) : [];
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                            <br>
                                                            <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($minutes_data['title'] ?? 'Minutes'); ?></small>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?>
                                                            <br>
                                                            <small style="color: var(--dark-gray);">
                                                                <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                                                <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $minutes_data['status'] ?? 'draft'; ?>">
                                                                <?php echo ucfirst($minutes_data['status'] ?? 'draft'); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($minutes_data['prepared_at'])): ?>
                                                                <?php echo date('M j, Y', strtotime($minutes_data['prepared_at'])); ?>
                                                                <br>
                                                                <small style="color: var(--dark-gray);">
                                                                    <?php echo date('g:i A', strtotime($minutes_data['prepared_at'])); ?>
                                                                </small>
                                                            <?php else: ?>
                                                                <small style="color: var(--dark-gray);">Not recorded</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                                <a href="?action=view&id=<?php echo $meeting['id']; ?>" class="btn btn-sm" title="View" style="background: var(--light-gray);">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                                <a href="?action=edit&id=<?php echo $meeting['id']; ?>" class="btn btn-sm" title="Edit" style="background: var(--light-gray);">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <?php if (($minutes_data['status'] ?? 'draft') === 'draft'): ?>
                                                                    <form method="POST" action="" style="display: inline;">
                                                                        <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                                                        <input type="hidden" name="status" value="published">
                                                                        <button type="submit" name="update_status" class="btn btn-sm btn-success" title="Publish">
                                                                            <i class="fas fa-check"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <a href="?action=delete&id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete these minutes?')">
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

                        <!-- Completed Meetings for Minutes Creation -->
                        <div class="card" style="margin-top: 1.5rem;">
                            <div class="card-header">
                                <h3>Completed Meetings (Ready for Minutes)</h3>
                            </div>
                            <div class="card-body">
                                <div class="table-container">
                                    <?php if (empty($completed_meetings)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-calendar-check"></i>
                                            <p>No completed meetings found</p>
                                        </div>
                                    <?php else: ?>
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Meeting Title</th>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th>Location</th>
                                                    <th>Organizer</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($completed_meetings as $meeting): 
                                                    $has_minutes = !empty($meeting['minutes']);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                                            <?php if ($has_minutes): ?>
                                                                <br>
                                                                <small style="color: var(--success);">Minutes already taken</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?></td>
                                                        <td>
                                                            <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                                            <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                                        <td><?php echo htmlspecialchars($meeting['organizer_name']); ?></td>
                                                        <td>
                                                            <?php if (!$has_minutes): ?>
                                                                <a href="?action=create&meeting_id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-primary">
                                                                    <i class="fas fa-file-alt"></i> Take Minutes
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="?action=view&id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-success">
                                                                    <i class="fas fa-eye"></i> View Minutes
                                                                </a>
                                                            <?php endif; ?>
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
                                <?php if (!empty($completed_meetings)): ?>
                                    <a href="?action=create&meeting_id=<?php echo $completed_meetings[0]['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Create Minutes
                                    </a>
                                <?php else: ?>
                                    <button class="btn" style="background: var(--medium-gray);" disabled>
                                        <i class="fas fa-plus"></i> No Completed Meetings
                                    </button>
                                <?php endif; ?>
                                <a href="class_rep_meetings.php" class="btn btn-success">
                                    <i class="fas fa-calendar-alt"></i> View Meetings
                                </a>
                                <a href="documents.php" class="btn btn-info">
                                    <i class="fas fa-folder"></i> Documents
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Meetings</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($completed_meetings)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <p>No completed meetings</p>
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($completed_meetings, 0, 5) as $meeting): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                            <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                            <?php if (!empty($meeting['minutes'])): ?>
                                                <span style="font-size: 0.6rem; color: var(--success);">✓</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                            <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?>
                                            <br>
                                            <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($meeting['location']); ?></small>
                                            <br>
                                            <?php if (empty($meeting['minutes'])): ?>
                                                <a href="?action=create&meeting_id=<?php echo $meeting['id']; ?>" class="btn btn-sm" style="margin-top: 0.5rem; font-size: 0.6rem; background: var(--light-gray);">
                                                    <i class="fas fa-file-alt"></i> Take Minutes
                                                </a>
                                            <?php else: ?>
                                                <small style="color: var(--success);">Minutes taken</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Minutes Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Minutes Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-overview">
                                <div class="overview-item">
                                    <span class="overview-label">Total Minutes</span>
                                    <span class="overview-value"><?php echo $total_minutes; ?></span>
                                </div>
                                <div class="overview-item">
                                    <span class="overview-label">Draft</span>
                                    <span class="overview-value"><?php echo $draft_minutes; ?></span>
                                </div>
                                <div class="overview-item">
                                    <span class="overview-label">Published</span>
                                    <span class="overview-value"><?php echo $published_minutes; ?></span>
                                </div>
                                <div class="overview-item">
                                    <span class="overview-label">Completion Rate</span>
                                    <span class="overview-value">
                                        <?php 
                                        $total_completed = count($completed_meetings);
                                        $rate = $total_completed > 0 ? round(($total_minutes / $total_completed) * 100) : 0;
                                        echo $rate; 
                                        ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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

        // Add dynamic items to forms
        function addDynamicItem(containerId, fieldName, isTextarea = false) {
            const container = document.getElementById(containerId);
            const item = document.createElement('div');
            item.className = 'dynamic-item';
            
            if (isTextarea) {
                item.innerHTML = `
                    <textarea class="form-control form-textarea" name="${fieldName}[]" placeholder="${fieldName.replace(/_/g, ' ')}"></textarea>
                    <div class="dynamic-item-actions">
                        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            } else {
                item.innerHTML = `
                    <input type="text" class="form-control" name="${fieldName}[]" placeholder="${fieldName.replace(/_/g, ' ')}">
                    <div class="dynamic-item-actions">
                        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            }
            
            container.appendChild(item);
        }

        // Prevent selecting same person as attendee and absentee
        document.addEventListener('DOMContentLoaded', function() {
            const attendeeCheckboxes = document.querySelectorAll('input[name="attendees[]"]');
            const absenteeCheckboxes = document.querySelectorAll('input[name="absentees[]"]');
            
            function syncCheckboxes(source, target) {
                source.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        if (this.checked) {
                            const corresponding = document.querySelector(`input[name="${target}[]"][value="${this.value}"]`);
                            if (corresponding) {
                                corresponding.checked = false;
                            }
                        }
                    });
                });
            }
            
            if (attendeeCheckboxes.length > 0 && absenteeCheckboxes.length > 0) {
                syncCheckboxes(attendeeCheckboxes, 'absentees');
                syncCheckboxes(absenteeCheckboxes, 'attendees');
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