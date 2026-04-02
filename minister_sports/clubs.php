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
    
    // Check if user needs to change password (first login)
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// ========== FUNCTION DEFINITIONS ========== //

// Function to get all clubs
function getAllClubs($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT c.*, 
                   COUNT(cm.id) as total_members,
                   u.full_name as advisor_name
            FROM clubs c
            LEFT JOIN club_members cm ON c.id = cm.club_id AND cm.status = 'active'
            LEFT JOIN users u ON c.faculty_advisor = u.id
            WHERE c.category = 'entertainment'
            GROUP BY c.id, u.full_name
            ORDER BY c.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting clubs: " . $e->getMessage());
        return [];
    }
}

// Function to get club by ID
function getClubById($pdo, $id) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as advisor_name
            FROM clubs c
            LEFT JOIN users u ON c.faculty_advisor = u.id
            WHERE c.id = ? AND c.category = 'entertainment'
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting club: " . $e->getMessage());
        return null;
    }
}

// Function to get club members
function getClubMembers($pdo, $club_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT cm.*, u.full_name, u.email, u.phone, 
                   d.name as department_name, p.name as program_name
            FROM club_members cm
            LEFT JOIN users u ON cm.user_id = u.id
            LEFT JOIN departments d ON cm.department_id = d.id
            LEFT JOIN programs p ON cm.program_id = p.id
            WHERE cm.club_id = ? AND cm.status = 'active'
            ORDER BY cm.role, cm.join_date DESC
        ");
        $stmt->execute([$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting club members: " . $e->getMessage());
        return [];
    }
}

// Function to get club activities
function getClubActivities($pdo, $club_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT ca.*, u.full_name as organizer_name
            FROM club_activities ca
            LEFT JOIN users u ON ca.organizer_id = u.id
            WHERE ca.club_id = ?
            ORDER BY ca.activity_date DESC, ca.start_time ASC
            LIMIT 10
        ");
        $stmt->execute([$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting club activities: " . $e->getMessage());
        return [];
    }
}

// Function to get club resources
function getClubResources($pdo, $club_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT cr.*, u.full_name as uploaded_by_name
            FROM club_resources cr
            LEFT JOIN users u ON cr.uploaded_by = u.id
            WHERE cr.club_id = ? AND cr.status = 'available'
            ORDER BY cr.created_at DESC
        ");
        $stmt->execute([$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting club resources: " . $e->getMessage());
        return [];
    }
}

// Function to get available students (not in the club)
function getAvailableStudents($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT id, reg_number, full_name, email, phone, 
                   department_id, program_id, academic_year
            FROM users 
            WHERE role = 'student' 
            AND status = 'active'
            ORDER BY full_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting available students: " . $e->getMessage());
        return [];
    }
}

// Function to handle club form submission
function handleClubForm($pdo, $user_id, $action, $club_id) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $established_date = $_POST['established_date'] ?? null;
    $meeting_schedule = trim($_POST['meeting_schedule'] ?? '');
    $meeting_location = trim($_POST['meeting_location'] ?? '');
    $faculty_advisor = $_POST['faculty_advisor'] ?? null;
    $advisor_contact = trim($_POST['advisor_contact'] ?? '');
    $status = $_POST['status'] ?? 'active';
    
    if (empty($name) || empty($description)) {
        $_SESSION['error'] = 'Name and description are required';
        return;
    }
    
    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO clubs (name, description, category, department, established_date, 
                                   meeting_schedule, meeting_location, faculty_advisor, 
                                   advisor_contact, status, created_by, created_at, updated_at)
                VALUES (?, ?, 'entertainment', ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $name, $description, $department, $established_date, $meeting_schedule,
                $meeting_location, $faculty_advisor, $advisor_contact, $status, $user_id
            ]);
            $_SESSION['success'] = 'Club created successfully';
        } else {
            $stmt = $pdo->prepare("
                UPDATE clubs SET 
                    name = ?,
                    description = ?,
                    department = ?,
                    established_date = ?,
                    meeting_schedule = ?,
                    meeting_location = ?,
                    faculty_advisor = ?,
                    advisor_contact = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND category = 'entertainment'
            ");
            $stmt->execute([
                $name, $description, $department, $established_date, $meeting_schedule,
                $meeting_location, $faculty_advisor, $advisor_contact, $status, $club_id
            ]);
            $_SESSION['success'] = 'Club updated successfully';
        }
        
        header('Location: clubs.php');
        exit();
    } catch (PDOException $e) {
        error_log("Error saving club: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to save club: ' . $e->getMessage();
    }
}

// Function to handle delete club
function handleDeleteClub($pdo, $club_id) {
    try {
        // Check if club has members
        $stmt = $pdo->prepare("SELECT COUNT(*) as member_count FROM club_members WHERE club_id = ? AND status = 'active'");
        $stmt->execute([$club_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['member_count'] > 0) {
            $_SESSION['error'] = 'Cannot delete club with active members. Please remove members first.';
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM clubs WHERE id = ? AND category = 'entertainment'");
        $stmt->execute([$club_id]);
        $_SESSION['success'] = 'Club deleted successfully';
    } catch (PDOException $e) {
        error_log("Error deleting club: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to delete club: ' . $e->getMessage();
    }
}

// Function to handle add member
function handleAddMember($pdo, $club_id) {
    $user_id_member = $_POST['user_id'] ?? 0;
    $role = $_POST['role'] ?? 'member';
    
    if (!$user_id_member) {
        $_SESSION['error'] = 'Please select a student';
        return;
    }
    
    try {
        // Check if student is already in the club
        $stmt = $pdo->prepare("SELECT id FROM club_members WHERE club_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$club_id, $user_id_member]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = 'Student is already a member of this club';
            return;
        }
        
        // Get student details
        $stmt = $pdo->prepare("
            SELECT reg_number, full_name, email, phone, department_id, program_id, academic_year
            FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id_member]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            $_SESSION['error'] = 'Student not found';
            return;
        }
        
        // Add member
        $stmt = $pdo->prepare("
            INSERT INTO club_members (club_id, user_id, reg_number, name, email, phone, 
                                      department_id, program_id, academic_year, role, join_date, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $club_id, $user_id_member, $student['reg_number'], $student['full_name'], 
            $student['email'], $student['phone'], $student['department_id'], 
            $student['program_id'], $student['academic_year'], $role
        ]);
        
        // Update members count
        $stmt = $pdo->prepare("
            UPDATE clubs SET members_count = members_count + 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$club_id]);
        
        $_SESSION['success'] = 'Member added successfully';
        header("Location: clubs.php?action=view&id=$club_id");
        exit();
    } catch (PDOException $e) {
        error_log("Error adding member: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to add member: ' . $e->getMessage();
    }
}

// Function to handle remove member
function handleRemoveMember($pdo, $club_id, $member_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE club_members SET status = 'inactive', updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND club_id = ?
        ");
        $stmt->execute([$member_id, $club_id]);
        
        // Update members count
        $stmt = $pdo->prepare("
            UPDATE clubs SET members_count = GREATEST(0, members_count - 1), updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$club_id]);
        
        $_SESSION['success'] = 'Member removed successfully';
    } catch (PDOException $e) {
        error_log("Error removing member: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to remove member: ' . $e->getMessage();
    }
}

// Function to handle add activity
function handleAddActivity($pdo, $club_id, $user_id) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $activity_type = $_POST['activity_type'] ?? 'meeting';
    $activity_date = $_POST['activity_date'] ?? '';
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    $location = trim($_POST['location'] ?? '');
    $budget = $_POST['budget'] ?? 0;
    $organizer_id = $_POST['organizer_id'] ?? $user_id;
    
    if (empty($title) || empty($activity_date)) {
        $_SESSION['error'] = 'Title and date are required';
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO club_activities (club_id, title, description, activity_type, 
                                         activity_date, start_time, end_time, location, 
                                         budget, organizer_id, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $club_id, $title, $description, $activity_type, $activity_date,
            $start_time, $end_time, $location, $budget, $organizer_id, $user_id
        ]);
        
        $_SESSION['success'] = 'Activity added successfully';
        header("Location: clubs.php?action=view&id=$club_id");
        exit();
    } catch (PDOException $e) {
        error_log("Error adding activity: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to add activity: ' . $e->getMessage();
    }
}

// Function to handle delete activity
function handleDeleteActivity($pdo, $activity_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM club_activities WHERE id = ?");
        $stmt->execute([$activity_id]);
        $_SESSION['success'] = 'Activity deleted successfully';
    } catch (PDOException $e) {
        error_log("Error deleting activity: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to delete activity: ' . $e->getMessage();
    }
}

// Function to handle add resource
function handleAddResource($pdo, $club_id, $user_id) {
    $resource_type = $_POST['resource_type'] ?? 'document';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity = $_POST['quantity'] ?? 1;
    $value = $_POST['value'] ?? 0;
    
    if (empty($title)) {
        $_SESSION['error'] = 'Title is required';
        return;
    }
    
    // Handle file upload
    $file_name = '';
    $file_path = '';
    $file_type = '';
    $file_size = 0;
    
    if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/club_resources/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $original_name = basename($_FILES['resource_file']['name']);
        $file_tmp = $_FILES['resource_file']['tmp_name'];
        $file_type = $_FILES['resource_file']['type'];
        $file_size = $_FILES['resource_file']['size'];
        
        // Generate unique filename
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $new_filename = uniqid('resource_', true) . '.' . $file_ext;
        $file_path = $upload_dir . $new_filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file_tmp, $file_path)) {
            $_SESSION['error'] = 'Failed to upload file';
            return;
        }
        
        $file_name = $new_filename;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO club_resources (club_id, resource_type, title, description, 
                                        file_name, file_path, file_type, file_size,
                                        quantity, value, status, uploaded_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $club_id, $resource_type, $title, $description, $file_name, $file_path,
            $file_type, $file_size, $quantity, $value, $user_id
        ]);
        
        $_SESSION['success'] = 'Resource added successfully';
        header("Location: clubs.php?action=view&id=$club_id");
        exit();
    } catch (PDOException $e) {
        error_log("Error adding resource: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to add resource: ' . $e->getMessage();
    }
}

// Function to handle delete resource
function handleDeleteResource($pdo, $resource_id) {
    try {
        // Get file path before deletion
        $stmt = $pdo->prepare("SELECT file_path FROM club_resources WHERE id = ?");
        $stmt->execute([$resource_id]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Delete file from server
        if ($resource && !empty($resource['file_path']) && file_exists($resource['file_path'])) {
            unlink($resource['file_path']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM club_resources WHERE id = ?");
        $stmt->execute([$resource_id]);
        
        $_SESSION['success'] = 'Resource deleted successfully';
    } catch (PDOException $e) {
        error_log("Error deleting resource: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to delete resource: ' . $e->getMessage();
    }
}

// ========== MAIN LOGIC ========== //

$action = $_GET['action'] ?? 'list';
$club_id = $_GET['id'] ?? 0;

// Initialize variables
$clubs = [];
$club = null;
$members = [];
$activities = [];
$resources = [];
$students = [];

// Handle different actions
switch ($action) {
    case 'add':
    case 'edit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            handleClubForm($pdo, $user_id, $action, $club_id);
        } else {
            if ($action === 'edit' && $club_id) {
                $club = getClubById($pdo, $club_id);
                if (!$club) {
                    $_SESSION['error'] = 'Club not found';
                    header('Location: clubs.php');
                    exit();
                }
            }
        }
        break;
        
    case 'delete':
        if ($club_id) {
            handleDeleteClub($pdo, $club_id);
        }
        header('Location: clubs.php');
        exit();
        
    case 'add_member':
        if ($club_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
            handleAddMember($pdo, $club_id);
        }
        break;
        
    case 'remove_member':
        $member_id = $_GET['member_id'] ?? 0;
        if ($club_id && $member_id) {
            handleRemoveMember($pdo, $club_id, $member_id);
        }
        header("Location: clubs.php?action=view&id=$club_id");
        exit();
        
    case 'add_activity':
        if ($club_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
            handleAddActivity($pdo, $club_id, $user_id);
        }
        break;
        
    case 'delete_activity':
        $activity_id = $_GET['activity_id'] ?? 0;
        if ($activity_id) {
            handleDeleteActivity($pdo, $activity_id);
        }
        header("Location: clubs.php?action=view&id=$club_id");
        exit();
        
    case 'add_resource':
        if ($club_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
            handleAddResource($pdo, $club_id, $user_id);
        }
        break;
        
    case 'delete_resource':
        $resource_id = $_GET['resource_id'] ?? 0;
        if ($resource_id) {
            handleDeleteResource($pdo, $resource_id);
        }
        header("Location: clubs.php?action=view&id=$club_id");
        exit();
        
    case 'view':
        if ($club_id) {
            $club = getClubById($pdo, $club_id);
            if ($club) {
                $members = getClubMembers($pdo, $club_id);
                $activities = getClubActivities($pdo, $club_id);
                $resources = getClubResources($pdo, $club_id);
                $students = getAvailableStudents($pdo);
            }
        }
        break;
        
    default:
        $clubs = getAllClubs($pdo);
        break;
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
    error_log("Unread messages error: " . $e->getMessage());
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Entertainment Clubs - Minister of Sports & Entertainment</title>
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
            font-size: 1rem;
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

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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
            animation: fadeInUp 0.3s ease;
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

        .status-scheduled {
            background: #cce7ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        /* Role Badges */
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .role-leader {
            background: #d1ecf1;
            color: #0c5460;
        }

        .role-deputy {
            background: #fff3cd;
            color: #856404;
        }

        .role-member {
            background: #f8f9fa;
            color: #6c757d;
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
            font-size: 0.8rem;
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

        /* Club View Layout */
        .club-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .club-info h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .club-meta {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .club-sections {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

            .club-sections {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
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

            .club-meta {
                gap: 0.75rem;
            }

            .meta-item {
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.8rem;
            }

            .club-header {
                padding: 1rem;
            }

            .club-info h2 {
                font-size: 1.2rem;
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

            .action-buttons {
                flex-direction: column;
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
                    <h1>Isonga - Minister of Sports & Entertainment</h1>
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
                        <div class="user-role">Minister of Sports & Entertainment</div>
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
                    <a href="teams.php">
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
                    <a href="clubs.php" class="active">
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
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); ?>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); ?>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($action === 'list'): ?>
                <!-- Clubs List -->
                <div class="page-header">
                    <h1><i class="fas fa-music"></i> Entertainment Clubs</h1>
                    <div class="page-actions">
                        <a href="clubs.php?action=add" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Club
                        </a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($clubs)): ?>
                            <div class="empty-state">
                                <i class="fas fa-music"></i>
                                <h3>No Entertainment Clubs Found</h3>
                                <p>Get started by creating your first entertainment club</p>
                                <a href="clubs.php?action=add" class="btn btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-plus"></i> Create First Club
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Club Name</th>
                                            <th>Department</th>
                                            <th>Members</th>
                                            <th>Faculty Advisor</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clubs as $club_item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($club_item['name']); ?></strong>
                                                    <div class="form-text" style="margin-top: 0.25rem;">
                                                        <?php echo htmlspecialchars(substr($club_item['description'], 0, 100)); ?>...
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($club_item['department']); ?></td>
                                                <td>
                                                    <span class="status-badge" style="background: var(--primary-blue); color: white;">
                                                        <?php echo $club_item['total_members']; ?> members
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($club_item['advisor_name'] ?? 'Not assigned'); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $club_item['status']; ?>">
                                                        <?php echo ucfirst($club_item['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="clubs.php?action=view&id=<?php echo $club_item['id']; ?>" class="btn btn-secondary btn-sm" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="clubs.php?action=edit&id=<?php echo $club_item['id']; ?>" class="btn btn-secondary btn-sm" title="Edit Club">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="clubs.php?action=delete&id=<?php echo $club_item['id']; ?>" 
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to delete this club?')"
                                                           title="Delete Club">
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
                </div>
                
            <?php elseif ($action === 'add' || $action === 'edit'): ?>
                <!-- Add/Edit Club Form -->
                <div class="page-header">
                    <h1><i class="fas <?php echo $action === 'add' ? 'fa-plus-circle' : 'fa-edit'; ?>"></i> <?php echo $action === 'add' ? 'Add New Club' : 'Edit Club'; ?></h1>
                    <div class="page-actions">
                        <a href="clubs.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Club Name <span style="color: var(--danger);">*</span></label>
                                    <input type="text" name="name" class="form-control" 
                                           value="<?php echo htmlspecialchars($club['name'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-control" 
                                           value="<?php echo htmlspecialchars($club['department'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Description <span style="color: var(--danger);">*</span></label>
                                <textarea name="description" class="form-control" rows="4" required><?php echo htmlspecialchars($club['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Established Date</label>
                                    <input type="date" name="established_date" class="form-control" 
                                           value="<?php echo htmlspecialchars($club['established_date'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" <?php echo ($club['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($club['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Meeting Schedule</label>
                                    <input type="text" name="meeting_schedule" class="form-control" 
                                           value="<?php echo htmlspecialchars($club['meeting_schedule'] ?? ''); ?>"
                                           placeholder="e.g., Every Monday, 4 PM">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Meeting Location</label>
                                    <input type="text" name="meeting_location" class="form-control" 
                                           value="<?php echo htmlspecialchars($club['meeting_location'] ?? ''); ?>"
                                           placeholder="e.g., Main Auditorium">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Faculty Advisor</label>
                                    <select name="faculty_advisor" class="form-select">
                                        <option value="">Select Faculty Advisor</option>
                                        <?php
                                        try {
                                            $stmt = $pdo->query("SELECT id, full_name FROM users WHERE role ILIKE '%faculty%' OR role ILIKE '%staff%' ORDER BY full_name");
                                            $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($faculty as $f) {
                                                $selected = ($club['faculty_advisor'] ?? '') == $f['id'] ? 'selected' : '';
                                                echo "<option value=\"{$f['id']}\" $selected>" . htmlspecialchars($f['full_name']) . "</option>";
                                            }
                                        } catch (PDOException $e) {
                                            // Silently handle error
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Advisor Contact</label>
                                    <input type="text" name="advisor_contact" class="form-control" 
                                           value="<?php echo htmlspecialchars($club['advisor_contact'] ?? ''); ?>"
                                           placeholder="e.g., advisor@college.ac.rw">
                                </div>
                            </div>
                            
                            <div class="form-group" style="margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Club
                                </button>
                                <a href="clubs.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                
            <?php elseif ($action === 'view' && $club): ?>
                <!-- View Club Details -->
                <div class="club-header">
                    <div class="club-info">
                        <h2><?php echo htmlspecialchars($club['name']); ?></h2>
                        <p><?php echo htmlspecialchars($club['description']); ?></p>
                        <div class="club-meta">
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo $club['members_count']; ?> Members</span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-building"></i>
                                <span><?php echo htmlspecialchars($club['department']); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Established: <?php echo date('F Y', strtotime($club['established_date'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-user-tie"></i>
                                <span>Advisor: <?php echo htmlspecialchars($club['advisor_name'] ?? 'Not assigned'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="page-header" style="margin-top: 0;">
                    <div>
                        <h1 style="font-size: 1.2rem; margin-bottom: 0.5rem;">Club Management</h1>
                        <p class="form-text">
                            <?php echo htmlspecialchars($club['meeting_schedule']); ?> at <?php echo htmlspecialchars($club['meeting_location']); ?>
                        </p>
                    </div>
                    <div class="page-actions">
                        <button onclick="showModal('addMemberModal')" class="btn btn-success">
                            <i class="fas fa-user-plus"></i> Add Member
                        </button>
                        <button onclick="showModal('addActivityModal')" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i> Add Activity
                        </button>
                        <button onclick="showModal('addResourceModal')" class="btn btn-secondary">
                            <i class="fas fa-file-upload"></i> Add Resource
                        </button>
                        <a href="clubs.php?action=edit&id=<?php echo $club_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Edit Club
                        </a>
                    </div>
                </div>
                
                <div class="club-sections">
                    <!-- Left Column: Members and Activities -->
                    <div class="left-column">
                        <!-- Members Section -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-users"></i> Club Members (<?php echo count($members); ?>)</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($members)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No members yet</p>
                                        <button onclick="showModal('addMemberModal')" class="btn btn-primary btn-sm">
                                            <i class="fas fa-user-plus"></i> Add Members
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Registration No.</th>
                                                    <th>Role</th>
                                                    <th>Join Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($members as $member): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($member['name']); ?></strong>
                                                            <div class="form-text">
                                                                <?php echo htmlspecialchars($member['program_name'] ?? ''); ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                        <td>
                                                            <span class="role-badge role-<?php echo $member['role']; ?>">
                                                                <?php echo ucfirst($member['role']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                                        <td>
                                                            <a href="clubs.php?action=remove_member&id=<?php echo $club_id; ?>&member_id=<?php echo $member['id']; ?>" 
                                                               class="btn btn-danger btn-sm"
                                                               onclick="return confirm('Remove <?php echo htmlspecialchars($member['name']); ?> from the club?')"
                                                               title="Remove Member">
                                                                <i class="fas fa-user-minus"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Activities Section -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-calendar-alt"></i> Club Activities</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($activities)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-calendar-alt"></i>
                                        <p>No activities scheduled</p>
                                        <button onclick="showModal('addActivityModal')" class="btn btn-primary btn-sm">
                                            <i class="fas fa-calendar-plus"></i> Schedule Activity
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Activity</th>
                                                    <th>Date & Time</th>
                                                    <th>Location</th>
                                                    <th>Type</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($activities as $activity): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                                            <div class="form-text">
                                                                <?php echo htmlspecialchars(substr($activity['description'], 0, 50)); ?>...
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M j, Y', strtotime($activity['activity_date'])); ?><br>
                                                            <small><?php echo date('g:i A', strtotime($activity['start_time'])); ?></small>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($activity['location']); ?></td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $activity['status']; ?>">
                                                                <?php echo ucfirst($activity['activity_type']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="clubs.php?action=delete_activity&id=<?php echo $club_id; ?>&activity_id=<?php echo $activity['id']; ?>" 
                                                               class="btn btn-danger btn-sm"
                                                               onclick="return confirm('Delete this activity?')"
                                                               title="Delete Activity">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
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
                    
                    <!-- Right Column: Resources and Quick Actions -->
                    <div class="right-column">
                        <!-- Resources Section -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-folder-open"></i> Club Resources</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($resources)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <p>No resources available</p>
                                        <button onclick="showModal('addResourceModal')" class="btn btn-primary btn-sm">
                                            <i class="fas fa-file-upload"></i> Upload Resource
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div style="display: grid; gap: 0.75rem;">
                                        <?php foreach ($resources as $resource): ?>
                                            <div style="padding: 0.75rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                                <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 0.5rem;">
                                                    <div style="flex: 1;">
                                                        <strong style="font-size: 0.85rem;"><?php echo htmlspecialchars($resource['title']); ?></strong>
                                                        <?php if ($resource['description']): ?>
                                                            <div class="form-text" style="margin-top: 0.25rem;">
                                                                <?php echo htmlspecialchars($resource['description']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($resource['file_name']): ?>
                                                            <div class="form-text" style="margin-top: 0.25rem;">
                                                                <i class="fas fa-file"></i> <?php echo strtoupper(pathinfo($resource['file_name'], PATHINFO_EXTENSION)); ?> • 
                                                                <?php echo round($resource['file_size'] / 1024, 1); ?> KB
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="action-buttons">
                                                        <?php if (!empty($resource['file_path'])): ?>
                                                            <a href="../<?php echo htmlspecialchars($resource['file_path']); ?>" 
                                                               class="btn btn-secondary btn-sm" target="_blank" download
                                                               title="Download">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="clubs.php?action=delete_resource&id=<?php echo $club_id; ?>&resource_id=<?php echo $resource['id']; ?>" 
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Delete this resource?')"
                                                           title="Delete Resource">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-info-circle"></i> Club Information</h3>
                            </div>
                            <div class="card-body">
                                <div style="display: grid; gap: 0.75rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="form-text">Total Members</span>
                                        <strong><?php echo $club['members_count']; ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="form-text">Department</span>
                                        <strong><?php echo htmlspecialchars($club['department']); ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="form-text">Meeting Schedule</span>
                                        <strong class="form-text"><?php echo htmlspecialchars($club['meeting_schedule']); ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="form-text">Meeting Location</span>
                                        <strong class="form-text"><?php echo htmlspecialchars($club['meeting_location']); ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="form-text">Status</span>
                                        <span class="status-badge status-<?php echo $club['status']; ?>">
                                            <?php echo ucfirst($club['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modals -->
                <!-- Add Member Modal -->
                <div id="addMemberModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3><i class="fas fa-user-plus"></i> Add Member to Club</h3>
                            <button class="modal-close" onclick="hideModal('addMemberModal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="clubs.php?action=add_member&id=<?php echo $club_id; ?>">
                                <div class="form-group">
                                    <label class="form-label">Select Student <span style="color: var(--danger);">*</span></label>
                                    <select name="user_id" class="form-select" required>
                                        <option value="">Choose a student...</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['full_name']); ?> 
                                                (<?php echo htmlspecialchars($student['reg_number']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select">
                                        <option value="member">Member</option>
                                        <option value="leader">Leader</option>
                                        <option value="deputy">Deputy Leader</option>
                                        <option value="treasurer">Treasurer</option>
                                        <option value="secretary">Secretary</option>
                                    </select>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" onclick="hideModal('addMemberModal')" class="btn btn-secondary">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Add Member</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Add Activity Modal -->
                <div id="addActivityModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3><i class="fas fa-calendar-plus"></i> Add Club Activity</h3>
                            <button class="modal-close" onclick="hideModal('addActivityModal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="clubs.php?action=add_activity&id=<?php echo $club_id; ?>">
                                <div class="form-group">
                                    <label class="form-label">Activity Title <span style="color: var(--danger);">*</span></label>
                                    <input type="text" name="title" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="3"></textarea>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Activity Type</label>
                                        <select name="activity_type" class="form-select">
                                            <option value="meeting">Regular Meeting</option>
                                            <option value="rehearsal">Rehearsal</option>
                                            <option value="performance">Performance</option>
                                            <option value="workshop">Workshop</option>
                                            <option value="competition">Competition</option>
                                            <option value="social">Social Event</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Budget (RWF)</label>
                                        <input type="number" name="budget" class="form-control" min="0" step="1000" value="0">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Date <span style="color: var(--danger);">*</span></label>
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
                                <div class="modal-footer">
                                    <button type="button" onclick="hideModal('addActivityModal')" class="btn btn-secondary">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Add Activity</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Add Resource Modal -->
                <div id="addResourceModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3><i class="fas fa-file-upload"></i> Add Club Resource</h3>
                            <button class="modal-close" onclick="hideModal('addResourceModal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="clubs.php?action=add_resource&id=<?php echo $club_id; ?>" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="form-label">Resource Type</label>
                                    <select name="resource_type" class="form-select">
                                        <option value="document">Document</option>
                                        <option value="music">Music File</option>
                                        <option value="video">Video</option>
                                        <option value="image">Image</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Title <span style="color: var(--danger);">*</span></label>
                                    <input type="text" name="title" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" name="quantity" class="form-control" min="1" value="1">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Value (RWF)</label>
                                        <input type="number" name="value" class="form-control" min="0" value="0">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Upload File</label>
                                    <input type="file" name="resource_file" class="form-control">
                                    <div class="form-text">
                                        Max file size: 10MB. Supported formats: PDF, DOC, MP3, MP4, JPG, PNG
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" onclick="hideModal('addResourceModal')" class="btn btn-secondary">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Upload Resource</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>
        </main>
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

        // Modal Functions
        function showModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function hideModal(modalId) {
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

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card, .club-header');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });
    </script>
</body>
</html>