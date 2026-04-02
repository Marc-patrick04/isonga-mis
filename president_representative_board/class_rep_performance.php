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

// Get performance filters
$filter_department = $_GET['department'] ?? '';
$filter_program = $_GET['program'] ?? '';
$filter_academic_year = $_GET['academic_year'] ?? '';
$filter_period = $_GET['period'] ?? '30'; // Default 30 days

// Calculate date range based on filter period
switch ($filter_period) {
    case '7':
        $date_range = 'DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        $period_label = 'Last 7 Days';
        break;
    case '90':
        $date_range = 'DATE_SUB(CURDATE(), INTERVAL 90 DAY)';
        $period_label = 'Last 90 Days';
        break;
    case '180':
        $date_range = 'DATE_SUB(CURDATE(), INTERVAL 180 DAY)';
        $period_label = 'Last 6 Months';
        break;
    case '365':
        $date_range = 'DATE_SUB(CURDATE(), INTERVAL 365 DAY)';
        $period_label = 'Last Year';
        break;
    default:
        $date_range = 'DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
        $period_label = 'Last 30 Days';
        break;
}

try {
    // 1. Overall performance statistics
    $params = [];
    $where_conditions = ["u.is_class_rep = 1", "u.status = 'active'"];
    
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
    
    // Get overall performance stats
    $sql = "
        SELECT 
            COUNT(DISTINCT u.id) as total_reps,
            SUM(CASE WHEN crr.id IS NOT NULL THEN 1 ELSE 0 END) as total_reports,
            AVG(CASE WHEN crr.id IS NOT NULL THEN 1 ELSE 0 END) * 100 as avg_reporting_rate,
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
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Get programs for filter
    $stmt = $pdo->query("SELECT id, name FROM programs WHERE is_active = 1 ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Get academic years
    $stmt = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE academic_year IS NOT NULL ORDER BY academic_year DESC");
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Get individual class rep performance
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
    
    // 6. Department-wise performance
    $sql = "
        SELECT 
            d.name as department_name,
            COUNT(DISTINCT u.id) as rep_count,
            COUNT(crr.id) as total_reports,
            AVG(CASE WHEN crr.id IS NOT NULL THEN 1 ELSE 0 END) * 100 as reporting_rate,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN crr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports
        FROM users u
        JOIN departments d ON u.department_id = d.id
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id 
            AND crr.created_at >= $date_range
        WHERE u.is_class_rep = 1 AND u.status = 'active'
        GROUP BY d.id, d.name
        ORDER BY reporting_rate DESC, total_reports DESC
    ";
    $stmt = $pdo->query($sql);
    $department_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Report submission trend (last 12 months)
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(crr.created_at, '%Y-%m') as month,
            COUNT(crr.id) as report_count,
            COUNT(DISTINCT crr.user_id) as active_reps
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        WHERE u.is_class_rep = 1 
        AND crr.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(crr.created_at, '%Y-%m')
        ORDER BY month
    ");
    $submission_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. Top performing class reps
    $sql = "
        SELECT 
            u.full_name,
            d.name as department_name,
            COUNT(crr.id) as total_reports,
            SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            ROUND(SUM(CASE WHEN crr.status = 'approved' THEN 1 ELSE 0 END) / COUNT(crr.id) * 100, 1) as approval_rate
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN class_rep_reports crr ON u.id = crr.user_id 
            AND crr.created_at >= $date_range
        WHERE u.is_class_rep = 1 AND u.status = 'active'
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
        WHERE u.is_class_rep = 1
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Representative Performance - President Dashboard</title>
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
            border-left: 3px solid var(--primary-blue);
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
            color: var(--warning);
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
            padding: 0.5rem 0.75rem;
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
            padding: 0.5rem 1rem;
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
            
            .filters-form {
                grid-template-columns: 1fr;
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
                    <h1>Class Representative Performance</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="dashboard.php" class="icon-btn" title="Back to Dashboard">
                        <i class="fas fa-tachometer-alt"></i>
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
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php" >
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
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
            

            <!-- Filters -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3>Filter Performance Data</h3>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='class_rep_performance.php'">
                        <i class="fas fa-redo"></i> Reset Filters
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
                            <div class="stat-number"><?php echo $overall_stats['total_reps'] ?? 0; ?></div>
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
                            <div class="stat-number"><?php echo $overall_stats['total_reports'] ?? 0; ?></div>
                            <div class="stat-label">Total Reports Submitted</div>
                            <div class="stat-sub"><?php echo $period_label; ?></div>
                        </div>
                    </div>
                </div>
                
               
                
                <div class="stat-card warning">
                    <div class="stat-content">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-main">
                            <div class="stat-number"><?php echo $overall_stats['pending_reports'] ?? 0; ?></div>
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
                            <?php if (empty($class_reps_performance)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No performance data available</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
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
                                </div>
                            <?php endif; ?>
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
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
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

                    

                    <!-- Recent Submissions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Report Submissions</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_submissions)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
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
                                                <span class="performance-rating" style="background: var(--<?php echo $submission['status_class']; ?>); color: white; margin-left: 0.5rem;">
                                                    <?php echo ucfirst($submission['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
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

        // Auto-refresh every 10 minutes
        setInterval(() => {
            console.log('Performance dashboard auto-refresh triggered');
        }, 600000);
    </script>
</body>
</html>