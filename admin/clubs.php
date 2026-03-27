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

// Handle Club Actions
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

// Handle Add Club
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Handle logo upload
            $logo_url = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/clubs/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                        $logo_url = 'assets/uploads/clubs/' . $file_name;
                    }
                }
            }
            
            // Check if name already exists
            $stmt = $pdo->prepare("SELECT id FROM clubs WHERE name = ?");
            $stmt->execute([$_POST['name']]);
            if ($stmt->fetch()) {
                throw new Exception("Club name '{$_POST['name']}' already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO clubs (
                    name, description, category, department, established_date,
                    meeting_schedule, meeting_location, faculty_advisor, advisor_contact,
                    members_count, status, logo_url, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                $_POST['name'],
                $_POST['description'] ?? null,
                $_POST['category'],
                $_POST['department'] ?? null,
                !empty($_POST['established_date']) ? $_POST['established_date'] : null,
                $_POST['meeting_schedule'] ?? null,
                $_POST['meeting_location'] ?? null,
                $_POST['faculty_advisor'] ?? null,
                $_POST['advisor_contact'] ?? null,
                $_POST['members_count'] ?? 0,
                $_POST['status'] ?? 'active',
                $logo_url,
                $user_id
            ]);
            
            $message = "Club added successfully!";
            header("Location: clubs.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding club: " . $e->getMessage();
            error_log("Club creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Club
    elseif ($_POST['action'] === 'edit') {
        try {
            $club_id = $_POST['club_id'];
            $logo_url = null;
            
            // Handle logo upload
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/clubs/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                        $logo_url = 'assets/uploads/clubs/' . $file_name;
                        
                        // Delete old logo
                        $stmt = $pdo->prepare("SELECT logo_url FROM clubs WHERE id = ?");
                        $stmt->execute([$club_id]);
                        $old_club = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($old_club['logo_url'])) {
                            $old_logo_path = '../' . $old_club['logo_url'];
                            if (file_exists($old_logo_path)) {
                                unlink($old_logo_path);
                            }
                        }
                    }
                }
            }
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'name', 'description', 'category', 'department', 'established_date',
                'meeting_schedule', 'meeting_location', 'faculty_advisor', 'advisor_contact',
                'members_count', 'status'
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
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $club_id;
            
            $sql = "UPDATE clubs SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Club updated successfully!";
            header("Location: clubs.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating club: " . $e->getMessage();
            error_log("Club update error: " . $e->getMessage());
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
                    $stmt = $pdo->prepare("UPDATE clubs SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " clubs activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE clubs SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " clubs deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get logos to delete
                    $stmt = $pdo->prepare("SELECT logo_url FROM clubs WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $clubs_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($clubs_to_delete as $club) {
                        if (!empty($club['logo_url'])) {
                            $logo_path = '../' . $club['logo_url'];
                            if (file_exists($logo_path)) {
                                unlink($logo_path);
                            }
                        }
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM clubs WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " clubs deleted.";
                }
                header("Location: clubs.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No clubs selected.";
        }
    }
    
    // Handle Add Member
    elseif ($_POST['action'] === 'add_member') {
        try {
            $club_id = $_POST['club_id'];
            $reg_number = $_POST['reg_number'];
            $name = $_POST['name'];
            $email = $_POST['email'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
            $academic_year = $_POST['academic_year'] ?? null;
            $role = $_POST['role'] ?? 'member';
            
            // Check if member already exists
            $stmt = $pdo->prepare("SELECT id FROM club_members WHERE club_id = ? AND reg_number = ?");
            $stmt->execute([$club_id, $reg_number]);
            if ($stmt->fetch()) {
                throw new Exception("Member with registration number '$reg_number' is already in this club.");
            }
            
            // Get user_id if exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ?");
            $stmt->execute([$reg_number]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_id_member = $user ? $user['id'] : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO club_members (
                    club_id, user_id, reg_number, name, email, phone,
                    department_id, program_id, academic_year, role, join_date, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active', NOW())
            ");
            
            $stmt->execute([
                $club_id,
                $user_id_member,
                $reg_number,
                $name,
                $email,
                $phone,
                $department_id,
                $program_id,
                $academic_year,
                $role
            ]);
            
            // Update club members count
            $stmt = $pdo->prepare("UPDATE clubs SET members_count = members_count + 1 WHERE id = ?");
            $stmt->execute([$club_id]);
            
            $message = "Member added successfully!";
            header("Location: clubs.php?tab=members&club_id=" . $club_id . "&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding member: " . $e->getMessage();
            error_log("Member addition error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Member
    elseif ($_POST['action'] === 'edit_member') {
        try {
            $member_id = $_POST['member_id'];
            $club_id = $_POST['club_id'];
            $name = $_POST['name'];
            $email = $_POST['email'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $academic_year = $_POST['academic_year'] ?? null;
            $role = $_POST['role'];
            $status = $_POST['status'];
            
            $stmt = $pdo->prepare("
                UPDATE club_members 
                SET name = ?, email = ?, phone = ?, academic_year = ?, role = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $academic_year, $role, $status, $member_id]);
            
            $message = "Member updated successfully!";
            header("Location: clubs.php?tab=members&club_id=" . $club_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating member: " . $e->getMessage();
            error_log("Member update error: " . $e->getMessage());
        }
    }
    
    // Handle Remove Member
    elseif ($_POST['action'] === 'remove_member') {
        try {
            $member_id = $_POST['member_id'];
            $club_id = $_POST['club_id'];
            
            $stmt = $pdo->prepare("DELETE FROM club_members WHERE id = ?");
            $stmt->execute([$member_id]);
            
            // Update club members count
            $stmt = $pdo->prepare("UPDATE clubs SET members_count = members_count - 1 WHERE id = ?");
            $stmt->execute([$club_id]);
            
            $message = "Member removed successfully!";
            header("Location: clubs.php?tab=members&club_id=" . $club_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error removing member: " . $e->getMessage();
            error_log("Member removal error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions for Members
    elseif ($_POST['action'] === 'bulk_members') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        $club_id = $_POST['club_id'] ?? 0;
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'remove') {
                    $stmt = $pdo->prepare("DELETE FROM club_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " members removed.";
                    
                    // Update club members count
                    $stmt = $pdo->prepare("UPDATE clubs SET members_count = (SELECT COUNT(*) FROM club_members WHERE club_id = ?) WHERE id = ?");
                    $stmt->execute([$club_id, $club_id]);
                }
                header("Location: clubs.php?tab=members&club_id=" . $club_id . "&msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No members selected.";
        }
    }
}

// Handle Status Toggle for Club
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $club_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM clubs WHERE id = ?");
        $stmt->execute([$club_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE clubs SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $club_id]);
        
        $message = "Club status updated successfully!";
        header("Location: clubs.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling club status: " . $e->getMessage();
    }
}

// Handle Delete Club
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $club_id = $_GET['id'];
    try {
        // Get logo to delete
        $stmt = $pdo->prepare("SELECT logo_url FROM clubs WHERE id = ?");
        $stmt->execute([$club_id]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($club['logo_url'])) {
            $logo_path = '../' . $club['logo_url'];
            if (file_exists($logo_path)) {
                unlink($logo_path);
            }
        }
        
        // Delete members first
        $stmt = $pdo->prepare("DELETE FROM club_members WHERE club_id = ?");
        $stmt->execute([$club_id]);
        
        $stmt = $pdo->prepare("DELETE FROM clubs WHERE id = ?");
        $stmt->execute([$club_id]);
        
        $message = "Club deleted successfully!";
        header("Location: clubs.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting club: " . $e->getMessage();
    }
}

// Get club for editing via AJAX
if (isset($_GET['get_club']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM clubs WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($club);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get member for editing via AJAX
if (isset($_GET['get_member']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT cm.*, d.name as department_name, p.name as program_name
            FROM club_members cm
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

// Get programs by department via AJAX
if (isset($_GET['get_programs']) && isset($_GET['department_id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND is_active = true ORDER BY name");
        $stmt->execute([$_GET['department_id']]);
        $programs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($programs_list);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering for Clubs
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(name ILIKE ? OR description ILIKE ? OR faculty_advisor ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($category_filter)) {
    $where_conditions[] = "category = ?";
    $params[] = $category_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM clubs WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_clubs = $stmt->fetchColumn();
    $total_pages = ceil($total_clubs / $limit);
} catch (PDOException $e) {
    $total_clubs = 0;
    $total_pages = 0;
}

// Get clubs with pagination
try {
    $sql = "
        SELECT c.*, 
               (SELECT COUNT(*) FROM club_members WHERE club_id = c.id) as actual_members_count
        FROM clubs c
        WHERE $where_clause
        ORDER BY c.name ASC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clubs = [];
    error_log("Clubs fetch error: " . $e->getMessage());
}

// Get club members for selected club
$selected_club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$club_members = [];
$selected_club = null;

if ($selected_club_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM clubs WHERE id = ?");
        $stmt->execute([$selected_club_id]);
        $selected_club = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("
            SELECT cm.*, d.name as department_name, p.name as program_name
            FROM club_members cm
            LEFT JOIN departments d ON cm.department_id = d.id
            LEFT JOIN programs p ON cm.program_id = p.id
            WHERE cm.club_id = ?
            ORDER BY cm.role ASC, cm.name ASC
        ");
        $stmt->execute([$selected_club_id]);
        $club_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $club_members = [];
        error_log("Club members fetch error: " . $e->getMessage());
    }
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT category, COUNT(*) as count FROM clubs GROUP BY category");
    $category_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM clubs GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM club_members");
    $total_members = $stmt->fetchColumn();
} catch (PDOException $e) {
    $category_stats = [];
    $status_stats = [];
    $total_members = 0;
}

// Club categories
$club_categories = [
    'academic' => 'Academic',
    'cultural' => 'Cultural',
    'sports' => 'Sports',
    'technical' => 'Technical',
    'entrepreneurship' => 'Entrepreneurship',
    'environment' => 'Environment',
    'entertainment' => 'Entertainment',
    'other' => 'Other'
];

$member_roles = [
    'president' => 'President',
    'vice_president' => 'Vice President',
    'secretary' => 'Secretary',
    'treasurer' => 'Treasurer',
    'member' => 'Member'
];

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'clubs';

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
    <title>Clubs Management - Isonga RPSU Admin</title>
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
            --indigo: #6366f1;
            
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

        .btn-success {
            background: var(--success);
            color: white;
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
            position: relative;
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
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

        /* Clubs Grid */
        .clubs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .club-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
        }

        .club-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .club-header {
            height: 120px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .club-logo {
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

        .club-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .club-logo .placeholder {
            font-size: 2.5rem;
            color: var(--primary);
        }

        .club-status {
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

        .club-status.active {
            background: #d4edda;
            color: #155724;
        }

        .club-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .club-status.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .club-status.inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .club-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 4px;
        }

        .club-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .club-info {
            padding: 1rem;
        }

        .club-name {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .club-category {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            background: var(--bg-primary);
            color: var(--primary);
        }

        .club-details {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .club-details i {
            width: 16px;
            color: var(--primary);
        }

        .club-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin: 0.75rem 0;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .club-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .club-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .club-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        /* Members Table */
        .members-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .members-table th,
        .members-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .members-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .members-table tr:hover {
            background: var(--bg-primary);
        }

        .role-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .role-badge.president { background: rgba(139, 92, 246, 0.1); color: var(--purple); }
        .role-badge.vice_president { background: rgba(99, 102, 241, 0.1); color: var(--indigo); }
        .role-badge.secretary { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .role-badge.treasurer { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .role-badge.member { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }

        /* Club Header for Members Tab */
        .club-header-info {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .club-header-details {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .club-header-logo {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .club-header-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .club-header-logo .placeholder {
            font-size: 1.5rem;
            color: white;
        }

        .club-header-text h2 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .club-header-text p {
            font-size: 0.75rem;
            color: var(--text-secondary);
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
            max-width: 700px;
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
            
            .clubs-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .club-actions {
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
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                  <li class="menu-item"><a href="representative.php"><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php" class="active"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
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

            <?php if ($active_tab === 'members' && $selected_club): ?>
                <!-- Members Management Page -->
                <div class="page-header">
                    <h1><i class="fas fa-users"></i> <?php echo htmlspecialchars($selected_club['name']); ?> - Members</h1>
                    <a href="clubs.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Clubs
                    </a>
                </div>

                <div class="club-header-info">
                    <div class="club-header-details">
                        <div class="club-header-logo">
                            <?php if (!empty($selected_club['logo_url']) && file_exists('../' . $selected_club['logo_url'])): ?>
                                <img src="../<?php echo htmlspecialchars($selected_club['logo_url']); ?>" alt="<?php echo htmlspecialchars($selected_club['name']); ?>">
                            <?php else: ?>
                                <div class="placeholder">
                                    <i class="fas fa-chess-queen"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="club-header-text">
                            <h2><?php echo htmlspecialchars($selected_club['name']); ?></h2>
                            <p><?php echo htmlspecialchars($selected_club['description'] ?? 'No description'); ?></p>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="openAddMemberModal(<?php echo $selected_club['id']; ?>)">
                            <i class="fas fa-plus"></i> Add Member
                        </button>
                    </div>
                </div>

                <!-- Members Table -->
                <form method="POST" action="" id="membersBulkForm">
                    <input type="hidden" name="action" value="bulk_members">
                    <input type="hidden" name="club_id" value="<?php echo $selected_club['id']; ?>">
                    <div class="bulk-actions-bar">
                        <select name="bulk_action" id="members_bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="remove">Remove Members</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkMembers()">Apply</button>
                    </div>

                    <div class="members-table-container">
                        <table class="members-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="select-all-members" onclick="toggleAllMembers(this)"></th>
                                    <th>Reg Number</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Department</th>
                                    <th>Program</th>
                                    <th>Academic Year</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </thead>
                                <tbody>
                                    <?php if (empty($club_members)): ?>
                                        <tr>
                                            <td colspan="11">
                                                <div class="empty-state">
                                                    <i class="fas fa-users"></i>
                                                    <h3>No members found</h3>
                                                    <p>Click "Add Member" to add members to this club.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($club_members as $member): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox"></td>
                                                <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                <td><strong><?php echo htmlspecialchars($member['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($member['phone'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($member['department_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($member['program_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($member['academic_year'] ?? '-'); ?></td>
                                                <td><span class="role-badge <?php echo $member['role']; ?>"><?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?></span></td>
                                                <td><span class="status-badge <?php echo $member['status']; ?>"><?php echo ucfirst($member['status']); ?></span></td>
                                                <td class="action-buttons">
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="openEditMemberModal(<?php echo $member['id']; ?>, <?php echo $selected_club['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="action" value="remove_member">
                                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                        <input type="hidden" name="club_id" value="<?php echo $selected_club['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove this member?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

            <?php else: ?>
                <!-- Clubs List Page -->
                <div class="page-header">
                    <h1><i class="fas fa-chess-queen"></i> Clubs Management</h1>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add Club
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_clubs; ?></div>
                        <div class="stat-label">Total Clubs</div>
                    </div>
                    <?php foreach ($status_stats as $stat): ?>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $stat['count']; ?></div>
                            <div class="stat-label"><?php echo ucfirst($stat['status']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_members; ?></div>
                        <div class="stat-label">Total Members</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn <?php echo $active_tab === 'clubs' ? 'active' : ''; ?>" onclick="switchTab('clubs')">
                        <i class="fas fa-list"></i> Clubs
                    </button>
                </div>

                <!-- Clubs Tab -->
                <div id="clubsTab" class="tab-pane active">
                    <!-- Filters -->
                    <form method="GET" action="" class="filters-bar">
                        <input type="hidden" name="tab" value="clubs">
                        <div class="filter-group">
                            <label>Category:</label>
                            <select name="category" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <?php foreach ($club_categories as $key => $cat_name): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $category_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat_name); ?>
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
                            <input type="text" name="search" placeholder="Search by name, advisor..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                            <?php if ($search || $category_filter || $status_filter): ?>
                                <a href="clubs.php?tab=clubs" class="btn btn-sm">Clear</a>
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

                        <div class="clubs-grid">
                            <?php if (empty($clubs)): ?>
                                <div class="empty-state" style="grid-column: 1/-1;">
                                    <i class="fas fa-chess-queen"></i>
                                    <h3>No clubs found</h3>
                                    <p>Click "Add Club" to create one.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($clubs as $club): ?>
                                    <div class="club-card">
                                        <div class="club-header">
                                            <div class="club-logo">
                                                <?php if (!empty($club['logo_url']) && file_exists('../' . $club['logo_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($club['logo_url']); ?>" alt="<?php echo htmlspecialchars($club['name']); ?>">
                                                <?php else: ?>
                                                    <div class="placeholder">
                                                        <i class="fas fa-chess-queen"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="club-status <?php echo $club['status']; ?>">
                                                <?php echo ucfirst($club['status']); ?>
                                            </div>
                                            <div class="club-checkbox-wrapper" onclick="event.stopPropagation()">
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo $club['id']; ?>" class="club-checkbox">
                                            </div>
                                        </div>
                                        <div class="club-info">
                                            <h3 class="club-name"><?php echo htmlspecialchars($club['name']); ?></h3>
                                            <span class="club-category">
                                                <?php echo $club_categories[$club['category']] ?? ucfirst($club['category']); ?>
                                            </span>
                                            <?php if (!empty($club['established_date'])): ?>
                                                <div class="club-details">
                                                    <i class="fas fa-calendar-alt"></i> Est. <?php echo date('Y', strtotime($club['established_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($club['faculty_advisor'])): ?>
                                                <div class="club-details">
                                                    <i class="fas fa-chalkboard-user"></i> Advisor: <?php echo htmlspecialchars($club['faculty_advisor']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($club['meeting_schedule'])): ?>
                                                <div class="club-details">
                                                    <i class="fas fa-calendar-week"></i> <?php echo htmlspecialchars($club['meeting_schedule']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($club['description'])): ?>
                                                <div class="club-description">
                                                    <?php echo htmlspecialchars(substr($club['description'], 0, 100)); ?>...
                                                </div>
                                            <?php endif; ?>
                                            <div class="club-meta">
                                                <span><i class="fas fa-users"></i> <?php echo $club['actual_members_count']; ?> members</span>
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($club['created_at'])); ?></span>
                                            </div>
                                            <div class="club-actions" onclick="event.stopPropagation()">
                                                <a href="clubs.php?tab=members&club_id=<?php echo $club['id']; ?>" class="btn btn-info btn-sm">
                                                    <i class="fas fa-users"></i> Members
                                                </a>
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $club['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <a href="?toggle_status=1&id=<?php echo $club['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle club status?')">
                                                    <i class="fas fa-toggle-on"></i>
                                                </a>
                                                <a href="?delete=1&id=<?php echo $club['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this club? This will also remove all members.')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page-1; ?>&tab=clubs&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&status=<?php echo $status_filter; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&tab=clubs&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&status=<?php echo $status_filter; ?>" 
                                   class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page+1; ?>&tab=clubs&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&status=<?php echo $status_filter; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit Club Modal -->
    <div id="clubModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="clubModalTitle">Add Club</h2>
                <button class="close-modal" onclick="closeClubModal()">&times;</button>
            </div>
            <form method="POST" action="" id="clubForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="clubAction" value="add">
                <input type="hidden" name="club_id" id="clubId" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Club Name *</label>
                        <input type="text" name="name" id="club_name" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category" id="club_category" required>
                            <option value="">Select Category</option>
                            <?php foreach ($club_categories as $key => $cat_name): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($cat_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" id="club_department" placeholder="e.g., Science & Technology">
                    </div>
                    <div class="form-group">
                        <label>Established Date</label>
                        <input type="date" name="established_date" id="club_established_date">
                    </div>
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" id="club_description" rows="3" placeholder="Brief description of the club..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Meeting Schedule</label>
                        <input type="text" name="meeting_schedule" id="club_meeting_schedule" placeholder="e.g., Every Friday, 2:00 PM">
                    </div>
                    <div class="form-group">
                        <label>Meeting Location</label>
                        <input type="text" name="meeting_location" id="club_meeting_location" placeholder="e.g., Room A-101">
                    </div>
                    <div class="form-group">
                        <label>Faculty Advisor</label>
                        <input type="text" name="faculty_advisor" id="club_faculty_advisor">
                    </div>
                    <div class="form-group">
                        <label>Advisor Contact</label>
                        <input type="text" name="advisor_contact" id="club_advisor_contact" placeholder="Phone or Email">
                    </div>
                    <div class="form-group">
                        <label>Members Count</label>
                        <input type="number" name="members_count" id="club_members_count" value="0">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="club_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Logo</label>
                        <input type="file" name="logo" id="club_logo" accept="image/*" onchange="previewClubImage(this)">
                        <div id="clubImagePreview" class="image-preview" style="display: none;">
                            <img id="clubPreviewImg" src="" alt="Preview">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeClubModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Club</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Member Modal -->
    <div id="memberModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="memberModalTitle">Add Member</h2>
                <button class="close-modal" onclick="closeMemberModal()">&times;</button>
            </div>
            <form method="POST" action="" id="memberForm">
                <input type="hidden" name="action" id="memberAction" value="add_member">
                <input type="hidden" name="member_id" id="memberId" value="">
                <input type="hidden" name="club_id" id="memberClubId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Registration Number *</label>
                        <input type="text" name="reg_number" id="member_reg_number" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" id="member_name" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="member_email">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="member_phone">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="member_role">
                            <?php foreach ($member_roles as $key => $role_name): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($role_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" id="member_academic_year" placeholder="e.g., Year 2">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" id="member_department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" id="member_program_id">
                            <option value="">Select Program</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Status</label>
                        <select name="status" id="member_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="graduated">Graduated</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeMemberModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Member</button>
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
        
        // Tab switching
        function switchTab(tab) {
            if (tab === 'clubs') {
                window.location.href = 'clubs.php?tab=clubs';
            }
        }
        
        // Club Modal functions
        function openAddModal() {
            document.getElementById('clubModalTitle').textContent = 'Add Club';
            document.getElementById('clubAction').value = 'add';
            document.getElementById('clubId').value = '';
            document.getElementById('clubForm').reset();
            document.getElementById('clubImagePreview').style.display = 'none';
            document.getElementById('clubModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditModal(clubId) {
            fetch(`clubs.php?get_club=1&id=${clubId}`)
                .then(response => response.json())
                .then(club => {
                    if (club.error) {
                        alert('Error loading club data');
                        return;
                    }
                    document.getElementById('clubModalTitle').textContent = 'Edit Club';
                    document.getElementById('clubAction').value = 'edit';
                    document.getElementById('clubId').value = club.id;
                    document.getElementById('club_name').value = club.name || '';
                    document.getElementById('club_category').value = club.category || '';
                    document.getElementById('club_department').value = club.department || '';
                    document.getElementById('club_established_date').value = club.established_date || '';
                    document.getElementById('club_description').value = club.description || '';
                    document.getElementById('club_meeting_schedule').value = club.meeting_schedule || '';
                    document.getElementById('club_meeting_location').value = club.meeting_location || '';
                    document.getElementById('club_faculty_advisor').value = club.faculty_advisor || '';
                    document.getElementById('club_advisor_contact').value = club.advisor_contact || '';
                    document.getElementById('club_members_count').value = club.members_count || 0;
                    document.getElementById('club_status').value = club.status || 'active';
                    
                    if (club.logo_url && club.logo_url !== 'null') {
                        const preview = document.getElementById('clubImagePreview');
                        const previewImg = document.getElementById('clubPreviewImg');
                        previewImg.src = '../' + club.logo_url;
                        preview.style.display = 'block';
                    } else {
                        document.getElementById('clubImagePreview').style.display = 'none';
                    }
                    
                    document.getElementById('clubModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading club data');
                });
        }
        
        function closeClubModal() {
            document.getElementById('clubModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function previewClubImage(input) {
            const preview = document.getElementById('clubImagePreview');
            const previewImg = document.getElementById('clubPreviewImg');
            
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
        
        // Member Modal functions
        function openAddMemberModal(clubId) {
            document.getElementById('memberModalTitle').textContent = 'Add Member';
            document.getElementById('memberAction').value = 'add_member';
            document.getElementById('memberId').value = '';
            document.getElementById('memberClubId').value = clubId;
            document.getElementById('memberForm').reset();
            document.getElementById('memberModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditMemberModal(memberId, clubId) {
            fetch(`clubs.php?get_member=1&id=${memberId}`)
                .then(response => response.json())
                .then(member => {
                    if (member.error) {
                        alert('Error loading member data');
                        return;
                    }
                    document.getElementById('memberModalTitle').textContent = 'Edit Member';
                    document.getElementById('memberAction').value = 'edit_member';
                    document.getElementById('memberId').value = member.id;
                    document.getElementById('memberClubId').value = clubId;
                    document.getElementById('member_reg_number').value = member.reg_number || '';
                    document.getElementById('member_name').value = member.name || '';
                    document.getElementById('member_email').value = member.email || '';
                    document.getElementById('member_phone').value = member.phone || '';
                    document.getElementById('member_role').value = member.role || 'member';
                    document.getElementById('member_academic_year').value = member.academic_year || '';
                    document.getElementById('member_status').value = member.status || 'active';
                    
                    if (member.department_id) {
                        document.getElementById('member_department_id').value = member.department_id;
                        loadProgramsForMember(member.department_id, member.program_id);
                    }
                    
                    document.getElementById('memberModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading member data');
                });
        }
        
        function closeMemberModal() {
            document.getElementById('memberModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Load programs for member
        function loadProgramsForMember(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('member_program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`clubs.php?get_programs=1&department_id=${departmentId}`)
                .then(response => response.json())
                .then(programs => {
                    let options = '<option value="">Select Program</option>';
                    if (!programs.error && programs.length > 0) {
                        programs.forEach(program => {
                            const selected = selectedProgramId == program.id ? 'selected' : '';
                            options += `<option value="${program.id}" ${selected}>${escapeHtml(program.name)}</option>`;
                        });
                    }
                    document.getElementById('member_program_id').innerHTML = options;
                })
                .catch(error => {
                    console.error('Error loading programs:', error);
                });
        }
        
        document.getElementById('member_department_id').addEventListener('change', function() {
            loadProgramsForMember(this.value);
        });
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.club-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function toggleAllMembers(source) {
            const checkboxes = document.querySelectorAll('.member-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.club-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one club');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} club(s)?`);
        }
        
        function confirmBulkMembers() {
            const action = document.getElementById('members_bulk_action').value;
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
        
        // Close modals on outside click
        window.onclick = function(event) {
            const clubModal = document.getElementById('clubModal');
            const memberModal = document.getElementById('memberModal');
            if (event.target === clubModal) closeClubModal();
            if (event.target === memberModal) closeMemberModal();
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