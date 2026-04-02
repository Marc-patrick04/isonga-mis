<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User query error: " . $e->getMessage());
}

// Get sidebar statistics
try {
    // Pending tickets count
    $ticketStmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE status IN ('open', 'in_progress') 
        AND (assigned_to = ? OR assigned_to IS NULL)
    ");
    $ticketStmt->execute([$user_id]);
    $pending_tickets = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    
    // New students count
    $new_students_stmt = $pdo->prepare("
        SELECT COUNT(*) as new_students 
        FROM users 
        WHERE role = 'student' 
        AND status = 'active' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $new_students_stmt->execute();
    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    
    // Upcoming meetings count
    $upcoming_meetings = $pdo->query("
        SELECT COUNT(*) as count FROM meetings 
        WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
    ")->fetch()['count'] ?? 0;
    
    // Pending minutes count
    $pending_minutes = $pdo->query("
        SELECT COUNT(*) as count FROM meeting_minutes 
        WHERE approval_status = 'submitted'
    ")->fetch()['count'] ?? 0;
    
    // Pending reports
    $pending_reports = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'")->fetch()['pending_reports'] ?? 0;
    
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
    
} catch (PDOException $e) {
    $pending_tickets = $new_students = $upcoming_meetings = $pending_minutes = $pending_reports = $unread_messages = 0;
}

// Handle actions
$action = $_GET['action'] ?? '';
$member_id = $_GET['id'] ?? null;
$message = '';
$message_type = '';

// Get all committee members with user details
try {
    $stmt = $pdo->prepare("
        SELECT cm.*, 
               u.full_name, u.email, u.phone, u.avatar_url,
               u.department_id, u.program_id, u.academic_year,
               d.name as department_name,
               p.name as program_name
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE cm.status = 'active'
        ORDER BY cm.role_order, cm.role, cm.name
    ");
    $stmt->execute();
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
    error_log("Committee members query error: " . $e->getMessage());
}

// Get all users who are not in committee for adding new members
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.reg_number, u.full_name, u.email, u.phone, 
               u.department_id, u.program_id, u.academic_year,
               d.name as department_name,
               p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.role = 'student' 
        AND u.status = 'active'
        AND u.id NOT IN (SELECT user_id FROM committee_members WHERE status = 'active')
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $available_students = [];
    error_log("Available students query error: " . $e->getMessage());
}

// Get departments and programs
try {
    $departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = $programs = [];
    error_log("Departments/Programs query error: " . $e->getMessage());
}

// Define committee roles and their order
$committee_roles = [
    'guild_president' => 'Guild President',
    'vice_guild_academic' => 'Vice Guild President - Academic',
    'vice_guild_finance' => 'Vice Guild President - Finance',
    'general_secretary' => 'General Secretary',
    'minister_sports' => 'Minister of Sports',
    'minister_environment' => 'Minister of Environment',
    'minister_public_relations' => 'Minister of Public Relations',
    'minister_health' => 'Minister of Health',
    'minister_culture' => 'Minister of Culture',
    'minister_gender' => 'Minister of Gender',
    'president_representative_board' => 'President - Representative Board',
    'vice_president_representative_board' => 'Vice President - Representative Board',
    'secretary_representative_board' => 'Secretary - Representative Board',
    'president_arbitration' => 'President - Arbitration',
    'vice_president_arbitration' => 'Vice President - Arbitration',
    'advisor_arbitration' => 'Advisor - Arbitration',
    'secretary_arbitration' => 'Secretary - Arbitration'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    try {
        switch ($post_action) {
            case 'add_member':
                $user_id = $_POST['user_id'] ?? null;
                $role = $_POST['role'] ?? '';
                $bio = $_POST['bio'] ?? '';
                $portfolio_description = $_POST['portfolio_description'] ?? '';
                
                if ($user_id && $role) {
                    // Get user details
                    $user_stmt = $pdo->prepare("SELECT reg_number, full_name, email, phone, department_id, program_id, academic_year FROM users WHERE id = ?");
                    $user_stmt->execute([$user_id]);
                    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user_data) {
                        // Check if role is already taken
                        $role_check = $pdo->prepare("SELECT id FROM committee_members WHERE role = ? AND status = 'active'");
                        $role_check->execute([$role]);
                        
                        if ($role_check->fetch()) {
                            $message = "This role is already assigned to another committee member!";
                            $message_type = 'error';
                        } else {
                            // Add to committee
                            $insert_stmt = $pdo->prepare("
                                INSERT INTO committee_members 
                                (reg_number, name, email, phone, department_id, program_id, academic_year, user_id, role, bio, portfolio_description, start_date, status, role_order)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, 'active', ?)
                            ");
                            
                            $role_order = array_search($role, array_keys($committee_roles)) + 1;
                            
                            $insert_stmt->execute([
                                $user_data['reg_number'],
                                $user_data['full_name'],
                                $user_data['email'],
                                $user_data['phone'],
                                $user_data['department_id'],
                                $user_data['program_id'],
                                $user_data['academic_year'],
                                $user_id,
                                $role,
                                $bio,
                                $portfolio_description,
                                $role_order
                            ]);
                            
                            // Update user role
                            $update_user = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                            $update_user->execute([$role, $user_id]);
                            
                            $message = "Committee member added successfully!";
                            $message_type = 'success';
                        }
                    }
                }
                break;
                
            case 'update_member':
                $member_id = $_POST['member_id'] ?? null;
                $role = $_POST['role'] ?? '';
                $bio = $_POST['bio'] ?? '';
                $portfolio_description = $_POST['portfolio_description'] ?? '';
                $status = $_POST['status'] ?? 'active';
                
                if ($member_id) {
                    $update_stmt = $pdo->prepare("
                        UPDATE committee_members 
                        SET role = ?, bio = ?, portfolio_description = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$role, $bio, $portfolio_description, $status, $member_id]);
                    
                    // Update user role if status is active
                    if ($status === 'active') {
                        $user_update = $pdo->prepare("
                            UPDATE users u 
                            JOIN committee_members cm ON u.id = cm.user_id 
                            SET u.role = ? 
                            WHERE cm.id = ?
                        ");
                        $user_update->execute([$role, $member_id]);
                    }
                    
                    $message = "Committee member updated successfully!";
                    $message_type = 'success';
                }
                break;
                
            case 'remove_member':
                $member_id = $_POST['member_id'] ?? null;
                
                if ($member_id) {
                    // Get user ID before removal
                    $user_stmt = $pdo->prepare("SELECT user_id FROM committee_members WHERE id = ?");
                    $user_stmt->execute([$member_id]);
                    $member_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($member_data) {
                        // Update committee member status
                        $update_stmt = $pdo->prepare("UPDATE committee_members SET status = 'inactive', end_date = CURRENT_DATE WHERE id = ?");
                        $update_stmt->execute([$member_id]);
                        
                        // Reset user role to student
                        $user_update = $pdo->prepare("UPDATE users SET role = 'student' WHERE id = ?");
                        $user_update->execute([$member_data['user_id']]);
                        
                        $message = "Committee member removed successfully!";
                        $message_type = 'success';
                    }
                }
                break;
        }
        
        // Refresh committee members data
        $stmt = $pdo->prepare("
            SELECT cm.*, 
                   u.full_name, u.email, u.phone, u.avatar_url,
                   u.department_id, u.program_id, u.academic_year,
                   d.name as department_name,
                   p.name as program_name
            FROM committee_members cm
            LEFT JOIN users u ON cm.user_id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            WHERE cm.status = 'active'
            ORDER BY cm.role_order, cm.role, cm.name
        ");
        $stmt->execute();
        $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Committee action error: " . $e->getMessage());
        $message = "Error processing request: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get performance statistics
try {
    // Meeting attendance statistics
    $attendance_stmt = $pdo->prepare("
        SELECT 
            cm.id as member_id,
            cm.name,
            cm.role,
            COUNT(ma.id) as total_meetings,
            SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as meetings_attended,
            ROUND((SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(ma.id), 0)) * 100, 2) as attendance_rate
        FROM committee_members cm
        LEFT JOIN meeting_attendance ma ON cm.id = ma.committee_member_id
        LEFT JOIN meetings m ON ma.meeting_id = m.id
        WHERE cm.status = 'active'
        AND m.meeting_date >= DATE_SUB(CURRENT_DATE, INTERVAL 3 MONTH)
        GROUP BY cm.id, cm.name, cm.role
        ORDER BY attendance_rate DESC
    ");
    $attendance_stmt->execute();
    $attendance_stats = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ticket resolution statistics
    $ticket_stmt = $pdo->prepare("
        SELECT 
            cm.id as member_id,
            cm.name,
            cm.role,
            COUNT(t.id) as total_tickets,
            SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
            ROUND((SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) / NULLIF(COUNT(t.id), 0)) * 100, 2) as resolution_rate
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN tickets t ON u.id = t.assigned_to
        WHERE cm.status = 'active'
        AND t.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 3 MONTH)
        GROUP BY cm.id, cm.name, cm.role
        ORDER BY resolution_rate DESC
    ");
    $ticket_stmt->execute();
    $ticket_stats = $ticket_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Report submission statistics
    $report_stmt = $pdo->prepare("
        SELECT 
            cm.id as member_id,
            cm.name,
            cm.role,
            COUNT(r.id) as total_reports,
            SUM(CASE WHEN r.status IN ('submitted', 'approved') THEN 1 ELSE 0 END) as submitted_reports
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN reports r ON u.id = r.user_id
        WHERE cm.status = 'active'
        AND r.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 3 MONTH)
        GROUP BY cm.id, cm.name, cm.role
        ORDER BY submitted_reports DESC
    ");
    $report_stmt->execute();
    $report_stats = $report_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Performance stats error: " . $e->getMessage());
    $attendance_stats = $ticket_stats = $report_stats = [];
}

// Combine all statistics
$performance_data = [];
foreach ($committee_members as $member) {
    $member_id = $member['id'];
    
    $attendance = array_filter($attendance_stats, function($stat) use ($member_id) {
        return $stat['member_id'] == $member_id;
    });
    $attendance = !empty($attendance) ? array_values($attendance)[0] : null;
    
    $tickets = array_filter($ticket_stats, function($stat) use ($member_id) {
        return $stat['member_id'] == $member_id;
    });
    $tickets = !empty($tickets) ? array_values($tickets)[0] : null;
    
    $reports = array_filter($report_stats, function($stat) use ($member_id) {
        return $stat['member_id'] == $member_id;
    });
    $reports = !empty($reports) ? array_values($reports)[0] : null;
    
    $performance_data[$member_id] = [
        'attendance' => $attendance,
        'tickets' => $tickets,
        'reports' => $reports,
        'overall_score' => 0
    ];
    
    // Calculate overall performance score
    $scores = [];
    if ($attendance && $attendance['total_meetings'] > 0) {
        $scores[] = $attendance['attendance_rate'];
    }
    if ($tickets && $tickets['total_tickets'] > 0) {
        $scores[] = $tickets['resolution_rate'];
    }
    if ($reports && $reports['total_reports'] > 0) {
        $scores[] = ($reports['submitted_reports'] / $reports['total_reports']) * 100;
    }
    
    if (!empty($scores)) {
        $performance_data[$member_id]['overall_score'] = round(array_sum($scores) / count($scores), 2);
    }
}

// Get recent committee activities
try {
    $activities_stmt = $pdo->prepare("
        (SELECT 
            'meeting' as type,
            m.title as activity_title,
            m.meeting_date as date,
            m.status,
            cm.name as member_name,
            cm.role
        FROM meetings m
        LEFT JOIN committee_members cm ON m.chairperson_id = cm.user_id
        WHERE m.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
        ORDER BY m.created_at DESC
        LIMIT 5)
        
        UNION ALL
        
        (SELECT 
            'ticket' as type,
            t.subject as activity_title,
            t.created_at as date,
            t.status,
            cm.name as member_name,
            cm.role
        FROM tickets t
        LEFT JOIN committee_members cm ON t.assigned_to = cm.user_id
        WHERE t.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
        ORDER BY t.created_at DESC
        LIMIT 5)
        
        UNION ALL
        
        (SELECT 
            'report' as type,
            r.title as activity_title,
            r.created_at as date,
            r.status,
            cm.name as member_name,
            cm.role
        FROM reports r
        LEFT JOIN committee_members cm ON r.user_id = cm.user_id
        WHERE r.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
        ORDER BY r.created_at DESC
        LIMIT 5)
        
        ORDER BY date DESC
        LIMIT 10
    ");
    $activities_stmt->execute();
    $recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_activities = [];
    error_log("Recent activities error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Committee Management - Isonga RPSU</title>
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
            --info: #4dd0e1;
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
            color: var(--primary-blue);
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
            border-color: var(--primary-blue);
            color: var(--primary-blue);
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
            color: var(--primary-blue);
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Committee Grid */
        .committee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .member-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .member-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .member-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .member-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0 auto 0.75rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .member-name {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .member-role {
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .member-body {
            padding: 1rem;
        }

        .member-info {
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
            font-size: 0.75rem;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .info-value {
            color: var(--text-dark);
            text-align: right;
        }

        .performance-score {
            text-align: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
        }

        .score-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: conic-gradient(var(--success) 0% var(--score-percent), var(--light-gray) var(--score-percent) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            position: relative;
        }

        .score-circle::before {
            content: '';
            position: absolute;
            width: 50px;
            height: 50px;
            background: var(--white);
            border-radius: 50%;
        }

        .score-value {
            position: relative;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .score-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
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
            background: var(--light-blue);
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin: 0.5rem 0 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-fill.warning {
            background: var(--warning);
        }

        .progress-fill.danger {
            background: var(--danger);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-in-progress {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        /* Activity Feed */
        .activity-feed {
            display: flex;
            flex-direction: column;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--light-blue);
            color: var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.7rem;
            color: var(--dark-gray);
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
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-blue);
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
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            resize: vertical;
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

        .alert-error {
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
                background: var(--primary-blue);
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

            .committee-grid {
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

            .committee-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                justify-content: center;
            }

            .tab {
                padding: 0.5rem 1rem;
                font-size: 0.75rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
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
                    <h1>Isonga - Committee</h1>
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
                        <div class="user-role">General Secretary</div>
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
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php" class="active">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
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
                
                <div>
                    <button class="btn btn-primary" onclick="openAddMemberModal()">
                        <i class="fas fa-user-plus"></i> Add Committee Member
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($committee_members); ?></div>
                        <div class="stat-label">Total Committee Members</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $active_count = array_reduce($committee_members, function($carry, $member) {
                                return $carry + ($member['status'] === 'active' ? 1 : 0);
                            }, 0);
                            echo $active_count;
                            ?>
                        </div>
                        <div class="stat-label">Active Members</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($committee_roles); ?></div>
                        <div class="stat-label">Available Roles</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php
                            $avg_performance = array_reduce($performance_data, function($carry, $data) {
                                return $carry + $data['overall_score'];
                            }, 0) / max(count($performance_data), 1);
                            echo round($avg_performance, 1) . '%';
                            ?>
                        </div>
                        <div class="stat-label">Average Performance</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab(event, 'members')">Committee Members</button>
                <button class="tab" onclick="switchTab(event, 'performance')">Performance Tracking</button>
                <button class="tab" onclick="switchTab(event, 'activities')">Recent Activities</button>
            </div>

            <!-- Committee Members Tab -->
            <div id="members-tab" class="tab-content active">
                <?php if (empty($committee_members)): ?>
                    <div class="card">
                        <div class="card-body empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Committee Members</h3>
                            <p>Start by adding committee members to manage the student union.</p>
                            <button class="btn btn-primary" onclick="openAddMemberModal()">
                                <i class="fas fa-user-plus"></i> Add First Member
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="committee-grid">
                        <?php foreach ($committee_members as $member): ?>
                            <?php 
                            $performance = $performance_data[$member['id']] ?? ['overall_score' => 0];
                            $score_percent = min($performance['overall_score'], 100);
                            ?>
                            <div class="member-card">
                                <div class="member-header">
                                    <div class="member-avatar">
                                        <?php if (!empty($member['avatar_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($member['avatar_url']); ?>" alt="Avatar">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($member['name'] ?? 'C', 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="member-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                    <div class="member-role"><?php echo htmlspecialchars($committee_roles[$member['role']] ?? $member['role']); ?></div>
                                </div>
                                <div class="member-body">
                                    <div class="member-info">
                                        <div class="info-item">
                                            <span class="info-label">Reg Number:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($member['reg_number']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Email:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($member['email']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Phone:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($member['phone']); ?></span>
                                        </div>
                                        <?php if ($member['department_name']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Department:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($member['department_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="info-item">
                                            <span class="info-label">Academic Year:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($member['academic_year']); ?></span>
                                        </div>
                                    </div>

                                    <div class="performance-score">
                                        <div class="score-circle" style="--score-percent: <?php echo $score_percent; ?>%">
                                            <span class="score-value"><?php echo $score_percent; ?>%</span>
                                        </div>
                                        <div class="score-label">Performance Score</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Performance Tracking Tab -->
            <div id="performance-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Committee Performance Overview</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($committee_members)): ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Role</th>
                                            <th>Meeting Attendance</th>
                                            <th>Ticket Resolution</th>
                                            <th>Report Submission</th>
                                            <th>Overall Score</th>
                                            <th>Rating</th>
                                        </thead>
                                    <tbody>
                                        <?php foreach ($committee_members as $member): ?>
                                            <?php 
                                            $performance = $performance_data[$member['id']] ?? [];
                                            $attendance = $performance['attendance'] ?? [];
                                            $tickets = $performance['tickets'] ?? [];
                                            $reports = $performance['reports'] ?? [];
                                            $overall_score = $performance['overall_score'] ?? 0;
                                            $report_rate = $reports && $reports['total_reports'] > 0 ? round(($reports['submitted_reports'] / $reports['total_reports']) * 100, 2) : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($member['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($committee_roles[$member['role']] ?? $member['role']); ?></td>
                                                <td style="min-width: 120px;">
                                                    <?php if ($attendance && $attendance['total_meetings'] > 0): ?>
                                                        <?php echo $attendance['meetings_attended']; ?>/<?php echo $attendance['total_meetings']; ?>
                                                        (<?php echo $attendance['attendance_rate']; ?>%)
                                                        <div class="progress-bar">
                                                            <div class="progress-fill <?php echo $attendance['attendance_rate'] >= 80 ? '' : ($attendance['attendance_rate'] >= 60 ? 'warning' : 'danger'); ?>" 
                                                                 style="width: <?php echo $attendance['attendance_rate']; ?>%"></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">No data</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="min-width: 120px;">
                                                    <?php if ($tickets && $tickets['total_tickets'] > 0): ?>
                                                        <?php echo $tickets['resolved_tickets']; ?>/<?php echo $tickets['total_tickets']; ?>
                                                        (<?php echo $tickets['resolution_rate']; ?>%)
                                                        <div class="progress-bar">
                                                            <div class="progress-fill <?php echo $tickets['resolution_rate'] >= 80 ? '' : ($tickets['resolution_rate'] >= 60 ? 'warning' : 'danger'); ?>" 
                                                                 style="width: <?php echo $tickets['resolution_rate']; ?>%"></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">No data</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="min-width: 120px;">
                                                    <?php if ($reports && $reports['total_reports'] > 0): ?>
                                                        <?php echo $reports['submitted_reports']; ?>/<?php echo $reports['total_reports']; ?>
                                                        (<?php echo $report_rate; ?>%)
                                                        <div class="progress-bar">
                                                            <div class="progress-fill <?php echo $report_rate >= 80 ? '' : ($report_rate >= 60 ? 'warning' : 'danger'); ?>" 
                                                                 style="width: <?php echo $report_rate; ?>%"></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">No data</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong><?php echo $overall_score; ?>%</strong></td>
                                                <td>
                                                    <span class="status-badge <?php echo $overall_score >= 80 ? 'status-completed' : ($overall_score >= 60 ? 'status-in-progress' : 'status-pending'); ?>">
                                                        <?php echo $overall_score >= 80 ? 'Excellent' : ($overall_score >= 60 ? 'Good' : 'Needs Improvement'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-bar"></i>
                                <p>No performance data available. Add committee members to start tracking performance.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activities Tab -->
            <div id="activities-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Committee Activities</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_activities)): ?>
                            <div class="activity-feed">
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?php if ($activity['type'] === 'meeting'): ?>
                                                <i class="fas fa-calendar-alt"></i>
                                            <?php elseif ($activity['type'] === 'ticket'): ?>
                                                <i class="fas fa-ticket-alt"></i>
                                            <?php else: ?>
                                                <i class="fas fa-file-alt"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo htmlspecialchars($activity['activity_title']); ?>
                                            </div>
                                            <div class="activity-meta">
                                                <?php if ($activity['member_name']): ?>
                                                    By <?php echo htmlspecialchars($activity['member_name']); ?> 
                                                    (<?php echo htmlspecialchars($committee_roles[$activity['role']] ?? $activity['role']); ?>) • 
                                                <?php endif; ?>
                                                <?php echo date('M j, Y g:i A', strtotime($activity['date'])); ?>
                                            </div>
                                        </div>
                                        <div class="status-badge <?php echo str_replace('_', '-', $activity['status']) === 'completed' ? 'status-completed' : (str_replace('_', '-', $activity['status']) === 'submitted' ? 'status-in-progress' : 'status-pending'); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-clock"></i>
                                <p>No recent activities found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add Committee Member</h3>
                <button class="close-modal" onclick="closeModal('addMemberModal')">&times;</button>
            </div>
            <form method="POST" id="addMemberForm">
                <input type="hidden" name="action" value="add_member">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Select Student *</label>
                        <select class="form-select" name="user_id" required>
                            <option value="">Select a student</option>
                            <?php foreach ($available_students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['reg_number'] . ') - ' . ($student['department_name'] ?? 'No Department')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($available_students)): ?>
                            <small style="color: var(--danger);">No available students found. All active students might already be in the committee.</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Committee Role *</label>
                        <select class="form-select" name="role" required>
                            <option value="">Select a role</option>
                            <?php foreach ($committee_roles as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($value); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bio/Description</label>
                        <textarea class="form-control" name="bio" rows="3" placeholder="Brief description about the member..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Portfolio Description</label>
                        <textarea class="form-control" name="portfolio_description" rows="3" placeholder="Description of their responsibilities..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add to Committee
                    </button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('addMemberModal')">Cancel</button>
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

        // Tab switching
        function switchTab(event, tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }
        }

        // Modal functions
        function openAddMemberModal() {
            document.getElementById('addMemberModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Form validation
        document.getElementById('addMemberForm')?.addEventListener('submit', function(e) {
            const userId = this.querySelector('select[name="user_id"]').value;
            const role = this.querySelector('select[name="role"]').value;
            
            if (!userId || !role) {
                e.preventDefault();
                alert('Please select both a student and a role.');
            }
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('addMemberModal');
            if (event.target === modal) {
                closeModal('addMemberModal');
            }
        });

        // Performance score color coding
        document.querySelectorAll('.score-circle').forEach(circle => {
            const score = parseInt(circle.querySelector('.score-value')?.textContent || '0');
            const scorePercent = score;
            if (score >= 80) {
                circle.style.background = 'conic-gradient(var(--success) 0% ' + scorePercent + '%, var(--light-gray) ' + scorePercent + '% 100%)';
            } else if (score >= 60) {
                circle.style.background = 'conic-gradient(var(--warning) 0% ' + scorePercent + '%, var(--light-gray) ' + scorePercent + '% 100%)';
            } else {
                circle.style.background = 'conic-gradient(var(--danger) 0% ' + scorePercent + '%, var(--light-gray) ' + scorePercent + '% 100%)';
            }
        });
    </script>
</body>
</html>