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

// Handle form actions
$action = $_GET['action'] ?? '';
$club_id = $_GET['id'] ?? 0;
$member_id = $_GET['member_id'] ?? 0;
$activity_id = $_GET['activity_id'] ?? 0;

// Handle club operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save_club') {
        $club_data = $_POST;
        
        try {
            if ($club_id) {
                // Update existing club
                $stmt = $pdo->prepare("
                    UPDATE clubs 
                    SET name = ?, description = ?, category = ?, department = ?, 
                        established_date = ?, meeting_schedule = ?, meeting_location = ?,
                        faculty_advisor = ?, advisor_contact = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $club_data['name'],
                    $club_data['description'],
                    $club_data['category'],
                    $club_data['department'],
                    $club_data['established_date'] ?: null,
                    $club_data['meeting_schedule'],
                    $club_data['meeting_location'],
                    $club_data['faculty_advisor'],
                    $club_data['advisor_contact'],
                    $club_data['status'],
                    $club_id
                ]);
                
                $_SESSION['success_message'] = "Club updated successfully!";
            } else {
                // Create new club
                $stmt = $pdo->prepare("
                    INSERT INTO clubs (name, description, category, department, established_date, 
                                    meeting_schedule, meeting_location, faculty_advisor, advisor_contact, 
                                    status, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $club_data['name'],
                    $club_data['description'],
                    $club_data['category'],
                    $club_data['department'],
                    $club_data['established_date'] ?: null,
                    $club_data['meeting_schedule'],
                    $club_data['meeting_location'],
                    $club_data['faculty_advisor'],
                    $club_data['advisor_contact'],
                    $club_data['status'],
                    $user_id
                ]);
                
                $club_id = $pdo->lastInsertId();
                $_SESSION['success_message'] = "Club created successfully!";
            }
            
            // Handle logo upload
            if (!empty($_FILES['logo']['name'])) {
                $upload_dir = "../assets/uploads/clubs/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_tmp = $_FILES['logo']['tmp_name'];
                $file_type = $_FILES['logo']['type'];
                $file_size = $_FILES['logo']['size'];
                $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $file_name = "club_{$club_id}_" . uniqid() . ".{$file_ext}";
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Store relative path in database
                    $relative_path = "assets/uploads/clubs/" . $file_name;
                    $stmt = $pdo->prepare("UPDATE clubs SET logo_url = ? WHERE id = ?");
                    $stmt->execute([$relative_path, $club_id]);
                }
            }
            
            header("Location: academic_clubs.php?view=$club_id");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error saving club: " . $e->getMessage();
        }
    }
    
    if ($action === 'add_member' && $club_id) {
        $member_data = $_POST;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO club_members (club_id, user_id, reg_number, name, email, phone, 
                                        department_id, program_id, academic_year, role, join_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active')
            ");
            $stmt->execute([
                $club_id,
                $member_data['user_id'] ?: null,
                $member_data['reg_number'],
                $member_data['name'],
                $member_data['email'],
                $member_data['phone'],
                $member_data['department_id'],
                $member_data['program_id'],
                $member_data['academic_year'],
                $member_data['role']
            ]);
            
            // Update members count
            $stmt = $pdo->prepare("UPDATE clubs SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'active') WHERE id = ?");
            $stmt->execute([$club_id, $club_id]);
            
            $_SESSION['success_message'] = "Member added successfully!";
            header("Location: academic_clubs.php?view=$club_id&tab=members");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding member: " . $e->getMessage();
        }
    }
    
    if ($action === 'save_activity' && $club_id) {
        $activity_data = $_POST;
        
        try {
            if ($activity_id) {
                // Update existing activity
                $stmt = $pdo->prepare("
                    UPDATE club_activities 
                    SET title = ?, description = ?, activity_type = ?, activity_date = ?,
                        start_time = ?, end_time = ?, location = ?, budget = ?, status = ?, updated_at = NOW()
                    WHERE id = ? AND club_id = ?
                ");
                $stmt->execute([
                    $activity_data['title'],
                    $activity_data['description'],
                    $activity_data['activity_type'],
                    $activity_data['activity_date'],
                    $activity_data['start_time'],
                    $activity_data['end_time'],
                    $activity_data['location'],
                    $activity_data['budget'] ?: 0,
                    $activity_data['status'],
                    $activity_id,
                    $club_id
                ]);
            } else {
                // Create new activity
                $stmt = $pdo->prepare("
                    INSERT INTO club_activities (club_id, title, description, activity_type, activity_date,
                                              start_time, end_time, location, budget, status, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $club_id,
                    $activity_data['title'],
                    $activity_data['description'],
                    $activity_data['activity_type'],
                    $activity_data['activity_date'],
                    $activity_data['start_time'],
                    $activity_data['end_time'],
                    $activity_data['location'],
                    $activity_data['budget'] ?: 0,
                    $user_id
                ]);
                
                $activity_id = $pdo->lastInsertId();
            }
            
            $_SESSION['success_message'] = "Activity " . ($activity_id ? 'updated' : 'created') . " successfully!";
            header("Location: academic_clubs.php?view=$club_id&tab=activities");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error saving activity: " . $e->getMessage();
        }
    }
    
    if ($action === 'record_attendance' && $activity_id) {
        $attendance_data = $_POST['attendance'] ?? [];
        
        try {
            $pdo->beginTransaction();
            
            // Clear existing attendance
            $stmt = $pdo->prepare("DELETE FROM club_attendance WHERE activity_id = ?");
            $stmt->execute([$activity_id]);
            
            // Insert new attendance records
            foreach ($attendance_data as $member_id => $status) {
                if ($status !== 'absent') {
                    $stmt = $pdo->prepare("
                        INSERT INTO club_attendance (activity_id, member_id, attendance_status, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$activity_id, $member_id, $status, $user_id]);
                }
            }
            
            // Update participants count
            $participants_count = count(array_filter($attendance_data, fn($status) => $status !== 'absent'));
            $stmt = $pdo->prepare("UPDATE club_activities SET participants_count = ? WHERE id = ?");
            $stmt->execute([$participants_count, $activity_id]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Attendance recorded successfully!";
            header("Location: academic_clubs.php?view=$club_id&tab=activities&activity_id=$activity_id");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error recording attendance: " . $e->getMessage();
        }
    }
    
    if ($action === 'delete_club' && $club_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM clubs WHERE id = ?");
            $stmt->execute([$club_id]);
            
            $_SESSION['success_message'] = "Club deleted successfully!";
            header('Location: academic_clubs.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting club: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$category_filter = $_GET['category'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$tab = $_GET['tab'] ?? 'overview';

// Build query for clubs
$query = "SELECT * FROM clubs WHERE 1=1";
$params = [];
$conditions = [];

if ($category_filter) {
    $conditions[] = "category = ?";
    $params[] = $category_filter;
}

if ($department_filter) {
    $conditions[] = "department = ?";
    $params[] = $department_filter;
}

if ($status_filter) {
    $conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $conditions[] = "(name LIKE ? OR description LIKE ? OR faculty_advisor LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " ORDER BY name ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Clubs query error: " . $e->getMessage());
    $clubs = [];
}

// Get current club details
$current_club = null;
$club_members = [];
$club_activities = [];
$club_resources = [];

if ($club_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM clubs WHERE id = ?");
        $stmt->execute([$club_id]);
        $current_club = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current_club) {
            // Get club members
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
            
            // Get club activities
            $stmt = $pdo->prepare("SELECT * FROM club_activities WHERE club_id = ? ORDER BY activity_date DESC, start_time DESC");
            $stmt->execute([$club_id]);
            $club_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get club resources
            $stmt = $pdo->prepare("SELECT * FROM club_resources WHERE club_id = ? ORDER BY created_at DESC");
            $stmt->execute([$club_id]);
            $club_resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Club details error: " . $e->getMessage());
    }
}

// Get current activity details
$current_activity = null;
$activity_attendance = [];

if ($activity_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM club_activities WHERE id = ?");
        $stmt->execute([$activity_id]);
        $current_activity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current_activity) {
            // Get attendance records
            $stmt = $pdo->prepare("
                SELECT cm.*, ca.attendance_status, ca.check_in_time 
                FROM club_members cm 
                LEFT JOIN club_attendance ca ON cm.id = ca.member_id AND ca.activity_id = ?
                WHERE cm.club_id = ? AND cm.status = 'active'
                ORDER BY cm.name
            ");
            $stmt->execute([$activity_id, $current_activity['club_id']]);
            $activity_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Activity details error: " . $e->getMessage());
    }
}

// Get club statistics
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_clubs,
            SUM(members_count) as total_members,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_clubs,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_clubs
        FROM clubs
    ");
    $club_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Club stats error: " . $e->getMessage());
    $club_stats = ['total_clubs' => 0, 'total_members' => 0, 'active_clubs' => 0, 'inactive_clubs' => 0];
}

// Get unique departments and categories for filters
try {
    $stmt = $pdo->query("SELECT DISTINCT department FROM clubs WHERE department IS NOT NULL ORDER BY department");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT DISTINCT category FROM clubs ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Filter data error: " . $e->getMessage());
    $departments = $categories = [];
}

// Get all students for member addition
try {
    $stmt = $pdo->query("
        SELECT id, reg_number, full_name, email, phone, department_id, program_id, academic_year 
        FROM users 
        WHERE status = 'active' AND role = 'student'
        ORDER BY full_name
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Students query error: " . $e->getMessage());
    $students = [];
}

// Get departments and programs for dropdowns
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
    $all_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT id, name FROM programs WHERE is_active = 1 ORDER BY name");
    $all_programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Departments/Programs query error: " . $e->getMessage());
    $all_departments = $all_programs = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Clubs Management - Isonga RPSU</title>
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
        --academic-primary: #2E7D32;
        --academic-secondary: #4CAF50;
        --academic-accent: #1B5E20;
        --academic-light: #E8F5E8;
        --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
        --academic-primary: #4CAF50;
        --academic-secondary: #66BB6A;
        --academic-accent: #2E7D32;
        --academic-light: #1B3E1B;
        --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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

    /* Main Content */
    .main-content {
        padding: 1.5rem;
        overflow-y: auto;
        height: calc(100vh - 80px);
    }

    /* Sidebar */
    .sidebar {
        background: var(--white);
        border-right: 1px solid var(--medium-gray);
        padding: 1.5rem 0;
        position: sticky;
        top: 60px;
        height: calc(100vh - 60px);
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

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--medium-gray);
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
        border-left: 3px solid var(--academic-primary);
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
        background: var(--academic-light);
        color: var(--academic-primary);
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

    /* Content Grid */
    .content-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }

    .card {
        background: var(--white);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
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

    /* Club Cards */
    .club-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 1.5rem;
    }

    .club-card {
        background: var(--white);
        border: 1px solid var(--medium-gray);
        border-radius: var(--border-radius);
        overflow: hidden;
        transition: var(--transition);
    }

    .club-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .club-header {
        padding: 1.25rem;
        border-bottom: 1px solid var(--medium-gray);
        background: var(--academic-light);
    }

    .club-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.5rem;
    }

    .club-logo {
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
        flex-shrink: 0;
        overflow: hidden;
    }

    .club-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .club-name {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 0.25rem;
    }

    .club-meta {
        display: flex;
        gap: 1rem;
        font-size: 0.8rem;
        color: var(--dark-gray);
    }

    .club-description {
        padding: 1.25rem;
        border-bottom: 1px solid var(--medium-gray);
    }

    .club-description p {
        color: var(--text-dark);
        line-height: 1.5;
        margin-bottom: 1rem;
    }

    .club-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        font-size: 0.8rem;
    }

    .detail-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .detail-icon {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: var(--light-gray);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--academic-primary);
        font-size: 0.7rem;
        flex-shrink: 0;
    }

    .club-members {
        padding: 1.25rem;
        border-bottom: 1px solid var(--medium-gray);
    }

    .members-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .members-count {
        font-weight: 600;
        color: var(--text-dark);
    }

    .members-list {
        max-height: 200px;
        overflow-y: auto;
    }

    .member-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0;
        border-bottom: 1px solid var(--medium-gray);
    }

    .member-item:last-child {
        border-bottom: none;
    }

    .member-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--gradient-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.7rem;
        flex-shrink: 0;
    }

    .member-details {
        flex: 1;
    }

    .member-name {
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-dark);
        margin-bottom: 0.1rem;
    }

    .member-role {
        font-size: 0.7rem;
        color: var(--dark-gray);
        text-transform: capitalize;
    }

    .club-actions {
        padding: 1.25rem;
        display: flex;
        gap: 0.75rem;
    }

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
        background: var(--academic-primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--academic-accent);
        transform: translateY(-1px);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-success:hover {
        background: #218838;
        transform: translateY(-1px);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-1px);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--medium-gray);
        color: var(--text-dark);
    }

    .btn-outline:hover {
        background: var(--light-gray);
        transform: translateY(-1px);
    }

    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .action-btn {
        padding: 0.4rem 0.8rem;
        background: var(--light-gray);
        border: 1px solid var(--medium-gray);
        border-radius: var(--border-radius);
        color: var(--text-dark);
        text-decoration: none;
        font-size: 0.75rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: var(--transition);
    }

    .action-btn:hover {
        background: var(--academic-primary);
        color: white;
        transform: translateY(-1px);
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
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
        color: var(--success);
    }

    .status-inactive {
        background: #f8d7da;
        color: var(--danger);
    }

    .status-suspended {
        background: #fff3cd;
        color: var(--warning);
    }

    /* Role Badges */
    .role-badge {
        padding: 0.2rem 0.5rem;
        border-radius: 12px;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: capitalize;
    }

    .role-president {
        background: #e3f2fd;
        color: #1565c0;
    }

    .role-vice_president {
        background: #e8f5e8;
        color: var(--academic-primary);
    }

    .role-secretary {
        background: #fff3e0;
        color: #ef6c00;
    }

    .role-treasurer {
        background: #f3e5f5;
        color: #7b1fa2;
    }

    .role-member {
        background: #f5f5f5;
        color: var(--dark-gray);
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

    .activity-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--academic-light);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--academic-primary);
        font-size: 0.8rem;
        flex-shrink: 0;
    }

    .activity-content {
        flex: 1;
    }

    .activity-title {
        font-size: 0.8rem;
        color: var(--text-dark);
        margin-bottom: 0.25rem;
        font-weight: 500;
    }

    .activity-meta {
        font-size: 0.7rem;
        color: var(--dark-gray);
    }

    /* Alert Messages */
    .alert {
        padding: 0.75rem 1rem;
        border-radius: var(--border-radius);
        margin-bottom: 1rem;
        border-left: 4px solid;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
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

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border-left-color: var(--danger);
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
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: var(--shadow-lg);
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--medium-gray);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.25rem;
        color: var(--dark-gray);
        cursor: pointer;
    }

    /* Form Styles */
    .form-container {
        background: var(--white);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .form-header {
        padding: 1.25rem;
        border-bottom: 1px solid var(--medium-gray);
        background: var(--academic-light);
    }

    .form-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-dark);
        margin: 0;
    }

    .form-body {
        padding: 1.5rem;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--text-dark);
        font-size: 0.85rem;
    }

    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--medium-gray);
        border-radius: var(--border-radius);
        font-size: 0.85rem;
        transition: var(--transition);
        background: var(--white);
        color: var(--text-dark);
        font-family: inherit;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: var(--academic-primary);
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    }

    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .form-select {
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        background-size: 1em;
    }

    .form-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        padding-top: 1rem;
        border-top: 1px solid var(--medium-gray);
    }

    /* Filters Card */
    .filters-card {
        background: var(--white);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .filters-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }

    .filter-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    /* Tabs */
    .tabs {
        display: flex;
        border-bottom: 1px solid var(--medium-gray);
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .tab {
        padding: 0.75rem 1.5rem;
        background: none;
        border: none;
        color: var(--dark-gray);
        cursor: pointer;
        font-weight: 500;
        border-bottom: 2px solid transparent;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .tab.active {
        color: var(--academic-primary);
        border-bottom-color: var(--academic-primary);
    }

    .tab:hover {
        color: var(--academic-primary);
        background: var(--academic-light);
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Table */
    .table-container {
        overflow-x: auto;
        margin-bottom: 1.5rem;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        background: var(--white);
        border-radius: var(--border-radius);
        overflow: hidden;
    }

    .table th,
    .table td {
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--medium-gray);
        font-size: 0.8rem;
    }

    .table th {
        background: var(--light-gray);
        font-weight: 600;
        color: var(--text-dark);
    }

    .table tr:hover {
        background: var(--academic-light);
    }

    /* Badge */
    .badge {
        padding: 0.2rem 0.5rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        background: var(--light-gray);
        color: var(--text-dark);
        text-transform: capitalize;
    }

    /* Club Details View */
    .club-details-view {
        background: var(--white);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .club-details-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.5rem;
        border-bottom: 1px solid var(--medium-gray);
        background: var(--academic-light);
    }

    .club-details-logo {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: var(--gradient-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 1.5rem;
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

    .club-details-info h2 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 0.5rem;
    }

    .club-details-info .club-meta {
        display: flex;
        gap: 1.5rem;
        font-size: 0.9rem;
        color: var(--dark-gray);
    }

    .club-status {
        padding: 0.25rem 0.5rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-active {
        background: #d4edda;
        color: var(--success);
    }

    .status-inactive {
        background: #f8d7da;
        color: var(--danger);
    }

    .status-suspended {
        background: #fff3cd;
        color: var(--warning);
    }

    /* Attendance Grid */
    .attendance-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .attendance-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        border: 1px solid var(--medium-gray);
        border-radius: var(--border-radius);
        background: var(--light-gray);
    }

    .attendance-info {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .attendance-actions {
        display: flex;
        gap: 0.5rem;
    }

    .attendance-btn {
        padding: 0.4rem 0.8rem;
        border: 1px solid var(--medium-gray);
        border-radius: var(--border-radius);
        background: var(--white);
        color: var(--text-dark);
        cursor: pointer;
        font-size: 0.7rem;
        font-weight: 500;
        transition: var(--transition);
    }

    .attendance-btn.active {
        background: var(--academic-primary);
        color: white;
        border-color: var(--academic-primary);
    }

    .attendance-btn.present.active {
        background: var(--success);
        border-color: var(--success);
    }

    .attendance-btn.excused.active {
        background: var(--warning);
        border-color: var(--warning);
    }

    .attendance-btn:hover {
        transform: translateY(-1px);
    }

    /* Clubs Grid */
    .clubs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.5rem;
    }

    .club-card {
        background: var(--white);
        border: 1px solid var(--medium-gray);
        border-radius: var(--border-radius);
        overflow: hidden;
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
    }

    .club-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .club-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem;
        border-bottom: 1px solid var(--medium-gray);
        background: var(--academic-light);
    }

    .club-logo {
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
        flex-shrink: 0;
        overflow: hidden;
    }

    .club-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .club-info h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 0.25rem;
    }

    .club-category {
        font-size: 0.8rem;
        color: var(--dark-gray);
        text-transform: capitalize;
    }

    .club-body {
        padding: 1.25rem;
    }

    .club-description {
        color: var(--text-dark);
        line-height: 1.5;
        margin-bottom: 1rem;
        font-size: 0.85rem;
    }

    .club-details {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .club-detail {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
    }

    .club-detail strong {
        color: var(--text-dark);
    }

    .club-detail span {
        color: var(--dark-gray);
    }

    .club-actions {
        display: flex;
        gap: 0.5rem;
    }

    /* ── Mobile Nav Overlay ── */
    .mobile-nav-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 199;
        backdrop-filter: blur(2px);
    }

    .mobile-nav-overlay.active { display: block; }

    /* ── Hamburger Button ── */
    .hamburger-btn {
        display: none;
        width: 44px;
        height: 44px;
        border: none;
        background: var(--light-gray);
        border-radius: 50%;
        align-items: center;
        justify-content: center;
        color: var(--text-dark);
        cursor: pointer;
        transition: var(--transition);
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .hamburger-btn:hover {
        background: var(--academic-primary);
        color: white;
    }

    /* ── Sidebar Drawer (mobile) ── */
    .sidebar {
        transition: transform 0.3s ease;
    }

    /* ── Responsive ── */

    /* Tablet & below */
    @media (max-width: 900px) {
        .dashboard-container {
            grid-template-columns: 1fr;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            z-index: 200;
            transform: translateX(-100%);
            padding-top: 1rem;
            box-shadow: var(--shadow-lg);
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .hamburger-btn {
            display: flex;
        }

        .main-content {
            height: auto;
            min-height: calc(100vh - 80px);
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .clubs-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }
    }

    /* Mobile */
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

        .logout-btn span {
            display: none;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .page-actions {
            width: 100%;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .page-actions .btn {
            flex: 1 1 auto;
            justify-content: center;
            min-width: 120px;
        }

        .tabs {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding-bottom: 0;
        }

        .tabs::-webkit-scrollbar { display: none; }

        .tab {
            white-space: nowrap;
            flex-shrink: 0;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .club-details-header {
            flex-direction: column;
            text-align: center;
            align-items: center;
        }

        .club-details-info .club-meta {
            flex-direction: column;
            gap: 0.5rem;
            align-items: center;
        }

        .attendance-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .attendance-actions {
            width: 100%;
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        .filter-form {
            grid-template-columns: 1fr;
        }

        .filter-actions {
            flex-direction: row;
            flex-wrap: wrap;
        }

        .club-actions {
            flex-wrap: wrap;
        }

        .form-actions {
            flex-wrap: wrap;
        }

        /* Table overflow on mobile */
        .card-body table {
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            white-space: nowrap;
        }
    }

    /* Small phones */
    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .main-content {
            padding: 1rem;
        }

        .clubs-grid {
            grid-template-columns: 1fr;
        }

        .club-grid {
            grid-template-columns: 1fr;
        }

        .club-actions {
            flex-direction: column;
        }

        .form-actions {
            flex-direction: column;
        }

        .filter-actions {
            flex-direction: column;
        }

        .page-actions .btn {
            width: 100%;
        }

        .header {
            height: 68px;
        }

        .main-content {
            min-height: calc(100vh - 68px);
        }

        .logos .logo {
            height: 32px;
        }

        .brand-text h1 {
            font-size: 0.9rem;
        }
    }
</style>


</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger-btn" id="hamburgerBtn" title="Toggle Menu" aria-label="Open navigation menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Affairs</h1>
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
                        <div class="user-role">Vice Guild Academic</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Mobile Nav Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay"></div>

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
                    <a href="academic_clubs.php" class="active">
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
                    <a href="performance_tracking.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Performance Tracking</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="innovation_projects.php">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Academic Clubs Management</h1>
                    <p>Manage all academic clubs, members, activities, and resources</p>
                </div>
                <div class="page-actions">
                    <a href="dashboard.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <?php if (!$current_club): ?>
                        <a href="?action=new" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Club
                        </a>
                    <?php else: ?>
                        <a href="academic_clubs.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Clubs
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <?php if ($current_club || $action === 'new'): ?>
                <!-- Club Form or Details View -->
                <?php if ($action === 'new' || $action === 'edit'): ?>
                    <!-- Club Form -->
                    <div class="form-container">
                        <div class="form-header">
                            <h2 class="form-title">
                                <?php echo $current_club ? 'Edit Club' : 'Create New Club'; ?>
                            </h2>
                        </div>
                        <form method="POST" action="?action=save_club<?php echo $current_club ? "&id={$current_club['id']}" : ''; ?>" enctype="multipart/form-data" class="form-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Club Name *</label>
                                    <input type="text" name="name" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['name'] ?? ''); ?>" 
                                           placeholder="Enter club name" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Category *</label>
                                    <select name="category" class="form-select" required>
                                        <option value="">Select Category</option>
                                        <option value="academic" <?php echo ($current_club['category'] ?? '') === 'academic' ? 'selected' : ''; ?>>Academic</option>
                                        <option value="cultural" <?php echo ($current_club['category'] ?? '') === 'cultural' ? 'selected' : ''; ?>>Cultural</option>
                                        <option value="sports" <?php echo ($current_club['category'] ?? '') === 'sports' ? 'selected' : ''; ?>>Sports</option>
                                        <option value="technical" <?php echo ($current_club['category'] ?? '') === 'technical' ? 'selected' : ''; ?>>Technical</option>
                                        <option value="entrepreneurship" <?php echo ($current_club['category'] ?? '') === 'entrepreneurship' ? 'selected' : ''; ?>>Entrepreneurship</option>
                                        <option value="other" <?php echo ($current_club['category'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['department'] ?? ''); ?>" 
                                           placeholder="e.g., ICT, Civil Engineering">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Established Date</label>
                                    <input type="date" name="established_date" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['established_date'] ?? ''); ?>">
                                </div>

                                <div class="form-group full-width">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-textarea" 
                                              placeholder="Describe the club's purpose and activities"><?php echo htmlspecialchars($current_club['description'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Meeting Schedule</label>
                                    <input type="text" name="meeting_schedule" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['meeting_schedule'] ?? ''); ?>" 
                                           placeholder="e.g., Every Tuesday 2 PM">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Meeting Location</label>
                                    <input type="text" name="meeting_location" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['meeting_location'] ?? ''); ?>" 
                                           placeholder="e.g., Room 101, Main Building">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Faculty Advisor</label>
                                    <input type="text" name="faculty_advisor" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['faculty_advisor'] ?? ''); ?>" 
                                           placeholder="Faculty advisor name">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Advisor Contact</label>
                                    <input type="text" name="advisor_contact" class="form-input" 
                                           value="<?php echo htmlspecialchars($current_club['advisor_contact'] ?? ''); ?>" 
                                           placeholder="Email or phone number">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" <?php echo ($current_club['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($current_club['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="suspended" <?php echo ($current_club['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Club Logo</label>
                                    <input type="file" name="logo" class="form-input" accept="image/*">
                                    <?php if (!empty($current_club['logo_url'])): ?>
                                        <div style="margin-top: 0.5rem;">
                                            <img src="../<?php echo htmlspecialchars($current_club['logo_url']); ?>" alt="Club Logo" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover;">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-actions">
                                <a href="<?php echo $current_club ? "?view={$current_club['id']}" : 'academic_clubs.php'; ?>" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> <?php echo $current_club ? 'Update Club' : 'Create Club'; ?>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Club Details View -->
                    <div class="club-details-view">
                        <div class="club-details-header">
                            <div class="club-details-logo">
                                <?php if (!empty($current_club['logo_url'])): ?>
                                    <img src="../<?php echo htmlspecialchars($current_club['logo_url']); ?>" alt="<?php echo htmlspecialchars($current_club['name']); ?>">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($current_club['name'], 0, 2)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="club-details-info">
                                <h2><?php echo htmlspecialchars($current_club['name']); ?></h2>
                                <div class="club-meta">
                                    <span><strong>Category:</strong> <?php echo ucfirst($current_club['category']); ?></span>
                                    <span><strong>Department:</strong> <?php echo htmlspecialchars($current_club['department'] ?? 'N/A'); ?></span>
                                    <span><strong>Members:</strong> <?php echo $current_club['members_count']; ?></span>
                                    <span><strong>Status:</strong> 
                                        <span class="club-status status-<?php echo $current_club['status']; ?>">
                                            <?php echo ucfirst($current_club['status']); ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div style="margin-left: auto;">
                                <a href="?action=edit&id=<?php echo $current_club['id']; ?>" class="btn btn-outline">
                                    <i class="fas fa-edit"></i> Edit Club
                                </a>
                            </div>
                        </div>

                        <!-- Tabs -->
                        <div class="tabs">
                            <button class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>" onclick="window.location.href='?view=<?php echo $current_club['id']; ?>&tab=overview'">
                                <i class="fas fa-info-circle"></i> Overview
                            </button>
                            <button class="tab <?php echo $tab === 'members' ? 'active' : ''; ?>" onclick="window.location.href='?view=<?php echo $current_club['id']; ?>&tab=members'">
                                <i class="fas fa-users"></i> Members (<?php echo count($club_members); ?>)
                            </button>
                            <button class="tab <?php echo $tab === 'activities' ? 'active' : ''; ?>" onclick="window.location.href='?view=<?php echo $current_club['id']; ?>&tab=activities'">
                                <i class="fas fa-calendar-alt"></i> Activities (<?php echo count($club_activities); ?>)
                            </button>
                            <button class="tab <?php echo $tab === 'resources' ? 'active' : ''; ?>" onclick="window.location.href='?view=<?php echo $current_club['id']; ?>&tab=resources'">
                                <i class="fas fa-file-alt"></i> Resources
                            </button>
                        </div>

                        <!-- Tab Content -->
                        <div class="tab-content">
                            <?php if ($tab === 'overview'): ?>
                                <!-- Overview Tab -->
                                <div style="display: grid; gap: 1.5rem;">
                                    <div>
                                        <h3 style="margin-bottom: 1rem;">Club Description</h3>
                                        <p style="color: var(--dark-gray); line-height: 1.6;">
                                            <?php echo nl2br(htmlspecialchars($current_club['description'] ?? 'No description available.')); ?>
                                        </p>
                                    </div>

                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                        <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <strong>Faculty Advisor</strong>
                                            <p><?php echo htmlspecialchars($current_club['faculty_advisor'] ?? 'Not assigned'); ?></p>
                                        </div>
                                        <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <strong>Meeting Schedule</strong>
                                            <p><?php echo htmlspecialchars($current_club['meeting_schedule'] ?? 'Not specified'); ?></p>
                                        </div>
                                        <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <strong>Meeting Location</strong>
                                            <p><?php echo htmlspecialchars($current_club['meeting_location'] ?? 'Not specified'); ?></p>
                                        </div>
                                        <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <strong>Established</strong>
                                            <p><?php echo $current_club['established_date'] ? date('F Y', strtotime($current_club['established_date'])) : 'Not specified'; ?></p>
                                        </div>
                                    </div>

                                    <!-- Quick Actions -->
                                    <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                                        <a href="?view=<?php echo $current_club['id']; ?>&tab=members&action=add_member" class="btn btn-primary">
                                            <i class="fas fa-user-plus"></i> Add Member
                                        </a>
                                        <a href="?view=<?php echo $current_club['id']; ?>&tab=activities&action=new_activity" class="btn btn-success">
                                            <i class="fas fa-calendar-plus"></i> Schedule Activity
                                        </a>
                                        <form method="POST" action="?action=delete_club&id=<?php echo $current_club['id']; ?>" style="display: inline;">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this club? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete Club
                                            </button>
                                        </form>
                                    </div>
                                </div>

                            <?php elseif ($tab === 'members'): ?>
                                <!-- Members Tab -->
                                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 1rem;">
                                    <h3>Club Members</h3>
                                    <a href="?view=<?php echo $current_club['id']; ?>&tab=members&action=add_member" class="btn btn-primary">
                                        <i class="fas fa-user-plus"></i> Add Member
                                    </a>
                                </div>

                                <?php if ($action === 'add_member'): ?>
                                    <!-- Add Member Form -->
                                    <div class="form-container" style="margin-bottom: 1.5rem;">
                                        <div class="form-header">
                                            <h2 class="form-title">Add New Member</h2>
                                        </div>
                                        <form method="POST" action="?action=add_member&id=<?php echo $current_club['id']; ?>" class="form-body">
                                            <div class="form-grid">
                                                <div class="form-group">
                                                    <label class="form-label">Select Student</label>
                                                    <select name="user_id" class="form-select">
                                                        <option value="">Select from existing students</option>
                                                        <?php foreach ($students as $student): ?>
                                                            <option value="<?php echo $student['id']; ?>">
                                                                <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['reg_number']); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Registration Number *</label>
                                                    <input type="text" name="reg_number" class="form-input" placeholder="Registration Number" required>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Full Name *</label>
                                                    <input type="text" name="name" class="form-input" placeholder="Full name" required>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" name="email" class="form-input" placeholder="Email address">
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Phone</label>
                                                    <input type="text" name="phone" class="form-input" placeholder="Phone number">
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Department</label>
                                                    <select name="department_id" class="form-select">
                                                        <option value="">Select Department</option>
                                                        <?php foreach ($all_departments as $dept): ?>
                                                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Program</label>
                                                    <select name="program_id" class="form-select">
                                                        <option value="">Select Program</option>
                                                        <?php foreach ($all_programs as $program): ?>
                                                            <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Academic Year</label>
                                                    <select name="academic_year" class="form-select">
                                                        <option value="Year 1">Year 1</option>
                                                        <option value="Year 2">Year 2</option>
                                                        <option value="Year 3">Year 3</option>
                                                        <option value="B-Tech">B-Tech</option>
                                                        <option value="M-Tech">M-Tech</option>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Role</label>
                                                    <select name="role" class="form-select">
                                                        <option value="member">Member</option>
                                                        <option value="president">President</option>
                                                        <option value="vice_president">Vice President</option>
                                                        <option value="secretary">Secretary</option>
                                                        <option value="treasurer">Treasurer</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="form-actions">
                                                <a href="?view=<?php echo $current_club['id']; ?>&tab=members" class="btn btn-outline">
                                                    <i class="fas fa-times"></i> Cancel
                                                </a>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save"></i> Add Member
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php if (empty($club_members)): ?>
                                    <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                        <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                        <h4>No Members Yet</h4>
                                        <p>Start by adding the first member to this club.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Registration</th>
                                                    <th>Department</th>
                                                    <th>Role</th>
                                                    <th>Join Date</th>
                                                    <th>Status</th>
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
                                                        <td><?php echo htmlspecialchars($member['department_name'] ?? $member['department_id'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <span class="role-badge role-<?php echo $member['role']; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $member['status']; ?>">
                                                                <?php echo ucfirst($member['status']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                            <?php elseif ($tab === 'activities'): ?>
                                <!-- Activities Tab -->
                                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 1rem;">
                                    <h3>Club Activities</h3>
                                    <a href="?view=<?php echo $current_club['id']; ?>&tab=activities&action=new_activity" class="btn btn-primary">
                                        <i class="fas fa-calendar-plus"></i> New Activity
                                    </a>
                                </div>

                                <?php if ($action === 'new_activity' || ($activity_id && !$current_activity)): ?>
                                    <!-- Activity Form -->
                                    <div class="form-container" style="margin-bottom: 1.5rem;">
                                        <div class="form-header">
                                            <h2 class="form-title">
                                                <?php echo $activity_id ? 'Edit Activity' : 'Create New Activity'; ?>
                                            </h2>
                                        </div>
                                        <form method="POST" action="?action=save_activity&id=<?php echo $current_club['id']; ?><?php echo $activity_id ? "&activity_id=$activity_id" : ''; ?>" class="form-body">
                                            <div class="form-grid">
                                                <div class="form-group">
                                                    <label class="form-label">Activity Title *</label>
                                                    <input type="text" name="title" class="form-input" 
                                                           value="<?php echo htmlspecialchars($current_activity['title'] ?? ''); ?>" 
                                                           placeholder="Enter activity title" required>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Activity Type *</label>
                                                    <select name="activity_type" class="form-select" required>
                                                        <option value="">Select Type</option>
                                                        <option value="meeting" <?php echo ($current_activity['activity_type'] ?? '') === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                                                        <option value="workshop" <?php echo ($current_activity['activity_type'] ?? '') === 'workshop' ? 'selected' : ''; ?>>Workshop</option>
                                                        <option value="competition" <?php echo ($current_activity['activity_type'] ?? '') === 'competition' ? 'selected' : ''; ?>>Competition</option>
                                                        <option value="social_event" <?php echo ($current_activity['activity_type'] ?? '') === 'social_event' ? 'selected' : ''; ?>>Social Event</option>
                                                        <option value="training" <?php echo ($current_activity['activity_type'] ?? '') === 'training' ? 'selected' : ''; ?>>Training</option>
                                                        <option value="other" <?php echo ($current_activity['activity_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Activity Date *</label>
                                                    <input type="date" name="activity_date" class="form-input" 
                                                           value="<?php echo htmlspecialchars($current_activity['activity_date'] ?? ''); ?>" required>
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Start Time</label>
                                                    <input type="time" name="start_time" class="form-input" 
                                                           value="<?php echo htmlspecialchars($current_activity['start_time'] ?? ''); ?>">
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">End Time</label>
                                                    <input type="time" name="end_time" class="form-input" 
                                                           value="<?php echo htmlspecialchars($current_activity['end_time'] ?? ''); ?>">
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Location</label>
                                                    <input type="text" name="location" class="form-input" 
                                                           value="<?php echo htmlspecialchars($current_activity['location'] ?? ''); ?>" 
                                                           placeholder="Activity location">
                                                </div>

                                                <div class="form-group">
                                                    <label class="form-label">Budget (RWF)</label>
                                                    <input type="number" name="budget" class="form-input" 
                                                           value="<?php echo htmlspecialchars($current_activity['budget'] ?? '0'); ?>" 
                                                           placeholder="Estimated budget" step="0.01">
                                                </div>

                                                <div class="form-group full-width">
                                                    <label class="form-label">Description</label>
                                                    <textarea name="description" class="form-textarea" 
                                                              placeholder="Describe the activity"><?php echo htmlspecialchars($current_activity['description'] ?? ''); ?></textarea>
                                                </div>

                                                <?php if ($activity_id): ?>
                                                    <div class="form-group">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-select">
                                                            <option value="scheduled" <?php echo ($current_activity['status'] ?? '') === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                            <option value="ongoing" <?php echo ($current_activity['status'] ?? '') === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                                            <option value="completed" <?php echo ($current_activity['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                            <option value="cancelled" <?php echo ($current_activity['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                        </select>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="form-actions">
                                                <a href="?view=<?php echo $current_club['id']; ?>&tab=activities" class="btn btn-outline">
                                                    <i class="fas fa-times"></i> Cancel
                                                </a>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save"></i> <?php echo $activity_id ? 'Update Activity' : 'Create Activity'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php if ($activity_id && $current_activity && $action !== 'new_activity'): ?>
                                    <!-- Activity Details and Attendance -->
                                    <div class="form-container" style="margin-bottom: 1.5rem;">
                                        <div class="form-header">
                                            <h2 class="form-title"><?php echo htmlspecialchars($current_activity['title']); ?></h2>
                                            <p>
                                                <strong>Date:</strong> <?php echo date('F j, Y', strtotime($current_activity['activity_date'])); ?>
                                                <?php if ($current_activity['start_time']): ?>
                                                    | <strong>Time:</strong> <?php echo date('g:i A', strtotime($current_activity['start_time'])); ?>
                                                    <?php if ($current_activity['end_time']): ?>
                                                        - <?php echo date('g:i A', strtotime($current_activity['end_time'])); ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                | <strong>Location:</strong> <?php echo htmlspecialchars($current_activity['location'] ?? 'TBD'); ?>
                                            </p>
                                        </div>

                                        <div class="form-body">
                                            <h3 style="margin-bottom: 1rem;">Record Attendance</h3>
                                            <form method="POST" action="?action=record_attendance&id=<?php echo $current_club['id']; ?>&activity_id=<?php echo $activity_id; ?>">
                                                <div class="attendance-grid">
                                                    <?php foreach ($activity_attendance as $member): ?>
                                                        <div class="attendance-item">
                                                            <div class="attendance-info">
                                                                <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                                                                <span><?php echo htmlspecialchars($member['reg_number']); ?></span>
                                                                <span class="role-badge role-<?php echo $member['role']; ?>">
                                                                    <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                                                </span>
                                                            </div>
                                                            <div class="attendance-actions">
                                                                <button type="button" class="attendance-btn present <?php echo ($member['attendance_status'] ?? 'absent') === 'present' ? 'active' : ''; ?>" 
                                                                        onclick="setAttendance(<?php echo $member['id']; ?>, 'present')">
                                                                    Present
                                                                </button>
                                                                <button type="button" class="attendance-btn excused <?php echo ($member['attendance_status'] ?? 'absent') === 'excused' ? 'active' : ''; ?>" 
                                                                        onclick="setAttendance(<?php echo $member['id']; ?>, 'excused')">
                                                                    Excused
                                                                </button>
                                                                <button type="button" class="attendance-btn <?php echo ($member['attendance_status'] ?? 'absent') === 'absent' ? 'active' : ''; ?>" 
                                                                        onclick="setAttendance(<?php echo $member['id']; ?>, 'absent')">
                                                                    Absent
                                                                </button>
                                                                <input type="hidden" name="attendance[<?php echo $member['id']; ?>]" id="attendance_<?php echo $member['id']; ?>" value="<?php echo $member['attendance_status'] ?? 'absent'; ?>">
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="form-actions">
                                                    <a href="?view=<?php echo $current_club['id']; ?>&tab=activities" class="btn btn-outline">
                                                        <i class="fas fa-arrow-left"></i> Back to Activities
                                                    </a>
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fas fa-save"></i> Save Attendance
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (empty($club_activities) && $action !== 'new_activity' && !$activity_id): ?>
                                    <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                        <i class="fas fa-calendar-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                        <h4>No Activities Scheduled</h4>
                                        <p>Start by scheduling the first activity for this club.</p>
                                    </div>
                                <?php elseif ($action !== 'new_activity' && !$activity_id): ?>
                                    <div class="table-container">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Title</th>
                                                    <th>Type</th>
                                                    <th>Date & Time</th>
                                                    <th>Location</th>
                                                    <th>Participants</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($club_activities as $activity): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                                            <?php if ($activity['description']): ?>
                                                                <br><small style="color: var(--dark-gray);"><?php echo substr(strip_tags($activity['description']), 0, 50); ?>...</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge"><?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M j, Y', strtotime($activity['activity_date'])); ?>
                                                            <?php if ($activity['start_time']): ?>
                                                                <br><small><?php echo date('g:i A', strtotime($activity['start_time'])); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($activity['location'] ?? 'TBD'); ?></td>
                                                        <td><?php echo $activity['participants_count']; ?> / <?php echo count($club_members); ?></td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $activity['status']; ?>">
                                                                <?php echo ucfirst($activity['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <a href="?view=<?php echo $current_club['id']; ?>&tab=activities&activity_id=<?php echo $activity['id']; ?>" class="action-btn" title="Take Attendance">
                                                                    <i class="fas fa-clipboard-check"></i>
                                                                </a>
                                                                <a href="?view=<?php echo $current_club['id']; ?>&tab=activities&action=edit_activity&activity_id=<?php echo $activity['id']; ?>" class="action-btn" title="Edit">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                            <?php elseif ($tab === 'resources'): ?>
                                <!-- Resources Tab -->
                                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 1rem;">
                                    <h3>Club Resources</h3>
                                    <button class="btn btn-primary" onclick="alert('Resource upload feature coming soon!')">
                                        <i class="fas fa-upload"></i> Upload Resource
                                    </button>
                                </div>

                                <?php if (empty($club_resources)): ?>
                                    <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                        <i class="fas fa-file-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                        <h4>No Resources Available</h4>
                                        <p>Upload documents, equipment lists, or other resources for this club.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Resource</th>
                                                    <th>Type</th>
                                                    <th>Description</th>
                                                    <th>Status</th>
                                                    <th>Uploaded</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($club_resources as $resource): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($resource['title']); ?></strong>
                                                            <?php if ($resource['file_name']): ?>
                                                                <br><small><?php echo htmlspecialchars($resource['file_name']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge"><?php echo ucfirst(str_replace('_', ' ', $resource['resource_type'])); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($resource['description'] ?? 'No description'); ?></td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $resource['status']; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $resource['status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($resource['created_at'])); ?></td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <?php if ($resource['file_path']): ?>
                                                                    <a href="../<?php echo htmlspecialchars($resource['file_path']); ?>" class="action-btn" title="Download" download>
                                                                        <i class="fas fa-download"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Clubs List View -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club_stats['total_clubs']; ?></div>
                            <div class="stat-label">Total Clubs</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club_stats['active_clubs']; ?></div>
                            <div class="stat-label">Active Clubs</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club_stats['total_members']; ?></div>
                            <div class="stat-label">Total Members</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo array_sum(array_column($club_activities, 'participants_count')); ?></div>
                            <div class="stat-label">Activity Participants</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="filters-form filter-form">
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($category); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-input" placeholder="Search clubs..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="academic_clubs.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Clubs Grid -->
                <div class="clubs-grid">
                    <?php if (empty($clubs)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                            <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No Clubs Found</h3>
                            <p>No clubs match your search criteria. Try adjusting your filters or create a new club.</p>
                            <a href="?action=new" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First Club
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($clubs as $club): ?>
                            <div class="club-card">
                                <div class="club-header">
                                    <div class="club-logo">
                                        <?php if (!empty($club['logo_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($club['logo_url']); ?>" alt="<?php echo htmlspecialchars($club['name']); ?>">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($club['name'], 0, 2)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="club-info">
                                        <h3><?php echo htmlspecialchars($club['name']); ?></h3>
                                        <span class="club-category"><?php echo ucfirst($club['category']); ?></span>
                                    </div>
                                </div>
                                <div class="club-body">
                                    <p class="club-description">
                                        <?php echo htmlspecialchars(substr($club['description'] ?? 'No description available.', 0, 100)); ?>...
                                    </p>
                                    <div class="club-details">
                                        <div class="club-detail">
                                            <strong>Department:</strong>
                                            <span><?php echo htmlspecialchars($club['department'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="club-detail">
                                            <strong>Members:</strong>
                                            <span><?php echo $club['members_count']; ?></span>
                                        </div>
                                        <div class="club-detail">
                                            <strong>Advisor:</strong>
                                            <span><?php echo htmlspecialchars($club['faculty_advisor'] ?? 'Not assigned'); ?></span>
                                        </div>
                                        <div class="club-detail">
                                            <strong>Status:</strong>
                                            <span class="club-status status-<?php echo $club['status']; ?>">
                                                <?php echo ucfirst($club['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="club-actions">
                                        <a href="?view=<?php echo $club['id']; ?>" class="action-btn">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="?action=edit&id=<?php echo $club['id']; ?>" class="action-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
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

        // Mobile Sidebar Toggle
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('mobileNavOverlay');

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            hamburgerBtn.innerHTML = '<i class="fas fa-times"></i>';
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            hamburgerBtn.innerHTML = '<i class="fas fa-bars"></i>';
            document.body.style.overflow = '';
        }

        hamburgerBtn.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        overlay.addEventListener('click', closeSidebar);

        // Close sidebar on resize back to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 900) closeSidebar();
        });


        // Attendance functionality
        function setAttendance(memberId, status) {
            document.getElementById('attendance_' + memberId).value = status;
            
            // Update button styles
            const buttons = document.querySelectorAll(`[onclick="setAttendance(${memberId}, '")`);
            buttons.forEach(btn => {
                btn.classList.remove('active', 'present', 'excused');
                if (btn.textContent.trim().toLowerCase() === status) {
                    btn.classList.add('active', status);
                }
            });
        }

        // Auto-hide alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Student selection auto-fill
        const studentSelect = document.querySelector('select[name="user_id"]');
        const manualFields = ['reg_number', 'name', 'email', 'phone', 'department_id', 'program_id', 'academic_year'];
        
        if (studentSelect) {
            studentSelect.addEventListener('change', function() {
                const selectedId = this.value;
                if (selectedId) {
                    // In a real implementation, you would fetch student data via AJAX
                    // For now, we'll just clear manual fields when a student is selected
                    manualFields.forEach(field => {
                        const input = document.querySelector(`[name="${field}"]`);
                        if (input) input.value = '';
                    });
                }
            });
        }
    </script>
</body>
</html>