<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Sports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_sports') {
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_training'])) {
        // Add new training session
        try {
            $stmt = $pdo->prepare("
                INSERT INTO training_sessions 
                (team_id, title, facility_id, session_date, start_time, end_time, focus_areas, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['team_id'],
                $_POST['title'],
                $_POST['facility_id'] ?: null,
                $_POST['session_date'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['focus_areas'],
                $user_id
            ]);
            $_SESSION['success_message'] = "Training session scheduled successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error scheduling training session: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_training'])) {
        // Update training session
        try {
            $stmt = $pdo->prepare("
                UPDATE training_sessions 
                SET team_id = ?, title = ?, facility_id = ?, session_date = ?, 
                    start_time = ?, end_time = ?, focus_areas = ?, status = ?
                WHERE id = ? AND created_by = ?
            ");
            $stmt->execute([
                $_POST['team_id'],
                $_POST['title'],
                $_POST['facility_id'] ?: null,
                $_POST['session_date'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['focus_areas'],
                $_POST['status'],
                $_POST['training_id'],
                $user_id
            ]);
            $_SESSION['success_message'] = "Training session updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating training session: " . $e->getMessage();
        }
    } elseif (isset($_POST['record_attendance'])) {
        // Record attendance
        try {
            $training_session_id = $_POST['training_id'];
            
            // Delete existing attendance for this session
            $stmt = $pdo->prepare("DELETE FROM training_attendance WHERE training_session_id = ?");
            $stmt->execute([$training_session_id]);
            
            // Insert new attendance records
            if (isset($_POST['attendance'])) {
                foreach ($_POST['attendance'] as $member_id => $status) {
                    $stmt = $pdo->prepare("
                        INSERT INTO training_attendance 
                        (training_session_id, team_member_id, attendance_status, recorded_by)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$training_session_id, $member_id, $status, $user_id]);
                }
            }
            
            // Update attendance count
            $stmt = $pdo->prepare("
                UPDATE training_sessions 
                SET attendance_count = (
                    SELECT COUNT(*) FROM training_attendance 
                    WHERE training_session_id = ? AND attendance_status = 'present'
                )
                WHERE id = ?
            ");
            $stmt->execute([$training_session_id, $training_session_id]);
            
            $_SESSION['success_message'] = "Attendance recorded successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error recording attendance: " . $e->getMessage();
        }
    }
    
    header("Location: training.php");
    exit();
}

// Handle delete action
if (isset($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM training_sessions WHERE id = ? AND created_by = ?");
        $stmt->execute([$_GET['delete'], $user_id]);
        $_SESSION['success_message'] = "Training session deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting training session: " . $e->getMessage();
    }
    header("Location: training.php");
    exit();
}

// Get training sessions data
try {
    // Get all training sessions
    $stmt = $pdo->prepare("
        SELECT ts.*, st.team_name, st.sport_type, sf.name as facility_name,
               COUNT(ta.id) as recorded_attendance,
               SUM(CASE WHEN ta.attendance_status = 'present' THEN 1 ELSE 0 END) as present_count
        FROM training_sessions ts
        LEFT JOIN sports_teams st ON ts.team_id = st.id
        LEFT JOIN sports_facilities sf ON ts.facility_id = sf.id
        LEFT JOIN training_attendance ta ON ts.id = ta.training_session_id
        GROUP BY ts.id, st.team_name, st.sport_type, sf.name
        ORDER BY ts.session_date DESC, ts.start_time DESC
    ");
    $stmt->execute();
    $training_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get active teams for dropdown
    $stmt = $pdo->query("
        SELECT id, team_name, sport_type 
        FROM sports_teams 
        WHERE status = 'active' 
        ORDER BY sport_type, team_name
    ");
    $active_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available facilities for dropdown
    $stmt = $pdo->query("
        SELECT id, name, type 
        FROM sports_facilities 
        WHERE status = 'available' 
        ORDER BY name
    ");
    $available_facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming training sessions (next 7 days)
    $stmt = $pdo->prepare("
        SELECT ts.*, st.team_name, sf.name as facility_name
        FROM training_sessions ts
        LEFT JOIN sports_teams st ON ts.team_id = st.id
        LEFT JOIN sports_facilities sf ON ts.facility_id = sf.id
        WHERE ts.session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND ts.status = 'scheduled'
        ORDER BY ts.session_date ASC, ts.start_time ASC
    ");
    $stmt->execute();
    $upcoming_trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get training statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_sessions,
            SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming_sessions,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
            AVG(attendance_count) as avg_attendance
        FROM training_sessions
        WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $training_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get team members for a specific training session (if viewing attendance)
    $team_members = [];
    if (isset($_GET['view_attendance'])) {
        $training_id = $_GET['view_attendance'];
        $stmt = $pdo->prepare("
            SELECT tm.*, ta.attendance_status, ta.performance_rating, ta.notes
            FROM team_members tm
            LEFT JOIN training_attendance ta ON tm.id = ta.team_member_id AND ta.training_session_id = ?
            WHERE tm.team_id = (
                SELECT team_id FROM training_sessions WHERE id = ?
            )
            AND tm.status = 'active'
            ORDER BY tm.name
        ");
        $stmt->execute([$training_id, $training_id]);
        $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Training data error: " . $e->getMessage());
    $training_sessions = $active_teams = $available_facilities = $upcoming_trainings = [];
    $training_stats = ['total_sessions' => 0, 'upcoming_sessions' => 0, 'completed_sessions' => 0, 'avg_attendance' => 0];
    $team_members = [];
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
    <title>Training Management - Minister of Sports</title>
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

        .page-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
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
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Alert */
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
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
            
            .form-row {
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
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
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
                    <h1>Isonga - Minister of Sports</h1>
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
                        <div class="user-role">Minister of Sports</div>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="teams.php">
                        <i class="fas fa-users"></i>
                        <span>Sports Teams</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php">
                        <i class="fas fa-building"></i>
                        <span>Sports Facilities</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-music"></i>
                        <span>Entertainment Clubs</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="competitions.php">
                        <i class="fas fa-trophy"></i>
                        <span>Competitions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="equipment.php" >
                        <i class="fas fa-baseball-ball"></i>
                        <span>Equipment</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Funding & Budget</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="training.php" class="active">
                        <i class="fas fa-running"></i>
                        <span>Training</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
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
                <h1 class="page-title">Training Management</h1>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addTrainingModal')">
                        <i class="fas fa-plus"></i> Schedule Training
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
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

            <!-- Training Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-running"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $training_stats['total_sessions']; ?></div>
                        <div class="stat-label">Total Sessions (30 days)</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $training_stats['upcoming_sessions']; ?></div>
                        <div class="stat-label">Upcoming Sessions</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $training_stats['avg_attendance'] ? round($training_stats['avg_attendance']) : 0; ?></div>
                        <div class="stat-label">Avg. Attendance</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-flag-checkered"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $training_stats['completed_sessions']; ?></div>
                        <div class="stat-label">Completed Sessions</div>
                    </div>
                </div>
            </div>

            <!-- Training Sessions Table -->
            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Training Sessions</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Training Session</th>
                                    <th>Team</th>
                                    <th>Date & Time</th>
                                    <th>Facility</th>
                                    <th>Attendance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($training_sessions)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                            No training sessions scheduled
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($training_sessions as $session): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($session['title']); ?></strong>
                                                <?php if (!empty($session['focus_areas'])): ?>
                                                    <br><small style="color: var(--dark-gray);"><?php echo htmlspecialchars($session['focus_areas']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($session['team_name']); ?></td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($session['session_date'])); ?><br>
                                                <small><?php echo date('g:i A', strtotime($session['start_time'])); ?> - <?php echo date('g:i A', strtotime($session['end_time'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($session['facility_name'] ?? 'TBD'); ?></td>
                                            <td>
                                                <?php if ($session['recorded_attendance'] > 0): ?>
                                                    <?php echo $session['present_count']; ?>/<?php echo $session['recorded_attendance']; ?>
                                                    (<?php echo $session['recorded_attendance'] > 0 ? round(($session['present_count'] / $session['recorded_attendance']) * 100) : 0; ?>%)
                                                <?php else: ?>
                                                    <span style="color: var(--dark-gray);">Not recorded</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $session['status']; ?>">
                                                    <?php echo ucfirst($session['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($session['status'] === 'scheduled' || $session['status'] === 'ongoing'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="openAttendanceModal(<?php echo $session['id']; ?>)">
                                                            <i class="fas fa-clipboard-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-warning btn-sm" onclick="editTraining(<?php echo $session['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $session['id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Upcoming Training Sessions -->
            <?php if (!empty($upcoming_trainings)): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Upcoming Training (Next 7 Days)</h3>
                </div>
                <div class="card-body">
                    <div style="display: grid; gap: 1rem;">
                        <?php foreach ($upcoming_trainings as $training): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                <div>
                                    <strong><?php echo htmlspecialchars($training['title']); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                        <?php echo htmlspecialchars($training['team_name']); ?> • 
                                        <?php echo htmlspecialchars($training['facility_name'] ?? 'TBD'); ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 600;"><?php echo date('D, M j', strtotime($training['session_date'])); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                        <?php echo date('g:i A', strtotime($training['start_time'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Training Modal -->
    <div id="addTrainingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Schedule Training Session</h3>
                <button class="modal-close" onclick="closeModal('addTrainingModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Training Title *</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g., Basketball Skills Development">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Team *</label>
                        <select name="team_id" class="form-control" required>
                            <option value="">Select Team</option>
                            <?php foreach ($active_teams as $team): ?>
                                <option value="<?php echo $team['id']; ?>">
                                    <?php echo htmlspecialchars($team['team_name']); ?> (<?php echo htmlspecialchars($team['sport_type']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Training Date *</label>
                            <input type="date" name="session_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Facility</label>
                            <select name="facility_id" class="form-control">
                                <option value="">Select Facility</option>
                                <?php foreach ($available_facilities as $facility): ?>
                                    <option value="<?php echo $facility['id']; ?>">
                                        <?php echo htmlspecialchars($facility['name']); ?> (<?php echo htmlspecialchars($facility['type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Start Time *</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Time *</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Focus Areas</label>
                        <textarea name="focus_areas" class="form-control" rows="3" placeholder="e.g., Defensive strategies, Shooting techniques, Team coordination..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addTrainingModal')">Cancel</button>
                    <button type="submit" name="add_training" class="btn btn-primary">Schedule Training</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Training functions
        function editTraining(trainingId) {
            // Implement edit functionality
            alert('Edit training session ' + trainingId);
            // You can implement AJAX to load training data into a modal
        }

        function openAttendanceModal(trainingId) {
            window.location.href = 'training.php?view_attendance=' + trainingId;
        }

        function confirmDelete(trainingId) {
            if (confirm('Are you sure you want to delete this training session?')) {
                window.location.href = 'training.php?delete=' + trainingId;
            }
        }

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

        // Set minimum time for time inputs to current time
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const timeString = now.toTimeString().slice(0, 5);
            document.querySelector('input[name="start_time"]').min = timeString;
            document.querySelector('input[name="end_time"]').min = timeString;
        });
    </script>
</body>
</html>