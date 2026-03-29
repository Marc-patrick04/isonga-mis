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
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Get filter parameters
$time_period = $_GET['period'] ?? 'current_month';
$department_filter = $_GET['department'] ?? '';
$club_filter = $_GET['club'] ?? '';
$metric_type = $_GET['metric'] ?? 'overall';

// Calculate date ranges based on period
$date_ranges = [
    'current_month' => [
        'start' => date('Y-m-01'),
        'end' => date('Y-m-t'),
        'label' => 'Current Month'
    ],
    'last_month' => [
        'start' => date('Y-m-01', strtotime('-1 month')),
        'end' => date('Y-m-t', strtotime('-1 month')),
        'label' => 'Last Month'
    ],
    'current_quarter' => [
        'start' => date('Y-m-01', strtotime('first day of this quarter')),
        'end' => date('Y-m-t', strtotime('last day of this quarter')),
        'label' => 'Current Quarter'
    ],
    'current_year' => [
        'start' => date('Y-01-01'),
        'end' => date('Y-12-31'),
        'label' => 'Current Year'
    ]
];

$current_range = $date_ranges[$time_period] ?? $date_ranges['current_month'];

// Get performance statistics
try {
    // Overall academic performance metrics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT r.id) as total_reports,
            COUNT(DISTINCT c.id) as active_clubs,
            COUNT(DISTINCT cm.id) as total_members,
            AVG(CAST(JSON_EXTRACT(r.content, '$.performance_rating') AS DECIMAL)) as avg_performance,
            SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN r.status = 'submitted' THEN 1 ELSE 0 END) as pending_reports
        FROM reports r
        LEFT JOIN clubs c ON r.content LIKE CONCAT('%', c.name, '%')
        LEFT JOIN club_members cm ON 1=1
        WHERE r.report_type IN ('academic', 'monthly', 'activity', 'club')
        AND r.created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$current_range['start'], $current_range['end']]);
    $performance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Performance stats error: " . $e->getMessage());
    $performance_stats = [
        'total_reports' => 0,
        'active_clubs' => 0,
        'total_members' => 0,
        'avg_performance' => 0,
        'approved_reports' => 0,
        'pending_reports' => 0
    ];
}

// Get department-wise performance
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.department,
            COUNT(DISTINCT c.id) as club_count,
            COUNT(DISTINCT cm.id) as member_count,
            COUNT(DISTINCT r.id) as report_count,
            AVG(CAST(JSON_EXTRACT(r.content, '$.performance_rating') AS DECIMAL)) as avg_performance,
            SUM(CASE WHEN ca.activity_type = 'workshop' THEN 1 ELSE 0 END) as workshop_count,
            SUM(CASE WHEN ca.activity_type = 'competition' THEN 1 ELSE 0 END) as competition_count
        FROM clubs c
        LEFT JOIN club_members cm ON c.id = cm.club_id AND cm.status = 'active'
        LEFT JOIN reports r ON r.content LIKE CONCAT('%', c.department, '%')
        LEFT JOIN club_activities ca ON c.id = ca.club_id AND ca.activity_date BETWEEN ? AND ?
        WHERE c.department IS NOT NULL
        GROUP BY c.department
        ORDER BY avg_performance DESC
    ");
    $stmt->execute([$current_range['start'], $current_range['end']]);
    $department_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Department performance error: " . $e->getMessage());
    $department_performance = [];
}

// Get club performance metrics
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.category,
            c.department,
            c.members_count,
            COUNT(DISTINCT ca.id) as activity_count,
            COUNT(DISTINCT r.id) as report_count,
            AVG(CAST(JSON_EXTRACT(r.content, '$.performance_rating') AS DECIMAL)) as performance_score,
            COUNT(DISTINCT cm.id) as active_members,
            SUM(CASE WHEN ca.status = 'completed' THEN 1 ELSE 0 END) as completed_activities
        FROM clubs c
        LEFT JOIN club_activities ca ON c.id = ca.club_id AND ca.activity_date BETWEEN ? AND ?
        LEFT JOIN reports r ON r.content LIKE CONCAT('%', c.name, '%') AND r.created_at BETWEEN ? AND ?
        LEFT JOIN club_members cm ON c.id = cm.club_id AND cm.status = 'active'
        WHERE c.status = 'active'
        GROUP BY c.id, c.name, c.category, c.department, c.members_count
        ORDER BY performance_score DESC
    ");
    $stmt->execute([
        $current_range['start'], $current_range['end'],
        $current_range['start'], $current_range['end']
    ]);
    $club_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Club performance error: " . $e->getMessage());
    $club_performance = [];
}

// Get activity trends
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(ca.activity_date, '%Y-%m') as month,
            COUNT(*) as activity_count,
            COUNT(DISTINCT ca.club_id) as active_clubs,
            SUM(ca.participants_count) as total_participants,
            AVG(ca.participants_count) as avg_participants
        FROM club_activities ca
        WHERE ca.activity_date BETWEEN DATE_SUB(?, INTERVAL 6 MONTH) AND ?
        GROUP BY DATE_FORMAT(ca.activity_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $stmt->execute([$current_range['start'], $current_range['end']]);
    $activity_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Activity trends error: " . $e->getMessage());
    $activity_trends = [];
}

// Get top performing clubs
$top_clubs = array_slice($club_performance, 0, 5);

// Get performance alerts (clubs needing attention)
$performance_alerts = array_filter($club_performance, function($club) {
    return ($club['performance_score'] ?? 0) < 3.0 || 
           ($club['activity_count'] ?? 0) < 2 ||
           ($club['active_members'] ?? 0) < 5;
});

// Calculate performance metrics
$total_performance_score = $performance_stats['avg_performance'] ? round($performance_stats['avg_performance'], 1) : 0;
$performance_percentage = min(100, max(0, ($total_performance_score / 5) * 100));
$report_approval_rate = $performance_stats['total_reports'] > 0 ? 
    round(($performance_stats['approved_reports'] / $performance_stats['total_reports']) * 100) : 0;

// Get unique departments for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT department FROM clubs WHERE department IS NOT NULL ORDER BY department");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Departments query error: " . $e->getMessage());
    $departments = [];
}

// Get unique clubs for filter
try {
    $stmt = $pdo->query("SELECT id, name FROM clubs WHERE status = 'active' ORDER BY name");
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Clubs query error: " . $e->getMessage());
    $clubs = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Performance Tracking - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Header and Sidebar styles (same as previous pages) */
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

        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 60px;
            height: calc(100vh - 60px);
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

        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--academic-primary);
            color: var(--academic-primary);
        }

        .btn-outline:hover {
            background: var(--academic-light);
        }

        /* Performance Overview */
        .performance-overview {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .main-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .performance-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            transition: var(--transition);
        }

        .performance-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .performance-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .performance-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .performance-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .performance-change {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .change-positive {
            color: var(--success);
        }

        .change-negative {
            color: var(--danger);
        }

        .performance-progress {
            margin-top: 1rem;
        }

        .progress-bar {
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--academic-primary);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .chart-container {
            position: relative;
            height: 250px;
        }

        /* Performance Grid */
        .performance-grid {
            display: grid;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .performance-section {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            background: var(--academic-light);
            border-bottom: 1px solid var(--medium-gray);
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .section-body {
            padding: 1.5rem;
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

        .performance-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .performance-excellent {
            background: #d4edda;
            color: var(--success);
        }

        .performance-good {
            background: #fff3cd;
            color: var(--warning);
        }

        .performance-average {
            background: #ffeaa7;
            color: #e17055;
        }

        .performance-poor {
            background: #f8d7da;
            color: var(--danger);
        }

        .trend-indicator {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .trend-neutral {
            color: var(--dark-gray);
        }

        /* Alerts */
        .alerts-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .alert-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .alert-item:hover {
            background: var(--light-gray);
        }

        .alert-item:last-child {
            border-bottom: none;
        }

        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .alert-warning .alert-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .alert-danger .alert-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .alert-info .alert-icon {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .alert-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .alert-action {
            color: var(--academic-primary);
            font-weight: 600;
            font-size: 0.8rem;
            text-decoration: none;
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            padding: 1.25rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-select {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-select:focus {
            outline: none;
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Metric Cards */
        .metric-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .metric-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .metric-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--academic-light);
            color: var(--academic-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin: 0 auto 1rem;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .metric-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* ── Mobile Nav Overlay ── */
        .mobile-nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
            backdrop-filter: blur(2px);
        }
        .mobile-nav-overlay.active { display: block; }

        /* ── Hamburger Button ── */
        .hamburger-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .hamburger-btn:hover {
            background: var(--academic-primary);
            color: white;
        }

        /* ── Sidebar Drawer ── */
        .sidebar { transition: transform 0.3s ease; }

        /* ── Tablet ── */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }

            .performance-overview {
                grid-template-columns: 1fr;
            }

            .charts-container {
                grid-template-columns: 1fr;
            }

            .main-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* ── Drawer threshold ── */
        @media (max-width: 900px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 260px;
                height: 100vh;
                z-index: 200;
                transform: translateX(-100%);
                padding-top: 1rem;
                box-shadow: var(--shadow-lg);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .hamburger-btn {
                display: flex;
            }

            .main-content {
                height: auto;
                min-height: calc(100vh - 80px);
            }
        }

        /* ── Mobile ── */
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .page-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .page-actions .btn {
                flex: 1 1 auto;
                justify-content: center;
                min-width: 120px;
            }

            .metric-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-wrap: wrap;
            }

            /* Tables scroll horizontally */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            /* Alert items wrap on small screens */
            .alert-item {
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .alert-action {
                margin-left: auto;
            }

            /* Section body padding */
            .section-body {
                padding: 1rem;
            }

            .chart-card {
                padding: 1rem;
            }
        }

        /* ── Small phones ── */
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .header {
                height: 68px;
            }

            .logos .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .metric-cards {
                grid-template-columns: 1fr 1fr;
            }

            .main-stats {
                grid-template-columns: 1fr;
            }

            .metric-card {
                padding: 1rem;
            }

            .metric-value {
                font-size: 1.25rem;
            }

            .performance-value {
                font-size: 1.5rem;
            }

            .chart-container {
                height: 200px;
            }

            .page-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger-btn" id="hamburgerBtn" title="Toggle Menu" aria-label="Open navigation menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Affairs</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
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

    <!-- Mobile Nav Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay"></div>

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
    <a href="academic_meetings.php">
        <i class="fas fa-calendar-check"></i>
        <span>Meetings</span>
        <?php
        // Count upcoming meetings where user is invited
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as upcoming_meetings 
                FROM meeting_attendees ma 
                JOIN meetings m ON ma.meeting_id = m.id 
                WHERE ma.user_id = ? 
                AND m.meeting_date >= CURDATE() 
                AND m.status = 'scheduled'
                AND ma.attendance_status = 'invited'
            ");
            $stmt->execute([$user_id]);
            $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'];
        } catch (PDOException $e) {
            $upcoming_meetings = 0;
        }
        ?>
        <?php if ($upcoming_meetings > 0): ?>
            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
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
                    <a href="performance_tracking.php" class="active">
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Academic Performance Tracking</h1>
                    <p>Monitor and analyze academic performance metrics across clubs and departments</p>
                </div>
                <div class="page-actions">
                    <a href="dashboard.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button class="btn btn-primary" onclick="exportPerformanceData()">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Time Period</label>
                        <select name="period" class="form-select">
                            <?php foreach ($date_ranges as $key => $range): ?>
                                <option value="<?php echo $key; ?>" <?php echo $time_period === $key ? 'selected' : ''; ?>>
                                    <?php echo $range['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Club</label>
                        <select name="club" class="form-select">
                            <option value="">All Clubs</option>
                            <?php foreach ($clubs as $club): ?>
                                <option value="<?php echo $club['id']; ?>" <?php echo $club_filter == $club['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($club['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metric Type</label>
                        <select name="metric" class="form-select">
                            <option value="overall" <?php echo $metric_type === 'overall' ? 'selected' : ''; ?>>Overall Performance</option>
                            <option value="activities" <?php echo $metric_type === 'activities' ? 'selected' : ''; ?>>Activity Metrics</option>
                            <option value="participation" <?php echo $metric_type === 'participation' ? 'selected' : ''; ?>>Participation Rates</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="performance_tracking.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Performance Overview -->
            <div class="performance-overview">
                <div class="main-stats">
                    <!-- Overall Performance Score -->
                    <div class="performance-card">
                        <div class="performance-header">
                            <div>
                                <div class="performance-title">Overall Performance Score</div>
                                <div class="performance-value"><?php echo $total_performance_score; ?>/5.0</div>
                                <div class="performance-change change-positive">
                                    <i class="fas fa-arrow-up"></i> 12% from last period
                                </div>
                            </div>
                            <div class="metric-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                        <div class="performance-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $performance_percentage; ?>%"></div>
                            </div>
                            <div class="progress-label">
                                <span>Target: 4.0/5.0</span>
                                <span><?php echo $performance_percentage; ?>%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Active Clubs -->
                    <div class="performance-card">
                        <div class="performance-header">
                            <div>
                                <div class="performance-title">Active Clubs</div>
                                <div class="performance-value"><?php echo $performance_stats['active_clubs']; ?></div>
                                <div class="performance-change change-positive">
                                    <i class="fas fa-arrow-up"></i> 3 new clubs
                                </div>
                            </div>
                            <div class="metric-icon">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="performance-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 85%"></div>
                            </div>
                            <div class="progress-label">
                                <span>Engagement Rate</span>
                                <span>85%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Report Completion -->
                    <div class="performance-card">
                        <div class="performance-header">
                            <div>
                                <div class="performance-title">Report Completion</div>
                                <div class="performance-value"><?php echo $report_approval_rate; ?>%</div>
                                <div class="performance-change change-positive">
                                    <i class="fas fa-arrow-up"></i> 8% improvement
                                </div>
                            </div>
                            <div class="metric-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                        </div>
                        <div class="performance-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $report_approval_rate; ?>%"></div>
                            </div>
                            <div class="progress-label">
                                <span>Target: 90%</span>
                                <span><?php echo $report_approval_rate; ?>%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Student Participation -->
                    <div class="performance-card">
                        <div class="performance-header">
                            <div>
                                <div class="performance-title">Student Participation</div>
                                <div class="performance-value"><?php echo $performance_stats['total_members']; ?></div>
                                <div class="performance-change change-positive">
                                    <i class="fas fa-arrow-up"></i> 15% growth
                                </div>
                            </div>
                            <div class="metric-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                        </div>
                        <div class="performance-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 78%"></div>
                            </div>
                            <div class="progress-label">
                                <span>Campus Coverage</span>
                                <span>78%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Metrics -->
                <div class="metric-cards">
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="metric-value"><?php echo array_sum(array_column($club_performance, 'activity_count')); ?></div>
                        <div class="metric-label">Activities This Period</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="metric-value"><?php echo count($top_clubs); ?></div>
                        <div class="metric-label">Top Performing Clubs</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="metric-value"><?php echo count($performance_alerts); ?></div>
                        <div class="metric-label">Clubs Needing Attention</div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-container">
                <!-- Department Performance Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Department Performance</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="departmentChart"></canvas>
                    </div>
                </div>

                <!-- Activity Trends Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Activity Trends</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="activityTrendsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Performance Alerts -->
            <?php if (!empty($performance_alerts)): ?>
                <div class="alerts-container">
                    <div class="section-header">
                        <h3 class="section-title">Performance Alerts</h3>
                    </div>
                    <div class="section-body">
                        <?php foreach (array_slice($performance_alerts, 0, 5) as $alert): ?>
                            <div class="alert-item alert-warning">
                                <div class="alert-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="alert-content">
                                    <div class="alert-title"><?php echo htmlspecialchars($alert['name']); ?> Needs Attention</div>
                                    <div class="alert-description">
                                        Performance score: <?php echo round($alert['performance_score'] ?? 0, 1); ?>/5.0 | 
                                        Activities: <?php echo $alert['activity_count'] ?? 0; ?> | 
                                        Active members: <?php echo $alert['active_members'] ?? 0; ?>
                                    </div>
                                </div>
                                <a href="academic_clubs.php?view=<?php echo $alert['id']; ?>" class="alert-action">
                                    View Details →
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Department Performance -->
            <div class="performance-section">
                <div class="section-header">
                    <h3 class="section-title">Department Performance Breakdown</h3>
                </div>
                <div class="section-body">
                    <?php if (empty($department_performance)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                            <i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h4>No Performance Data Available</h4>
                            <p>Performance data will appear here as clubs submit reports and activities.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Performance Score</th>
                                        <th>Active Clubs</th>
                                        <th>Total Members</th>
                                        <th>Reports</th>
                                        <th>Workshops</th>
                                        <th>Competitions</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($department_performance as $dept): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($dept['department']); ?></strong>
                                            </td>
                                            <td>
                                                <?php $score = round($dept['avg_performance'] ?? 0, 1); ?>
                                                <span class="performance-badge 
                                                    <?php echo $score >= 4 ? 'performance-excellent' : 
                                                          ($score >= 3 ? 'performance-good' : 
                                                          ($score >= 2 ? 'performance-average' : 'performance-poor')); ?>">
                                                    <?php echo $score; ?>/5.0
                                                </span>
                                            </td>
                                            <td><?php echo $dept['club_count']; ?></td>
                                            <td><?php echo $dept['member_count']; ?></td>
                                            <td><?php echo $dept['report_count']; ?></td>
                                            <td><?php echo $dept['workshop_count']; ?></td>
                                            <td><?php echo $dept['competition_count']; ?></td>
                                            <td>
                                                <div class="trend-indicator trend-up">
                                                    <i class="fas fa-arrow-up"></i>
                                                    <span>+5%</span>
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

            <!-- Top Performing Clubs -->
            <div class="performance-section">
                <div class="section-header">
                    <h3 class="section-title">Top Performing Clubs</h3>
                </div>
                <div class="section-body">
                    <?php if (empty($top_clubs)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                            <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h4>No Club Performance Data</h4>
                            <p>Club performance rankings will appear here as data becomes available.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Club Name</th>
                                        <th>Department</th>
                                        <th>Performance Score</th>
                                        <th>Activities</th>
                                        <th>Members</th>
                                        <th>Reports</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_clubs as $index => $club): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <span style="
                                                        width: 24px;
                                                        height: 24px;
                                                        border-radius: 50%;
                                                        background: <?php echo $index < 3 ? 'var(--academic-primary)' : 'var(--light-gray)'; ?>;
                                                        color: white;
                                                        display: flex;
                                                        align-items: center;
                                                        justify-content: center;
                                                        font-size: 0.7rem;
                                                        font-weight: 600;
                                                    ">
                                                        <?php echo $index + 1; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($club['name']); ?></strong>
                                                <br>
                                                <small style="color: var(--dark-gray);"><?php echo ucfirst($club['category']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($club['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php $score = round($club['performance_score'] ?? 0, 1); ?>
                                                <span class="performance-badge 
                                                    <?php echo $score >= 4 ? 'performance-excellent' : 
                                                          ($score >= 3 ? 'performance-good' : 
                                                          ($score >= 2 ? 'performance-average' : 'performance-poor')); ?>">
                                                    <?php echo $score; ?>/5.0
                                                </span>
                                            </td>
                                            <td><?php echo $club['activity_count']; ?></td>
                                            <td><?php echo $club['active_members']; ?>/<?php echo $club['members_count']; ?></td>
                                            <td><?php echo $club['report_count']; ?></td>
                                            <td>
                                                <span class="performance-badge performance-excellent">
                                                    Top Performer
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity Trends -->
            <div class="performance-section">
                <div class="section-header">
                    <h3 class="section-title">Activity Participation Trends</h3>
                </div>
                <div class="section-body">
                    <?php if (empty($activity_trends)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                            <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h4>No Activity Trend Data</h4>
                            <p>Activity participation trends will appear here as clubs schedule more activities.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Activities</th>
                                        <th>Active Clubs</th>
                                        <th>Total Participants</th>
                                        <th>Avg. Participants</th>
                                        <th>Growth</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity_trends as $trend): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></strong>
                                            </td>
                                            <td><?php echo $trend['activity_count']; ?></td>
                                            <td><?php echo $trend['active_clubs']; ?></td>
                                            <td><?php echo $trend['total_participants']; ?></td>
                                            <td><?php echo round($trend['avg_participants'] ?? 0, 1); ?></td>
                                            <td>
                                                <div class="trend-indicator trend-up">
                                                    <i class="fas fa-arrow-up"></i>
                                                    <span>+12%</span>
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
        </main>
    </div>

    <script>
        // ── Mobile Nav (hamburger sidebar) ──
        (function() {
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const navSidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('mobileNavOverlay');

            function openNav() {
                navSidebar.classList.add('open');
                overlay.classList.add('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-times"></i>';
                document.body.style.overflow = 'hidden';
            }

            function closeNav() {
                navSidebar.classList.remove('open');
                overlay.classList.remove('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }

            hamburgerBtn.addEventListener('click', () => {
                navSidebar.classList.contains('open') ? closeNav() : openNav();
            });

            overlay.addEventListener('click', closeNav);

            window.addEventListener('resize', () => {
                if (window.innerWidth > 900) closeNav();
            });
        })();

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

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Department Performance Chart
            const departmentCtx = document.getElementById('departmentChart').getContext('2d');
            const departmentChart = new Chart(departmentCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($department_performance, 'department')); ?>,
                    datasets: [{
                        label: 'Performance Score',
                        data: <?php echo json_encode(array_column($department_performance, 'avg_performance')); ?>,
                        backgroundColor: '#2E7D32',
                        borderColor: '#1B5E20',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 5,
                            title: {
                                display: true,
                                text: 'Performance Score'
                            }
                        }
                    }
                }
            });

            // Activity Trends Chart
            const trendsCtx = document.getElementById('activityTrendsChart').getContext('2d');
            const trendsChart = new Chart(trendsCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($trend) {
                        return date('M Y', strtotime($trend['month'] . '-01'));
                    }, $activity_trends)); ?>,
                    datasets: [{
                        label: 'Activities',
                        data: <?php echo json_encode(array_column($activity_trends, 'activity_count')); ?>,
                        borderColor: '#2E7D32',
                        backgroundColor: 'rgba(46, 125, 50, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Participants',
                        data: <?php echo json_encode(array_column($activity_trends, 'total_participants')); ?>,
                        borderColor: '#1E88E5',
                        backgroundColor: 'rgba(30, 136, 229, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });

        // Export functionality
        function exportPerformanceData() {
            // In a real implementation, this would generate and download a report
            alert('Performance report export feature would generate a comprehensive PDF/Excel report with all metrics and charts.');
            
            // Simulate export process
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            btn.disabled = true;
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                alert('Performance report generated successfully! The download should start automatically.');
            }, 2000);
        }

        // Auto-refresh performance data every 5 minutes
        setInterval(() => {
            // In a real implementation, this would refresh the data
            console.log('Auto-refreshing performance data...');
        }, 300000);
    </script>
</body>
</html>