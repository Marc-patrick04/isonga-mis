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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_club':
                $name = $_POST['name'];
                $description = $_POST['description'];
                $category = $_POST['category'];
                $department = $_POST['department'] ?? '';
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
                    $file_name = 'club_' . uniqid() . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                        $logo_url = 'assets/uploads/clubs/' . $file_name;
                    }
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO clubs (name, description, category, department, established_date, 
                                         meeting_schedule, meeting_location, faculty_advisor, advisor_contact, 
                                         logo_url, created_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([
                        $name, $description, $category, $department, $established_date,
                        $meeting_schedule, $meeting_location, $faculty_advisor, $advisor_contact,
                        $logo_url, $user_id
                    ]);
                    
                    $club_id = $pdo->lastInsertId();
                    $_SESSION['success_message'] = "Club created successfully!";
                    
                    // Redirect to club details page
                    header("Location: clubs.php?view=details&id=" . $club_id);
                    exit();
                    
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating club: " . $e->getMessage();
                }
                break;
                
            case 'update_club':
                $club_id = $_POST['club_id'];
                $name = $_POST['name'];
                $description = $_POST['description'];
                $category = $_POST['category'];
                $department = $_POST['department'] ?? '';
                $established_date = $_POST['established_date'];
                $meeting_schedule = $_POST['meeting_schedule'];
                $meeting_location = $_POST['meeting_location'];
                $faculty_advisor = $_POST['faculty_advisor'];
                $advisor_contact = $_POST['advisor_contact'];
                $status = $_POST['status'];
                
                // Handle logo upload
                $logo_url = $_POST['current_logo'] ?? null;
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/clubs/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $file_name = 'club_' . $club_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                        $logo_url = 'assets/uploads/clubs/' . $file_name;
                        
                        // Delete old logo if exists
                        if (!empty($_POST['current_logo']) && file_exists('../' . $_POST['current_logo'])) {
                            unlink('../' . $_POST['current_logo']);
                        }
                    }
                }
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE clubs 
                        SET name = ?, description = ?, category = ?, department = ?, established_date = ?,
                            meeting_schedule = ?, meeting_location = ?, faculty_advisor = ?, advisor_contact = ?,
                            logo_url = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND created_by = ?
                    ");
                    $stmt->execute([
                        $name, $description, $category, $department, $established_date,
                        $meeting_schedule, $meeting_location, $faculty_advisor, $advisor_contact,
                        $logo_url, $status, $club_id, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Club updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating club: " . $e->getMessage();
                }
                break;
                
            case 'add_member':
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
                    // Check if user exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ?");
                    $stmt->execute([$reg_number]);
                    $user_exists = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user_id_member = $user_exists ? $user_exists['id'] : null;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO club_members (club_id, user_id, reg_number, name, email, phone, 
                                                department_id, program_id, academic_year, role, join_date, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active')
                    ");
                    $stmt->execute([
                        $club_id, $user_id_member, $reg_number, $name, $email, $phone,
                        $department_id, $program_id, $academic_year, $role
                    ]);
                    
                    // Update members count in clubs table
                    $stmt = $pdo->prepare("
                        UPDATE clubs 
                        SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'active') 
                        WHERE id = ?
                    ");
                    $stmt->execute([$club_id, $club_id]);
                    
                    $_SESSION['success_message'] = "Member added successfully!";
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) { // Duplicate entry
                        $_SESSION['error_message'] = "Member with this registration number already exists in this club.";
                    } else {
                        $_SESSION['error_message'] = "Error adding member: " . $e->getMessage();
                    }
                }
                break;
                
            case 'update_member':
                $member_id = $_POST['member_id'];
                $club_id = $_POST['club_id'];
                $reg_number = $_POST['reg_number'];
                $name = $_POST['name'];
                $email = $_POST['email'];
                $phone = $_POST['phone'];
                $department_id = $_POST['department_id'];
                $program_id = $_POST['program_id'];
                $academic_year = $_POST['academic_year'];
                $role = $_POST['role'];
                $status = $_POST['status'];
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE club_members 
                        SET reg_number = ?, name = ?, email = ?, phone = ?, department_id = ?, 
                            program_id = ?, academic_year = ?, role = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND club_id = ?
                    ");
                    $stmt->execute([
                        $reg_number, $name, $email, $phone, $department_id,
                        $program_id, $academic_year, $role, $status, $member_id, $club_id
                    ]);
                    
                    $_SESSION['success_message'] = "Member updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating member: " . $e->getMessage();
                }
                break;
                
            case 'create_activity':
                $club_id = $_POST['club_id'];
                $title = $_POST['title'];
                $description = $_POST['description'];
                $activity_type = $_POST['activity_type'];
                $activity_date = $_POST['activity_date'];
                $start_time = $_POST['start_time'];
                $end_time = $_POST['end_time'];
                $location = $_POST['location'];
                $budget = $_POST['budget'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO club_activities (club_id, title, description, activity_type, activity_date,
                                                   start_time, end_time, location, budget, created_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                    ");
                    $stmt->execute([
                        $club_id, $title, $description, $activity_type, $activity_date,
                        $start_time, $end_time, $location, $budget, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Activity created successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating activity: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: clubs.php" . (isset($_POST['club_id']) ? "?view=details&id=" . $_POST['club_id'] : ""));
        exit();
    }
}

// Get view and action parameters
$view = $_GET['view'] ?? 'list';
$club_id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

// Get gender-related clubs
try {
    $query = "
        SELECT c.*, 
               COUNT(cm.id) as actual_members_count,
               u.full_name as creator_name
        FROM clubs c
        LEFT JOIN club_members cm ON c.id = cm.club_id AND cm.status = 'active'
        LEFT JOIN users u ON c.created_by = u.id
        WHERE c.category IN ('cultural', 'other') 
        AND c.created_by = ?
    ";
    
    $params = [$user_id];
    
    if ($club_id) {
        $query .= " AND c.id = ?";
        $params[] = $club_id;
    }
    
    $query .= " GROUP BY c.id ORDER BY c.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    if ($club_id) {
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        $clubs = $club ? [$club] : [];
    } else {
        $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Clubs query error: " . $e->getMessage());
    $clubs = [];
    $club = null;
}

// Get club members if viewing club details
$club_members = [];
$club_activities = [];
if ($club_id && $club) {
    try {
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
                END,
                cm.name
        ");
        $stmt->execute([$club_id]);
        $club_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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

// Get departments and programs for forms
try {
    $stmt = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Departments/Programs error: " . $e->getMessage());
    $departments = [];
    $programs = [];
}

// Get club statistics
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_clubs,
               SUM(members_count) as total_members,
               SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_clubs
        FROM clubs 
        WHERE category IN ('cultural', 'other') AND created_by = ?
    ");
    $stmt->execute([$user_id]);
    $club_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Club statistics error: " . $e->getMessage());
    $club_stats = ['total_clubs' => 0, 'total_members' => 0, 'active_clubs' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gender Clubs Management - Minister of Gender & Protocol</title>
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
        }

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
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
            border-left: 3px solid var(--primary-purple);
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
            background: var(--light-purple);
            color: var(--primary-purple);
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

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
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
            padding: 0.5rem 0.75rem;
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
            min-height: 100px;
        }

        .form-file {
            padding: 0.5rem 0;
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

        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #dbeafe;
            color: var(--primary-purple);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Club Grid */
        .club-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .club-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .club-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .club-logo {
            width: 100%;
            height: 150px;
            background: var(--light-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-purple);
            font-size: 2rem;
        }

        .club-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .club-content {
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .club-members {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Alert Messages */
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
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
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
            
            .club-grid {
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
            
            .action-buttons {
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
                    <h1>Isonga - Minister of Gender & Protocol</h1>
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
            <a href="tickets.php" >
                <i class="fas fa-ticket-alt"></i>
                <span>Gender Issues</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="protocol.php">
                <i class="fas fa-handshake"></i>
                <span>Protocol & Visitors</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="clubs.php" class="active">
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
        
        <!-- Added Action Funding -->
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

            <?php if ($view === 'list' || $view === 'create'): ?>
                <!-- Clubs List View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Gender Clubs Management</h1>
                        <p>Manage gender-focused clubs and organizations</p>
                    </div>
                    <div class="page-actions">
                        <a href="clubs.php?view=create" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New Club
                        </a>
                    </div>
                </div>

                <!-- Statistics Grid -->
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
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club_stats['total_members']; ?></div>
                            <div class="stat-label">Total Members</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($club_activities); ?></div>
                            <div class="stat-label">Recent Activities</div>
                        </div>
                    </div>
                </div>

                <?php if ($view === 'create'): ?>
                    <!-- Create Club Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Create New Gender Club</h3>
                            <a href="clubs.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Clubs
                            </a>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data" class="form-grid">
                                <input type="hidden" name="action" value="create_club">
                                
                                <div class="form-group">
                                    <label class="form-label">Club Name *</label>
                                    <input type="text" name="name" class="form-input" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Category *</label>
                                    <select name="category" class="form-select" required>
                                        <option value="cultural">Cultural</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-input">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Established Date</label>
                                    <input type="date" name="established_date" class="form-input">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Meeting Schedule</label>
                                    <input type="text" name="meeting_schedule" class="form-input" placeholder="e.g., Every Monday 2 PM">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Meeting Location</label>
                                    <input type="text" name="meeting_location" class="form-input" placeholder="e.g., Main Hall">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Faculty Advisor</label>
                                    <input type="text" name="faculty_advisor" class="form-input">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Advisor Contact</label>
                                    <input type="text" name="advisor_contact" class="form-input" placeholder="Email or Phone">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Club Logo</label>
                                    <input type="file" name="logo" class="form-file" accept="image/*">
                                </div>
                                
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label class="form-label">Description *</label>
                                    <textarea name="description" class="form-textarea" required placeholder="Describe the club's purpose, activities, and goals..."></textarea>
                                </div>
                                
                                <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Create Club
                                    </button>
                                    <a href="clubs.php" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Clubs Grid -->
                    <?php if (empty($clubs)): ?>
                        <div class="card">
                            <div class="card-body" style="text-align: center; padding: 3rem;">
                                <i class="fas fa-users" style="font-size: 4rem; color: var(--dark-gray); margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3 style="color: var(--dark-gray); margin-bottom: 1rem;">No Gender Clubs Found</h3>
                                <p style="color: var(--dark-gray); margin-bottom: 2rem;">Create your first gender-focused club to get started.</p>
                                <a href="clubs.php?view=create" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Create Your First Club
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="club-grid">
                            <?php foreach ($clubs as $club): ?>
                                <div class="club-card">
                                    <div class="club-logo">
                                        <?php if (!empty($club['logo_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($club['logo_url']); ?>" alt="<?php echo htmlspecialchars($club['name']); ?>">
                                        <?php else: ?>
                                            <i class="fas fa-users"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="club-content">
                                        <h3 class="club-title"><?php echo htmlspecialchars($club['name']); ?></h3>
                                        <p class="club-description"><?php echo htmlspecialchars($club['description']); ?></p>
                                        <div class="club-meta">
                                            <span class="status-badge status-<?php echo $club['status']; ?>">
                                                <?php echo ucfirst($club['status']); ?>
                                            </span>
                                            <div class="club-members">
                                                <i class="fas fa-user-friends"></i>
                                                <?php echo $club['actual_members_count']; ?> members
                                            </div>
                                        </div>
                                        <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                            <a href="clubs.php?view=details&id=<?php echo $club['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="clubs.php?view=edit&id=<?php echo $club['id']; ?>" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ($view === 'details' && $club): ?>
                <!-- Club Details View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1><?php echo htmlspecialchars($club['name']); ?></h1>
                        <p><?php echo htmlspecialchars($club['description']); ?></p>
                    </div>
                    <div class="page-actions">
                        <a href="clubs.php?view=edit&id=<?php echo $club['id']; ?>" class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Edit Club
                        </a>
                        <a href="clubs.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Clubs
                        </a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club['actual_members_count']; ?></div>
                            <div class="stat-label">Total Members</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($club_activities); ?></div>
                            <div class="stat-label">Activities</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club['meeting_schedule'] ?: 'Not Set'; ?></div>
                            <div class="stat-label">Meeting Schedule</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $club['meeting_location'] ?: 'Not Set'; ?></div>
                            <div class="stat-label">Meeting Location</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Club Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Club Name</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($club['name']); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo ucfirst($club['category']); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($club['department'] ?: 'Not specified'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Established Date</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo $club['established_date'] ? date('F j, Y', strtotime($club['established_date'])) : 'Not specified'; ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Faculty Advisor</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($club['faculty_advisor'] ?: 'Not assigned'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Advisor Contact</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($club['advisor_contact'] ?: 'Not available'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <div class="form-input" style="background: var(--light-gray);">
                                    <span class="status-badge status-<?php echo $club['status']; ?>">
                                        <?php echo ucfirst($club['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Meeting Schedule</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($club['meeting_schedule'] ?: 'Not scheduled'); ?></div>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Meeting Location</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($club['meeting_location'] ?: 'Not specified'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Members Management -->
                <div class="card">
                    <div class="card-header">
                        <h3>Club Members (<?php echo count($club_members); ?>)</h3>
                        <button class="btn btn-primary btn-sm" onclick="openModal('addMemberModal')">
                            <i class="fas fa-user-plus"></i> Add Member
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($club_members)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No members added yet. Add the first member to get started.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Registration Number</th>
                                            <th>Department</th>
                                            <th>Program</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($club_members as $member): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($member['name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars($member['email']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                <td><?php echo htmlspecialchars($member['department_name'] ?: 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($member['program_name'] ?: 'N/A'); ?></td>
                                                <td>
                                                    <span class="role-badge">
                                                        <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $member['status']; ?>">
                                                        <?php echo ucfirst($member['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-secondary btn-sm" onclick="editMember(<?php echo $member['id']; ?>)">
                                                            <i class="fas fa-edit"></i> Edit
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

                <!-- Activities -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Activities</h3>
                        <button class="btn btn-primary btn-sm" onclick="openModal('addActivityModal')">
                            <i class="fas fa-plus"></i> Add Activity
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($club_activities)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-calendar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No activities scheduled yet. Plan your first activity.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Activity</th>
                                            <th>Type</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($club_activities as $activity): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($activity['title']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo htmlspecialchars(substr($activity['description'], 0, 50)); ?>...</div>
                                                </td>
                                                <td><?php echo ucfirst($activity['activity_type']); ?></td>
                                                <td>
                                                    <div style="font-size: 0.8rem;"><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                        <?php echo date('g:i A', strtotime($activity['start_time'])); ?> - 
                                                        <?php echo date('g:i A', strtotime($activity['end_time'])); ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($activity['location']); ?></td>
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

            <?php elseif ($view === 'edit' && $club): ?>
                <!-- Edit Club View -->
                <div class="card">
                    <div class="card-header">
                        <h3>Edit Club: <?php echo htmlspecialchars($club['name']); ?></h3>
                        <a href="clubs.php?view=details&id=<?php echo $club['id']; ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Club
                        </a>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" class="form-grid">
                            <input type="hidden" name="action" value="update_club">
                            <input type="hidden" name="club_id" value="<?php echo $club['id']; ?>">
                            <input type="hidden" name="current_logo" value="<?php echo htmlspecialchars($club['logo_url'] ?? ''); ?>">
                            
                            <div class="form-group">
                                <label class="form-label">Club Name *</label>
                                <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($club['name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category" class="form-select" required>
                                    <option value="cultural" <?php echo $club['category'] === 'cultural' ? 'selected' : ''; ?>>Cultural</option>
                                    <option value="other" <?php echo $club['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <input type="text" name="department" class="form-input" value="<?php echo htmlspecialchars($club['department'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Established Date</label>
                                <input type="date" name="established_date" class="form-input" value="<?php echo htmlspecialchars($club['established_date'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Meeting Schedule</label>
                                <input type="text" name="meeting_schedule" class="form-input" value="<?php echo htmlspecialchars($club['meeting_schedule'] ?? ''); ?>" placeholder="e.g., Every Monday 2 PM">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Meeting Location</label>
                                <input type="text" name="meeting_location" class="form-input" value="<?php echo htmlspecialchars($club['meeting_location'] ?? ''); ?>" placeholder="e.g., Main Hall">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Faculty Advisor</label>
                                <input type="text" name="faculty_advisor" class="form-input" value="<?php echo htmlspecialchars($club['faculty_advisor'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Advisor Contact</label>
                                <input type="text" name="advisor_contact" class="form-input" value="<?php echo htmlspecialchars($club['advisor_contact'] ?? ''); ?>" placeholder="Email or Phone">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" required>
                                    <option value="active" <?php echo $club['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $club['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $club['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Club Logo</label>
                                <input type="file" name="logo" class="form-file" accept="image/*">
                                <?php if (!empty($club['logo_url'])): ?>
                                    <div style="margin-top: 0.5rem;">
                                        <img src="../<?php echo htmlspecialchars($club['logo_url']); ?>" alt="Current Logo" style="max-width: 100px; max-height: 100px; border-radius: var(--border-radius);">
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Description *</label>
                                <textarea name="description" class="form-textarea" required><?php echo htmlspecialchars($club['description']); ?></textarea>
                            </div>
                            
                            <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Club
                                </button>
                                <a href="clubs.php?view=details&id=<?php echo $club['id']; ?>" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Club Member</h3>
                <button class="modal-close" onclick="closeModal('addMemberModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="club_id" value="<?php echo $club_id; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Registration Number *</label>
                        <input type="text" name="reg_number" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-input">
                    </div>
                    
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
                    
                    <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Add Member
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addMemberModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div id="addActivityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Club Activity</h3>
                <button class="modal-close" onclick="closeModal('addActivityModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="create_activity">
                    <input type="hidden" name="club_id" value="<?php echo $club_id; ?>">
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Activity Title *</label>
                        <input type="text" name="title" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Activity Type</label>
                        <select name="activity_type" class="form-select">
                            <option value="meeting">Meeting</option>
                            <option value="workshop">Workshop</option>
                            <option value="competition">Competition</option>
                            <option value="social_event">Social Event</option>
                            <option value="training">Training</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Activity Date *</label>
                        <input type="date" name="activity_date" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Time *</label>
                        <input type="time" name="start_time" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Time *</label>
                        <input type="time" name="end_time" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Budget (RWF)</label>
                        <input type="number" name="budget" class="form-input" min="0" step="0.01">
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea"></textarea>
                    </div>
                    
                    <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i> Create Activity
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addActivityModal')">
                            Cancel
                        </button>
                    </div>
                </form>
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

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function editMember(memberId) {
            // This would typically load member data via AJAX and open an edit modal
            alert('Edit member functionality would load member data for editing. Member ID: ' + memberId);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>