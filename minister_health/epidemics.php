<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Health
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_health') {
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
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_alert':
                $title = $_POST['title'];
                $description = $_POST['description'];
                $disease_type = $_POST['disease_type'];
                $severity = $_POST['severity'];
                $affected_areas = json_encode(explode(',', $_POST['affected_areas']));
                $reported_cases = $_POST['reported_cases'] ?? 0;
                $suspected_cases = $_POST['suspected_cases'] ?? 0;
                $start_date = $_POST['start_date'];
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO epidemic_alerts (title, description, disease_type, severity, affected_areas, reported_cases, suspected_cases, start_date, reported_by, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$title, $description, $disease_type, $severity, $affected_areas, $reported_cases, $suspected_cases, $start_date, $user_id]);
                    
                    $_SESSION['success_message'] = "Epidemic alert created successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating alert: " . $e->getMessage();
                }
                break;
                
            case 'create_measure':
                $epidemic_alert_id = !empty($_POST['epidemic_alert_id']) ? $_POST['epidemic_alert_id'] : null;
                $measure_type = $_POST['measure_type'];
                $title = $_POST['title'];
                $description = $_POST['description'];
                $target_area = $_POST['target_area'];
                $start_date = $_POST['start_date'];
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $responsible_person = $_POST['responsible_person'];
                $budget = $_POST['budget'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO prevention_measures (epidemic_alert_id, measure_type, title, description, target_area, start_date, end_date, responsible_person, budget, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$epidemic_alert_id, $measure_type, $title, $description, $target_area, $start_date, $end_date, $responsible_person, $budget, $user_id]);
                    
                    $_SESSION['success_message'] = "Prevention measure created successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating measure: " . $e->getMessage();
                }
                break;
                
            case 'record_screening':
                $title = $_POST['title'];
                $screening_type = $_POST['screening_type'];
                $location = $_POST['location'];
                $screening_date = $_POST['screening_date'];
                $participants_count = $_POST['participants_count'] ?? 0;
                $positive_cases = $_POST['positive_cases'] ?? 0;
                $notes = $_POST['notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO health_screenings (title, screening_type, location, screening_date, participants_count, positive_cases, notes, conducted_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$title, $screening_type, $location, $screening_date, $participants_count, $positive_cases, $notes, $user_id]);
                    
                    $_SESSION['success_message'] = "Health screening recorded successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error recording screening: " . $e->getMessage();
                }
                break;
                
            case 'update_alert_status':
                $alert_id = $_POST['alert_id'];
                $status = $_POST['status'];
                
                try {
                    $stmt = $pdo->prepare("UPDATE epidemic_alerts SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$status, $alert_id]);
                    
                    $_SESSION['success_message'] = "Alert status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating alert status: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: epidemics.php");
        exit();
    }
}

// Get epidemic statistics
try {
    // Active alerts
    $stmt = $pdo->query("SELECT COUNT(*) as active_alerts FROM epidemic_alerts WHERE status = 'active'");
    $active_alerts = $stmt->fetch(PDO::FETCH_ASSOC)['active_alerts'] ?? 0;
    
    // Total reported cases
    $stmt = $pdo->query("SELECT COALESCE(SUM(reported_cases), 0) as total_cases FROM epidemic_alerts WHERE status = 'active'");
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'] ?? 0;
    
    // Prevention measures in progress
    $stmt = $pdo->query("SELECT COUNT(*) as active_measures FROM prevention_measures WHERE status = 'in_progress'");
    $active_measures = $stmt->fetch(PDO::FETCH_ASSOC)['active_measures'] ?? 0;
    
    // Recent screenings (last 7 days)
    $stmt = $pdo->query("SELECT COUNT(*) as recent_screenings FROM health_screenings WHERE screening_date >= CURRENT_DATE - INTERVAL '7 days'");
    $recent_screenings = $stmt->fetch(PDO::FETCH_ASSOC)['recent_screenings'] ?? 0;
    
    // Alerts by severity
    $stmt = $pdo->query("
        SELECT severity, COUNT(*) as count 
        FROM epidemic_alerts 
        WHERE status = 'active' 
        GROUP BY severity
    ");
    $alerts_by_severity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent epidemic alerts
    $stmt = $pdo->prepare("
        SELECT ea.*, u.full_name as reported_by_name
        FROM epidemic_alerts ea
        LEFT JOIN users u ON ea.reported_by = u.id
        ORDER BY ea.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active prevention measures
    $stmt = $pdo->prepare("
        SELECT pm.*, u.full_name as responsible_name, ea.title as alert_title
        FROM prevention_measures pm
        LEFT JOIN users u ON pm.responsible_person = u.id
        LEFT JOIN epidemic_alerts ea ON pm.epidemic_alert_id = ea.id
        WHERE pm.status IN ('planned', 'in_progress')
        ORDER BY pm.start_date ASC
        LIMIT 5
    ");
    $stmt->execute();
    $active_prevention_measures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent health screenings
    $stmt = $pdo->prepare("
        SELECT hs.*, u.full_name as conducted_by_name
        FROM health_screenings hs
        LEFT JOIN users u ON hs.conducted_by = u.id
        ORDER BY hs.screening_date DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_screenings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // All active alerts for dropdowns
    $stmt = $pdo->query("SELECT id, title FROM epidemic_alerts WHERE status = 'active' ORDER BY created_at DESC");
    $active_alerts_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Committee members for assignment
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role
        FROM users u
        WHERE u.role IN ('minister_health', 'vice_guild_academic', 'general_secretary', 'minister_environment')
        AND u.status = 'active'
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Epidemics statistics error: " . $e->getMessage());
    $active_alerts = $total_cases = $active_measures = $recent_screenings = 0;
    $alerts_by_severity = $recent_alerts = $active_prevention_measures = $recent_screenings_list = [];
    $active_alerts_list = $committee_members = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Epidemics Prevention - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #20c997;
            --accent-green: #198754;
            --light-green: #d1f2eb;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            --primary-green: #20c997;
            --secondary-green: #3dd9a7;
            --accent-green: #198754;
            --light-green: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-green);
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
        }

        .icon-btn:hover {
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
        }

        .menu-item i {
            width: 20px;
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

        .page-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-description {
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
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
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
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
        }

        .btn-outline:hover {
            background: var(--primary-green);
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
            border-left: 4px solid var(--primary-green);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-card.success {
            border-left-color: var(--success);
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
            background: var(--light-green);
            color: var(--primary-green);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
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

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--light-green);
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
            background: var(--light-green);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #fff3cd;
            color: #856404;
        }

        .status-contained {
            background: #cce7ff;
            color: #004085;
        }

        .status-resolved {
            background: #d4edda;
            color: #155724;
        }

        .status-false_alarm {
            background: #f8d7da;
            color: #721c24;
        }

        /* Severity Badges */
        .severity-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .severity-critical {
            background: #f8d7da;
            color: var(--danger);
        }

        .severity-high {
            background: #ffeaa7;
            color: #e17055;
        }

        .severity-medium {
            background: #cce7ff;
            color: var(--info);
        }

        .severity-low {
            background: #d4edda;
            color: var(--success);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--light-green);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            font-size: 0.8rem;
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
            margin-top: 0;
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
            cursor: pointer;
        }

        .action-btn:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-green);
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        /* Forms */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-input, .form-select, .form-textarea {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-green);
        }

        .modal-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
        }

        .modal-close:hover {
            color: var(--text-dark);
        }

        .modal-body {
            padding: 1.25rem;
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

        /* Animations */
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
                background: var(--primary-green);
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

            .content-grid {
                grid-template-columns: 1fr;
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
                justify-content: space-between;
            }

            .quick-actions {
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

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Epidemics Prevention</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                   
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                   
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Health</div>
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
                    <a href="health_tickets.php">
                        <i class="fas fa-heartbeat"></i>
                        <span>Health Issues</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostels.php">
                        <i class="fas fa-bed"></i>
                        <span>Hostel Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="campaigns.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Health Campaigns</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="epidemics.php" class="active">
                        <i class="fas fa-virus"></i>
                        <span>Epidemic Prevention</span>
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
        <main class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                
                <div class="page-actions">
                    <button class="btn btn-outline" onclick="openModal('screeningModal')">
                        <i class="fas fa-stethoscope"></i> Record Screening
                    </button>
                    <button class="btn btn-primary" onclick="openModal('alertModal')">
                        <i class="fas fa-bell"></i> New Alert
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($active_alerts); ?></div>
                        <div class="stat-label">Active Alerts</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-procedures"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_cases); ?></div>
                        <div class="stat-label">Reported Cases</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($active_measures); ?></div>
                        <div class="stat-label">Active Measures</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($recent_screenings); ?></div>
                        <div class="stat-label">Screenings (Week)</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Active Epidemic Alerts -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-exclamation-triangle"></i> Active Epidemic Alerts</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_alerts)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No active epidemic alerts</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Disease</th>
                                                <th>Severity</th>
                                                <th>Cases</th>
                                                <th>Start Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_alerts as $alert): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($alert['disease_type']); ?></div>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($alert['title']); ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="severity-badge severity-<?php echo $alert['severity']; ?>">
                                                            <?php echo ucfirst($alert['severity']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div style="font-weight: 600;"><?php echo number_format($alert['reported_cases']); ?> confirmed</div>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo number_format($alert['suspected_cases']); ?> suspected</div>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($alert['start_date'])); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $alert['status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $alert['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="update_alert_status">
                                                            <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                                            <select name="status" class="form-select" style="font-size: 0.7rem; padding: 0.25rem; width: auto;" onchange="confirmStatusChange(this)">
                                                                <option value="active" <?php echo $alert['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="contained" <?php echo $alert['status'] === 'contained' ? 'selected' : ''; ?>>Contained</option>
                                                                <option value="resolved" <?php echo $alert['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                                <option value="false_alarm" <?php echo $alert['status'] === 'false_alarm' ? 'selected' : ''; ?>>False Alarm</option>
                                                            </select>
                                                        </form>
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
                    <!-- Recent Screening Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-stethoscope"></i> Recent Screening Activities</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_screenings_list)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No recent screening activities</p>
                                </div>
                            <?php else: ?>
                                <ul class="activity-list">
                                    <?php foreach ($recent_screenings_list as $screening): ?>
                                        <li class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-user-md"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <?php echo htmlspecialchars($screening['full_name']); ?> screened for <?php echo htmlspecialchars($screening['disease_type']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, Y, g:i A', strtotime($screening['screening_date'])); ?>
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
    <!-- Modals -->
    <?php include 'modals/epidemic_alert_modal.php'; ?>
    <?php include 'modals/screening_modal.php'; ?>  
    <!-- Scripts -->
    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const mainContent = document.getElementById('mainContent');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
        });

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-open');
            mobileOverlay.classList.toggle('active');
        });

        mobileOverlay.addEventListener('click', () => {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
        });

        

        // Load saved theme preference
        window.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark-mode');
            }
        });

        // Confirm status change
        function confirmStatusChange(select) {
            const form = select.closest('form');
            const status = select.value;
            let message = '';
            switch(status) {
                case 'active':
                    message = 'Mark this alert as Active?';
                    break;
                case 'contained':
                    message = 'Mark this alert as Contained?';
                    break;
                case 'resolved':
                    message = 'Mark this alert as Resolved?';
                    break;
                case 'false_alarm':
                    message = 'Mark this alert as False Alarm?';
                    break;
                default:
                    message = 'Change alert status?';
            }
            if (confirm(message)) {
                form.submit();
            } else {
                // Revert to previous value if cancelled
                form.reset();
            }
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display =
                'block';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).style.display =
                'none';
        }
        // Close modals when clicking outside of them
        window.onclick = function(event) {
            const alertModal = document.getElementById('alertModal');
            const screeningModal = document.getElementById('screeningModal');
            if (event.target === alertModal) {
                closeModal('alertModal');
            }
            if (event.target === screeningModal) {
                closeModal('screeningModal');
            }
        }
    </script>
</body>
</html>
