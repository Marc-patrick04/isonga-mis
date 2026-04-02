<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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

// Get unread messages count for badge
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_visitor':
                $visitor_name = $_POST['visitor_name'];
                $organization = $_POST['organization'];
                $purpose = $_POST['purpose'];
                $visit_date = $_POST['visit_date'];
                $visit_time = $_POST['visit_time'];
                $expected_duration = $_POST['expected_duration'] ?? 60;
                $contact_person_id = !empty($_POST['contact_person_id']) ? $_POST['contact_person_id'] : null;
                $meeting_location = $_POST['meeting_location'] ?? '';
                $special_requirements = $_POST['special_requirements'] ?? '';
                
                error_log("DEBUG: Adding visitor - $visitor_name from $organization");
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO protocol_visitors 
                        (visitor_name, organization, purpose, visit_date, visit_time, expected_duration, 
                         contact_person_id, meeting_location, special_requirements, created_by, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $result = $stmt->execute([
                        $visitor_name, $organization, $purpose, $visit_date, $visit_time, 
                        $expected_duration, $contact_person_id, $meeting_location, $special_requirements, $user_id
                    ]);
                    
                    if ($result) {
                        $_SESSION['success_message'] = "Visitor scheduled successfully!";
                        error_log("DEBUG: Visitor added successfully - ID: " . $pdo->lastInsertId());
                    } else {
                        $_SESSION['error_message'] = "Failed to schedule visitor.";
                        error_log("DEBUG: Visitor insertion failed");
                    }
                } catch (PDOException $e) {
                    $error_msg = "Error scheduling visitor: " . $e->getMessage();
                    $_SESSION['error_message'] = $error_msg;
                    error_log("DEBUG: PDO Error: " . $e->getMessage());
                }
                break;
                
            case 'update_visitor_status':
                $visitor_id = $_POST['visitor_id'];
                $status = $_POST['status'];
                
                try {
                    $stmt = $pdo->prepare("UPDATE protocol_visitors SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$status, $visitor_id]);
                    
                    $_SESSION['success_message'] = "Visitor status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating visitor status: " . $e->getMessage();
                }
                break;
                
            case 'add_event':
                $event_name = $_POST['event_name'];
                $event_type = $_POST['event_type'];
                $event_date = $_POST['event_date'];
                $start_time = $_POST['start_time'];
                $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
                $location = $_POST['location'];
                $organizer = $_POST['organizer'] ?? '';
                $guest_of_honor = $_POST['guest_of_honor'] ?? '';
                $expected_attendees = !empty($_POST['expected_attendees']) ? $_POST['expected_attendees'] : null;
                $budget = $_POST['budget'] ?? 0;
                $protocol_requirements = $_POST['protocol_requirements'] ?? '';
                $security_level = $_POST['security_level'] ?? 'medium';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO protocol_events 
                        (event_name, event_type, event_date, start_time, end_time, location, organizer,
                         guest_of_honor, expected_attendees, budget, protocol_requirements, security_level, created_by, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $event_name, $event_type, $event_date, $start_time, $end_time, $location,
                        $organizer, $guest_of_honor, $expected_attendees, $budget, $protocol_requirements, 
                        $security_level, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Event created successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating event: " . $e->getMessage();
                }
                break;
                
            case 'add_team_member':
                $user_id_team = $_POST['user_id'];
                $role = $_POST['role'];
                $specialization = $_POST['specialization'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $email = $_POST['email'] ?? '';
                
                try {
                    // Check if user is already in protocol team
                    $check_stmt = $pdo->prepare("SELECT id FROM protocol_team WHERE user_id = ?");
                    $check_stmt->execute([$user_id_team]);
                    
                    if ($check_stmt->fetch()) {
                        $_SESSION['error_message'] = "This user is already in the protocol team.";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO protocol_team 
                            (user_id, role, specialization, phone, email, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$user_id_team, $role, $specialization, $phone, $email]);
                        
                        $_SESSION['success_message'] = "Team member added successfully!";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding team member: " . $e->getMessage();
                }
                break;
                
            case 'assign_team_to_event':
                $event_id = $_POST['event_id'];
                $team_member_id = $_POST['team_member_id'];
                $assigned_role = $_POST['assigned_role'];
                $responsibilities = $_POST['responsibilities'] ?? '';
                
                try {
                    // Check if already assigned
                    $check_stmt = $pdo->prepare("SELECT id FROM event_team_assignments WHERE event_id = ? AND team_member_id = ?");
                    $check_stmt->execute([$event_id, $team_member_id]);
                    
                    if ($check_stmt->fetch()) {
                        $_SESSION['error_message'] = "This team member is already assigned to this event.";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO event_team_assignments 
                            (event_id, team_member_id, assigned_role, responsibilities, assigned_by, assigned_at, created_at) 
                            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$event_id, $team_member_id, $assigned_role, $responsibilities, $user_id]);
                        
                        $_SESSION['success_message'] = "Team member assigned to event successfully!";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error assigning team member: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: protocol.php");
        exit();
    }
}

// Get statistics
try {
    // Today's visitors
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM protocol_visitors WHERE visit_date = CURRENT_DATE");
    $stmt->execute();
    $today_visitors = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Upcoming events
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM protocol_events WHERE event_date >= CURRENT_DATE AND status != 'completed'");
    $stmt->execute();
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Pending security clearances
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM protocol_visitors WHERE security_clearance = 'pending'");
    $stmt->execute();
    $pending_clearances = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Total visitors this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM protocol_visitors 
        WHERE EXTRACT(MONTH FROM visit_date) = EXTRACT(MONTH FROM CURRENT_DATE) 
        AND EXTRACT(YEAR FROM visit_date) = EXTRACT(YEAR FROM CURRENT_DATE)
    ");
    $stmt->execute();
    $month_visitors = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Protocol team count
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM protocol_team WHERE status = 'active'");
    $stmt->execute();
    $team_members_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Get today's visitors
    $stmt = $pdo->prepare("
        SELECT pv.*, u.full_name as contact_person_name 
        FROM protocol_visitors pv 
        LEFT JOIN users u ON pv.contact_person_id = u.id 
        WHERE pv.visit_date = CURRENT_DATE 
        ORDER BY pv.visit_time ASC
    ");
    $stmt->execute();
    $today_visitors_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming events
    $stmt = $pdo->prepare("
        SELECT * FROM protocol_events 
        WHERE event_date >= CURRENT_DATE AND status != 'completed'
        ORDER BY event_date ASC, start_time ASC 
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_events_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all visitors for the table
    $stmt = $pdo->prepare("
        SELECT pv.*, u.full_name as contact_person_name 
        FROM protocol_visitors pv 
        LEFT JOIN users u ON pv.contact_person_id = u.id 
        ORDER BY pv.visit_date DESC, pv.visit_time DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $all_visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all events for the table
    $stmt = $pdo->prepare("
        SELECT * FROM protocol_events 
        ORDER BY event_date DESC, start_time DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $all_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get protocol team members
    $stmt = $pdo->prepare("
        SELECT pt.*, u.full_name, u.email as user_email 
        FROM protocol_team pt 
        LEFT JOIN users u ON pt.user_id = u.id 
        WHERE pt.status = 'active'
        ORDER BY pt.role, u.full_name
    ");
    $stmt->execute();
    $protocol_team = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available users for adding to protocol team
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, u.role 
        FROM users u 
        WHERE u.status = 'active' 
        AND u.id NOT IN (SELECT user_id FROM protocol_team WHERE status = 'active')
        AND u.role IN ('student', 'staff', 'committee_member')
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get committee members for contact persons (fallback if protocol_team is empty)
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role 
        FROM users u 
        WHERE u.status = 'active' 
        AND u.role IN ('guild_president', 'vice_guild_academic', 'vice_guild_finance', 'general_secretary', 'minister_gender')
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Protocol statistics error: " . $e->getMessage());
    $today_visitors = $upcoming_events = $pending_clearances = $month_visitors = $team_members_count = 0;
    $today_visitors_list = $upcoming_events_list = $all_visitors = $all_events = $protocol_team = $available_users = $committee_members = [];
}

// Debug information
error_log("DEBUG: Today visitors: $today_visitors, Upcoming events: $upcoming_events, Team members: $team_members_count");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Protocol & Visitor Management - Minister of Gender & Protocol</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #a78bfa;
            --accent-purple: #7c3aed;
            --light-purple: #f3f4f6;
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
            --primary-purple: #a78bfa;
            --secondary-purple: #c4b5fd;
            --accent-purple: #8b5cf6;
            --light-purple: #1f2937;
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
        }

        .page-title p {
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

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
            text-align: center;
            white-space: nowrap;
        }

        .tab:hover {
            background: var(--light-purple);
            color: var(--text-dark);
        }

        .tab.active {
            background: var(--primary-purple);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #cce7ff;
            color: var(--primary-purple);
        }

        .status-arrived {
            background: #fff3cd;
            color: #856404;
        }

        .status-in_meeting {
            background: #d4edda;
            color: #155724;
        }

        .status-completed {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .security-pending {
            background: #fff3cd;
            color: #856404;
        }

        .security-approved {
            background: #d4edda;
            color: #155724;
        }

        .security-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-purple);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-purple);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.7rem;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
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

        .form-select, .form-input, .form-textarea {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        /* Alert Messages */
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Visitor List */
        .visitor-list {
            display: grid;
            gap: 0.75rem;
        }

        .visitor-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            border-left: 3px solid var(--primary-purple);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .visitor-info h4 {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .visitor-info p {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Team Grid */
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 0;
        }

        .team-member-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-purple);
            transition: var(--transition);
        }

        .team-member-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .team-member-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .team-member-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .team-member-role {
            background: var(--primary-purple);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .team-member-details {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .availability-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .availability-available {
            background: #d4edda;
            color: var(--success);
        }

        .availability-busy {
            background: #fff3cd;
            color: var(--warning);
        }

        .availability-unavailable {
            background: #f8d7da;
            color: var(--danger);
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
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1;
            background: var(--light-purple);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--light-gray);
            color: var(--danger);
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

            .tabs {
                flex-direction: column;
            }

            .tab {
                text-align: left;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .visitor-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .team-grid {
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
                    <h1>Isonga - Protocol & Visitor Management</h1>
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
                    
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Gender & Protocol</div>
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
                        <span>Gender Issues</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="protocol.php" class="active">
                        <i class="fas fa-handshake"></i>
                        <span>Protocol & Visitors</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Gender Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostel-management.php">
                        <i class="fas fa-building"></i>
                        <span>Hostel Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
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
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
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
               
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-primary" onclick="openModal('addVisitorModal')">
                        <i class="fas fa-user-plus"></i> Schedule Visitor
                    </button>
                    <button class="btn btn-secondary" onclick="openModal('addTeamMemberModal')">
                        <i class="fas fa-users"></i> Add Team Member
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($today_visitors); ?></div>
                        <div class="stat-label">Today's Visitors</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($upcoming_events); ?></div>
                        <div class="stat-label">Upcoming Events</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($pending_clearances); ?></div>
                        <div class="stat-label">Pending Clearances</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($team_members_count); ?></div>
                        <div class="stat-label">Protocol Team</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="visitors">Visitors</button>
                <button class="tab" data-tab="events">Events</button>
                <button class="tab" data-tab="team">Protocol Team</button>
                <button class="tab" data-tab="today">Today's Schedule</button>
            </div>

            <!-- Visitors Tab -->
            <div id="visitors" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>All Visitors</h3>
                        <button class="btn btn-primary btn-sm" onclick="openModal('addVisitorModal')">
                            <i class="fas fa-user-plus"></i> Add Visitor
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($all_visitors)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No visitors scheduled yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Visitor Name</th>
                                            <th>Organization</th>
                                            <th>Purpose</th>
                                            <th>Visit Date & Time</th>
                                            <th>Contact Person</th>
                                            <th>Status</th>
                                            <th>Security</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_visitors as $visitor): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($visitor['visitor_name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($visitor['organization']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($visitor['organization']); ?></td>
                                                <td>
                                                    <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                        <?php echo htmlspecialchars($visitor['purpose']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.8rem;"><?php echo date('M j, Y', strtotime($visitor['visit_date'])); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo date('g:i A', strtotime($visitor['visit_time'])); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($visitor['contact_person_name'] ?? 'Not assigned'); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $visitor['status']; ?>">
                                                        <?php echo ucfirst($visitor['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge security-<?php echo $visitor['security_clearance']; ?>">
                                                        <?php echo ucfirst($visitor['security_clearance']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-secondary btn-sm" onclick="updateVisitorStatus(<?php echo $visitor['id']; ?>, '<?php echo $visitor['status']; ?>')">
                                                            <i class="fas fa-edit"></i> Update
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
            </div>

            <!-- Events Tab -->
            <div id="events" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Protocol Events</h3>
                        <button class="btn btn-primary btn-sm" onclick="openModal('addEventModal')">
                            <i class="fas fa-calendar-plus"></i> Add Event
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($all_events)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar"></i>
                                <p>No events scheduled yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Event Name</th>
                                            <th>Type</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Guest of Honor</th>
                                            <th>Attendees</th>
                                            <th>Budget</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_events as $event): ?>
                                            <tr>
                                                <td style="font-weight: 600;"><?php echo htmlspecialchars($event['event_name']); ?></td>
                                                <td><?php echo htmlspecialchars(str_replace('_', ' ', $event['event_type'])); ?></td>
                                                <td>
                                                    <div style="font-size: 0.8rem;"><?php echo date('M j, Y', strtotime($event['event_date'])); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo date('g:i A', strtotime($event['start_time'])); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($event['location']); ?></td>
                                                <td><?php echo htmlspecialchars($event['guest_of_honor'] ?? 'Not specified'); ?></td>
                                                <td><?php echo $event['expected_attendees'] ?? 'N/A'; ?></td>
                                                <td>RWF <?php echo number_format($event['budget'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $event['status']; ?>">
                                                        <?php echo ucfirst($event['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-secondary btn-sm" onclick="viewEvent(<?php echo $event['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
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
            </div>

            <!-- Protocol Team Tab -->
            <div id="team" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Protocol Team Management</h3>
                        <button class="btn btn-primary btn-sm" onclick="openModal('addTeamMemberModal')">
                            <i class="fas fa-user-plus"></i> Add Team Member
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($protocol_team)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-friends"></i>
                                <p>No team members added yet.</p>
                                <p>Start by adding members to your protocol team.</p>
                                <button class="btn btn-primary" onclick="openModal('addTeamMemberModal')" style="margin-top: 1rem;">
                                    <i class="fas fa-user-plus"></i> Add First Team Member
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="team-grid">
                                <?php foreach ($protocol_team as $member): ?>
                                    <div class="team-member-card">
                                        <div class="team-member-header">
                                            <div>
                                                <div class="team-member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                <span class="team-member-role"><?php echo ucfirst($member['role']); ?></span>
                                            </div>
                                            <span class="availability-badge availability-<?php echo $member['availability']; ?>">
                                                <?php echo ucfirst($member['availability']); ?>
                                            </span>
                                        </div>
                                        <div class="team-member-details">
                                            <p><strong>Email:</strong> <?php echo htmlspecialchars($member['user_email']); ?></p>
                                            <?php if ($member['phone']): ?>
                                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($member['phone']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($member['specialization']): ?>
                                                <p><strong>Specialization:</strong> <?php echo htmlspecialchars($member['specialization']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Today's Schedule Tab -->
            <div id="today" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Today's Visitor Schedule</h3>
                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
                            <?php echo date('F j, Y'); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($today_visitors_list)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-day"></i>
                                <p>No visitors scheduled for today.</p>
                            </div>
                        <?php else: ?>
                            <div class="visitor-list">
                                <?php foreach ($today_visitors_list as $visitor): ?>
                                    <div class="visitor-item">
                                        <div class="visitor-info">
                                            <h4><?php echo htmlspecialchars($visitor['visitor_name']); ?></h4>
                                            <p>
                                                <strong>Organization:</strong> <?php echo htmlspecialchars($visitor['organization']); ?> |
                                                <strong>Time:</strong> <?php echo date('g:i A', strtotime($visitor['visit_time'])); ?> |
                                                <strong>Purpose:</strong> <?php echo htmlspecialchars($visitor['purpose']); ?>
                                            </p>
                                            <p>
                                                <strong>Contact:</strong> <?php echo htmlspecialchars($visitor['contact_person_name'] ?? 'Not assigned'); ?> |
                                                <strong>Location:</strong> <?php echo htmlspecialchars($visitor['meeting_location'] ?? 'TBD'); ?>
                                            </p>
                                        </div>
                                        <div class="action-buttons">
                                            <span class="status-badge status-<?php echo $visitor['status']; ?>">
                                                <?php echo ucfirst($visitor['status']); ?>
                                            </span>
                                            <button class="btn btn-secondary btn-sm" onclick="updateVisitorStatus(<?php echo $visitor['id']; ?>, '<?php echo $visitor['status']; ?>')">
                                                <i class="fas fa-edit"></i> Update
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Events -->
                <div class="card">
                    <div class="card-header">
                        <h3>Upcoming Protocol Events</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_events_list)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-alt"></i>
                                <p>No upcoming events.</p>
                            </div>
                        <?php else: ?>
                            <div class="visitor-list">
                                <?php foreach ($upcoming_events_list as $event): ?>
                                    <div class="visitor-item">
                                        <div class="visitor-info">
                                            <h4><?php echo htmlspecialchars($event['event_name']); ?></h4>
                                            <p>
                                                <strong>Date:</strong> <?php echo date('M j, Y', strtotime($event['event_date'])); ?> |
                                                <strong>Time:</strong> <?php echo date('g:i A', strtotime($event['start_time'])); ?> |
                                                <strong>Type:</strong> <?php echo htmlspecialchars(str_replace('_', ' ', $event['event_type'])); ?>
                                            </p>
                                            <p>
                                                <strong>Location:</strong> <?php echo htmlspecialchars($event['location']); ?> |
                                                <strong>Guest of Honor:</strong> <?php echo htmlspecialchars($event['guest_of_honor'] ?? 'Not specified'); ?>
                                            </p>
                                        </div>
                                        <div class="action-buttons">
                                            <span class="status-badge status-<?php echo $event['status']; ?>">
                                                <?php echo ucfirst($event['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Visitor Modal -->
    <div id="addVisitorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Schedule New Visitor</h3>
                <button class="modal-close" onclick="closeModal('addVisitorModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_visitor">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Visitor Name *</label>
                            <input type="text" name="visitor_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Organization *</label>
                            <input type="text" name="organization" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Visit Date *</label>
                            <input type="date" name="visit_date" class="form-input" required id="visit_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Visit Time *</label>
                            <input type="time" name="visit_time" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expected Duration (minutes)</label>
                            <input type="number" name="expected_duration" class="form-input" min="15" max="480" value="60">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Person</label>
                            <select name="contact_person_id" class="form-select">
                                <option value="">Select Contact Person</option>
                                <?php if (!empty($protocol_team)): ?>
                                    <?php foreach ($protocol_team as $member): ?>
                                        <option value="<?php echo $member['user_id']; ?>">
                                            <?php echo htmlspecialchars($member['full_name']); ?> - <?php echo ucfirst($member['role']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($committee_members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>">
                                            <?php echo htmlspecialchars($member['full_name']); ?> - <?php echo htmlspecialchars(str_replace('_', ' ', $member['role'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">Purpose of Visit *</label>
                            <textarea name="purpose" class="form-textarea" required placeholder="Brief description of the visit purpose..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meeting Location</label>
                            <input type="text" name="meeting_location" class="form-input" placeholder="e.g., Guild Council Office, Conference Room">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">Special Requirements</label>
                            <textarea name="special_requirements" class="form-textarea" placeholder="Any special arrangements needed..."></textarea>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Schedule Visitor
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addVisitorModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Team Member Modal -->
    <div id="addTeamMemberModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Add Protocol Team Member</h3>
                <button class="modal-close" onclick="closeModal('addTeamMemberModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_team_member">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Select User *</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">Select User</option>
                                <?php foreach ($available_users as $user_avail): ?>
                                    <option value="<?php echo $user_avail['id']; ?>">
                                        <?php echo htmlspecialchars($user_avail['full_name']); ?> - <?php echo htmlspecialchars($user_avail['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($available_users)): ?>
                                <small style="color: var(--danger); margin-top: 0.5rem;">
                                    No available users to add. All active users might already be in the protocol team.
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-select" required>
                                <option value="coordinator">Coordinator</option>
                                <option value="logistics">Logistics</option>
                                <option value="security">Security</option>
                                <option value="hospitality">Hospitality</option>
                                <option value="transportation">Transportation</option>
                                <option value="technical">Technical</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Specialization</label>
                            <input type="text" name="specialization" class="form-input" placeholder="e.g., Audio-Visual, Catering, Security">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-input" placeholder="Phone number">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-input" placeholder="Email address">
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary" <?php echo empty($available_users) ? 'disabled' : ''; ?>>
                            <i class="fas fa-save"></i> Add Team Member
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addTeamMemberModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Event Modal -->
    <div id="addEventModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Create New Protocol Event</h3>
                <button class="modal-close" onclick="closeModal('addEventModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_event">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Event Name *</label>
                            <input type="text" name="event_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Event Type *</label>
                            <select name="event_type" class="form-select" required>
                                <option value="ceremony">Ceremony</option>
                                <option value="meeting">Meeting</option>
                                <option value="reception">Reception</option>
                                <option value="official_visit">Official Visit</option>
                                <option value="workshop">Workshop</option>
                                <option value="conference">Conference</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Event Date *</label>
                            <input type="date" name="event_date" class="form-input" required id="event_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Time *</label>
                            <input type="time" name="start_time" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Organizer</label>
                            <input type="text" name="organizer" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Guest of Honor</label>
                            <input type="text" name="guest_of_honor" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expected Attendees</label>
                            <input type="number" name="expected_attendees" class="form-input" min="1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Budget (RWF)</label>
                            <input type="number" name="budget" class="form-input" min="0" step="1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Security Level</label>
                            <select name="security_level" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="maximum">Maximum</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">Protocol Requirements</label>
                            <textarea name="protocol_requirements" class="form-textarea" placeholder="Special protocol arrangements, seating plans, etc..."></textarea>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Event
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addEventModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Visitor Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Update Visitor Status</h3>
                <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_visitor_status">
                    <input type="hidden" name="visitor_id" id="status_visitor_id">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="status_select" class="form-select" required>
                            <option value="scheduled">Scheduled</option>
                            <option value="arrived">Arrived</option>
                            <option value="in_meeting">In Meeting</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">
                            Cancel
                        </button>
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
            
            // Activate selected tab button
            const tabs = document.querySelectorAll('.tab');
            const tabMap = {
                'visitors': 0,
                'events': 1,
                'team': 2,
                'today': 3
            };
            const index = tabMap[tabName];
            if (tabs[index]) {
                tabs[index].classList.add('active');
            }
        }

        // Make tabs work with click events
        document.querySelectorAll('.tab').forEach((tab, index) => {
            tab.addEventListener('click', function(e) {
                const tabNames = ['visitors', 'events', 'team', 'today'];
                switchTab(tabNames[index]);
            });
        });

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = '';
        }

        function updateVisitorStatus(visitorId, currentStatus) {
            document.getElementById('status_visitor_id').value = visitorId;
            document.getElementById('status_select').value = currentStatus;
            openModal('updateStatusModal');
        }

        function viewEvent(eventId) {
            alert('View Event details - Event ID: ' + eventId);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.getElementsByClassName('modal');
                for (let modal of modals) {
                    if (modal.style.display === 'flex') {
                        closeModal(modal.id);
                    }
                }
            }
        });

        // Set minimum date for visit date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const visitDateInput = document.getElementById('visit_date');
            if (visitDateInput) {
                visitDateInput.min = today;
            }
            
            const eventDateInput = document.getElementById('event_date');
            if (eventDateInput) {
                eventDateInput.min = today;
            }

            // Add loading animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
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
    </script>
</body>
</html>