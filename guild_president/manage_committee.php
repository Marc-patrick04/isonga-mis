<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_user = [];
}

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (Exception $e) {
        $pending_reports = 0;
    }
    
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
        $unread_messages = 0;
    }
    
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (Exception $e) {
        $pending_docs = 0;
    }

    $new_students = 0;
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= CURRENT_DATE - INTERVAL '7 days'
        ");
        $new_students = $stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    } catch (Exception $e) {
        $new_students = 0;
    }
    
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $pending_reports = $unread_messages = $pending_docs = $new_students = 0;
}

// Handle Add Committee Member
// Handle Add Committee Member - UPDATED to update user role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $student_id = $_POST['student_id'] ?? null;
            $student = null;
            
            if ($student_id) {
                $student_stmt = $pdo->prepare("SELECT id, full_name, reg_number, email, phone, department_id, program_id, academic_year FROM users WHERE id = ? AND role = 'student' AND status = 'active' AND deleted_at IS NULL");
                $student_stmt->execute([$student_id]);
                $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception("Student not found or not active.");
                }
            }
            
            if (!$student) {
                throw new Exception("Please select a valid student from the search results.");
            }
            
            // Check if already a committee member (by user_id OR email)
            $check_stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ? OR email = ?");
            $check_stmt->execute([$student['id'], $student['email']]);
            if ($check_stmt->fetch()) {
                throw new Exception("This student is already a committee member.");
            }
            
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
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo_url = 'assets/uploads/committee/' . $file_name;
                    }
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update user role from 'student' to the committee role
            $committee_role = $_POST['role'];
            $update_user_stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $update_user_stmt->execute([$committee_role, $student['id']]);
            
            // Insert into committee_members
            $stmt = $pdo->prepare("
                INSERT INTO committee_members (
                    user_id, name, reg_number, role, role_order, 
                    department_id, program_id, academic_year, 
                    email, phone, bio, portfolio_description,
                    photo_url, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW()
                )
            ");
            
            $stmt->execute([
                $student['id'],
                $student['full_name'],
                $student['reg_number'],
                $committee_role,
                $_POST['role_order'] ?? 0,
                $student['department_id'],
                $student['program_id'],
                $student['academic_year'],
                $student['email'],
                $student['phone'],
                $_POST['bio'] ?? null,
                $_POST['portfolio_description'] ?? null,
                $photo_url,
                $user_id
            ]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Student added to committee successfully! User role has been updated.";
            header("Location: manage_committee.php");
            exit();
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error adding committee member: " . $e->getMessage();
        }
    }
    
    // Handle Edit Committee Member - UPDATED to sync user role
    elseif ($_POST['action'] === 'edit') {
        try {
            $member_id = $_POST['member_id'];
            $photo_url = null;
            
            // Get current committee member to know the user_id
            $current_member_stmt = $pdo->prepare("SELECT user_id, role FROM committee_members WHERE id = ?");
            $current_member_stmt->execute([$member_id]);
            $current_member = $current_member_stmt->fetch(PDO::FETCH_ASSOC);
            $user_id_to_update = $current_member['user_id'];
            $old_role = $current_member['role'];
            
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
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo_url = 'assets/uploads/committee/' . $file_name;
                        
                        $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id = ?");
                        $stmt->execute([$member_id]);
                        $old = $stmt->fetch();
                        if (!empty($old['photo_url'])) {
                            $old_path = '../' . $old['photo_url'];
                            if (file_exists($old_path)) {
                                unlink($old_path);
                            }
                        }
                    }
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['role', 'role_order', 'bio', 'portfolio_description', 'status'];
            
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
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $member_id;
            
            $sql = "UPDATE committee_members SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Update user role in users table if role changed
            $new_role = $_POST['role'];
            if ($new_role !== $old_role && $user_id_to_update) {
                $update_user_stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $update_user_stmt->execute([$new_role, $user_id_to_update]);
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Committee member updated successfully!";
            header("Location: manage_committee.php");
            exit();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error updating committee member: " . $e->getMessage();
        }
    }
    
    // Handle Bulk Actions - UPDATED to sync user roles
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                $pdo->beginTransaction();
                
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE committee_members SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success'] = count($selected_ids) . " members activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE committee_members SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success'] = count($selected_ids) . " members deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get user_ids to revert their roles back to 'student'
                    $user_ids_stmt = $pdo->prepare("SELECT user_id FROM committee_members WHERE id IN ($placeholders)");
                    $user_ids_stmt->execute($selected_ids);
                    $user_ids = $user_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Delete photos first
                    $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $members = $stmt->fetchAll();
                    foreach ($members as $member) {
                        if (!empty($member['photo_url'])) {
                            $photo_path = '../' . $member['photo_url'];
                            if (file_exists($photo_path)) {
                                unlink($photo_path);
                            }
                        }
                    }
                    
                    // Delete from committee_members
                    $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    // Revert user roles back to 'student' for deleted committee members
                    if (!empty($user_ids)) {
                        $user_placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $revert_stmt = $pdo->prepare("UPDATE users SET role = 'student' WHERE id IN ($user_placeholders) AND role != 'admin'");
                        $revert_stmt->execute($user_ids);
                    }
                    
                    $_SESSION['success'] = count($selected_ids) . " members deleted and roles reverted to student.";
                }
                
                $pdo->commit();
                header("Location: manage_committee.php");
                exit();
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No members selected.";
        }
    }
}

// Handle Status Toggle - UPDATED to sync user role status
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT status, user_id FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_status = $current['status'] === 'active' ? 'inactive' : 'active';
        
        $stmt = $pdo->prepare("UPDATE committee_members SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $member_id]);
        
        // Sync user status if user_id exists
        if ($current['user_id']) {
            $user_status = $new_status === 'active' ? 'active' : 'inactive';
            $user_stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $user_stmt->execute([$user_status, $current['user_id']]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Member status updated!";
        header("Location: manage_committee.php");
        exit();
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error toggling status";
    }
}

// Handle Delete - UPDATED to revert user role
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $pdo->beginTransaction();
        
        // Get user_id before deleting
        $stmt = $pdo->prepare("SELECT user_id, photo_url FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch();
        
        if (!empty($member['photo_url'])) {
            $photo_path = '../' . $member['photo_url'];
            if (file_exists($photo_path)) {
                unlink($photo_path);
            }
        }
        
        // Delete from committee_members
        $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        
        // Revert user role back to 'student' (if not admin)
        if ($member['user_id']) {
            $user_stmt = $pdo->prepare("UPDATE users SET role = 'student' WHERE id = ? AND role != 'admin'");
            $user_stmt->execute([$member['user_id']]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Member deleted successfully and role reverted to student!";
        header("Location: manage_committee.php");
        exit();
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error deleting member";
    }
}
// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $current = $stmt->fetchColumn();
        $new_status = $current === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE committee_members SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $member_id]);
        $_SESSION['success'] = "Member status updated!";
        header("Location: manage_committee.php");
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling status";
    }
}

// Handle Delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch();
        if (!empty($member['photo_url'])) {
            $photo_path = '../' . $member['photo_url'];
            if (file_exists($photo_path)) {
                unlink($photo_path);
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $_SESSION['success'] = "Member deleted successfully!";
        header("Location: manage_committee.php");
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting member";
    }
}

// Get member for editing via AJAX
if (isset($_GET['get_member']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM committee_members WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($member);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Search student via AJAX - Enhanced search
if (isset($_GET['search_student']) && isset($_GET['query'])) {
    header('Content-Type: application/json');
    $query = trim($_GET['query']);
    
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.reg_number, u.email, u.phone, u.department_id, u.program_id, u.academic_year,
                   d.name as department_name, p.name as program_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            WHERE u.role = 'student' 
            AND u.status = 'active' 
            AND u.deleted_at IS NULL
            AND u.id NOT IN (SELECT COALESCE(user_id, 0) FROM committee_members WHERE user_id IS NOT NULL)
            AND (
                u.reg_number ILIKE ? 
                OR u.full_name ILIKE ? 
                OR u.email ILIKE ?
                OR u.phone ILIKE ?
            )
            ORDER BY 
                CASE 
                    WHEN u.reg_number ILIKE ? THEN 1
                    WHEN u.full_name ILIKE ? THEN 2
                    ELSE 3
                END,
                u.full_name ASC
            LIMIT 15
        ");
        $search_term = "%$query%";
        $stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($students);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get student details by ID for auto-fill
if (isset($_GET['get_student_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.reg_number, u.email, u.phone, u.department_id, u.program_id, u.academic_year,
                   d.name as department_name, p.name as program_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            WHERE u.id = ? AND u.role = 'student' AND u.status = 'active'
        ");
        $stmt->execute([$_GET['id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($student);
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

$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(name ILIKE ? OR email ILIKE ? OR reg_number ILIKE ? OR role ILIKE ?)";
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

$where_clause = implode(" AND ", $where_conditions);

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

try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM committee_members GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_stats = [];
}

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

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
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
            transition: var(--transition);
        }

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

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

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

        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--primary-blue);
        }

        .page-description {
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .header-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
            border: 1px solid var(--medium-gray);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-icon.total { background: var(--light-blue); color: var(--primary-blue); }
        .stat-icon.active { background: #d4edda; color: var(--success); }
        .stat-icon.inactive { background: #f8d7da; color: var(--danger); }

        .stat-content h3 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .filters-bar {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--medium-gray);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .filter-group select, .search-box input {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: 6px;
            background: var(--white);
            color: var(--text-dark);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-box input {
            width: 250px;
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .view-btn {
            padding: 0.4rem 0.8rem;
            background: var(--light-gray);
            border: 1px solid var(--medium-gray);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            color: var(--text-dark);
        }

        .view-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .bulk-actions-bar {
            background: var(--white);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            border: 1px solid var(--medium-gray);
        }

        .members-table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--medium-gray);
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
            border-bottom: 1px solid var(--medium-gray);
        }

        .members-table th {
            background: var(--light-gray);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--dark-gray);
        }

        .members-table tr:hover {
            background: var(--light-blue);
        }

        .member-avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

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

        .committee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .member-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            border: 1px solid var(--medium-gray);
            transition: transform 0.2s;
        }

        .member-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .member-image {
            width: 100%;
            aspect-ratio: 1 / 1;
            position: relative;
            overflow: hidden;
            background: var(--gradient-primary);
        }

        .member-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
        }

        .member-image .placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            font-size: 4rem;
            color: rgba(255,255,255,0.8);
        }

        .member-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--white);
            z-index: 2;
        }

        .member-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--white);
            border-radius: 6px;
            padding: 4px;
            z-index: 2;
        }

        .member-info {
            padding: 1rem;
        }

        .member-name {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .member-role {
            color: var(--primary-blue);
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .member-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--medium-gray);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
        }

        .btn-warning {
            background: var(--warning);
            color: #856404;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

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
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
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
            color: var(--dark-gray);
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-dark);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        .search-container {
            position: relative;
        }

        .student-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-md);
        }

        .student-search-result {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .student-search-result:hover {
            background: var(--light-blue);
        }

        .student-search-result:last-child {
            border-bottom: none;
        }

        .student-result-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }

        .student-result-details {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .image-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            margin-top: 0.5rem;
            border: 2px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .info-box {
            background: var(--light-blue);
            border-left: 4px solid var(--primary-blue);
            padding: 1rem;
            margin: 1rem 0;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
        }

        .info-box i {
            margin-right: 0.5rem;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .empty-state {
            text-align: center;
            padding: 3rem;
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
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary-blue);
            background: var(--white);
        }

        .pagination a:hover {
            background: var(--primary-blue);
            color: white;
        }

        .pagination .active {
            background: var(--primary-blue);
            color: white;
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

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle { display: none; }
            .main-content { margin-left: 0 !important; }
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
            }
            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }
        }

        @media (max-width: 768px) {
            .nav-container { padding: 0 1rem; gap: 0.5rem; }
            .brand-text h1 { font-size: 1rem; }
            .user-details { display: none; }
            .main-content { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { margin-left: 0; }
            .search-box input { width: 100%; }
            .view-toggle { justify-content: center; }
            .members-table { font-size: 0.7rem; }
            .members-table th, .members-table td { padding: 0.5rem; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .stat-number { font-size: 1.2rem; }
            .page-title { font-size: 1.2rem; }
            .committee-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="overlay" id="mobileOverlay"></div>

    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo">
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

    <div class="dashboard-container">
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
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
                    <a href="reports.php" >
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
                    <a href="committee.php" >
                        <i class="fas fa-users"></i>
                        <span>Committee Performance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="manage_committee.php" class="active">
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
            </ul>
        </nav>

        <main class="main-content" id="mainContent">
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="page-header">
                <div class="header-actions-row">
                    <div>
                        <h1 class="page-title"><i class="fas fa-user-tie"></i> Committee Management</h1>
                        <p class="page-description">Manage your committee members and their roles</p>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Member</button>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total"><i class="fas fa-users"></i></div>
                    <div class="stat-content"><h3><?php echo $total_members; ?></h3><p>Total Members</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon active"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content"><h3><?php $active = 0; foreach ($status_stats as $stat) { if ($stat['status'] === 'active') $active = $stat['count']; } echo $active; ?></h3><p>Active Members</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon inactive"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-content"><h3><?php $inactive = 0; foreach ($status_stats as $stat) { if ($stat['status'] === 'inactive') $inactive = $stat['count']; } echo $inactive; ?></h3><p>Inactive Members</p></div>
                </div>
            </div>

            <form method="GET" class="filters-bar">
                <div class="filter-group">
                    <label>Role:</label>
                    <select name="role" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <?php foreach ($available_roles as $key => $name): ?>
                            <option value="<?php echo $key; ?>" <?php echo $role_filter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
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
                    <input type="text" name="search" placeholder="Search by name, email..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $role_filter || $status_filter): ?>
                        <a href="manage_committee.php" class="btn btn-secondary btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
                <div class="view-toggle">
                    <button type="button" class="view-btn" id="tableViewBtn" onclick="toggleView('table')"><i class="fas fa-table"></i> Table</button>
                    <button type="button" class="view-btn" id="gridViewBtn" onclick="toggleView('grid')"><i class="fas fa-th-large"></i> Grid</button>
                </div>
            </form>

            <form method="POST" id="bulkForm">
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

                <div id="tableView" class="members-table-container">
                    <table class="members-table">
                        <thead>
                            <tr><th width="40"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th><th width="60">Photo</th><th>Name</th><th>Reg Number</th><th>Role</th><th>Email</th><th>Phone</th><th>Status</th><th width="120">Actions</th></thead>
                        <tbody>
                            <?php if (empty($committee_members)): ?>
                                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-user-tie"></i><h3>No committee members found</h3><p>Click "Add Member" to add a student to the committee.</p></div></td></tr>
                            <?php else: ?>
                                <?php foreach ($committee_members as $member): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox"></td>
                                        <td><?php if (!empty($member['photo_url']) && file_exists('../' . $member['photo_url'])): ?><img src="../<?php echo htmlspecialchars($member['photo_url']); ?>" class="member-avatar-sm"><?php else: ?><div class="member-avatar-sm" style="background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; border-radius: 50%; width: 40px; height: 40px;"><?php echo strtoupper(substr($member['name'], 0, 1)); ?></div><?php endif; ?></td>
                                        <td><strong><?php echo htmlspecialchars($member['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($member['reg_number'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($available_roles[$member['role']] ?? str_replace('_', ' ', $member['role'])); ?></td>
                                        <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($member['phone'] ?? '-'); ?></td>
                                        <td><span class="status-badge <?php echo $member['status']; ?>"><?php echo ucfirst($member['status']); ?></span></td>
                                        <td><button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $member['id']; ?>)"><i class="fas fa-edit"></i></button><a href="?toggle_status=1&id=<?php echo $member['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle status?')"><i class="fas fa-toggle-on"></i></a><a href="?delete=1&id=<?php echo $member['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this member?')"><i class="fas fa-trash"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div id="gridView" class="committee-grid" style="display: none;">
                    <?php if (!empty($committee_members)): ?>
                        <?php foreach ($committee_members as $member): ?>
                            <div class="member-card">
                                <div class="member-image">
                                    <?php if (!empty($member['photo_url']) && file_exists('../' . $member['photo_url'])): ?>
                                        <img src="../<?php echo htmlspecialchars($member['photo_url']); ?>" alt="<?php echo htmlspecialchars($member['name']); ?>">
                                    <?php else: ?>
                                        <div class="placeholder"><i class="fas fa-user-circle"></i></div>
                                    <?php endif; ?>
                                    <div class="member-status <?php echo $member['status']; ?>"><?php echo ucfirst($member['status']); ?></div>
                                    <div class="member-checkbox-wrapper"><input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox"></div>
                                </div>
                                <div class="member-info">
                                    <div class="member-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                    <div class="member-role"><?php echo htmlspecialchars($available_roles[$member['role']] ?? str_replace('_', ' ', $member['role'])); ?></div>
                                    <div class="member-actions">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $member['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                                        <a href="?toggle_status=1&id=<?php echo $member['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle status?')"><i class="fas fa-toggle-on"></i></a>
                                        <a href="?delete=1&id=<?php echo $member['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="grid-column: 1/-1;"><i class="fas fa-user-tie"></i><h3>No committee members found</h3><p>Click "Add Member" to add a student to the committee.</p></div>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?><a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                    <?php if ($page < $total_pages): ?><a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="memberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Committee Member</h2>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="memberForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="member_id" id="memberId" value="">
                <input type="hidden" name="student_id" id="studentId" value="">
                
                <div id="studentSearchSection" class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Important:</strong> Only existing students can be added to the committee. 
                    If a student is not found, please add them first in <a href="students.php" style="color: var(--primary-blue);">Student Management</a>.
                </div>
                
                <div class="form-group search-container" id="searchContainer">
                    <label>Search Student *</label>
                    <input type="text" id="studentSearchInput" class="form-control" placeholder="Enter registration number, name, email, or phone..." autocomplete="off">
                    <div id="studentSearchResults" class="student-search-results"></div>
                    <div id="searchLoader" style="display: none; font-size: 0.75rem; color: var(--dark-gray); margin-top: 0.25rem;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>
                </div>
                
                <div id="selectedStudentInfo" style="display: none;" class="info-box">
                    <strong><i class="fas fa-user-check"></i> Selected Student:</strong>
                    <div id="studentDetails"></div>
                </div>
                
                <div class="form-group">
                    <label>Committee Role *</label>
                    <select name="role" id="role" required>
                        <option value="">Select Role</option>
                        <?php foreach ($available_roles as $key => $name): ?>
                            <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Role Order (for display sorting)</label>
                    <input type="number" name="role_order" id="role_order" value="0" placeholder="Lower numbers appear first">
                    <small>Optional: Use to control display order (1=President, 2=Vice President, etc.)</small>
                </div>
                
                <div class="form-group">
                    <label>Bio / Profile Description</label>
                    <textarea name="bio" id="bio" rows="3" placeholder="Brief description of the member's background and responsibilities..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Portfolio Description</label>
                    <textarea name="portfolio_description" id="portfolio_description" rows="3" placeholder="Detailed description of their portfolio and duties..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Profile Photo</label>
                    <input type="file" name="photo" id="photo" accept="image/*" onchange="previewImage(this)">
                    <div id="imagePreview" class="image-preview" style="display: none;"></div>
                    <small>Recommended: Square image, at least 300x300 pixels. Max size: 5MB</small>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Add to Committee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let searchTimeout;
        let selectedStudent = null;
        let currentView = 'table';
        
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
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        function toggleView(view) {
            const tableView = document.getElementById('tableView');
            const gridView = document.getElementById('gridView');
            const tableBtn = document.getElementById('tableViewBtn');
            const gridBtn = document.getElementById('gridViewBtn');
            
            if (view === 'table') {
                tableView.style.display = 'block';
                gridView.style.display = 'none';
                tableBtn.classList.add('active');
                gridBtn.classList.remove('active');
                currentView = 'table';
            } else {
                tableView.style.display = 'none';
                gridView.style.display = 'grid';
                tableBtn.classList.remove('active');
                gridBtn.classList.add('active');
                currentView = 'grid';
            }
            localStorage.setItem('committee_view', view);
        }

        const savedView = localStorage.getItem('committee_view');
        if (savedView === 'grid') toggleView('grid');
        else toggleView('table');

        // Enhanced Student Search with Auto-fill
        const studentSearchInput = document.getElementById('studentSearchInput');
        const searchResults = document.getElementById('studentSearchResults');
        const searchLoader = document.getElementById('searchLoader');
        const selectedStudentInfo = document.getElementById('selectedStudentInfo');
        const studentDetails = document.getElementById('studentDetails');
        const submitBtn = document.getElementById('submitBtn');
        
        if (studentSearchInput) {
            studentSearchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    if (searchResults) searchResults.style.display = 'none';
                    return;
                }
                
                if (searchLoader) searchLoader.style.display = 'block';
                
                searchTimeout = setTimeout(() => {
                    fetch(`manage_committee.php?search_student=1&query=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            if (searchLoader) searchLoader.style.display = 'none';
                            
                            if (data.error) {
                                console.error(data.error);
                                if (searchResults) {
                                    searchResults.innerHTML = `<div class="student-search-result" style="color: var(--danger);">Error: ${data.error}</div>`;
                                    searchResults.style.display = 'block';
                                }
                                return;
                            }
                            
                            if (!data || data.length === 0) {
                                if (searchResults) {
                                    searchResults.innerHTML = '<div class="student-search-result">No students found. <a href="students.php" style="color: var(--primary-blue);">Add a student first</a></div>';
                                    searchResults.style.display = 'block';
                                }
                                return;
                            }
                            
                            if (searchResults) {
                                searchResults.innerHTML = data.map(student => `
                                    <div class="student-search-result" onclick="selectStudent(${student.id})">
                                        <div class="student-result-name">${escapeHtml(student.full_name)}</div>
                                        <div class="student-result-details">
                                            Reg: ${escapeHtml(student.reg_number)} | Email: ${escapeHtml(student.email)} | Phone: ${escapeHtml(student.phone || 'N/A')}
                                        </div>
                                    </div>
                                `).join('');
                                searchResults.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            if (searchLoader) searchLoader.style.display = 'none';
                            if (searchResults) {
                                searchResults.innerHTML = '<div class="student-search-result" style="color: var(--danger);">Search error. Please try again.</div>';
                                searchResults.style.display = 'block';
                            }
                        });
                }, 300);
            });
        }
        
        // Auto-fill student details when selected
        function selectStudent(studentId) {
            // Show loading state
            if (searchLoader) searchLoader.style.display = 'block';
            
            fetch(`manage_committee.php?get_student_details=1&id=${studentId}`)
                .then(res => res.json())
                .then(student => {
                    if (searchLoader) searchLoader.style.display = 'none';
                    
                    if (student.error) {
                        console.error(student.error);
                        alert('Error loading student details: ' + student.error);
                        return;
                    }
                    
                    selectedStudent = student;
                    
                    // Display selected student info with auto-filled data
                    if (studentDetails) {
                        studentDetails.innerHTML = `
                            <div style="display: grid; grid-template-columns: auto 1fr; gap: 0.5rem;">
                                <strong>Name:</strong> <span>${escapeHtml(student.full_name)}</span>
                                <strong>Reg Number:</strong> <span>${escapeHtml(student.reg_number)}</span>
                                <strong>Email:</strong> <span>${escapeHtml(student.email)}</span>
                                <strong>Phone:</strong> <span>${escapeHtml(student.phone || 'N/A')}</span>
                                <strong>Department:</strong> <span>${escapeHtml(student.department_name || 'N/A')}</span>
                                <strong>Program:</strong> <span>${escapeHtml(student.program_name || 'N/A')}</span>
                                <strong>Academic Year:</strong> <span>${escapeHtml(student.academic_year || 'N/A')}</span>
                            </div>
                        `;
                    }
                    if (selectedStudentInfo) selectedStudentInfo.style.display = 'block';
                    
                    // Set student_id field
                    const studentIdField = document.getElementById('studentId');
                    if (studentIdField) studentIdField.value = student.id;
                    
                    // Clear search and hide results
                    if (studentSearchInput) studentSearchInput.value = student.full_name;
                    if (searchResults) searchResults.style.display = 'none';
                    
                    // Enable submit button
                    if (submitBtn) submitBtn.disabled = false;
                    
                    // Clear any previous error messages
                    const existingAlert = document.querySelector('#memberForm .alert-danger');
                    if (existingAlert) existingAlert.remove();
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (searchLoader) searchLoader.style.display = 'none';
                    alert('Error loading student details');
                });
        }
        
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (studentSearchInput && searchResults && !studentSearchInput.contains(e.target) && !searchResults.contains(e.target)) {
                if (searchResults) searchResults.style.display = 'none';
            }
        });
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Committee Member';
            document.getElementById('formAction').value = 'add';
            document.getElementById('memberId').value = '';
            
            const form = document.getElementById('memberForm');
            if (form) form.reset();
            
            const imagePreview = document.getElementById('imagePreview');
            if (imagePreview) {
                imagePreview.style.display = 'none';
                imagePreview.innerHTML = '';
            }
            
            const studentSearch = document.getElementById('studentSearchInput');
            if (studentSearch) studentSearch.value = '';
            
            const studentIdField = document.getElementById('studentId');
            if (studentIdField) studentIdField.value = '';
            
            const selectedInfo = document.getElementById('selectedStudentInfo');
            if (selectedInfo) selectedInfo.style.display = 'none';
            
            const studentDetailsDiv = document.getElementById('studentDetails');
            if (studentDetailsDiv) studentDetailsDiv.innerHTML = '';
            
            const searchContainer = document.querySelector('.search-container');
            const searchSection = document.getElementById('studentSearchSection');
            if (searchContainer) searchContainer.style.display = 'block';
            if (searchSection) searchSection.style.display = 'block';
            
            selectedStudent = null;
            
            const addSubmitBtn = document.getElementById('submitBtn');
            if (addSubmitBtn) addSubmitBtn.disabled = true;
            
            const existingAlert = document.querySelector('#memberForm .alert-danger, #memberForm .alert-success');
            if (existingAlert) existingAlert.remove();
            
            document.getElementById('memberModal').classList.add('active');
        }
        
        function openEditModal(id) {
            event.stopPropagation();
            
            const searchContainer = document.querySelector('.search-container');
            const searchSection = document.getElementById('studentSearchSection');
            if (searchContainer) searchContainer.style.display = 'none';
            if (searchSection) searchSection.style.display = 'none';
            
            fetch(`manage_committee.php?get_member=1&id=${id}`)
                .then(res => res.json())
                .then(member => {
                    if (member.error) {
                        console.error('Error:', member.error);
                        alert('Error loading member data');
                        return;
                    }
                    
                    document.getElementById('modalTitle').textContent = 'Edit Committee Member';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('memberId').value = member.id;
                    document.getElementById('role').value = member.role;
                    document.getElementById('role_order').value = member.role_order || 0;
                    document.getElementById('bio').value = member.bio || '';
                    document.getElementById('portfolio_description').value = member.portfolio_description || '';
                    document.getElementById('status').value = member.status;
                    
                    const selectedInfo = document.getElementById('selectedStudentInfo');
                    const studentDetailsDiv = document.getElementById('studentDetails');
                    if (selectedInfo) selectedInfo.style.display = 'block';
                    if (studentDetailsDiv) {
                        studentDetailsDiv.innerHTML = `
                            <div style="display: grid; grid-template-columns: auto 1fr; gap: 0.5rem;">
                                <strong>Name:</strong> <span>${escapeHtml(member.name)}</span>
                                <strong>Reg Number:</strong> <span>${escapeHtml(member.reg_number || '-')}</span>
                                <strong>Email:</strong> <span>${escapeHtml(member.email || '-')}</span>
                                <strong>Phone:</strong> <span>${escapeHtml(member.phone || '-')}</span>
                            </div>
                        `;
                    }
                    
                    const preview = document.getElementById('imagePreview');
                    if (preview) {
                        if (member.photo_url && member.photo_url.trim() !== '') {
                            preview.innerHTML = `<img src="../${member.photo_url}" alt="Preview" style="width: 100%; height: 100%; object-fit: cover;">`;
                            preview.style.display = 'block';
                        } else {
                            preview.innerHTML = '';
                            preview.style.display = 'none';
                        }
                    }
                    
                    const editSubmitBtn = document.getElementById('submitBtn');
                    if (editSubmitBtn) editSubmitBtn.disabled = false;
                    document.getElementById('memberModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading member data');
                });
        }
        
        function closeModal() {
            document.getElementById('memberModal').classList.remove('active');
            const searchContainer = document.querySelector('.search-container');
            const searchSection = document.getElementById('studentSearchSection');
            if (searchContainer) searchContainer.style.display = 'block';
            if (searchSection) searchSection.style.display = 'block';
        }
        
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="width: 100%; height: 100%; object-fit: cover;">`;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.innerHTML = '';
                preview.style.display = 'none';
            }
        }
        
        function toggleAll(source) {
            document.querySelectorAll('.member-checkbox').forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.member-checkbox:checked').length;
            if (!action) { alert('Select an action'); return false; }
            if (checked === 0) { alert('Select members'); return false; }
            let message = '';
            if (action === 'activate') message = `Activate ${checked} member(s)?`;
            else if (action === 'deactivate') message = `Deactivate ${checked} member(s)?`;
            else if (action === 'delete') message = `Delete ${checked} member(s)? This action cannot be undone.`;
            return confirm(message);
        }
        
        window.onclick = function(e) {
            const memberModal = document.getElementById('memberModal');
            if (e.target === memberModal) closeModal();
        };
        
        document.querySelectorAll('.modal-content').forEach(modalContent => {
            modalContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        const cards = document.querySelectorAll('.stat-card, .member-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.animation = `fadeInUp 0.3s ease forwards`;
            card.style.animationDelay = `${index * 0.05}s`;
        });
        
        const style = document.createElement('style');
        style.textContent = `@keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }`;
        document.head.appendChild(style);
        
        setTimeout(() => {
            cards.forEach(card => { card.style.opacity = '1'; });
        }, 100);
    </script>
</body>
</html>