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

// Create maintenance_requests table if it doesn't exist (PostgreSQL syntax)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maintenance_requests (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            facility_id INTEGER,
            facility_name VARCHAR(255),
            location VARCHAR(255) NOT NULL,
            issue_type VARCHAR(20) DEFAULT 'other' CHECK (issue_type IN ('electrical', 'plumbing', 'structural', 'furniture', 'equipment', 'cleaning', 'safety', 'other')),
            priority VARCHAR(10) DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'urgent')),
            reported_by VARCHAR(100) NOT NULL,
            reporter_contact VARCHAR(100),
            status VARCHAR(20) DEFAULT 'reported' CHECK (status IN ('reported', 'assigned', 'in_progress', 'completed', 'cancelled')),
            assigned_to INTEGER,
            estimated_cost DECIMAL(10,2) DEFAULT 0.00,
            actual_cost DECIMAL(10,2) DEFAULT 0.00,
            completion_notes TEXT,
            reported_by_user INTEGER,
            assigned_by INTEGER,
            completed_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            assigned_at TIMESTAMP,
            completed_at TIMESTAMP,
            due_date DATE
        )
    ");
} catch (PDOException $e) {
    error_log("Maintenance requests table creation error: " . $e->getMessage());
}

// Create maintenance_contractors table if it doesn't exist (PostgreSQL syntax)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maintenance_contractors (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100),
            phone VARCHAR(20),
            email VARCHAR(100),
            specialty VARCHAR(20) DEFAULT 'general' CHECK (specialty IN ('electrical', 'plumbing', 'carpentry', 'painting', 'cleaning', 'general')),
            hourly_rate DECIMAL(8,2) DEFAULT 0.00,
            status VARCHAR(10) DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    error_log("Maintenance contractors table creation error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_request'])) {
        // Add new maintenance request
        $title = $_POST['title'];
        $description = $_POST['description'];
        $location = $_POST['location'];
        $facility_name = $_POST['facility_name'];
        $issue_type = $_POST['issue_type'];
        $priority = $_POST['priority'];
        $reported_by = $_POST['reported_by'];
        $reporter_contact = $_POST['reporter_contact'];
        $due_date = $_POST['due_date'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO maintenance_requests 
                (title, description, location, facility_name, issue_type, priority, reported_by, reporter_contact, status, reported_by_user, due_date, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reported', ?, ?, NOW())
            ");
            $stmt->execute([$title, $description, $location, $facility_name, $issue_type, $priority, $reported_by, $reporter_contact, $user_id, $due_date]);
            $success_message = "Maintenance request submitted successfully!";
        } catch (PDOException $e) {
            $error_message = "Error submitting request: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_request'])) {
        // Update maintenance request
        $request_id = $_POST['request_id'];
        $status = $_POST['status'];
        $assigned_to = $_POST['assigned_to'];
        $estimated_cost = $_POST['estimated_cost'];
        $completion_notes = $_POST['completion_notes'];
        $actual_cost = $_POST['actual_cost'];
        
        try {
            if ($status === 'assigned' && $assigned_to) {
                $stmt = $pdo->prepare("
                    UPDATE maintenance_requests 
                    SET status = ?, assigned_to = ?, assigned_by = ?, assigned_at = NOW(), estimated_cost = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $assigned_to, $user_id, $estimated_cost, $request_id]);
            } elseif ($status === 'completed') {
                $stmt = $pdo->prepare("
                    UPDATE maintenance_requests 
                    SET status = ?, completed_by = ?, completed_at = NOW(), actual_cost = ?, completion_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $user_id, $actual_cost, $completion_notes, $request_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE maintenance_requests 
                    SET status = ?, estimated_cost = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $estimated_cost, $request_id]);
            }
            $success_message = "Maintenance request updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating request: " . $e->getMessage();
        }
    } elseif (isset($_POST['add_contractor'])) {
        // Add maintenance contractor
        $name = $_POST['contractor_name'];
        $contact_person = $_POST['contact_person'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $specialty = $_POST['specialty'];
        $hourly_rate = $_POST['hourly_rate'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO maintenance_contractors 
                (name, contact_person, phone, email, specialty, hourly_rate, status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())
            ");
            $stmt->execute([$name, $contact_person, $phone, $email, $specialty, $hourly_rate, $user_id]);
            $success_message = "Contractor added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding contractor: " . $e->getMessage();
        }
    }
}

// Handle request deletion
if (isset($_GET['delete_request'])) {
    $delete_id = $_GET['delete_request'];
    try {
        $stmt = $pdo->prepare("DELETE FROM maintenance_requests WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success_message = "Maintenance request deleted successfully!";
    } catch (PDOException $e) {
        $error_message = "Error deleting request: " . $e->getMessage();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for maintenance requests (PostgreSQL syntax with ILIKE)
$query = "
    SELECT mr.*, 
           u_assigned.full_name as assigned_to_name,
           u_reported.full_name as reported_by_name
    FROM maintenance_requests mr
    LEFT JOIN users u_assigned ON mr.assigned_to = u_assigned.id
    LEFT JOIN users u_reported ON mr.reported_by_user = u_reported.id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND mr.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $query .= " AND mr.priority = ?";
    $params[] = $priority_filter;
}

if ($type_filter !== 'all') {
    $query .= " AND mr.issue_type = ?";
    $params[] = $type_filter;
}

if (!empty($search_term)) {
    $query .= " AND (mr.title ILIKE ? OR mr.description ILIKE ? OR mr.location ILIKE ? OR mr.reported_by ILIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY 
    CASE mr.priority 
        WHEN 'urgent' THEN 1
        WHEN 'high' THEN 2
        WHEN 'medium' THEN 3
        WHEN 'low' THEN 4
    END,
    mr.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $maintenance_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Maintenance requests query error: " . $e->getMessage());
    $maintenance_requests = [];
}

// Get maintenance contractors
try {
    $stmt = $pdo->query("SELECT * FROM maintenance_contractors WHERE status = 'active' ORDER BY name");
    $contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $contractors = [];
}

// Get users for assignment (other committee members)
try {
    $stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE role IN ('minister_environment', 'vice_guild_finance', 'general_secretary')
        AND status = 'active'
        ORDER BY full_name
    ");
    $assignment_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assignment_users = [];
}

// Get statistics (PostgreSQL syntax)
try {
    // Total requests
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM maintenance_requests");
    $total_requests = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Requests by status
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM maintenance_requests GROUP BY status");
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Requests by priority
    $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM maintenance_requests GROUP BY priority");
    $priority_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pending requests (reported + assigned)
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM maintenance_requests WHERE status IN ('reported', 'assigned')");
    $pending_requests = $stmt->fetch(PDO::FETCH_ASSOC)['pending'] ?? 0;
    
    // Total estimated costs
    $stmt = $pdo->query("SELECT SUM(estimated_cost) as total_estimated FROM maintenance_requests WHERE status != 'cancelled'");
    $total_estimated = $stmt->fetch(PDO::FETCH_ASSOC)['total_estimated'] ?? 0;
    
    // Total actual costs
    $stmt = $pdo->query("SELECT SUM(actual_cost) as total_actual FROM maintenance_requests WHERE status = 'completed'");
    $total_actual = $stmt->fetch(PDO::FETCH_ASSOC)['total_actual'] ?? 0;
    
} catch (PDOException $e) {
    $total_requests = 0;
    $status_counts = [];
    $priority_counts = [];
    $pending_requests = 0;
    $total_estimated = 0;
    $total_actual = 0;
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

// Get pending tickets count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE assigned_to = ? AND status IN ('open', 'in_progress')
    ");
    $stmt->execute([$user_id]);
    $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
} catch (PDOException $e) {
    $pending_tickets = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Maintenance Management - Minister of Environment & Security</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
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
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
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

        /* Dashboard Header */
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

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
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

        /* Tabs */
        .tabs-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .tabs {
            display: flex;
            background: var(--white);
            border-bottom: 1px solid var(--medium-gray);
            overflow-x: auto;
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--dark-gray);
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .tab.active {
            color: var(--primary-green);
            border-bottom-color: var(--primary-green);
        }

        .tab:hover {
            color: var(--primary-green);
            background: var(--light-green);
        }

        /* Content Sections */
        .content-section {
            display: none;
            padding: 1.25rem;
        }

        .content-section.active {
            display: block;
        }

        /* Filters */
        .filters-card {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            font-size: 0.75rem;
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

        select.form-control {
            cursor: pointer;
        }

        /* Buttons */
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 4px;
        }

        /* Table */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow-x: auto;
        }

        .section-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .section-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
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
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-reported {
            background: #fff3cd;
            color: #856404;
        }

        .status-assigned {
            background: #cce7ff;
            color: #004085;
        }

        .status-in_progress {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #e2e3e5;
            color: #383d41;
        }

        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-urgent, .priority-high {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }

        .priority-low {
            background: #d4edda;
            color: #155724;
        }

        .type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #e2e3e5;
            color: #383d41;
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        /* Contractors Grid */
        .contractors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
        }

        .contractor-card {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            border-left: 4px solid var(--primary-green);
            transition: var(--transition);
        }

        .contractor-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .contractor-card h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .contractor-card p {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .contractor-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        /* Cost Display */
        .cost-display {
            font-weight: 600;
            color: var(--success);
        }

        .cost-estimated {
            color: var(--warning);
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
            border-radius: var(--border-radius-lg);
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
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--danger);
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
            margin-bottom: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.8rem;
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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

            .form-row {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .contractors-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
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

            .welcome-section h1 {
                font-size: 1.2rem;
            }

            .table th, .table td {
                padding: 0.5rem;
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
                    <h1>Isonga - Environment & Security</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Student Tickets</span>
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="security.php">
                        <i class="fas fa-shield-alt"></i>
                        <span>Security</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php" class="active">
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Campus Maintenance Management 🔧</h1>
                    <p>Manage maintenance requests, track repairs, and coordinate with contractors</p>
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
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_requests; ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_requests; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_estimated, 0); ?></div>
                        <div class="stat-label">Estimated Costs</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">RWF <?php echo number_format($total_actual, 0); ?></div>
                        <div class="stat-label">Actual Costs</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab active" onclick="showTab('requests')">
                        <i class="fas fa-clipboard-list"></i> Maintenance Requests
                    </button>
                    <button class="tab" onclick="showTab('contractors')">
                        <i class="fas fa-hard-hat"></i> Contractors
                    </button>
                    <button class="tab" onclick="showTab('reports')">
                        <i class="fas fa-chart-bar"></i> Maintenance Reports
                    </button>
                </div>

                <!-- Requests Tab -->
                <div id="requests-tab" class="content-section active">
                    <!-- Filters -->
                    <div class="filters-card">
                        <form method="GET" class="filters-form">
                            <div class="form-group">
                                <label class="form-label">Search Requests</label>
                                <input type="text" name="search" class="form-control" placeholder="Search by title, location, or description..." value="<?php echo htmlspecialchars($search_term); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="reported" <?php echo $status_filter === 'reported' ? 'selected' : ''; ?>>Reported</option>
                                    <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-control">
                                    <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priority</option>
                                    <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Issue Type</label>
                                <select name="type" class="form-control">
                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="electrical" <?php echo $type_filter === 'electrical' ? 'selected' : ''; ?>>Electrical</option>
                                    <option value="plumbing" <?php echo $type_filter === 'plumbing' ? 'selected' : ''; ?>>Plumbing</option>
                                    <option value="structural" <?php echo $type_filter === 'structural' ? 'selected' : ''; ?>>Structural</option>
                                    <option value="furniture" <?php echo $type_filter === 'furniture' ? 'selected' : ''; ?>>Furniture</option>
                                    <option value="equipment" <?php echo $type_filter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                    <option value="cleaning" <?php echo $type_filter === 'cleaning' ? 'selected' : ''; ?>>Cleaning</option>
                                    <option value="safety" <?php echo $type_filter === 'safety' ? 'selected' : ''; ?>>Safety</option>
                                    <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="maintenance.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Requests Table -->
                    <div class="table-container">
                        <div class="section-header">
                            <h3>Maintenance Requests (<?php echo count($maintenance_requests); ?>)</h3>
                            <button class="btn btn-primary btn-sm" onclick="openAddRequestModal()">
                                <i class="fas fa-plus"></i> New Request
                            </button>
                        </div>
                        <?php if (empty($maintenance_requests)): ?>
                            <div class="empty-state">
                                <i class="fas fa-tools"></i>
                                <h3>No Maintenance Requests Found</h3>
                                <p>No maintenance requests match your current filters.</p>
                                <button class="btn btn-primary" onclick="openAddRequestModal()">
                                    <i class="fas fa-plus"></i> Create First Request
                                </button>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Request Title</th>
                                            <th>Location</th>
                                            <th>Type</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Cost</th>
                                            <th>Reported By</th>
                                            <th>Due Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($maintenance_requests as $request): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($request['title']); ?></strong>
                                                    <?php if (strlen($request['description']) > 100): ?>
                                                        <br><small><?php echo htmlspecialchars(substr($request['description'], 0, 100)) . '...'; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['location']); ?>
                                                    <?php if ($request['facility_name']): ?>
                                                        <br><small><?php echo htmlspecialchars($request['facility_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="type-badge">
                                                        <?php echo ucfirst($request['issue_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                        <?php echo ucfirst($request['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                                        <?php echo str_replace('_', ' ', ucfirst($request['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($request['actual_cost'] > 0): ?>
                                                        <span class="cost-display">RWF <?php echo number_format($request['actual_cost'], 0); ?></span>
                                                    <?php elseif ($request['estimated_cost'] > 0): ?>
                                                        <span class="cost-estimated">RWF <?php echo number_format($request['estimated_cost'], 0); ?></span>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['reported_by']); ?>
                                                    <?php if ($request['reporter_contact']): ?>
                                                        <br><small><?php echo htmlspecialchars($request['reporter_contact']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($request['due_date']): ?>
                                                        <?php echo date('M j, Y', strtotime($request['due_date'])); ?>
                                                        <?php 
                                                        $due_date = new DateTime($request['due_date']);
                                                        $today = new DateTime();
                                                        if ($due_date < $today && $request['status'] != 'completed'): ?>
                                                            <br><small style="color: var(--danger);">Overdue</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-info btn-sm" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-warning btn-sm" onclick="updateRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="deleteRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contractors Tab -->
                <div id="contractors-tab" class="content-section">
                    <div class="section-header">
                        <h3>Maintenance Contractors</h3>
                        <button class="btn btn-primary btn-sm" onclick="openAddContractorModal()">
                            <i class="fas fa-plus"></i> Add Contractor
                        </button>
                    </div>
                    <div class="contractors-grid">
                        <?php if (empty($contractors)): ?>
                            <div class="empty-state" style="grid-column: 1 / -1;">
                                <i class="fas fa-hard-hat"></i>
                                <h3>No Contractors</h3>
                                <p>No maintenance contractors have been added yet.</p>
                                <button class="btn btn-primary" onclick="openAddContractorModal()">
                                    <i class="fas fa-plus"></i> Add First Contractor
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($contractors as $contractor): ?>
                                <div class="contractor-card">
                                    <h4><?php echo htmlspecialchars($contractor['name']); ?></h4>
                                    <p><strong>Specialty:</strong> <?php echo ucfirst($contractor['specialty']); ?></p>
                                    <?php if ($contractor['contact_person']): ?>
                                        <p><strong>Contact:</strong> <?php echo htmlspecialchars($contractor['contact_person']); ?></p>
                                    <?php endif; ?>
                                    <div class="contractor-meta">
                                        <span>
                                            <i class="fas fa-phone"></i>
                                            <?php echo htmlspecialchars($contractor['phone']); ?>
                                        </span>
                                        <?php if ($contractor['hourly_rate'] > 0): ?>
                                            <span>
                                                <i class="fas fa-money-bill-wave"></i>
                                                RWF <?php echo number_format($contractor['hourly_rate'], 0); ?>/hr
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($contractor['email']): ?>
                                        <div class="contractor-meta">
                                            <span>
                                                <i class="fas fa-envelope"></i>
                                                <?php echo htmlspecialchars($contractor['email']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reports Tab -->
                <div id="reports-tab" class="content-section">
                    <div class="section-header">
                        <h3>Maintenance Reports & Analytics</h3>
                    </div>
                    <div class="filters-card">
                        <div style="text-align: center; padding: 1rem;">
                            <i class="fas fa-chart-bar" style="font-size: 2rem; color: var(--primary-green); margin-bottom: 1rem;"></i>
                            <h3 style="margin-bottom: 1rem;">Maintenance Analytics Summary</h3>
                            <div class="stats-grid" style="margin-top: 0;">
                                <div class="stat-card">
                                    <div class="stat-content">
                                        <div class="stat-number"><?php echo $total_requests; ?></div>
                                        <div class="stat-label">Total Requests</div>
                                    </div>
                                </div>
                                <div class="stat-card warning">
                                    <div class="stat-content">
                                        <div class="stat-number"><?php echo $pending_requests; ?></div>
                                        <div class="stat-label">Pending Resolution</div>
                                    </div>
                                </div>
                                <div class="stat-card success">
                                    <div class="stat-content">
                                        <div class="stat-number">RWF <?php echo number_format($total_estimated, 0); ?></div>
                                        <div class="stat-label">Total Estimated</div>
                                    </div>
                                </div>
                                <div class="stat-card danger">
                                    <div class="stat-content">
                                        <div class="stat-number">RWF <?php echo number_format($total_actual, 0); ?></div>
                                        <div class="stat-label">Total Actual</div>
                                    </div>
                                </div>
                            </div>
                            <div style="margin-top: 2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                                <div>
                                    <h4 style="margin-bottom: 1rem;">Requests by Status</h4>
                                    <div style="text-align: left;">
                                        <?php foreach ($status_counts as $status): ?>
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; padding: 0.25rem 0; border-bottom: 1px solid var(--medium-gray);">
                                                <span><?php echo ucfirst(str_replace('_', ' ', $status['status'])); ?></span>
                                                <strong><?php echo $status['count']; ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div>
                                    <h4 style="margin-bottom: 1rem;">Requests by Priority</h4>
                                    <div style="text-align: left;">
                                        <?php foreach ($priority_counts as $priority): ?>
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; padding: 0.25rem 0; border-bottom: 1px solid var(--medium-gray);">
                                                <span><?php echo ucfirst($priority['priority']); ?></span>
                                                <strong><?php echo $priority['count']; ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Request Modal -->
    <div id="addRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Maintenance Request</h3>
                <button class="modal-close" onclick="closeAddRequestModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Request Title *</label>
                            <input type="text" name="title" class="form-control" required placeholder="Brief description of the maintenance needed">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Issue Type *</label>
                            <select name="issue_type" class="form-control" required>
                                <option value="electrical">Electrical</option>
                                <option value="plumbing">Plumbing</option>
                                <option value="structural">Structural</option>
                                <option value="furniture">Furniture</option>
                                <option value="equipment">Equipment</option>
                                <option value="cleaning">Cleaning</option>
                                <option value="safety">Safety</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priority Level *</label>
                            <select name="priority" class="form-control" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required placeholder="Building, room, or area">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Facility Name</label>
                            <input type="text" name="facility_name" class="form-control" placeholder="Specific facility or equipment">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Reported By *</label>
                            <input type="text" name="reported_by" class="form-control" required placeholder="Name of person reporting">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Information</label>
                            <input type="text" name="reporter_contact" class="form-control" placeholder="Phone or email">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Detailed Description *</label>
                            <textarea name="description" class="form-control" required placeholder="Provide detailed information about the maintenance issue, including any specific problems, safety concerns, or special requirements..." rows="4"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddRequestModal()">Cancel</button>
                    <button type="submit" name="add_request" class="btn btn-primary">Create Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Request Modal -->
    <div id="updateRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Maintenance Request</h3>
                <button class="modal-close" onclick="closeUpdateRequestModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="request_id" id="update_request_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-control" required id="request_status" onchange="toggleAssignmentFields()">
                                <option value="reported">Reported</option>
                                <option value="assigned">Assigned</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estimated Cost (RWF)</label>
                            <input type="number" name="estimated_cost" class="form-control" id="estimated_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-row" id="assignment_fields" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Assign To</label>
                            <select name="assigned_to" class="form-control" id="assigned_to">
                                <option value="">Select Assignee</option>
                                <?php foreach ($assignment_users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php foreach ($contractors as $contractor): ?>
                                    <option value="<?php echo $contractor['id'] + 1000; ?>">
                                        <?php echo htmlspecialchars($contractor['name']); ?> (Contractor)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row" id="completion_fields" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Actual Cost (RWF)</label>
                            <input type="number" name="actual_cost" class="form-control" id="actual_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Completion Notes</label>
                            <textarea name="completion_notes" class="form-control" id="completion_notes" placeholder="Add any notes about the completion, work done, or follow-up requirements..." rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUpdateRequestModal()">Cancel</button>
                    <button type="submit" name="update_request" class="btn btn-primary">Update Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Contractor Modal -->
    <div id="addContractorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Maintenance Contractor</h3>
                <button class="modal-close" onclick="closeAddContractorModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Contractor Name *</label>
                            <input type="text" name="contractor_name" class="form-control" required placeholder="Company or individual name">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control" placeholder="Primary contact name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number *</label>
                            <input type="text" name="phone" class="form-control" required placeholder="Phone number">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="Email address">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Specialty *</label>
                            <select name="specialty" class="form-control" required>
                                <option value="electrical">Electrical</option>
                                <option value="plumbing">Plumbing</option>
                                <option value="carpentry">Carpentry</option>
                                <option value="painting">Painting</option>
                                <option value="cleaning">Cleaning</option>
                                <option value="general" selected>General</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Hourly Rate (RWF)</label>
                            <input type="number" name="hourly_rate" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddContractorModal()">Cancel</button>
                    <button type="submit" name="add_contractor" class="btn btn-primary">Add Contractor</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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

        // Tab Functions
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.content-section').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Modal Functions
        function openAddRequestModal() {
            document.getElementById('addRequestModal').style.display = 'flex';
        }

        function closeAddRequestModal() {
            document.getElementById('addRequestModal').style.display = 'none';
        }

        function openUpdateRequestModal() {
            document.getElementById('updateRequestModal').style.display = 'flex';
        }

        function closeUpdateRequestModal() {
            document.getElementById('updateRequestModal').style.display = 'none';
        }

        function openAddContractorModal() {
            document.getElementById('addContractorModal').style.display = 'flex';
        }

        function closeAddContractorModal() {
            document.getElementById('addContractorModal').style.display = 'none';
        }

        function updateRequest(requestId) {
            document.getElementById('update_request_id').value = requestId;
            openUpdateRequestModal();
        }

        function viewRequest(requestId) {
            alert('View request details for ID: ' + requestId);
        }

        function deleteRequest(requestId) {
            if (confirm('Are you sure you want to delete this maintenance request?')) {
                window.location.href = 'maintenance.php?delete_request=' + requestId;
            }
        }

        // Toggle assignment fields based on status
        function toggleAssignmentFields() {
            const status = document.getElementById('request_status').value;
            const assignmentFields = document.getElementById('assignment_fields');
            const completionFields = document.getElementById('completion_fields');
            
            if (status === 'assigned') {
                assignmentFields.style.display = 'grid';
                completionFields.style.display = 'none';
            } else if (status === 'completed') {
                assignmentFields.style.display = 'none';
                completionFields.style.display = 'grid';
            } else {
                assignmentFields.style.display = 'none';
                completionFields.style.display = 'none';
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['addRequestModal', 'updateRequestModal', 'addContractorModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'addRequestModal') closeAddRequestModal();
                    if (modalId === 'updateRequestModal') closeUpdateRequestModal();
                    if (modalId === 'addContractorModal') closeAddContractorModal();
                }
            });
        }

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .tabs-container');
            cards.forEach((card, index) => {
                card.style.animation = 'fadeInUp 0.4s ease forwards';
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            const style = document.createElement('style');
            style.textContent = `
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
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 500);
        });
    </script>
</body>
</html>