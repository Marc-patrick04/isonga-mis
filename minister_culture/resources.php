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
    if (isset($_POST['add_resource'])) {
        // Add new cultural resource
        $resource_type = $_POST['resource_type'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $category = $_POST['category'];
        $quantity = $_POST['quantity'];
        $condition = $_POST['condition'];
        $location = $_POST['location'];
        $purchase_date = $_POST['purchase_date'];
        $purchase_price = $_POST['purchase_price'];
        $supplier = $_POST['supplier'];
        $maintenance_schedule = $_POST['maintenance_schedule'];
        $status = $_POST['status'];
        $notes = $_POST['notes'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO cultural_resources 
                (resource_type, name, description, category, quantity, available_quantity, 
                 condition, location, purchase_date, purchase_price, supplier, 
                 maintenance_schedule, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $resource_type, $name, $description, $category, $quantity, $quantity,
                $condition, $location, $purchase_date, $purchase_price, $supplier,
                $maintenance_schedule, $status, $notes, $user_id
            ]);
            
            $success_message = "Cultural resource added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding resource: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_resource'])) {
        // Update resource
        $resource_id = $_POST['resource_id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $category = $_POST['category'];
        $quantity = $_POST['quantity'];
        $condition = $_POST['condition'];
        $location = $_POST['location'];
        $status = $_POST['status'];
        $notes = $_POST['notes'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE cultural_resources 
                SET name = ?, description = ?, category = ?, quantity = ?, 
                    condition = ?, location = ?, status = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $category, $quantity, $condition, 
                $location, $status, $notes, $resource_id
            ]);
            
            $success_message = "Resource updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating resource: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_maintenance'])) {
        // Add maintenance record
        $resource_id = $_POST['resource_id'];
        $maintenance_type = $_POST['maintenance_type'];
        $description = $_POST['description'];
        $performed_by = $_POST['performed_by'];
        $cost = $_POST['cost'];
        $maintenance_date = $_POST['maintenance_date'];
        $next_maintenance_date = $_POST['next_maintenance_date'];
        $status = $_POST['status'];
        $notes = $_POST['notes'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO resource_maintenance 
                (resource_id, maintenance_type, description, performed_by, cost, 
                 maintenance_date, next_maintenance_date, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $resource_id, $maintenance_type, $description, $performed_by, $cost,
                $maintenance_date, $next_maintenance_date, $status, $notes, $user_id
            ]);
            
            // Update resource's next maintenance date if provided
            if ($next_maintenance_date) {
                $stmt = $pdo->prepare("
                    UPDATE cultural_resources 
                    SET next_maintenance = ?, last_maintenance = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$next_maintenance_date, $maintenance_date, $resource_id]);
            }
            
            $success_message = "Maintenance record added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding maintenance record: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_booking_status'])) {
        // Update booking status
        $booking_id = $_POST['booking_id'];
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            if ($status === 'approved') {
                $stmt = $pdo->prepare("
                    UPDATE resource_bookings 
                    SET status = ?, approved_by = ?, approved_at = NOW(), notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $user_id, $notes, $booking_id]);
                
                // Update available quantity
                $stmt = $pdo->prepare("
                    UPDATE cultural_resources cr
                    JOIN resource_bookings rb ON cr.id = rb.resource_id
                    SET cr.available_quantity = cr.available_quantity - rb.quantity
                    WHERE rb.id = ? AND rb.status = 'approved'
                ");
                $stmt->execute([$booking_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE resource_bookings 
                    SET status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $notes, $booking_id]);
            }
            
            $success_message = "Booking status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating booking: " . $e->getMessage();
        }
    }
}

// Get resource statistics
try {
    // Total resources
    $stmt = $pdo->query("SELECT COUNT(*) as total_resources FROM cultural_resources");
    $total_resources = $stmt->fetch(PDO::FETCH_ASSOC)['total_resources'] ?? 0;
    
    // Available resources
    $stmt = $pdo->query("SELECT COUNT(*) as available_resources FROM cultural_resources WHERE status = 'available'");
    $available_resources = $stmt->fetch(PDO::FETCH_ASSOC)['available_resources'] ?? 0;
    
    // Resources needing maintenance
    $stmt = $pdo->query("SELECT COUNT(*) as maintenance_resources FROM cultural_resources WHERE status = 'maintenance' OR condition IN ('poor', 'needs_replacement')");
    $maintenance_resources = $stmt->fetch(PDO::FETCH_ASSOC)['maintenance_resources'] ?? 0;
    
    // Pending bookings
    $stmt = $pdo->query("SELECT COUNT(*) as pending_bookings FROM resource_bookings WHERE status = 'pending'");
    $pending_bookings = $stmt->fetch(PDO::FETCH_ASSOC)['pending_bookings'] ?? 0;
    
    // Total resource value
    $stmt = $pdo->query("SELECT SUM(purchase_price * quantity) as total_value FROM cultural_resources");
    $total_value = $stmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;
    
} catch (PDOException $e) {
    $total_resources = $available_resources = $maintenance_resources = $pending_bookings = $total_value = 0;
    error_log("Error fetching resource statistics: " . $e->getMessage());
}

// Get resources based on filter
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $query = "SELECT * FROM cultural_resources WHERE 1=1";
    $params = [];
    
    // Apply filters
    if ($filter === 'available') {
        $query .= " AND status = 'available'";
    } elseif ($filter === 'maintenance') {
        $query .= " AND (status = 'maintenance' OR condition IN ('poor', 'needs_replacement'))";
    } elseif ($filter === 'equipment') {
        $query .= " AND resource_type = 'equipment'";
    } elseif ($filter === 'instruments') {
        $query .= " AND resource_type = 'instrument'";
    } elseif ($filter === 'costumes') {
        $query .= " AND resource_type = 'costume'";
    }
    
    // Apply search
    if (!empty($search)) {
        $query .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $query .= " ORDER BY 
        CASE 
            WHEN status = 'available' THEN 1
            WHEN status = 'in_use' THEN 2
            WHEN status = 'maintenance' THEN 3
            ELSE 4
        END,
        name ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $resources = [];
    error_log("Error fetching resources: " . $e->getMessage());
}

// Get pending bookings
try {
    $stmt = $pdo->prepare("
        SELECT rb.*, cr.name as resource_name, cr.resource_type, 
               u.full_name as student_name, u.reg_number
        FROM resource_bookings rb
        JOIN cultural_resources cr ON rb.resource_id = cr.id
        JOIN users u ON rb.booked_by = u.id
        WHERE rb.status = 'pending'
        ORDER BY rb.booking_date ASC, rb.start_time ASC
    ");
    $stmt->execute();
    $pending_bookings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pending_bookings_list = [];
    error_log("Error fetching pending bookings: " . $e->getMessage());
}

// Get active bookings
try {
    $stmt = $pdo->prepare("
        SELECT rb.*, cr.name as resource_name, cr.resource_type, 
               u.full_name as student_name, u.reg_number
        FROM resource_bookings rb
        JOIN cultural_resources cr ON rb.resource_id = cr.id
        JOIN users u ON rb.booked_by = u.id
        WHERE rb.status IN ('approved', 'active')
        ORDER BY rb.booking_date ASC, rb.start_time ASC
    ");
    $stmt->execute();
    $active_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $active_bookings = [];
    error_log("Error fetching active bookings: " . $e->getMessage());
}

// Get maintenance history
try {
    $stmt = $pdo->prepare("
        SELECT rm.*, cr.name as resource_name, cr.resource_type
        FROM resource_maintenance rm
        JOIN cultural_resources cr ON rm.resource_id = cr.id
        ORDER BY rm.maintenance_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $maintenance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $maintenance_history = [];
    error_log("Error fetching maintenance history: " . $e->getMessage());
}

// Get resource details if viewing a specific resource
$resource_details = null;
$resource_bookings = [];
$resource_maintenance = [];

if (isset($_GET['view_resource']) && is_numeric($_GET['view_resource'])) {
    $resource_id = $_GET['view_resource'];
    
    try {
        // Get resource details
        $stmt = $pdo->prepare("SELECT * FROM cultural_resources WHERE id = ?");
        $stmt->execute([$resource_id]);
        $resource_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resource_details) {
            // Get resource bookings
            $stmt = $pdo->prepare("
                SELECT rb.*, u.full_name as student_name, u.reg_number
                FROM resource_bookings rb
                JOIN users u ON rb.booked_by = u.id
                WHERE rb.resource_id = ?
                ORDER BY rb.booking_date DESC, rb.start_time DESC
                LIMIT 10
            ");
            $stmt->execute([$resource_id]);
            $resource_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get resource maintenance
            $stmt = $pdo->prepare("
                SELECT * FROM resource_maintenance 
                WHERE resource_id = ? 
                ORDER BY maintenance_date DESC
                LIMIT 10
            ");
            $stmt->execute([$resource_id]);
            $resource_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching resource details: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cultural Resources Management - Minister of Culture</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
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

        .status-in_use {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-maintenance {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-retired {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .condition-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .condition-excellent {
            background: #d4edda;
            color: var(--success);
        }

        .condition-good {
            background: #cce7ff;
            color: var(--primary-purple);
        }

        .condition-fair {
            background: #fff3cd;
            color: var(--warning);
        }

        .condition-poor {
            background: #f8d7da;
            color: var(--danger);
        }

        .condition-needs_replacement {
            background: #dc3545;
            color: white;
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

        /* Resource Grid */
        .resource-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .resource-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .resource-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .resource-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .resource-type {
            font-size: 0.7rem;
            color: var(--dark-gray);
            text-transform: uppercase;
            font-weight: 600;
        }

        .resource-body {
            padding: 1rem;
        }

        .resource-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .resource-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .resource-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .resource-footer {
            padding: 1rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .resource-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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
            
            .resource-grid {
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
            
            .resource-meta {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Cultural Resources</h1>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php" >
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php" class="active">
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
                    <h1>Cultural Resources Management</h1>
                    <p>Manage equipment, instruments, costumes, and other cultural resources</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addResourceModal')">
                        <i class="fas fa-plus"></i> Add Resource
                    </button>
                    <button class="btn btn-secondary" onclick="openModal('addMaintenanceModal')">
                        <i class="fas fa-tools"></i> Record Maintenance
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

            <!-- Resource Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_resources; ?></div>
                        <div class="stat-label">Total Resources</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $available_resources; ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $maintenance_resources; ?></div>
                        <div class="stat-label">Needs Maintenance</div>
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

            <!-- Search and Filter -->
            <div class="search-filter">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control" placeholder="Search resources..." 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           onkeypress="if(event.keyCode==13) searchResources()">
                </div>
                <select class="form-control filter-select" onchange="filterResources(this.value)">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Resources</option>
                    <option value="available" <?php echo $filter === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="maintenance" <?php echo $filter === 'maintenance' ? 'selected' : ''; ?>>Needs Maintenance</option>
                    <option value="equipment" <?php echo $filter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                    <option value="instruments" <?php echo $filter === 'instruments' ? 'selected' : ''; ?>>Instruments</option>
                    <option value="costumes" <?php echo $filter === 'costumes' ? 'selected' : ''; ?>>Costumes</option>
                </select>
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('resources-tab')">Resources Inventory</button>
                <button class="tab" onclick="openTab('bookings-tab')">
                    Pending Bookings
                    <?php if ($pending_bookings > 0): ?>
                        <span style="background: var(--danger); color: white; border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; margin-left: 0.5rem;"><?php echo $pending_bookings; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab" onclick="openTab('maintenance-tab')">Maintenance History</button>
                <?php if (isset($_GET['view_resource'])): ?>
                    <button class="tab" onclick="openTab('resource-details-tab')">Resource Details</button>
                <?php endif; ?>
            </div>

            <!-- Resources Inventory Tab -->
            <div id="resources-tab" class="tab-content active">
                <?php if (empty($resources)): ?>
                    <div class="card">
                        <div class="card-body" style="text-align: center; padding: 3rem;">
                            <i class="fas fa-box-open" style="font-size: 4rem; color: var(--dark-gray); margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3 style="color: var(--dark-gray); margin-bottom: 0.5rem;">No Resources Found</h3>
                            <p style="color: var(--dark-gray);">Get started by adding your first cultural resource.</p>
                            <button class="btn btn-primary" onclick="openModal('addResourceModal')" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Add First Resource
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="resource-grid">
                        <?php foreach ($resources as $resource): ?>
                            <div class="resource-card">
                                <div class="resource-header">
                                    <span class="resource-type"><?php echo ucfirst($resource['resource_type']); ?></span>
                                    <span class="status-badge status-<?php echo $resource['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $resource['status'])); ?>
                                    </span>
                                </div>
                                <div class="resource-body">
                                    <h3 class="resource-name"><?php echo htmlspecialchars($resource['name']); ?></h3>
                                    <p class="resource-description"><?php echo htmlspecialchars($resource['description']); ?></p>
                                    <div class="resource-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Quantity</span>
                                            <span class="meta-value"><?php echo $resource['quantity']; ?> units</span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Available</span>
                                            <span class="meta-value"><?php echo $resource['available_quantity']; ?> units</span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Condition</span>
                                            <span class="condition-badge condition-<?php echo $resource['condition']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $resource['condition'])); ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Location</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($resource['location']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="resource-footer">
                                    <span class="meta-value" style="font-size: 0.8rem;">
                                        <?php if ($resource['purchase_price'] > 0): ?>
                                            RWF <?php echo number_format($resource['purchase_price'], 2); ?>
                                        <?php else: ?>
                                            Not priced
                                        <?php endif; ?>
                                    </span>
                                    <div class="action-buttons">
                                        <a href="?view_resource=<?php echo $resource['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pending Bookings Tab -->
            <div id="bookings-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Pending Resource Bookings</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_bookings_list)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No pending bookings at the moment.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Resource</th>
                                        <th>Student</th>
                                        <th>Purpose</th>
                                        <th>Date & Time</th>
                                        <th>Quantity</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_bookings_list as $booking): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($booking['resource_name']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo ucfirst($booking['resource_type']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($booking['student_name']); ?>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo htmlspecialchars($booking['reg_number']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($booking['purpose']); ?></td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                                <small><?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?></small>
                                            </td>
                                            <td><?php echo $booking['quantity']; ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                        <input type="hidden" name="status" value="approved">
                                                        <button type="submit" name="update_booking_status" class="btn btn-success btn-sm">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                        <input type="hidden" name="status" value="rejected">
                                                        <button type="submit" name="update_booking_status" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Active Bookings -->
                <div class="card">
                    <div class="card-header">
                        <h3>Active Bookings</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($active_bookings)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <p>No active bookings at the moment.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Resource</th>
                                        <th>Student</th>
                                        <th>Purpose</th>
                                        <th>Date & Time</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_bookings as $booking): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($booking['resource_name']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($booking['student_name']); ?>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo htmlspecialchars($booking['reg_number']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($booking['purpose']); ?></td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                                <small><?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                    <?php echo ucfirst($booking['status']); ?>
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

            <!-- Maintenance History Tab -->
            <div id="maintenance-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Maintenance History</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($maintenance_history)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-tools" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No maintenance records found.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Resource</th>
                                        <th>Maintenance Type</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <th>Cost</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($maintenance_history as $maintenance): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($maintenance['resource_name']); ?></td>
                                            <td><?php echo ucfirst($maintenance['maintenance_type']); ?></td>
                                            <td><?php echo htmlspecialchars($maintenance['description']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($maintenance['maintenance_date'])); ?></td>
                                            <td>
                                                <?php if ($maintenance['cost'] > 0): ?>
                                                    RWF <?php echo number_format($maintenance['cost'], 2); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $maintenance['status']; ?>">
                                                    <?php echo ucfirst($maintenance['status']); ?>
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

            <!-- Resource Details Tab -->
            <?php if (isset($_GET['view_resource']) && $resource_details): ?>
                <div id="resource-details-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Resource Details: <?php echo htmlspecialchars($resource_details['name']); ?></h3>
                            <a href="resources.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Resources
                            </a>
                        </div>
                        <div class="card-body">
                            <!-- Resource Information -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Resource Information</h3>
                                </div>
                                <div class="card-body">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Resource Name</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($resource_details['name']); ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Resource Type</label>
                                            <input type="text" class="form-control" value="<?php echo ucfirst($resource_details['resource_type']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($resource_details['description']); ?></textarea>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Category</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($resource_details['category']); ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Location</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($resource_details['location']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Total Quantity</label>
                                            <input type="text" class="form-control" value="<?php echo $resource_details['quantity']; ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Available Quantity</label>
                                            <input type="text" class="form-control" value="<?php echo $resource_details['available_quantity']; ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Condition</label>
                                            <input type="text" class="form-control" value="<?php echo ucfirst(str_replace('_', ' ', $resource_details['condition'])); ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Status</label>
                                            <input type="text" class="form-control" value="<?php echo ucfirst(str_replace('_', ' ', $resource_details['status'])); ?>" readonly>
                                        </div>
                                    </div>
                                    <?php if ($resource_details['purchase_date']): ?>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Purchase Date</label>
                                                <input type="text" class="form-control" value="<?php echo date('M j, Y', strtotime($resource_details['purchase_date'])); ?>" readonly>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Purchase Price</label>
                                                <input type="text" class="form-control" value="RWF <?php echo number_format($resource_details['purchase_price'], 2); ?>" readonly>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($resource_details['supplier']): ?>
                                        <div class="form-group">
                                            <label class="form-label">Supplier</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($resource_details['supplier']); ?>" readonly>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($resource_details['maintenance_schedule']): ?>
                                        <div class="form-group">
                                            <label class="form-label">Maintenance Schedule</label>
                                            <textarea class="form-control" rows="2" readonly><?php echo htmlspecialchars($resource_details['maintenance_schedule']); ?></textarea>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($resource_details['notes']): ?>
                                        <div class="form-group">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($resource_details['notes']); ?></textarea>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Update Resource Form -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Update Resource Information</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="resource_id" value="<?php echo $resource_details['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Resource Name *</label>
                                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($resource_details['name']); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Category</label>
                                                <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($resource_details['category']); ?>">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($resource_details['description']); ?></textarea>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Total Quantity *</label>
                                                <input type="number" name="quantity" class="form-control" value="<?php echo $resource_details['quantity']; ?>" required min="1">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Location</label>
                                                <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($resource_details['location']); ?>">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Condition</label>
                                                <select name="condition" class="form-control">
                                                    <option value="excellent" <?php echo $resource_details['condition'] === 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                                    <option value="good" <?php echo $resource_details['condition'] === 'good' ? 'selected' : ''; ?>>Good</option>
                                                    <option value="fair" <?php echo $resource_details['condition'] === 'fair' ? 'selected' : ''; ?>>Fair</option>
                                                    <option value="poor" <?php echo $resource_details['condition'] === 'poor' ? 'selected' : ''; ?>>Poor</option>
                                                    <option value="needs_replacement" <?php echo $resource_details['condition'] === 'needs_replacement' ? 'selected' : ''; ?>>Needs Replacement</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-control">
                                                    <option value="available" <?php echo $resource_details['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                                    <option value="in_use" <?php echo $resource_details['status'] === 'in_use' ? 'selected' : ''; ?>>In Use</option>
                                                    <option value="maintenance" <?php echo $resource_details['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                                    <option value="retired" <?php echo $resource_details['status'] === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($resource_details['notes']); ?></textarea>
                                        </div>
                                        <div class="form-group" style="text-align: right;">
                                            <button type="submit" name="update_resource" class="btn btn-primary">Update Resource</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Resource Bookings -->
                            <?php if (!empty($resource_bookings)): ?>
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Recent Bookings</h3>
                                    </div>
                                    <div class="card-body">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Student</th>
                                                    <th>Purpose</th>
                                                    <th>Date & Time</th>
                                                    <th>Quantity</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($resource_bookings as $booking): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo htmlspecialchars($booking['student_name']); ?>
                                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                                <?php echo htmlspecialchars($booking['reg_number']); ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($booking['purpose']); ?></td>
                                                        <td>
                                                            <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                                            <small><?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?></small>
                                                        </td>
                                                        <td><?php echo $booking['quantity']; ?></td>
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

                            <!-- Resource Maintenance -->
                            <?php if (!empty($resource_maintenance)): ?>
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Maintenance History</h3>
                                    </div>
                                    <div class="card-body">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Description</th>
                                                    <th>Date</th>
                                                    <th>Cost</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($resource_maintenance as $maintenance): ?>
                                                    <tr>
                                                        <td><?php echo ucfirst($maintenance['maintenance_type']); ?></td>
                                                        <td><?php echo htmlspecialchars($maintenance['description']); ?></td>
                                                        <td><?php echo date('M j, Y', strtotime($maintenance['maintenance_date'])); ?></td>
                                                        <td>
                                                            <?php if ($maintenance['cost'] > 0): ?>
                                                                RWF <?php echo number_format($maintenance['cost'], 2); ?>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $maintenance['status']; ?>">
                                                                <?php echo ucfirst($maintenance['status']); ?>
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

    <!-- Add Resource Modal -->
    <div id="addResourceModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Add New Cultural Resource</h3>
                <button class="icon-btn" onclick="closeModal('addResourceModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Resource Type *</label>
                            <select name="resource_type" class="form-control" required>
                                <option value="equipment">Equipment</option>
                                <option value="instrument">Instrument</option>
                                <option value="costume">Costume</option>
                                <option value="art_supply">Art Supply</option>
                                <option value="document">Document</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Resource Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g., Musical, Dance, Art">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" value="1" required min="1">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Condition</label>
                            <select name="condition" class="form-control">
                                <option value="excellent">Excellent</option>
                                <option value="good" selected>Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                                <option value="needs_replacement">Needs Replacement</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g., Storage Room A, Drama Department">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Purchase Price (RWF)</label>
                            <input type="number" name="purchase_price" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Supplier</label>
                            <input type="text" name="supplier" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="available" selected>Available</option>
                                <option value="in_use">In Use</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="retired">Retired</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Maintenance Schedule</label>
                        <textarea name="maintenance_schedule" class="form-control" rows="2" placeholder="e.g., Every 6 months, Before each performance"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addResourceModal')">Cancel</button>
                        <button type="submit" name="add_resource" class="btn btn-primary">Add Resource</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Maintenance Modal -->
    <div id="addMaintenanceModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Record Maintenance</h3>
                <button class="icon-btn" onclick="closeModal('addMaintenanceModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Resource *</label>
                        <select name="resource_id" class="form-control" required>
                            <option value="">Select Resource</option>
                            <?php foreach ($resources as $resource): ?>
                                <option value="<?php echo $resource['id']; ?>">
                                    <?php echo htmlspecialchars($resource['name']); ?> (<?php echo ucfirst($resource['resource_type']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Maintenance Type *</label>
                            <select name="maintenance_type" class="form-control" required>
                                <option value="routine">Routine</option>
                                <option value="repair">Repair</option>
                                <option value="cleaning">Cleaning</option>
                                <option value="inspection">Inspection</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Maintenance Date *</label>
                            <input type="date" name="maintenance_date" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control" rows="3" required placeholder="Describe the maintenance performed..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Performed By</label>
                            <input type="text" name="performed_by" class="form-control" placeholder="e.g., Technician name or company">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cost (RWF)</label>
                            <input type="number" name="cost" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Next Maintenance Date</label>
                            <input type="date" name="next_maintenance_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="completed" selected>Completed</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="in_progress">In Progress</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addMaintenanceModal')">Cancel</button>
                        <button type="submit" name="add_maintenance" class="btn btn-primary">Record Maintenance</button>
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

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Search and Filter Functions
        function searchResources() {
            const search = document.querySelector('.search-box input').value;
            const filter = document.querySelector('.filter-select').value;
            let url = 'resources.php?';
            
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (filter !== 'all') url += `filter=${filter}`;
            
            window.location.href = url;
        }

        function filterResources(filter) {
            const search = document.querySelector('.search-box input').value;
            let url = 'resources.php?';
            
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (filter !== 'all') url += `filter=${filter}`;
            
            window.location.href = url;
        }

        function clearFilters() {
            window.location.href = 'resources.php';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Auto-refresh for pending bookings count
        setInterval(() => {
            // You can implement auto-refresh for pending bookings count here
            console.log('Auto-refresh resources');
        }, 300000); // 5 minutes
    </script>
</body>
</html>