<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Environment & Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_environment') {
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
    error_log("User profile error: " . $e->getMessage());
    $user = [];
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
                INSERT INTO sports_facilities 
                (name, type, location, capacity, description, equipment_included, booking_requirements, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?)
            ");
            $stmt->execute([$name, $type, $location, $capacity, $description, $equipment_included, $booking_requirements, $user_id]);
            $success_message = "Facility added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding facility: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_facility'])) {
        // Update facility
        $facility_id = $_POST['facility_id'];
        $name = $_POST['name'];
        $type = $_POST['type'];
        $location = $_POST['location'];
        $capacity = $_POST['capacity'];
        $description = $_POST['description'];
        $equipment_included = $_POST['equipment_included'];
        $booking_requirements = $_POST['booking_requirements'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE sports_facilities 
                SET name = ?, type = ?, location = ?, capacity = ?, description = ?, 
                    equipment_included = ?, booking_requirements = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $location, $capacity, $description, $equipment_included, $booking_requirements, $status, $facility_id]);
            $success_message = "Facility updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating facility: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_status'])) {
        // Update facility status only
        $facility_id = $_POST['facility_id'];
        $status = $_POST['status'];
        $maintenance_notes = $_POST['maintenance_notes'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE sports_facilities SET status = ? WHERE id = ?");
            $stmt->execute([$status, $facility_id]);
            $success_message = "Facility status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating facility status: " . $e->getMessage();
        }
    }
}

// Handle facility deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM sports_facilities WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success_message = "Facility deleted successfully!";
    } catch (PDOException $e) {
        $error_message = "Error deleting facility: " . $e->getMessage();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for facilities
$query = "SELECT * FROM sports_facilities WHERE 1=1";
$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

if ($type_filter !== 'all') {
    $query .= " AND type = ?";
    $params[] = $type_filter;
}

if (!empty($search_term)) {
    $query .= " AND (name LIKE ? OR location LIKE ? OR description LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY name ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Facilities query error: " . $e->getMessage());
    $facilities = [];
}

// Get statistics
try {
    // Total facilities
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sports_facilities");
    $total_facilities = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Facilities by status
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM sports_facilities GROUP BY status");
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Facilities by type
    $stmt = $pdo->query("SELECT type, COUNT(*) as count FROM sports_facilities GROUP BY type");
    $type_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active bookings
    $stmt = $pdo->query("
        SELECT COUNT(*) as active_bookings 
        FROM facility_bookings 
        WHERE booking_date >= CURDATE() 
        AND status IN ('approved', 'pending')
    ");
    $active_bookings = $stmt->fetch(PDO::FETCH_ASSOC)['active_bookings'] ?? 0;
    
} catch (PDOException $e) {
    $total_facilities = 0;
    $status_counts = [];
    $type_counts = [];
    $active_bookings = 0;
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

// Facility types for dropdown
$facility_types = [
    'field' => 'Field',
    'court' => 'Court',
    'track' => 'Track',
    'gym' => 'Gym',
    'pool' => 'Swimming Pool',
    'hall' => 'Hall',
    'other' => 'Other'
];

// Facility statuses
$facility_statuses = [
    'available' => 'Available',
    'maintenance' => 'Under Maintenance',
    'occupied' => 'Occupied',
    'closed' => 'Closed'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Facilities - Minister of Environment & Security</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #4caf50;
            --accent-green: #2e7d32;
            --light-green: #e8f5e9;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-green: #4caf50;
            --secondary-green: #66bb6a;
            --accent-green: #388e3c;
            --light-green: #1b5e20;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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
            padding: 1.25rem;
            margin-bottom: 1.5rem;
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
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
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
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        /* Facilities Grid */
        .facilities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .facility-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .facility-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .facility-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .facility-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .facility-status {
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
            background: #cce7ff;
            color: var(--primary-green);
        }

        .status-closed {
            background: #f8d7da;
            color: var(--danger);
        }

        .facility-body {
            padding: 1.25rem;
        }

        .facility-info {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .info-value {
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .facility-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            line-height: 1.4;
            margin-bottom: 1rem;
        }

        .facility-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 4px;
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

        .btn-info {
            background: #17a2b8;
            color: white;
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

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .facilities-grid {
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
            
            .facilities-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .filters-form {
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
                    <h1>Isonga - Environment & Security</h1>
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
                        <div class="user-role">Minister of Environment & Security</div>
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
    <a href="tickets.php">
        <i class="fas fa-ticket-alt"></i>
        <span>Student Tickets</span>
        <?php 
        // Get pending tickets count for this minister
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_tickets 
                FROM tickets 
                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
            ");
            $stmt->execute([$user_id]);
            $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
            
            if ($pending_tickets > 0): ?>
                <span class="menu-badge"><?php echo $pending_tickets; ?></span>
            <?php endif;
        } catch (PDOException $e) {
            // Skip badge if error
        }
        ?>
    </a>
</li>
                <li class="menu-item">
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php" class="active">
                        <i class="fas fa-building"></i>
                        <span>Campus Facilities</span>
                        <span class="menu-badge"><?php echo $total_facilities; ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="security.php">
                        <i class="fas fa-shield-alt"></i>
                        <span>Security</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Environmental Clubs</span>
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
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
                    <h1>Campus Facilities Management</h1>
                    <p>Manage campus facilities, track availability, and handle maintenance requests</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-primary" onclick="openAddFacilityModal()">
                        <i class="fas fa-plus"></i> Add New Facility
                    </button>
                </div>
            </div>

            <!-- Alerts -->
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
                <?php 
                $available_count = 0;
                $maintenance_count = 0;
                foreach ($status_counts as $status) {
                    if ($status['status'] === 'available') {
                        $available_count = $status['count'];
                    } elseif ($status['status'] === 'maintenance') {
                        $maintenance_count = $status['count'];
                    }
                }
                ?>
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
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_bookings; ?></div>
                        <div class="stat-label">Active Bookings</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Search Facilities</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, location or description..." value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                            <option value="occupied" <?php echo $status_filter === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Facility Type</label>
                        <select name="type" class="form-control">
                            <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <?php foreach ($facility_types as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Facilities Grid -->
            <div class="facilities-grid">
                <?php if (empty($facilities)): ?>
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-building"></i>
                        <h3>No Facilities Found</h3>
                        <p>No campus facilities match your current filters.</p>
                        <button class="btn btn-primary" onclick="openAddFacilityModal()">
                            <i class="fas fa-plus"></i> Add New Facility
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($facilities as $facility): ?>
                        <div class="facility-card">
                            <div class="facility-header">
                                <h3 class="facility-title"><?php echo htmlspecialchars($facility['name']); ?></h3>
                                <span class="facility-status status-<?php echo $facility['status']; ?>">
                                    <?php echo $facility_statuses[$facility['status']]; ?>
                                </span>
                            </div>
                            <div class="facility-body">
                                <div class="facility-info">
                                    <div class="info-item">
                                        <span class="info-label">Type:</span>
                                        <span class="info-value"><?php echo $facility_types[$facility['type']] ?? 'Other'; ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Location:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($facility['location']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Capacity:</span>
                                        <span class="info-value"><?php echo $facility['capacity'] ? $facility['capacity'] . ' people' : 'Not specified'; ?></span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($facility['description'])): ?>
                                    <div class="facility-description">
                                        <strong>Description:</strong><br>
                                        <?php echo htmlspecialchars($facility['description']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($facility['equipment_included'])): ?>
                                    <div class="facility-description">
                                        <strong>Equipment:</strong><br>
                                        <?php echo htmlspecialchars($facility['equipment_included']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="facility-actions">
                                    <button class="btn btn-sm btn-info" onclick="viewFacility(<?php echo $facility['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="editFacility(<?php echo $facility['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteFacility(<?php echo $facility['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add Facility Modal -->
    <div id="addFacilityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Campus Facility</h3>
                <button class="modal-close" onclick="closeAddFacilityModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Facility Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Facility Type *</label>
                            <select name="type" class="form-control" required>
                                <option value="">Select Type</option>
                                <?php foreach ($facility_types as $key => $value): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity" class="form-control" placeholder="Number of people">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required placeholder="Building, room number, or area">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" placeholder="Describe the facility and its features..."></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Equipment Included</label>
                            <textarea name="equipment_included" class="form-control" placeholder="List available equipment..."></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Booking Requirements</label>
                            <textarea name="booking_requirements" class="form-control" placeholder="Any special requirements for booking..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddFacilityModal()">Cancel</button>
                    <button type="submit" name="add_facility" class="btn btn-primary">Add Facility</button>
                </div>
            </form>
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
        function openAddFacilityModal() {
            document.getElementById('addFacilityModal').style.display = 'flex';
        }

        function closeAddFacilityModal() {
            document.getElementById('addFacilityModal').style.display = 'none';
        }

        function viewFacility(facilityId) {
            alert('View facility details for ID: ' + facilityId);
            // Implement view functionality - could show booking history, maintenance records, etc.
        }

        function editFacility(facilityId) {
            alert('Edit facility with ID: ' + facilityId);
            // Implement edit functionality - could open a pre-filled modal
        }

        function deleteFacility(facilityId) {
            if (confirm('Are you sure you want to delete this facility? This action cannot be undone.')) {
                window.location.href = 'facilities.php?delete_id=' + facilityId;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('addFacilityModal');
            if (event.target === modal) {
                closeAddFacilityModal();
            }
        }
    </script>
</body>
</html>