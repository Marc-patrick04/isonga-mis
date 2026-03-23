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

// Handle form actions
$action = $_GET['action'] ?? '';
$facility_id = $_GET['id'] ?? '';

// Add new facility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    try {
        $name = $_POST['name'];
        $type = $_POST['type'];
        $location = $_POST['location'];
        $capacity = $_POST['capacity'] ?? null;
        $description = $_POST['description'];
        $equipment_included = $_POST['equipment_included'];
        $booking_requirements = $_POST['booking_requirements'];
        
        $stmt = $pdo->prepare("
            INSERT INTO sports_facilities 
            (name, type, location, capacity, description, equipment_included, booking_requirements, created_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'available')
        ");
        $stmt->execute([$name, $type, $location, $capacity, $description, $equipment_included, $booking_requirements, $user_id]);
        
        $_SESSION['success_message'] = "Facility created successfully!";
        header('Location: facilities.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error creating facility: " . $e->getMessage();
    }
}

// Update facility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    try {
        $name = $_POST['name'];
        $type = $_POST['type'];
        $location = $_POST['location'];
        $capacity = $_POST['capacity'] ?? null;
        $description = $_POST['description'];
        $equipment_included = $_POST['equipment_included'];
        $booking_requirements = $_POST['booking_requirements'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("
            UPDATE sports_facilities 
            SET name = ?, type = ?, location = ?, capacity = ?, description = ?, 
                equipment_included = ?, booking_requirements = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $location, $capacity, $description, $equipment_included, $booking_requirements, $status, $facility_id]);
        
        $_SESSION['success_message'] = "Facility updated successfully!";
        header('Location: facilities.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating facility: " . $e->getMessage();
    }
}

// Delete facility
if ($action === 'delete' && $facility_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM sports_facilities WHERE id = ?");
        $stmt->execute([$facility_id]);
        
        $_SESSION['success_message'] = "Facility deleted successfully!";
        header('Location: facilities.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting facility: " . $e->getMessage();
    }
}

// Update facility status
if ($action === 'update_status' && $facility_id) {
    try {
        $status = $_GET['status'];
        $stmt = $pdo->prepare("UPDATE sports_facilities SET status = ? WHERE id = ?");
        $stmt->execute([$status, $facility_id]);
        
        $_SESSION['success_message'] = "Facility status updated successfully!";
        header('Location: facilities.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating facility status: " . $e->getMessage();
    }
}

// Get facilities data
try {
    // All facilities
    $stmt = $pdo->query("
        SELECT sf.*, u.full_name as created_by_name,
               COUNT(fb.id) as booking_count
        FROM sports_facilities sf
        LEFT JOIN users u ON sf.created_by = u.id
        LEFT JOIN facility_bookings fb ON sf.id = fb.facility_id AND fb.status = 'approved'
        GROUP BY sf.id
        ORDER BY sf.name ASC
    ");
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Facility types
    $facility_types = ['field', 'court', 'track', 'gym', 'pool', 'hall', 'other'];
    
    // Status types
    $status_types = ['available', 'maintenance', 'occupied', 'closed'];
    
    // Get facility for editing
    if ($action === 'edit' && $facility_id) {
        $stmt = $pdo->prepare("SELECT * FROM sports_facilities WHERE id = ?");
        $stmt->execute([$facility_id]);
        $edit_facility = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get facility for viewing
    if ($action === 'view' && $facility_id) {
        $stmt = $pdo->prepare("
            SELECT sf.*, u.full_name as created_by_name
            FROM sports_facilities sf
            LEFT JOIN users u ON sf.created_by = u.id
            WHERE sf.id = ?
        ");
        $stmt->execute([$facility_id]);
        $current_facility = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get upcoming bookings for this facility
        $stmt = $pdo->prepare("
            SELECT fb.*, u.full_name as booked_by_name
            FROM facility_bookings fb
            LEFT JOIN users u ON fb.booked_by = u.id
            WHERE fb.facility_id = ? AND fb.booking_date >= CURDATE() AND fb.status = 'approved'
            ORDER BY fb.booking_date ASC, fb.start_time ASC
            LIMIT 10
        ");
        $stmt->execute([$facility_id]);
        $upcoming_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Statistics
    $total_facilities = count($facilities);
    $available_facilities = array_filter($facilities, function($facility) {
        return $facility['status'] === 'available';
    });
    $maintenance_facilities = array_filter($facilities, function($facility) {
        return $facility['status'] === 'maintenance';
    });
    $occupied_facilities = array_filter($facilities, function($facility) {
        return $facility['status'] === 'occupied';
    });
    
    $available_count = count($available_facilities);
    $maintenance_count = count($maintenance_facilities);
    $occupied_count = count($occupied_facilities);
    
    // Facility type distribution
    $type_distribution = [];
    foreach ($facilities as $facility) {
        $type = $facility['type'];
        if (!isset($type_distribution[$type])) {
            $type_distribution[$type] = 0;
        }
        $type_distribution[$type]++;
    }
    
} catch (PDOException $e) {
    error_log("Facilities data error: " . $e->getMessage());
    $facilities = [];
    $facility_types = $status_types = [];
    $total_facilities = $available_count = $maintenance_count = $occupied_count = 0;
    $type_distribution = [];
}

// Unread messages count
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
    <title>Sports Facilities Management - Isonga RPSU</title>
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
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
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
            border-radius: 6px;
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
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

        /* Card */
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

        .table tr:hover {
            background: var(--light-gray);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-available {
            background: #d4edda;
            color: var(--success);
        }

        .status-maintenance {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-occupied {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-closed {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .action-btn {
            padding: 0.25rem 0.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .action-btn.view {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .action-btn.edit {
            background: #fff3cd;
            color: var(--warning);
        }

        .action-btn.delete {
            background: #f8d7da;
            color: var(--danger);
        }

        .action-btn.status {
            background: #d4edda;
            color: var(--success);
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        /* Facility Cards */
        .facility-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .facility-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border-left: 4px solid var(--primary-blue);
        }

        .facility-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .facility-card.available {
            border-left-color: var(--success);
        }

        .facility-card.maintenance {
            border-left-color: var(--warning);
        }

        .facility-card.occupied {
            border-left-color: var(--danger);
        }

        .facility-card.closed {
            border-left-color: var(--dark-gray);
        }

        .facility-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .facility-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .facility-type {
            font-size: 0.8rem;
            color: var(--dark-gray);
            background: var(--light-blue);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            display: inline-block;
            text-transform: capitalize;
        }

        .facility-status {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
        }

        .facility-body {
            padding: 1rem;
        }

        .facility-info {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .info-item i {
            width: 16px;
            color: var(--dark-gray);
        }

        .facility-description {
            font-size: 0.8rem;
            color: var(--dark-gray);
            line-height: 1.4;
            margin-bottom: 1rem;
        }

        .facility-features {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .feature-tag {
            background: var(--light-gray);
            color: var(--dark-gray);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .facility-footer {
            padding: 1rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .booking-count {
            font-size: 0.8rem;
            color: var(--dark-gray);
            display: flex;
            align-items: center;
            gap: 0.25rem;
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

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Alerts */
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

        .modal.show {
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

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Type Distribution */
        .type-distribution {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .type-item {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            transition: var(--transition);
        }

        .type-item:hover {
            background: var(--light-blue);
            transform: translateY(-2px);
        }

        .type-name {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
            text-transform: capitalize;
        }

        .type-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .facility-cards {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
            
            .facility-cards {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .tabs {
                overflow-x: auto;
            }
            
            .type-distribution {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
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
            
            .page-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
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
                    <a href="facilities.php" class="active">
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
                    <a href="training.php">
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
                <div class="page-title">
                    <h1>Sports Facilities Management 🏟️</h1>
                    <p>Manage all sports facilities, availability, and bookings</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addFacilityModal')">
                        <i class="fas fa-plus"></i> Add New Facility
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
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_facilities; ?></div>
                        <div class="stat-label">Total Facilities</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $available_count; ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $maintenance_count; ?></div>
                        <div class="stat-label">Under Maintenance</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $occupied_count; ?></div>
                        <div class="stat-label">Currently Occupied</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('cards')">Facility Cards</button>
                <button class="tab" onclick="switchTab('table')">Table View</button>
                <button class="tab" onclick="switchTab('types')">Type Distribution</button>
            </div>

            <!-- Facility Cards View -->
            <div id="cards" class="tab-content active">
                <div class="facility-cards">
                    <?php if (empty($facilities)): ?>
                        <div style="text-align: center; color: var(--dark-gray); padding: 3rem; grid-column: 1 / -1;">
                            <i class="fas fa-building" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No Sports Facilities Found</h3>
                            <p>Get started by adding your first sports facility.</p>
                            <button class="btn btn-primary" onclick="openModal('addFacilityModal')" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Add First Facility
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($facilities as $facility): ?>
                            <div class="facility-card <?php echo $facility['status']; ?>">
                                <div class="facility-header">
                                    <div>
                                        <div class="facility-title"><?php echo htmlspecialchars($facility['name']); ?></div>
                                        <span class="facility-type"><?php echo htmlspecialchars($facility['type']); ?></span>
                                    </div>
                                    <span class="status-badge status-<?php echo $facility['status']; ?>">
                                        <?php echo ucfirst($facility['status']); ?>
                                    </span>
                                </div>
                                <div class="facility-body">
                                    <div class="facility-info">
                                        <div class="info-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($facility['location']); ?></span>
                                        </div>
                                        <?php if ($facility['capacity']): ?>
                                            <div class="info-item">
                                                <i class="fas fa-users"></i>
                                                <span>Capacity: <?php echo $facility['capacity']; ?> people</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="info-item">
                                            <i class="fas fa-calendar-check"></i>
                                            <span><?php echo $facility['booking_count']; ?> approved bookings</span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($facility['description']): ?>
                                        <div class="facility-description">
                                            <?php echo htmlspecialchars($facility['description']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($facility['equipment_included']): ?>
                                        <div class="facility-features">
                                            <span class="feature-tag">
                                                <i class="fas fa-tools"></i>
                                                Equipment: <?php echo htmlspecialchars($facility['equipment_included']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="facility-footer">
                                    <div class="booking-count">
                                        <i class="fas fa-history"></i>
                                        Added: <?php echo date('M j, Y', strtotime($facility['created_at'])); ?>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="facilities.php?action=view&id=<?php echo $facility['id']; ?>" class="action-btn view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="facilities.php?action=edit&id=<?php echo $facility['id']; ?>" class="action-btn edit" title="Edit Facility">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn status" onclick="updateStatus(<?php echo $facility['id']; ?>)" title="Update Status">
                                            <i class="fas fa-sync"></i>
                                        </button>
                                        <button class="action-btn delete" onclick="confirmDelete(<?php echo $facility['id']; ?>)" title="Delete Facility">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Table View -->
            <div id="table" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>All Sports Facilities</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($facilities)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <i class="fas fa-building" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Sports Facilities Found</h3>
                                <p>Get started by adding your first sports facility.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Facility Name</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Capacity</th>
                                        <th>Status</th>
                                        <th>Bookings</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($facilities as $facility): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($facility['name']); ?></strong>
                                                <?php if ($facility['description']): ?>
                                                    <br><small style="color: var(--dark-gray);"><?php echo htmlspecialchars(substr($facility['description'], 0, 50)); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="text-transform: capitalize;"><?php echo htmlspecialchars($facility['type']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($facility['location']); ?></td>
                                            <td><?php echo $facility['capacity'] ? $facility['capacity'] . ' people' : 'N/A'; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $facility['status']; ?>">
                                                    <?php echo ucfirst($facility['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-weight: 600; color: var(--primary-blue);">
                                                    <?php echo $facility['booking_count']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($facility['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="facilities.php?action=view&id=<?php echo $facility['id']; ?>" class="action-btn view" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="facilities.php?action=edit&id=<?php echo $facility['id']; ?>" class="action-btn edit" title="Edit Facility">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button class="action-btn status" onclick="updateStatus(<?php echo $facility['id']; ?>)" title="Update Status">
                                                        <i class="fas fa-sync"></i>
                                                    </button>
                                                    <button class="action-btn delete" onclick="confirmDelete(<?php echo $facility['id']; ?>)" title="Delete Facility">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Type Distribution View -->
            <div id="types" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Facility Type Distribution</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($type_distribution)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <p>No facility type data available</p>
                            </div>
                        <?php else: ?>
                            <div class="type-distribution">
                                <?php foreach ($type_distribution as $type => $count): ?>
                                    <div class="type-item">
                                        <div class="type-name"><?php echo $type; ?></div>
                                        <div class="type-count"><?php echo $count; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Facility Modal -->
    <div class="modal" id="addFacilityModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Sports Facility</h3>
                <button class="modal-close" onclick="closeModal('addFacilityModal')">&times;</button>
            </div>
            <form method="POST" action="facilities.php?action=add">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="name">Facility Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required placeholder="e.g., Main Football Field">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="type">Facility Type *</label>
                            <select class="form-select" id="type" name="type" required>
                                <option value="">Select Facility Type</option>
                                <?php foreach ($facility_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="capacity">Capacity</label>
                            <input type="number" class="form-control" id="capacity" name="capacity" placeholder="e.g., 100">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="location">Location *</label>
                        <input type="text" class="form-control" id="location" name="location" required placeholder="e.g., Main Campus, Near Library">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control form-textarea" id="description" name="description" placeholder="Brief description of the facility..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="equipment_included">Equipment Included</label>
                        <input type="text" class="form-control" id="equipment_included" name="equipment_included" placeholder="e.g., Goals, Nets, Benches">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="booking_requirements">Booking Requirements</label>
                        <textarea class="form-control form-textarea" id="booking_requirements" name="booking_requirements" placeholder="Any special requirements for booking this facility..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addFacilityModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Facility</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Facility Status</h3>
                <button class="modal-close" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Select new status for this facility:</p>
                <div class="form-group">
                    <select class="form-select" id="newStatus">
                        <?php foreach ($status_types as $status): ?>
                            <option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitStatusUpdate()">Update Status</button>
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

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // Tab Switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Activate clicked tab
            event.currentTarget.classList.add('active');
        }

        // Status Update
        let currentFacilityId = null;

        function updateStatus(facilityId) {
            currentFacilityId = facilityId;
            openModal('statusModal');
        }

        function submitStatusUpdate() {
            if (currentFacilityId) {
                const newStatus = document.getElementById('newStatus').value;
                window.location.href = `facilities.php?action=update_status&id=${currentFacilityId}&status=${newStatus}`;
            }
        }

        // Delete Confirmation
        function confirmDelete(facilityId) {
            if (confirm('Are you sure you want to delete this facility? This action cannot be undone.')) {
                window.location.href = 'facilities.php?action=delete&id=' + facilityId;
            }
        }

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(tooltip => {
                tooltip.addEventListener('mouseenter', function(e) {
                    // You can add custom tooltip implementation here if needed
                });
            });
        });
    </script>
</body>
</html>