<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle Committee Member Actions
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Get departments and programs for dropdowns
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT id, name, department_id FROM programs WHERE is_active = 1 ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    $programs = [];
    error_log("Error fetching departments/programs: " . $e->getMessage());
}

// Handle Add Committee Member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Handle photo upload
            $photo_url = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/committee/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    // Resize and optimize image
                    $image_info = getimagesize($_FILES['photo']['tmp_name']);
                    if ($image_info) {
                        $max_width = 400;
                        $max_height = 400;
                        
                        switch ($image_info[2]) {
                            case IMAGETYPE_JPEG:
                                $src = imagecreatefromjpeg($_FILES['photo']['tmp_name']);
                                break;
                            case IMAGETYPE_PNG:
                                $src = imagecreatefrompng($_FILES['photo']['tmp_name']);
                                break;
                            case IMAGETYPE_GIF:
                                $src = imagecreatefromgif($_FILES['photo']['tmp_name']);
                                break;
                            default:
                                $src = null;
                        }
                        
                        if ($src) {
                            list($width, $height) = $image_info;
                            $ratio = min($max_width / $width, $max_height / $height);
                            $new_width = $width * $ratio;
                            $new_height = $height * $ratio;
                            
                            $dst = imagecreatetruecolor($new_width, $new_height);
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                            
                            if ($file_extension == 'png') {
                                imagepng($dst, $upload_path, 8);
                            } else {
                                imagejpeg($dst, $upload_path, 85);
                            }
                            
                            imagedestroy($src);
                            imagedestroy($dst);
                            $photo_url = 'assets/uploads/committee/' . $file_name;
                        }
                    }
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO committee_members (
                    user_id, name, reg_number, role, role_order, 
                    department_id, program_id, academic_year, 
                    email, phone, bio, portfolio_description,
                    photo_url, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                !empty($_POST['user_id']) ? $_POST['user_id'] : null,
                $_POST['name'],
                $_POST['reg_number'] ?? null,
                $_POST['role'],
                $_POST['role_order'] ?? 0,
                !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                $_POST['academic_year'] ?? null,
                $_POST['email'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['bio'] ?? null,
                $_POST['portfolio_description'] ?? null,
                $photo_url,
                $_POST['status'] ?? 'active',
                $user_id
            ]);
            
            $message = "Committee member added successfully!";
            header("Location: committee.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error adding committee member: " . $e->getMessage();
            error_log("Committee member creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Committee Member
    elseif ($_POST['action'] === 'edit') {
        try {
            $member_id = $_POST['member_id'];
            
            // Handle photo upload
            $photo_url = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/committee/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    // Resize and optimize image
                    $image_info = getimagesize($_FILES['photo']['tmp_name']);
                    if ($image_info) {
                        $max_width = 400;
                        $max_height = 400;
                        
                        switch ($image_info[2]) {
                            case IMAGETYPE_JPEG:
                                $src = imagecreatefromjpeg($_FILES['photo']['tmp_name']);
                                break;
                            case IMAGETYPE_PNG:
                                $src = imagecreatefrompng($_FILES['photo']['tmp_name']);
                                break;
                            case IMAGETYPE_GIF:
                                $src = imagecreatefromgif($_FILES['photo']['tmp_name']);
                                break;
                            default:
                                $src = null;
                        }
                        
                        if ($src) {
                            list($width, $height) = $image_info;
                            $ratio = min($max_width / $width, $max_height / $height);
                            $new_width = $width * $ratio;
                            $new_height = $height * $ratio;
                            
                            $dst = imagecreatetruecolor($new_width, $new_height);
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                            
                            if ($file_extension == 'png') {
                                imagepng($dst, $upload_path, 8);
                            } else {
                                imagejpeg($dst, $upload_path, 85);
                            }
                            
                            imagedestroy($src);
                            imagedestroy($dst);
                            $photo_url = 'assets/uploads/committee/' . $file_name;
                            
                            // Delete old photo
                            $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id = ?");
                            $stmt->execute([$member_id]);
                            $old_member = $stmt->fetch(PDO::FETCH_ASSOC);
                            if (!empty($old_member['photo_url'])) {
                                $old_photo_path = '../' . $old_member['photo_url'];
                                if (file_exists($old_photo_path)) {
                                    unlink($old_photo_path);
                                }
                            }
                        }
                    }
                }
            }
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'user_id', 'name', 'reg_number', 'role', 'role_order',
                'department_id', 'program_id', 'academic_year',
                'email', 'phone', 'bio', 'portfolio_description', 'status'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field] !== '' ? $_POST[$field] : null;
                }
            }
            
            if ($photo_url) {
                $updateFields[] = "photo_url = ?";
                $params[] = $photo_url;
            }
            
            $params[] = $member_id;
            
            $sql = "UPDATE committee_members SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Committee member updated successfully!";
            header("Location: committee.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating committee member: " . $e->getMessage();
            error_log("Committee member update error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE committee_members SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " members activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE committee_members SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " members deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get photos to delete
                    $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $members_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($members_to_delete as $member) {
                        if (!empty($member['photo_url'])) {
                            $photo_path = '../' . $member['photo_url'];
                            if (file_exists($photo_path)) {
                                unlink($photo_path);
                            }
                        }
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " members deleted.";
                }
                header("Location: committee.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No members selected.";
        }
    }
}

// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE committee_members SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $member_id]);
        
        $message = "Member status updated successfully!";
        header("Location: committee.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling member status: " . $e->getMessage();
    }
}

// Handle Delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        // Get photo to delete
        $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($member['photo_url'])) {
            $photo_path = '../' . $member['photo_url'];
            if (file_exists($photo_path)) {
                unlink($photo_path);
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $message = "Committee member deleted successfully!";
        header("Location: committee.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting committee member: " . $e->getMessage();
    }
}

// Get member for editing via AJAX
if (isset($_GET['get_member']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT cm.*, 
                   d.name as department_name, 
                   p.name as program_name
            FROM committee_members cm
            LEFT JOIN departments d ON cm.department_id = d.id
            LEFT JOIN programs p ON cm.program_id = p.id
            WHERE cm.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($member);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get students for search via AJAX
if (isset($_GET['search_students'])) {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, reg_number, full_name, email, phone, department_id, program_id 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active'
            AND (full_name LIKE ? OR reg_number LIKE ? OR email LIKE ?)
            ORDER BY full_name ASC 
            LIMIT 15
        ");
        $search_term = "%$search%";
        $stmt->execute([$search_term, $search_term, $search_term]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($students);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get programs by department via AJAX
if (isset($_GET['get_programs']) && isset($_GET['department_id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$_GET['department_id']]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($programs);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';
$program_filter = $_GET['program'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(name LIKE ? OR email LIKE ? OR reg_number LIKE ? OR role LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($role_filter)) {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($department_filter)) {
    $where_conditions[] = "department_id = ?";
    $params[] = $department_filter;
}

if (!empty($program_filter)) {
    $where_conditions[] = "program_id = ?";
    $params[] = $program_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM committee_members WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_members = $stmt->fetchColumn();
    $total_pages = ceil($total_members / $limit);
} catch (PDOException $e) {
    $total_members = 0;
    $total_pages = 0;
}

// Get committee members with joins
try {
    $sql = "
        SELECT cm.*, 
               d.name as department_name, 
               p.name as program_name
        FROM committee_members cm
        LEFT JOIN departments d ON cm.department_id = d.id
        LEFT JOIN programs p ON cm.program_id = p.id
        WHERE $where_clause
        ORDER BY cm.role_order ASC, cm.name ASC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM committee_members GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_stats = [];
}

// Define available roles for dropdown
$available_roles = [
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
    'president_representative_board' => 'President - Rep Board',
    'vice_president_representative_board' => 'Vice President - Rep Board',
    'secretary_representative_board' => 'Secretary - Rep Board',
    'president_arbitration' => 'President - Arbitration',
    'vice_president_arbitration' => 'Vice President - Arbitration',
    'advisor_arbitration' => 'Advisor - Arbitration',
    'secretary_arbitration' => 'Secretary - Arbitration'
];

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Management - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #003d82;
            --primary-light: #4d8be6;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --border: #dee2e6;
            --shadow: 0 2px 4px rgba(0,0,0,0.1);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            color: #333;
            font-size: 14px;
        }

        /* Header */
        .header {
            background: white;
            box-shadow: var(--shadow);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
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
            height: 36px;
        }

        .brand-text h1 {
            font-size: 1.1rem;
            color: var(--primary);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.85rem;
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.75rem;
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 65px);
        }

        /* Sidebar */
        .sidebar {
            width: 240px;
            background: white;
            border-right: 1px solid var(--border);
            padding: 1rem 0;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 1.2rem;
            color: #555;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .menu-item a:hover,
        .menu-item a.active {
            background: #e3f2fd;
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.2rem;
            overflow-x: auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.2rem;
            flex-wrap: wrap;
            gap: 0.8rem;
        }

        .page-header h1 {
            font-size: 1.3rem;
            color: #333;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: #333;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Filters Bar */
        .filters-bar {
            background: white;
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.2rem;
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .filter-group label {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .filter-group select,
        .filter-group input {
            padding: 0.4rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.75rem;
        }

        .search-box {
            display: flex;
            gap: 0.4rem;
            margin-left: auto;
        }

        .search-box input {
            padding: 0.4rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            width: 200px;
            font-size: 0.75rem;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }

        .stat-card {
            background: white;
            padding: 0.8rem;
            border-radius: var(--border-radius);
            text-align: center;
            box-shadow: var(--shadow);
        }

        .stat-number {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            margin-top: 0.2rem;
        }

        /* Committee Grid */
        .committee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.2rem;
            margin-top: 0.8rem;
        }

        .member-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
        }

        .member-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .member-image {
            height: 180px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .member-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
        }

        .member-image .placeholder {
            font-size: 3rem;
            color: rgba(255,255,255,0.8);
        }

        .member-status {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: white;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .member-info {
            padding: 0.8rem;
        }

        .member-name {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .member-role {
            color: var(--primary);
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.6rem;
        }

        .member-details {
            font-size: 0.7rem;
            color: var(--gray);
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .member-details i {
            width: 14px;
            color: var(--primary);
            font-size: 0.7rem;
        }

        .member-bio {
            font-size: 0.7rem;
            color: #666;
            margin: 0.6rem 0;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .member-actions {
            display: flex;
            gap: 0.4rem;
            margin-top: 0.8rem;
            padding-top: 0.6rem;
            border-top: 1px solid var(--border);
        }

        /* Table View */
        .members-table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .members-table th,
        .members-table td {
            padding: 0.6rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .members-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .member-avatar-sm {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.7rem;
        }

        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        .view-toggle {
            display: flex;
            gap: 0.4rem;
            margin-bottom: 0.8rem;
        }

        .view-btn {
            padding: 0.4rem 0.8rem;
            background: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.75rem;
        }

        .view-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 1.2rem;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            font-size: 1.2rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--gray);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #555;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.4rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.75rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }

        .form-group input[type="file"] {
            padding: 0.2rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.2rem;
            padding-top: 0.8rem;
            border-top: 1px solid var(--border);
        }

        /* Student Search Results */
        .student-search-results {
            position: absolute;
            background: white;
            border: 1px solid var(--border);
            border-radius: 4px;
            max-height: 250px;
            overflow-y: auto;
            width: calc(100% - 2px);
            z-index: 1001;
            display: none;
            box-shadow: var(--shadow);
        }

        .student-result-item {
            padding: 0.6rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }

        .student-result-item:hover {
            background: var(--light);
        }

        .student-result-name {
            font-weight: 600;
            font-size: 0.8rem;
        }

        .student-result-reg {
            font-size: 0.65rem;
            color: var(--gray);
        }

        .search-container {
            position: relative;
        }

        /* Alert Messages */
        .alert {
            padding: 0.6rem 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.8rem;
            font-size: 0.75rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.4rem;
            margin-top: 1.2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            text-decoration: none;
            color: var(--primary);
            font-size: 0.7rem;
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Checkbox */
        .select-all {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .bulk-actions {
            display: flex;
            gap: 0.4rem;
            margin-bottom: 0.8rem;
        }

        .image-preview {
            margin-top: 0.4rem;
            max-width: 80px;
            border-radius: 6px;
            overflow: hidden;
        }

        .image-preview img {
            width: 100%;
            height: auto;
            border-radius: 6px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-box {
                margin-left: 0;
            }
            .sidebar {
                display: none;
            }
            .committee-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <img src="../assets/images/rp_logo.png" alt="Logo" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Admin Panel</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?>
                </div>
                <span style="font-size: 0.8rem;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php" class="active"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="content.php"><i class="fas fa-newspaper"></i> Content</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="page-header">
                <h1><i class="fas fa-user-tie"></i> Committee Management</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add Committee Member
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_members; ?></div>
                    <div class="stat-label">Total Members</div>
                </div>
                <?php foreach ($status_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['status']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form method="GET" action="" class="filters-bar">
                <div class="filter-group">
                    <label>Role:</label>
                    <select name="role" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <?php foreach ($available_roles as $key => $role_name): ?>
                            <option value="<?php echo $key; ?>" <?php echo $role_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status:</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Department:</label>
                    <select name="department" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Program:</label>
                    <select name="program" onchange="this.form.submit()">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['id']; ?>" <?php echo $program_filter == $prog['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prog['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $role_filter || $status_filter || $department_filter || $program_filter): ?>
                        <a href="committee.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- View Toggle -->
            <div class="view-toggle">
                <button class="view-btn active" onclick="toggleView('grid')" id="gridViewBtn">
                    <i class="fas fa-th-large"></i> Grid View
                </button>
                <button class="view-btn" onclick="toggleView('table')" id="tableViewBtn">
                    <i class="fas fa-table"></i> Table View
                </button>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk">
                <div class="bulk-actions">
                    <select name="bulk_action" id="bulk_action" style="font-size: 0.75rem; padding: 0.3rem;">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                </div>

                <!-- Grid View -->
                <div id="gridView" class="committee-grid">
                    <?php if (empty($committee_members)): ?>
                        <div style="grid-column: 1/-1; text-align: center; padding: 2rem; background: white; border-radius: var(--border-radius);">
                            <i class="fas fa-users" style="font-size: 2rem; color: var(--gray); margin-bottom: 0.5rem;"></i>
                            <h3 style="font-size: 1rem;">No committee members found</h3>
                            <p style="font-size: 0.75rem;">Click "Add Committee Member" to create one.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($committee_members as $member): ?>
                            <div class="member-card">
                                <div class="member-image">
                                    <?php if (!empty($member['photo_url']) && file_exists('../' . $member['photo_url'])): ?>
                                        <img src="../<?php echo htmlspecialchars($member['photo_url']); ?>" alt="<?php echo htmlspecialchars($member['name']); ?>">
                                    <?php else: ?>
                                        <div class="placeholder">
                                            <i class="fas fa-user-circle"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="member-status status-<?php echo $member['status']; ?>">
                                        <?php echo ucfirst($member['status']); ?>
                                    </div>
                                    <div style="position: absolute; top: 8px; left: 8px;">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox" style="width: 16px; height: 16px;">
                                    </div>
                                </div>
                                <div class="member-info">
                                    <h3 class="member-name"><?php echo htmlspecialchars($member['name']); ?></h3>
                                    <div class="member-role">
                                        <?php 
                                            $role_display = $available_roles[$member['role']] ?? str_replace('_', ' ', ucfirst($member['role']));
                                            echo htmlspecialchars($role_display);
                                        ?>
                                    </div>
                                    <?php if (!empty($member['reg_number'])): ?>
                                        <div class="member-details">
                                            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($member['reg_number']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($member['email'])): ?>
                                        <div class="member-details">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($member['email']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($member['department_name'])): ?>
                                        <div class="member-details">
                                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($member['department_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($member['bio'])): ?>
                                        <div class="member-bio"><?php echo htmlspecialchars(substr($member['bio'], 0, 80)); ?></div>
                                    <?php endif; ?>
                                    <div class="member-actions">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $member['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="?toggle_status=1&id=<?php echo $member['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle member status?')">
                                            <i class="fas fa-toggle-on"></i>
                                        </a>
                                        <a href="?delete=1&id=<?php echo $member['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this member?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Table View -->
                <div id="tableView" class="members-table-container" style="display: none;">
                    <table class="members-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Reg Number</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($committee_members as $member): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox"></td>
                                    <td>
                                        <?php if (!empty($member['photo_url']) && file_exists('../' . $member['photo_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($member['photo_url']); ?>" class="member-avatar-sm" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="member-avatar-sm" style="background: var(--primary);">
                                                <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($member['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($member['reg_number'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($available_roles[$member['role']] ?? str_replace('_', ' ', ucfirst($member['role']))); ?></td>
                                    <td><?php echo htmlspecialchars($member['department_name'] ?? '-'); ?></td>
                                    <td><span class="status-badge status-<?php echo $member['status']; ?>"><?php echo ucfirst($member['status']); ?></span></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $member['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?toggle_status=1&id=<?php echo $member['id']; ?>" class="btn btn-warning btn-sm">
                                            <i class="fas fa-toggle-on"></i>
                                        </a>
                                        <a href="?delete=1&id=<?php echo $member['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit Member Modal -->
    <div id="memberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Committee Member</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="memberForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="member_id" id="memberId" value="">
                <input type="hidden" name="user_id" id="user_id" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Search Student by Registration Number or Name</label>
                        <div class="search-container">
                            <input type="text" id="studentSearch" class="search-input" placeholder="Type reg number or name to search..." autocomplete="off">
                            <div id="studentSearchResults" class="student-search-results"></div>
                        </div>
                        <small>Search for existing students to auto-fill their information</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" id="name" required>
                    </div>
                    <div class="form-group">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" id="reg_number">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="email">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="phone">
                    </div>
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" id="role" required>
                            <option value="">Select Role</option>
                            <?php foreach ($available_roles as $key => $role_name): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($role_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Role Order</label>
                        <input type="number" name="role_order" id="role_order" value="0">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" id="department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" id="program_id">
                            <option value="">Select Program</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" id="academic_year" placeholder="e.g., 2024-2025">
                    </div>
                    <div class="form-group full-width">
                        <label>Bio</label>
                        <textarea name="bio" id="bio" rows="2" placeholder="Brief biography..."></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Portfolio Description</label>
                        <textarea name="portfolio_description" id="portfolio_description" rows="2" placeholder="Responsibilities..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Profile Photo</label>
                        <input type="file" name="photo" id="photo" accept="image/*" onchange="previewImage(this)">
                        <div id="imagePreview" class="image-preview" style="display: none;">
                            <img id="previewImg" src="" alt="Preview">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Member</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentView = 'grid';
        
        // View Toggle
        function toggleView(view) {
            currentView = view;
            const gridView = document.getElementById('gridView');
            const tableView = document.getElementById('tableView');
            const gridBtn = document.getElementById('gridViewBtn');
            const tableBtn = document.getElementById('tableViewBtn');
            
            if (view === 'grid') {
                gridView.style.display = 'grid';
                tableView.style.display = 'none';
                gridBtn.classList.add('active');
                tableBtn.classList.remove('active');
            } else {
                gridView.style.display = 'none';
                tableView.style.display = 'block';
                gridBtn.classList.remove('active');
                tableBtn.classList.add('active');
            }
        }
        
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Committee Member';
            document.getElementById('formAction').value = 'add';
            document.getElementById('memberId').value = '';
            document.getElementById('memberForm').reset();
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('studentSearch').value = '';
            document.getElementById('user_id').value = '';
            document.getElementById('memberModal').classList.add('active');
        }
        
        function openEditModal(memberId) {
            fetch(`committee.php?get_member=1&id=${memberId}`)
                .then(response => response.json())
                .then(member => {
                    if (member.error) {
                        alert('Error loading member data');
                        return;
                    }
                    
                    document.getElementById('modalTitle').textContent = 'Edit Committee Member';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('memberId').value = member.id;
                    document.getElementById('name').value = member.name || '';
                    document.getElementById('reg_number').value = member.reg_number || '';
                    document.getElementById('email').value = member.email || '';
                    document.getElementById('phone').value = member.phone || '';
                    document.getElementById('role').value = member.role;
                    document.getElementById('role_order').value = member.role_order || 0;
                    document.getElementById('department_id').value = member.department_id || '';
                    document.getElementById('academic_year').value = member.academic_year || '';
                    document.getElementById('bio').value = member.bio || '';
                    document.getElementById('portfolio_description').value = member.portfolio_description || '';
                    document.getElementById('status').value = member.status;
                    document.getElementById('user_id').value = member.user_id || '';
                    
                    if (member.department_id) {
                        loadPrograms(member.department_id, member.program_id);
                    }
                    
                    if (member.photo_url && member.photo_url !== 'null') {
                        const preview = document.getElementById('imagePreview');
                        const previewImg = document.getElementById('previewImg');
                        previewImg.src = '../' + member.photo_url;
                        preview.style.display = 'block';
                    } else {
                        document.getElementById('imagePreview').style.display = 'none';
                    }
                    
                    document.getElementById('memberModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading member data');
                });
        }
        
        function closeModal() {
            document.getElementById('memberModal').classList.remove('active');
        }
        
        // Load programs based on department
        function loadPrograms(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`committee.php?get_programs=1&department_id=${departmentId}`)
                .then(response => response.json())
                .then(programs => {
                    let options = '<option value="">Select Program</option>';
                    if (!programs.error && programs.length > 0) {
                        programs.forEach(program => {
                            options += `<option value="${program.id}" ${selectedProgramId == program.id ? 'selected' : ''}>${escapeHtml(program.name)}</option>`;
                        });
                    }
                    document.getElementById('program_id').innerHTML = options;
                });
        }
        
        document.getElementById('department_id').addEventListener('change', function() {
            loadPrograms(this.value);
        });
        
        // Image preview
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const previewImg = document.getElementById('previewImg');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }
        
        // Student search functionality
        let searchTimeout;
        const studentSearch = document.getElementById('studentSearch');
        const searchResults = document.getElementById('studentSearchResults');
        
        studentSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                searchResults.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch(`committee.php?search_students=1&search=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(students => {
                        if (students.error || students.length === 0) {
                            searchResults.innerHTML = '<div class="student-result-item">No students found</div>';
                            searchResults.style.display = 'block';
                            return;
                        }
                        
                        let html = '';
                        students.forEach(student => {
                            html += `
                                <div class="student-result-item" onclick="selectStudent(${student.id}, '${escapeHtml(student.full_name)}', '${escapeHtml(student.reg_number || '')}', '${escapeHtml(student.email || '')}', '${escapeHtml(student.phone || '')}', ${student.department_id || 'null'}, ${student.program_id || 'null'})">
                                    <div class="student-result-name">${escapeHtml(student.full_name)}</div>
                                    <div class="student-result-reg">${escapeHtml(student.reg_number || 'No reg number')} | ${escapeHtml(student.email || 'No email')}</div>
                                </div>
                            `;
                        });
                        searchResults.innerHTML = html;
                        searchResults.style.display = 'block';
                    });
            }, 300);
        });
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function selectStudent(userId, fullName, regNumber, email, phone, departmentId, programId) {
            document.getElementById('name').value = fullName;
            document.getElementById('reg_number').value = regNumber;
            document.getElementById('email').value = email;
            document.getElementById('phone').value = phone;
            document.getElementById('user_id').value = userId;
            
            if (departmentId && departmentId !== 'null') {
                document.getElementById('department_id').value = departmentId;
                loadPrograms(departmentId, programId !== 'null' ? programId : null);
            }
            
            studentSearch.value = fullName;
            searchResults.style.display = 'none';
        }
        
        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            if (studentSearch && !studentSearch.contains(event.target) && searchResults && !searchResults.contains(event.target)) {
                if (searchResults) searchResults.style.display = 'none';
            }
        });
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.member-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.member-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one member');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} member(s)?`);
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('memberModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>