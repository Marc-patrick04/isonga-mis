<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
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

// Get dashboard statistics for sidebar - PostgreSQL compatible
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (Exception $e) {
        error_log("Reports table query error: " . $e->getMessage());
        $pending_reports = 0;
    }
    
    // Unread messages
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    } catch (Exception $e) {
        error_log("Messages query error: " . $e->getMessage());
        $unread_messages = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (Exception $e) {
        error_log("Documents table error: " . $e->getMessage());
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_tickets = $open_tickets = $pending_reports = $unread_messages = $pending_docs = 0;
}

// Handle actions
$action = $_GET['action'] ?? '';
$member_id = $_GET['id'] ?? null;
$message = '';
$message_type = '';

// Get all committee members with user details - PostgreSQL compatible
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

// Get all users who are not in committee for adding new members - PostgreSQL compatible
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
        AND u.id NOT IN (SELECT user_id FROM committee_members WHERE status = 'active' AND user_id IS NOT NULL)
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $available_students = [];
    error_log("Available students query error: " . $e->getMessage());
}

// Get departments and programs - PostgreSQL compatible
try {
    $departments = $pdo->query("SELECT * FROM departments WHERE is_active = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT * FROM programs WHERE is_active = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
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
                            // Add to committee - PostgreSQL uses CURRENT_DATE
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
                            UPDATE users 
                            SET role = ? 
                            WHERE id = (SELECT user_id FROM committee_members WHERE id = ?)
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
                        // Update committee member status - PostgreSQL uses CURRENT_DATE
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

// Get performance statistics - PostgreSQL compatible (using INTERVAL)
try {
    // Meeting attendance statistics
    $attendance_stmt = $pdo->prepare("
        SELECT 
            cm.id as member_id,
            cm.name,
            cm.role,
            COUNT(ma.id) as total_meetings,
            SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END) as meetings_attended,
            ROUND((SUM(CASE WHEN ma.attendance_status = 'present' THEN 1 ELSE 0 END)::numeric / NULLIF(COUNT(ma.id), 0) * 100), 2) as attendance_rate
        FROM committee_members cm
        LEFT JOIN meeting_attendance ma ON cm.id = ma.committee_member_id
        LEFT JOIN meetings m ON ma.meeting_id = m.id
        WHERE cm.status = 'active'
        AND m.meeting_date >= CURRENT_DATE - INTERVAL '3 months'
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
            ROUND((SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END)::numeric / NULLIF(COUNT(t.id), 0) * 100), 2) as resolution_rate
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        LEFT JOIN tickets t ON u.id = t.assigned_to
        WHERE cm.status = 'active'
        AND t.created_at >= CURRENT_DATE - INTERVAL '3 months'
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
        AND r.created_at >= CURRENT_DATE - INTERVAL '3 months'
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
        $scores[] = floatval($attendance['attendance_rate']);
    }
    if ($tickets && $tickets['total_tickets'] > 0) {
        $scores[] = floatval($tickets['resolution_rate']);
    }
    if ($reports && $reports['total_reports'] > 0) {
        $scores[] = ($reports['submitted_reports'] / $reports['total_reports']) * 100;
    }
    
    if (!empty($scores)) {
        $performance_data[$member_id]['overall_score'] = round(array_sum($scores) / count($scores), 2);
    }
}

// Get recent committee activities - PostgreSQL compatible (using INTERVAL)
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
        WHERE m.created_at >= CURRENT_DATE - INTERVAL '7 days'
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
        WHERE t.created_at >= CURRENT_DATE - INTERVAL '7 days'
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
        WHERE r.created_at >= CURRENT_DATE - INTERVAL '7 days'
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

// Get new student registrations count (last 7 days) - PostgreSQL syntax
try {
    $new_students_stmt = $pdo->prepare("
        SELECT COUNT(*) as new_students 
        FROM users 
        WHERE role = 'student' 
        AND status = 'active' 
        AND created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $new_students_stmt->execute();
    $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
} catch (PDOException $e) {
    $new_students = 0;
}

// Calculate average performance
$avg_performance = 0;
if (!empty($performance_data)) {
    $total_score = array_reduce($performance_data, function($carry, $data) {
        return $carry + $data['overall_score'];
    }, 0);
    $avg_performance = round($total_score / count($performance_data), 1);
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
            width: 70px;
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
            margin-left: 70px;
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
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

        /* Alert */
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

        /* Committee Stats */
        .committee-stats {
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
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
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
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover:not(.active) {
            color: var(--primary-blue);
            background: var(--light-blue);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
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
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
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
            padding: 1.25rem;
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
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0 auto 0.75rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
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
            font-size: 0.75rem;
        }

        .info-value {
            color: var(--text-dark);
            font-size: 0.75rem;
            text-align: right;
        }

        /* Performance Score */
        .performance-score {
            text-align: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
        }

        .score-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            position: relative;
            background: conic-gradient(var(--success) 0deg, var(--light-gray) 0deg);
        }

        .score-circle::before {
            content: '';
            position: absolute;
            width: 55px;
            height: 55px;
            background: var(--white);
            border-radius: 50%;
        }

        .score-value {
            position: relative;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text-dark);
        }

        .score-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Member Actions */
        .member-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: #856404;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.7rem;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Performance Table */
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .performance-table th,
        .performance-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .performance-table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        .performance-table tr:hover {
            background: var(--light-blue);
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s ease;
        }

        .progress-fill.warning {
            background: var(--warning);
        }

        .progress-fill.danger {
            background: var(--danger);
        }

        /* Status Badge */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #fff3cd;
            color: #856404;
        }

        .status-excellent {
            background: #d4edda;
            color: #155724;
        }

        .status-good {
            background: #fff3cd;
            color: #856404;
        }

        .status-needs-improvement {
            background: #f8d7da;
            color: #721c24;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.8rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
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
            background: var(--light-gray);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 2% auto;
            border-radius: var(--border-radius-lg);
            width: 95%;
            max-width: 600px;
            box-shadow: var(--shadow-lg);
            position: relative;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            line-height: 1;
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--white);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-family: inherit;
            background: var(--white);
            color: var(--text-dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2.5rem;
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

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--light-gray);
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

            .committee-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .committee-grid {
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

            .committee-stats {
                grid-template-columns: 1fr;
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

            .performance-table {
                font-size: 0.7rem;
            }

            .performance-table th,
            .performance-table td {
                padding: 0.5rem;
            }

            .modal-content {
                width: 98%;
                margin: 1% auto;
            }
        }

        @media (max-width: 480px) {
            .stat-number {
                font-size: 1.2rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
            }

            .member-actions {
                flex-wrap: wrap;
                justify-content: center;
            }

            .btn-sm {
                padding: 0.3rem 0.6rem;
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
                    <h1>Isonga - President</h1>
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
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Guild President</div>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php" >
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php" >
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php" class="active">
                        <i class="fas fa-users"></i>
                        <span>Committee Performance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="manage_committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
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
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
                    </a>
                </li>
                 <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Reports</span>
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
            <div class="container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Committee Management</h1>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="committee-stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($committee_members); ?></div>
                        <div class="stat-label">Total Committee Members</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-number">
                            <?php 
                            $active_count = array_reduce($committee_members, function($carry, $member) {
                                return $carry + (($member['status'] ?? 'active') === 'active' ? 1 : 0);
                            }, 0);
                            echo $active_count;
                            ?>
                        </div>
                        <div class="stat-label">Active Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($committee_roles); ?></div>
                        <div class="stat-label">Available Roles</div>
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
                                $score_color = $score_percent >= 80 ? 'success' : ($score_percent >= 60 ? 'warning' : 'danger');
                                ?>
                                <div class="member-card">
                                    <div class="member-header">
                                        <div class="member-avatar">
                                            <?php if (!empty($member['avatar_url'])): ?>
                                                <img src="../<?php echo htmlspecialchars($member['avatar_url']); ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">
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
                                        </div>

                                        <div class="performance-score">
                                            <div class="score-circle" style="background: conic-gradient(var(--<?php echo $score_color; ?>) 0deg <?php echo $score_percent * 3.6; ?>deg, var(--light-gray) <?php echo $score_percent * 3.6; ?>deg 360deg);">
                                                <span class="score-value"><?php echo $score_percent; ?>%</span>
                                            </div>
                                            <div class="score-label">Performance Score</div>
                                        </div>

                                        <div class="member-actions">
                                            <button class="btn btn-info btn-sm" onclick="viewMemberDetails(<?php echo $member['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-warning btn-sm" onclick="sendMessageToMember(<?php echo $member['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($member['name'])); ?>')">
                                                <i class="fas fa-envelope"></i> Message
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 1rem; text-align: center;">
                            <button class="btn btn-primary" onclick="openAddMemberModal()">
                                <i class="fas fa-user-plus"></i> Add New Committee Member
                            </button>
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
                                    <table class="performance-table">
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
                                                        <span class="status-badge status-<?php echo $overall_score >= 80 ? 'excellent' : ($overall_score >= 60 ? 'good' : 'needs-improvement'); ?>">
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
                                <div class="activity-list">
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-avatar">
                                                <?php if ($activity['type'] === 'meeting'): ?>
                                                    <i class="fas fa-calendar-alt"></i>
                                                <?php elseif ($activity['type'] === 'ticket'): ?>
                                                    <i class="fas fa-ticket-alt"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-file-alt"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['activity_title']); ?></strong>
                                                </div>
                                                <div class="activity-time">
                                                    <?php if ($activity['member_name']): ?>
                                                        By <?php echo htmlspecialchars($activity['member_name']); ?> 
                                                        (<?php echo htmlspecialchars($committee_roles[$activity['role']] ?? $activity['role']); ?>) • 
                                                    <?php endif; ?>
                                                    <?php echo date('M j, Y g:i A', strtotime($activity['date'])); ?>
                                                </div>
                                            </div>
                                            <span class="status-badge status-<?php echo str_replace('_', '-', $activity['status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?>
                                            </span>
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
            </div>
        </main>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add Committee Member</h3>
                <span class="close">&times;</span>
            </div>
            <form method="POST" id="addMemberForm">
                <input type="hidden" name="action" value="add_member">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="user_id">Select Student *</label>
                        <select class="form-control" id="user_id" name="user_id" required>
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
                        <label for="role">Committee Role *</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="">Select a role</option>
                            <?php foreach ($committee_roles as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($value); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="bio">Bio/Description</label>
                        <textarea class="form-control" id="bio" name="bio" rows="3" placeholder="Brief description about the member..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="portfolio_description">Portfolio Description</label>
                        <textarea class="form-control" id="portfolio_description" name="portfolio_description" rows="3" placeholder="Description of their responsibilities..."></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add to Committee
                    </button>
                    <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Send Message Modal -->
    <div id="sendMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Send Message to Member</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="sendMessageForm" method="POST" action="send_message.php">
                    <input type="hidden" name="recipient_id" id="message_recipient_id">
                    
                    <div class="form-group">
                        <label for="message_subject">Subject:</label>
                        <input type="text" name="subject" id="message_subject" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="message_content">Message:</label>
                        <textarea name="message" id="message_content" class="form-control" rows="6" required></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Send Message</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Member Details Modal -->
    <div id="viewMemberModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-user-circle"></i> Member Details</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body" id="memberDetailsContent">
                <!-- Member details will be loaded here -->
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary close-modal">Close</button>
            </div>
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

        function sendMessageToMember(userId, memberName) {
            document.getElementById('message_recipient_id').value = userId;
            document.getElementById('message_subject').value = 'Message from Guild President - ' + new Date().toLocaleDateString();
            document.getElementById('message_content').value = '';
            document.getElementById('sendMessageModal').style.display = 'block';
        }

        function viewMemberDetails(memberId) {
            const modal = document.getElementById('viewMemberModal');
            const content = document.getElementById('memberDetailsContent');
            
            content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            modal.style.display = 'block';
            
            fetch('get_member_details.php?id=' + memberId)
                .then(response => response.text())
                .then(data => {
                    content.innerHTML = data;
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">Error loading member details.</div>';
                });
        }

        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.style.display = 'none';
            });
        }

        // Sidebar Toggle
        document.addEventListener('DOMContentLoaded', function() {
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
            
            window.addEventListener('resize', () => {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('mobile-open');
                    mobileOverlay.classList.remove('active');
                    if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                    document.body.style.overflow = '';
                }
            });
            
            // Modal close handlers
            const closeButtons = document.querySelectorAll('.close, .close-modal');
            closeButtons.forEach(btn => {
                btn.addEventListener('click', closeAllModals);
            });
            
            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeAllModals();
                }
            });
            
            // Form validation
            const addMemberForm = document.getElementById('addMemberForm');
            if (addMemberForm) {
                addMemberForm.addEventListener('submit', function(e) {
                    const userId = document.getElementById('user_id').value;
                    const role = document.getElementById('role').value;
                    
                    if (!userId || !role) {
                        e.preventDefault();
                        alert('Please select both a student and a role.');
                    }
                });
            }
            
            // Add animation to cards
            const cards = document.querySelectorAll('.stat-card, .member-card, .card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.animation = `fadeInUp 0.3s ease forwards`;
                card.style.animationDelay = `${index * 0.05}s`;
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
            }, 100);
        });
    </script>
</body>
</html>