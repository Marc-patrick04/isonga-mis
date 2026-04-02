<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_representative_board') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$president_name = $_SESSION['full_name'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// Get dashboard statistics for President of Representative Board
try {
    // 1. Total class representatives - from users table
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = 1 AND status = 'active'");
    $total_reps = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    // 2. Total students in college
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    // 3. Pending class rep reports
    $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM class_rep_reports WHERE status = 'submitted'");
    $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    
    // 4. Upcoming meetings for Representative Board
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_meetings 
        FROM meetings 
        WHERE meeting_date >= CURDATE() 
        AND status = 'scheduled' 
        AND committee_role = 'representative_board'
    ");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    // 5. Student tickets analysis
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_tickets,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets
        FROM tickets 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $ticket_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 6. Representative Board team members (vice president and secretary)
    $stmt = $pdo->query("
        SELECT cm.* 
        FROM committee_members cm
        WHERE cm.role IN ('vice_president_representative_board', 'secretary_representative_board')
        AND cm.status = 'active'
        ORDER BY cm.role_order
    ");
    $board_team = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Recent Representative Board reports
    $stmt = $pdo->query("
        SELECT r.*, u.full_name as author_name
        FROM reports r
        JOIN users u ON r.user_id = u.id
        WHERE r.is_team_report = 1 
        AND r.team_role IN ('president', 'vice_president', 'secretary', 'combined')
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $recent_board_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. President's attendance in meetings
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_meetings,
            SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as attended_meetings,
            SUM(CASE WHEN ma.attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_meetings
        FROM meetings m
        LEFT JOIN meeting_attendance ma ON m.id = ma.meeting_id
        LEFT JOIN committee_members cm ON ma.committee_member_id = cm.id
        WHERE cm.role = 'president_representative_board'
        AND m.meeting_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 9. Recent class representative reports pending review
    $stmt = $pdo->query("
        SELECT crr.*, u.full_name, u.department_id, d.name as department_name 
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE crr.status = 'submitted'
        ORDER BY crr.created_at DESC 
        LIMIT 5
    ");
    $pending_class_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 10. Upcoming meetings for the President to attend
    $stmt = $pdo->query("
        SELECT m.*, u.full_name as chairperson_name
        FROM meetings m
        JOIN users u ON m.chairperson_id = u.id
        WHERE m.meeting_date >= CURDATE()
        AND m.status = 'scheduled'
        AND (m.committee_role = 'representative_board' OR m.meeting_type = 'executive')
        ORDER BY m.meeting_date ASC, m.start_time ASC
        LIMIT 5
    ");
    $upcoming_meetings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 11. Recent activities of Representative Board team
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        WHERE u.role IN ('president_representative_board', 'vice_president_representative_board', 'secretary_representative_board')
        ORDER BY la.login_time DESC 
        LIMIT 6
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 12. President's own budget requests (if any)
    $stmt = $pdo->prepare("
        SELECT cbr.* 
        FROM committee_budget_requests cbr
        JOIN committee_members cm ON cbr.committee_id = cm.id
        WHERE cm.role = 'president_representative_board'
        AND cbr.requested_by = ?
        ORDER BY cbr.created_at DESC 
        LIMIT 3
    ");
    $stmt->execute([$user_id]);
    $president_budget_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 13. Department-wise class representative distribution
    $stmt = $pdo->query("
        SELECT 
            d.name as department_name,
            COUNT(u.id) as rep_count
        FROM users u
        JOIN departments d ON u.department_id = d.id
        WHERE u.is_class_rep = 1
        AND u.status = 'active'
        GROUP BY d.id, d.name
        ORDER BY rep_count DESC
    ");
    $department_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 14. Recent student tickets for analysis
    $stmt = $pdo->query("
        SELECT t.*, d.name as department_name, p.name as program_name
        FROM tickets t
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 15. Class representative performance metrics
    $stmt = $pdo->query("
        SELECT 
            u.full_name,
            COUNT(crr.id) as reports_submitted,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN crr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports
        FROM users u
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id
        WHERE u.is_class_rep = 1
        AND u.status = 'active'
        AND crr.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY u.id, u.full_name
        ORDER BY reports_submitted DESC
        LIMIT 5
    ");
    $rep_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("President dashboard error: " . $e->getMessage());
    // Initialize all variables with defaults
    $total_reps = $total_students = $pending_reports = $upcoming_meetings = 0;
    $ticket_stats = ['total_tickets' => 0, 'resolved_tickets' => 0, 'open_tickets' => 0, 'in_progress_tickets' => 0];
    $board_team = $recent_board_reports = $pending_class_reports = $upcoming_meetings_list = [];
    $recent_activities = $president_budget_requests = $department_reps = $recent_tickets = $rep_performance = [];
    $attendance_stats = ['total_meetings' => 0, 'attended_meetings' => 0, 'absent_meetings' => 0];
}
 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>President of Representative Board Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --purple: #6f42c1;
            --teal: #20c997;
            --indigo: #6610f2;
            --orange: #fd7e14;
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
            grid-template-columns: 260px 1fr;
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

        .stat-card.purple {
            border-left-color: var(--purple);
        }

        .stat-card.teal {
            border-left-color: var(--teal);
        }

        .stat-card.indigo {
            border-left-color: var(--indigo);
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

        .stat-card.purple .stat-icon {
            background: #e2d9f3;
            color: var(--purple);
        }

        .stat-card.teal .stat-icon {
            background: #d1f7e8;
            color: var(--teal);
        }

        .stat-card.indigo .stat-icon {
            background: #e8eaf6;
            color: var(--indigo);
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

        .status-open {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-in_progress {
            background: #cce7ff;
            color: var(--info);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-closed {
            background: #f8f9fa;
            color: var(--dark-gray);
        }

        .status-submitted {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-approved {
            background: #d4edda;
            color: var(--success);
        }

        .status-rejected {
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

        .alert-info {
            background: #cce7ff;
            color: #004085;
            border-left-color: var(--info);
        }

        .alert a {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .alert a:hover {
            text-decoration: underline;
        }

        /* Committee Member Info */
        .member-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            background: var(--light-gray);
            margin-bottom: 0.5rem;
        }

        .member-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.7rem;
        }

        .member-details {
            flex: 1;
        }

        .member-name {
            font-weight: 600;
            font-size: 0.8rem;
        }

        .member-role {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Chart Container */
        .chart-container {
            height: 200px;
            margin-top: 1rem;
            position: relative;
        }

        /* Department Stats */
        .department-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
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
            
            .quick-actions {
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
                    <h1>Isonga - Representative Board President</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($president_name, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($president_name); ?></div>
                        <div class="user-role">President - Representative Board</div>
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
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="active">
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
                    <a href="tickets_analysis.php" >
                        <i class="fas fa-ticket-alt"></i>
                        <span>Tickets Analysis</span>
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
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Welcome, President <?php echo htmlspecialchars($president_name); ?>! </h1>
                   
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
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_reps; ?></div>
                        <div class="stat-label">Class Representatives</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_reports; ?></div>
                        <div class="stat-label">Pending Reports</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_meetings; ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Student Tickets Analysis -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Student Issues Analysis (30 Days)</h3>
                            <div class="card-header-actions">
                                <a href="tickets_analysis.php" class="card-header-btn" title="View Details">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="ticketsChart"></canvas>
                            </div>
                            <div style="display: flex; justify-content: space-around; margin-top: 1rem;">
                                <div style="text-align: center;">
                                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--success);">
                                        <?php echo $ticket_stats['resolved_tickets'] ?? 0; ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">Resolved</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--warning);">
                                        <?php echo $ticket_stats['open_tickets'] ?? 0; ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">Open</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--info);">
                                        <?php echo $ticket_stats['in_progress_tickets'] ?? 0; ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">In Progress</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--primary-blue);">
                                        <?php echo $ticket_stats['total_tickets'] ?? 0; ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">Total</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Class Representative Reports -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Pending Class Representative Reports</h3>
                            <div class="card-header-actions">
                                <a href="class_rep_reports.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_class_reports)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; color: var(--success);"></i>
                                    <p>No pending class representative reports</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Representative</th>
                                            <th>Report Title</th>
                                            <th>Department</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_class_reports as $report): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($report['full_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($report['title']); ?></td>
                                                <td><?php echo htmlspecialchars($report['department_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M j', strtotime($report['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Department-wise Class Representatives -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Class Representatives by Department</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($department_reps)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No department data available</p>
                                </div>
                            <?php else: ?>
                                <div class="department-stats">
                                    <?php foreach ($department_reps as $dept): ?>
                                        <div class="department-stat">
                                            <div class="department-name"><?php echo htmlspecialchars($dept['department_name']); ?></div>
                                            <div class="department-count"><?php echo $dept['rep_count']; ?></div>
                                            <div style="font-size: 0.6rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                representatives
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="class_rep_reports.php?action=review" class="action-btn">
                            <i class="fas fa-file-check"></i>
                            <span class="action-label">Review Reports</span>
                        </a>
                        <a href="meetings.php?action=schedule" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Schedule Meeting</span>
                        </a>
                        <a href="reports.php?action=new" class="action-btn">
                            <i class="fas fa-file-alt"></i>
                            <span class="action-label">Create Report</span>
                        </a>
                        <a href="tickets_analysis.php" class="action-btn">
                            <i class="fas fa-chart-pie"></i>
                            <span class="action-label">Analyze Tickets</span>
                        </a>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Representative Board Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Representative Board Activities</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                        <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                        <p>No recent activities</p>
                                    </li>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'] ?? 'U', 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong>
                                                    <br><small><?php echo str_replace('_', ' ', $activity['role']); ?></small>
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

                    

                    <!-- Upcoming Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Upcoming Meetings</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_meetings_list)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No upcoming meetings</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming_meetings_list as $meeting): ?>
                                    <div class="member-info">
                                        <div class="member-avatar" style="background: var(--info);">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                        <div class="member-details">
                                            <div class="member-name"><?php echo htmlspecialchars($meeting['title']); ?></div>
                                            <div class="member-role">
                                                <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?>
                                                at <?php echo date('g:i A', strtotime($meeting['start_time'])); ?>
                                                <br>
                                                <small>Chair: <?php echo htmlspecialchars($meeting['chairperson_name']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Attendance Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Your Meeting Attendance (30 Days)</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Total Meetings</span>
                                    <strong style="color: var(--text-dark);"><?php echo $attendance_stats['total_meetings'] ?? 0; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Meetings Attended</span>
                                    <strong style="color: var(--success);"><?php echo $attendance_stats['attended_meetings'] ?? 0; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Meetings Missed</span>
                                    <strong style="color: var(--danger);"><?php echo $attendance_stats['absent_meetings'] ?? 0; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="color: var(--dark-gray); font-size: 0.8rem;">Attendance Rate</span>
                                    <strong style="color: var(--primary-blue);">
                                        <?php 
                                        $total = $attendance_stats['total_meetings'] ?? 0;
                                        $attended = $attendance_stats['attended_meetings'] ?? 0;
                                        $rate = $total > 0 ? round(($attended / $total) * 100) : 100;
                                        echo $rate; 
                                        ?>%
                                    </strong>
                                </div>
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

        // Student Tickets Chart
        const ticketsCtx = document.getElementById('ticketsChart').getContext('2d');
        const ticketsChart = new Chart(ticketsCtx, {
            type: 'doughnut',
            data: {
                labels: ['Resolved', 'Open', 'In Progress'],
                datasets: [{
                    data: [
                        <?php echo $ticket_stats['resolved_tickets'] ?? 0; ?>,
                        <?php echo $ticket_stats['open_tickets'] ?? 0; ?>,
                        <?php echo $ticket_stats['in_progress_tickets'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(23, 162, 184, 0.8)'
                    ],
                    borderColor: [
                        'rgb(40, 167, 69)',
                        'rgb(255, 193, 7)',
                        'rgb(23, 162, 184)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                        }
                    }
                },
                cutout: '65%'
            }
        });

        // Auto-refresh dashboard every 5 minutes
        setInterval(() => {
            // You can add auto-refresh logic here
            console.log('Dashboard auto-refresh triggered');
        }, 300000);
    </script>
</body>
</html>