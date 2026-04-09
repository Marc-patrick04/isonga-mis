<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_admin = [];
}

// Handle Association Actions
$message = '';
$error = '';

// Get departments for dropdowns
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    error_log("Error fetching departments: " . $e->getMessage());
}

// Get programs for dropdowns
try {
    $stmt = $pdo->query("SELECT id, name, department_id FROM programs WHERE is_active = true ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
}

// Handle Add Association
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Handle logo upload
            $logo_url = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/associations/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                        $logo_url = 'assets/uploads/associations/' . $file_name;
                    }
                }
            }
            
            // Prepare social links JSON
            $social_links = [];
            if (!empty($_POST['facebook'])) $social_links['facebook'] = $_POST['facebook'];
            if (!empty($_POST['twitter'])) $social_links['twitter'] = $_POST['twitter'];
            if (!empty($_POST['instagram'])) $social_links['instagram'] = $_POST['instagram'];
            if (!empty($_POST['linkedin'])) $social_links['linkedin'] = $_POST['linkedin'];
            if (!empty($_POST['website'])) $social_links['website'] = $_POST['website'];
            
            $social_links_json = !empty($social_links) ? json_encode($social_links) : null;
            
            // Check if name already exists
            $stmt = $pdo->prepare("SELECT id FROM associations WHERE name = ?");
            $stmt->execute([$_POST['name']]);
            if ($stmt->fetch()) {
                throw new Exception("Association name '{$_POST['name']}' already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO associations (
                    name, type, description, established_date, meeting_schedule, meeting_location,
                    faculty_advisor, advisor_contact, members_count, status, logo_url,
                    contact_person, contact_email, contact_phone, social_links,
                    performance_notes, goals, achievements, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                $_POST['name'],
                $_POST['type'],
                $_POST['description'] ?? null,
                !empty($_POST['established_date']) ? $_POST['established_date'] : null,
                $_POST['meeting_schedule'] ?? null,
                $_POST['meeting_location'] ?? null,
                $_POST['faculty_advisor'] ?? null,
                $_POST['advisor_contact'] ?? null,
                $_POST['members_count'] ?? 0,
                $_POST['status'] ?? 'active',
                $logo_url,
                $_POST['contact_person'] ?? null,
                $_POST['contact_email'] ?? null,
                $_POST['contact_phone'] ?? null,
                $social_links_json,
                $_POST['performance_notes'] ?? null,
                $_POST['goals'] ?? null,
                $_POST['achievements'] ?? null,
                $user_id
            ]);
            
            $message = "Association added successfully!";
            header("Location: associations.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding association: " . $e->getMessage();
            error_log("Association creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Association
    elseif ($_POST['action'] === 'edit') {
        try {
            $association_id = $_POST['association_id'];
            $logo_url = null;
            
            // Handle logo upload
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/associations/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                        $logo_url = 'assets/uploads/associations/' . $file_name;
                        
                        // Delete old logo
                        $stmt = $pdo->prepare("SELECT logo_url FROM associations WHERE id = ?");
                        $stmt->execute([$association_id]);
                        $old_assoc = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($old_assoc['logo_url'])) {
                            $old_logo_path = '../' . $old_assoc['logo_url'];
                            if (file_exists($old_logo_path)) {
                                unlink($old_logo_path);
                            }
                        }
                    }
                }
            }
            
            // Prepare social links JSON
            $social_links = [];
            if (!empty($_POST['facebook'])) $social_links['facebook'] = $_POST['facebook'];
            if (!empty($_POST['twitter'])) $social_links['twitter'] = $_POST['twitter'];
            if (!empty($_POST['instagram'])) $social_links['instagram'] = $_POST['instagram'];
            if (!empty($_POST['linkedin'])) $social_links['linkedin'] = $_POST['linkedin'];
            if (!empty($_POST['website'])) $social_links['website'] = $_POST['website'];
            
            $social_links_json = !empty($social_links) ? json_encode($social_links) : null;
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'name', 'type', 'description', 'established_date', 'meeting_schedule',
                'meeting_location', 'faculty_advisor', 'advisor_contact', 'members_count',
                'status', 'contact_person', 'contact_email', 'contact_phone',
                'performance_notes', 'goals', 'achievements'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $value = $_POST[$field] !== '' ? $_POST[$field] : null;
                    $params[] = $value;
                }
            }
            
            if ($logo_url) {
                $updateFields[] = "logo_url = ?";
                $params[] = $logo_url;
            }
            
            if ($social_links_json) {
                $updateFields[] = "social_links = ?";
                $params[] = $social_links_json;
            }
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $association_id;
            
            $sql = "UPDATE associations SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Association updated successfully!";
            header("Location: associations.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating association: " . $e->getMessage();
            error_log("Association update error: " . $e->getMessage());
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
                    $stmt = $pdo->prepare("UPDATE associations SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " associations activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE associations SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " associations deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get logos to delete
                    $stmt = $pdo->prepare("SELECT logo_url FROM associations WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $assocs_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($assocs_to_delete as $assoc) {
                        if (!empty($assoc['logo_url'])) {
                            $logo_path = '../' . $assoc['logo_url'];
                            if (file_exists($logo_path)) {
                                unlink($logo_path);
                            }
                        }
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM associations WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " associations deleted.";
                }
                header("Location: associations.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No associations selected.";
        }
    }
}

// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $assoc_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM associations WHERE id = ?");
        $stmt->execute([$assoc_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE associations SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $assoc_id]);
        
        $message = "Association status updated successfully!";
        header("Location: associations.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling association status: " . $e->getMessage();
    }
}

// Handle Delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $assoc_id = $_GET['id'];
    try {
        // Get logo to delete
        $stmt = $pdo->prepare("SELECT logo_url FROM associations WHERE id = ?");
        $stmt->execute([$assoc_id]);
        $assoc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($assoc['logo_url'])) {
            $logo_path = '../' . $assoc['logo_url'];
            if (file_exists($logo_path)) {
                unlink($logo_path);
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM associations WHERE id = ?");
        $stmt->execute([$assoc_id]);
        $message = "Association deleted successfully!";
        header("Location: associations.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting association: " . $e->getMessage();
    }
}

// Get association for editing via AJAX
if (isset($_GET['get_association']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM associations WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $association = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode social links
        if (!empty($association['social_links'])) {
            $social = json_decode($association['social_links'], true);
            if (is_array($social)) {
                $association = array_merge($association, $social);
            }
        }
        
        echo json_encode($association);
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
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(name ILIKE ? OR description ILIKE ? OR contact_person ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($type_filter)) {
    $where_conditions[] = "type = ?";
    $params[] = $type_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM associations WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_associations = $stmt->fetchColumn();
    $total_pages = ceil($total_associations / $limit);
} catch (PDOException $e) {
    $total_associations = 0;
    $total_pages = 0;
}

// Get associations with pagination
try {
    $sql = "
        SELECT a.*, 
               (SELECT COUNT(*) FROM association_members WHERE association_id = a.id) as actual_members_count
        FROM associations a
        WHERE $where_clause
        ORDER BY a.name ASC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $associations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $associations = [];
    error_log("Association fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT type, COUNT(*) as count FROM associations GROUP BY type");
    $type_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM associations GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $type_stats = [];
    $status_stats = [];
}

// Association types
$association_types = [
    'religious' => 'Religious',
    'cultural' => 'Cultural',
    'academic' => 'Academic',
    'sports' => 'Sports',
    'social' => 'Social',
    'other' => 'Other'
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Associations Management - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Light Mode (Default) */
        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --pink: #ec489a;
            
            /* Light Mode Colors */
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        /* Dark Mode */
        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --sidebar-bg: #1f2937;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Header */
        .header {
            background: var(--header-bg);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text p {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border-color);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
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
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem 0;
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
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
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover {
            background: var(--bg-primary);
            border-left-color: var(--primary);
        }

        .menu-item a.active {
            background: var(--bg-primary);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* Filters Bar */
        .filters-bar {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.8rem;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-box input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            width: 250px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Bulk Actions */
        .bulk-actions-bar {
            background: var(--card-bg);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .bulk-actions-bar select {
            padding: 0.4rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.75rem;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Associations Grid */
        .associations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .association-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
        }

        .association-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .association-header {
            height: 120px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .association-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
        }

        .association-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .association-logo .placeholder {
            font-size: 2.5rem;
            color: var(--primary);
        }

        .association-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--card-bg);
            color: var(--text-primary);
        }

        .association-status.active {
            background: #d4edda;
            color: #155724;
        }

        .association-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .association-status.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .association-status.inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .association-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 4px;
        }

        .association-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .association-info {
            padding: 1rem;
        }

        .association-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .association-type {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            background: var(--bg-primary);
            color: var(--primary);
        }

        .association-details {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .association-details i {
            width: 16px;
            color: var(--primary);
        }

        .association-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin: 0.75rem 0;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .association-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .association-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .association-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        /* Table View */
        .associations-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .associations-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .associations-table th,
        .associations-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .associations-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .associations-table tr:hover {
            background: var(--bg-primary);
        }

        .association-logo-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
        }

        .type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .type-badge.religious { background: rgba(139, 92, 246, 0.1); color: var(--purple); }
        .type-badge.cultural { background: rgba(236, 72, 153, 0.1); color: var(--pink); }
        .type-badge.academic { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .type-badge.sports { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .type-badge.social { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-badge.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .status-badge.inactive {
            background: rgba(239, 68, 68, 0.2);
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
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.2s;
            padding: 0.5rem;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.85rem;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group small {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .image-preview {
            margin-top: 0.5rem;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--border-color);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
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

        body.dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }

        body.dark-mode .alert-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary);
            background: var(--card-bg);
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                margin-left: 0;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .header-container {
                padding: 0.75rem 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .associations-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .association-actions {
                flex-direction: column;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo-img">
                <div class="logo-text">
                    <h1>Isonga Admin</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <div class="user-area">
                <button class="theme-toggle" id="themeToggle" title="Toggle Dark/Light Mode">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_admin['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($current_admin['full_name'] ?? 'Admin'); ?></div>
                        <div class="user-role">System Administrator</div>
                    </div>
                </div>
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
                <li class="menu-item"><a href="hero.php"><i class="fas fa-images"></i> Hero Images</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                  <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php" class="active"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
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
                <h1><i class="fas fa-handshake"></i> Associations Management</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add Association
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_associations; ?></div>
                    <div class="stat-label">Total Associations</div>
                </div>
                <?php foreach ($status_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['status']); ?></div>
                    </div>
                <?php endforeach; ?>
                <?php foreach (array_slice($type_stats, 0, 2) as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['type']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form method="GET" action="" class="filters-bar">
                <div class="filter-group">
                    <label>Type:</label>
                    <select name="type" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <?php foreach ($association_types as $key => $type_name): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type_name); ?>
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
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, contact person..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $type_filter || $status_filter): ?>
                        <a href="associations.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Bulk Actions -->
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk">
                <div class="bulk-actions-bar">
                    <select name="bulk_action" id="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                </div>

                <!-- Grid View -->
                <div id="gridView" class="associations-grid">
                    <?php if (empty($associations)): ?>
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <i class="fas fa-handshake"></i>
                            <h3>No associations found</h3>
                            <p>Click "Add Association" to create one.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($associations as $assoc): ?>
                            <div class="association-card">
                                <div class="association-header">
                                    <div class="association-logo">
                                        <?php if (!empty($assoc['logo_url']) && file_exists('../' . $assoc['logo_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($assoc['logo_url']); ?>" alt="<?php echo htmlspecialchars($assoc['name']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder">
                                                <i class="fas fa-handshake"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="association-status <?php echo $assoc['status']; ?>">
                                        <?php echo ucfirst($assoc['status']); ?>
                                    </div>
                                    <div class="association-checkbox-wrapper">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $assoc['id']; ?>" class="association-checkbox">
                                    </div>
                                </div>
                                <div class="association-info">
                                    <h3 class="association-name"><?php echo htmlspecialchars($assoc['name']); ?></h3>
                                    <span class="association-type">
                                        <?php echo $association_types[$assoc['type']] ?? ucfirst($assoc['type']); ?>
                                    </span>
                                    <?php if (!empty($assoc['established_date'])): ?>
                                        <div class="association-details">
                                            <i class="fas fa-calendar-alt"></i> Est. <?php echo date('Y', strtotime($assoc['established_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($assoc['contact_person'])): ?>
                                        <div class="association-details">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($assoc['contact_person']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($assoc['description'])): ?>
                                        <div class="association-description">
                                            <?php echo htmlspecialchars(substr($assoc['description'], 0, 100)); ?>...
                                        </div>
                                    <?php endif; ?>
                                    <div class="association-meta">
                                        <span><i class="fas fa-users"></i> <?php echo $assoc['actual_members_count'] ?? $assoc['members_count']; ?> members</span>
                                        <?php if (!empty($assoc['meeting_schedule'])): ?>
                                            <span><i class="fas fa-calendar-week"></i> Weekly</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="association-actions">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $assoc['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="?toggle_status=1&id=<?php echo $assoc['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle association status?')">
                                            <i class="fas fa-toggle-on"></i>
                                        </a>
                                        <a href="?delete=1&id=<?php echo $assoc['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this association?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Table View -->
                <div id="tableView" class="associations-table-container" style="display: none;">
                    <table class="associations-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>Logo</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Contact Person</th>
                                <th>Members</th>
                                <th>Status</th>
                                <th>Established</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($associations)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i class="fas fa-handshake"></i>
                                            <h3>No associations found</h3>
                                            <p>Click "Add Association" to create one.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($associations as $assoc): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $assoc['id']; ?>" class="association-checkbox"></td>
                                        <td>
                                            <?php if (!empty($assoc['logo_url']) && file_exists('../' . $assoc['logo_url'])): ?>
                                                <img src="../<?php echo htmlspecialchars($assoc['logo_url']); ?>" class="association-logo-sm" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="association-logo-sm" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);">
                                                    <i class="fas fa-handshake"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($assoc['name']); ?></strong></td>
                                        <td><span class="type-badge <?php echo $assoc['type']; ?>"><?php echo $association_types[$assoc['type']] ?? ucfirst($assoc['type']); ?></span></td>
                                        <td><?php echo htmlspecialchars($assoc['contact_person'] ?? '-'); ?></td>
                                        <td><?php echo $assoc['actual_members_count'] ?? $assoc['members_count']; ?></td>
                                        <td><span class="status-badge <?php echo $assoc['status']; ?>"><?php echo ucfirst($assoc['status']); ?></span></td>
                                        <td><?php echo $assoc['established_date'] ? date('Y', strtotime($assoc['established_date'])) : '-'; ?></td>
                                        <td class="action-buttons">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $assoc['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle_status=1&id=<?php echo $assoc['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle status?')">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $assoc['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- View Toggle -->
            <div class="view-toggle" style="margin-top: 1rem;">
                <button class="view-btn active" onclick="toggleView('grid')" id="gridViewBtn">
                    <i class="fas fa-th-large"></i> Grid View
                </button>
                <button class="view-btn" onclick="toggleView('table')" id="tableViewBtn">
                    <i class="fas fa-table"></i> Table View
                </button>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo $type_filter; ?>&status=<?php echo $status_filter; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo $type_filter; ?>&status=<?php echo $status_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo $type_filter; ?>&status=<?php echo $status_filter; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit Association Modal -->
    <div id="associationModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle">Add Association</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="associationForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="association_id" id="associationId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Association Name *</label>
                        <input type="text" name="name" id="name" required>
                    </div>
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="type" id="type" required>
                            <option value="">Select Type</option>
                            <?php foreach ($association_types as $key => $type_name): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($type_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Established Date</label>
                        <input type="date" name="established_date" id="established_date">
                    </div>
                    <div class="form-group">
                        <label>Members Count</label>
                        <input type="number" name="members_count" id="members_count" value="0">
                    </div>
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" id="description" rows="3" placeholder="Brief description of the association..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Meeting Schedule</label>
                        <input type="text" name="meeting_schedule" id="meeting_schedule" placeholder="e.g., Every Friday, 2:00 PM">
                    </div>
                    <div class="form-group">
                        <label>Meeting Location</label>
                        <input type="text" name="meeting_location" id="meeting_location" placeholder="e.g., Room A-101">
                    </div>
                    <div class="form-group">
                        <label>Faculty Advisor</label>
                        <input type="text" name="faculty_advisor" id="faculty_advisor">
                    </div>
                    <div class="form-group">
                        <label>Advisor Contact</label>
                        <input type="text" name="advisor_contact" id="advisor_contact">
                    </div>
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="contact_person">
                    </div>
                    <div class="form-group">
                        <label>Contact Email</label>
                        <input type="email" name="contact_email" id="contact_email">
                    </div>
                    <div class="form-group">
                        <label>Contact Phone</label>
                        <input type="text" name="contact_phone" id="contact_phone">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Logo</label>
                        <input type="file" name="logo" id="logo" accept="image/*" onchange="previewImage(this)">
                        <div id="imagePreview" class="image-preview" style="display: none;">
                            <img id="previewImg" src="" alt="Preview">
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label>Social Links</label>
                        <div class="form-group">
                            <input type="url" name="facebook" id="facebook" placeholder="Facebook URL">
                        </div>
                        <div class="form-group">
                            <input type="url" name="twitter" id="twitter" placeholder="Twitter URL">
                        </div>
                        <div class="form-group">
                            <input type="url" name="instagram" id="instagram" placeholder="Instagram URL">
                        </div>
                        <div class="form-group">
                            <input type="url" name="linkedin" id="linkedin" placeholder="LinkedIn URL">
                        </div>
                        <div class="form-group">
                            <input type="url" name="website" id="website" placeholder="Website URL">
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label>Goals & Objectives</label>
                        <textarea name="goals" id="goals" rows="2" placeholder="Association goals and objectives..."></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Achievements</label>
                        <textarea name="achievements" id="achievements" rows="2" placeholder="Notable achievements..."></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Performance Notes</label>
                        <textarea name="performance_notes" id="performance_notes" rows="2" placeholder="Performance notes..."></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Association</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        
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
            document.getElementById('modalTitle').textContent = 'Add Association';
            document.getElementById('formAction').value = 'add';
            document.getElementById('associationId').value = '';
            document.getElementById('associationForm').reset();
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('associationModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditModal(assocId) {
            fetch(`associations.php?get_association=1&id=${assocId}`)
                .then(response => response.json())
                .then(assoc => {
                    if (assoc.error) {
                        alert('Error loading association data');
                        return;
                    }
                    
                    document.getElementById('modalTitle').textContent = 'Edit Association';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('associationId').value = assoc.id;
                    document.getElementById('name').value = assoc.name || '';
                    document.getElementById('type').value = assoc.type || '';
                    document.getElementById('description').value = assoc.description || '';
                    document.getElementById('established_date').value = assoc.established_date || '';
                    document.getElementById('meeting_schedule').value = assoc.meeting_schedule || '';
                    document.getElementById('meeting_location').value = assoc.meeting_location || '';
                    document.getElementById('faculty_advisor').value = assoc.faculty_advisor || '';
                    document.getElementById('advisor_contact').value = assoc.advisor_contact || '';
                    document.getElementById('members_count').value = assoc.members_count || 0;
                    document.getElementById('contact_person').value = assoc.contact_person || '';
                    document.getElementById('contact_email').value = assoc.contact_email || '';
                    document.getElementById('contact_phone').value = assoc.contact_phone || '';
                    document.getElementById('status').value = assoc.status || 'active';
                    document.getElementById('goals').value = assoc.goals || '';
                    document.getElementById('achievements').value = assoc.achievements || '';
                    document.getElementById('performance_notes').value = assoc.performance_notes || '';
                    document.getElementById('facebook').value = assoc.facebook || '';
                    document.getElementById('twitter').value = assoc.twitter || '';
                    document.getElementById('instagram').value = assoc.instagram || '';
                    document.getElementById('linkedin').value = assoc.linkedin || '';
                    document.getElementById('website').value = assoc.website || '';
                    
                    if (assoc.logo_url && assoc.logo_url !== 'null') {
                        const preview = document.getElementById('imagePreview');
                        const previewImg = document.getElementById('previewImg');
                        previewImg.src = '../' + assoc.logo_url;
                        preview.style.display = 'block';
                    } else {
                        document.getElementById('imagePreview').style.display = 'none';
                    }
                    
                    document.getElementById('associationModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading association data');
                });
        }
        
        function closeModal() {
            document.getElementById('associationModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Image preview - circular
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
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.association-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.association-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one association');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} association(s)?`);
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('associationModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Prevent modal content click from bubbling
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>