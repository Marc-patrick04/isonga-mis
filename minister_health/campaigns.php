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

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_campaign':
                $title = $_POST['title'];
                $description = $_POST['description'];
                $campaign_type = $_POST['campaign_type'];
                $target_audience = $_POST['target_audience'];
                $start_date = $_POST['start_date'];
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $location = $_POST['location'];
                $budget = $_POST['budget'] ?? 0;
                $allocated_budget = $_POST['allocated_budget'] ?? 0;
                $expected_participants = $_POST['expected_participants'] ?? 0;
                $objectives = $_POST['objectives'];
                $partner_organizations = json_encode($_POST['partners'] ?? []);
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO health_campaigns 
                        (title, description, campaign_type, target_audience, start_date, end_date, 
                         location, budget, allocated_budget, expected_participants, objectives, 
                         partner_organizations, organizer_id, status, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $title, $description, $campaign_type, $target_audience, $start_date, $end_date,
                        $location, $budget, $allocated_budget, $expected_participants, $objectives,
                        $partner_organizations, $user_id, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Health campaign created successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating campaign: " . $e->getMessage();
                }
                break;
                
            case 'edit_campaign':
                $campaign_id = $_POST['campaign_id'];
                $title = $_POST['title'];
                $description = $_POST['description'];
                $campaign_type = $_POST['campaign_type'];
                $target_audience = $_POST['target_audience'];
                $start_date = $_POST['start_date'];
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $location = $_POST['location'];
                $budget = $_POST['budget'] ?? 0;
                $allocated_budget = $_POST['allocated_budget'] ?? 0;
                $expected_participants = $_POST['expected_participants'] ?? 0;
                $objectives = $_POST['objectives'];
                $status = $_POST['status'];
                $outcomes = $_POST['outcomes'] ?? '';
                $media_coverage = $_POST['media_coverage'] ?? '';
                $actual_participants = $_POST['actual_participants'] ?? 0;
                $partner_organizations = json_encode($_POST['partners'] ?? []);
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE health_campaigns 
                        SET title = ?, description = ?, campaign_type = ?, target_audience = ?,
                            start_date = ?, end_date = ?, location = ?, budget = ?, allocated_budget = ?,
                            expected_participants = ?, objectives = ?, status = ?, outcomes = ?,
                            media_coverage = ?, actual_participants = ?, partner_organizations = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $title, $description, $campaign_type, $target_audience, $start_date, $end_date,
                        $location, $budget, $allocated_budget, $expected_participants, $objectives,
                        $status, $outcomes, $media_coverage, $actual_participants, $partner_organizations,
                        $campaign_id
                    ]);
                    
                    $_SESSION['success_message'] = "Campaign updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating campaign: " . $e->getMessage();
                }
                break;
                
            case 'delete_campaign':
                $campaign_id = $_POST['campaign_id'];
                
                try {
                    $stmt = $pdo->prepare("DELETE FROM health_campaigns WHERE id = ?");
                    $stmt->execute([$campaign_id]);
                    
                    $_SESSION['success_message'] = "Campaign deleted successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting campaign: " . $e->getMessage();
                }
                break;
                
            case 'add_task':
                $campaign_id = $_POST['campaign_id'];
                $title = $_POST['title'];
                $description = $_POST['description'];
                $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
                $due_date = $_POST['due_date'];
                $priority = $_POST['priority'];
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO campaign_tasks 
                        (campaign_id, title, description, assigned_to, due_date, priority, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$campaign_id, $title, $description, $assigned_to, $due_date, $priority, $user_id]);
                    
                    $_SESSION['success_message'] = "Task added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding task: " . $e->getMessage();
                }
                break;
                
            case 'update_task_status':
                $task_id = $_POST['task_id'];
                $status = $_POST['status'];
                $completion_notes = $_POST['completion_notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE campaign_tasks 
                        SET status = ?, completion_notes = ?, 
                            completed_at = CASE WHEN ? = 'completed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$status, $completion_notes, $status, $task_id]);
                    
                    $_SESSION['success_message'] = "Task status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating task: " . $e->getMessage();
                }
                break;
                
            case 'add_resource':
                $campaign_id = $_POST['campaign_id'];
                $resource_type = $_POST['resource_type'];
                $title = $_POST['title'];
                $description = $_POST['description'];
                $quantity = $_POST['quantity'] ?? 1;
                $cost = $_POST['cost'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO campaign_resources 
                        (campaign_id, resource_type, title, description, quantity, cost, uploaded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$campaign_id, $resource_type, $title, $description, $quantity, $cost, $user_id]);
                    
                    $_SESSION['success_message'] = "Resource added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding resource: " . $e->getMessage();
                }
                break;
                
            case 'register_participant':
                $campaign_id = $_POST['campaign_id'];
                $reg_number = $_POST['reg_number'];
                $name = $_POST['name'];
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
                
                try {
                    // Check if already registered
                    $stmt = $pdo->prepare("SELECT id FROM campaign_participants WHERE campaign_id = ? AND reg_number = ?");
                    $stmt->execute([$campaign_id, $reg_number]);
                    
                    if ($stmt->fetch()) {
                        $_SESSION['error_message'] = "Student is already registered for this campaign.";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO campaign_participants 
                            (campaign_id, reg_number, name, email, phone, department_id, registered_at)
                            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$campaign_id, $reg_number, $name, $email, $phone, $department_id]);
                        
                        // Update actual participants count in campaign
                        $stmt = $pdo->prepare("
                            UPDATE health_campaigns 
                            SET actual_participants = actual_participants + 1, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$campaign_id]);
                        
                        $_SESSION['success_message'] = "Participant registered successfully!";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error registering participant: " . $e->getMessage();
                }
                break;
                
            case 'update_participant_attendance':
                $participant_id = $_POST['participant_id'];
                $attendance_status = $_POST['attendance_status'];
                $feedback_rating = !empty($_POST['feedback_rating']) ? $_POST['feedback_rating'] : null;
                $feedback_comment = $_POST['feedback_comment'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE campaign_participants 
                        SET attendance_status = ?, feedback_rating = ?, feedback_comment = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$attendance_status, $feedback_rating, $feedback_comment, $participant_id]);
                    
                    $_SESSION['success_message'] = "Attendance updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating attendance: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: campaigns.php");
        exit();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$view_id = isset($_GET['view']) && is_numeric($_GET['view']) ? (int)$_GET['view'] : null;

// Get campaigns data
try {
    // Campaigns statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_campaigns,
            COALESCE(SUM(budget), 0) as total_budget,
            COALESCE(SUM(allocated_budget), 0) as total_allocated,
            COALESCE(SUM(expected_participants), 0) as total_expected,
            COALESCE(SUM(actual_participants), 0) as total_participants
        FROM health_campaigns
    ");
    $campaign_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Campaigns by status
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count
        FROM health_campaigns
        GROUP BY status
    ");
    $campaigns_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Campaigns list
    $query = "
        SELECT hc.*, u.full_name as organizer_name
        FROM health_campaigns hc
        LEFT JOIN users u ON hc.organizer_id = u.id
        WHERE 1=1
    ";
    $params = [];
    
    if ($status_filter !== 'all') {
        $query .= " AND hc.status = ?";
        $params[] = $status_filter;
    }
    
    if ($type_filter !== 'all') {
        $query .= " AND hc.campaign_type = ?";
        $params[] = $type_filter;
    }
    
    if (!empty($search)) {
        $query .= " AND (hc.title ILIKE ? OR hc.description ILIKE ? OR hc.location ILIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $query .= " ORDER BY 
        CASE 
            WHEN hc.status = 'ongoing' THEN 1
            WHEN hc.status = 'planned' THEN 2
            WHEN hc.status = 'completed' THEN 3
            ELSE 4
        END,
        hc.start_date DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get committee members for task assignment
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, cm.role as committee_role
        FROM users u
        LEFT JOIN committee_members cm ON u.id = cm.user_id
        WHERE u.status = 'active'
        AND u.role IN ('minister_health', 'vice_guild_academic', 'general_secretary', 'minister_gender', 'minister_environment')
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get departments for participant registration
    $stmt = $pdo->query("SELECT * FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Campaigns data error: " . $e->getMessage());
    $campaign_stats = ['total_campaigns' => 0, 'total_budget' => 0, 'total_allocated' => 0, 'total_expected' => 0, 'total_participants' => 0];
    $campaigns_by_status = $campaigns = $committee_members = $departments = [];
}

// Handle single campaign view
$view_campaign = null;
$campaign_tasks = [];
$campaign_resources = [];
$campaign_participants = [];

if ($view_id) {
    try {
        // Get campaign details
        $stmt = $pdo->prepare("
            SELECT hc.*, u.full_name as organizer_name
            FROM health_campaigns hc
            LEFT JOIN users u ON hc.organizer_id = u.id
            WHERE hc.id = ?
        ");
        $stmt->execute([$view_id]);
        $view_campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($view_campaign) {
            // Get campaign tasks
            $stmt = $pdo->prepare("
                SELECT ct.*, u.full_name as assigned_to_name
                FROM campaign_tasks ct
                LEFT JOIN users u ON ct.assigned_to = u.id
                WHERE ct.campaign_id = ?
                ORDER BY 
                    CASE 
                        WHEN ct.status = 'pending' THEN 1
                        WHEN ct.status = 'in_progress' THEN 2
                        ELSE 3
                    END,
                    CASE 
                        WHEN ct.priority = 'urgent' THEN 1
                        WHEN ct.priority = 'high' THEN 2
                        WHEN ct.priority = 'medium' THEN 3
                        ELSE 4
                    END,
                    ct.due_date ASC
            ");
            $stmt->execute([$view_id]);
            $campaign_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get campaign resources
            $stmt = $pdo->prepare("
                SELECT cr.*, u.full_name as uploaded_by_name
                FROM campaign_resources cr
                LEFT JOIN users u ON cr.uploaded_by = u.id
                WHERE cr.campaign_id = ?
                ORDER BY cr.created_at DESC
            ");
            $stmt->execute([$view_id]);
            $campaign_resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get campaign participants
            $stmt = $pdo->prepare("
                SELECT cp.*, d.name as department_name
                FROM campaign_participants cp
                LEFT JOIN departments d ON cp.department_id = d.id
                WHERE cp.campaign_id = ?
                ORDER BY cp.registered_at DESC
            ");
            $stmt->execute([$view_id]);
            $campaign_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        error_log("Campaign view error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading campaign details.";
        $view_campaign = null;
    }
}

// Common campaign types and partner organizations
$campaign_types = ['awareness', 'vaccination', 'screening', 'workshop', 'seminar', 'other'];
$partner_organizations = ['Ministry of Health', 'RBC', 'RHA', 'WHO', 'UNICEF', 'Red Cross', 'Local Hospital', 'Community Health Center', 'NGO Partners'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Health Campaigns - Isonga RPSU</title>
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

        /* Page Header */
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

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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

        /* Filters Card */
        .filters-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        /* Campaigns Grid */
        .campaigns-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .campaign-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .campaign-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .campaign-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-green);
        }

        .campaign-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .campaign-meta {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            font-size: 0.75rem;
        }

        .campaign-body {
            padding: 1rem;
        }

        .campaign-description {
            color: var(--text-dark);
            margin-bottom: 1rem;
            line-height: 1.5;
            font-size: 0.8rem;
        }

        .campaign-details {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.25rem 0;
            font-size: 0.75rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .detail-value {
            color: var(--text-dark);
        }

        .campaign-progress {
            margin: 1rem 0;
        }

        .progress-bar {
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
        }

        .progress-text {
            font-size: 0.65rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
        }

        .campaign-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--medium-gray);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-planned {
            background: #cce7ff;
            color: #004085;
        }

        .status-ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .type-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--light-green);
            color: var(--primary-green);
        }

        .priority-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-high, .priority-urgent {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .priority-low {
            background: #d4edda;
            color: var(--success);
        }

        /* Card for single view */
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
            flex-wrap: wrap;
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
            font-size: 0.75rem;
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
            font-size: 0.7rem;
        }

        .table tbody tr:hover {
            background: var(--light-green);
        }

        /* Task List */
        .task-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .task-item {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            border-left: 3px solid var(--primary-green);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .task-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-dark);
        }

        .task-meta {
            display: flex;
            gap: 0.75rem;
            font-size: 0.65rem;
            color: var(--dark-gray);
            flex-wrap: wrap;
        }

        .task-description {
            font-size: 0.75rem;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
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

        .form-select, .form-input {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .form-textarea {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            resize: vertical;
            min-height: 100px;
            transition: var(--transition);
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .checkbox-item label {
            font-size: 0.8rem;
            color: var(--text-dark);
            cursor: pointer;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
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
            max-width: 800px;
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

        .modal-header h3 {
            margin: 0;
            font-size: 1rem;
            color: var(--text-dark);
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
        }

        .close:hover {
            color: var(--text-dark);
        }

        .modal-body {
            padding: 1.25rem;
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

            .campaigns-grid {
                grid-template-columns: 1fr;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
                justify-content: space-between;
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

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .checkbox-grid {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Health Campaigns</h1>
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
                    <a href="campaigns.php" class="active">
                        <i class="fas fa-bullhorn"></i>
                        <span>Health Campaigns</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="epidemics.php">
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
                <div>
                    <h1 class="page-title">Health Campaigns Management</h1>
                    <p class="page-description">Organize and manage health awareness campaigns, workshops, and vaccination programs</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button class="btn btn-primary" onclick="showModal('addCampaignModal')">
                        <i class="fas fa-plus-circle"></i> New Campaign
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

            <?php if ($view_campaign): ?>
                <!-- Single Campaign View -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullhorn"></i> <?php echo htmlspecialchars($view_campaign['title']); ?></h3>
                        <div class="card-header-actions">
                            <button class="btn btn-outline btn-small" onclick="editCampaign(<?php echo $view_campaign['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-outline btn-small" onclick="showModalWithCampaign('addTaskModal', <?php echo $view_campaign['id']; ?>)">
                                <i class="fas fa-tasks"></i> Add Task
                            </button>
                            <button class="btn btn-outline btn-small" onclick="showModalWithCampaign('addResourceModal', <?php echo $view_campaign['id']; ?>)">
                                <i class="fas fa-box"></i> Add Resource
                            </button>
                            <button class="btn btn-outline btn-small" onclick="showModalWithCampaign('registerParticipantModal', <?php echo $view_campaign['id']; ?>)">
                                <i class="fas fa-user-plus"></i> Register
                            </button>
                            <a href="campaigns.php" class="btn btn-outline btn-small">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="campaign-details">
                            <div class="detail-item">
                                <span class="detail-label">Status:</span>
                                <span class="status-badge status-<?php echo $view_campaign['status']; ?>">
                                    <?php echo ucfirst($view_campaign['status']); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Type:</span>
                                <span class="type-badge"><?php echo ucfirst($view_campaign['campaign_type']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Dates:</span>
                                <span class="detail-value">
                                    <?php echo date('M j, Y', strtotime($view_campaign['start_date'])); ?> 
                                    <?php if ($view_campaign['end_date']): ?>
                                        - <?php echo date('M j, Y', strtotime($view_campaign['end_date'])); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Location:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($view_campaign['location']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Budget:</span>
                                <span class="detail-value">
                                    RWF <?php echo number_format($view_campaign['allocated_budget'], 0); ?> 
                                    (of RWF <?php echo number_format($view_campaign['budget'], 0); ?>)
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Participants:</span>
                                <span class="detail-value">
                                    <?php echo $view_campaign['actual_participants']; ?> 
                                    (of <?php echo $view_campaign['expected_participants']; ?> expected)
                                </span>
                            </div>
                        </div>

                        <?php if ($view_campaign['description']): ?>
                            <div style="margin-top: 1rem;">
                                <h4>Description</h4>
                                <p><?php echo nl2br(htmlspecialchars($view_campaign['description'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($view_campaign['objectives']): ?>
                            <div style="margin-top: 1rem;">
                                <h4>Objectives</h4>
                                <p><?php echo nl2br(htmlspecialchars($view_campaign['objectives'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- Tasks Section -->
                        <div style="margin-top: 2rem;">
                            <h4><i class="fas fa-tasks"></i> Campaign Tasks</h4>
                            <?php if (empty($campaign_tasks)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No tasks created for this campaign.</p>
                                </div>
                            <?php else: ?>
                                <div class="task-list">
                                    <?php foreach ($campaign_tasks as $task): ?>
                                        <div class="task-item">
                                            <div class="task-header">
                                                <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                                <div class="task-meta">
                                                    <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                                        <?php echo ucfirst($task['priority']); ?>
                                                    </span>
                                                    <span class="status-badge status-<?php echo $task['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php if ($task['description']): ?>
                                                <div class="task-description">
                                                    <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="task-meta">
                                                <?php if ($task['assigned_to_name']): ?>
                                                    <span><i class="fas fa-user"></i> Assigned to: <?php echo htmlspecialchars($task['assigned_to_name']); ?></span>
                                                <?php endif; ?>
                                                <?php if ($task['due_date']): ?>
                                                    <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="task-actions">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="update_task_status">
                                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                    <select name="status" class="form-select" style="font-size: 0.7rem; padding: 0.25rem; width: auto;" onchange="this.form.submit()">
                                                        <option value="pending" <?php echo $task['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                    </select>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Resources Section -->
                        <div style="margin-top: 2rem;">
                            <h4><i class="fas fa-box"></i> Campaign Resources</h4>
                            <?php if (empty($campaign_resources)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <p>No resources added for this campaign.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Resource</th>
                                                <th>Type</th>
                                                <th>Quantity</th>
                                                <th>Cost (RWF)</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($campaign_resources as $resource): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($resource['title']); ?></div>
                                                        <?php if ($resource['description']): ?>
                                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                                <?php echo htmlspecialchars($resource['description']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo ucfirst($resource['resource_type']); ?></td>
                                                    <td><?php echo $resource['quantity']; ?></td>
                                                    <td><?php echo number_format($resource['cost'], 0); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $resource['status']; ?>">
                                                            <?php echo ucfirst($resource['status']); ?>
                                                        </span>
                                                    </td>
                                                </table>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Participants Section -->
                        <div style="margin-top: 2rem;">
                            <h4><i class="fas fa-users"></i> Campaign Participants (<?php echo count($campaign_participants); ?>)</h4>
                            <?php if (empty($campaign_participants)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-friends"></i>
                                    <p>No participants registered for this campaign.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Department</th>
                                                <th>Contact</th>
                                                <th>Attendance</th>
                                                <th>Feedback</th>
                                                <th>Registered</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($campaign_participants as $participant): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($participant['name']); ?></div>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                            <?php echo htmlspecialchars($participant['reg_number']); ?>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($participant['department_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php if ($participant['email']): ?>
                                                            <div style="font-size: 0.7rem;"><?php echo htmlspecialchars($participant['email']); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($participant['phone']): ?>
                                                            <div style="font-size: 0.7rem;"><?php echo htmlspecialchars($participant['phone']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="update_participant_attendance">
                                                            <input type="hidden" name="participant_id" value="<?php echo $participant['id']; ?>">
                                                            <select name="attendance_status" class="form-select" style="font-size: 0.7rem; padding: 0.25rem; width: auto;" onchange="this.form.submit()">
                                                                <option value="registered" <?php echo $participant['attendance_status'] === 'registered' ? 'selected' : ''; ?>>Registered</option>
                                                                <option value="attended" <?php echo $participant['attendance_status'] === 'attended' ? 'selected' : ''; ?>>Attended</option>
                                                                <option value="absent" <?php echo $participant['attendance_status'] === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                            </select>
                                                        </form>
                                                    </td>
                                                    <td>
                                                        <?php if ($participant['feedback_rating']): ?>
                                                            <div style="color: var(--warning);">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <i class="fas fa-star<?php echo $i <= $participant['feedback_rating'] ? '' : '-o'; ?>"></i>
                                                                <?php endfor; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span style="color: var(--dark-gray); font-size: 0.7rem;">No feedback</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($participant['registered_at'])); ?></td>
                                                    <td>
                                                        <button class="btn btn-outline btn-small" onclick="updateParticipantAttendance(<?php echo $participant['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
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

            <?php else: ?>
                <!-- Campaigns List View -->
                
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($campaign_stats['total_campaigns'] ?? 0); ?></div>
                            <div class="stat-label">Total Campaigns</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">RWF <?php echo number_format(($campaign_stats['total_budget'] ?? 0), 0); ?></div>
                            <div class="stat-label">Total Budget</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($campaign_stats['total_expected'] ?? 0); ?></div>
                            <div class="stat-label">Expected Participants</div>
                        </div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($campaign_stats['total_participants'] ?? 0); ?></div>
                            <div class="stat-label">Actual Participants</div>
                        </div>
                    </div>
                </div>

                <!-- Status Overview -->
                <div class="stats-grid" style="margin-top: 0;">
                    <?php foreach ($campaigns_by_status as $status): ?>
                        <div class="stat-card status-<?php echo $status['status']; ?>">
                            <div class="stat-icon">
                                <i class="fas fa-<?php 
                                    switch($status['status']) {
                                        case 'planned': echo 'calendar-alt'; break;
                                        case 'ongoing': echo 'play-circle'; break;
                                        case 'completed': echo 'check-circle'; break;
                                        case 'cancelled': echo 'times-circle'; break;
                                        default: echo 'circle';
                                    }
                                ?>"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $status['count']; ?></div>
                                <div class="stat-label"><?php echo ucfirst($status['status']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="planned" <?php echo $status_filter === 'planned' ? 'selected' : ''; ?>>Planned</option>
                                <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Campaign Type</label>
                            <select name="type" class="form-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <?php foreach ($campaign_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-input" placeholder="Search campaigns..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="campaigns.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Campaigns Grid -->
                <div class="campaigns-grid">
                    <?php if (empty($campaigns)): ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <i class="fas fa-bullhorn"></i>
                            <p>No campaigns found. <a href="javascript:void(0)" onclick="showModal('addCampaignModal')" style="color: var(--primary-green);">Create the first campaign</a></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($campaigns as $campaign): ?>
                            <?php 
                            $expected = $campaign['expected_participants'] ?? 0;
                            $actual = $campaign['actual_participants'] ?? 0;
                            $participant_rate = $expected > 0 ? ($actual / $expected) * 100 : 0;
                            ?>
                            <div class="campaign-card">
                                <div class="campaign-header">
                                    <h3 class="campaign-title"><?php echo htmlspecialchars($campaign['title']); ?></h3>
                                    <div class="campaign-meta">
                                        <span class="status-badge status-<?php echo $campaign['status']; ?>">
                                            <?php echo ucfirst($campaign['status']); ?>
                                        </span>
                                        <span class="type-badge"><?php echo ucfirst($campaign['campaign_type']); ?></span>
                                        <span><?php echo date('M j, Y', strtotime($campaign['start_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="campaign-body">
                                    <?php if ($campaign['description']): ?>
                                        <div class="campaign-description">
                                            <?php 
                                            $desc = htmlspecialchars($campaign['description']);
                                            echo strlen($desc) > 120 ? substr($desc, 0, 120) . '...' : $desc;
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="campaign-details">
                                        <div class="detail-item">
                                            <span class="detail-label">Location:</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($campaign['location']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Budget:</span>
                                            <span class="detail-value">RWF <?php echo number_format($campaign['allocated_budget'], 0); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Participants:</span>
                                            <span class="detail-value"><?php echo $actual; ?> / <?php echo $expected; ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($campaign['status'] === 'ongoing' || $campaign['status'] === 'planned'): ?>
                                        <div class="campaign-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min(100, $participant_rate); ?>%"></div>
                                            </div>
                                            <div class="progress-text">
                                                <span>Registration Progress</span>
                                                <span><?php echo round($participant_rate); ?>%</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="campaign-actions">
                                        <a href="?view=<?php echo $campaign['id']; ?>" class="btn btn-primary btn-small">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                        <button class="btn btn-outline btn-small" onclick="editCampaign(<?php echo $campaign['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if ($campaign['status'] !== 'completed' && $campaign['status'] !== 'cancelled'): ?>
                                            <button class="btn btn-outline btn-small" onclick="showModalWithCampaign('addTaskModal', <?php echo $campaign['id']; ?>)">
                                                <i class="fas fa-tasks"></i> Add Task
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Campaign Modal -->
    <div id="addCampaignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Health Campaign</h3>
                <button class="close" onclick="closeModal('addCampaignModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addCampaignForm">
                    <input type="hidden" name="action" value="add_campaign">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Campaign Title *</label>
                            <input type="text" name="title" class="form-input" required placeholder="Enter campaign title">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Campaign Type *</label>
                            <select name="campaign_type" class="form-select" required>
                                <option value="">Select type...</option>
                                <?php foreach ($campaign_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Target Audience *</label>
                            <input type="text" name="target_audience" class="form-input" required placeholder="e.g., All students, First years, Female students">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-input" required placeholder="e.g., Main Hall, Sports Ground">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Estimated Budget (RWF)</label>
                            <input type="number" name="budget" class="form-input" placeholder="0" step="1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Allocated Budget (RWF)</label>
                            <input type="number" name="allocated_budget" class="form-input" placeholder="0" step="1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Expected Participants</label>
                            <input type="number" name="expected_participants" class="form-input" placeholder="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Campaign Description *</label>
                        <textarea name="description" class="form-textarea" required placeholder="Describe the campaign purpose, activities, and expected outcomes..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Objectives *</label>
                        <textarea name="objectives" class="form-textarea" required placeholder="List the main objectives of this campaign..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Partner Organizations</label>
                        <div class="checkbox-grid">
                            <?php foreach ($partner_organizations as $partner): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="partners[]" value="<?php echo $partner; ?>" id="partner_<?php echo strtolower(str_replace(' ', '_', $partner)); ?>">
                                    <label for="partner_<?php echo strtolower(str_replace(' ', '_', $partner)); ?>"><?php echo $partner; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addCampaignModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Campaign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Task Modal -->
    <div id="addTaskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Campaign Task</h3>
                <button class="close" onclick="closeModal('addTaskModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addTaskForm">
                    <input type="hidden" name="action" value="add_task">
                    <input type="hidden" name="campaign_id" id="task_campaign_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Task Title *</label>
                            <input type="text" name="title" class="form-input" required placeholder="Enter task title">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Assigned To</label>
                            <select name="assigned_to" class="form-select">
                                <option value="">Not assigned</option>
                                <?php foreach ($committee_members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo htmlspecialchars($member['full_name'] . ' (' . $member['role'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Due Date *</label>
                            <input type="date" name="due_date" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Task Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Describe the task details and requirements..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addTaskModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Resource Modal -->
    <div id="addResourceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Campaign Resource</h3>
                <button class="close" onclick="closeModal('addResourceModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addResourceForm">
                    <input type="hidden" name="action" value="add_resource">
                    <input type="hidden" name="campaign_id" id="resource_campaign_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Resource Title *</label>
                            <input type="text" name="title" class="form-input" required placeholder="Enter resource name">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Resource Type *</label>
                            <select name="resource_type" class="form-select" required>
                                <option value="equipment">Equipment</option>
                                <option value="material">Material</option>
                                <option value="stationery">Stationery</option>
                                <option value="medical">Medical Supplies</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-input" value="1" min="1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Cost (RWF)</label>
                            <input type="number" name="cost" class="form-input" placeholder="0" step="1">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Describe the resource and its purpose..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addResourceModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Resource</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Register Participant Modal -->
    <div id="registerParticipantModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Register Campaign Participant</h3>
                <button class="close" onclick="closeModal('registerParticipantModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="registerParticipantForm">
                    <input type="hidden" name="action" value="register_participant">
                    <input type="hidden" name="campaign_id" id="participant_campaign_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Registration Number *</label>
                            <input type="text" name="reg_number" class="form-input" required placeholder="e.g., 25RP01234">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-input" required placeholder="Enter student's full name">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-input" placeholder="student@rpmusanze.ac.rw">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-input" placeholder="+250788100100">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">Select department...</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('registerParticipantModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Register Participant</button>
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

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
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
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Modal Functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                
                // Set current date for date fields
                const today = new Date().toISOString().split('T')[0];
                const dateFields = modal.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    if (!field.value) {
                        field.value = today;
                    }
                });
            }
        }
        
        function showModalWithCampaign(modalId, campaignId) {
            showModal(modalId);
            const campaignIdField = document.getElementById(modalId.replace('Modal', '_campaign_id'));
            if (campaignIdField) {
                campaignIdField.value = campaignId;
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        function editCampaign(campaignId) {
            // In a real implementation, you would fetch campaign data and populate the form
            alert('Edit functionality would open a form with campaign data. Campaign ID: ' + campaignId);
        }

        function updateParticipantAttendance(participantId) {
            const status = prompt('Update attendance status (registered, attended, absent):');
            if (status && ['registered', 'attended', 'absent'].includes(status.toLowerCase())) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_participant_attendance">
                    <input type="hidden" name="participant_id" value="${participantId}">
                    <input type="hidden" name="attendance_status" value="${status.toLowerCase()}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
        });

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
            
            // Set minimum dates to today
            const dateFields = document.querySelectorAll('input[type="date"]');
            const today = new Date().toISOString().split('T')[0];
            dateFields.forEach(field => {
                if (field.name === 'start_date' || field.name === 'due_date') {
                    field.min = today;
                }
            });
        });
    </script>
</body>
</html>