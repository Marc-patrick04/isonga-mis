<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
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

// Get dashboard statistics for sidebar
try {
    // Innovation projects statistics - using correct table names
    $stmt = $pdo->query("SELECT COUNT(*) as total_projects FROM innovation_projects WHERE status != 'archived'");
    $total_projects = $stmt->fetch(PDO::FETCH_ASSOC)['total_projects'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_projects FROM innovation_projects WHERE status = 'pending_review'");
    $pending_projects = $stmt->fetch(PDO::FETCH_ASSOC)['pending_projects'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as approved_projects FROM innovation_projects WHERE status = 'approved'");
    $approved_projects = $stmt->fetch(PDO::FETCH_ASSOC)['approved_projects'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as in_progress_projects FROM innovation_projects WHERE status = 'in_progress'");
    $in_progress_projects = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress_projects'];
    
    // Innovation club members - using correct table name
    $stmt = $pdo->query("SELECT COUNT(*) as club_members FROM innovation_club_members WHERE status = 'active'");
    $club_members = $stmt->fetch(PDO::FETCH_ASSOC)['club_members'];
    
    // Academic tickets
    $stmt = $pdo->query("SELECT COUNT(*) as academic_tickets FROM tickets WHERE category_id = 1");
    $academic_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['academic_tickets'];
    
    // Get unread messages count - using correct table structure
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    
} catch (PDOException $e) {
    // If tables don't exist, set default values
    $total_projects = $pending_projects = $approved_projects = $in_progress_projects = $club_members = $academic_tickets = $unread_messages = 0;
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_project_status':
                $project_id = $_POST['project_id'];
                $status = $_POST['status'];
                $feedback = $_POST['feedback'] ?? '';
                
                $stmt = $pdo->prepare("
                    UPDATE innovation_projects 
                    SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$status, $feedback, $user_id, $project_id]);
                
                $_SESSION['success'] = "Project status updated successfully!";
                break;
                
            case 'add_club_member':
                $student_id = $_POST['student_id'];
                $role = $_POST['role'];
                $department = $_POST['department'];
                
                // Check if student exists
                $check_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'student'");
                $check_stmt->execute([$student_id]);
                $student = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    // Check if student is already a member
                    $check_member_stmt = $pdo->prepare("SELECT id FROM innovation_club_members WHERE user_id = ?");
                    $check_member_stmt->execute([$student_id]);
                    
                    if ($check_member_stmt->fetch()) {
                        $_SESSION['error'] = "Student is already a member of the innovation club";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO innovation_club_members (user_id, role, department, joined_date, status) 
                            VALUES (?, ?, ?, NOW(), 'active')
                        ");
                        $stmt->execute([$student_id, $role, $department]);
                        
                        $_SESSION['success'] = "Student added to innovation club successfully!";
                    }
                } else {
                    $_SESSION['error'] = "Student not found or invalid student ID";
                }
                break;
                
            case 'update_club_member':
                $member_id = $_POST['member_id'];
                $role = $_POST['role'];
                $status = $_POST['status'];
                
                $stmt = $pdo->prepare("
                    UPDATE innovation_club_members 
                    SET role = ?, status = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$role, $status, $member_id]);
                
                $_SESSION['success'] = "Club member updated successfully!";
                break;
                
            case 'create_project_category':
                $category_name = $_POST['category_name'];
                $description = $_POST['description'] ?? '';
                
                $stmt = $pdo->prepare("
                    INSERT INTO innovation_categories (name, description, status, created_by, created_at) 
                    VALUES (?, ?, 'active', ?, NOW())
                ");
                $stmt->execute([$category_name, $description, $user_id]);
                
                $_SESSION['success'] = "Project category created successfully!";
                break;
                
            case 'add_project_progress':
                $project_id = $_POST['project_id'];
                $progress_text = $_POST['progress_text'];
                $progress_percentage = $_POST['progress_percentage'] ?? 0;
                
                $stmt = $pdo->prepare("
                    INSERT INTO project_progress_updates (project_id, update_text, progress_percentage, updated_by, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$project_id, $progress_text, $progress_percentage, $user_id]);
                
                $_SESSION['success'] = "Progress update added successfully!";
                break;
                
            case 'create_innovation_project':
                $title = $_POST['title'];
                $description = $_POST['description'];
                $category_id = $_POST['category_id'];
                $department_id = $_POST['department_id'];
                $priority = $_POST['priority'];
                
                // For Vice Guild Academic creating a project on behalf of students
                $student_id = $_POST['student_id'] ?? $user_id;
                
                $stmt = $pdo->prepare("
                    INSERT INTO innovation_projects (title, description, student_id, category_id, department_id, priority, status, submitted_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())
                ");
                $stmt->execute([$title, $description, $student_id, $category_id, $department_id, $priority]);
                
                $_SESSION['success'] = "Innovation project created successfully!";
                break;

            case 'delete_club_member':
                $member_id = $_POST['member_id'];
                
                $stmt = $pdo->prepare("DELETE FROM innovation_club_members WHERE id = ?");
                $stmt->execute([$member_id]);
                
                $_SESSION['success'] = "Club member removed successfully!";
                break;

            case 'delete_project_category':
                $category_id = $_POST['category_id'];
                
                $stmt = $pdo->prepare("UPDATE innovation_categories SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$category_id]);
                
                $_SESSION['success'] = "Project category deleted successfully!";
                break;
        }
        
        // Refresh page to show updated data
        header("Location: innovation_projects.php");
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
        error_log("Innovation projects error: " . $e->getMessage());
    }
}

// Get innovation projects with correct table joins
try {
    $projects_stmt = $pdo->query("
        SELECT 
            ip.*, 
            u.full_name as student_name,
            u.reg_number,
            d.name as department_name,
            ic.name as category_name,
            rev.full_name as reviewed_by_name
        FROM innovation_projects ip
        LEFT JOIN users u ON ip.student_id = u.id
        LEFT JOIN departments d ON ip.department_id = d.id
        LEFT JOIN innovation_categories ic ON ip.category_id = ic.id
        LEFT JOIN users rev ON ip.reviewed_by = rev.id
        WHERE ip.status != 'archived'
        ORDER BY 
            CASE 
                WHEN ip.status = 'pending_review' THEN 1
                WHEN ip.status = 'in_progress' THEN 2
                WHEN ip.status = 'approved' THEN 3
                ELSE 4
            END,
            ip.created_at DESC
    ");
    $innovation_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $innovation_projects = [];
    error_log("Innovation projects query error: " . $e->getMessage());
}

// Get innovation club members with correct table structure
try {
    $members_stmt = $pdo->query("
        SELECT 
            icm.*,
            u.full_name,
            u.reg_number,
            u.email,
            u.phone,
            d.name as department_name
        FROM innovation_club_members icm
        JOIN users u ON icm.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        ORDER BY 
            CASE icm.role
                WHEN 'president' THEN 1
                WHEN 'team_lead' THEN 2
                WHEN 'secretary' THEN 3
                WHEN 'treasurer' THEN 4
                ELSE 5
            END,
            icm.joined_date DESC
    ");
    $club_members_list = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $club_members_list = [];
    error_log("Club members query error: " . $e->getMessage());
}

// Get project categories
try {
    $categories_stmt = $pdo->query("
        SELECT * FROM innovation_categories 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $project_categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $project_categories = [];
    error_log("Project categories query error: " . $e->getMessage());
}

// Get students for adding to club and projects
try {
    $students_stmt = $pdo->query("
        SELECT u.id, u.full_name, u.reg_number, d.name as department_name 
        FROM users u 
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.role = 'student' AND u.status = 'active'
        ORDER BY u.full_name
    ");
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
    error_log("Students query error: " . $e->getMessage());
}

// Get departments for dropdown
try {
    $dept_stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    error_log("Departments query error: " . $e->getMessage());
}

// Get project progress updates
$project_progress = [];
if (isset($_GET['view_project'])) {
    $project_id = $_GET['view_project'];
    try {
        $progress_stmt = $pdo->prepare("
            SELECT 
                ppu.*,
                u.full_name as updated_by_name
            FROM project_progress_updates ppu
            JOIN users u ON ppu.updated_by = u.id
            WHERE ppu.project_id = ?
            ORDER BY ppu.created_at DESC
        ");
        $progress_stmt->execute([$project_id]);
        $project_progress = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $project_progress = [];
        error_log("Project progress query error: " . $e->getMessage());
    }
}

// Get active tab from URL
$active_tab = $_GET['tab'] ?? 'projects';

// Display success/error messages from session
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Innovation Projects & Club - Isonga RPSU</title>
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --innovation-primary: #FF6B35;
            --innovation-secondary: #FF8E53;
            --innovation-accent: #E55A2B;
            --innovation-light: #FFF3E0;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
            --gradient-innovation: linear-gradient(135deg, var(--innovation-primary) 0%, var(--innovation-accent) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --border-radius: 8px;
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
            --academic-primary: #4CAF50;
            --academic-secondary: #66BB6A;
            --academic-accent: #2E7D32;
            --academic-light: #1B3E1B;
            --innovation-primary: #FF8A65;
            --innovation-secondary: #FFAB91;
            --innovation-accent: #F4511E;
            --innovation-light: #332219;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
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
            color: var(--academic-primary);
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
            border-color: var(--academic-primary);
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
            background: var(--academic-primary);
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
            background: var(--academic-light);
            border-left-color: var(--academic-primary);
            color: var(--academic-primary);
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
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
            border-left: 4px solid var(--innovation-primary);
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
            border-left-color: var(--academic-primary);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-card .stat-icon {
            background: var(--innovation-light);
            color: var(--innovation-primary);
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
            background: var(--academic-light);
            color: var(--academic-primary);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Tabs */
        .tabs-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .tabs-header {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--dark-gray);
            border-bottom: 3px solid transparent;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            background: var(--white);
            color: var(--text-dark);
        }

        .tab.active {
            color: var(--innovation-primary);
            border-bottom-color: var(--innovation-primary);
            background: var(--white);
        }

        .tab-badge {
            background: var(--innovation-primary);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .tab-content {
            padding: 0;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.5rem;
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

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending_review { background: #fff3cd; color: var(--warning); }
        .status-approved { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }
        .status-in_progress { background: #cce7ff; color: var(--primary-blue); }
        .status-completed { background: #d4edda; color: var(--success); }
        .status-archived { background: #e2e3e5; color: var(--dark-gray); }
        .status-active { background: #d4edda; color: var(--success); }
        .status-inactive { background: #e2e3e5; color: var(--dark-gray); }

        /* Priority Badges */
        .priority-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-high { background: #f8d7da; color: var(--danger); }
        .priority-medium { background: #fff3cd; color: var(--warning); }
        .priority-low { background: #d4edda; color: var(--success); }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
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
            background: var(--gradient-innovation);
            color: white;
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
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
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--innovation-primary);
            box-shadow: 0 0 0 2px rgba(255, 107, 53, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Project Cards */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .project-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border-left: 4px solid var(--innovation-primary);
        }

        .project-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .project-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .project-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .project-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .project-body {
            padding: 1.25rem;
        }

        .project-description {
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .project-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .project-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--innovation-light);
        }

        .modal-header h3 {
            margin: 0;
            color: var(--innovation-primary);
            font-size: 1.2rem;
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .projects-grid {
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs-header {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
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
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Affairs</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                  
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
                        <div class="user-role">Vice Guild Academic</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
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
    <a href="academic_meetings.php">
        <i class="fas fa-calendar-check"></i>
        <span>Meetings</span>
        <?php
        // Count upcoming meetings where user is invited
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as upcoming_meetings 
                FROM meeting_attendees ma 
                JOIN meetings m ON ma.meeting_id = m.id 
                WHERE ma.user_id = ? 
                AND m.meeting_date >= CURDATE() 
                AND m.status = 'scheduled'
                AND ma.attendance_status = 'invited'
            ");
            $stmt->execute([$user_id]);
            $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'];
        } catch (PDOException $e) {
            $upcoming_meetings = 0;
        }
        ?>
        <?php if ($upcoming_meetings > 0): ?>
            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
        <?php endif; ?>
    </a>
</li>
                <li class="menu-item">
                    <a href="academic_tickets.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academic Tickets</span>
                       
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Academic Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
                    </a>
                </li>
                                                <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
               
                <li class="menu-item">
                    <a href="innovation_projects.php" class="active">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
                       
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
                    <a href="academic_calendar.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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
            <div class="container">
                <!-- Page Header -->
               
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Statistics Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-lightbulb"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $total_projects; ?></div>
                            <div class="stat-label">Total Innovation Projects</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $pending_projects; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $approved_projects; ?></div>
                            <div class="stat-label">Approved Projects</div>
                        </div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club_members; ?></div>
                            <div class="stat-label">Club Members</div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs-container">
                    <div class="tabs-header">
                        <button class="tab <?php echo $active_tab === 'projects' ? 'active' : ''; ?>" onclick="switchTab('projects')">
                            <i class="fas fa-lightbulb"></i> Innovation Projects
                            <?php if ($pending_projects > 0): ?>
                                <span class="tab-badge"><?php echo $pending_projects; ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="tab <?php echo $active_tab === 'club' ? 'active' : ''; ?>" onclick="switchTab('club')">
                            <i class="fas fa-users"></i> Innovation Club
                        </button>
                        <button class="tab <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" onclick="switchTab('categories')">
                            <i class="fas fa-tags"></i> Project Categories
                        </button>
                    </div>

                    <div class="tab-content">
                        <!-- Projects Tab -->
                        <div id="projects-tab" class="tab-pane <?php echo $active_tab === 'projects' ? 'active' : ''; ?>">
                            <div class="action-buttons">
                                <button class="btn btn-primary" onclick="openModal('createProjectModal')">
                                    <i class="fas fa-plus"></i> Create Project
                                </button>
                                <button class="btn btn-secondary" onclick="refreshProjects()">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                            </div>

                            <?php if (empty($innovation_projects)): ?>
                                <div class="card">
                                    <div class="card-body" style="text-align: center; padding: 3rem;">
                                        <i class="fas fa-lightbulb" style="font-size: 3rem; color: var(--dark-gray); margin-bottom: 1rem;"></i>
                                        <h3>No Innovation Projects</h3>
                                        <p>No innovation projects have been submitted yet.</p>
                                        <button class="btn btn-primary" onclick="openModal('createProjectModal')">
                                            <i class="fas fa-plus"></i> Create First Project
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="projects-grid">
                                    <?php foreach ($innovation_projects as $project): ?>
                                        <div class="project-card">
                                            <div class="project-header">
                                                <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                                <div class="project-meta">
                                                    <span><?php echo htmlspecialchars($project['student_name']); ?></span>
                                                    <span><?php echo date('M j, Y', strtotime($project['submitted_at'] ?? $project['created_at'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="project-body">
                                                <div class="project-description">
                                                    <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                                                </div>
                                                <div class="project-details">
                                                    <div class="detail-item">
                                                        <span class="detail-label">Category</span>
                                                        <span><?php echo htmlspecialchars($project['category_name'] ?? 'Uncategorized'); ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Department</span>
                                                        <span><?php echo htmlspecialchars($project['department_name']); ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Status</span>
                                                        <span class="status-badge status-<?php echo $project['status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Priority</span>
                                                        <span class="priority-badge priority-<?php echo $project['priority'] ?? 'medium'; ?>">
                                                            <?php echo ucfirst($project['priority'] ?? 'Medium'); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($project['feedback']): ?>
                                                    <div class="feedback-section" style="margin-top: 1rem; padding: 0.75rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                                        <strong>Feedback:</strong>
                                                        <p style="margin: 0.5rem 0 0 0;"><?php echo nl2br(htmlspecialchars($project['feedback'])); ?></p>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="project-actions">
                                                    <button class="btn btn-primary btn-sm" onclick="viewProjectDetails(<?php echo $project['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </button>
                                                    
                                                    <?php if ($project['status'] === 'pending_review'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="updateProjectStatus(<?php echo $project['id']; ?>, 'approved')">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="updateProjectStatus(<?php echo $project['id']; ?>, 'rejected')">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    <?php elseif ($project['status'] === 'approved'): ?>
                                                        <button class="btn btn-warning btn-sm" onclick="updateProjectStatus(<?php echo $project['id']; ?>, 'in_progress')">
                                                            <i class="fas fa-play"></i> Start Progress
                                                        </button>
                                                    <?php elseif ($project['status'] === 'in_progress'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="updateProjectStatus(<?php echo $project['id']; ?>, 'completed')">
                                                            <i class="fas fa-flag-checkered"></i> Complete
                                                        </button>
                                                        <button class="btn btn-info btn-sm" onclick="addProgressUpdate(<?php echo $project['id']; ?>)">
                                                            <i class="fas fa-tasks"></i> Update Progress
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Innovation Club Tab -->
                        <div id="club-tab" class="tab-pane <?php echo $active_tab === 'club' ? 'active' : ''; ?>">
                            <div class="action-buttons">
                                <button class="btn btn-primary" onclick="openModal('addMemberModal')">
                                    <i class="fas fa-user-plus"></i> Add Member
                                </button>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <h3>Innovation Club Members (<?php echo $club_members; ?>)</h3>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($club_members_list)): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                            <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                            <p>No members in the innovation club yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Registration</th>
                                                    <th>Department</th>
                                                    <th>Role</th>
                                                    <th>Join Date</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($club_members_list as $member): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                                            <br>
                                                            <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($member['email']); ?></small>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                        <td><?php echo htmlspecialchars($member['department_name']); ?></td>
                                                        <td>
                                                            <span class="status-badge status-active">
                                                                <?php echo htmlspecialchars($member['role']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($member['joined_date'])); ?></td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $member['status']; ?>">
                                                                <?php echo ucfirst($member['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-primary" onclick="editClubMember(<?php echo $member['id']; ?>, '<?php echo $member['role']; ?>', '<?php echo $member['status']; ?>')">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-danger" onclick="removeClubMember(<?php echo $member['id']; ?>)">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Categories Tab -->
                        <div id="categories-tab" class="tab-pane <?php echo $active_tab === 'categories' ? 'active' : ''; ?>">
                            <div class="action-buttons">
                                <button class="btn btn-primary" onclick="openModal('addCategoryModal')">
                                    <i class="fas fa-plus"></i> Add Category
                                </button>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <h3>Project Categories</h3>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($project_categories)): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                            <i class="fas fa-tags" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                            <p>No project categories defined yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="projects-grid">
                                            <?php foreach ($project_categories as $category): ?>
                                                <div class="project-card">
                                                    <div class="project-header">
                                                        <div class="project-title"><?php echo htmlspecialchars($category['name']); ?></div>
                                                    </div>
                                                    <div class="project-body">
                                                        <div class="project-description">
                                                            <?php echo nl2br(htmlspecialchars($category['description'] ?? 'No description provided.')); ?>
                                                        </div>
                                                        <div class="project-actions">
                                                            <button class="btn btn-danger btn-sm" onclick="deleteCategory(<?php echo $category['id']; ?>)">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <!-- Create Project Modal -->
    <div id="createProjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Innovation Project</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="createProjectForm" method="POST">
                    <input type="hidden" name="action" value="create_innovation_project">
                    
                    <div class="form-group">
                        <label for="project_title">Project Title:</label>
                        <input type="text" id="project_title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="project_description">Project Description:</label>
                        <textarea id="project_description" name="description" class="form-control" rows="4" required></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="project_category">Category:</label>
                            <select id="project_category" name="category_id" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach ($project_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="project_department">Department:</label>
                            <select id="project_department" name="department_id" class="form-control" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="project_priority">Priority:</label>
                            <select id="project_priority" name="priority" class="form-control" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="project_student">Assign to Student (Optional):</label>
                            <select id="project_student" name="student_id" class="form-control">
                                <option value="">Select Student (Optional)</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name']); ?> - <?php echo htmlspecialchars($student['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Create Project</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Progress Update Modal -->
    <div id="progressUpdateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Progress Update</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="progressUpdateForm" method="POST">
                    <input type="hidden" name="action" value="add_project_progress">
                    <input type="hidden" id="progress_project_id" name="project_id">
                    
                    <div class="form-group">
                        <label for="progress_text">Progress Update:</label>
                        <textarea id="progress_text" name="progress_text" class="form-control" rows="4" required placeholder="Describe the progress made..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="progress_percentage">Progress Percentage:</label>
                        <input type="range" id="progress_percentage" name="progress_percentage" class="form-control" min="0" max="100" value="0">
                        <div style="text-align: center; margin-top: 0.5rem;">
                            <span id="progress_percentage_display">0%</span>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Add Progress Update</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Project Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="updateStatusTitle">Update Project Status</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="updateStatusForm" method="POST">
                    <input type="hidden" name="action" value="update_project_status">
                    <input type="hidden" id="project_id" name="project_id">
                    <input type="hidden" id="status" name="status">
                    
                    <div class="form-group">
                        <label for="feedback">Feedback/Comments:</label>
                        <textarea id="feedback" name="feedback" class="form-control" rows="4" placeholder="Add any feedback or comments for the student..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Update Status</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Club Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Club Member</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addMemberForm" method="POST">
                    <input type="hidden" name="action" value="add_club_member">
                    
                    <div class="form-group">
                        <label for="student_id">Select Student:</label>
                        <select id="student_id" name="student_id" class="form-control" required>
                            <option value="">Choose a student...</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>" data-department="<?php echo htmlspecialchars($student['department_name']); ?>">
                                    <?php echo htmlspecialchars($student['full_name']); ?> - <?php echo htmlspecialchars($student['reg_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role">Role:</label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="member">Member</option>
                                <option value="team_lead">Team Lead</option>
                                <option value="secretary">Secretary</option>
                                <option value="treasurer">Treasurer</option>
                                <option value="president">President</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="department">Department:</label>
                            <input type="text" id="department" name="department" class="form-control" required readonly>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Add Member</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Club Member Modal -->
    <div id="editMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Club Member</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editMemberForm" method="POST">
                    <input type="hidden" name="action" value="update_club_member">
                    <input type="hidden" id="edit_member_id" name="member_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_role">Role:</label>
                            <select id="edit_role" name="role" class="form-control" required>
                                <option value="member">Member</option>
                                <option value="team_lead">Team Lead</option>
                                <option value="secretary">Secretary</option>
                                <option value="treasurer">Treasurer</option>
                                <option value="president">President</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_status">Status:</label>
                            <select id="edit_status" name="status" class="form-control" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Update Member</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div id="addCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Project Category</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addCategoryForm" method="POST">
                    <input type="hidden" name="action" value="create_project_category">
                    
                    <div class="form-group">
                        <label for="category_name">Category Name:</label>
                        <input type="text" id="category_name" name="category_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description:</label>
                        <textarea id="description" name="description" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Create Category</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
            
            // Hide all tab panes
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            const targetTab = document.getElementById(tabName + '-tab');
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Activate selected tab button
            event.target.classList.add('active');
        }

        // Modal functionality
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modals when clicking X or outside
        document.querySelectorAll('.close, .close-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            });
        });

        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        // Project status update
        function updateProjectStatus(projectId, status) {
            document.getElementById('project_id').value = projectId;
            document.getElementById('status').value = status;
            
            let modalTitle = document.getElementById('updateStatusTitle');
            switch(status) {
                case 'approved':
                    modalTitle.textContent = 'Approve Project';
                    break;
                case 'rejected':
                    modalTitle.textContent = 'Reject Project';
                    break;
                case 'in_progress':
                    modalTitle.textContent = 'Start Project Progress';
                    break;
                case 'completed':
                    modalTitle.textContent = 'Mark Project Complete';
                    break;
            }
            
            openModal('updateStatusModal');
        }

        // Club member management
        function editClubMember(memberId, currentRole, currentStatus) {
            document.getElementById('edit_member_id').value = memberId;
            document.getElementById('edit_role').value = currentRole;
            document.getElementById('edit_status').value = currentStatus;
            openModal('editMemberModal');
        }

        function removeClubMember(memberId) {
            if (confirm('Are you sure you want to remove this member from the innovation club?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_club_member';
                form.appendChild(actionInput);
                
                const memberInput = document.createElement('input');
                memberInput.type = 'hidden';
                memberInput.name = 'member_id';
                memberInput.value = memberId;
                form.appendChild(memberInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Category management
        function deleteCategory(categoryId) {
            if (confirm('Are you sure you want to delete this category?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_project_category';
                form.appendChild(actionInput);
                
                const categoryInput = document.createElement('input');
                categoryInput.type = 'hidden';
                categoryInput.name = 'category_id';
                categoryInput.value = categoryId;
                form.appendChild(categoryInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

    
        // Auto-fill department when student is selected
        document.getElementById('student_id')?.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const department = selectedOption.getAttribute('data-department');
                document.getElementById('department').value = department || '';
            } else {
                document.getElementById('department').value = '';
            }
        });

        // Add progress update
        function addProgressUpdate(projectId) {
            document.getElementById('progress_project_id').value = projectId;
            openModal('progressUpdateModal');
        }

        // Update progress percentage display
        document.getElementById('progress_percentage')?.addEventListener('input', function() {
            document.getElementById('progress_percentage_display').textContent = this.value + '%';
        });

        // Refresh page
        function refreshProjects() {
            window.location.reload();
        }

        // Auto-fill department when student is selected in project creation
        document.getElementById('project_student')?.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const departmentText = selectedOption.textContent.split(' - ')[1];
                // Find department ID from departments array
                const departments = <?php echo json_encode($departments); ?>;
                const department = departments.find(dept => dept.name === departmentText);
                                if (department) {
                    document.getElementById('project_department').value = department.id;
                }
            }
        });

        // View project details
        function viewProjectDetails(projectId) {
            window.location.href = 'innovation_projects.php?view_project=' + projectId + '&tab=projects';
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            // Add form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let valid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            valid = false;
                            field.style.borderColor = 'var(--danger)';
                        } else {
                            field.style.borderColor = '';
                        }
                    });
                    
                    if (!valid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Initialize tooltips
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(button => {
                button.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-1px)';
                });
                button.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        // Project progress visualization
        function updateProgressBars() {
            const progressBars = document.querySelectorAll('.progress-bar');
            progressBars.forEach(bar => {
                const percentage = bar.getAttribute('data-progress');
                bar.style.width = percentage + '%';
            });
        }

        // Search and filter functionality
        function filterProjects() {
            const searchTerm = document.getElementById('projectSearch')?.value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter')?.value;
            const categoryFilter = document.getElementById('categoryFilter')?.value;
            
            const projectCards = document.querySelectorAll('.project-card');
            
            projectCards.forEach(card => {
                const title = card.querySelector('.project-title').textContent.toLowerCase();
                const status = card.querySelector('.status-badge').textContent.toLowerCase();
                const category = card.querySelector('.detail-item:nth-child(1) span:last-child').textContent.toLowerCase();
                
                let matchesSearch = !searchTerm || title.includes(searchTerm);
                let matchesStatus = !statusFilter || status.includes(statusFilter.toLowerCase());
                let matchesCategory = !categoryFilter || category.includes(categoryFilter.toLowerCase());
                
                if (matchesSearch && matchesStatus && matchesCategory) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Export functionality
        function exportProjects() {
            // This would typically generate a CSV or PDF report
            alert('Export functionality would be implemented here. This could generate a CSV or PDF report of all projects.');
        }

        // Bulk actions
        function selectAllProjects() {
            const checkboxes = document.querySelectorAll('.project-checkbox');
            const selectAll = document.getElementById('selectAll');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        function performBulkAction() {
            const selectedProjects = document.querySelectorAll('.project-checkbox:checked');
            const action = document.getElementById('bulkAction').value;
            
            if (selectedProjects.length === 0) {
                alert('Please select at least one project.');
                return;
            }
            
            if (action === '') {
                alert('Please select an action.');
                return;
            }
            
            if (confirm(`Are you sure you want to ${action} ${selectedProjects.length} project(s)?`)) {
                // Implement bulk action logic here
                const projectIds = Array.from(selectedProjects).map(checkbox => checkbox.value);
                
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'bulk_action';
                actionInput.value = action;
                form.appendChild(actionInput);
                
                projectIds.forEach(id => {
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'project_ids[]';
                    idInput.value = id;
                    form.appendChild(idInput);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Real-time updates (simulated)
        function checkForUpdates() {
            // This would typically make an AJAX call to check for new projects or updates
            console.log('Checking for updates...');
        }

        // Set up periodic updates every 30 seconds
        setInterval(checkForUpdates, 30000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N: New project
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openModal('createProjectModal');
            }
            
            // Ctrl + R: Refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                refreshProjects();
            }
            
            // Escape: Close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        // Responsive table handling
        function makeTablesResponsive() {
            const tables = document.querySelectorAll('.table');
            tables.forEach(table => {
                const wrapper = document.createElement('div');
                wrapper.className = 'table-responsive';
                wrapper.style.overflowX = 'auto';
                
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            });
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            makeTablesResponsive();
            updateProgressBars();
            
            // Show active tab based on URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab') || 'projects';
            switchTab(activeTab);
            
            // If viewing a specific project, show its details
            <?php if (isset($_GET['view_project'])): ?>
                const projectId = <?php echo $_GET['view_project']; ?>;
                viewProjectDetailsModal(projectId);
            <?php endif; ?>
        });

        // Project details modal (for when viewing specific project)
        function viewProjectDetailsModal(projectId) {
            // This would typically fetch project details via AJAX
            // For now, we'll show a simple alert
            alert('Viewing project details for ID: ' + projectId + '\n\nThis would show a detailed view with progress updates, files, and team members.');
            
            // In a real implementation, this would:
            // 1. Fetch project details via AJAX
            // 2. Populate a detailed modal
            // 3. Show progress history
            // 4. Display attached files
            // 5. Show team members and their roles
        }

        // File upload handling for project attachments
        function handleFileUpload(files, projectId) {
            // This would handle file uploads for project attachments
            console.log('Uploading files for project:', projectId);
            console.log('Files:', files);
            
            // In a real implementation, this would:
            // 1. Validate file types and sizes
            // 2. Upload files to server
            // 3. Update database with file references
            // 4. Refresh the file list
        }

        // Progress tracking chart (simplified)
        function initializeProgressChart(projectId) {
            // This would initialize a chart showing project progress over time
            console.log('Initializing progress chart for project:', projectId);
            
            // In a real implementation, this would:
            // 1. Fetch progress data
            // 2. Initialize a chart using Chart.js or similar
            // 3. Display progress trends
        }

        // Team collaboration features
        function assignTeamMember(projectId, userId, role) {
            // This would assign a team member to a project
            console.log('Assigning team member:', { projectId, userId, role });
            
            // In a real implementation, this would:
            // 1. Update project team in database
            // 2. Send notification to the assigned member
            // 3. Update the UI to reflect the assignment
        }

        // Notification system
        function sendProjectNotification(projectId, message, recipients) {
            // This would send notifications about project updates
            console.log('Sending notification:', { projectId, message, recipients });
            
            // In a real implementation, this would:
            // 1. Create notification records in database
            // 2. Send email notifications if enabled
            // 3. Update notification badges
        }

        // Analytics and reporting
        function generateProjectReport(timeframe) {
            // This would generate reports on project progress and performance
            console.log('Generating project report for timeframe:', timeframe);
            
            // In a real implementation, this would:
            // 1. Fetch analytics data
            // 2. Generate CSV/PDF report
            // 3. Download or display the report
        }

        // Resource management
        function allocateResources(projectId, resources) {
            // This would handle resource allocation for projects
            console.log('Allocating resources:', { projectId, resources });
            
            // In a real implementation, this would:
            // 1. Check resource availability
            // 2. Update resource allocation in database
            // 3. Update project budget if applicable
        }
    </script>

    <!-- Additional CSS for enhanced features -->
    <style>
        /* Enhanced table styles */
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 1rem;
        }

        /* Progress bars */
        .progress {
            height: 8px;
            background: var(--medium-gray);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-bar {
            height: 100%;
            background: var(--innovation-primary);
            transition: width 0.3s ease;
        }

        /* Checkbox styles for bulk actions */
        .project-checkbox {
            margin-right: 0.5rem;
        }

        /* Search and filter controls */
        .controls-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
        }

        .filter-select {
            min-width: 150px;
        }

        /* Bulk actions panel */
        .bulk-actions {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: none;
        }

        .bulk-actions.active {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Loading states */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            border: 2px solid var(--medium-gray);
            border-top: 2px solid var(--innovation-primary);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Status indicators */
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }

        .status-indicator.pending { background: var(--warning); }
        .status-indicator.approved { background: var(--success); }
        .status-indicator.in-progress { background: var(--primary-blue); }
        .status-indicator.completed { background: var(--success); }
        .status-indicator.rejected { background: var(--danger); }

        /* File attachments */
        .file-attachments {
            margin-top: 1rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }

        .file-icon {
            color: var(--dark-gray);
        }

        /* Team members display */
        .team-members {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .team-member {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 0.8rem;
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box, .filter-select {
                min-width: auto;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            
            .project-details {
                grid-template-columns: 1fr;
            }
        }

        /* Print styles */
        @media print {
            .header, .sidebar, .action-buttons, .bulk-actions {
                display: none !important;
            }
            
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 0;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid var(--dark-gray);
            }
        }

        /* Accessibility improvements */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            :root {
                --light-gray: #ffffff;
                --medium-gray: #000000;
                --text-dark: #000000;
            }
            
            .stat-card, .card, .project-card {
                border: 2px solid var(--text-dark);
            }
        }
    </style>
</body>
</html>