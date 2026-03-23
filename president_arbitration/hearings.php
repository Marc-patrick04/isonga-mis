<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle form submissions
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
                SET status = 'hearing_scheduled', hearing_date = ?, updated_at = NOW()
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
        // Update hearing
        $hearing_id = $_POST['hearing_id'];
        $hearing_date = $_POST['hearing_date'];
        $location = $_POST['location'];
        $purpose = $_POST['purpose'];
        $minutes = $_POST['minutes'];
        $decisions = $_POST['decisions'];
        $next_hearing_date = $_POST['next_hearing_date'];
        $status = $_POST['status'];
        $attendees = isset($_POST['attendees']) ? json_encode($_POST['attendees']) : '[]';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE arbitration_hearings 
                SET hearing_date = ?, location = ?, purpose = ?, minutes = ?, 
                    decisions = ?, next_hearing_date = ?, status = ?, attendees = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $hearing_date, $location, $purpose, $minutes, $decisions, 
                $next_hearing_date, $status, $attendees, $hearing_id
            ]);
            
            $_SESSION['success_message'] = "Hearing updated successfully!";
            header('Location: hearings.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating hearing: " . $e->getMessage();
        }
    }
}

// Handle hearing deletion
if (isset($_GET['delete_hearing'])) {
    $hearing_id = $_GET['delete_hearing'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM arbitration_hearings WHERE id = ?");
        $stmt->execute([$hearing_id]);
        
        $_SESSION['success_message'] = "Hearing deleted successfully!";
        header('Location: hearings.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting hearing: " . $e->getMessage();
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

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
    $query .= " AND DATE(ah.hearing_date) >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $query .= " AND DATE(ah.hearing_date) <= ?";
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
    $stmt = $pdo->query("SELECT COUNT(*) as scheduled FROM arbitration_hearings WHERE status = 'scheduled' AND hearing_date >= CURDATE()");
    $scheduled_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['scheduled'];
    
    // Completed hearings
    $stmt = $pdo->query("SELECT COUNT(*) as completed FROM arbitration_hearings WHERE status = 'completed'");
    $completed_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['completed'];
    
    // Today's hearings
    $stmt = $pdo->query("SELECT COUNT(*) as today FROM arbitration_hearings WHERE DATE(hearing_date) = CURDATE()");
    $today_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['today'];
    
    // Upcoming hearings (next 7 days)
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming 
        FROM arbitration_hearings 
        WHERE hearing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
        AND status = 'scheduled'
    ");
    $upcoming_hearings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming'];
} catch (PDOException $e) {
    $total_hearings = $scheduled_hearings = $completed_hearings = $today_hearings = $upcoming_hearings = 0;
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

        /* Page Header */
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

        .urgency-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .urgency-high {
            background: #f8d7da;
            color: var(--danger);
        }

        .urgency-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .urgency-low {
            background: #d4edda;
            color: var(--success);
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
            justify-content: between;
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
            justify-content: between;
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

        /* Responsive */
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
            
            .action-buttons {
                flex-direction: column;
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
                    <h1>Isonga - Arbitration</h1>
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
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration President</div>
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
                    <a href="cases.php" >
                        <i class="fas fa-balance-scale"></i>
                        <span>Arbitration Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php" class="active">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
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
        <main class="main-content">
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
                                <?php foreach ($hearings as $hearing): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($hearing['case_number']); ?></div>
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
                                                        title="Edit Hearing">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="viewHearingDetails(<?php echo $hearing['id']; ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="confirmDelete(<?php echo $hearing['id']; ?>, '<?php echo htmlspecialchars($hearing['case_number']); ?>')"
                                                        title="Delete Hearing">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Mobile Cards -->
                        <div class="hearing-cards">
                            <?php foreach ($hearings as $hearing): ?>
                                <div class="hearing-card">
                                    <div class="hearing-card-header">
                                        <div>
                                            <div class="hearing-card-title"><?php echo htmlspecialchars($hearing['case_number']); ?></div>
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
                                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($hearing)); ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-success btn-sm" 
                                                onclick="viewHearingDetails(<?php echo $hearing['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn btn-danger btn-sm" 
                                                onclick="confirmDelete(<?php echo $hearing['id']; ?>, '<?php echo htmlspecialchars($hearing['case_number']); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
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
                                               name="attendees[]" value="<?php echo $member['id']; ?>">
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
                            <label for="edit_status">Status *</label>
                            <select class="form-control" id="edit_status" name="status" required>
                                <option value="scheduled">Scheduled</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                                <option value="postponed">Postponed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
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
                            <label for="edit_decisions">Decisions & Outcomes</label>
                            <textarea class="form-control" id="edit_decisions" name="decisions" rows="4"></textarea>
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
            document.getElementById('edit_status').value = hearingData.status || 'scheduled';
            document.getElementById('edit_next_hearing_date').value = hearingData.next_hearing_date ? 
                hearingData.next_hearing_date.replace(' ', 'T').substr(0, 16) : '';
            document.getElementById('edit_purpose').value = hearingData.purpose || '';
            document.getElementById('edit_minutes').value = hearingData.minutes || '';
            document.getElementById('edit_decisions').value = hearingData.decisions || '';
            
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

        function viewHearingDetails(hearingId) {
            window.location.href = `hearing_details.php?id=${hearingId}`;
        }

        function confirmDelete(hearingId, caseNumber) {
            if (confirm(`Are you sure you want to delete the hearing for case ${caseNumber}? This action cannot be undone.`)) {
                window.location.href = `hearings.php?delete_hearing=${hearingId}`;
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const scheduleModal = document.getElementById('scheduleModal');
            const editModal = document.getElementById('editModal');
            
            if (event.target === scheduleModal) {
                closeScheduleModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
        }

        // Set minimum datetime for scheduling to current time
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            document.getElementById('hearing_date').min = localDateTime;
        });

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
    </script>
</body>
</html>