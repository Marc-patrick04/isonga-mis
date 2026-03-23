
<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
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
    if (isset($_POST['add_facility'])) {
        // Add new facility
        $name = $_POST['name'];
        $type = $_POST['type'];
        $location = $_POST['location'];
        $capacity = $_POST['capacity'];
        $description = $_POST['description'];
        $equipment_included = $_POST['equipment_included'];
        $booking_requirements = $_POST['booking_requirements'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO sports_facilities (name, type, location, capacity, description, equipment_included, booking_requirements, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $type, $location, $capacity, $description,
                $equipment_included, $booking_requirements, $user_id
            ]);
            
            $success_message = "Facility added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding facility: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['book_facility'])) {
        // Book facility
        $facility_id = $_POST['facility_id'];
        $purpose = $_POST['purpose'];
        $booking_date = $_POST['booking_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $participants_count = $_POST['participants_count'];
        $equipment_needed = json_encode(explode(',', $_POST['equipment_needed']));
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO facility_bookings (facility_id, booked_by, purpose, booking_date, start_time, end_time, participants_count, equipment_needed, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $facility_id, $user_id, $purpose, $booking_date, $start_time,
                $end_time, $participants_count, $equipment_needed
            ]);
            
            $success_message = "Facility booking request submitted successfully!";
        } catch (PDOException $e) {
            $error_message = "Error booking facility: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_booking_status'])) {
        // Update booking status
        $booking_id = $_POST['booking_id'];
        $status = $_POST['status'];
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE facility_bookings 
                SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $rejection_reason, $user_id, $booking_id]);
            
            $success_message = "Booking status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating booking: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_facility_status'])) {
        // Update facility status
        $facility_id = $_POST['facility_id'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE sports_facilities SET status = ? WHERE id = ?");
            $stmt->execute([$status, $facility_id]);
            $success_message = "Facility status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating facility status: " . $e->getMessage();
        }
    }
}

// Get facilities statistics
try {
    // Total facilities
    $stmt = $pdo->query("SELECT COUNT(*) as total_facilities FROM sports_facilities");
    $total_facilities = $stmt->fetch(PDO::FETCH_ASSOC)['total_facilities'] ?? 0;
    
    // Available facilities
    $stmt = $pdo->query("SELECT COUNT(*) as available_facilities FROM sports_facilities WHERE status = 'available'");
    $available_facilities = $stmt->fetch(PDO::FETCH_ASSOC)['available_facilities'] ?? 0;
    
    // Under maintenance
    $stmt = $pdo->query("SELECT COUNT(*) as maintenance_facilities FROM sports_facilities WHERE status = 'maintenance'");
    $maintenance_facilities = $stmt->fetch(PDO::FETCH_ASSOC)['maintenance_facilities'] ?? 0;
    
    // Pending bookings
    $stmt = $pdo->query("SELECT COUNT(*) as pending_bookings FROM facility_bookings WHERE status = 'pending'");
    $pending_bookings = $stmt->fetch(PDO::FETCH_ASSOC)['pending_bookings'] ?? 0;
    
    // Cultural facilities (halls, courts for cultural activities)
    $stmt = $pdo->query("SELECT COUNT(*) as cultural_facilities FROM sports_facilities WHERE type IN ('hall', 'court')");
    $cultural_facilities = $stmt->fetch(PDO::FETCH_ASSOC)['cultural_facilities'] ?? 0;
    
} catch (PDOException $e) {
    $total_facilities = $available_facilities = $maintenance_facilities = $pending_bookings = $cultural_facilities = 0;
    error_log("Error fetching facilities statistics: " . $e->getMessage());
}

// Get all facilities
try {
    $stmt = $pdo->query("
        SELECT sf.*, 
               COUNT(fb.id) as total_bookings,
               SUM(CASE WHEN fb.status = 'pending' THEN 1 ELSE 0 END) as pending_bookings_count
        FROM sports_facilities sf
        LEFT JOIN facility_bookings fb ON sf.id = fb.facility_id
        GROUP BY sf.id
        ORDER BY sf.name
    ");
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $facilities = [];
    error_log("Error fetching facilities: " . $e->getMessage());
}

// Get facility bookings
$filter = $_GET['filter'] ?? 'all';
try {
    $query = "
        SELECT fb.*, sf.name as facility_name, sf.location, u.full_name as booked_by_name
        FROM facility_bookings fb
        JOIN sports_facilities sf ON fb.facility_id = sf.id
        JOIN users u ON fb.booked_by = u.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Apply filters
    if ($filter === 'pending') {
        $query .= " AND fb.status = 'pending'";
    } elseif ($filter === 'approved') {
        $query .= " AND fb.status = 'approved'";
    } elseif ($filter === 'rejected') {
        $query .= " AND fb.status = 'rejected'";
    } elseif ($filter === 'my_bookings') {
        $query .= " AND fb.booked_by = ?";
        $params[] = $user_id;
    }
    
    $query .= " ORDER BY fb.booking_date DESC, fb.start_time DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $bookings = [];
    error_log("Error fetching bookings: " . $e->getMessage());
}

// Get facility details if viewing a specific facility
$facility_details = null;
$facility_bookings = [];
$facility_availability = [];

if (isset($_GET['view_facility']) && is_numeric($_GET['view_facility'])) {
    $facility_id = $_GET['view_facility'];
    
    try {
        // Get facility details
        $stmt = $pdo->prepare("SELECT * FROM sports_facilities WHERE id = ?");
        $stmt->execute([$facility_id]);
        $facility_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($facility_details) {
            // Get facility bookings
            $stmt = $pdo->prepare("
                SELECT fb.*, u.full_name as booked_by_name
                FROM facility_bookings fb
                JOIN users u ON fb.booked_by = u.id
                WHERE fb.facility_id = ? AND fb.booking_date >= CURDATE()
                ORDER BY fb.booking_date ASC, fb.start_time ASC
            ");
            $stmt->execute([$facility_id]);
            $facility_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get availability for next 7 days
            $stmt = $pdo->prepare("
                SELECT 
                    DATE_ADD(CURDATE(), INTERVAL n DAY) as date,
                    DAYNAME(DATE_ADD(CURDATE(), INTERVAL n DAY)) as day_name,
                    (SELECT COUNT(*) FROM facility_bookings 
                     WHERE facility_id = ? 
                     AND booking_date = DATE_ADD(CURDATE(), INTERVAL n DAY)
                     AND status = 'approved') as booked_slots
                FROM (
                    SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 
                    UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
                ) days
                ORDER BY date
            ");
            $stmt->execute([$facility_id]);
            $facility_availability = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching facility details: " . $e->getMessage());
    }
}

// Get today's bookings for dashboard
try {
    $stmt = $pdo->prepare("
        SELECT fb.*, sf.name as facility_name, u.full_name as booked_by_name
        FROM facility_bookings fb
        JOIN sports_facilities sf ON fb.facility_id = sf.id
        JOIN users u ON fb.booked_by = u.id
        WHERE fb.booking_date = CURDATE() AND fb.status = 'approved'
        ORDER BY fb.start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $todays_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $todays_bookings = [];
    error_log("Error fetching today's bookings: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facilities Management - Minister of Culture</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #A78BFA;
            --accent-purple: #7C3AED;
            --light-purple: #f3f0ff;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-purple: #A78BFA;
            --secondary-purple: #C4B5FD;
            --accent-purple: #8B5CF6;
            --light-purple: #1f1a2e;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
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

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        /* Cards */
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

        .status-pending {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-approved {
            background: #d4edda;
            color: var(--success);
        }

        .status-rejected {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-completed {
            background: #cce7ff;
            color: var(--primary-purple);
        }

        .status-cancelled {
            background: #e9ecef;
            color: var(--dark-gray);
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
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            border-bottom: 2px solid transparent;
        }

        .tab.active {
            color: var(--primary-purple);
            border-bottom-color: var(--primary-purple);
        }

        .tab:hover {
            color: var(--primary-purple);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
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
            border-left: 3px solid var(--primary-purple);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.success {
            border-left-color: var(--success);
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
            background: var(--light-purple);
            color: var(--primary-purple);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
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

        /* Search and Filter */
        .search-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            padding-left: 2.5rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }

        .filter-select {
            min-width: 150px;
        }

        /* Facility Cards */
        .facilities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .facility-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .facility-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .facility-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-purple);
        }

        .facility-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .facility-type {
            font-size: 0.8rem;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .facility-body {
            padding: 1.25rem;
        }

        .facility-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .facility-description {
            color: var(--dark-gray);
            font-size: 0.85rem;
            line-height: 1.4;
            margin-bottom: 1rem;
        }

        .facility-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Availability Calendar */
        .availability-calendar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .calendar-day {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 0.75rem;
            text-align: center;
            transition: var(--transition);
        }

        .calendar-day.available {
            background: #d4edda;
            border-color: var(--success);
        }

        .calendar-day.booked {
            background: #f8d7da;
            border-color: var(--danger);
        }

        .calendar-day.partial {
            background: #fff3cd;
            border-color: var(--warning);
        }

        .day-name {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .day-date {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .day-status {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 600;
        }

        /* Booking Timeline */
        .booking-timeline {
            margin-top: 1rem;
        }

        .timeline-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            background: var(--white);
        }

        .timeline-time {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 80px;
        }

        .timeline-content {
            flex: 1;
            margin-left: 1rem;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .timeline-desc {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .facilities-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .search-filter {
                flex-direction: column;
                align-items: stretch;
            }
            
            .facilities-grid {
                grid-template-columns: 1fr;
            }
            
            .facility-meta {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .availability-calendar {
                grid-template-columns: repeat(2, 1fr);
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
                    <h1>Isonga - Facilities Management</h1>
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
                        <div class="user-role">Minister of Culture & Civic Education</div>
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
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="facilities.php" class="active">
                        <i class="fas fa-building"></i>
                        <span>Facilities</span>
                        <?php if ($pending_bookings > 0): ?>
                            <span class="menu-badge"><?php echo $pending_bookings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php">
                        <i class="fas fa-palette"></i>
                        <span>Cultural Resources</span>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="troupe.php">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
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
            <div class="page-header">
                <div class="page-title">
                    <h1>Facilities Management</h1>
                    <p>Manage cultural facilities, bookings, and availability</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addFacilityModal')">
                        <i class="fas fa-plus"></i> Add Facility
                    </button>
                    <button class="btn btn-secondary" onclick="openModal('bookFacilityModal')">
                        <i class="fas fa-calendar-plus"></i> Book Facility
                    </button>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Facilities Statistics -->
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
                        <div class="stat-number"><?php echo $available_facilities; ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $maintenance_facilities; ?></div>
                        <div class="stat-label">Under Maintenance</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_bookings; ?></div>
                        <div class="stat-label">Pending Bookings</div>
                    </div>
                </div>
            </div>

            <!-- Today's Bookings -->
            <?php if (!empty($todays_bookings)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Today's Bookings</h3>
                    </div>
                    <div class="card-body">
                        <div class="booking-timeline">
                            <?php foreach ($todays_bookings as $booking): ?>
                                <div class="timeline-item">
                                    <div class="timeline-time">
                                        <?php echo date('g:i A', strtotime($booking['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title"><?php echo htmlspecialchars($booking['facility_name']); ?></div>
                                        <div class="timeline-desc">
                                            <?php echo htmlspecialchars($booking['purpose']); ?> • 
                                            Booked by: <?php echo htmlspecialchars($booking['booked_by_name']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('facilities-tab')">All Facilities</button>
                <button class="tab" onclick="openTab('bookings-tab')">Bookings Management</button>
                <?php if (isset($_GET['view_facility'])): ?>
                    <button class="tab" onclick="openTab('facility-details-tab')">Facility Details</button>
                <?php endif; ?>
            </div>

            <!-- Facilities Tab -->
            <div id="facilities-tab" class="tab-content active">
                <div class="search-filter">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="form-control" placeholder="Search facilities..." id="facilitySearch">
                    </div>
                    <select class="form-control filter-select" id="facilityFilter">
                        <option value="all">All Facilities</option>
                        <option value="available">Available</option>
                        <option value="maintenance">Under Maintenance</option>
                        <option value="cultural">Cultural Facilities</option>
                    </select>
                </div>

                <div class="facilities-grid" id="facilitiesContainer">
                    <?php if (empty($facilities)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray); grid-column: 1 / -1;">
                            <i class="fas fa-building" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No facilities found. Add your first facility to get started.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($facilities as $facility): ?>
                            <div class="facility-card" data-status="<?php echo $facility['status']; ?>" data-type="<?php echo $facility['type']; ?>">
                                <div class="facility-header">
                                    <div class="facility-name"><?php echo htmlspecialchars($facility['name']); ?></div>
                                    <div class="facility-type"><?php echo ucfirst($facility['type']); ?> • Capacity: <?php echo $facility['capacity']; ?> people</div>
                                </div>
                                <div class="facility-body">
                                    <div class="facility-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Location</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($facility['location']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Status</span>
                                            <span class="meta-value">
                                                <span class="status-badge status-<?php echo $facility['status']; ?>">
                                                    <?php echo ucfirst($facility['status']); ?>
                                                </span>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Total Bookings</span>
                                            <span class="meta-value"><?php echo $facility['total_bookings']; ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Pending</span>
                                            <span class="meta-value"><?php echo $facility['pending_bookings_count']; ?></span>
                                        </div>
                                    </div>
                                    <?php if ($facility['description']): ?>
                                        <div class="facility-description">
                                            <?php echo htmlspecialchars($facility['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($facility['equipment_included']): ?>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0.5rem;">
                                            <strong>Equipment:</strong> <?php echo htmlspecialchars($facility['equipment_included']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="facility-footer">
                                    <div>
                                        <a href="?view_facility=<?php echo $facility['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </div>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="facility_id" value="<?php echo $facility['id']; ?>">
                                        <select name="status" onchange="this.form.submit()" class="form-control" style="width: auto; display: inline-block; padding: 0.3rem; font-size: 0.75rem;">
                                            <option value="available" <?php echo $facility['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                            <option value="maintenance" <?php echo $facility['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                            <option value="occupied" <?php echo $facility['status'] === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                            <option value="closed" <?php echo $facility['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                        <input type="hidden" name="update_facility_status">
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bookings Management Tab -->
            <div id="bookings-tab" class="tab-content">
                <div class="search-filter">
                    <select class="form-control filter-select" onchange="filterBookings(this.value)">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Bookings</option>
                        <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="my_bookings" <?php echo $filter === 'my_bookings' ? 'selected' : ''; ?>>My Bookings</option>
                    </select>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Facility Bookings</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($bookings)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-calendar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No bookings found matching your criteria.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Facility</th>
                                        <th>Booked By</th>
                                        <th>Purpose</th>
                                        <th>Date & Time</th>
                                        <th>Participants</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($booking['facility_name']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($booking['location']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($booking['booked_by_name']); ?></td>
                                            <td><?php echo htmlspecialchars($booking['purpose']); ?></td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                                <small><?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?></small>
                                            </td>
                                            <td><?php echo $booking['participants_count']; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                    <?php echo ucfirst($booking['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($booking['status'] === 'pending'): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                            <input type="hidden" name="status" value="approved">
                                                            <button type="submit" name="update_booking_status" class="btn btn-success btn-sm">
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                        </form>
                                                        <button class="btn btn-danger btn-sm" onclick="openRejectionModal(<?php echo $booking['id']; ?>)">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    <?php endif; ?>
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

            <!-- Facility Details Tab -->
            <?php if (isset($_GET['view_facility']) && $facility_details): ?>
                <div id="facility-details-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><?php echo htmlspecialchars($facility_details['name']); ?> Details</h3>
                            <a href="facilities.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Facilities
                            </a>
                        </div>
                        <div class="card-body">
                            <!-- Facility Information -->
                            <div class="card" style="margin-bottom: 1.5rem;">
                                <div class="card-header">
                                    <h3>Facility Information</h3>
                                </div>
                                <div class="card-body">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Facility Name</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($facility_details['name']); ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Type</label>
                                            <input type="text" class="form-control" value="<?php echo ucfirst($facility_details['type']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Location</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($facility_details['location']); ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Capacity</label>
                                            <input type="text" class="form-control" value="<?php echo $facility_details['capacity']; ?> people" readonly>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($facility_details['description']); ?></textarea>
                                    </div>
                                    <?php if ($facility_details['equipment_included']): ?>
                                        <div class="form-group">
                                            <label class="form-label">Equipment Included</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($facility_details['equipment_included']); ?>" readonly>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($facility_details['booking_requirements']): ?>
                                        <div class="form-group">
                                            <label class="form-label">Booking Requirements</label>
                                            <textarea class="form-control" rows="2" readonly><?php echo htmlspecialchars($facility_details['booking_requirements']); ?></textarea>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Availability Calendar -->
                            <div class="card" style="margin-bottom: 1.5rem;">
                                <div class="card-header">
                                    <h3>7-Day Availability</h3>
                                </div>
                                <div class="card-body">
                                    <div class="availability-calendar">
                                        <?php foreach ($facility_availability as $day): ?>
                                            <div class="calendar-day <?php echo $day['booked_slots'] > 0 ? 'booked' : 'available'; ?>">
                                                <div class="day-name"><?php echo $day['day_name']; ?></div>
                                                <div class="day-date"><?php echo date('M j', strtotime($day['date'])); ?></div>
                                                <div class="day-status">
                                                    <?php echo $day['booked_slots'] > 0 ? 'Booked' : 'Available'; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Upcoming Bookings -->
                            <?php if (!empty($facility_bookings)): ?>
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Upcoming Bookings</h3>
                                    </div>
                                    <div class="card-body">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th>Booked By</th>
                                                    <th>Purpose</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($facility_bookings as $booking): ?>
                                                    <tr>
                                                        <td><?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></td>
                                                        <td>
                                                            <?php echo date('g:i A', strtotime($booking['start_time'])); ?> - 
                                                            <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($booking['booked_by_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($booking['purpose']); ?></td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                                <?php echo ucfirst($booking['status']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Facility Modal -->
    <div id="addFacilityModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--white); border-radius: var(--border-radius); width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3>Add New Facility</h3>
                <button class="icon-btn" onclick="closeModal('addFacilityModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Facility Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type *</label>
                            <select name="type" class="form-control" required>
                                <option value="hall">Hall</option>
                                <option value="court">Court</option>
                                <option value="field">Field</option>
                                <option value="gym">Gym</option>
                                <option value="pool">Pool</option>
                                <option value="track">Track</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Equipment Included</label>
                        <input type="text" name="equipment_included" class="form-control" placeholder="e.g., Sound system, projectors, chairs">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Booking Requirements</label>
                        <textarea name="booking_requirements" class="form-control" rows="2" placeholder="e.g., Advance notice, faculty approval"></textarea>
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addFacilityModal')">Cancel</button>
                        <button type="submit" name="add_facility" class="btn btn-primary">Add Facility</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Book Facility Modal -->
    <div id="bookFacilityModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--white); border-radius: var(--border-radius); width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3>Book Facility</h3>
                <button class="icon-btn" onclick="closeModal('bookFacilityModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Facility *</label>
                        <select name="facility_id" class="form-control" required>
                            <option value="">Select a facility</option>
                            <?php foreach ($facilities as $facility): ?>
                                <?php if ($facility['status'] === 'available'): ?>
                                    <option value="<?php echo $facility['id']; ?>">
                                        <?php echo htmlspecialchars($facility['name']); ?> - <?php echo htmlspecialchars($facility['location']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purpose *</label>
                        <textarea name="purpose" class="form-control" rows="3" placeholder="Describe the purpose of your booking..." required></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Booking Date *</label>
                            <input type="date" name="booking_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Participants Count</label>
                            <input type="number" name="participants_count" class="form-control" min="1">
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
                        <label class="form-label">Additional Equipment Needed</label>
                        <input type="text" name="equipment_needed" class="form-control" placeholder="e.g., Microphones, chairs, tables (comma separated)">
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('bookFacilityModal')">Cancel</button>
                        <button type="submit" name="book_facility" class="btn btn-primary">Submit Booking Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectionModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--white); border-radius: var(--border-radius); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3>Reject Booking Request</h3>
                <button class="icon-btn" onclick="closeModal('rejectionModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST" id="rejectionForm">
                    <input type="hidden" name="booking_id" id="rejectBookingId">
                    <input type="hidden" name="status" value="rejected">
                    <div class="form-group">
                        <label class="form-label">Reason for Rejection *</label>
                        <textarea name="rejection_reason" class="form-control" rows="4" placeholder="Please provide a reason for rejecting this booking request..." required></textarea>
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('rejectionModal')">Cancel</button>
                        <button type="submit" name="update_booking_status" class="btn btn-danger">Reject Booking</button>
                    </div>
                </form>
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
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function openRejectionModal(bookingId) {
            document.getElementById('rejectBookingId').value = bookingId;
            document.getElementById('rejectionModal').style.display = 'flex';
        }

        // Tab Functions
        function openTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Facility Search and Filter
        document.getElementById('facilitySearch').addEventListener('input', filterFacilities);
        document.getElementById('facilityFilter').addEventListener('change', filterFacilities);

        function filterFacilities() {
            const searchTerm = document.getElementById('facilitySearch').value.toLowerCase();
            const filterValue = document.getElementById('facilityFilter').value;
            const facilities = document.querySelectorAll('.facility-card');
            
            facilities.forEach(facility => {
                const name = facility.querySelector('.facility-name').textContent.toLowerCase();
                const status = facility.getAttribute('data-status');
                const type = facility.getAttribute('data-type');
                
                let matchesSearch = name.includes(searchTerm);
                let matchesFilter = filterValue === 'all' || 
                                  (filterValue === 'available' && status === 'available') ||
                                  (filterValue === 'maintenance' && status === 'maintenance') ||
                                  (filterValue === 'cultural' && (type === 'hall' || type === 'court'));
                
                if (matchesSearch && matchesFilter) {
                    facility.style.display = 'block';
                } else {
                    facility.style.display = 'none';
                }
            });
        }

        function filterBookings(filter) {
            window.location.href = `facilities.php?filter=${filter}`;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Set minimum time for booking (current time)
        const now = new Date();
        const currentTime = now.toTimeString().slice(0,5);
        document.querySelector('input[name="start_time"]').min = currentTime;
        document.querySelector('input[name="end_time"]').min = currentTime;
    </script>
</body>
</html>