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
                        INSERT INTO epidemic_alerts (title, description, disease_type, severity, affected_areas, reported_cases, suspected_cases, start_date, reported_by, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([$title, $description, $disease_type, $severity, $affected_areas, $reported_cases, $suspected_cases, $start_date, $user_id]);
                    
                    $_SESSION['success_message'] = "Epidemic alert created successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating alert: " . $e->getMessage();
                }
                break;
                
            case 'create_measure':
                $epidemic_alert_id = $_POST['epidemic_alert_id'] ?? null;
                $measure_type = $_POST['measure_type'];
                $title = $_POST['title'];
                $description = $_POST['description'];
                $target_area = $_POST['target_area'];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $responsible_person = $_POST['responsible_person'];
                $budget = $_POST['budget'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO prevention_measures (epidemic_alert_id, measure_type, title, description, target_area, start_date, end_date, responsible_person, budget, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                        INSERT INTO health_screenings (title, screening_type, location, screening_date, participants_count, positive_cases, notes, conducted_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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
                    $stmt = $pdo->prepare("UPDATE epidemic_alerts SET status = ? WHERE id = ?");
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
    $stmt = $pdo->query("SELECT SUM(reported_cases) as total_cases FROM epidemic_alerts WHERE status = 'active'");
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'] ?? 0;
    
    // Prevention measures in progress
    $stmt = $pdo->query("SELECT COUNT(*) as active_measures FROM prevention_measures WHERE status = 'in_progress'");
    $active_measures = $stmt->fetch(PDO::FETCH_ASSOC)['active_measures'] ?? 0;
    
    // Recent screenings
    $stmt = $pdo->query("SELECT COUNT(*) as recent_screenings FROM health_screenings WHERE screening_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            color: var(--primary-green);
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
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
        }

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .page-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
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
            border-left: 3px solid var(--primary-green);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card .stat-icon {
            background: var(--light-green);
            color: var(--primary-green);
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

        .status-active {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-contained {
            background: #cce7ff;
            color: var(--info);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-false_alarm {
            background: #f8d7da;
            color: var(--danger);
        }

        .severity-badge {
            padding: 0.25rem 0.5rem;
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

        /* Forms */
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
            border-radius: 4px;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .modal-body {
            padding: 1.25rem;
        }

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
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: start;
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
            cursor: pointer;
        }

        .action-btn:hover {
            border-color: var(--primary-green);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
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
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .page-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .table {
                font-size: 0.7rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
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
                    <h1>Isonga - Epidemics Prevention</h1>
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
              <nav class="sidebar">
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
                    <a href="action-funding.php" >
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
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Epidemics Prevention & Control</h1>
                    <p class="page-description">Monitor disease outbreaks and implement prevention measures across campus</p>
                </div>
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

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_alerts; ?></div>
                        <div class="stat-label">Active Alerts</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-procedures"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_cases; ?></div>
                        <div class="stat-label">Reported Cases</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_measures; ?></div>
                        <div class="stat-label">Active Measures</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $recent_screenings; ?></div>
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
                            <h3>Active Epidemic Alerts</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_alerts)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No active epidemic alerts</p>
                                </div>
                            <?php else: ?>
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
                                                    <div style="font-weight: 600;"><?php echo $alert['reported_cases']; ?> confirmed</div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo $alert['suspected_cases']; ?> suspected</div>
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
                                                        <select name="status" onchange="this.form.submit()" style="font-size: 0.7rem; padding: 0.25rem;">
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
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Prevention Measures -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Active Prevention Measures</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" onclick="openModal('measureModal')" title="Add Measure">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($active_prevention_measures)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No active prevention measures</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach ($active_prevention_measures as $measure): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-<?php 
                                                    switch($measure['measure_type']) {
                                                        case 'vaccination': echo 'syringe'; break;
                                                        case 'sanitation': echo 'spray-can'; break;
                                                        case 'isolation': echo 'procedures'; break;
                                                        case 'screening': echo 'stethoscope'; break;
                                                        default: echo 'shield-alt';
                                                    }
                                                ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($measure['title']); ?></strong>
                                                    <?php if ($measure['alert_title']): ?>
                                                        - Related to: <?php echo htmlspecialchars($measure['alert_title']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo htmlspecialchars($measure['responsible_name']); ?> • 
                                                    <?php echo date('M j, Y', strtotime($measure['start_date'])); ?> - 
                                                    <?php echo date('M j, Y', strtotime($measure['end_date'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Health Screenings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Health Screenings</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_screenings_list)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No recent health screenings</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Screening</th>
                                            <th>Location</th>
                                            <th>Date</th>
                                            <th>Participants</th>
                                            <th>Positive</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_screenings_list as $screening): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($screening['title']); ?></td>
                                                <td><?php echo htmlspecialchars($screening['location']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($screening['screening_date'])); ?></td>
                                                <td><?php echo $screening['participants_count']; ?></td>
                                                <td>
                                                    <span style="color: <?php echo $screening['positive_cases'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>; font-weight: 600;">
                                                        <?php echo $screening['positive_cases']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <button class="action-btn" onclick="openModal('alertModal')">
                                    <i class="fas fa-bell"></i>
                                    <span class="action-label">New Alert</span>
                                </button>
                                <button class="action-btn" onclick="openModal('measureModal')">
                                    <i class="fas fa-shield-alt"></i>
                                    <span class="action-label">Prevention Measure</span>
                                </button>
                                <button class="action-btn" onclick="openModal('screeningModal')">
                                    <i class="fas fa-stethoscope"></i>
                                    <span class="action-label">Health Screening</span>
                                </button>
                                <a href="reports.php?type=epidemic" class="action-btn">
                                    <i class="fas fa-chart-bar"></i>
                                    <span class="action-label">Reports</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Severity Distribution -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Alerts by Severity</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($alerts_by_severity)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                                    <p>No severity data available</p>
                                </div>
                            <?php else: ?>
                                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                    <?php foreach ($alerts_by_severity as $severity): ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <span class="severity-badge severity-<?php echo $severity['severity']; ?>" style="font-size: 0.6rem;">
                                                    <?php echo ucfirst($severity['severity']); ?>
                                                </span>
                                            </div>
                                            <span style="font-weight: 600; color: var(--text-dark);"><?php echo $severity['count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Emergency Contacts -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Emergency Contacts</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.8rem;">College Clinic</div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">+250 788 123 456</div>
                                    </div>
                                    <button class="btn btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                        Call
                                    </button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--medium-gray);">
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.8rem;">Emergency Services</div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">112</div>
                                    </div>
                                    <button class="btn btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                        Call
                                    </button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.8rem;">Health Ministry</div>
                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">+250 788 234 567</div>
                                    </div>
                                    <button class="btn btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">
                                        Call
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Important Notice -->
                    <?php if ($active_alerts > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Active Epidemic Alert:</strong> 
                            There <?php echo $active_alerts === 1 ? 'is' : 'are'; ?> currently 
                            <?php echo $active_alerts; ?> active epidemic 
                            alert<?php echo $active_alerts === 1 ? '' : 's'; ?> on campus.
                            <a href="#" style="display: block; margin-top: 0.5rem; font-weight: 600;">View All Alerts →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <!-- New Epidemic Alert Modal -->
    <div class="modal" id="alertModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Epidemic Alert</h3>
                <button class="modal-close" onclick="closeModal('alertModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_alert">
                    
                    <div class="form-group">
                        <label class="form-label">Alert Title *</label>
                        <input type="text" name="title" class="form-input" placeholder="e.g., Influenza Outbreak in Hostel A" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Disease Type *</label>
                        <select name="disease_type" class="form-select" required>
                            <option value="">Select disease type...</option>
                            <option value="Influenza">Influenza (Flu)</option>
                            <option value="COVID-19">COVID-19</option>
                            <option value="Malaria">Malaria</option>
                            <option value="Cholera">Cholera</option>
                            <option value="Typhoid">Typhoid Fever</option>
                            <option value="Dengue">Dengue Fever</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Severity Level *</label>
                        <select name="severity" class="form-select" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Affected Areas *</label>
                        <input type="text" name="affected_areas" class="form-input" placeholder="e.g., Hostel A, Cafeteria, Library" required>
                        <small style="color: var(--dark-gray);">Separate multiple areas with commas</small>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Reported Cases</label>
                            <input type="number" name="reported_cases" class="form-input" value="0" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Suspected Cases</label>
                            <input type="number" name="suspected_cases" class="form-input" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Date *</label>
                        <input type="date" name="start_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-textarea" placeholder="Describe the outbreak, symptoms observed, and immediate concerns..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('alertModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Alert</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Prevention Measure Modal -->
    <div class="modal" id="measureModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create Prevention Measure</h3>
                <button class="modal-close" onclick="closeModal('measureModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_measure">
                    
                    <div class="form-group">
                        <label class="form-label">Related Alert (Optional)</label>
                        <select name="epidemic_alert_id" class="form-select">
                            <option value="">No specific alert</option>
                            <?php foreach ($active_alerts_list as $alert): ?>
                                <option value="<?php echo $alert['id']; ?>"><?php echo htmlspecialchars($alert['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Measure Type *</label>
                        <select name="measure_type" class="form-select" required>
                            <option value="awareness">Awareness Campaign</option>
                            <option value="sanitation">Sanitation Drive</option>
                            <option value="vaccination">Vaccination Program</option>
                            <option value="isolation">Isolation Measures</option>
                            <option value="screening">Health Screening</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-input" placeholder="e.g., Hand Hygiene Awareness Campaign" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Target Area *</label>
                        <input type="text" name="target_area" class="form-input" placeholder="e.g., All Hostels, Main Campus" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-input">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Responsible Person *</label>
                        <select name="responsible_person" class="form-select" required>
                            <option value="">Select responsible person...</option>
                            <?php foreach ($committee_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['full_name'] . ' (' . $member['role'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Budget (RWF)</label>
                        <input type="number" name="budget" class="form-input" placeholder="0.00" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-textarea" placeholder="Describe the prevention measure, objectives, and implementation plan..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('measureModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Measure</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Health Screening Modal -->
    <div class="modal" id="screeningModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Record Health Screening</h3>
                <button class="modal-close" onclick="closeModal('screeningModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="record_screening">
                    
                    <div class="form-group">
                        <label class="form-label">Screening Title *</label>
                        <input type="text" name="title" class="form-input" placeholder="e.g., Routine Temperature Check - Hostel B" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Screening Type *</label>
                        <select name="screening_type" class="form-select" required>
                            <option value="temperature">Temperature Check</option>
                            <option value="symptoms">Symptoms Screening</option>
                            <option value="vaccination">Vaccination Status</option>
                            <option value="general">General Health Check</option>
                            <option value="targeted">Targeted Screening</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Location *</label>
                        <input type="text" name="location" class="form-input" placeholder="e.g., Hostel B Common Area" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Screening Date *</label>
                        <input type="date" name="screening_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Participants Count</label>
                            <input type="number" name="participants_count" class="form-input" value="0" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Positive Cases</label>
                            <input type="number" name="positive_cases" class="form-input" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes & Observations</label>
                        <textarea name="notes" class="form-textarea" placeholder="Record any observations, follow-up actions needed, or special notes..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('screeningModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Record Screening</button>
                    </div>
                </form>
            </div>
        </div>
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

        // Modal Functions
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

        // Auto-refresh data every 5 minutes
        setInterval(() => {
            window.location.reload();
        }, 300000);

        // Add confirmation for critical actions
        document.addEventListener('DOMContentLoaded', function() {
            const statusSelects = document.querySelectorAll('select[name="status"]');
            statusSelects.forEach(select => {
                select.addEventListener('change', function() {
                    if (this.value === 'resolved' || this.value === 'false_alarm') {
                        if (!confirm('Are you sure you want to mark this alert as ' + this.value + '? This will archive the alert.')) {
                            this.value = 'active';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>