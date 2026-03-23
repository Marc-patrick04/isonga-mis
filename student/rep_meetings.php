<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'] ?? '';
$program = $_SESSION['program'] ?? '';
$academic_year = $_SESSION['academic_year'] ?? '';

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: rep_meetings.php');
    exit();
}

// FIXED: Get upcoming meetings for the class rep
$upcoming_meetings_stmt = $pdo->prepare("
    SELECT 
        rm.*,
        u.full_name as organizer_name,
        u.email as organizer_email,
        rma.attendance_status,
        rma.check_in_time,
        mm.id as minutes_id
    FROM rep_meetings rm
    JOIN users u ON rm.organizer_id = u.id
    LEFT JOIN rep_meeting_attendance rma ON rm.id = rma.meeting_id AND rma.user_id = ?
    LEFT JOIN meeting_minutes mm ON rm.id = mm.meeting_id
    WHERE rm.meeting_date >= CURRENT_DATE
        AND rm.status IN ('scheduled', 'ongoing')
        AND (
    rm.required_attendees IS NULL
    OR rm.required_attendees = '[]'::jsonb
    OR ? = ANY (SELECT jsonb_array_elements_text(rm.required_attendees))
    OR (SELECT COUNT(*) FROM users WHERE is_class_rep = '1' AND status = 'active') = jsonb_array_length(rm.required_attendees)
)
    ORDER BY rm.meeting_date ASC, rm.start_time ASC
    LIMIT 10
");
$upcoming_meetings_stmt->execute([$student_id, $student_id]);
$upcoming_meetings = $upcoming_meetings_stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Get past meetings with attendance
$past_meetings_stmt = $pdo->prepare("
    SELECT 
        rm.*,
        u.full_name as organizer_name,
        rma.attendance_status,
        rma.check_in_time,
        mm.id as minutes_id
    FROM rep_meetings rm
    JOIN users u ON rm.organizer_id = u.id
    LEFT JOIN rep_meeting_attendance rma ON rm.id = rma.meeting_id AND rma.user_id = ?
    LEFT JOIN meeting_minutes mm ON rm.id = mm.meeting_id
    WHERE rm.meeting_date < CURRENT_DATE
        AND rm.status IN ('completed', 'cancelled')
        AND (
           
    rm.required_attendees IS NULL
    OR rm.required_attendees = '[]'::jsonb
    OR ? = ANY (SELECT jsonb_array_elements_text(rm.required_attendees))
    OR (SELECT COUNT(*) FROM users WHERE is_class_rep = '1' AND status = 'active') = jsonb_array_length(rm.required_attendees)
)
        
    ORDER BY rm.meeting_date DESC
    LIMIT 10
");
$past_meetings_stmt->execute([$student_id, $student_id]);
$past_meetings = $past_meetings_stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Get meeting statistics
$meeting_stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT rm.id) as total_meetings,
        SUM(CASE WHEN rm.meeting_date >= CURRENT_DATE THEN 1 ELSE 0 END) as upcoming_meetings,
        SUM(CASE WHEN rm.meeting_date < CURRENT_DATE THEN 1 ELSE 0 END) as past_meetings,
        SUM(CASE WHEN rma.attendance_status = 'present' THEN 1 ELSE 0 END) as meetings_attended,
        SUM(CASE WHEN rma.attendance_status = 'absent' THEN 1 ELSE 0 END) as meetings_missed,
        SUM(CASE WHEN rma.attendance_status = 'excused' THEN 1 ELSE 0 END) as meetings_excused
    FROM rep_meetings rm
    LEFT JOIN rep_meeting_attendance rma ON rm.id = rma.meeting_id AND rma.user_id = ?
    WHERE (
    rm.required_attendees IS NULL
    OR rm.required_attendees = '[]'::jsonb
    OR ? = ANY (SELECT jsonb_array_elements_text(rm.required_attendees))
    OR (SELECT COUNT(*) FROM users WHERE is_class_rep = '1' AND status = 'active') = jsonb_array_length(rm.required_attendees)
)
");
$meeting_stats_stmt->execute([$student_id, $student_id]);
$meeting_stats = $meeting_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Helper functions
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

function formatMeetingDateTime($date, $start_time, $end_time) {
    $date_str = date('M j, Y', strtotime($date));
    $start_str = date('g:i A', strtotime($start_time));
    $end_str = date('g:i A', strtotime($end_time));
    return "$date_str | $start_str - $end_str";
}

function getMeetingStatusBadge($status, $meeting_date, $start_time) {
    $now = time();
    $meeting_datetime = strtotime("$meeting_date $start_time");
    
    if ($status === 'cancelled') {
        return '<span class="status status-cancelled">Cancelled</span>';
    } elseif ($status === 'completed') {
        return '<span class="status status-completed">Completed</span>';
    } elseif ($status === 'ongoing') {
        return '<span class="status status-ongoing">Ongoing</span>';
    } elseif ($meeting_datetime < $now) {
        return '<span class="status status-past">Past</span>';
    } else {
        return '<span class="status status-upcoming">Upcoming</span>';
    }
}

function getAttendanceBadge($status) {
    switch ($status) {
        case 'present':
            return '<span class="attendance attendance-present">Present</span>';
        case 'absent':
            return '<span class="attendance attendance-absent">Absent</span>';
        case 'excused':
            return '<span class="attendance attendance-excused">Excused</span>';
        case 'late':
            return '<span class="attendance attendance-late">Late</span>';
        default:
            return '<span class="attendance attendance-unknown">Not Recorded</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Representative Meetings - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0056b3; --secondary: #1e88e5; --accent: #0d47a1;
            --light: #f8f9fa; --white: #fff; --gray: #e9ecef; 
            --dark-gray: #6c757d; --text: #2c3e50; --success: #28a745;
            --warning: #ffc107; --danger: #dc3545; --info: #17a2b8;
            --shadow: 0 4px 12px rgba(0,0,0,0.1); --radius: 8px; 
            --transition: all 0.3s ease;
        }
        [data-theme="dark"] {
            --white: #1a1a1a; --light: #2d2d2d; --gray: #3d3d3d;
            --dark-gray: #a0a0a0; --text: #e9ecef;
            --shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); transition: var(--transition); }
        .container { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 250px; height: 100vh; z-index: 1000; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.5rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.8rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        
        /* Main Content */
        .main { grid-column: 2; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; background: var(--white); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .actions { display: flex; gap: 1rem; }
        .btn { padding: 0.8rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        .stat-label { font-size: 0.9rem; color: var(--dark-gray); }
        
        /* Cards */
        .card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-title { font-size: 1.2rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.9rem; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 1rem; }
        
        /* Meeting List */
        .meeting-list { display: grid; gap: 1rem; }
        .meeting-card { padding: 1.5rem; background: var(--light); border-radius: var(--radius); transition: var(--transition); border-left: 4px solid var(--secondary); }
        .meeting-card:hover { background: var(--gray); transform: translateY(-2px); }
        .meeting-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; }
        .meeting-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; flex: 1; }
        .meeting-meta { display: flex; gap: 1rem; font-size: 0.85rem; color: var(--dark-gray); margin-bottom: 0.5rem; }
        .meeting-datetime { font-weight: 600; color: var(--text); }
        .meeting-organizer { font-style: italic; }
        .meeting-description { color: var(--dark-gray); margin-bottom: 1rem; line-height: 1.5; }
        .meeting-footer { display: flex; justify-content: space-between; align-items: center; }
        
        /* Status Badges */
        .status { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .status-upcoming { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-ongoing { background: rgba(255,193,7,0.1); color: var(--warning); }
        .status-completed { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        .status-cancelled { background: rgba(220,53,69,0.1); color: var(--danger); }
        .status-past { background: rgba(23,162,184,0.1); color: var(--info); }
        
        /* Attendance Badges */
        .attendance { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .attendance-present { background: rgba(40,167,69,0.1); color: var(--success); }
        .attendance-absent { background: rgba(220,53,69,0.1); color: var(--danger); }
        .attendance-excused { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        .attendance-late { background: rgba(255,193,7,0.1); color: var(--warning); }
        .attendance-unknown { background: rgba(23,162,184,0.1); color: var(--info); }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 3rem; color: var(--dark-gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; color: var(--gray); }
        
        /* Alert */
        .alert { padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        .alert-warning { background: rgba(255,193,7,0.1); color: var(--warning); border-left-color: var(--warning); }
        .alert-success { background: rgba(40,167,69,0.1); color: var(--success); border-left-color: var(--success); }
        .alert-error { background: rgba(220,53,69,0.1); color: var(--danger); border-left-color: var(--danger); }
        
        /* Tabs */
        .tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 2px solid var(--gray); }
        .tab { padding: 0.8rem 1.5rem; background: none; border: none; color: var(--dark-gray); cursor: pointer; transition: var(--transition); border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .tab.active { color: var(--secondary); border-bottom-color: var(--secondary); font-weight: 600; }
        .tab:hover { color: var(--secondary); }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: var(--white); border-radius: var(--radius); width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--gray); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 1.5rem; }
        .tab-content { display: block; }
        
        /* Button Styles */
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 600;
        }
        .btn-agenda {
            background: var(--info);
            color: white;
        }
        .btn-agenda:hover {
            background: #138496;
            transform: translateY(-1px);
        }
        .btn-minutes {
            background: var(--warning);
            color: var(--text);
        }
        .btn-minutes:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }
        .btn-checkin {
            background: var(--success);
            color: white;
        }
        .btn-checkin:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .meeting-header { flex-direction: column; gap: 0.5rem; }
            .meeting-footer { flex-direction: column; gap: 0.5rem; align-items: start; }
            .action-buttons { flex-wrap: wrap; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .tabs { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="brand">
                <div class="brand-logo"><i class="fas fa-user-shield"></i></div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></li>
                <li><a href="class_tickets.php"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_students.php"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="#" class="active"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main">
            <!-- Header -->
            <div class="header">
                <div class="welcome">
                    <h1>Representative Meetings
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p>View scheduled meetings and track your attendance</p>
                </div>
                <div class="actions">
                    <form method="POST">
                        <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Meetings Information:</strong> This page shows meetings scheduled for class representatives. You can view upcoming meetings and check your attendance in past meetings.
            </div>

            <!-- Meeting Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-calendar"></i></div>
                    <div class="stat-number"><?php echo $meeting_stats['total_meetings'] ?? 0; ?></div>
                    <div class="stat-label">Total Meetings</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo $meeting_stats['upcoming_meetings'] ?? 0; ?></div>
                    <div class="stat-label">Upcoming</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo $meeting_stats['meetings_attended'] ?? 0; ?></div>
                    <div class="stat-label">Meetings Attended</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(220,53,69,0.1); color: var(--danger);"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-number"><?php echo $meeting_stats['meetings_missed'] ?? 0; ?></div>
                    <div class="stat-label">Meetings Missed</div>
                </div>
            </div>

            <!-- Debug Info (if no meetings are showing) -->
            <?php if (count($upcoming_meetings) === 0 && count($past_meetings) === 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>No meetings found for your account.</strong> 
                    This could be because:
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <li>No meetings have been scheduled yet</li>
                        <li>You are not included in the required attendees list</li>
                        <li>There are no upcoming or past meetings</li>
                    </ul>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem;">
                        <i class="fas fa-info-circle"></i> 
                        Your Student ID: <?php echo $student_id; ?> | 
                        Name: <?php echo $student_name; ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="showTab('upcoming')">Upcoming Meetings</button>
                <button class="tab" onclick="showTab('past')">Past Meetings & Attendance</button>
            </div>

            <!-- Upcoming Meetings -->
            <div id="upcomingTab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Upcoming Meetings</h3>
                        <span class="link" onclick="refreshPage()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </span>
                    </div>
                    <div class="meeting-list">
                        <?php if (empty($upcoming_meetings)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No Upcoming Meetings</h3>
                                <p>There are no meetings scheduled for you at the moment.</p>
                                <p style="margin-top: 1rem; font-size: 0.9rem;">
                                    <i class="fas fa-info-circle"></i> 
                                    If you believe this is incorrect, please contact the Secretary of Representative Board.
                                </p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_meetings as $meeting): ?>
                                <div class="meeting-card">
                                    <div class="meeting-header">
                                        <div style="flex: 1;">
                                            <div class="meeting-title"><?php echo safe_display($meeting['title']); ?></div>
                                            <div class="meeting-meta">
                                                <span class="meeting-datetime">
                                                    <i class="fas fa-calendar"></i> 
                                                    <?php echo formatMeetingDateTime($meeting['meeting_date'], $meeting['start_time'], $meeting['end_time']); ?>
                                                </span>
                                                <span class="meeting-organizer">
                                                    <i class="fas fa-user"></i> Organized by: <?php echo safe_display($meeting['organizer_name']); ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-map-marker-alt"></i> <?php echo safe_display($meeting['location']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <?php echo getMeetingStatusBadge($meeting['status'], $meeting['meeting_date'], $meeting['start_time']); ?>
                                    </div>
                                    
                                    <?php if ($meeting['description']): ?>
                                        <div class="meeting-description">
                                            <?php echo safe_display($meeting['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="meeting-footer">
                                        <span style="font-size: 0.85rem; color: var(--dark-gray);">
                                            <i class="fas fa-info-circle"></i> 
                                            <?php echo ucfirst(str_replace('_', ' ', $meeting['meeting_type'])); ?> Meeting
                                            <?php if ($meeting['attendance_status']): ?>
                                                | Your Status: <?php echo getAttendanceBadge($meeting['attendance_status']); ?>
                                            <?php endif; ?>
                                        </span>
                                        <div class="action-buttons" style="display: flex; gap: 0.5rem;">
                                            <?php if ($meeting['agenda']): ?>
                                                <button class="btn-sm btn-agenda" 
                                                        onclick="viewAgenda(<?php echo $meeting['id']; ?>)">
                                                    <i class="fas fa-list"></i> Agenda
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($meeting['minutes_id']): ?>
                                                <button class="btn-sm btn-minutes" 
                                                        onclick="viewMinutes(<?php echo $meeting['id']; ?>)">
                                                    <i class="fas fa-file-alt"></i> Minutes
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($meeting['meeting_date'] == date('Y-m-d') && $meeting['status'] === 'scheduled'): ?>
                                                <button class="btn-sm btn-checkin" onclick="checkIn(<?php echo $meeting['id']; ?>)">
                                                    <i class="fas fa-check-circle"></i> Check In
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Past Meetings -->
            <div id="pastTab" class="tab-content" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Past Meetings & Attendance</h3>
                    </div>
                    <div class="meeting-list">
                        <?php if (empty($past_meetings)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h3>No Past Meetings</h3>
                                <p>You haven't attended any meetings yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($past_meetings as $meeting): ?>
                                <div class="meeting-card">
                                    <div class="meeting-header">
                                        <div style="flex: 1;">
                                            <div class="meeting-title"><?php echo safe_display($meeting['title']); ?></div>
                                            <div class="meeting-meta">
                                                <span class="meeting-datetime">
                                                    <i class="fas fa-calendar"></i> 
                                                    <?php echo formatMeetingDateTime($meeting['meeting_date'], $meeting['start_time'], $meeting['end_time']); ?>
                                                </span>
                                                <span class="meeting-organizer">
                                                    <i class="fas fa-user"></i> Organized by: <?php echo safe_display($meeting['organizer_name']); ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-map-marker-alt"></i> <?php echo safe_display($meeting['location']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <?php echo getMeetingStatusBadge($meeting['status'], $meeting['meeting_date'], $meeting['start_time']); ?>
                                            <?php echo getAttendanceBadge($meeting['attendance_status']); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($meeting['description']): ?>
                                        <div class="meeting-description">
                                            <?php echo safe_display($meeting['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="meeting-footer">
                                        <span style="font-size: 0.85rem; color: var(--dark-gray);">
                                            <?php if ($meeting['check_in_time']): ?>
                                                <i class="fas fa-clock"></i> Checked in: <?php echo date('M j, g:i A', strtotime($meeting['check_in_time'])); ?>
                                            <?php endif; ?>
                                        </span>
                                        <div class="action-buttons" style="display: flex; gap: 0.5rem;">
                                            <?php if ($meeting['agenda']): ?>
                                                <button class="btn-sm btn-agenda" 
                                                        onclick="viewAgenda(<?php echo $meeting['id']; ?>)">
                                                    <i class="fas fa-list"></i> Agenda
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($meeting['minutes_id']): ?>
                                                <button class="btn-sm btn-minutes" 
                                                        onclick="viewMinutes(<?php echo $meeting['id']; ?>)">
                                                    <i class="fas fa-file-alt"></i> Minutes
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Agenda Modal -->
    <div id="agendaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Meeting Agenda</h3>
                <button class="icon-btn close-modal" onclick="closeModal('agendaModal')">&times;</button>
            </div>
            <div class="modal-body" id="agendaContent">
                <!-- Agenda content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Minutes Modal -->
    <div id="minutesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Meeting Minutes</h3>
                <button class="icon-btn close-modal" onclick="closeModal('minutesModal')">&times;</button>
            </div>
            <div class="modal-body" id="minutesContent">
                <!-- Minutes content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab and set active
            document.getElementById(tabName + 'Tab').style.display = 'block';
            event.target.classList.add('active');
        }

        // Modal functions
        async function viewAgenda(meetingId) {
            const agendaModal = document.getElementById('agendaModal');
            const agendaContent = document.getElementById('agendaContent');
            
            // Show loading
            agendaContent.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--secondary);"></i>
                    <p style="margin-top: 1rem; color: var(--dark-gray);">Loading agenda...</p>
                </div>
            `;
            agendaModal.style.display = 'flex';
            
            try {
                const response = await fetch(`get_meeting_agenda.php?id=${meetingId}`);
                const data = await response.json();
                
                if (data.success) {
                    agendaContent.innerHTML = data.agenda;
                } else {
                    agendaContent.innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            ${data.message || 'Failed to load agenda'}
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading agenda:', error);
                agendaContent.innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Error loading agenda. Please try again.
                    </div>
                `;
            }
        }

        async function viewMinutes(meetingId) {
            const minutesModal = document.getElementById('minutesModal');
            const minutesContent = document.getElementById('minutesContent');
            
            // Show loading
            minutesContent.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--secondary);"></i>
                    <p style="margin-top: 1rem; color: var(--dark-gray);">Loading minutes...</p>
                </div>
            `;
            minutesModal.style.display = 'flex';
            
            try {
                const response = await fetch(`get_meeting_minutes.php?id=${meetingId}`);
                const data = await response.json();
                
                if (data.success) {
                    minutesContent.innerHTML = data.minutes;
                } else {
                    minutesContent.innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            ${data.message || 'Failed to load minutes'}
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading minutes:', error);
                minutesContent.innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Error loading minutes. Please try again.
                    </div>
                `;
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Check-in function
        function checkIn(meetingId) {
            if (confirm('Do you want to check in for this meeting?')) {
                // In a real implementation, this would make an API call
                alert('Check-in functionality would be implemented here.\nMeeting ID: ' + meetingId);
                // Refresh the page to update status
                setTimeout(() => {
                    location.reload();
                }, 1000);
            }
        }

        // Refresh page function
        function refreshPage() {
            location.reload();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const agendaModal = document.getElementById('agendaModal');
            const minutesModal = document.getElementById('minutesModal');
            
            if (event.target === agendaModal) closeModal('agendaModal');
            if (event.target === minutesModal) closeModal('minutesModal');
        }

        // Escape key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal('agendaModal');
                closeModal('minutesModal');
            }
        });
    </script>
</body>
</html>