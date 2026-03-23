<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
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

// Get current user's committee member ID
try {
    $stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? AND role = 'vice_guild_finance'");
    $stmt->execute([$user_id]);
    $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $committee_member_id = $committee_member['id'] ?? null;
} catch (PDOException $e) {
    $committee_member_id = null;
    error_log("Committee member error: " . $e->getMessage());
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'upcoming';
$type_filter = $_GET['type'] ?? 'all';
$month_filter = $_GET['month'] ?? '';

// Build query for meetings
$query = "
    SELECT 
        m.*,
        cm.name as chairperson_name,
        creator.full_name as created_by_name,
        ma.attendance_status,
        ma.check_in_time,
        ma.notes as attendance_notes
    FROM meetings m
    LEFT JOIN committee_members cm ON m.chairperson_id = cm.id
    LEFT JOIN users creator ON m.created_by = creator.id
    LEFT JOIN meeting_attendance ma ON m.id = ma.meeting_id AND ma.committee_member_id = ?
    WHERE 1=1
";

$params = [$committee_member_id];

// Apply status filter
if ($status_filter === 'upcoming') {
    $query .= " AND m.meeting_date >= CURDATE() AND m.status IN ('scheduled', 'ongoing')";
} elseif ($status_filter === 'past') {
    $query .= " AND (m.meeting_date < CURDATE() OR m.status IN ('completed', 'cancelled', 'postponed'))";
} elseif ($status_filter !== 'all') {
    $query .= " AND m.status = ?";
    $params[] = $status_filter;
}

// Apply type filter
if ($type_filter !== 'all') {
    $query .= " AND m.meeting_type = ?";
    $params[] = $type_filter;
}

// Apply month filter
if (!empty($month_filter)) {
    $query .= " AND DATE_FORMAT(m.meeting_date, '%Y-%m') = ?";
    $params[] = $month_filter;
}

$query .= " ORDER BY 
    CASE 
        WHEN m.status = 'ongoing' THEN 1
        WHEN m.status = 'scheduled' THEN 2
        WHEN m.status = 'completed' THEN 3
        WHEN m.status = 'postponed' THEN 4
        ELSE 5
    END,
    m.meeting_date ASC, 
    m.start_time ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $meetings = [];
    error_log("Meetings query error: " . $e->getMessage());
}

// Get statistics
try {
    // Total meetings
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM meetings");
    $total_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Upcoming meetings
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming FROM meetings WHERE meeting_date >= CURDATE() AND status IN ('scheduled', 'ongoing')");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming'] ?? 0;

    // Attendance statistics
    if ($committee_member_id) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_attended,
                COUNT(CASE WHEN attendance_status = 'present' THEN 1 END) as present_count,
                COUNT(CASE WHEN attendance_status = 'absent' THEN 1 END) as absent_count,
                COUNT(CASE WHEN attendance_status = 'excused' THEN 1 END) as excused_count
            FROM meeting_attendance 
            WHERE committee_member_id = ?
        ");
        $stmt->execute([$committee_member_id]);
        $attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $attendance_stats = ['total_attended' => 0, 'present_count' => 0, 'absent_count' => 0, 'excused_count' => 0];
    }

    // Meetings by type
    $stmt = $pdo->query("
        SELECT meeting_type, COUNT(*) as count 
        FROM meetings 
        WHERE meeting_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY meeting_type
    ");
    $meetings_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_meetings = $upcoming_meetings = 0;
    $attendance_stats = ['total_attended' => 0, 'present_count' => 0, 'absent_count' => 0, 'excused_count' => 0];
    $meetings_by_type = [];
    error_log("Meetings statistics error: " . $e->getMessage());
}

// Get available months for filter
try {
    $stmt = $pdo->query("
        SELECT DISTINCT DATE_FORMAT(meeting_date, '%Y-%m') as month 
        FROM meetings 
        ORDER BY month DESC
    ");
    $available_months = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $available_months = [];
    error_log("Available months error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meetings - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse all the CSS from dashboard.php */
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
            --finance-primary: #1976D2;
            --finance-secondary: #2196F3;
            --finance-accent: #0D47A1;
            --finance-light: #E3F2FD;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
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
            --finance-primary: #2196F3;
            --finance-secondary: #64B5F6;
            --finance-accent: #1976D2;
            --finance-light: #0D1B2A;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
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
            color: var(--finance-primary);
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
            border-color: var(--finance-primary);
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
            background: var(--finance-primary);
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

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        /* Sidebar */
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
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
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
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon {
            background: var(--finance-light);
            color: var(--finance-primary);
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
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        .trend-positive {
            color: var(--success);
        }

        .trend-negative {
            color: var(--danger);
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
            background: var(--finance-light);
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
        .meetings-grid {
            display: grid;
            gap: 1rem;
        }

        .meeting-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
            transition: var(--transition);
            overflow: hidden;
        }

        .meeting-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .meeting-card.ongoing {
            border-left-color: var(--success);
        }

        .meeting-card.completed {
            border-left-color: var(--dark-gray);
        }

        .meeting-card.cancelled {
            border-left-color: var(--danger);
        }

        .meeting-card.postponed {
            border-left-color: var(--warning);
        }

        .meeting-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: var(--finance-light);
        }

        .meeting-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .meeting-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .meeting-body {
            padding: 1.25rem;
        }

        .meeting-details {
            display: grid;
            gap: 1rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 120px;
        }

        .detail-value {
            flex: 1;
            color: var(--dark-gray);
        }

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #cce7ff;
            color: #004085;
        }

        .status-ongoing {
            background: #d4edda;
            color: #155724;
        }

        .status-completed {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-postponed {
            background: #fff3cd;
            color: #856404;
        }

        /* Attendance badges */
        .attendance-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .attendance-present {
            background: #d4edda;
            color: #155724;
        }

        .attendance-absent {
            background: #f8d7da;
            color: #721c24;
        }

        .attendance-excused {
            background: #fff3cd;
            color: #856404;
        }

        .attendance-pending {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        /* Type badges */
        .type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-general {
            background: #cce7ff;
            color: #004085;
        }

        .type-executive {
            background: #d4edda;
            color: #155724;
        }

        .type-committee {
            background: #fff3cd;
            color: #856404;
        }

        .type-emergency {
            background: #f8d7da;
            color: #721c24;
        }

        .type-planning {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
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

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
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
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 200px;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--medium-gray);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
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
            
            .filters {
                flex-direction: column;
                align-items: stretch;
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
            
            .meeting-header {
                flex-direction: column;
                gap: 1rem;
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
                    <h1>Isonga - Meetings</h1>
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
                        <div class="user-role">Vice Guild Finance</div>
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
                    <a href="budget_management.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Budget Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="rental_management.php">
                        <i class="fas fa-home"></i>
                        <span>Rental Properties</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="allowances.php">
                        <i class="fas fa-money-check"></i>
                        <span>Allowances</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="bank_reconciliation.php">
                        <i class="fas fa-university"></i>
                        <span>Bank Reconciliation</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Official Documents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
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
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Meetings & Attendance 📅</h1>
                    <p>View scheduled meetings and track your attendance records</p>
                </div>
            </div>

            <!-- Meetings Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_meetings; ?></div>
                        <div class="stat-label">Total Meetings</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-chart-line"></i> All Time
                        </div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_meetings; ?></div>
                        <div class="stat-label">Upcoming Meetings</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-bell"></i> Scheduled
                        </div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $attendance_stats['present_count'] ?? 0; ?></div>
                        <div class="stat-label">Meetings Attended</div>
                        <div class="stat-trend trend-positive">
                            <i class="fas fa-check-circle"></i> Present
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                                $attendance_rate = $attendance_stats['total_attended'] > 0 ? 
                                    round(($attendance_stats['present_count'] / $attendance_stats['total_attended']) * 100, 1) : 0;
                                echo $attendance_rate; 
                            ?>%
                        </div>
                        <div class="stat-label">Attendance Rate</div>
                        <div class="stat-trend <?php echo $attendance_rate >= 80 ? 'trend-positive' : 'trend-negative'; ?>">
                            <i class="fas fa-<?php echo $attendance_rate >= 80 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <?php echo $attendance_rate >= 80 ? 'Good' : 'Needs Improvement'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="form-select" onchange="applyFilters()" id="statusFilter">
                        <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming Meetings</option>
                        <option value="past" <?php echo $status_filter === 'past' ? 'selected' : ''; ?>>Past Meetings</option>
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Meetings</option>
                        <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Meeting Type</label>
                    <select class="form-select" onchange="applyFilters()" id="typeFilter">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="general" <?php echo $type_filter === 'general' ? 'selected' : ''; ?>>General</option>
                        <option value="executive" <?php echo $type_filter === 'executive' ? 'selected' : ''; ?>>Executive</option>
                        <option value="committee" <?php echo $type_filter === 'committee' ? 'selected' : ''; ?>>Committee</option>
                        <option value="emergency" <?php echo $type_filter === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                        <option value="planning" <?php echo $type_filter === 'planning' ? 'selected' : ''; ?>>Planning</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Month</label>
                    <select class="form-select" onchange="applyFilters()" id="monthFilter">
                        <option value="">All Months</option>
                        <?php foreach ($available_months as $month): ?>
                            <option value="<?php echo $month; ?>" <?php echo $month_filter === $month ? 'selected' : ''; ?>>
                                <?php echo date('F Y', strtotime($month . '-01')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label" style="visibility: hidden;">Actions</label>
                    <button class="btn btn-primary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>

            <!-- Meetings Grid -->
            <div class="meetings-grid">
                <?php if (empty($meetings)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Meetings Found</h3>
                        <p>There are no meetings matching your current filters.</p>
                        <button class="btn btn-primary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset Filters
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($meetings as $meeting): ?>
                        <div class="meeting-card <?php echo $meeting['status']; ?>">
                            <div class="meeting-header">
                                <div>
                                    <div class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></div>
                                    <div class="meeting-meta">
                                        <span class="type-badge type-<?php echo $meeting['meeting_type']; ?>">
                                            <?php echo ucfirst($meeting['meeting_type']); ?> Meeting
                                        </span>
                                        <span class="status-badge status-<?php echo $meeting['status']; ?>">
                                            <?php echo ucfirst($meeting['status']); ?>
                                        </span>
                                        <?php if ($meeting['attendance_status']): ?>
                                            <span class="attendance-badge attendance-<?php echo $meeting['attendance_status']; ?>">
                                                <?php echo ucfirst($meeting['attendance_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="attendance-badge attendance-pending">
                                                Attendance Pending
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 600; color: var(--text-dark);">
                                        <?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                        <?php echo date('g:i A', strtotime($meeting['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($meeting['end_time'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="meeting-body">
                                <div class="meeting-details">
                                    <div class="detail-row">
                                        <div class="detail-label">Location:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['location']); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Chairperson:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['chairperson_name'] ?? 'TBD'); ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Created By:</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($meeting['created_by_name']); ?></div>
                                    </div>
                                    <?php if (!empty($meeting['agenda'])): ?>
                                        <div class="detail-row">
                                            <div class="detail-label">Agenda:</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($meeting['agenda']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($meeting['minutes'])): ?>
                                        <div class="detail-row">
                                            <div class="detail-label">Minutes:</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($meeting['minutes']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($meeting['attendance_status'] && $meeting['check_in_time']): ?>
                                        <div class="detail-row">
                                            <div class="detail-label">Check-in Time:</div>
                                            <div class="detail-value">
                                                <?php echo date('M j, Y g:i A', strtotime($meeting['check_in_time'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($meeting['attendance_status'] && !empty($meeting['attendance_notes'])): ?>
                                        <div class="detail-row">
                                            <div class="detail-label">Attendance Notes:</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($meeting['attendance_notes']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Meeting Details Modal -->
    <div id="meetingDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalMeetingTitle">Meeting Details</h3>
                <button class="close" onclick="closeModal('meetingDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalMeetingContent">
                    <!-- Content will be loaded via JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('meetingDetailsModal')">Close</button>
            </div>
        </div>
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

        // Filter functionality
        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const month = document.getElementById('monthFilter').value;
            
            let url = 'meetings.php?';
            const params = [];
            
            if (status) params.push(`status=${status}`);
            if (type !== 'all') params.push(`type=${type}`);
            if (month) params.push(`month=${month}`);
            
            window.location.href = url + params.join('&');
        }

        function resetFilters() {
            window.location.href = 'meetings.php';
        }

        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // View meeting details
        function viewMeetingDetails(meetingId) {
            // In a real application, you would fetch this data via AJAX
            // For now, we'll use the existing data
            const meeting = <?php echo json_encode($meetings); ?>.find(m => m.id == meetingId);
            
            if (meeting) {
                document.getElementById('modalMeetingTitle').textContent = meeting.title;
                
                const content = `
                    <div class="meeting-details">
                        <div class="detail-row">
                            <div class="detail-label">Meeting Type:</div>
                            <div class="detail-value">
                                <span class="type-badge type-${meeting.meeting_type}">
                                    ${meeting.meeting_type.charAt(0).toUpperCase() + meeting.meeting_type.slice(1)} Meeting
                                </span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span class="status-badge status-${meeting.status}">
                                    ${meeting.status.charAt(0).toUpperCase() + meeting.status.slice(1)}
                                </span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Date & Time:</div>
                            <div class="detail-value">
                                ${new Date(meeting.meeting_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}<br>
                                ${new Date('1970-01-01T' + meeting.start_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })} - 
                                ${new Date('1970-01-01T' + meeting.end_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Location:</div>
                            <div class="detail-value">${meeting.location}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Chairperson:</div>
                            <div class="detail-value">${meeting.chairperson_name || 'TBD'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Created By:</div>
                            <div class="detail-value">${meeting.created_by_name}</div>
                        </div>
                        ${meeting.agenda ? `
                        <div class="detail-row">
                            <div class="detail-label">Agenda:</div>
                            <div class="detail-value">${meeting.agenda}</div>
                        </div>
                        ` : ''}
                        ${meeting.minutes ? `
                        <div class="detail-row">
                            <div class="detail-label">Minutes:</div>
                            <div class="detail-value">${meeting.minutes}</div>
                        </div>
                        ` : ''}
                        <div class="detail-row">
                            <div class="detail-label">Your Attendance:</div>
                            <div class="detail-value">
                                ${meeting.attendance_status ? `
                                    <span class="attendance-badge attendance-${meeting.attendance_status}">
                                        ${meeting.attendance_status.charAt(0).toUpperCase() + meeting.attendance_status.slice(1)}
                                    </span>
                                    ${meeting.check_in_time ? `<br><small>Checked in at ${new Date(meeting.check_in_time).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</small>` : ''}
                                ` : `
                                    <span class="attendance-badge attendance-pending">
                                        Attendance Pending
                                    </span>
                                `}
                            </div>
                        </div>
                        ${meeting.attendance_notes ? `
                        <div class="detail-row">
                            <div class="detail-label">Attendance Notes:</div>
                            <div class="detail-value">${meeting.attendance_notes}</div>
                        </div>
                        ` : ''}
                    </div>
                `;
                
                document.getElementById('modalMeetingContent').innerHTML = content;
                openModal('meetingDetailsModal');
            }
        }

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Make meeting cards clickable to view details
        document.addEventListener('DOMContentLoaded', function() {
            const meetingCards = document.querySelectorAll('.meeting-card');
            meetingCards.forEach(card => {
                card.style.cursor = 'pointer';
                card.addEventListener('click', function() {
                    const meetingId = this.getAttribute('data-meeting-id');
                    if (meetingId) {
                        viewMeetingDetails(meetingId);
                    }
                });
            });
        });

        // Add meeting IDs to cards for click functionality
        document.addEventListener('DOMContentLoaded', function() {
            const meetingCards = document.querySelectorAll('.meeting-card');
            const meetings = <?php echo json_encode($meetings); ?>;
            
            meetingCards.forEach((card, index) => {
                if (meetings[index]) {
                    card.setAttribute('data-meeting-id', meetings[index].id);
                }
            });
        });
    </script>
</body>
</html>