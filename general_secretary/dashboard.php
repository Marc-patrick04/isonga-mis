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
    
    // Check if user needs to change password (first login)
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// Get dashboard statistics for General Secretary
try {
    // Total students count
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'];
    
    // New students this month
    $stmt = $pdo->query("SELECT COUNT(*) as new_students FROM users WHERE role = 'student' AND status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $new_students = $stmt->fetch(PDO::FETCH_ASSOC)['new_students'];
    
    // Total committee members
    $stmt = $pdo->query("SELECT COUNT(*) as committee_members FROM committee_members WHERE status = 'active'");
    $committee_members = $stmt->fetch(PDO::FETCH_ASSOC)['committee_members'];
    
    // Total meetings this month
    $stmt = $pdo->query("SELECT COUNT(*) as total_meetings FROM meetings WHERE MONTH(meeting_date) = MONTH(CURRENT_DATE()) AND YEAR(meeting_date) = YEAR(CURRENT_DATE())");
    $total_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['total_meetings'];
    
    // Upcoming meetings
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM meetings WHERE meeting_date >= CURDATE() AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'];
    
    // Recent meeting attendance rate
    try {
        $stmt = $pdo->query("
            SELECT 
                m.id,
                COUNT(ma.id) as total_attendees,
                SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as present_count
            FROM meetings m
            LEFT JOIN meeting_attendance ma ON m.id = ma.meeting_id
            WHERE m.meeting_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY m.id
            ORDER BY m.meeting_date DESC
            LIMIT 5
        ");
        $recent_meetings_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_attendance_rate = 0;
        $meeting_count = count($recent_meetings_attendance);
        
        foreach ($recent_meetings_attendance as $meeting) {
            if ($meeting['total_attendees'] > 0) {
                $attendance_rate = ($meeting['present_count'] / $meeting['total_attendees']) * 100;
                $total_attendance_rate += $attendance_rate;
            }
        }
        
        $average_attendance_rate = $meeting_count > 0 ? round($total_attendance_rate / $meeting_count) : 0;
        
    } catch (PDOException $e) {
        error_log("Attendance calculation error: " . $e->getMessage());
        $average_attendance_rate = 0;
    }
    
    // Pending documents for processing
    $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
    $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];
    
    // Total documents processed
    $stmt = $pdo->query("SELECT COUNT(*) as total_docs FROM documents WHERE status IN ('generated', 'approved', 'distributed')");
    $total_docs = $stmt->fetch(PDO::FETCH_ASSOC)['total_docs'];
    
    // Recent student registrations
    $stmt = $pdo->query("
        SELECT u.*, d.name as department_name, p.name as program_name 
        FROM users u 
        LEFT JOIN departments d ON u.department_id = d.id 
        LEFT JOIN programs p ON u.program_id = p.id 
        WHERE u.role = 'student' AND u.status = 'active' 
        ORDER BY u.created_at DESC 
        LIMIT 5
    ");
    $recent_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming meetings with details
    $stmt = $pdo->query("
        SELECT m.*, u.full_name as chairperson_name 
        FROM meetings m 
        LEFT JOIN users u ON m.chairperson_id = u.id 
        WHERE m.meeting_date >= CURDATE() AND m.status = 'scheduled' 
        ORDER BY m.meeting_date ASC, m.start_time ASC 
        LIMIT 5
    ");
    $upcoming_meetings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activities (login activities)
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        ORDER BY la.login_time DESC 
        LIMIT 8
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Unread messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    
    // Student statistics by department
    $stmt = $pdo->query("
        SELECT d.name as department_name, COUNT(u.id) as student_count
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.role = 'student' AND u.status = 'active'
        GROUP BY d.name
        ORDER BY student_count DESC
    ");
    $students_by_department = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Committee attendance statistics
    try {
        $stmt = $pdo->query("
            SELECT 
                cm.name,
                cm.role,
                COUNT(ma.id) as total_meetings,
                SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as attended_meetings
            FROM committee_members cm
            LEFT JOIN meeting_attendance ma ON cm.id = ma.committee_member_id
            LEFT JOIN meetings m ON ma.meeting_id = m.id
            WHERE cm.status = 'active' AND m.meeting_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY cm.id, cm.name, cm.role
            ORDER BY attended_meetings DESC
            LIMIT 5
        ");
        $committee_attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Committee attendance stats error: " . $e->getMessage());
        $committee_attendance_stats = [];
    }
    
} catch (PDOException $e) {
    // Handle general error
    error_log("General Secretary dashboard statistics error: " . $e->getMessage());
    $total_students = $new_students = $committee_members = $total_meetings = $upcoming_meetings = 0;
    $average_attendance_rate = $pending_docs = $total_docs = $unread_messages = 0;
    $recent_students = $upcoming_meetings_list = $recent_activities = [];
    $students_by_department = $committee_attendance_stats = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Secretary Dashboard - Isonga RPSU</title>
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
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--primary-blue);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.75rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert a {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* Dark Mode Toggle */
        .theme-toggle {
            position: relative;
            width: 50px;
            height: 26px;
        }

        .theme-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--medium-gray);
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary-blue);
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        /* Department Stats */
        .department-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .department-stat {
            background: var(--light-gray);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .department-name {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .department-count {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Attendance Progress */
        .attendance-progress {
            margin-top: 0.5rem;
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
            background: var(--success);
            border-radius: 3px;
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
        }

        /* Responsive */
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
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
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
                    <h1>Isonga - General Secretary</h1>
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
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                        <li class="menu-item">
            <a href="tickets.php">
                <i class="fas fa-ticket-alt"></i>
                <span>Student Tickets</span>
                <?php
                // Get pending tickets count for badge
                try {
                    $ticketStmt = $pdo->prepare("
                        SELECT COUNT(*) as pending_tickets 
                        FROM tickets 
                        WHERE status IN ('open', 'in_progress') 
                        AND (assigned_to = ? OR assigned_to IS NULL)
                    ");
                    $ticketStmt->execute([$user_id]);
                    $pending_tickets = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'];
                } catch (PDOException $e) {
                    $pending_tickets = 0;
                }
                ?>
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
    <a href="meeting_minutes.php">
        <i class="fas fa-clipboard-list"></i>
        <span>Meeting Minutes</span>
        <?php
        // Count pending minutes (minutes that need to be written/approved)
        try {
            $pending_minutes = $pdo->query("
                SELECT COUNT(*) as count FROM meetings 
                WHERE status = 'completed' 
                AND id NOT IN (SELECT meeting_id FROM meeting_minutes WHERE status = 'approved')
            ")->fetch()['count'];
        } catch (PDOException $e) {
            $pending_minutes = 0;
        }
        ?>
        <?php if ($pending_minutes > 0): ?>
            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
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
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Welcome, Secretary <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 📋</h1>
                    <p>Manage student records, meeting attendance, and committee documentation</p>
                </div>
            </div>

            <?php if ($password_change_required): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Action Required:</strong> Please <a href="profile.php?tab=security">change your password</a> for security reasons.
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $new_students; ?></div>
                        <div class="stat-label">New Students (30 days)</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $committee_members; ?></div>
                        <div class="stat-label">Committee Members</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $average_attendance_rate; ?>%</div>
                        <div class="stat-label">Avg. Meeting Attendance</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Grid -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_meetings; ?></div>
                        <div class="stat-label">Meetings This Month</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_meetings; ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_docs; ?></div>
                        <div class="stat-label">Pending Documents</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_docs; ?></div>
                        <div class="stat-label">Documents Processed</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Student Registrations -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Student Registrations</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="students.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Registration No.</th>
                                        <th>Department</th>
                                        <th>Program</th>
                                        <th>Date Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_students)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent student registrations</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_students as $student): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($student['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Upcoming Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Meetings</h3>
                            <div class="card-header-actions">
                                <a href="meetings.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_meetings_list)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No upcoming meetings</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Meeting Title</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Chairperson</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_meetings_list as $meeting): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($meeting['title']); ?></td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($meeting['start_time'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                                <td><?php echo htmlspecialchars($meeting['chairperson_name']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Student Distribution by Department -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Student Distribution by Department</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($students_by_department)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No department data available</p>
                                </div>
                            <?php else: ?>
                                <div class="department-stats">
                                    <?php foreach ($students_by_department as $dept): ?>
                                        <div class="department-stat">
                                            <div class="department-name"><?php echo htmlspecialchars($dept['department_name'] ?? 'Undeclared'); ?></div>
                                            <div class="department-count"><?php echo $dept['student_count']; ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="students.php?action=add" class="action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span class="action-label">Add Student</span>
                        </a>
                        <a href="meetings.php?action=schedule" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Schedule Meeting</span>
                        </a>
                        <a href="documents.php?action=create" class="action-btn">
                            <i class="fas fa-file-medical"></i>
                            <span class="action-label">Create Document</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-bar"></i>
                            <span class="action-label">Generate Report</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent System Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 2rem;">No recent activities</li>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> logged in
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, g:i A', strtotime($activity['login_time'])); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Committee Attendance Leaders -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Committee Attendance Leaders</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($committee_attendance_stats)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No attendance data available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($committee_attendance_stats as $member): ?>
                                    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                            <div>
                                                <strong style="font-size: 0.8rem;"><?php echo htmlspecialchars($member['name']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars(str_replace('_', ' ', $member['role'])); ?></div>
                                            </div>
                                            <div style="text-align: right;">
                                                <div style="font-size: 0.8rem; font-weight: 600;">
                                                    <?php 
                                                    $attendance_rate = $member['total_meetings'] > 0 
                                                        ? round(($member['attended_meetings'] / $member['total_meetings']) * 100) 
                                                        : 0;
                                                    echo $attendance_rate; ?>%
                                                </div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo $member['attended_meetings']; ?>/<?php echo $member['total_meetings']; ?> meetings
                                                </div>
                                            </div>
                                        </div>
                                        <div class="attendance-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $attendance_rate; ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Unread Messages</span>
                                    <strong style="color: var(--text-dark);"><?php echo $unread_messages; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Pending Documents</span>
                                    <strong style="color: var(--text-dark);"><?php echo $pending_docs; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Active Committee</span>
                                    <strong style="color: var(--text-dark);"><?php echo $committee_members; ?> members</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Meetings This Month</span>
                                    <strong style="color: var(--text-dark);"><?php echo $total_meetings; ?></strong>
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

        // Check for saved theme preference or respect OS preference
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

        // Auto-refresh dashboard every 3 minutes
        setInterval(() => {
            // You can add auto-refresh logic here
            console.log('Dashboard auto-refresh triggered');
        }, 180000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>