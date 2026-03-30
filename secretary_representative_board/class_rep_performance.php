<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_representative_board') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$secretary_name = $_SESSION['full_name'];

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

// Get performance filters
$filter_department = $_GET['department'] ?? '';
$filter_program = $_GET['program'] ?? '';
$filter_academic_year = $_GET['academic_year'] ?? '';
$filter_period = $_GET['period'] ?? '30'; // Default 30 days

// Calculate date range based on filter period (PostgreSQL compatible)
switch ($filter_period) {
    case '7':
        $date_range = "CURRENT_DATE - INTERVAL '7 days'";
        $period_label = 'Last 7 Days';
        break;
    case '90':
        $date_range = "CURRENT_DATE - INTERVAL '90 days'";
        $period_label = 'Last 90 Days';
        break;
    case '180':
        $date_range = "CURRENT_DATE - INTERVAL '180 days'";
        $period_label = 'Last 6 Months';
        break;
    case '365':
        $date_range = "CURRENT_DATE - INTERVAL '365 days'";
        $period_label = 'Last Year';
        break;
    default:
        $date_range = "CURRENT_DATE - INTERVAL '30 days'";
        $period_label = 'Last 30 Days';
        break;
}

try {
    // 1. Overall performance statistics
    $params = [];
    $where_conditions = ["u.is_class_rep = true", "u.status = 'active'"];
    
    if ($filter_department) {
        $where_conditions[] = "u.department_id = ?";
        $params[] = $filter_department;
    }
    
    if ($filter_program) {
        $where_conditions[] = "u.program_id = ?";
        $params[] = $filter_program;
    }
    
    if ($filter_academic_year) {
        $where_conditions[] = "u.academic_year = ?";
        $params[] = $filter_academic_year;
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    // Get overall performance stats (PostgreSQL compatible)
    $sql = "
        SELECT 
            COUNT(DISTINCT u.id) as total_reps,
            COUNT(crr.id) as total_reports,
            CASE 
                WHEN COUNT(DISTINCT u.id) > 0 
                THEN (COUNT(crr.id)::float / COUNT(DISTINCT u.id)) * 100 
                ELSE 0 
            END as avg_reporting_rate,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN crr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports,
            SUM(CASE WHEN crr.status = 'submitted' THEN 1 ELSE 0 END) as pending_reports
        FROM users u
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id 
            AND crr.created_at >= $date_range
        WHERE $where_sql
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $overall_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Get departments for filter
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Get programs for filter
    $stmt = $pdo->query("SELECT id, name FROM programs WHERE is_active = true ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Get academic years
    $stmt = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE academic_year IS NOT NULL ORDER BY academic_year DESC");
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Get individual class rep performance (PostgreSQL compatible)
    $sql = "
        SELECT 
            u.id,
            u.full_name,
            u.reg_number,
            u.email,
            u.phone,
            u.academic_year,
            d.name as department_name,
            p.name as program_name,
            COUNT(crr.id) as total_reports,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN crr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports,
            SUM(CASE WHEN crr.status = 'submitted' THEN 1 ELSE 0 END) as pending_reports,
            COUNT(DISTINCT DATE(crr.created_at)) as active_days,
            MAX(crr.created_at) as last_report_date
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id 
            AND crr.created_at >= $date_range
        WHERE $where_sql
        GROUP BY u.id, u.full_name, u.reg_number, u.email, u.phone, u.academic_year, d.name, p.name
        ORDER BY total_reports DESC, approved_reports DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $class_reps_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Department-wise performance (PostgreSQL compatible)
    $sql = "
        SELECT 
            d.name as department_name,
            COUNT(DISTINCT u.id) as rep_count,
            COUNT(crr.id) as total_reports,
            CASE 
                WHEN COUNT(DISTINCT u.id) > 0 
                THEN (COUNT(crr.id)::float / COUNT(DISTINCT u.id)) * 100 
                ELSE 0 
            END as reporting_rate,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN crr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports
        FROM users u
        JOIN departments d ON u.department_id = d.id
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id 
            AND crr.created_at >= $date_range
        WHERE u.is_class_rep = true AND u.status = 'active'
        GROUP BY d.id, d.name
        ORDER BY reporting_rate DESC, total_reports DESC
    ";
    $stmt = $pdo->query($sql);
    $department_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Report submission trend (last 12 months) - PostgreSQL uses TO_CHAR
    $stmt = $pdo->query("
        SELECT 
            TO_CHAR(crr.created_at, 'YYYY-MM') as month,
            COUNT(crr.id) as report_count,
            COUNT(DISTINCT crr.user_id) as active_reps
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        WHERE u.is_class_rep = true 
        AND crr.created_at >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY TO_CHAR(crr.created_at, 'YYYY-MM')
        ORDER BY month
    ");
    $submission_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. Top performing class reps (PostgreSQL compatible)
    $sql = "
        SELECT 
            u.full_name,
            d.name as department_name,
            COUNT(crr.id) as total_reports,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            CASE 
                WHEN COUNT(crr.id) > 0 
                THEN ROUND((SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END)::float / COUNT(crr.id)) * 100, 1)
                ELSE 0 
            END as approval_rate
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id 
            AND crr.created_at >= $date_range
        WHERE u.is_class_rep = true AND u.status = 'active'
        GROUP BY u.id, u.full_name, d.name
        HAVING COUNT(crr.id) > 0
        ORDER BY total_reports DESC, approval_rate DESC
        LIMIT 10
    ";
    $stmt = $pdo->query($sql);
    $top_performers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 9. Recent report submissions
    $sql = "
        SELECT 
            crr.*,
            u.full_name,
            u.department_id,
            d.name as department_name,
            CASE 
                WHEN crr.status = 'approved' THEN 'success'
                WHEN crr.status = 'rejected' THEN 'danger'
                WHEN crr.status = 'submitted' THEN 'warning'
                ELSE 'info'
            END as status_class
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.is_class_rep = true
        ORDER BY crr.created_at DESC
        LIMIT 10
    ";
    $stmt = $pdo->query($sql);
    $recent_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Class rep performance error: " . $e->getMessage());
    $overall_stats = ['total_reps' => 0, 'total_reports' => 0, 'avg_reporting_rate' => 0];
    $departments = $programs = $academic_years = [];
    $class_reps_performance = $department_performance = $submission_trend = [];
    $top_performers = $recent_submissions = [];
}

// Get sidebar statistics (PostgreSQL compatible)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $total_reps = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM class_rep_reports WHERE status = 'submitted'");
    $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    
    // Pending minutes
    $pending_minutes = 0;
    try {
        $stmt = $pdo->query("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_name = 'rep_meetings'
            ) as rep_meetings_exists
        ");
        $rep_meetings_exists = $stmt->fetch(PDO::FETCH_ASSOC)['rep_meetings_exists'] ?? false;
        
        if ($rep_meetings_exists) {
            $stmt = $pdo->query("SELECT COUNT(*) as pending_minutes FROM rep_meetings WHERE (minutes IS NULL OR minutes = '') AND status = 'completed'");
            $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'] ?? 0;
        }
    } catch (Exception $e) {
        error_log("Pending minutes query error: " . $e->getMessage());
    }
    
    // Upcoming meetings
    $stmt = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = 'rep_meetings'
        ) as rep_meetings_exists
    ");
    $rep_meetings_exists = $stmt->fetch(PDO::FETCH_ASSOC)['rep_meetings_exists'] ?? false;
    
    $upcoming_meetings = 0;
    if ($rep_meetings_exists) {
        $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
        $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    }
    
    // Unread messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    
} catch (PDOException $e) {
    $total_reps = $pending_reports = $pending_minutes = $upcoming_meetings = $unread_messages = 0;
    error_log("Sidebar stats error: " . $e->getMessage());
}

// Add CSS styles for member-info and member-details
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Class Representative Performance - Secretary Dashboard</title>
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
            --purple: #9c27b0;
            --teal: #009688;
            --indigo: #3f51b5;
            --orange: #ff9800;
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

        /* Performance Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
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

        .stat-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
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

        .stat-main {
            flex: 1;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stat-sub {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
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

        /* Member Info Styles */
        .member-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            background: var(--light-gray);
            margin-bottom: 0.75rem;
        }

        .member-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .member-details {
            flex: 1;
        }

        .member-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .member-role {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Performance Rating */
        .performance-rating {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .rating-excellent {
            background: #d4edda;
            color: var(--success);
        }

        .rating-good {
            background: #cce7ff;
            color: var(--info);
        }

        .rating-average {
            background: #fff3cd;
            color: #856404;
        }

        .rating-poor {
            background: #f8d7da;
            color: var(--danger);
        }

        /* Filters */
        .filters-container {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-control {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
            transform: translateY(-1px);
        }

        /* Chart Container */
        .chart-container {
            height: 300px;
            margin-top: 1rem;
            position: relative;
        }

        /* Performance Metrics */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .metric-item {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.25rem;
        }

        .metric-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Empty State */
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
                    <h1>Class Representative Performance</h1>
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
                    <a href="dashboard.php" class="icon-btn" title="Back to Dashboard">
                        <i class="fas fa-tachometer-alt"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($secretary_name, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($secretary_name); ?></div>
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
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_performance.php" class="active">
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
                    <h1>Class Representative Performance Tracking</h1>
                    <p>Monitor and analyze performance metrics of class representatives for <?php echo $period_label; ?></p>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3>Filter Performance Data</h3>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='class_rep_performance.php'">
                        <i class="fas fa-sync-alt"></i> Reset Filters
                    </button>
                </div>
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department" class="form-control">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $filter_department == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="program">Program</label>
                        <select id="program" name="program" class="form-control">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>" <?php echo $filter_program == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year" class="form-control">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year['academic_year']); ?>" <?php echo $filter_academic_year == $year['academic_year'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['academic_year']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="period">Time Period</label>
                        <select id="period" name="period" class="form-control">
                            <option value="7" <?php echo $filter_period == '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30" <?php echo $filter_period == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="90" <?php echo $filter_period == '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                            <option value="180" <?php echo $filter_period == '180' ? 'selected' : ''; ?>>Last 6 Months</option>
                            <option value="365" <?php echo $filter_period == '365' ? 'selected' : ''; ?>>Last Year</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Performance Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-main">
                            <div class="stat-number"><?php echo number_format($overall_stats['total_reps'] ?? 0); ?></div>
                            <div class="stat-label">Active Class Representatives</div>
                            <div class="stat-sub">Across all departments</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-main">
                            <div class="stat-number"><?php echo number_format($overall_stats['total_reports'] ?? 0); ?></div>
                            <div class="stat-label">Total Reports Submitted</div>
                            <div class="stat-sub"><?php echo $period_label; ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-main">
                            <div class="stat-number"><?php echo round($overall_stats['avg_reporting_rate'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Average Reporting Rate</div>
                            <div class="stat-sub">Active representatives</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-main">
                            <div class="stat-number"><?php echo number_format($overall_stats['pending_reports'] ?? 0); ?></div>
                            <div class="stat-label">Pending Reports</div>
                            <div class="stat-sub">Awaiting review</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Performance Chart -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Report Submission Trend (12 Months)</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="submissionChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Individual Performance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Class Representative Performance</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Export">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-container">
                                <?php if (empty($class_reps_performance)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No performance data available</p>
                                    </div>
                                <?php else: ?>
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Representative</th>
                                                <th>Department</th>
                                                <th>Total Reports</th>
                                                <th>Approved</th>
                                                <th>Pending</th>
                                                <th>Rating</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($class_reps_performance as $rep): 
                                                $total = $rep['total_reports'] ?? 0;
                                                $approved = $rep['approved_reports'] ?? 0;
                                                $pending = $rep['pending_reports'] ?? 0;
                                                $rating = $total > 0 ? ($approved / $total) * 100 : 0;
                                                
                                                $rating_class = 'rating-average';
                                                $rating_text = 'Average';
                                                
                                                if ($rating >= 80) {
                                                    $rating_class = 'rating-excellent';
                                                    $rating_text = 'Excellent';
                                                } elseif ($rating >= 60) {
                                                    $rating_class = 'rating-good';
                                                    $rating_text = 'Good';
                                                } elseif ($rating >= 40) {
                                                    $rating_class = 'rating-average';
                                                    $rating_text = 'Average';
                                                } else {
                                                    $rating_class = 'rating-poor';
                                                    $rating_text = 'Poor';
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                        <br><small><?php echo htmlspecialchars($rep['reg_number'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <strong><?php echo $total; ?></strong>
                                                        <br><small>Active days: <?php echo $rep['active_days'] ?? 0; ?></small>
                                                    </td>
                                                    <td>
                                                        <span style="color: var(--success); font-weight: 600;"><?php echo $approved; ?></span>
                                                        <br><small>Rejected: <?php echo $rep['rejected_reports'] ?? 0; ?></small>
                                                    </td>
                                                    <td>
                                                        <span style="color: var(--warning); font-weight: 600;"><?php echo $pending; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="performance-rating <?php echo $rating_class; ?>">
                                                            <?php echo $rating_text; ?>
                                                        </span>
                                                        <br><small><?php echo round($rating, 1); ?>%</small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Top Performers -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Top Performers</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($top_performers)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-trophy"></i>
                                    <p>No top performers data</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($top_performers as $index => $performer): 
                                    $rank = $index + 1;
                                    $approval_rate = $performer['approval_rate'] ?? 0;
                                ?>
                                    <div class="member-info">
                                        <div class="member-avatar" style="background: var(--primary-blue);">
                                            <?php echo $rank; ?>
                                        </div>
                                        <div class="member-details">
                                            <div class="member-name"><?php echo htmlspecialchars($performer['full_name']); ?></div>
                                            <div class="member-role"><?php echo htmlspecialchars($performer['department_name'] ?? 'N/A'); ?></div>
                                            <div style="font-size: 0.7rem; color: var(--success); margin-top: 0.25rem;">
                                                <?php echo $performer['total_reports']; ?> reports • <?php echo $approval_rate; ?>% approved
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Department Performance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Department Performance</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($department_performance)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-building"></i>
                                    <p>No department data</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($department_performance as $dept): 
                                    $reporting_rate = $dept['reporting_rate'] ?? 0;
                                    $total_reports = $dept['total_reports'] ?? 0;
                                    $rep_count = $dept['rep_count'] ?? 0;
                                    
                                    // Calculate average reports per rep
                                    $avg_reports = $rep_count > 0 ? round($total_reports / $rep_count, 1) : 0;
                                ?>
                                    <div class="member-info">
                                        <div class="member-avatar" style="background: var(--info);">
                                            <?php echo $rep_count; ?>
                                        </div>
                                        <div class="member-details">
                                            <div class="member-name"><?php echo htmlspecialchars($dept['department_name']); ?></div>
                                            <div class="member-role"><?php echo $rep_count; ?> representatives</div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo $total_reports; ?> reports • <?php echo round($reporting_rate, 1); ?>% active
                                                <br>Avg: <?php echo $avg_reports; ?> reports/rep
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Submissions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Report Submissions</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_submissions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <p>No recent submissions</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_submissions as $submission): ?>
                                    <div class="member-info">
                                        <div class="member-avatar">
                                            <?php echo strtoupper(substr($submission['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="member-details">
                                            <div class="member-name"><?php echo htmlspecialchars($submission['full_name']); ?></div>
                                            <div class="member-role"><?php echo htmlspecialchars($submission['title']); ?></div>
                                            <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                                <span style="color: var(--dark-gray);"><?php echo date('M j, g:i A', strtotime($submission['created_at'])); ?></span>
                                                <span class="performance-rating" style="background: var(--<?php echo $submission['status_class']; ?>); color: white; margin-left: 0.5rem; padding: 0.2rem 0.4rem;">
                                                    <?php echo ucfirst($submission['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Performance Metrics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Performance Metrics</h3>
                        </div>
                        <div class="card-body">
                            <div class="metrics-grid">
                                <div class="metric-item">
                                    <div class="metric-value"><?php echo number_format($overall_stats['approved_reports'] ?? 0); ?></div>
                                    <div class="metric-label">Approved Reports</div>
                                </div>
                                <div class="metric-item">
                                    <div class="metric-value"><?php echo number_format($overall_stats['rejected_reports'] ?? 0); ?></div>
                                    <div class="metric-label">Rejected Reports</div>
                                </div>
                                <div class="metric-item">
                                    <?php
                                    $avg_reports_per_rep = $overall_stats['total_reps'] > 0 ? 
                                        round($overall_stats['total_reports'] / $overall_stats['total_reps'], 1) : 0;
                                    ?>
                                    <div class="metric-value"><?php echo $avg_reports_per_rep; ?></div>
                                    <div class="metric-label">Avg Reports/Rep</div>
                                </div>
                                <div class="metric-item">
                                    <?php
                                    $approval_rate = $overall_stats['total_reports'] > 0 ? 
                                        round(($overall_stats['approved_reports'] / $overall_stats['total_reports']) * 100, 1) : 0;
                                    ?>
                                    <div class="metric-value"><?php echo $approval_rate; ?>%</div>
                                    <div class="metric-label">Approval Rate</div>
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

        // Submission Trend Chart
        const submissionCtx = document.getElementById('submissionChart').getContext('2d');
        
        // Prepare chart data
        const months = <?php echo json_encode(array_column($submission_trend, 'month')); ?>;
        const reportCounts = <?php echo json_encode(array_column($submission_trend, 'report_count')); ?>;
        const activeReps = <?php echo json_encode(array_column($submission_trend, 'active_reps')); ?>;
        
        const submissionChart = new Chart(submissionCtx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Reports Submitted',
                        data: reportCounts,
                        borderColor: 'rgb(0, 123, 255)',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Active Representatives',
                        data: activeReps,
                        borderColor: 'rgb(40, 167, 69)',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            boxWidth: 10
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [2, 2]
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'nearest'
                }
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
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