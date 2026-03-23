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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_club'])) {
        // Add new environmental club
        $name = $_POST['name'];
        $description = $_POST['description'];
        $category = 'environment'; // Environmental clubs fall under cultural category
        $department = $_POST['department'];
        $established_date = $_POST['established_date'];
        $meeting_schedule = $_POST['meeting_schedule'];
        $meeting_location = $_POST['meeting_location'];
        $faculty_advisor = $_POST['faculty_advisor'];
        $advisor_contact = $_POST['advisor_contact'];
        
        // Handle logo upload
        $logo_url = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/clubs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $file_name = 'club_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                $logo_url = 'assets/uploads/clubs/' . $file_name;
            }
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO clubs 
                (name, description, category, department, established_date, meeting_schedule, 
                 meeting_location, faculty_advisor, advisor_contact, logo_url, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $name, $description, $category, $department, $established_date, 
                $meeting_schedule, $meeting_location, $faculty_advisor, $advisor_contact, 
                $logo_url, $user_id
            ]);
            $club_id = $pdo->lastInsertId();
            $success_message = "Environmental club created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating club: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_club'])) {
        // Update club information
        $club_id = $_POST['club_id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $department = $_POST['department'];
        $meeting_schedule = $_POST['meeting_schedule'];
        $meeting_location = $_POST['meeting_location'];
        $faculty_advisor = $_POST['faculty_advisor'];
        $advisor_contact = $_POST['advisor_contact'];
        $status = $_POST['status'];
        
        // Handle logo upload
        $logo_url = $_POST['current_logo'];
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/clubs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $file_name = 'club_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                // Delete old logo if exists
                if ($logo_url && file_exists('../' . $logo_url)) {
                    unlink('../' . $logo_url);
                }
                $logo_url = 'assets/uploads/clubs/' . $file_name;
            }
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE clubs 
                SET name = ?, description = ?, department = ?, meeting_schedule = ?, 
                    meeting_location = ?, faculty_advisor = ?, advisor_contact = ?, 
                    logo_url = ?, status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $department, $meeting_schedule, $meeting_location,
                $faculty_advisor, $advisor_contact, $logo_url, $status, $club_id
            ]);
            $success_message = "Club updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating club: " . $e->getMessage();
        }
    } elseif (isset($_POST['add_member'])) {
        // Add member to club
        $club_id = $_POST['club_id'];
        $reg_number = $_POST['reg_number'];
        $name = $_POST['name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $department_id = $_POST['department_id'];
        $program_id = $_POST['program_id'];
        $academic_year = $_POST['academic_year'];
        $role = $_POST['role'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO club_members 
                (club_id, reg_number, name, email, phone, department_id, program_id, academic_year, role, join_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active')
            ");
            $stmt->execute([
                $club_id, $reg_number, $name, $email, $phone, $department_id, 
                $program_id, $academic_year, $role
            ]);
            
            // Update members count in clubs table
            $stmt = $pdo->prepare("
                UPDATE clubs 
                SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'active') 
                WHERE id = ?
            ");
            $stmt->execute([$club_id, $club_id]);
            
            $success_message = "Member added to club successfully!";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                $error_message = "This student is already a member of this club!";
            } else {
                $error_message = "Error adding member: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_member_status'])) {
        // Update member status
        $member_id = $_POST['member_id'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE club_members SET status = ? WHERE id = ?");
            $stmt->execute([$status, $member_id]);
            
            // Update members count in clubs table
            $stmt = $pdo->prepare("
                UPDATE clubs 
                SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = (SELECT club_id FROM club_members WHERE id = ?) AND status = 'active') 
                WHERE id = (SELECT club_id FROM club_members WHERE id = ?)
            ");
            $stmt->execute([$member_id, $member_id]);
            
            $success_message = "Member status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating member: " . $e->getMessage();
        }
    } elseif (isset($_POST['add_activity'])) {
        // Add club activity
        $club_id = $_POST['club_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $activity_type = $_POST['activity_type'];
        $activity_date = $_POST['activity_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $location = $_POST['location'];
        $budget = $_POST['budget'] ?: 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO club_activities 
                (club_id, title, description, activity_type, activity_date, start_time, end_time, location, budget, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
            ");
            $stmt->execute([
                $club_id, $title, $description, $activity_type, $activity_date, 
                $start_time, $end_time, $location, $budget, $user_id
            ]);
            $success_message = "Club activity scheduled successfully!";
        } catch (PDOException $e) {
            $error_message = "Error scheduling activity: " . $e->getMessage();
        }
    }
}

// Handle deletions
if (isset($_GET['delete_club'])) {
    $club_id = $_GET['delete_club'];
    try {
        $stmt = $pdo->prepare("UPDATE clubs SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$club_id]);
        $success_message = "Club deactivated successfully!";
    } catch (PDOException $e) {
        $error_message = "Error deactivating club: " . $e->getMessage();
    }
}

if (isset($_GET['delete_member'])) {
    $member_id = $_GET['delete_member'];
    try {
        $stmt = $pdo->prepare("DELETE FROM club_members WHERE id = ?");
        $stmt->execute([$member_id]);
        
        // Update members count
        $stmt = $pdo->prepare("
            UPDATE clubs 
            SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = (SELECT club_id FROM club_members WHERE id = ?) AND status = 'active') 
            WHERE id = (SELECT club_id FROM club_members WHERE id = ?)
        ");
        $stmt->execute([$member_id, $member_id]);
        
        $success_message = "Member removed from club successfully!";
    } catch (PDOException $e) {
        $error_message = "Error removing member: " . $e->getMessage();
    }
}

// Handle member status update
if (isset($_GET['update_member_status'])) {
    $member_id = $_GET['update_member_status'];
    $status = $_GET['status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE club_members SET status = ? WHERE id = ?");
        $stmt->execute([$status, $member_id]);
        
        // Update members count in clubs table
        $stmt = $pdo->prepare("
            UPDATE clubs 
            SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = (SELECT club_id FROM club_members WHERE id = ?) AND status = 'active') 
            WHERE id = (SELECT club_id FROM club_members WHERE id = ?)
        ");
        $stmt->execute([$member_id, $member_id]);
        
        $success_message = "Member status updated successfully!";
    } catch (PDOException $e) {
        $error_message = "Error updating member status: " . $e->getMessage();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'active';
$category_filter = $_GET['category'] ?? 'environment';
$search_term = $_GET['search'] ?? '';

// Build query for environmental clubs - FIXED to include member count
$query = "
    SELECT c.*, 
           COUNT(cm.id) as actual_members_count 
    FROM clubs c 
    LEFT JOIN club_members cm ON c.id = cm.club_id AND cm.status = 'active'
    WHERE c.category = 'environment'
";

$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND c.status = ?";
    $params[] = $status_filter;
}

if (!empty($search_term)) {
    $query .= " AND (c.name LIKE ? OR c.description LIKE ? OR c.department LIKE ? OR c.faculty_advisor LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " GROUP BY c.id ORDER BY c.name ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Clubs query error: " . $e->getMessage());
    $clubs = [];
}

// Get specific club details for view/edit
$club_details = null;
$club_members = [];
$club_activities = [];
$edit_mode = isset($_GET['edit']);

if (isset($_GET['view_club'])) {
    $club_id = $_GET['view_club'];
    try {
        // Get club details
        $stmt = $pdo->prepare("SELECT * FROM clubs WHERE id = ?");
        $stmt->execute([$club_id]);
        $club_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get club members with actual count
        $stmt = $pdo->prepare("
            SELECT cm.*, d.name as department_name, p.name as program_name 
            FROM club_members cm 
            LEFT JOIN departments d ON cm.department_id = d.id 
            LEFT JOIN programs p ON cm.program_id = p.id 
            WHERE cm.club_id = ? 
            ORDER BY 
                CASE cm.role 
                    WHEN 'president' THEN 1
                    WHEN 'vice_president' THEN 2
                    WHEN 'secretary' THEN 3
                    WHEN 'treasurer' THEN 4
                    ELSE 5
                END, cm.name
        ");
        $stmt->execute([$club_id]);
        $club_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update the members count in clubs table to reflect actual count
        $actual_member_count = count(array_filter($club_members, function($member) {
            return $member['status'] === 'active';
        }));
        
        $stmt = $pdo->prepare("UPDATE clubs SET members_count = ? WHERE id = ?");
        $stmt->execute([$actual_member_count, $club_id]);
        
        // Get club activities
        $stmt = $pdo->prepare("
            SELECT * FROM club_activities 
            WHERE club_id = ? 
            ORDER BY activity_date DESC, start_time DESC
            LIMIT 10
        ");
        $stmt->execute([$club_id]);
        $club_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Club details error: " . $e->getMessage());
    }
}

// Get departments and programs for dropdowns
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT id, name FROM programs WHERE is_active = 1 ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    $programs = [];
}

// Get statistics
try {
    // Total environmental clubs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clubs WHERE category = 'environment'");
    $total_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Active clubs
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM clubs WHERE category = 'environment' AND status = 'active'");
    $active_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0;
    
    // Total members across all environmental clubs
    $stmt = $pdo->query("
        SELECT COUNT(*) as total_members 
        FROM club_members cm 
        JOIN clubs c ON cm.club_id = c.id 
        WHERE c.category = 'environment' AND cm.status = 'active'
    ");
    $total_members = $stmt->fetch(PDO::FETCH_ASSOC)['total_members'] ?? 0;
    
    // Recent activities
    $stmt = $pdo->query("
        SELECT COUNT(*) as recent_activities 
        FROM club_activities ca 
        JOIN clubs c ON ca.club_id = c.id 
        WHERE c.category = 'environment' AND ca.activity_date >= CURDATE()
    ");
    $recent_activities = $stmt->fetch(PDO::FETCH_ASSOC)['recent_activities'] ?? 0;
    
} catch (PDOException $e) {
    $total_clubs = $active_clubs = $total_members = $recent_activities = 0;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Environmental Clubs - Minister of Environment & Security</title>
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
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-green: #4caf50;
            --secondary-green: #66bb6a;
            --accent-green: #388e3c;
            --light-green: #1b5e20;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
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
            color: var(--primary-green);
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
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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

        .btn-info {
            background: #17a2b8;
            color: white;
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
            border-left: 3px solid var(--primary-green);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
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
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
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
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 0;
            flex-wrap: wrap;
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
            background: var(--white);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: var(--shadow-sm);
        }

        .content-section.active {
            display: block;
        }

        .section-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 0.8rem;
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

        /* Clubs Grid */
        .clubs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .club-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .club-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .club-header {
            position: relative;
            height: 120px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .club-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-green);
            border: 4px solid var(--white);
            overflow: hidden;
        }

        .club-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .club-body {
            padding: 1.25rem;
        }

        .club-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .club-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .club-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .club-footer {
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-gray);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .status-suspended {
            background: #f8d7da;
            color: var(--danger);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 4px;
        }

        /* Club Details */
        .club-details {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .club-details-header {
            padding: 1.5rem;
            background: var(--light-green);
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .club-details-logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--primary-green);
            border: 4px solid var(--white);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .club-details-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .club-details-info {
            flex: 1;
        }

        .club-details-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .club-details-meta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .club-details-body {
            padding: 1.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-section {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
        }

        .info-section h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-section p {
            font-size: 0.8rem;
            color: var(--dark-gray);
            line-height: 1.4;
        }

        /* Tables */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
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

        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .role-president {
            background: #d4edda;
            color: var(--success);
        }

        .role-vice_president {
            background: #cce7ff;
            color: var(--primary-green);
        }

        .role-secretary {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .role-treasurer {
            background: #fff3cd;
            color: var(--warning);
        }

        .role-member {
            background: #f8d7da;
            color: var(--danger);
        }

        .activity-status {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-scheduled {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-ongoing {
            background: #cce7ff;
            color: var(--primary-green);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
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
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            text-align: center;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--primary-green);
        }

        .file-upload input {
            display: none;
        }

        .file-upload label {
            cursor: pointer;
            color: var(--primary-green);
            font-weight: 600;
        }

        .file-preview {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .clubs-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .clubs-grid {
                grid-template-columns: 1fr;
            }
            
            .club-details-header {
                flex-direction: column;
                text-align: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
            
            .club-meta {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Environment & Security</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
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
         <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
    <a href="tickets.php">
        <i class="fas fa-ticket-alt"></i>
        <span>Student Tickets</span>
        <?php 
        // Get pending tickets count for this minister
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_tickets 
                FROM tickets 
                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
            ");
            $stmt->execute([$user_id]);
            $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
            
            if ($pending_tickets > 0): ?>
                <span class="menu-badge"><?php echo $pending_tickets; ?></span>
            <?php endif;
        } catch (PDOException $e) {
            // Skip badge if error
        }
        ?>
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
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php" class="active">
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
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Environmental Clubs Management</h1>
                    <p>Manage environmental clubs, members, and activities across campus</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-primary" onclick="openAddClubModal()">
                        <i class="fas fa-plus"></i> New Club
                    </button>
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
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_clubs; ?></div>
                        <div class="stat-label">Total Clubs</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_clubs; ?></div>
                        <div class="stat-label">Active Clubs</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_members; ?></div>
                        <div class="stat-label">Total Members</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $recent_activities; ?></div>
                        <div class="stat-label">Upcoming Activities</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab active" onclick="showTab('clubs')">
                        <i class="fas fa-users"></i> All Clubs
                    </button>
                    <?php if (isset($_GET['view_club']) && $club_details): ?>
                    <button class="tab" onclick="showTab('details')">
                        <i class="fas fa-info-circle"></i> Club Details
                    </button>
                    <button class="tab" onclick="showTab('members')">
                        <i class="fas fa-user-friends"></i> Members (<?php echo count($club_members); ?>)
                    </button>
                    <button class="tab" onclick="showTab('activities')">
                        <i class="fas fa-calendar-alt"></i> Activities
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Clubs Tab -->
                <div id="clubs-tab" class="content-section active">
                    <!-- Filters -->
                    <div class="filters-card">
                        <form method="GET" class="filters-form">
                            <div class="form-group">
                                <label class="form-label">Search Clubs</label>
                                <input type="text" name="search" class="form-control" placeholder="Search by club name, description, or advisor..." value="<?php echo htmlspecialchars($search_term); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Clubs Grid -->
<!-- Clubs Grid -->
<div class="table-container">
    <div class="section-header">
        <h3>Environmental Clubs (<?php echo count($clubs); ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddClubModal()">
            <i class="fas fa-plus"></i> New Club
        </button>
    </div>
    <?php if (empty($clubs)): ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <h3>No Environmental Clubs Found</h3>
            <p>No environmental clubs match your current filters.</p>
            <button class="btn btn-primary" onclick="openAddClubModal()">
                <i class="fas fa-plus"></i> Create First Club
            </button>
        </div>
    <?php else: ?>
        <div class="clubs-grid">
            <?php foreach ($clubs as $club): 
                // Use actual members count from the query
                $member_count = $club['actual_members_count'] ?? $club['members_count'] ?? 0;
            ?>
                <div class="club-card">
                    <div class="club-header">
                        <div class="club-logo">
                            <?php if ($club['logo_url']): ?>
                                <img src="../<?php echo htmlspecialchars($club['logo_url']); ?>" alt="<?php echo htmlspecialchars($club['name']); ?>">
                            <?php else: ?>
                                <i class="fas fa-leaf"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="club-body">
                        <h3 class="club-title"><?php echo htmlspecialchars($club['name']); ?></h3>
                        <p class="club-description"><?php echo htmlspecialchars($club['description']); ?></p>
                        <div class="club-meta">
                            <div class="meta-item">
                                <span class="meta-label">Department</span>
                                <span class="meta-value"><?php echo htmlspecialchars($club['department'] ?? 'General'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Members</span>
                                <span class="meta-value"><?php echo $member_count; ?> students</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Advisor</span>
                                <span class="meta-value"><?php echo htmlspecialchars($club['faculty_advisor'] ?? 'Not assigned'); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Established</span>
                                <span class="meta-value"><?php echo $club['established_date'] ? date('M Y', strtotime($club['established_date'])) : 'N/A'; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="club-footer">
                        <span class="status-badge status-<?php echo $club['status']; ?>">
                            <?php echo ucfirst($club['status']); ?>
                        </span>
                        <div class="action-buttons">
                            <a href="?view_club=<?php echo $club['id']; ?>" class="btn btn-sm btn-info" title="View Club Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="?view_club=<?php echo $club['id']; ?>&edit=true" class="btn btn-sm btn-warning" title="Edit Club">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button class="btn btn-sm btn-danger" onclick="deleteClub(<?php echo $club['id']; ?>)" title="Deactivate Club">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
                </div>

                <!-- Club Details Tab -->
                <?php if (isset($_GET['view_club']) && $club_details): ?>
                <div id="details-tab" class="content-section">
                    <div class="club-details">
                        <div class="club-details-header">
                            <div class="club-details-logo">
                                <?php if ($club_details['logo_url']): ?>
                                    <img src="../<?php echo htmlspecialchars($club_details['logo_url']); ?>" alt="<?php echo htmlspecialchars($club_details['name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-leaf"></i>
                                <?php endif; ?>
                            </div>
                            <div class="club-details-info">
                                <h1 class="club-details-title"><?php echo htmlspecialchars($club_details['name']); ?></h1>
                                <div class="club-details-meta">
                                    <span class="status-badge status-<?php echo $club_details['status']; ?>">
                                        <?php echo ucfirst($club_details['status']); ?>
                                    </span>
                                    <span><i class="fas fa-users"></i> <?php echo $club_details['members_count']; ?> members</span>
                                    <span><i class="fas fa-calendar"></i> Established: <?php echo $club_details['established_date'] ? date('F Y', strtotime($club_details['established_date'])) : 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="club-details-body">
                            <div class="info-grid">
                                <div class="info-section">
                                    <h4><i class="fas fa-info-circle"></i> About</h4>
                                    <p><?php echo nl2br(htmlspecialchars($club_details['description'])); ?></p>
                                </div>
                                <div class="info-section">
                                    <h4><i class="fas fa-map-marker-alt"></i> Meeting Information</h4>
                                    <p><strong>Schedule:</strong> <?php echo htmlspecialchars($club_details['meeting_schedule'] ?? 'Not specified'); ?></p>
                                    <p><strong>Location:</strong> <?php echo htmlspecialchars($club_details['meeting_location'] ?? 'Not specified'); ?></p>
                                </div>
                                <div class="info-section">
                                    <h4><i class="fas fa-user-tie"></i> Faculty Advisor</h4>
                                    <p><strong>Name:</strong> <?php echo htmlspecialchars($club_details['faculty_advisor'] ?? 'Not assigned'); ?></p>
                                    <p><strong>Contact:</strong> <?php echo htmlspecialchars($club_details['advisor_contact'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="info-section">
                                    <h4><i class="fas fa-university"></i> Department</h4>
                                    <p><?php echo htmlspecialchars($club_details['department'] ?? 'General'); ?></p>
                                </div>
                            </div>
                            
                            <div class="action-buttons" style="justify-content: center; gap: 1rem;">
                                <button class="btn btn-warning" onclick="openEditClubModal(<?php echo $club_details['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit Club
                                </button>
                                <button class="btn btn-success" onclick="openAddMemberModal(<?php echo $club_details['id']; ?>)">
                                    <i class="fas fa-user-plus"></i> Add Member
                                </button>
                                <button class="btn btn-info" onclick="openAddActivityModal(<?php echo $club_details['id']; ?>)">
                                    <i class="fas fa-calendar-plus"></i> Schedule Activity
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Members Tab -->
                <div id="members-tab" class="content-section">
                    <div class="table-container">
                        <div class="section-header">
                            <h3>Club Members (<?php echo count($club_members); ?>)</h3>
                            <button class="btn btn-primary btn-sm" onclick="openAddMemberModal(<?php echo $club_details['id']; ?>)">
                                <i class="fas fa-user-plus"></i> Add Member
                            </button>
                        </div>
                        <?php if (empty($club_members)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-friends"></i>
                                <h3>No Members Yet</h3>
                                <p>This club doesn't have any registered members.</p>
                                <button class="btn btn-primary" onclick="openAddMemberModal(<?php echo $club_details['id']; ?>)">
                                    <i class="fas fa-user-plus"></i> Add First Member
                                </button>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Registration Number</th>
                                        <th>Department</th>
                                        <th>Program</th>
                                        <th>Academic Year</th>
                                        <th>Role</th>
                                        <th>Join Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($club_members as $member): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                                                <?php if ($member['email']): ?>
                                                    <br><small><?php echo htmlspecialchars($member['email']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                            <td><?php echo htmlspecialchars($member['department_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($member['program_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($member['academic_year']); ?></td>
                                            <td>
                                                <span class="role-badge role-<?php echo $member['role']; ?>">
                                                    <?php echo str_replace('_', ' ', ucfirst($member['role'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $member['status']; ?>">
                                                    <?php echo ucfirst($member['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-warning" onclick="openUpdateMemberModal(<?php echo $member['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteMember(<?php echo $member['id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Activities Tab -->
                <div id="activities-tab" class="content-section">
                    <div class="table-container">
                        <div class="section-header">
                            <h3>Club Activities</h3>
                            <button class="btn btn-primary btn-sm" onclick="openAddActivityModal(<?php echo $club_details['id']; ?>)">
                                <i class="fas fa-calendar-plus"></i> Schedule Activity
                            </button>
                        </div>
                        <?php if (empty($club_activities)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-alt"></i>
                                <h3>No Activities Scheduled</h3>
                                <p>This club doesn't have any scheduled activities.</p>
                                <button class="btn btn-primary" onclick="openAddActivityModal(<?php echo $club_details['id']; ?>)">
                                    <i class="fas fa-calendar-plus"></i> Schedule First Activity
                                </button>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Activity Title</th>
                                        <th>Type</th>
                                        <th>Date & Time</th>
                                        <th>Location</th>
                                        <th>Budget</th>
                                        <th>Status</th>
                                        <th>Participants</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($club_activities as $activity): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                                <?php if ($activity['description']): ?>
                                                    <br><small><?php echo htmlspecialchars(substr($activity['description'], 0, 100)) . '...'; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo str_replace('_', ' ', ucfirst($activity['activity_type'])); ?></td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($activity['activity_date'])); ?>
                                                <br><small><?php echo date('g:i A', strtotime($activity['start_time'])); ?> - <?php echo date('g:i A', strtotime($activity['end_time'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($activity['location']); ?></td>
                                            <td><?php echo number_format($activity['budget'], 2); ?> RWF</td>
                                            <td>
                                                <span class="activity-status status-<?php echo $activity['status']; ?>">
                                                    <?php echo ucfirst($activity['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $activity['participants_count']; ?> students</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add Club Modal -->
    <div id="addClubModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Environmental Club</h3>
                <button class="modal-close" onclick="closeAddClubModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Club Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" required placeholder="Describe the club's mission, objectives, and activities..." rows="4"></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" placeholder="Associated department">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Established Date</label>
                            <input type="date" name="established_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Meeting Schedule</label>
                            <input type="text" name="meeting_schedule" class="form-control" placeholder="e.g., Every Monday 4 PM">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meeting Location</label>
                            <input type="text" name="meeting_location" class="form-control" placeholder="e.g., Room 101">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Faculty Advisor</label>
                            <input type="text" name="faculty_advisor" class="form-control" placeholder="Faculty member name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Advisor Contact</label>
                            <input type="text" name="advisor_contact" class="form-control" placeholder="Email or phone">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Club Logo</label>
                            <div class="file-upload">
                                <input type="file" name="logo" id="clubLogo" accept="image/*">
                                <label for="clubLogo">
                                    <i class="fas fa-upload"></i> Choose Logo File
                                </label>
                                <div class="file-preview" id="clubLogoPreview">No file chosen</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddClubModal()">Cancel</button>
                    <button type="submit" name="add_club" class="btn btn-primary">Create Club</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Member to Club</h3>
                <button class="modal-close" onclick="closeAddMemberModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="club_id" id="add_member_club_id">
                <div class="modal-body">
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
                            <select name="department_id" class="form-control">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Program</label>
                            <select name="program_id" class="form-control">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Academic Year *</label>
                            <select name="academic_year" class="form-control" required>
                                <option value="Year 1">Year 1</option>
                                <option value="Year 2">Year 2</option>
                                <option value="Year 3">Year 3</option>
                                <option value="B-Tech">B-Tech</option>
                                <option value="M-Tech">M-Tech</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-control" required>
                                <option value="member">Member</option>
                                <option value="president">President</option>
                                <option value="vice_president">Vice President</option>
                                <option value="secretary">Secretary</option>
                                <option value="treasurer">Treasurer</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddMemberModal()">Cancel</button>
                    <button type="submit" name="add_member" class="btn btn-primary">Add Member</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div id="addActivityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Schedule Club Activity</h3>
                <button class="modal-close" onclick="closeAddActivityModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="club_id" id="add_activity_club_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Activity Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" required placeholder="Describe the activity, objectives, and expected outcomes..." rows="3"></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Activity Type *</label>
                            <select name="activity_type" class="form-control" required>
                                <option value="meeting">Meeting</option>
                                <option value="workshop">Workshop</option>
                                <option value="competition">Competition</option>
                                <option value="social_event">Social Event</option>
                                <option value="training">Training</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Activity Date *</label>
                            <input type="date" name="activity_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Budget (RWF)</label>
                            <input type="number" name="budget" class="form-control" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Start Time *</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Time *</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddActivityModal()">Cancel</button>
                    <button type="submit" name="add_activity" class="btn btn-primary">Schedule Activity</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editClubModal" class="modal" style="display: <?php echo $edit_mode && $club_details ? 'flex' : 'none'; ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Environmental Club</h3>
            <button class="modal-close" onclick="closeEditClubModal()">&times;</button>
        </div>
        <?php if ($club_details): ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="club_id" value="<?php echo $club_details['id']; ?>">
            <input type="hidden" name="current_logo" value="<?php echo htmlspecialchars($club_details['logo_url'] ?? ''); ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group form-full">
                        <label class="form-label">Club Name *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($club_details['name']); ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group form-full">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control" required rows="4"><?php echo htmlspecialchars($club_details['description']); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($club_details['department'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Established Date</label>
                        <input type="date" name="established_date" class="form-control" value="<?php echo htmlspecialchars($club_details['established_date'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Meeting Schedule</label>
                        <input type="text" name="meeting_schedule" class="form-control" value="<?php echo htmlspecialchars($club_details['meeting_schedule'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Meeting Location</label>
                        <input type="text" name="meeting_location" class="form-control" value="<?php echo htmlspecialchars($club_details['meeting_location'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Faculty Advisor</label>
                        <input type="text" name="faculty_advisor" class="form-control" value="<?php echo htmlspecialchars($club_details['faculty_advisor'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Advisor Contact</label>
                        <input type="text" name="advisor_contact" class="form-control" value="<?php echo htmlspecialchars($club_details['advisor_contact'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?php echo $club_details['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $club_details['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $club_details['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group form-full">
                        <label class="form-label">Club Logo</label>
                        <?php if ($club_details['logo_url']): ?>
                            <div style="margin-bottom: 10px;">
                                <img src="../<?php echo htmlspecialchars($club_details['logo_url']); ?>" alt="Current Logo" style="max-width: 100px; max-height: 100px; border-radius: 8px;">
                            </div>
                        <?php endif; ?>
                        <div class="file-upload">
                            <input type="file" name="logo" id="editClubLogo" accept="image/*">
                            <label for="editClubLogo">
                                <i class="fas fa-upload"></i> Change Logo
                            </label>
                            <div class="file-preview" id="editClubLogoPreview">No file chosen</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditClubModal()">Cancel</button>
                <button type="submit" name="update_club" class="btn btn-primary">Update Club</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Update Member Status Modal -->
<div id="updateMemberModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Member Status</h3>
            <button class="modal-close" onclick="closeUpdateMemberModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="updateMemberForm" method="GET">
                <input type="hidden" name="update_member_status" id="update_member_id">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="graduated">Graduated</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeUpdateMemberModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitMemberUpdate()">Update Status</button>
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
        function openAddClubModal() {
            document.getElementById('addClubModal').style.display = 'flex';
        }

        function closeAddClubModal() {
            document.getElementById('addClubModal').style.display = 'none';
        }

        function openAddMemberModal(clubId) {
            document.getElementById('add_member_club_id').value = clubId;
            document.getElementById('addMemberModal').style.display = 'flex';
        }

        function closeAddMemberModal() {
            document.getElementById('addMemberModal').style.display = 'none';
        }

        function openAddActivityModal(clubId) {
            document.getElementById('add_activity_club_id').value = clubId;
            document.getElementById('addActivityModal').style.display = 'flex';
        }

        function closeAddActivityModal() {
            document.getElementById('addActivityModal').style.display = 'none';
        }

        function openEditClubModal(clubId) {
            // For now, redirect to view with edit parameter
            window.location.href = '?view_club=' + clubId + '&edit=true';
        }

        function openUpdateMemberModal(memberId) {
            // Implement member update modal
            alert('Update member functionality for ID: ' + memberId);
        }

        function deleteClub(clubId) {
            if (confirm('Are you sure you want to deactivate this club? This will make it inactive but preserve all data.')) {
                window.location.href = '?delete_club=' + clubId;
            }
        }

        function deleteMember(memberId) {
            if (confirm('Are you sure you want to remove this member from the club?')) {
                window.location.href = '?delete_member=' + memberId;
            }
        }

        // File Upload Preview
        document.getElementById('clubLogo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('clubLogoPreview');
            
            if (file) {
                preview.textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
            } else {
                preview.textContent = 'No file chosen';
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['addClubModal', 'addMemberModal', 'addActivityModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'addClubModal') closeAddClubModal();
                    if (modalId === 'addMemberModal') closeAddMemberModal();
                    if (modalId === 'addActivityModal') closeAddActivityModal();
                }
            });
        }

        // Auto-set activity date to today
        document.addEventListener('DOMContentLoaded', function() {
            const activityDate = document.querySelector('input[name="activity_date"]');
            if (activityDate && !activityDate.value) {
                const today = new Date().toISOString().split('T')[0];
                activityDate.value = today;
            }

            // Set default times for activity
            const startTime = document.querySelector('input[name="start_time"]');
            const endTime = document.querySelector('input[name="end_time"]');
            if (startTime && !startTime.value) {
                startTime.value = '14:00';
            }
            if (endTime && !endTime.value) {
                endTime.value = '16:00';
            }
        });

        // Auto-refresh clubs data every 5 minutes
        setInterval(() => {
            console.log('Clubs data auto-refresh triggered');
        }, 300000);

        // Search functionality
        function searchClubs() {
            const searchTerm = document.querySelector('input[name="search"]').value.toLowerCase();
            const clubCards = document.querySelectorAll('.club-card');
            
            clubCards.forEach(card => {
                const clubName = card.querySelector('.club-title').textContent.toLowerCase();
                const clubDesc = card.querySelector('.club-description').textContent.toLowerCase();
                const clubDept = card.querySelector('.meta-value').textContent.toLowerCase();
                
                if (clubName.includes(searchTerm) || clubDesc.includes(searchTerm) || clubDept.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Add event listener for search input
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('input', searchClubs);
            }
        });

        function openEditClubModal(clubId) {
        window.location.href = '?view_club=' + clubId + '&edit=true';
    }

    function closeEditClubModal() {
        window.location.href = '?view_club=<?php echo $club_details['id'] ?? ''; ?>';
    }

    // Member Status Update Functions
    function openUpdateMemberModal(memberId) {
        document.getElementById('update_member_id').value = memberId;
        document.getElementById('updateMemberModal').style.display = 'flex';
    }

    function closeUpdateMemberModal() {
        document.getElementById('updateMemberModal').style.display = 'none';
    }

    function submitMemberUpdate() {
        document.getElementById('updateMemberForm').submit();
    }

    // Enhanced delete functions with confirmation
    function deleteClub(clubId) {
        if (confirm('Are you sure you want to deactivate this club? This will make it inactive but preserve all data.')) {
            window.location.href = '?delete_club=' + clubId;
        }
    }

    function deleteMember(memberId) {
        if (confirm('Are you sure you want to remove this member from the club?')) {
            window.location.href = '?delete_member=' + memberId;
        }
    }

    // File Upload Preview for Edit Modal
    document.getElementById('editClubLogo').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const preview = document.getElementById('editClubLogoPreview');
        
        if (file) {
            preview.textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
        } else {
            preview.textContent = 'No file chosen';
        }
    });

    // Auto-open edit modal if in edit mode
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($edit_mode && $club_details): ?>
        document.getElementById('editClubModal').style.display = 'flex';
        <?php endif; ?>
    });

    // Enhanced member management
    function updateMemberStatus(memberId, status) {
        if (confirm('Are you sure you want to update this member\'s status?')) {
            window.location.href = '?update_member_status=' + memberId + '&status=' + status;
        }
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = ['addClubModal', 'addMemberModal', 'addActivityModal', 'editClubModal', 'updateMemberModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target === modal) {
                if (modalId === 'addClubModal') closeAddClubModal();
                if (modalId === 'addMemberModal') closeAddMemberModal();
                if (modalId === 'addActivityModal') closeAddActivityModal();
                if (modalId === 'editClubModal') closeEditClubModal();
                if (modalId === 'updateMemberModal') closeUpdateMemberModal();
            }
        });
    }

    // Refresh member count when members are added/removed
    function refreshMemberCount(clubId) {
        // This would typically be done via AJAX, but for now we'll reload
        window.location.reload();
    }
    </script>
</body>
</html>