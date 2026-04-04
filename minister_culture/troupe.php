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

// Get sidebar statistics
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets_all = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets_all = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (PDOException $e) {
        $pending_reports = 0;
    }
    
    // Unread messages
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    } catch (PDOException $e) {
        $unread_messages = 0;
    }
    
    // New students count
    $new_students = 0;
    try {
        $new_students_stmt = $pdo->prepare("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= NOW() - INTERVAL '7 days'
        ");
        $new_students_stmt->execute();
        $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    } catch (PDOException $e) {
        $new_students = 0;
    }
    
    // Upcoming meetings count
    $upcoming_meetings = 0;
    try {
        $upcoming_meetings = $pdo->query("
            SELECT COUNT(*) as count FROM meetings 
            WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $upcoming_meetings = 0;
    }
    
    // Pending minutes count
    $pending_minutes = 0;
    try {
        $pending_minutes = $pdo->query("
            SELECT COUNT(*) as count FROM meeting_minutes 
            WHERE approval_status = 'submitted'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $pending_minutes = 0;
    }
    
    // Pending tickets for badge
    $pending_tickets_badge = 0;
    try {
        $ticketStmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE status IN ('open', 'in_progress') 
            AND (assigned_to = ? OR assigned_to IS NULL)
        ");
        $ticketStmt->execute([$user_id]);
        $pending_tickets_badge = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets_badge = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (PDOException $e) {
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    $total_tickets_all = $open_tickets_all = $pending_reports = $unread_messages = 0;
    $new_students = $upcoming_meetings = $pending_minutes = $pending_tickets_badge = $pending_docs = 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_troupe'])) {
        // Add new troupe
        $name = trim($_POST['name']);
        $type = $_POST['type'];
        $description = trim($_POST['description']);
        $established_date = $_POST['established_date'] ?: null;
        $practice_schedule = trim($_POST['practice_schedule']);
        $practice_location = trim($_POST['practice_location']);
        $director = trim($_POST['director']);
        $director_contact = trim($_POST['director_contact']);
        $achievements = trim($_POST['achievements']);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupes (name, type, description, established_date, 
                practice_schedule, practice_location, director, director_contact, 
                achievements, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $type, $description, $established_date,
                $practice_schedule, $practice_location, $director, $director_contact,
                $achievements, $user_id
            ]);
            
            $_SESSION['success_message'] = "Troupe created successfully!";
            header("Location: troupe.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error creating troupe: " . $e->getMessage();
            header("Location: troupe.php");
            exit();
        }
    }
    
    if (isset($_POST['add_member'])) {
        // Add member to troupe
        $troupe_id = $_POST['troupe_id'];
        $reg_number = trim($_POST['reg_number']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $department_id = $_POST['department_id'] ?: null;
        $program_id = $_POST['program_id'] ?: null;
        $academic_year = $_POST['academic_year'];
        $role = $_POST['role'];
        $specialization = trim($_POST['specialization']);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupe_members (troupe_id, reg_number, name, email, phone, 
                department_id, program_id, academic_year, role, specialization, join_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
            ");
            $stmt->execute([
                $troupe_id, $reg_number, $name, $email, $phone,
                $department_id, $program_id, $academic_year, $role, $specialization
            ]);
            
            // Update troupe members count
            $stmt = $pdo->prepare("UPDATE troupes SET members_count = members_count + 1 WHERE id = ?");
            $stmt->execute([$troupe_id]);
            
            $_SESSION['success_message'] = "Member added successfully!";
            header("Location: troupe.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding member: " . $e->getMessage();
            header("Location: troupe.php");
            exit();
        }
    }
    
    if (isset($_POST['add_activity'])) {
        // Add troupe activity
        $troupe_id = $_POST['troupe_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $activity_type = $_POST['activity_type'];
        $activity_date = $_POST['activity_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $location = trim($_POST['location']);
        $budget = $_POST['budget'] ?: 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupe_activities (troupe_id, title, description, activity_type, 
                activity_date, start_time, end_time, location, budget, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $troupe_id, $title, $description, $activity_type,
                $activity_date, $start_time, $end_time, $location, $budget, $user_id
            ]);
            
            $_SESSION['success_message'] = "Activity added successfully!";
            header("Location: troupe.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding activity: " . $e->getMessage();
            header("Location: troupe.php");
            exit();
        }
    }
    
    if (isset($_POST['add_achievement'])) {
        // Add troupe achievement
        $troupe_id = $_POST['troupe_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $achievement_type = $_POST['achievement_type'];
        $event_name = trim($_POST['event_name']);
        $event_date = $_POST['event_date'] ?: null;
        $position = trim($_POST['position']);
        $prize = trim($_POST['prize']);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupe_achievements (troupe_id, title, description, achievement_type, 
                event_name, event_date, position, prize, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $troupe_id, $title, $description, $achievement_type,
                $event_name, $event_date, $position, $prize, $user_id
            ]);
            
            $_SESSION['success_message'] = "Achievement added successfully!";
            header("Location: troupe.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding achievement: " . $e->getMessage();
            header("Location: troupe.php");
            exit();
        }
    }
    
    if (isset($_POST['update_troupe_status'])) {
        // Update troupe status
        $troupe_id = $_POST['troupe_id'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE troupes SET status = ? WHERE id = ?");
            $stmt->execute([$status, $troupe_id]);
            $_SESSION['success_message'] = "Troupe status updated successfully!";
            header("Location: troupe.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating troupe status: " . $e->getMessage();
            header("Location: troupe.php");
            exit();
        }
    }
}

// Get all troupes (PostgreSQL compatible)
try {
    $stmt = $pdo->query("
        SELECT t.*, COUNT(tm.id) as actual_members_count 
        FROM troupes t 
        LEFT JOIN troupe_members tm ON t.id = tm.troupe_id AND tm.status = 'active'
        GROUP BY t.id 
        ORDER BY t.name
    ");
    $troupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $troupes = [];
    error_log("Error fetching troupes: " . $e->getMessage());
}

// Get departments for dropdowns
try {
    $stmt = $pdo->query("SELECT * FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Get programs for dropdowns
try {
    $stmt = $pdo->query("SELECT * FROM programs WHERE is_active = true ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
}

// Get troupe members for a specific troupe (if requested)
$troupe_members = [];
if (isset($_GET['view_members']) && is_numeric($_GET['view_members'])) {
    $troupe_id = (int)$_GET['view_members'];
    try {
        $stmt = $pdo->prepare("
            SELECT tm.*, d.name as department_name, p.name as program_name
            FROM troupe_members tm
            LEFT JOIN departments d ON tm.department_id = d.id
            LEFT JOIN programs p ON tm.program_id = p.id
            WHERE tm.troupe_id = ? AND tm.status = 'active'
            ORDER BY 
                CASE tm.role 
                    WHEN 'director' THEN 1
                    WHEN 'assistant_director' THEN 2
                    WHEN 'lead_performer' THEN 3
                    WHEN 'choreographer' THEN 4
                    WHEN 'musician' THEN 5
                    WHEN 'vocalist' THEN 6
                    ELSE 7
                END,
                tm.name
        ");
        $stmt->execute([$troupe_id]);
        $troupe_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching troupe members: " . $e->getMessage());
    }
}

// Get troupe activities for a specific troupe (if requested)
$troupe_activities = [];
if (isset($_GET['view_activities']) && is_numeric($_GET['view_activities'])) {
    $troupe_id = (int)$_GET['view_activities'];
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM troupe_activities 
            WHERE troupe_id = ? 
            ORDER BY activity_date DESC, start_time DESC
            LIMIT 10
        ");
        $stmt->execute([$troupe_id]);
        $troupe_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching troupe activities: " . $e->getMessage());
    }
}

// Get troupe achievements for a specific troupe (if requested)
$troupe_achievements = [];
if (isset($_GET['view_achievements']) && is_numeric($_GET['view_achievements'])) {
    $troupe_id = (int)$_GET['view_achievements'];
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM troupe_achievements 
            WHERE troupe_id = ? 
            ORDER BY event_date DESC
            LIMIT 10
        ");
        $stmt->execute([$troupe_id]);
        $troupe_achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching troupe achievements: " . $e->getMessage());
    }
}

// Calculate statistics
$total_troupes = count($troupes);
$total_members = 0;
$active_troupes = 0;
foreach ($troupes as $troupe) {
    $total_members += $troupe['actual_members_count'];
    if ($troupe['status'] === 'active') $active_troupes++;
}

// Get upcoming activities count
$upcoming_count = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as upcoming_count 
        FROM troupe_activities 
        WHERE activity_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'
        AND status = 'scheduled'
    ");
    $upcoming_count = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_count'] ?? 0;
} catch (PDOException $e) {
    $upcoming_count = 0;
}

// Success/Error messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>College Troupe Management - Minister of Culture</title>
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
            --info: #17a2b8;
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
            --light-purple: #1f1a2e;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #4dd0e1;
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
            color: var(--primary-purple);
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

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.85rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.7rem;
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

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            background: var(--light-purple);
            color: var(--primary-purple);
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
            background: var(--light-purple);
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .tab:hover {
            color: var(--primary-purple);
        }

        .tab.active {
            color: var(--primary-purple);
            border-bottom-color: var(--primary-purple);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Table */
        .table-wrapper {
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

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-suspended {
            background: #fff3cd;
            color: #856404;
        }

        .status-scheduled {
            background: #cce7ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .status-select {
            padding: 0.3rem 0.5rem;
            font-size: 0.7rem;
            width: auto;
            display: inline-block;
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

        .modal.active {
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
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-purple);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
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

        .close-modal {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
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
            font-size: 0.75rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
                background: var(--primary-purple);
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

            #sidebarToggleBtn {
                display: none;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-buttons {
                flex-direction: column;
            }

            .status-select {
                width: 100%;
                margin-top: 0.5rem;
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

            .page-title h1 {
                font-size: 1.2rem;
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
                    <h1>Isonga - College Troupe</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                   
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
                    <a href="clubs.php">
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
                        <?php if ($pending_tickets_badge > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets_badge; ?></span>
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
                    <a href="troupe.php" class="active">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
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
            <div class="page-header">
               
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addTroupeModal')">
                        <i class="fas fa-plus"></i> Create Troupe
                    </button>
                    <button class="btn btn-outline" onclick="openModal('addMemberModal')">
                        <i class="fas fa-user-plus"></i> Add Member
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_troupes; ?></div>
                        <div class="stat-label">Total Troupes</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_members); ?></div>
                        <div class="stat-label">Total Members</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_troupes; ?></div>
                        <div class="stat-label">Active Troupes</div>
                    </div>
                </div>
                
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?php echo !isset($_GET['view_members']) && !isset($_GET['view_activities']) && !isset($_GET['view_achievements']) ? 'active' : ''; ?>" onclick="switchTab(event, 'troupes-tab')">All Troupes</button>
                <?php if (isset($_GET['view_members'])): ?>
                    <button class="tab active" onclick="switchTab(event, 'members-tab')">Troupe Members</button>
                <?php endif; ?>
                <?php if (isset($_GET['view_activities'])): ?>
                    <button class="tab active" onclick="switchTab(event, 'activities-tab')">Troupe Activities</button>
                <?php endif; ?>
                <?php if (isset($_GET['view_achievements'])): ?>
                    <button class="tab active" onclick="switchTab(event, 'achievements-tab')">Troupe Achievements</button>
                <?php endif; ?>
            </div>

            <!-- Troupes Tab -->
            <div id="troupes-tab" class="tab-content <?php echo !isset($_GET['view_members']) && !isset($_GET['view_activities']) && !isset($_GET['view_achievements']) ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>College Troupes</h3>
                        <div class="action-buttons">
                            <button class="btn btn-outline btn-sm" onclick="openModal('addActivityModal')">
                                <i class="fas fa-calendar-plus"></i> Add Activity
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="openModal('addAchievementModal')">
                                <i class="fas fa-trophy"></i> Add Achievement
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($troupes)): ?>
                            <div class="empty-state">
                                <i class="fas fa-music"></i>
                                <p>No troupes found. Create your first troupe to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Troupe Name</th>
                                            <th>Type</th>
                                            <th>Members</th>
                                            <th>Director</th>
                                            <th>Established</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($troupes as $troupe): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($troupe['name']); ?></strong>
                                                    <?php if (!empty($troupe['description'])): ?>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($troupe['description'], 0, 60)) . (strlen($troupe['description']) > 60 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-active">
                                                        <?php echo ucfirst($troupe['type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $troupe['actual_members_count']; ?></td>
                                                <td><?php echo htmlspecialchars($troupe['director'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M Y', strtotime($troupe['established_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $troupe['status']; ?>">
                                                        <?php echo ucfirst($troupe['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?view_members=<?php echo $troupe['id']; ?>" class="btn btn-outline btn-sm">
                                                            <i class="fas fa-users"></i> Members
                                                        </a>
                                                        <a href="?view_activities=<?php echo $troupe['id']; ?>" class="btn btn-outline btn-sm">
                                                            <i class="fas fa-calendar"></i> Activities
                                                        </a>
                                                        <a href="?view_achievements=<?php echo $troupe['id']; ?>" class="btn btn-outline btn-sm">
                                                            <i class="fas fa-trophy"></i> Achievements
                                                        </a>
                                                        <form method="POST" style="display: inline-block;">
                                                            <input type="hidden" name="troupe_id" value="<?php echo $troupe['id']; ?>">
                                                            <select name="status" onchange="this.form.submit()" class="form-select status-select">
                                                                <option value="active" <?php echo $troupe['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="inactive" <?php echo $troupe['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                                <option value="suspended" <?php echo $troupe['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                                            </select>
                                                            <input type="hidden" name="update_troupe_status">
                                                        </form>
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
            </div>

            <!-- Members Tab -->
            <?php if (isset($_GET['view_members'])): ?>
                <div id="members-tab" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3>Troupe Members</h3>
                            <a href="troupe.php" class="btn btn-outline btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Troupes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($troupe_members)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-friends"></i>
                                    <p>No members found for this troupe.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Reg Number</th>
                                                <th>Role</th>
                                                <th>Specialization</th>
                                                <th>Department</th>
                                                <th>Academic Year</th>
                                                <th>Join Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($troupe_members as $member): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($member['name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                    <td>
                                                        <span class="status-badge status-active">
                                                            <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($member['specialization'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($member['department_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($member['academic_year']); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Activities Tab -->
            <?php if (isset($_GET['view_activities'])): ?>
                <div id="activities-tab" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3>Troupe Activities</h3>
                            <a href="troupe.php" class="btn btn-outline btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Troupes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($troupe_activities)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar"></i>
                                    <p>No activities found for this troupe.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Activity</th>
                                                <th>Type</th>
                                                <th>Date & Time</th>
                                                <th>Location</th>
                                                <th>Budget</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($troupe_activities as $activity): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                                        <?php if (!empty($activity['description'])): ?>
                                                            <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                                <?php echo htmlspecialchars(substr($activity['description'], 0, 60)) . (strlen($activity['description']) > 60 ? '...' : ''); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo ucfirst($activity['activity_type']); ?></td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($activity['activity_date'])); ?><br>
                                                        <small><?php echo date('g:i A', strtotime($activity['start_time'])); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($activity['location']); ?></td>
                                                    <td><?php echo number_format($activity['budget'], 0); ?> RWF</td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $activity['status']; ?>">
                                                            <?php echo ucfirst($activity['status']); ?>
                                                        </span>
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
            <?php endif; ?>

            <!-- Achievements Tab -->
            <?php if (isset($_GET['view_achievements'])): ?>
                <div id="achievements-tab" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3>Troupe Achievements</h3>
                            <a href="troupe.php" class="btn btn-outline btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Troupes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($troupe_achievements)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-trophy"></i>
                                    <p>No achievements found for this troupe.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Achievement</th>
                                                <th>Type</th>
                                                <th>Event</th>
                                                <th>Date</th>
                                                <th>Position</th>
                                                <th>Prize</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($troupe_achievements as $achievement): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($achievement['title']); ?></strong>
                                                        <?php if (!empty($achievement['description'])): ?>
                                                            <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                                <?php echo htmlspecialchars(substr($achievement['description'], 0, 60)) . (strlen($achievement['description']) > 60 ? '...' : ''); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo ucfirst($achievement['achievement_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($achievement['event_name']); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($achievement['event_date'])); ?></td>
                                                    <td>
                                                        <span class="status-badge status-active">
                                                            <?php echo htmlspecialchars($achievement['position']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($achievement['prize']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Troupe Modal -->
    <div id="addTroupeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Create New Troupe</h3>
                <button class="close-modal" onclick="closeModal('addTroupeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Troupe Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Troupe Type *</label>
                            <select name="type" class="form-select" required>
                                <option value="dance">Dance</option>
                                <option value="music">Music</option>
                                <option value="drama">Drama</option>
                                <option value="traditional">Traditional</option>
                                <option value="multidisciplinary" selected>Multidisciplinary</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the troupe's focus, style, and purpose..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Established Date</label>
                            <input type="date" name="established_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Practice Location</label>
                            <input type="text" name="practice_location" class="form-control" placeholder="e.g., College Auditorium">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Practice Schedule</label>
                        <textarea name="practice_schedule" class="form-control" rows="2" placeholder="e.g., Monday and Wednesday 4:00 PM - 6:00 PM"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Director</label>
                            <input type="text" name="director" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Director Contact</label>
                            <input type="text" name="director_contact" class="form-control" placeholder="Email or phone">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Achievements</label>
                        <textarea name="achievements" class="form-control" rows="2" placeholder="List notable achievements..."></textarea>
                    </div>
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addTroupeModal')">Cancel</button>
                        <button type="submit" name="add_troupe" class="btn btn-primary">Create Troupe</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add Member to Troupe</h3>
                <button class="close-modal" onclick="closeModal('addMemberModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Troupe *</label>
                        <select name="troupe_id" class="form-select" required>
                            <option value="">Select a troupe</option>
                            <?php foreach ($troupes as $troupe): ?>
                                <option value="<?php echo $troupe['id']; ?>"><?php echo htmlspecialchars($troupe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Registration Number *</label>
                            <input type="text" name="reg_number" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Program</label>
                            <select name="program_id" class="form-select">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year" class="form-select">
                                <option value="Year 1">Year 1</option>
                                <option value="Year 2">Year 2</option>
                                <option value="Year 3">Year 3</option>
                                <option value="B-Tech">B-Tech</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <option value="member">Member</option>
                                <option value="lead_performer">Lead Performer</option>
                                <option value="choreographer">Choreographer</option>
                                <option value="musician">Musician</option>
                                <option value="vocalist">Vocalist</option>
                                <option value="director">Director</option>
                                <option value="assistant_director">Assistant Director</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Specialization</label>
                        <input type="text" name="specialization" class="form-control" placeholder="e.g., Traditional Dance, Drums, Acting">
                    </div>
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addMemberModal')">Cancel</button>
                        <button type="submit" name="add_member" class="btn btn-primary">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div id="addActivityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Add Troupe Activity</h3>
                <button class="close-modal" onclick="closeModal('addActivityModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Troupe *</label>
                        <select name="troupe_id" class="form-select" required>
                            <option value="">Select a troupe</option>
                            <?php foreach ($troupes as $troupe): ?>
                                <option value="<?php echo $troupe['id']; ?>"><?php echo htmlspecialchars($troupe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Activity Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Activity Type *</label>
                            <select name="activity_type" class="form-select" required>
                                <option value="practice">Practice</option>
                                <option value="competition">Competition</option>
                                <option value="performance">Performance</option>
                                <option value="workshop">Workshop</option>
                                <option value="rehearsal">Rehearsal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Activity Date *</label>
                            <input type="date" name="activity_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Budget (RWF)</label>
                        <input type="number" name="budget" class="form-control" step="0.01" min="0">
                    </div>
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addActivityModal')">Cancel</button>
                        <button type="submit" name="add_activity" class="btn btn-primary">Add Activity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Achievement Modal -->
    <div id="addAchievementModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-trophy"></i> Add Troupe Achievement</h3>
                <button class="close-modal" onclick="closeModal('addAchievementModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Troupe *</label>
                        <select name="troupe_id" class="form-select" required>
                            <option value="">Select a troupe</option>
                            <?php foreach ($troupes as $troupe): ?>
                                <option value="<?php echo $troupe['id']; ?>"><?php echo htmlspecialchars($troupe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Achievement Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Achievement Type *</label>
                            <select name="achievement_type" class="form-select" required>
                                <option value="competition">Competition</option>
                                <option value="performance">Performance</option>
                                <option value="award">Award</option>
                                <option value="recognition">Recognition</option>
                                <option value="certification">Certification</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Event Name</label>
                            <input type="text" name="event_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Event Date</label>
                            <input type="date" name="event_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Position/Award</label>
                            <input type="text" name="position" class="form-control" placeholder="e.g., 1st Place, Best Performance">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prize</label>
                            <input type="text" name="prize" class="form-control" placeholder="e.g., Trophy, Certificate, Cash Prize">
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addAchievementModal')">Cancel</button>
                        <button type="submit" name="add_achievement" class="btn btn-primary">Add Achievement</button>
                    </div>
                </form>
            </div>
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
                    : '<i class="fas fa-bars</i>';
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
        function switchTab(event, tabId) {
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
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });
    </script>
</body>
</html>