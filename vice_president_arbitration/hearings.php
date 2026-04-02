<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle form submissions (vice president can schedule hearings but with some restrictions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['schedule_hearing'])) {
        // Schedule new hearing
        $case_id = $_POST['case_id'];
        $hearing_date = $_POST['hearing_date'];
        $location = $_POST['location'];
        $purpose = $_POST['purpose'];
        $attendees = isset($_POST['attendees']) ? json_encode($_POST['attendees']) : '[]';
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO arbitration_hearings 
                (case_id, hearing_date, location, purpose, attendees, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $case_id, $hearing_date, $location, $purpose, $attendees, $user_id
            ]);
            
            // Update case status to hearing_scheduled
            $stmt = $pdo->prepare("
                UPDATE arbitration_cases 
                SET status = 'hearing_scheduled', hearing_date = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$hearing_date, $case_id]);
            
            $_SESSION['success_message'] = "Hearing scheduled successfully!";
            header('Location: hearings.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error scheduling hearing: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_hearing'])) {
        // Update hearing (vice president can update certain fields)
        $hearing_id = $_POST['hearing_id'];
        $hearing_date = $_POST['hearing_date'];
        $location = $_POST['location'];
        $purpose = $_POST['purpose'];
        $minutes = $_POST['minutes'];
        $next_hearing_date = $_POST['next_hearing_date'];
        $attendees = isset($_POST['attendees']) ? json_encode($_POST['attendees']) : '[]';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE arbitration_hearings 
                SET hearing_date = ?, location = ?, purpose = ?, minutes = ?, 
                    next_hearing_date = ?, attendees = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $hearing_date, $location, $purpose, $minutes, 
                $next_hearing_date, $attendees, $hearing_id
            ]);
            
            $_SESSION['success_message'] = "Hearing updated successfully!";
            header('Location: hearings.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating hearing: " . $e->getMessage();
        }
    }
}

// Handle hearing cancellation (vice president can cancel but not delete)
if (isset($_POST['cancel_hearing'])) {
    $hearing_id = $_POST['hearing_id'];
    $cancellation_reason = $_POST['cancellation_reason'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE arbitration_hearings 
            SET status = 'cancelled', cancellation_reason = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$cancellation_reason, $hearing_id]);
        
        $_SESSION['success_message'] = "Hearing cancelled successfully!";
        header('Location: hearings.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error cancelling hearing: " . $e->getMessage();
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$filter_case = $_GET['case_id'] ?? '';

// Build query for hearings
$query = "SELECT ah.*, 
                 ac.case_number, ac.title as case_title, ac.complainant_name, ac.respondent_name,
                 u.full_name as creator_name
          FROM arbitration_hearings ah
          JOIN arbitration_cases ac ON ah.case_id = ac.id
          LEFT JOIN users u ON ah.created_by = u.id
          WHERE 1=1";

$params = [];

if (!empty($filter_status)) {
    $query .= " AND ah.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_date_from)) {
    $query .= " AND ah.hearing_date::date >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $query .= " AND ah.hearing_date::date <= ?";
    $params[] = $filter_date_to;
}

if (!empty($search)) {
    $query .= " AND (ac.case_number LIKE ? OR ac.title LIKE ? OR ac.complainant_name LIKE ? OR ac.respondent_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($filter_case)) {
    $query .= " AND ac.id = ?";
    $params[] = $filter_case;
}

$query .= " ORDER BY ah.hearing_date ASC";

// Get hearings
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hearings = [];
    error_log("Error fetching hearings: " . $e->getMessage());
}

// Get cases for scheduling
try {
    $stmt = $pdo->query("
        SELECT id, case_number, title, complainant_name, respondent_name 
        FROM arbitration_cases 
        WHERE status NOT IN ('resolved', 'dismissed')
        ORDER BY created_at DESC
    ");
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cases = [];
}

// Get cases for filter dropdown
try {
    $stmt = $pdo->query("
        SELECT DISTINCT ac.id, ac.case_number, ac.title
        FROM arbitration_hearings ah
        JOIN arbitration_cases ac ON ah.case_id = ac.id
        ORDER BY ac.case_number DESC
        LIMIT 20
    ");
    $cases_for_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cases_for_filter = [];
}

// Get arbitration committee members for attendees
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, cm.role 
        FROM users u 
        JOIN committee_members cm ON u.id = cm.user_id
        WHERE cm.role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        AND u.status = 'active'
        ORDER BY cm.role_order
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
}

// Get statistics
try {
    // Total hearings
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_hearings");
    $total_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Scheduled hearings
    $stmt = $pdo->query("SELECT COUNT(*) as scheduled FROM arbitration_hearings WHERE status = 'scheduled' AND hearing_date >= CURRENT_DATE");
    $scheduled_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['scheduled'];
    
    // Completed hearings
    $stmt = $pdo->query("SELECT COUNT(*) as completed FROM arbitration_hearings WHERE status = 'completed'");
    $completed_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['completed'];
    
    // Today's hearings
    $stmt = $pdo->query("SELECT COUNT(*) as today FROM arbitration_hearings WHERE hearing_date::date = CURRENT_DATE");
    $today_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['today'];
    
    // Upcoming hearings (next 7 days)
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming 
        FROM arbitration_hearings 
        WHERE hearing_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'
        AND status = 'scheduled'
    ");
    $upcoming_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming'];
    
    // Hearings I need to attend
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ah.id) as my_hearings
        FROM arbitration_hearings ah
        WHERE ah.attendees @> ?::jsonb
        AND ah.status = 'scheduled'
        AND ah.hearing_date >= CURRENT_DATE
    ");
    $stmt->execute([json_encode([$user_id])]);
    $my_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['my_hearings'];
} catch (PDOException $e) {
    $total_hearings = $scheduled_hearings = $completed_hearings = $today_hearings = $upcoming_hearings = $my_hearings = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arbitration Hearings - Isonga RPSU</title>
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            width: 100%;
            gap: 0.5rem;
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
            transform: translateY(-1px);
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
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
            text-align: center;
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
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
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
            border-left: 4px solid var(--primary-blue);
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
            border-left-color: var(--secondary-blue);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
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
            background: #d1ecf1;
            color: var(--secondary-blue);
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

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            padding: 1.25rem;
        }

        .filter-form {
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

        .form-group label {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        /* Table */
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

        .card-body {
            padding: 1.25rem;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 900px;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
            white-space: nowrap;
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

        .status-postponed {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-cancelled {
            background: #6c757d;
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Hearing Cards for Mobile */
        .hearing-cards {
            display: none;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .hearing-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
            border-left: 4px solid var(--primary-blue);
        }

        .hearing-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }

        .hearing-card-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .hearing-card-case {
            font-size: 0.8rem;
            color: var(--primary-blue);
            font-weight: 500;
        }

        .hearing-card-details {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .hearing-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .hearing-detail i {
            width: 16px;
            text-align: center;
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
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .attendees-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .attendee-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: 4px;
        }

        .attendee-item input[type="checkbox"] {
            margin: 0;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .table {
                display: none;
            }

            .hearing-cards {
                display: grid;
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

            .page-title {
                font-size: 1.2rem;
            }

            .action-buttons {
                flex-direction: column;
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
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        if (!empty($_SESSION['avatar_url'])): 
                            echo '<img src="../' . htmlspecialchars($_SESSION['avatar_url']) . '" alt="Profile">';
                        else:
                            echo strtoupper(substr($_SESSION['full_name'], 0, 1)); 
                        endif;
                        ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration Vice President</div>
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
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>Arbitration Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php" class="active">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                        <?php if ($upcoming_hearings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_hearings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="election_committee.php">
                        <i class="fas fa-users-cog"></i>
                        <span>Election Committee</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
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
                <h1 class="page-title">Arbitration Hearings</h1>
                <button class="btn btn-primary" onclick="openScheduleModal()">
                    <i class="fas fa-calendar-plus"></i> Schedule Hearing
                </button>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_hearings; ?></div>
                        <div class="stat-label">Total Hearings</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $scheduled_hearings; ?></div>
                        <div class="stat-label">Scheduled</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $completed_hearings; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $today_hearings; ?></div>
                        <div class="stat-label">Today</div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="stats-grid" style="margin-top: 1rem;">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_hearings; ?></div>
                        <div class="stat-label">Next 7 Days</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $my_hearings; ?></div>
                        <div class="stat-label">My Upcoming Hearings</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="search">Search Hearings</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by case number, parties...">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="ongoing" <?php echo $filter_status === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="postponed" <?php echo $filter_status === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="case_id">Case</label>
                        <select class="form-control" id="case_id" name="case_id">
                            <option value="">All Cases</option>
                            <?php foreach ($cases_for_filter as $case): ?>
                                <option value="<?php echo $case['id']; ?>" <?php echo $filter_case == $case['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($case['case_number']); ?> - <?php echo htmlspecialchars($case['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date_from">From Date</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" 
                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_to">To Date</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" 
                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="hearings.php" class="btn btn-outline">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Hearings Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Arbitration Hearings (<?php echo count($hearings); ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($hearings)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                            <i class="fas fa-gavel" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No hearings found</h3>
                            <p>No arbitration hearings match your current filters.</p>
                            <button class="btn btn-primary" onclick="openScheduleModal()">
                                <i class="fas fa-calendar-plus"></i> Schedule First Hearing
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- Desktop Table -->
                        <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Case</th>
                                    <th>Date & Time</th>
                                    <th>Location</th>
                                    <th>Purpose</th>
                                    <th>Status</th>
                                    <th>Parties</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hearings as $hearing): 
                                    // Check if current user is in attendees
                                    $attendees = json_decode($hearing['attendees'] ?? '[]', true);
                                    $is_attendee = in_array($user_id, $attendees);
                                ?>
                                    <tr <?php echo $is_attendee ? 'style="background-color: rgba(227, 242, 253, 0.3);"' : ''; ?>>
                                        <td>
                                            <div style="font-weight: 500;">
                                                <?php echo htmlspecialchars($hearing['case_number']); ?>
                                                <?php if ($is_attendee): ?>
                                                    <i class="fas fa-user-check" style="color: var(--success); margin-left: 5px;" title="You are attending"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                <?php echo htmlspecialchars($hearing['case_title']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;">
                                                <?php echo date('M j, Y', strtotime($hearing['hearing_date'])); ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                <?php echo date('g:i A', strtotime($hearing['hearing_date'])); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($hearing['location']); ?></td>
                                        <td style="max-width: 200px;">
                                            <?php echo strlen($hearing['purpose']) > 100 ? 
                                                htmlspecialchars(substr($hearing['purpose'], 0, 100)) . '...' : 
                                                htmlspecialchars($hearing['purpose']); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $hearing['status']; ?>">
                                                <?php echo ucfirst($hearing['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <strong>C:</strong> <?php echo htmlspecialchars($hearing['complainant_name']); ?><br>
                                                <strong>R:</strong> <?php echo htmlspecialchars($hearing['respondent_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline btn-sm" 
                                                        onclick="openEditModal(<?php echo htmlspecialchars(json_encode($hearing)); ?>)"
                                                        title="Edit Hearing" <?php echo $hearing['status'] === 'completed' ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="viewHearingDetails(<?php echo $hearing['id']; ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($hearing['status'] === 'scheduled' || $hearing['status'] === 'ongoing'): ?>
                                                <button class="btn btn-warning btn-sm" 
                                                        onclick="openCancelModal(<?php echo $hearing['id']; ?>, '<?php echo htmlspecialchars($hearing['case_number']); ?>')"
                                                        title="Cancel Hearing">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <!-- Mobile Cards -->
                        <div class="hearing-cards">
                            <?php foreach ($hearings as $hearing): 
                                $attendees = json_decode($hearing['attendees'] ?? '[]', true);
                                $is_attendee = in_array($user_id, $attendees);
                            ?>
                                <div class="hearing-card" <?php echo $is_attendee ? 'style="border-left-color: var(--success);"' : ''; ?>>
                                    <div class="hearing-card-header">
                                        <div>
                                            <div class="hearing-card-title">
                                                <?php echo htmlspecialchars($hearing['case_number']); ?>
                                                <?php if ($is_attendee): ?>
                                                    <i class="fas fa-user-check" style="color: var(--success); margin-left: 5px;" title="You are attending"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="hearing-card-case"><?php echo htmlspecialchars($hearing['case_title']); ?></div>
                                        </div>
                                        <span class="status-badge status-<?php echo $hearing['status']; ?>">
                                            <?php echo ucfirst($hearing['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="hearing-card-details">
                                        <div class="hearing-detail">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($hearing['hearing_date'])); ?>
                                        </div>
                                        <div class="hearing-detail">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($hearing['location']); ?>
                                        </div>
                                        <div class="hearing-detail">
                                            <i class="fas fa-users"></i>
                                            <?php echo htmlspecialchars($hearing['complainant_name']); ?> vs <?php echo htmlspecialchars($hearing['respondent_name']); ?>
                                        </div>
                                        <div class="hearing-detail">
                                            <i class="fas fa-info-circle"></i>
                                            <?php echo strlen($hearing['purpose']) > 100 ? 
                                                htmlspecialchars(substr($hearing['purpose'], 0, 100)) . '...' : 
                                                htmlspecialchars($hearing['purpose']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="action-buttons">
                                        <button class="btn btn-outline btn-sm" 
                                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($hearing)); ?>)"
                                                <?php echo $hearing['status'] === 'completed' ? 'disabled' : ''; ?>>
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-success btn-sm" 
                                                onclick="viewHearingDetails(<?php echo $hearing['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($hearing['status'] === 'scheduled' || $hearing['status'] === 'ongoing'): ?>
                                        <button class="btn btn-warning btn-sm" 
                                                onclick="openCancelModal(<?php echo $hearing['id']; ?>, '<?php echo htmlspecialchars($hearing['case_number']); ?>')">
                                            <i class="fas fa-ban"></i> Cancel
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Schedule Hearing Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Schedule New Hearing</h3>
                <button class="close-btn" onclick="closeScheduleModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="scheduleHearingForm">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="case_id">Select Case *</label>
                            <select class="form-control" id="case_id" name="case_id" required>
                                <option value="">Select a case...</option>
                                <?php foreach ($cases as $case): ?>
                                    <option value="<?php echo $case['id']; ?>">
                                        <?php echo htmlspecialchars($case['case_number']); ?> - 
                                        <?php echo htmlspecialchars($case['title']); ?> 
                                        (<?php echo htmlspecialchars($case['complainant_name']); ?> vs <?php echo htmlspecialchars($case['respondent_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hearing_date">Hearing Date & Time *</label>
                            <input type="datetime-local" class="form-control" id="hearing_date" name="hearing_date" required>
                        </div>
                        <div class="form-group">
                            <label for="location">Location *</label>
                            <input type="text" class="form-control" id="location" name="location" 
                                   placeholder="e.g., Arbitration Room, Main Hall" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="purpose">Purpose/Agenda *</label>
                            <textarea class="form-control" id="purpose" name="purpose" required></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label>Committee Members (Attendees)</label>
                            <div class="attendees-grid">
                                <?php foreach ($committee_members as $member): ?>
                                    <div class="attendee-item">
                                        <input type="checkbox" id="attendee_<?php echo $member['id']; ?>" 
                                               name="attendees[]" value="<?php echo $member['id']; ?>"
                                               <?php echo $member['id'] == $user_id ? 'checked' : ''; ?>>
                                        <label for="attendee_<?php echo $member['id']; ?>" style="margin: 0; font-size: 0.8rem;">
                                            <?php echo htmlspecialchars($member['full_name']); ?>
                                            <small style="color: var(--dark-gray);">(<?php echo str_replace('_', ' ', $member['role']); ?>)</small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeScheduleModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="schedule_hearing">Schedule Hearing</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Hearing Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Hearing Details</h3>
                <button class="close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editHearingForm">
                    <input type="hidden" id="edit_hearing_id" name="hearing_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_hearing_date">Hearing Date & Time *</label>
                            <input type="datetime-local" class="form-control" id="edit_hearing_date" name="hearing_date" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_location">Location *</label>
                            <input type="text" class="form-control" id="edit_location" name="location" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_next_hearing_date">Next Hearing Date</label>
                            <input type="datetime-local" class="form-control" id="edit_next_hearing_date" name="next_hearing_date">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="edit_purpose">Purpose/Agenda *</label>
                            <textarea class="form-control" id="edit_purpose" name="purpose" required></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label for="edit_minutes">Meeting Minutes</label>
                            <textarea class="form-control" id="edit_minutes" name="minutes" rows="4"></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full">
                            <label>Committee Members (Attendees)</label>
                            <div class="attendees-grid" id="edit_attendees_container">
                                <?php foreach ($committee_members as $member): ?>
                                    <div class="attendee-item">
                                        <input type="checkbox" id="edit_attendee_<?php echo $member['id']; ?>" 
                                               name="attendees[]" value="<?php echo $member['id']; ?>">
                                        <label for="edit_attendee_<?php echo $member['id']; ?>" style="margin: 0; font-size: 0.8rem;">
                                            <?php echo htmlspecialchars($member['full_name']); ?>
                                            <small style="color: var(--dark-gray);">(<?php echo str_replace('_', ' ', $member['role']); ?>)</small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="update_hearing">Update Hearing</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Hearing Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Hearing</h3>
                <button class="close-btn" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="cancelHearingForm">
                    <input type="hidden" id="cancel_hearing_id" name="hearing_id">
                    
                    <div class="form-row">
                        <div class="form-group form-full">
                            <p id="cancel_message">Are you sure you want to cancel this hearing?</p>
                            <label for="cancellation_reason">Cancellation Reason *</label>
                            <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" 
                                      rows="4" placeholder="Please provide a reason for cancelling this hearing..." required></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-full" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeCancelModal()">Go Back</button>
                            <button type="submit" class="btn btn-danger" name="cancel_hearing">Cancel Hearing</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openScheduleModal() {
            document.getElementById('scheduleModal').style.display = 'flex';
        }

        function closeScheduleModal() {
            document.getElementById('scheduleModal').style.display = 'none';
        }

        function openEditModal(hearingData) {
            document.getElementById('edit_hearing_id').value = hearingData.id;
            document.getElementById('edit_hearing_date').value = hearingData.hearing_date ? 
                hearingData.hearing_date.replace(' ', 'T').substr(0, 16) : '';
            document.getElementById('edit_location').value = hearingData.location || '';
            document.getElementById('edit_next_hearing_date').value = hearingData.next_hearing_date ? 
                hearingData.next_hearing_date.replace(' ', 'T').substr(0, 16) : '';
            document.getElementById('edit_purpose').value = hearingData.purpose || '';
            document.getElementById('edit_minutes').value = hearingData.minutes || '';
            
            // Set attendees
            const attendees = hearingData.attendees ? JSON.parse(hearingData.attendees) : [];
            const attendeeCheckboxes = document.querySelectorAll('#edit_attendees_container input[type="checkbox"]');
            attendeeCheckboxes.forEach(checkbox => {
                checkbox.checked = attendees.includes(parseInt(checkbox.value));
            });
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openCancelModal(hearingId, caseNumber) {
            document.getElementById('cancel_hearing_id').value = hearingId;
            document.getElementById('cancel_message').textContent = 
                `Are you sure you want to cancel the hearing for case ${caseNumber}?`;
            document.getElementById('cancelModal').style.display = 'flex';
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        function viewHearingDetails(hearingId) {
            window.location.href = `hearing_details.php?id=${hearingId}`;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const scheduleModal = document.getElementById('scheduleModal');
            const editModal = document.getElementById('editModal');
            const cancelModal = document.getElementById('cancelModal');
            
            if (event.target === scheduleModal) {
                closeScheduleModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === cancelModal) {
                closeCancelModal();
            }
        }

        // Set minimum datetime for scheduling to current time
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            document.getElementById('hearing_date').min = localDateTime;
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
    </script>
</body>
</html>