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
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active', ?)
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
                        $update_stmt = $pdo->prepare("UPDATE committee_members SET status = 'inactive', end_date = CURDATE() WHERE id = ?");
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
            ROUND((SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) / COUNT(ma.id)) * 100, 2) as attendance_rate
        FROM committee_members cm
        LEFT JOIN meeting_attendance ma ON cm.id = ma.committee_member_id
        LEFT JOIN meetings m ON ma.meeting_id = m.id
        WHERE cm.status = 'active'
        AND m.meeting_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
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
            ROUND((SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) / COUNT(t.id)) * 100, 2) as resolution_rate
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN tickets t ON u.id = t.assigned_to
        WHERE cm.status = 'active'
        AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
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
            SUM(CASE WHEN r.status = 'submitted' OR r.status = 'approved' THEN 1 ELSE 0 END) as submitted_reports
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN reports r ON u.id = r.user_id
        WHERE cm.status = 'active'
        AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
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
        WHERE m.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
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
        WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
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
        WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
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
            color: var(--primary-blue);
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
            border-color: var(--primary-blue);
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
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
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
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
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
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--secondary-blue); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Committee Grid */
        .committee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
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
            position: relative;
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
            margin: 0 auto 1rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .member-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .member-role {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .member-body {
            padding: 1.25rem;
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
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .info-value {
            color: var(--text-dark);
            font-size: 0.8rem;
            text-align: right;
        }

        .performance-score {
            text-align: center;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
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
            font-size: 1rem;
            color: var(--text-dark);
        }

        .score-label {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .member-actions {
            display: flex;
            gap: 0.5rem;
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
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Progress Bars */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--medium-gray);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s ease;
        }

        .progress-fill.warning { background: var(--warning); }
        .progress-fill.danger { background: var(--danger); }

        /* Activity Feed */
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
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
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .activity-status {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed { background: #d4edda; color: var(--success); }
        .status-pending { background: #fff3cd; color: var(--warning); }
        .status-in-progress { background: #cce7ff; color: var(--primary-blue); }

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
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-lg);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--text-dark);
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }

        .close:hover {
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

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .committee-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
            
            .committee-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .member-actions {
                flex-direction: column;
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
                    <h1>Isonga - General Secretary</h1>
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Committee Management Control Room</h1>
                    <p>Manage committee members and track their performance</p>
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
                    <div class="stat-number"><?php echo count($committee_members); ?></div>
                    <div class="stat-label">Total Committee Members</div>
                </div>
                <div class="stat-card success">
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
                <div class="stat-card info">
                    <div class="stat-number"><?php echo count($committee_roles); ?></div>
                    <div class="stat-label">Available Roles</div>
                </div>
                <div class="stat-card warning">
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

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('members')">Committee Members</button>
                <button class="tab" onclick="switchTab('performance')">Performance Tracking</button>
                <button class="tab" onclick="switchTab('activities')">Recent Activities</button>
            </div>

            <!-- Committee Members Tab -->
            <div id="members-tab" class="tab-content active">
                <div class="committee-grid">
                    <?php foreach ($committee_members as $member): ?>
                        <?php 
                        $performance = $performance_data[$member['id']] ?? ['overall_score' => 0];
                        $score_percent = min($performance['overall_score'], 100);
                        $score_color = $score_percent >= 80 ? 'success' : ($score_percent >= 60 ? 'warning' : 'danger');
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
                                        <span class="info-label">Registration No:</span>
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
                                    <?php if ($member['program_name']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Program:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($member['program_name']); ?></span>
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

                <?php if (empty($committee_members)): ?>
                    <div class="card">
                        <div class="card-body" style="text-align: center; padding: 3rem;">
                            <i class="fas fa-users" style="font-size: 3rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                            <h3>No Committee Members</h3>
                            <p>Start by adding committee members to manage the student union.</p>
                            <button class="btn btn-primary" onclick="openAddMemberModal()">
                                <i class="fas fa-user-plus"></i> Add First Member
                            </button>
                        </div>
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
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Role</th>
                                            <th>Meeting Attendance</th>
                                            <th>Ticket Resolution</th>
                                            <th>Report Submission</th>
                                            <th>Overall Score</th>
                                            <th>Performance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($committee_members as $member): ?>
                                            <?php 
                                            $performance = $performance_data[$member['id']] ?? [];
                                            $attendance = $performance['attendance'] ?? [];
                                            $tickets = $performance['tickets'] ?? [];
                                            $reports = $performance['reports'] ?? [];
                                            $overall_score = $performance['overall_score'] ?? 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($committee_roles[$member['role']] ?? $member['role']); ?></td>
                                                <td>
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
                                                <td>
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
                                                <td>
                                                    <?php if ($reports && $reports['total_reports'] > 0): ?>
                                                        <?php echo $reports['submitted_reports']; ?>/<?php echo $reports['total_reports']; ?>
                                                        (<?php echo round(($reports['submitted_reports'] / $reports['total_reports']) * 100, 2); ?>%)
                                                        <div class="progress-bar">
                                                            <div class="progress-fill <?php echo ($reports['submitted_reports'] / $reports['total_reports'] * 100) >= 80 ? '' : (($reports['submitted_reports'] / $reports['total_reports'] * 100) >= 60 ? 'warning' : 'danger'); ?>" 
                                                                 style="width: <?php echo ($reports['submitted_reports'] / $reports['total_reports'] * 100); ?>%"></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="color: var(--dark-gray);">No data</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo $overall_score; ?>%</strong>
                                                </td>
                                                <td>
                                                    <span class="activity-status status-<?php echo $overall_score >= 80 ? 'completed' : ($overall_score >= 60 ? 'in-progress' : 'pending'); ?>">
                                                        <?php echo $overall_score >= 80 ? 'Excellent' : ($overall_score >= 60 ? 'Good' : 'Needs Improvement'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
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
                                        <div class="activity-status status-<?php echo str_replace('_', '-', $activity['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-clock" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
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
                <button class="close">&times;</button>
            </div>
            <form method="POST" id="addMemberForm">
                <input type="hidden" name="action" value="add_member">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="user_id">Select Student *</label>
                        <select class="form-control" id="user_id" name="user_id" required>
                            <option value="">Select a student</option>
                            <?php foreach ($available_students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['reg_number'] . ') - ' . $student['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($available_students)): ?>
                            <small style="color: var(--danger);">No available students found. All active students might already be in the committee.</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="role">Committee Role *</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="">Select a role</option>
                            <?php foreach ($committee_roles as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($value); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="bio">Bio/Description</label>
                        <textarea class="form-control" id="bio" name="bio" rows="3" placeholder="Brief description about the member..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="portfolio_description">Portfolio Description</label>
                        <textarea class="form-control" id="portfolio_description" name="portfolio_description" rows="3" placeholder="Description of their responsibilities..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add to Committee
                    </button>
                    <button type="button" class="btn btn-outline close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Modal functions
        function openAddMemberModal() {
            document.getElementById('addMemberModal').style.display = 'block';
        }

        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.style.display = 'none';
            });
        }

        function editMember(memberId) {
            // In a real implementation, this would open an edit modal
            // For now, we'll show an alert
            alert('Edit functionality for member ID: ' + memberId + ' would open here.');
        }

        function viewPerformance(memberId) {
            // In a real implementation, this would open a detailed performance view
            alert('Detailed performance view for member ID: ' + memberId + ' would open here.');
        }

        function generateCommitteeReport() {
            alert('Committee report generation would start here. This would generate a PDF report of all committee members and their performance.');
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Close modals
            document.querySelectorAll('.close, .close-modal').forEach(btn => {
                btn.addEventListener('click', closeAllModals);
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeAllModals();
                }
            });
            
            // Auto-refresh performance data every 2 minutes
            setInterval(() => {
                if (!document.querySelector('.modal[style*="display: block"]')) {
                    // In a real implementation, this would refresh the performance data
                    console.log('Refreshing performance data...');
                }
            }, 120000);
        });

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

        // Form validation
        document.getElementById('addMemberForm')?.addEventListener('submit', function(e) {
            const userId = document.getElementById('user_id').value;
            const role = document.getElementById('role').value;
            
            if (!userId || !role) {
                e.preventDefault();
                alert('Please select both a student and a role.');
            }
        });

        // Performance score color coding
        document.querySelectorAll('.score-circle').forEach(circle => {
            const score = parseInt(circle.querySelector('.score-value').textContent);
            if (score >= 80) {
                circle.style.background = 'conic-gradient(var(--success) 0% ' + score + '%, var(--light-gray) ' + score + '% 100%)';
            } else if (score >= 60) {
                circle.style.background = 'conic-gradient(var(--warning) 0% ' + score + '%, var(--light-gray) ' + score + '% 100%)';
            } else {
                circle.style.background = 'conic-gradient(var(--danger) 0% ' + score + '%, var(--light-gray) ' + score + '% 100%)';
            }
        });
    </script>
</body>
</html>