<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Sports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_sports') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

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

// Handle form actions
$action = $_GET['action'] ?? '';
$team_id = $_GET['id'] ?? '';

// Add new team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    try {
        $team_name = $_POST['team_name'];
        $sport_type = $_POST['sport_type'];
        $team_gender = $_POST['team_gender'];
        $category = $_POST['category'];
        $coach_id = $_POST['coach_id'] ?? null;
        $captain_id = $_POST['captain_id'] ?? null;
        $training_schedule = $_POST['training_schedule'] ?? '';
        
        $stmt = $pdo->prepare("
            INSERT INTO sports_teams 
            (team_name, sport_type, team_gender, category, coach_id, captain_id, training_schedule, created_by, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$team_name, $sport_type, $team_gender, $category, $coach_id, $captain_id, $training_schedule, $user_id]);
        
        $_SESSION['success_message'] = "Team created successfully!";
        header('Location: teams.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error creating team: " . $e->getMessage();
    }
}

// Update team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    try {
        $team_name = $_POST['team_name'];
        $sport_type = $_POST['sport_type'];
        $team_gender = $_POST['team_gender'];
        $category = $_POST['category'];
        $coach_id = $_POST['coach_id'] ?? null;
        $captain_id = $_POST['captain_id'] ?? null;
        $training_schedule = $_POST['training_schedule'] ?? '';
        $status = $_POST['status'];
        $achievements = $_POST['achievements'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE sports_teams 
            SET team_name = ?, sport_type = ?, team_gender = ?, category = ?, 
                coach_id = ?, captain_id = ?, training_schedule = ?, status = ?, achievements = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$team_name, $sport_type, $team_gender, $category, $coach_id, $captain_id, $training_schedule, $status, $achievements, $team_id]);
        
        $_SESSION['success_message'] = "Team updated successfully!";
        header('Location: teams.php?action=view&id=' . $team_id);
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating team: " . $e->getMessage();
    }
}

// Delete team
if ($action === 'delete' && $team_id) {
    try {
        // First delete team members
        $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ?");
        $stmt->execute([$team_id]);
        
        // Then delete the team
        $stmt = $pdo->prepare("DELETE FROM sports_teams WHERE id = ?");
        $stmt->execute([$team_id]);
        
        $_SESSION['success_message'] = "Team deleted successfully!";
        header('Location: teams.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting team: " . $e->getMessage();
    }
}

// Add team member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_member') {
    try {
        $reg_number = $_POST['reg_number'];
        $name = $_POST['name'];
        $department_id = $_POST['department_id'] ?? null;
        $position = $_POST['position'] ?? '';
        $jersey_number = $_POST['jersey_number'] ?? null;
        $skills = $_POST['skills'] ?? '';
        
        // Check if user exists and get user_id
        $user_id_member = null;
        if (!empty($reg_number)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ?");
            $stmt->execute([$reg_number]);
            $user_exists = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_id_member = $user_exists['id'] ?? null;
        }
        
        // Check if member already exists in this team
        $stmt = $pdo->prepare("SELECT id FROM team_members WHERE team_id = ? AND reg_number = ?");
        $stmt->execute([$team_id, $reg_number]);
        $existing_member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_member) {
            $_SESSION['error_message'] = "A member with registration number $reg_number already exists in this team!";
            header("Location: teams.php?action=view&id=$team_id");
            exit();
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO team_members 
            (team_id, user_id, reg_number, name, department_id, position, jersey_number, skills, join_date, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$team_id, $user_id_member, $reg_number, $name, $department_id, $position, $jersey_number, $skills]);
        
        // Update members count in sports_teams table
        $stmt = $pdo->prepare("
            UPDATE sports_teams 
            SET members_count = (SELECT COUNT(*) FROM team_members WHERE team_id = ? AND status = 'active'),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$team_id, $team_id]);
        
        $_SESSION['success_message'] = "Team member added successfully!";
        header("Location: teams.php?action=view&id=$team_id");
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding team member: " . $e->getMessage();
    }
}

// Update team member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_member') {
    $member_id = $_POST['member_id'];
    try {
        $reg_number = $_POST['reg_number'];
        $name = $_POST['name'];
        $department_id = $_POST['department_id'] ?? null;
        $position = $_POST['position'] ?? '';
        $jersey_number = $_POST['jersey_number'] ?? null;
        $skills = $_POST['skills'] ?? '';
        $performance_notes = $_POST['performance_notes'] ?? '';
        $status = $_POST['status'];
        
        // Check if user exists and get user_id
        $user_id_member = null;
        if (!empty($reg_number)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ?");
            $stmt->execute([$reg_number]);
            $user_exists = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_id_member = $user_exists['id'] ?? null;
        }
        
        $stmt = $pdo->prepare("
            UPDATE team_members 
            SET user_id = ?, reg_number = ?, name = ?, department_id = ?, position = ?, 
                jersey_number = ?, skills = ?, performance_notes = ?, status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND team_id = ?
        ");
        $stmt->execute([$user_id_member, $reg_number, $name, $department_id, $position, $jersey_number, $skills, $performance_notes, $status, $member_id, $team_id]);
        
        // Update members count if status changed
        $stmt = $pdo->prepare("
            UPDATE sports_teams 
            SET members_count = (SELECT COUNT(*) FROM team_members WHERE team_id = ? AND status = 'active'),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$team_id, $team_id]);
        
        $_SESSION['success_message'] = "Team member updated successfully!";
        header("Location: teams.php?action=view&id=$team_id");
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating team member: " . $e->getMessage();
    }
}

// Remove team member
if ($action === 'remove_member') {
    $member_id = $_GET['member_id'] ?? '';
    if ($member_id && $team_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM team_members WHERE id = ? AND team_id = ?");
            $stmt->execute([$member_id, $team_id]);
            
            // Update members count
            $stmt = $pdo->prepare("
                UPDATE sports_teams 
                SET members_count = (SELECT COUNT(*) FROM team_members WHERE team_id = ? AND status = 'active'),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$team_id, $team_id]);
            
            $_SESSION['success_message'] = "Team member removed successfully!";
            header("Location: teams.php?action=view&id=$team_id");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error removing team member: " . $e->getMessage();
        }
    }
}

// Get teams data
try {
    // All teams with proper member counts
    $stmt = $pdo->query("
        SELECT 
            st.*,
            u.full_name as coach_name,
            u2.full_name as captain_name
        FROM sports_teams st
        LEFT JOIN users u ON st.coach_id = u.id
        LEFT JOIN users u2 ON st.captain_id = u2.id
        ORDER BY st.created_at DESC
    ");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get actual member counts for each team
    foreach ($teams as &$team) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as member_count FROM team_members WHERE team_id = ? AND status = 'active'");
        $stmt->execute([$team['id']]);
        $member_count = $stmt->fetch(PDO::FETCH_ASSOC);
        $team['member_count'] = $member_count['member_count'] ?? 0;
    }
    unset($team);
    
    // Available coaches (all active users except students)
    $stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE status = 'active' 
        AND role != 'student'
        ORDER BY full_name
    ");
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Available students for team members
    $stmt = $pdo->query("
        SELECT id, reg_number, full_name, email, phone, department_id, program_id
        FROM users 
        WHERE status = 'active' 
        AND role = 'student'
        ORDER BY full_name
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Departments for dropdowns
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Team members for specific team if viewing details
    if ($action === 'view' && $team_id) {
        $stmt = $pdo->prepare("
            SELECT tm.*, d.name as department_name,
                   u.full_name as user_full_name, u.email as user_email, u.phone as user_phone
            FROM team_members tm
            LEFT JOIN departments d ON tm.department_id = d.id
            LEFT JOIN users u ON tm.user_id = u.id
            WHERE tm.team_id = ?
            ORDER BY tm.position, tm.name
        ");
        $stmt->execute([$team_id]);
        $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get team details
        $stmt = $pdo->prepare("
            SELECT st.*, u.full_name as coach_name, u2.full_name as captain_name
            FROM sports_teams st
            LEFT JOIN users u ON st.coach_id = u.id
            LEFT JOIN users u2 ON st.captain_id = u2.id
            WHERE st.id = ?
        ");
        $stmt->execute([$team_id]);
        $current_team = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get team member for editing
    if ($action === 'edit_member' && $team_id) {
        $member_id = $_GET['member_id'] ?? '';
        $stmt = $pdo->prepare("
            SELECT tm.*, d.name as department_name
            FROM team_members tm
            LEFT JOIN departments d ON tm.department_id = d.id
            WHERE tm.id = ? AND tm.team_id = ?
        ");
        $stmt->execute([$member_id, $team_id]);
        $edit_member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_member) {
            $_SESSION['error_message'] = "Team member not found!";
            header("Location: teams.php?action=view&id=$team_id");
            exit();
        }
        
        // Get current team for position options
        $stmt = $pdo->prepare("SELECT * FROM sports_teams WHERE id = ?");
        $stmt->execute([$team_id]);
        $current_team = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get team for editing
    if ($action === 'edit' && $team_id) {
        $stmt = $pdo->prepare("SELECT * FROM sports_teams WHERE id = ?");
        $stmt->execute([$team_id]);
        $edit_team = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Sports types for dropdown
    $sport_types = ['Football', 'Basketball', 'Volleyball', 'Tennis', 'Athletics', 'Swimming', 'Rugby', 'Cricket', 'Handball', 'Table Tennis', 'Badminton', 'Other'];
    
    // Common sports positions
    $sport_positions = [
        'Football' => ['Goalkeeper', 'Defender', 'Midfielder', 'Forward', 'Captain', 'Vice-Captain'],
        'Basketball' => ['Point Guard', 'Shooting Guard', 'Small Forward', 'Power Forward', 'Center', 'Captain'],
        'Volleyball' => ['Setter', 'Outside Hitter', 'Opposite Hitter', 'Middle Blocker', 'Libero', 'Captain'],
        'Tennis' => ['Singles Player', 'Doubles Player', 'Captain'],
        'Athletics' => ['Sprinter', 'Long Distance', 'Jumper', 'Thrower', 'Captain'],
        'General' => ['Player', 'Captain', 'Vice-Captain', 'Team Manager']
    ];
    
    // Statistics
    $total_teams = count($teams);
    $active_teams = array_filter($teams, function($team) {
        return $team['status'] === 'active';
    });
    $active_teams_count = count($active_teams);
    
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
        $category_id = 6; // Sports category
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE category_id = ? 
            AND status IN ('open', 'in_progress')
        ");
        $stmt->execute([$category_id]);
        $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets = 0;
    }
    
} catch (PDOException $e) {
    error_log("Teams data error: " . $e->getMessage());
    $teams = $coaches = $students = $departments = $team_members = [];
    $sport_types = [];
    $sport_positions = [];
    $total_teams = $active_teams_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Sports Teams Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #3B82F6;
            --secondary-blue: #60A5FA;
            --accent-blue: #1D4ED8;
            --light-blue: #EFF6FF;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
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
            color: var(--primary-blue);
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
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
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
            background: var(--light-blue);
            color: var(--primary-blue);
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
            background: var(--light-blue);
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
            background: var(--light-blue);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
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

        .status-injured {
            background: #fff3cd;
            color: #856404;
        }

        .status-graduated {
            background: #cce7ff;
            color: #004085;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.4rem 0.6rem;
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

        .action-btn:hover {
            transform: translateY(-1px);
        }

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

        .form-control, .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
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
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-blue);
            position: sticky;
            top: 0;
        }

        .modal-header h3 {
            font-size: 1rem;
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
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Team Details */
        .team-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1rem;
            border-left: 3px solid var(--primary-blue);
            box-shadow: var(--shadow-sm);
        }

        .detail-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        /* Member Card */
        .member-card {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .member-info {
            flex: 1;
        }

        .member-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .member-details {
            color: var(--dark-gray);
            font-size: 0.75rem;
        }

        .member-actions {
            display: flex;
            gap: 0.5rem;
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

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
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

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
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

            .team-details-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
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
                width: 95%;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .member-card {
                flex-direction: column;
                text-align: center;
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
                    <h1>Isonga - Minister of Sports</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
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
                    <a href="teams.php" class="active">
                        <i class="fas fa-users"></i>
                        <span>Sports Teams</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php">
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
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="competitions.php">
                        <i class="fas fa-trophy"></i>
                        <span>Competitions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="equipment.php">
                        <i class="fas fa-baseball-ball"></i>
                        <span>Equipment</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
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
            <?php if ($action === 'view' && $team_id && isset($current_team)): ?>
                <!-- Team Details View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1><i class="fas fa-users"></i> <?php echo htmlspecialchars($current_team['team_name']); ?></h1>
                        <p>Team management and member details</p>
                    </div>
                    <div class="page-actions">
                        <a href="teams.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Teams
                        </a>
                        <a href="teams.php?action=edit&id=<?php echo $team_id; ?>" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit Team
                        </a>
                        <button class="btn btn-primary" onclick="openModal('addMemberModal')">
                            <i class="fas fa-user-plus"></i> Add Member
                        </button>
                    </div>
                </div>

                <!-- Team Details -->
                <div class="team-details-grid">
                    <div class="detail-card">
                        <div class="detail-label">Sport Type</div>
                        <div class="detail-value"><?php echo htmlspecialchars($current_team['sport_type']); ?></div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Category & Gender</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars(ucfirst($current_team['category'])); ?> • 
                            <?php echo htmlspecialchars(ucfirst($current_team['team_gender'])); ?>
                        </div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Coach</div>
                        <div class="detail-value"><?php echo htmlspecialchars($current_team['coach_name'] ?? 'Not assigned'); ?></div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Captain</div>
                        <div class="detail-value"><?php echo htmlspecialchars($current_team['captain_name'] ?? 'Not assigned'); ?></div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Total Members</div>
                        <div class="detail-value"><?php echo count($team_members); ?> players</div>
                    </div>
                    <div class="detail-card">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <span class="status-badge status-<?php echo $current_team['status']; ?>">
                                <?php echo ucfirst($current_team['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($current_team['training_schedule']): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Training Schedule</h3>
                    </div>
                    <div class="card-body">
                        <p><?php echo nl2br(htmlspecialchars($current_team['training_schedule'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($current_team['achievements']): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-trophy"></i> Achievements</h3>
                    </div>
                    <div class="card-body">
                        <p><?php echo nl2br(htmlspecialchars($current_team['achievements'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Team Members -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-friends"></i> Team Members (<?php echo count($team_members); ?>)</h3>
                        <div class="card-header-actions">
                            <button class="btn btn-primary btn-sm" onclick="openModal('addMemberModal')">
                                <i class="fas fa-user-plus"></i> Add Member
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($team_members)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-friends"></i>
                                <h3>No Team Members</h3>
                                <p>Add members to build your team roster</p>
                                <button class="btn btn-primary" onclick="openModal('addMemberModal')">
                                    <i class="fas fa-user-plus"></i> Add First Member
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Reg Number</th>
                                            <th>Name</th>
                                            <th>Position</th>
                                            <th>Jersey</th>
                                            <th>Department</th>
                                            <th>Status</th>
                                            <th>Join Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($team_members as $member): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($member['reg_number']); ?></strong>
                                                    <?php if ($member['user_full_name'] && $member['user_full_name'] !== $member['name']): ?>
                                                        <br><small><?php echo htmlspecialchars($member['user_full_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['name']); ?></td>
                                                <td><?php echo htmlspecialchars($member['position']); ?></td>
                                                <td>
                                                    <?php if ($member['jersey_number']): ?>
                                                        <span class="status-badge" style="background: var(--primary-blue); color: white;">
                                                            #<?php echo $member['jersey_number']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['department_name'] ?? '-'); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $member['status']; ?>">
                                                        <?php echo ucfirst($member['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="action-btn edit" 
                                                                onclick="window.location.href='teams.php?action=edit_member&id=<?php echo $team_id; ?>&member_id=<?php echo $member['id']; ?>'" 
                                                                title="Edit Member">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="action-btn delete" 
                                                                onclick="confirmRemoveMember(<?php echo $member['id']; ?>)" 
                                                                title="Remove Member">
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

            <?php elseif ($action === 'edit_member' && $team_id && isset($edit_member)): ?>
                <!-- Edit Member View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1><i class="fas fa-user-edit"></i> Edit Team Member: <?php echo htmlspecialchars($edit_member['name']); ?></h1>
                        <p>Update member information and performance notes</p>
                    </div>
                    <div class="page-actions">
                        <a href="teams.php?action=view&id=<?php echo $team_id; ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Team
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="teams.php?action=edit_member&id=<?php echo $team_id; ?>">
                            <input type="hidden" name="member_id" value="<?php echo $edit_member['id']; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="reg_number">Registration Number <span style="color: var(--danger);">*</span></label>
                                    <input type="text" class="form-control" id="reg_number" name="reg_number" 
                                           value="<?php echo htmlspecialchars($edit_member['reg_number']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="name">Full Name <span style="color: var(--danger);">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($edit_member['name']); ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="department_id">Department</label>
                                    <select class="form-select" id="department_id" name="department_id">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" 
                                                <?php echo $edit_member['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="position">Position</label>
                                    <select class="form-select" id="position" name="position">
                                        <option value="">Select Position</option>
                                        <?php 
                                        $current_sport = $current_team['sport_type'] ?? 'General';
                                        $positions = $sport_positions[$current_sport] ?? $sport_positions['General'];
                                        foreach ($positions as $pos): ?>
                                            <option value="<?php echo $pos; ?>" 
                                                <?php echo $edit_member['position'] === $pos ? 'selected' : ''; ?>>
                                                <?php echo $pos; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="jersey_number">Jersey Number</label>
                                    <input type="number" class="form-control" id="jersey_number" name="jersey_number" 
                                           value="<?php echo htmlspecialchars($edit_member['jersey_number'] ?? ''); ?>" min="1" max="99">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active" <?php echo $edit_member['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="injured" <?php echo $edit_member['status'] === 'injured' ? 'selected' : ''; ?>>Injured</option>
                                        <option value="inactive" <?php echo $edit_member['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="graduated" <?php echo $edit_member['status'] === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="skills">Skills & Abilities</label>
                                <textarea class="form-control" id="skills" name="skills" rows="3" 
                                          placeholder="List player skills, strengths, special abilities..."><?php echo htmlspecialchars($edit_member['skills'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="performance_notes">Performance Notes</label>
                                <textarea class="form-control" id="performance_notes" name="performance_notes" rows="3" 
                                          placeholder="Coach's notes on performance, areas for improvement..."><?php echo htmlspecialchars($edit_member['performance_notes'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group" style="margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">Update Member</button>
                                <a href="teams.php?action=view&id=<?php echo $team_id; ?>" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'edit' && $team_id && isset($edit_team)): ?>
                <!-- Edit Team View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1><i class="fas fa-edit"></i> Edit Team: <?php echo htmlspecialchars($edit_team['team_name']); ?></h1>
                        <p>Update team information and settings</p>
                    </div>
                    <div class="page-actions">
                        <a href="teams.php?action=view&id=<?php echo $team_id; ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Team
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="teams.php?action=edit&id=<?php echo $team_id; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="team_name">Team Name <span style="color: var(--danger);">*</span></label>
                                    <input type="text" class="form-control" id="team_name" name="team_name" 
                                           value="<?php echo htmlspecialchars($edit_team['team_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="sport_type">Sport Type <span style="color: var(--danger);">*</span></label>
                                    <select class="form-select" id="sport_type" name="sport_type" required>
                                        <option value="">Select Sport Type</option>
                                        <?php foreach ($sport_types as $type): ?>
                                            <option value="<?php echo $type; ?>" 
                                                <?php echo $edit_team['sport_type'] === $type ? 'selected' : ''; ?>>
                                                <?php echo $type; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="team_gender">Team Gender <span style="color: var(--danger);">*</span></label>
                                    <select class="form-select" id="team_gender" name="team_gender" required>
                                        <option value="male" <?php echo $edit_team['team_gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $edit_team['team_gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="mixed" <?php echo $edit_team['team_gender'] === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="category">Category <span style="color: var(--danger);">*</span></label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="college" <?php echo $edit_team['category'] === 'college' ? 'selected' : ''; ?>>College</option>
                                        <option value="department" <?php echo $edit_team['category'] === 'department' ? 'selected' : ''; ?>>Department</option>
                                        <option value="club" <?php echo $edit_team['category'] === 'club' ? 'selected' : ''; ?>>Club</option>
                                        <option value="national" <?php echo $edit_team['category'] === 'national' ? 'selected' : ''; ?>>National</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="coach_id">Coach</label>
                                    <select class="form-select" id="coach_id" name="coach_id">
                                        <option value="">Select Coach</option>
                                        <?php foreach ($coaches as $coach): ?>
                                            <option value="<?php echo $coach['id']; ?>" 
                                                <?php echo $edit_team['coach_id'] == $coach['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($coach['full_name']); ?> (<?php echo $coach['role']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="captain_id">Team Captain</label>
                                    <select class="form-select" id="captain_id" name="captain_id">
                                        <option value="">Select Captain</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>" 
                                                <?php echo $edit_team['captain_id'] == $student['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo $student['reg_number']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="training_schedule">Training Schedule</label>
                                <textarea class="form-control" id="training_schedule" name="training_schedule" rows="3" 
                                          placeholder="E.g., Monday & Wednesday 4-6pm, Saturday 9-11am..."><?php echo htmlspecialchars($edit_team['training_schedule'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="achievements">Achievements</label>
                                <textarea class="form-control" id="achievements" name="achievements" rows="3" 
                                          placeholder="List team achievements, awards, tournaments won..."><?php echo htmlspecialchars($edit_team['achievements'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?php echo $edit_team['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $edit_team['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $edit_team['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">Update Team</button>
                                <a href="teams.php?action=view&id=<?php echo $team_id; ?>" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Main Teams List View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1><i class="fas fa-users"></i> Sports Teams Management</h1>
                        <p>Manage all sports teams, members, and team information</p>
                    </div>
                    <div class="page-actions">
                        <button class="btn btn-primary" onclick="openModal('addTeamModal')">
                            <i class="fas fa-plus"></i> Add New Team
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

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_teams); ?></div>
                            <div class="stat-label">Total Teams</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($active_teams_count); ?></div>
                            <div class="stat-label">Active Teams</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php 
                                $total_members = array_sum(array_column($teams, 'member_count'));
                                echo number_format($total_members); 
                                ?>
                            </div>
                            <div class="stat-label">Total Members</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_teams - $active_teams_count); ?></div>
                            <div class="stat-label">Inactive Teams</div>
                        </div>
                    </div>
                </div>

                <!-- Teams Table -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> All Sports Teams</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($teams)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <h3>No Sports Teams Found</h3>
                                <p>Get started by creating your first sports team.</p>
                                <button class="btn btn-primary" onclick="openModal('addTeamModal')">
                                    <i class="fas fa-plus"></i> Create First Team
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Team Name</th>
                                            <th>Sport Type</th>
                                            <th>Category</th>
                                            <th>Members</th>
                                            <th>Coach</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teams as $team): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($team['team_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($team['sport_type']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars(ucfirst($team['team_gender'])); ?><br>
                                                    <small class="form-text"><?php echo htmlspecialchars($team['category']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="status-badge" style="background: var(--primary-blue); color: white;">
                                                        <?php echo $team['member_count']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($team['coach_name'] ?? 'Not assigned'); ?>                                                    </tr>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $team['status']; ?>">
                                                        <?php echo ucfirst($team['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($team['created_at'])); ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="teams.php?action=view&id=<?php echo $team['id']; ?>" class="action-btn view" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="teams.php?action=edit&id=<?php echo $team['id']; ?>" class="action-btn edit" title="Edit Team">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button class="action-btn delete" onclick="confirmDelete(<?php echo $team['id']; ?>)" title="Delete Team">
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
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Team Modal -->
    <div class="modal" id="addTeamModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Sports Team</h3>
                <button class="modal-close" onclick="closeModal('addTeamModal')">&times;</button>
            </div>
            <form method="POST" action="teams.php?action=add">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="team_name">Team Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" class="form-control" id="team_name" name="team_name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="sport_type">Sport Type <span style="color: var(--danger);">*</span></label>
                            <select class="form-select" id="sport_type" name="sport_type" required>
                                <option value="">Select Sport Type</option>
                                <?php foreach ($sport_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="team_gender">Team Gender <span style="color: var(--danger);">*</span></label>
                            <select class="form-select" id="team_gender" name="team_gender" required>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="category">Category <span style="color: var(--danger);">*</span></label>
                            <select class="form-select" id="category" name="category" required>
                                <option value="college">College</option>
                                <option value="department">Department</option>
                                <option value="club">Club</option>
                                <option value="national">National</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="coach_id">Coach</label>
                            <select class="form-select" id="coach_id" name="coach_id">
                                <option value="">Select Coach</option>
                                <?php foreach ($coaches as $coach): ?>
                                    <option value="<?php echo $coach['id']; ?>">
                                        <?php echo htmlspecialchars($coach['full_name']); ?> (<?php echo $coach['role']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="captain_id">Team Captain</label>
                            <select class="form-select" id="captain_id" name="captain_id">
                                <option value="">Select Captain</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo $student['reg_number']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="training_schedule">Training Schedule</label>
                        <textarea class="form-control" id="training_schedule" name="training_schedule" rows="3" 
                                  placeholder="E.g., Monday & Wednesday 4-6pm, Saturday 9-11am..."></textarea>
                        <div class="form-text">Optional: Enter the regular training schedule for this team</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addTeamModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Team</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Team Member Modal -->
    <?php if ($action === 'view' && $team_id && isset($current_team)): ?>
    <div class="modal" id="addMemberModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add Team Member to <?php echo htmlspecialchars($current_team['team_name']); ?></h3>
                <button class="modal-close" onclick="closeModal('addMemberModal')">&times;</button>
            </div>
            <form method="POST" action="teams.php?action=add_member&id=<?php echo $team_id; ?>">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="reg_number">Registration Number <span style="color: var(--danger);">*</span></label>
                            <input type="text" class="form-control" id="reg_number" name="reg_number" required>
                            <div class="form-text">Enter student registration number</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="name">Full Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="department_id">Department</label>
                            <select class="form-select" id="department_id" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="position">Position</label>
                            <select class="form-select" id="position" name="position">
                                <option value="">Select Position</option>
                                <?php 
                                $current_sport = $current_team['sport_type'];
                                $positions = $sport_positions[$current_sport] ?? $sport_positions['General'];
                                foreach ($positions as $pos): ?>
                                    <option value="<?php echo $pos; ?>"><?php echo $pos; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="jersey_number">Jersey Number</label>
                            <input type="number" class="form-control" id="jersey_number" name="jersey_number" min="1" max="99">
                            <div class="form-text">Optional: Jersey number (1-99)</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="skills">Skills & Abilities</label>
                            <input type="text" class="form-control" id="skills" name="skills" placeholder="e.g., Speed, Shooting, Defense...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addMemberModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Member</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
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
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

        // Delete Confirmation
        function confirmDelete(teamId) {
            if (confirm('Are you sure you want to delete this team? This will also remove all team members. This action cannot be undone.')) {
                window.location.href = 'teams.php?action=delete&id=' + teamId;
            }
        }

        // Remove Member Confirmation
        function confirmRemoveMember(memberId) {
            if (confirm('Are you sure you want to remove this team member?')) {
                window.location.href = 'teams.php?action=remove_member&id=<?php echo $team_id; ?>&member_id=' + memberId;
            }
        }

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            });
        }, 5000);

        // Auto-fill user details when registration number is entered
        document.addEventListener('DOMContentLoaded', function() {
            const regNumberInput = document.getElementById('reg_number');
            const nameInput = document.getElementById('name');
            const departmentSelect = document.getElementById('department_id');

            if (regNumberInput && nameInput) {
                regNumberInput.addEventListener('blur', function() {
                    const regNumber = this.value.trim();
                    if (regNumber) {
                        // Fetch user details via AJAX
                        fetch('../api/get_user_details.php?reg_number=' + encodeURIComponent(regNumber))
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    if (nameInput) nameInput.value = data.user.full_name || '';
                                    if (departmentSelect && data.user.department_id) {
                                        departmentSelect.value = data.user.department_id;
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching user details:', error);
                            });
                    }
                });
            }
        });

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