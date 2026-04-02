<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'dashboard';
$association_id = $_GET['association_id'] ?? null;
$member_id = $_GET['member_id'] ?? null;
$activity_id = $_GET['activity_id'] ?? null;
$message = '';
$error = '';

// Initialize variables to prevent undefined errors
$unread_messages = 0;
$pending_tickets = 0;

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Initialize other variables
$associations = [];
$members = [];
$activities = [];
$departments = [];
$programs = [];
$stats = [
    'total_associations' => 0, 
    'total_members' => 0, 
    'active_associations' => 0,
    'religious_associations' => 0,
    'cultural_associations' => 0,
    'academic_associations' => 0
];
$current_association = null;
$current_member = null;
$current_activity = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_association' || $action === 'edit_association') {
        $name = trim($_POST['name']);
        $type = $_POST['type'] ?? 'religious';
        $description = trim($_POST['description']);
        $established_date = $_POST['established_date'] ?: null;
        $meeting_schedule = trim($_POST['meeting_schedule']);
        $meeting_location = trim($_POST['meeting_location']);
        $faculty_advisor = trim($_POST['faculty_advisor']);
        $advisor_contact = trim($_POST['advisor_contact']);
        $contact_person = trim($_POST['contact_person']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        $performance_notes = trim($_POST['performance_notes']);
        $goals = trim($_POST['goals']);
        $achievements = trim($_POST['achievements']);
        $status = $_POST['status'] ?? 'active';
        
        if (!empty($name)) {
            try {
                if ($action === 'add_association') {
                    $stmt = $pdo->prepare("
                        INSERT INTO associations 
                        (name, type, description, established_date, meeting_schedule, meeting_location, 
                         faculty_advisor, advisor_contact, contact_person, contact_email, contact_phone,
                         performance_notes, goals, achievements, status, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $name, $type, $description, $established_date, $meeting_schedule, $meeting_location,
                        $faculty_advisor, $advisor_contact, $contact_person, $contact_email, $contact_phone,
                        $performance_notes, $goals, $achievements, $status, $user_id
                    ]);
                    $message = "Association '$name' created successfully!";
                    $action = 'dashboard';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE associations 
                        SET name = ?, type = ?, description = ?, established_date = ?, meeting_schedule = ?, 
                            meeting_location = ?, faculty_advisor = ?, advisor_contact = ?, contact_person = ?,
                            contact_email = ?, contact_phone = ?, performance_notes = ?, goals = ?, 
                            achievements = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $name, $type, $description, $established_date, $meeting_schedule, $meeting_location,
                        $faculty_advisor, $advisor_contact, $contact_person, $contact_email, $contact_phone,
                        $performance_notes, $goals, $achievements, $status, $association_id
                    ]);
                    $message = "Association updated successfully!";
                    $action = 'view';
                }
            } catch (PDOException $e) {
                $error = "Error saving association: " . $e->getMessage();
            }
        } else {
            $error = "Association name is required.";
        }
    }
    elseif ($action === 'add_member' || $action === 'edit_member') {
        $reg_number = trim($_POST['reg_number']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $department_id = $_POST['department_id'] ?: null;
        $program_id = $_POST['program_id'] ?: null;
        $academic_year = $_POST['academic_year'] ?: null;
        $role = $_POST['role'] ?? 'member';
        $join_date = $_POST['join_date'] ?: date('Y-m-d');
        $status = $_POST['status'] ?? 'active';
        $membership_notes = trim($_POST['membership_notes']);
        
        if (!empty($reg_number) && !empty($name) && $association_id) {
            try {
                if ($action === 'add_member') {
                    $check_stmt = $pdo->prepare("SELECT id FROM association_members WHERE association_id = ? AND reg_number = ?");
                    $check_stmt->execute([$association_id, $reg_number]);
                    if ($check_stmt->fetch()) {
                        $error = "Member with registration number $reg_number already exists in this association!";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO association_members 
                            (association_id, reg_number, name, email, phone, department_id, program_id, 
                             academic_year, role, join_date, status, membership_notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $association_id, $reg_number, $name, $email, $phone, $department_id,
                            $program_id, $academic_year, $role, $join_date, $status, $membership_notes
                        ]);
                        
                        $update_stmt = $pdo->prepare("
                            UPDATE associations SET members_count = members_count + 1 WHERE id = ?
                        ");
                        $update_stmt->execute([$association_id]);
                        
                        $message = "Member added successfully!";
                        $action = 'view';
                    }
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE association_members 
                        SET reg_number = ?, name = ?, email = ?, phone = ?, department_id = ?, program_id = ?,
                            academic_year = ?, role = ?, join_date = ?, status = ?, membership_notes = ?,
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ? AND association_id = ?
                    ");
                    $stmt->execute([
                        $reg_number, $name, $email, $phone, $department_id, $program_id,
                        $academic_year, $role, $join_date, $status, $membership_notes, $member_id, $association_id
                    ]);
                    $message = "Member updated successfully!";
                    $action = 'view';
                }
            } catch (PDOException $e) {
                $error = "Error saving member: " . $e->getMessage();
            }
        } else {
            $error = "Registration number and name are required.";
        }
    }
    elseif ($action === 'add_activity' || $action === 'edit_activity') {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $activity_type = $_POST['activity_type'] ?? 'meeting';
        $activity_date = $_POST['activity_date'] ?: date('Y-m-d');
        $start_time = $_POST['start_time'] ?: null;
        $end_time = $_POST['end_time'] ?: null;
        $location = trim($_POST['location']);
        $participants_count = $_POST['participants_count'] ?? 0;
        $budget = $_POST['budget'] ?? 0;
        $status = $_POST['status'] ?? 'scheduled';
        $notes = trim($_POST['notes']);
        
        if (!empty($title) && $association_id) {
            try {
                if ($action === 'add_activity') {
                    $stmt = $pdo->prepare("
                        INSERT INTO association_activities 
                        (association_id, title, description, activity_type, activity_date, start_time, 
                         end_time, location, participants_count, budget, status, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $association_id, $title, $description, $activity_type, $activity_date, $start_time,
                        $end_time, $location, $participants_count, $budget, $status, $notes, $user_id
                    ]);
                    $message = "Activity added successfully!";
                    $action = 'view';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE association_activities 
                        SET title = ?, description = ?, activity_type = ?, activity_date = ?, start_time = ?,
                            end_time = ?, location = ?, participants_count = ?, budget = ?, status = ?, 
                            notes = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ? AND association_id = ?
                    ");
                    $stmt->execute([
                        $title, $description, $activity_type, $activity_date, $start_time, $end_time,
                        $location, $participants_count, $budget, $status, $notes, $activity_id, $association_id
                    ]);
                    $message = "Activity updated successfully!";
                    $action = 'view';
                }
            } catch (PDOException $e) {
                $error = "Error saving activity: " . $e->getMessage();
            }
        } else {
            $error = "Activity title is required.";
        }
    }
}

// Handle delete actions
if (isset($_GET['delete'])) {
    if ($_GET['delete'] === 'association' && $association_id) {
        try {
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM association_members WHERE association_id = ?");
            $check_stmt->execute([$association_id]);
            $member_count = $check_stmt->fetchColumn();
            
            if ($member_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM associations WHERE id = ?");
                $stmt->execute([$association_id]);
                $message = "Association deleted successfully!";
                $action = 'dashboard';
            } else {
                $error = "Cannot delete association with members. Please remove all members first.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting association: " . $e->getMessage();
        }
    }
    elseif ($_GET['delete'] === 'member' && $member_id && $association_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM association_members WHERE id = ? AND association_id = ?");
            $stmt->execute([$member_id, $association_id]);
            
            $update_stmt = $pdo->prepare("
                UPDATE associations SET members_count = GREATEST(0, members_count - 1) WHERE id = ?
            ");
            $update_stmt->execute([$association_id]);
            
            $message = "Member removed successfully!";
            $action = 'view';
        } catch (PDOException $e) {
            $error = "Error deleting member: " . $e->getMessage();
        }
    }
    elseif ($_GET['delete'] === 'activity' && $activity_id && $association_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM association_activities WHERE id = ? AND association_id = ?");
            $stmt->execute([$activity_id, $association_id]);
            $message = "Activity deleted successfully!";
            $action = 'view';
        } catch (PDOException $e) {
            $error = "Error deleting activity: " . $e->getMessage();
        }
    }
}

// Get data based on current action
try {
    // Get all associations for overview
    $associations_stmt = $pdo->query("
        SELECT a.*, 
               COUNT(am.id) as actual_members_count,
               COUNT(DISTINCT aa.id) as activities_count
        FROM associations a
        LEFT JOIN association_members am ON a.id = am.association_id AND am.status = 'active'
        LEFT JOIN association_activities aa ON a.id = aa.association_id
        GROUP BY a.id
        ORDER BY a.name
    ");
    $associations = $associations_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_associations,
            COALESCE(SUM(members_count), 0) as total_members,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_associations,
            COUNT(CASE WHEN type = 'religious' THEN 1 END) as religious_associations,
            COUNT(CASE WHEN type = 'cultural' THEN 1 END) as cultural_associations,
            COUNT(CASE WHEN type = 'academic' THEN 1 END) as academic_associations
        FROM associations
    ");
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats_result) {
        $stats = $stats_result;
    }
    
    if ($association_id) {
        $association_stmt = $pdo->prepare("SELECT * FROM associations WHERE id = ?");
        $association_stmt->execute([$association_id]);
        $current_association = $association_stmt->fetch(PDO::FETCH_ASSOC);
        
        $members_stmt = $pdo->prepare("
            SELECT am.*, d.name as department_name, p.name as program_name
            FROM association_members am
            LEFT JOIN departments d ON am.department_id = d.id
            LEFT JOIN programs p ON am.program_id = p.id
            WHERE am.association_id = ?
            ORDER BY 
                CASE am.role 
                    WHEN 'president' THEN 1
                    WHEN 'vice_president' THEN 2
                    WHEN 'secretary' THEN 3
                    WHEN 'treasurer' THEN 4
                    WHEN 'advisor' THEN 5
                    ELSE 6
                END,
                am.name
        ");
        $members_stmt->execute([$association_id]);
        $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $activities_stmt = $pdo->prepare("
            SELECT * FROM association_activities 
            WHERE association_id = ? 
            ORDER BY activity_date DESC, start_time DESC
        ");
        $activities_stmt->execute([$association_id]);
        $activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $departments_stmt = $pdo->query("SELECT * FROM departments WHERE is_active = true ORDER BY name");
        $departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $programs_stmt = $pdo->query("SELECT * FROM programs WHERE is_active = true ORDER BY name");
        $programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($action === 'edit_association' && $association_id) {
        $stmt = $pdo->prepare("SELECT * FROM associations WHERE id = ?");
        $stmt->execute([$association_id]);
        $current_association = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($action === 'edit_member' && $member_id && $association_id) {
        $stmt = $pdo->prepare("SELECT * FROM association_members WHERE id = ? AND association_id = ?");
        $stmt->execute([$member_id, $association_id]);
        $current_member = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($action === 'edit_activity' && $activity_id && $association_id) {
        $stmt = $pdo->prepare("SELECT * FROM association_activities WHERE id = ? AND association_id = ?");
        $stmt->execute([$activity_id, $association_id]);
        $current_activity = $stmt->fetch(PDO::FETCH_ASSOC);
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
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $unread_messages = $result['unread_messages'] ?? 0;
    } catch (PDOException $e) {
        $unread_messages = 0;
        error_log("Unread messages query error: " . $e->getMessage());
    }
    
    // Get pending tickets count
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE assigned_to = ? AND status IN ('open', 'in_progress')
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pending_tickets = $result['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets = 0;
        error_log("Pending tickets query error: " . $e->getMessage());
    }
    
} catch (PDOException $e) {
    error_log("Associations data error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Associations Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #A78BFA;
            --accent-purple: #7C3AED;
            --light-purple: #F3F4F6;
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        .dark-mode {
            --primary-purple: #A78BFA;
            --secondary-purple: #C4B5FD;
            --accent-purple: #8B5CF6;
            --light-purple: #1F2937;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
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

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-purple);
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
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .page-description {
            color: var(--dark-gray);
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-sm {
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
            border-left: 4px solid var(--primary-purple);
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
            background: var(--light-purple);
            color: var(--primary-purple);
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

        /* Cards */
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
            background: var(--light-purple);
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

        /* Associations Grid */
        .associations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .association-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border-left: 4px solid var(--primary-purple);
        }

        .association-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .association-header {
            padding: 1rem;
            background: var(--light-purple);
            border-bottom: 1px solid var(--medium-gray);
        }

        .association-name {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .association-body {
            padding: 1rem;
        }

        .association-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 1rem;
            display: -webkit-box;
            /* -webkit-line-clamp: 2; */
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .association-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .association-stat {
            text-align: center;
        }

        .stat-value {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary-purple);
        }

        .association-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--medium-gray);
        }

        .action-link {
            color: var(--primary-purple);
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .action-link:hover {
            text-decoration: underline;
        }

        .action-link.danger {
            color: var(--danger);
        }

        /* Type and Status Badges */
        .type-badge, .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-religious { background: #e3f2fd; color: #1565c0; }
        .type-cultural { background: #f3e5f5; color: #7b1fa2; }
        .type-academic { background: #e8f5e8; color: #2e7d32; }
        .type-sports { background: #fff3e0; color: #ef6c00; }
        .type-social { background: #fce4ec; color: #c2185b; }
        .type-other { background: #f5f5f5; color: #424242; }

        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-suspended { background: #fff3cd; color: #856404; }

        /* Role Badges */
        .role-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .role-president { background: #ffebee; color: #c62828; }
        .role-vice_president { background: #f3e5f5; color: #7b1fa2; }
        .role-secretary { background: #e8f5e8; color: #2e7d32; }
        .role-treasurer { background: #fff3e0; color: #ef6c00; }
        .role-advisor { background: #e3f2fd; color: #1565c0; }
        .role-member { background: #f5f5f5; color: #424242; }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
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
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        /* Tables */
        .table-responsive {
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
            background: var(--light-purple);
        }

        /* Tabs */
        .tabs {
            display: flex;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            gap: 0.25rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            font-weight: 500;
            font-size: 0.85rem;
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
            animation: fadeIn 0.3s ease-in-out;
        }

        .tab-content.active {
            display: block;
        }

        /* Detail Sections */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-section {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.25rem;
        }

        .detail-section h4 {
            color: var(--primary-purple);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light-purple);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-item label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .detail-item span {
            color: var(--dark-gray);
            font-size: 0.8rem;
            text-align: right;
        }

        .tab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .tab-header h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Activities Grid */
        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .activity-card {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
        }

        .activity-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .activity-header {
            padding: 1rem;
            background: var(--light-purple);
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .activity-body {
            padding: 1rem;
        }

        .activity-datetime, .activity-location {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .activity-datetime i, .activity-location i {
            width: 16px;
            margin-right: 0.5rem;
        }

        .activity-description {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin: 0.75rem 0;
        }

        .activity-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
        }

        .activity-stat {
            text-align: center;
        }

        .activity-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .settings-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Alerts */
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
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

        @keyframes fadeIn {
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
                padding-top: 4rem;
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

            .associations-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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

            .associations-grid {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
            }

            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .detail-item {
                flex-direction: column;
                gap: 0.25rem;
            }

            .detail-item span {
                text-align: left;
            }

            .activities-grid {
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

            .association-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .settings-actions {
                flex-direction: column;
            }

            .settings-actions .btn {
                width: 100%;
                justify-content: center;
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
                <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Associations</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <!-- <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button> -->
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Public Relations</div>
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
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php" class="active">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
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
            <!-- Page Header -->
            <div class="page-header">
                
                <div class="page-actions">
                    <?php if ($action === 'dashboard' || $action === 'view'): ?>
                        <a href="?action=add_association" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> New Association
                        </a>
                    <?php else: ?>
                        <a href="?action=<?php echo $association_id ? 'view&association_id=' . $association_id : 'dashboard'; ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-church"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_associations']); ?></div>
                        <div class="stat-label">Total Associations</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_members']); ?></div>
                        <div class="stat-label">Total Members</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['active_associations']); ?></div>
                        <div class="stat-label">Active Associations</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-pray"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['religious_associations']); ?></div>
                        <div class="stat-label">Religious Groups</div>
                    </div>
                </div>
            </div>

            <!-- Content based on action -->
            <?php if ($action === 'dashboard' || ($action === 'view' && !$association_id)): ?>
                <!-- Associations Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-church"></i> All Student Associations</h3>
                        <div class="card-header-actions">
                            <span style="color: var(--dark-gray); font-size: 0.8rem;">
                                <?php echo count($associations); ?> associations
                            </span>
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($associations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-church"></i>
                                <p>No associations found.</p>
                                <a href="?action=add_association" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-plus-circle"></i> Create First Association
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="associations-grid">
                                <?php foreach ($associations as $association): ?>
                                    <div class="association-card">
                                        <div class="association-header">
                                            <div class="association-name"><?php echo htmlspecialchars($association['name']); ?></div>
                                            <span class="type-badge type-<?php echo $association['type']; ?>">
                                                <?php echo ucfirst($association['type']); ?>
                                            </span>
                                        </div>
                                        <div class="association-body">
                                            <?php if (!empty($association['description'])): ?>
                                                <div class="association-description">
                                                    <?php echo htmlspecialchars(substr($association['description'], 0, 150)); ?>
                                                    <?php if (strlen($association['description']) > 150): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="association-stats">
                                                <div class="association-stat">
                                                    <div class="stat-value"><?php echo $association['actual_members_count']; ?></div>
                                                    <div class="stat-label">Members</div>
                                                </div>
                                                <div class="association-stat">
                                                    <div class="stat-value"><?php echo $association['activities_count']; ?></div>
                                                    <div class="stat-label">Activities</div>
                                                </div>
                                                <div class="association-stat">
                                                    <div class="stat-value">
                                                        <span class="status-badge status-<?php echo $association['status']; ?>">
                                                            <?php echo ucfirst($association['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="association-actions">
                                                <a href="?action=view&association_id=<?php echo $association['id']; ?>" class="action-link">
                                                    <i class="fas fa-eye"></i> View Details
                                                </a>
                                                <a href="?action=edit_association&association_id=<?php echo $association['id']; ?>" class="action-link">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="?action=dashboard&delete=association&association_id=<?php echo $association['id']; ?>" 
                                                   class="action-link danger" 
                                                   onclick="return confirm('Are you sure you want to delete this association?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'view' && $association_id && $current_association): ?>
                <!-- Association Details View -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-church"></i> <?php echo htmlspecialchars($current_association['name']); ?></h3>
                        <div class="card-header-actions">
                            <span class="type-badge type-<?php echo $current_association['type']; ?>">
                                <?php echo ucfirst($current_association['type']); ?>
                            </span>
                            <span class="status-badge status-<?php echo $current_association['status']; ?>">
                                <?php echo ucfirst($current_association['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Association Tabs -->
                        <div class="tabs">
                            <button class="tab active" onclick="showTab('overview')">Overview</button>
                            <button class="tab" onclick="showTab('members')">Members (<?php echo count($members); ?>)</button>
                            <button class="tab" onclick="showTab('activities')">Activities (<?php echo count($activities); ?>)</button>
                            <button class="tab" onclick="showTab('settings')">Settings</button>
                        </div>

                        <!-- Overview Tab -->
                        <div id="overview" class="tab-content active">
                            <div class="detail-grid">
                                <div class="detail-section">
                                    <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                                    <div class="detail-item">
                                        <label>Type:</label>
                                        <span><?php echo ucfirst($current_association['type']); ?></span>
                                    </div>
                                    <?php if ($current_association['established_date']): ?>
                                        <div class="detail-item">
                                            <label>Established:</label>
                                            <span><?php echo date('F j, Y', strtotime($current_association['established_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($current_association['meeting_schedule']): ?>
                                        <div class="detail-item">
                                            <label>Meeting Schedule:</label>
                                            <span><?php echo htmlspecialchars($current_association['meeting_schedule']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($current_association['meeting_location']): ?>
                                        <div class="detail-item">
                                            <label>Meeting Location:</label>
                                            <span><?php echo htmlspecialchars($current_association['meeting_location']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="detail-section">
                                    <h4><i class="fas fa-address-card"></i> Contact Information</h4>
                                    <?php if ($current_association['contact_person']): ?>
                                        <div class="detail-item">
                                            <label>Contact Person:</label>
                                            <span><?php echo htmlspecialchars($current_association['contact_person']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($current_association['contact_email']): ?>
                                        <div class="detail-item">
                                            <label>Email:</label>
                                            <span><?php echo htmlspecialchars($current_association['contact_email']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($current_association['contact_phone']): ?>
                                        <div class="detail-item">
                                            <label>Phone:</label>
                                            <span><?php echo htmlspecialchars($current_association['contact_phone']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($current_association['faculty_advisor']): ?>
                                        <div class="detail-item">
                                            <label>Faculty Advisor:</label>
                                            <span><?php echo htmlspecialchars($current_association['faculty_advisor']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($current_association['description']): ?>
                                <div class="detail-section">
                                    <h4><i class="fas fa-align-left"></i> Description</h4>
                                    <p><?php echo nl2br(htmlspecialchars($current_association['description'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($current_association['goals']): ?>
                                <div class="detail-section">
                                    <h4><i class="fas fa-bullseye"></i> Goals & Objectives</h4>
                                    <p><?php echo nl2br(htmlspecialchars($current_association['goals'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($current_association['achievements']): ?>
                                <div class="detail-section">
                                    <h4><i class="fas fa-trophy"></i> Key Achievements</h4>
                                    <p><?php echo nl2br(htmlspecialchars($current_association['achievements'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Members Tab -->
                        <div id="members" class="tab-content">
                            <div class="tab-header">
                                <h4><i class="fas fa-users"></i> Association Members</h4>
                                <a href="?action=add_member&association_id=<?php echo $association_id; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-user-plus"></i> Add Member
                                </a>
                            </div>
                            
                            <?php if (empty($members)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No members found in this association.</p>
                                    <a href="?action=add_member&association_id=<?php echo $association_id; ?>" class="btn btn-primary">
                                        <i class="fas fa-user-plus"></i> Add First Member
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Registration No.</th>
                                                <th>Role</th>
                                                <th>Department</th>
                                                <th>Join Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $member): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                                                        <?php if ($member['email']): ?>
                                                            <br><small><?php echo htmlspecialchars($member['email']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                    <td>
                                                        <span class="role-badge role-<?php echo $member['role']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($member['department_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $member['status']; ?>">
                                                            <?php echo ucfirst($member['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <a href="?action=edit_member&association_id=<?php echo $association_id; ?>&member_id=<?php echo $member['id']; ?>" 
                                                               class="action-link" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <a href="?action=view&association_id=<?php echo $association_id; ?>&delete=member&member_id=<?php echo $member['id']; ?>" 
                                                               class="action-link danger" 
                                                               onclick="return confirm('Are you sure you want to remove this member?')"
                                                               title="Remove">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Activities Tab -->
                        <div id="activities" class="tab-content">
                            <div class="tab-header">
                                <h4><i class="fas fa-calendar-alt"></i> Association Activities</h4>
                                <a href="?action=add_activity&association_id=<?php echo $association_id; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-calendar-plus"></i> Add Activity
                                </a>
                            </div>
                            
                            <?php if (empty($activities)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-alt"></i>
                                    <p>No activities found for this association.</p>
                                    <a href="?action=add_activity&association_id=<?php echo $association_id; ?>" class="btn btn-primary">
                                        <i class="fas fa-calendar-plus"></i> Schedule First Activity
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="activities-grid">
                                    <?php foreach ($activities as $activity): ?>
                                        <div class="activity-card">
                                            <div class="activity-header">
                                                <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                                <span class="type-badge type-<?php echo $activity['activity_type']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?>
                                                </span>
                                            </div>
                                            <div class="activity-body">
                                                <div class="activity-datetime">
                                                    <i class="fas fa-calendar"></i>
                                                    <?php echo date('F j, Y', strtotime($activity['activity_date'])); ?>
                                                    <?php if ($activity['start_time']): ?>
                                                        at <?php echo date('g:i A', strtotime($activity['start_time'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($activity['location']): ?>
                                                    <div class="activity-location">
                                                        <i class="fas fa-map-marker-alt"></i>
                                                        <?php echo htmlspecialchars($activity['location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($activity['description']): ?>
                                                    <div class="activity-description">
                                                        <?php echo htmlspecialchars(substr($activity['description'], 0, 100)); ?>
                                                        <?php if (strlen($activity['description']) > 100): ?>...<?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="activity-stats">
                                                    <div class="activity-stat">
                                                        <span class="stat-value"><?php echo $activity['participants_count']; ?></span>
                                                        <span class="stat-label">Participants</span>
                                                    </div>
                                                    <?php if ($activity['budget'] > 0): ?>
                                                        <div class="activity-stat">
                                                            <span class="stat-value">RWF <?php echo number_format($activity['budget']); ?></span>
                                                            <span class="stat-label">Budget</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="activity-actions">
                                                <span class="status-badge status-<?php echo $activity['status']; ?>">
                                                    <?php echo ucfirst($activity['status']); ?>
                                                </span>
                                                <div class="action-buttons">
                                                    <a href="?action=edit_activity&association_id=<?php echo $association_id; ?>&activity_id=<?php echo $activity['id']; ?>" 
                                                       class="action-link" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?action=view&association_id=<?php echo $association_id; ?>&delete=activity&activity_id=<?php echo $activity['id']; ?>" 
                                                       class="action-link danger" 
                                                       onclick="return confirm('Are you sure you want to delete this activity?')"
                                                       title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Settings Tab -->
                        <div id="settings" class="tab-content">
                            <div class="tab-header">
                                <h4><i class="fas fa-cog"></i> Association Settings</h4>
                            </div>
                            <div class="settings-actions">
                                <a href="?action=edit_association&association_id=<?php echo $association_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Edit Association Details
                                </a>
                                <a href="?action=view&association_id=<?php echo $association_id; ?>&delete=association" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this association? This action cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete Association
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action === 'add_association' || $action === 'edit_association'): ?>
                <!-- Add/Edit Association Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas <?php echo $action === 'add_association' ? 'fa-plus-circle' : 'fa-edit'; ?>"></i> 
                            <?php echo $action === 'add_association' ? 'Create New Association' : 'Edit Association'; ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?action=<?php echo $action; ?><?php echo $association_id ? '&association_id=' . $association_id : ''; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="name">Association Name <span style="color: var(--danger);">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['name']) : ''; ?>" 
                                           required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="type">Association Type <span style="color: var(--danger);">*</span></label>
                                    <select class="form-control" id="type" name="type" required>
                                        <option value="religious" <?php echo (isset($current_association) && $current_association['type'] === 'religious') ? 'selected' : ''; ?>>Religious</option>
                                        <option value="cultural" <?php echo (isset($current_association) && $current_association['type'] === 'cultural') ? 'selected' : ''; ?>>Cultural</option>
                                        <option value="academic" <?php echo (isset($current_association) && $current_association['type'] === 'academic') ? 'selected' : ''; ?>>Academic</option>
                                        <option value="sports" <?php echo (isset($current_association) && $current_association['type'] === 'sports') ? 'selected' : ''; ?>>Sports</option>
                                        <option value="social" <?php echo (isset($current_association) && $current_association['type'] === 'social') ? 'selected' : ''; ?>>Social</option>
                                        <option value="other" <?php echo (isset($current_association) && $current_association['type'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($current_association) ? htmlspecialchars($current_association['description']) : ''; ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="established_date">Established Date</label>
                                    <input type="date" class="form-control" id="established_date" name="established_date" 
                                           value="<?php echo isset($current_association) ? $current_association['established_date'] : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="active" <?php echo (isset($current_association) && $current_association['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($current_association) && $current_association['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="suspended" <?php echo (isset($current_association) && $current_association['status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="meeting_schedule">Meeting Schedule</label>
                                    <input type="text" class="form-control" id="meeting_schedule" name="meeting_schedule" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['meeting_schedule']) : ''; ?>"
                                           placeholder="e.g., Every Monday at 4:00 PM">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="meeting_location">Meeting Location</label>
                                    <input type="text" class="form-control" id="meeting_location" name="meeting_location" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['meeting_location']) : ''; ?>"
                                           placeholder="e.g., Main Hall Room 101">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="faculty_advisor">Faculty Advisor</label>
                                    <input type="text" class="form-control" id="faculty_advisor" name="faculty_advisor" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['faculty_advisor']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="advisor_contact">Advisor Contact</label>
                                    <input type="text" class="form-control" id="advisor_contact" name="advisor_contact" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['advisor_contact']) : ''; ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="contact_person">Contact Person</label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['contact_person']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="contact_email">Contact Email</label>
                                    <input type="email" class="form-control" id="contact_email" name="contact_email" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['contact_email']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="contact_phone">Contact Phone</label>
                                    <input type="tel" class="form-control" id="contact_phone" name="contact_phone" 
                                           value="<?php echo isset($current_association) ? htmlspecialchars($current_association['contact_phone']) : ''; ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="goals">Goals & Objectives</label>
                                <textarea class="form-control" id="goals" name="goals" rows="3"><?php echo isset($current_association) ? htmlspecialchars($current_association['goals']) : ''; ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="achievements">Key Achievements</label>
                                <textarea class="form-control" id="achievements" name="achievements" rows="3"><?php echo isset($current_association) ? htmlspecialchars($current_association['achievements']) : ''; ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="performance_notes">Performance Notes</label>
                                <textarea class="form-control" id="performance_notes" name="performance_notes" rows="3"><?php echo isset($current_association) ? htmlspecialchars($current_association['performance_notes']) : ''; ?></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'add_association' ? 'Create Association' : 'Update Association'; ?>
                                </button>
                                <a href="?action=<?php echo $action === 'add_association' ? 'dashboard' : 'view&association_id=' . $association_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif (($action === 'add_member' || $action === 'edit_member') && $association_id): ?>
                <!-- Add/Edit Member Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas <?php echo $action === 'add_member' ? 'fa-user-plus' : 'fa-user-edit'; ?>"></i> 
                            <?php echo $action === 'add_member' ? 'Add New Member' : 'Edit Member'; ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?action=<?php echo $action; ?>&association_id=<?php echo $association_id; ?><?php echo $member_id ? '&member_id=' . $member_id : ''; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="reg_number">Registration Number <span style="color: var(--danger);">*</span></label>
                                    <input type="text" class="form-control" id="reg_number" name="reg_number" 
                                           value="<?php echo isset($current_member) ? htmlspecialchars($current_member['reg_number']) : ''; ?>" 
                                           required>
                                    <div class="form-text">Format: 25RP01234</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="name">Full Name <span style="color: var(--danger);">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo isset($current_member) ? htmlspecialchars($current_member['name']) : ''; ?>" 
                                           required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="email">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo isset($current_member) ? htmlspecialchars($current_member['email']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="phone">Phone</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo isset($current_member) ? htmlspecialchars($current_member['phone']) : ''; ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="department_id">Department</label>
                                    <select class="form-control" id="department_id" name="department_id">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?php echo $department['id']; ?>" 
                                                    <?php echo (isset($current_member) && $current_member['department_id'] == $department['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($department['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="program_id">Program</label>
                                    <select class="form-control" id="program_id" name="program_id">
                                        <option value="">Select Program</option>
                                        <?php foreach ($programs as $program): ?>
                                            <option value="<?php echo $program['id']; ?>" 
                                                    <?php echo (isset($current_member) && $current_member['program_id'] == $program['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($program['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="academic_year">Academic Year</label>
                                    <select class="form-control" id="academic_year" name="academic_year">
                                        <option value="">Select Year</option>
                                        <option value="Year 1" <?php echo (isset($current_member) && $current_member['academic_year'] === 'Year 1') ? 'selected' : ''; ?>>Year 1</option>
                                        <option value="Year 2" <?php echo (isset($current_member) && $current_member['academic_year'] === 'Year 2') ? 'selected' : ''; ?>>Year 2</option>
                                        <option value="Year 3" <?php echo (isset($current_member) && $current_member['academic_year'] === 'Year 3') ? 'selected' : ''; ?>>Year 3</option>
                                        <option value="B-Tech" <?php echo (isset($current_member) && $current_member['academic_year'] === 'B-Tech') ? 'selected' : ''; ?>>B-Tech</option>
                                        <option value="M-Tech" <?php echo (isset($current_member) && $current_member['academic_year'] === 'M-Tech') ? 'selected' : ''; ?>>M-Tech</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="role">Role <span style="color: var(--danger);">*</span></label>
                                    <select class="form-control" id="role" name="role" required>
                                        <option value="member" <?php echo (isset($current_member) && $current_member['role'] === 'member') ? 'selected' : ''; ?>>Member</option>
                                        <option value="president" <?php echo (isset($current_member) && $current_member['role'] === 'president') ? 'selected' : ''; ?>>President</option>
                                        <option value="vice_president" <?php echo (isset($current_member) && $current_member['role'] === 'vice_president') ? 'selected' : ''; ?>>Vice President</option>
                                        <option value="secretary" <?php echo (isset($current_member) && $current_member['role'] === 'secretary') ? 'selected' : ''; ?>>Secretary</option>
                                        <option value="treasurer" <?php echo (isset($current_member) && $current_member['role'] === 'treasurer') ? 'selected' : ''; ?>>Treasurer</option>
                                        <option value="advisor" <?php echo (isset($current_member) && $current_member['role'] === 'advisor') ? 'selected' : ''; ?>>Advisor</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="join_date">Join Date <span style="color: var(--danger);">*</span></label>
                                    <input type="date" class="form-control" id="join_date" name="join_date" 
                                           value="<?php echo isset($current_member) ? $current_member['join_date'] : date('Y-m-d'); ?>" 
                                           required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="active" <?php echo (isset($current_member) && $current_member['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($current_member) && $current_member['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="graduated" <?php echo (isset($current_member) && $current_member['status'] === 'graduated') ? 'selected' : ''; ?>>Graduated</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="membership_notes">Membership Notes</label>
                                <textarea class="form-control" id="membership_notes" name="membership_notes" rows="3"><?php echo isset($current_member) ? htmlspecialchars($current_member['membership_notes']) : ''; ?></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'add_member' ? 'Add Member' : 'Update Member'; ?>
                                </button>
                                <a href="?action=view&association_id=<?php echo $association_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif (($action === 'add_activity' || $action === 'edit_activity') && $association_id): ?>
                <!-- Add/Edit Activity Form -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas <?php echo $action === 'add_activity' ? 'fa-calendar-plus' : 'fa-edit'; ?>"></i> 
                            <?php echo $action === 'add_activity' ? 'Schedule New Activity' : 'Edit Activity'; ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?action=<?php echo $action; ?>&association_id=<?php echo $association_id; ?><?php echo $activity_id ? '&activity_id=' . $activity_id : ''; ?>">
                            <div class="form-group">
                                <label class="form-label" for="title">Activity Title <span style="color: var(--danger);">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo isset($current_activity) ? htmlspecialchars($current_activity['title']) : ''; ?>" 
                                       required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($current_activity) ? htmlspecialchars($current_activity['description']) : ''; ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="activity_type">Activity Type <span style="color: var(--danger);">*</span></label>
                                    <select class="form-control" id="activity_type" name="activity_type" required>
                                        <option value="meeting" <?php echo (isset($current_activity) && $current_activity['activity_type'] === 'meeting') ? 'selected' : ''; ?>>Meeting</option>
                                        <option value="workshop" <?php echo (isset($current_activity) && $current_activity['activity_type'] === 'workshop') ? 'selected' : ''; ?>>Workshop</option>
                                        <option value="event" <?php echo (isset($current_activity) && $current_activity['activity_type'] === 'event') ? 'selected' : ''; ?>>Event</option>
                                        <option value="community_service" <?php echo (isset($current_activity) && $current_activity['activity_type'] === 'community_service') ? 'selected' : ''; ?>>Community Service</option>
                                        <option value="training" <?php echo (isset($current_activity) && $current_activity['activity_type'] === 'training') ? 'selected' : ''; ?>>Training</option>
                                        <option value="other" <?php echo (isset($current_activity) && $current_activity['activity_type'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="scheduled" <?php echo (isset($current_activity) && $current_activity['status'] === 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="ongoing" <?php echo (isset($current_activity) && $current_activity['status'] === 'ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                                        <option value="completed" <?php echo (isset($current_activity) && $current_activity['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo (isset($current_activity) && $current_activity['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="activity_date">Activity Date <span style="color: var(--danger);">*</span></label>
                                    <input type="date" class="form-control" id="activity_date" name="activity_date" 
                                           value="<?php echo isset($current_activity) ? $current_activity['activity_date'] : date('Y-m-d'); ?>" 
                                           required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="start_time">Start Time</label>
                                    <input type="time" class="form-control" id="start_time" name="start_time" 
                                           value="<?php echo isset($current_activity) ? $current_activity['start_time'] : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="end_time">End Time</label>
                                    <input type="time" class="form-control" id="end_time" name="end_time" 
                                           value="<?php echo isset($current_activity) ? $current_activity['end_time'] : ''; ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="location">Location</label>
                                    <input type="text" class="form-control" id="location" name="location" 
                                           value="<?php echo isset($current_activity) ? htmlspecialchars($current_activity['location']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="participants_count">Expected Participants</label>
                                    <input type="number" class="form-control" id="participants_count" name="participants_count" 
                                           value="<?php echo isset($current_activity) ? $current_activity['participants_count'] : '0'; ?>" 
                                           min="0">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="budget">Budget (RWF)</label>
                                    <input type="number" class="form-control" id="budget" name="budget" 
                                           value="<?php echo isset($current_activity) ? $current_activity['budget'] : '0'; ?>" 
                                           min="0" step="100">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="notes">Additional Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo isset($current_activity) ? htmlspecialchars($current_activity['notes']) : ''; ?></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'add_activity' ? 'Schedule Activity' : 'Update Activity'; ?>
                                </button>
                                <a href="?action=view&association_id=<?php echo $association_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Dark Mode Toggle
        // const themeToggle = document.getElementById('themeToggle');
        // const body = document.body;

        // const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        // if (savedTheme === 'dark') {
        //     body.classList.add('dark-mode');
        //     themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        // }

        // themeToggle.addEventListener('click', () => {
        //     body.classList.toggle('dark-mode');
        //     const isDark = body.classList.contains('dark-mode');
        //     localStorage.setItem('theme', isDark ? 'dark' : 'light');
        //     themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        // });

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

        // Tab functionality
        function showTab(tabName) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.classList.remove('active');
            });

            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });

            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Auto-hide success messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });
    </script>
</body>
</html>