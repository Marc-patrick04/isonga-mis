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

// Handle Student Actions
$message = '';
$error = '';

// Get departments and programs for dropdowns
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

// Handle Add Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $username = !empty($_POST['username']) ? trim($_POST['username']) : explode('@', $_POST['email'])[0];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                throw new Exception("Username '$username' already exists.");
            }
            
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$_POST['email']]);
            if ($stmt->fetch()) {
                throw new Exception("Email '{$_POST['email']}' already exists.");
            }
            
            // Check if reg_number already exists
            if (!empty($_POST['reg_number'])) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ? AND deleted_at IS NULL");
                $stmt->execute([$_POST['reg_number']]);
                if ($stmt->fetch()) {
                    throw new Exception("Registration number '{$_POST['reg_number']}' already exists.");
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    reg_number, username, password, role, full_name, email, phone, 
                    date_of_birth, gender, bio, address, emergency_contact_name, 
                    emergency_contact_phone, email_notifications, sms_notifications, 
                    preferred_language, theme_preference, two_factor_enabled, 
                    academic_year, is_class_rep, department_id, program_id, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, 'student', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW()
                )
            ");
            
            $stmt->execute([
                $_POST['reg_number'] ?? null,
                $username,
                $password,
                $_POST['full_name'],
                $_POST['email'],
                $_POST['phone'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['gender'] ?? null,
                $_POST['bio'] ?? null,
                $_POST['address'] ?? null,
                $_POST['emergency_contact_name'] ?? null,
                $_POST['emergency_contact_phone'] ?? null,
                isset($_POST['email_notifications']) ? 1 : 0,
                isset($_POST['sms_notifications']) ? 1 : 0,
                $_POST['preferred_language'] ?? 'en',
                $_POST['theme_preference'] ?? 'light',
                isset($_POST['two_factor_enabled']) ? 1 : 0,
                $_POST['academic_year'] ?? null,
                isset($_POST['is_class_rep']) ? 1 : 0,
                !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                $user_id
            ]);
            
            $message = "Student added successfully!";
            header("Location: students.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding student: " . $e->getMessage();
            error_log("Student creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Student
    elseif ($_POST['action'] === 'edit') {
        try {
            $student_id = $_POST['student_id'];
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'reg_number', 'username', 'full_name', 'email', 'phone',
                'date_of_birth', 'gender', 'bio', 'address', 'emergency_contact_name',
                'emergency_contact_phone', 'preferred_language', 'theme_preference',
                'academic_year', 'department_id', 'program_id'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field] !== '' ? $_POST[$field] : null;
                }
            }
            
            // Handle checkbox fields
            $updateFields[] = "email_notifications = ?";
            $params[] = isset($_POST['email_notifications']) ? 1 : 0;
            
            $updateFields[] = "sms_notifications = ?";
            $params[] = isset($_POST['sms_notifications']) ? 1 : 0;
            
            $updateFields[] = "two_factor_enabled = ?";
            $params[] = isset($_POST['two_factor_enabled']) ? 1 : 0;
            
            $updateFields[] = "is_class_rep = ?";
            $params[] = isset($_POST['is_class_rep']) ? 1 : 0;
            
            $updateFields[] = "status = ?";
            $params[] = $_POST['status'] ?? 'active';
            
            // Update password if provided
            if (!empty($_POST['password'])) {
                $updateFields[] = "password = ?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $student_id;
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ? AND role = 'student'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Student updated successfully!";
            header("Location: students.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating student: " . $e->getMessage();
            error_log("Student update error: " . $e->getMessage());
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
                    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Instead of setting status to 'deleted', set to 'inactive' and set deleted_at timestamp
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', deleted_at = NOW() WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students deleted (marked as inactive).";
                } elseif ($bulk_action === 'set_class_rep') {
                    $stmt = $pdo->prepare("UPDATE users SET is_class_rep = true WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students set as class representatives.";
                } elseif ($bulk_action === 'remove_class_rep') {
                    $stmt = $pdo->prepare("UPDATE users SET is_class_rep = false WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students removed as class representatives.";
                }
                header("Location: students.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No students selected.";
        }
    }
    
    // Handle Import Students
    elseif ($_POST['action'] === 'import') {
        try {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a CSV file to upload.");
            }
            
            $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = fgetcsv($file);
            
            $required_headers = ['reg_number', 'full_name', 'email'];
            foreach ($required_headers as $req) {
                if (!in_array($req, $header)) {
                    throw new Exception("CSV must contain columns: " . implode(', ', $required_headers));
                }
            }
            
            $imported = 0;
            $skipped = 0;
            $errors = [];
            
            while (($row = fgetcsv($file)) !== false) {
                $data = array_combine($header, $row);
                
                // Generate username from email
                $username = explode('@', $data['email'])[0];
                $password = password_hash('password123', PASSWORD_DEFAULT);
                
                // Check if already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ? OR email = ?");
                $stmt->execute([$data['reg_number'], $data['email']]);
                if ($stmt->fetch()) {
                    $skipped++;
                    continue;
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (
                            reg_number, username, password, role, full_name, email, phone,
                            academic_year, department_id, program_id, status, created_by, created_at
                        ) VALUES (
                            ?, ?, ?, 'student', ?, ?, ?, ?, ?, ?, 'active', ?, NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        $data['reg_number'],
                        $username,
                        $password,
                        $data['full_name'],
                        $data['email'],
                        $data['phone'] ?? null,
                        $data['academic_year'] ?? null,
                        !empty($data['department_id']) ? $data['department_id'] : null,
                        !empty($data['program_id']) ? $data['program_id'] : null,
                        $user_id
                    ]);
                    $imported++;
                } catch (Exception $e) {
                    $errors[] = $data['reg_number'] . ': ' . $e->getMessage();
                }
            }
            
            fclose($file);
            
            $message = "Import completed: $imported students imported, $skipped skipped.";
            if (!empty($errors)) {
                $error = "Errors: " . implode(', ', array_slice($errors, 0, 5));
            }
            header("Location: students.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $student_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'student'");
        $stmt->execute([$new_status, $student_id]);
        
        $message = "Student status updated successfully!";
        header("Location: students.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling student status: " . $e->getMessage();
    }
}

// Handle Delete Student
// Handle Delete Student - PERMANENT DELETE
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $student_id = $_GET['id'];
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Check if student has any related records that need to be handled
        // Delete from committee_members if they were a committee member
        $cm_stmt = $pdo->prepare("DELETE FROM committee_members WHERE user_id = ?");
        $cm_stmt->execute([$student_id]);
        
        // Delete the student
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        
        $pdo->commit();
        
        $message = "Student permanently deleted successfully!";
        header("Location: students.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error deleting student: " . $e->getMessage();
        error_log("Student deletion error: " . $e->getMessage());
    }
}

// Get student for editing via AJAX
if (isset($_GET['get_student']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, d.name as department_name, p.name as program_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            WHERE u.id = ? AND u.role = 'student'
        ");
        $stmt->execute([$_GET['id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($student);
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

// Pagination and Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';
$program_filter = $_GET['program'] ?? '';
$class_rep_filter = $_GET['class_rep'] ?? '';

// Build WHERE clause - only show active and inactive, not deleted ones
$where_conditions = ["role = 'student'", "deleted_at IS NULL"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name ILIKE ? OR email ILIKE ? OR reg_number ILIKE ? OR username ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
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

if ($class_rep_filter === 'yes') {
    $where_conditions[] = "is_class_rep = true";
} elseif ($class_rep_filter === 'no') {
    $where_conditions[] = "is_class_rep = false";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM users WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_students = $stmt->fetchColumn();
    $total_pages = ceil($total_students / $limit);
} catch (PDOException $e) {
    $total_students = 0;
    $total_pages = 0;
}

// Get students with pagination
try {
    $sql = "
        SELECT u.*, d.name as department_name, p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE $where_clause
        ORDER BY u.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
    error_log("Students fetch error: " . $e->getMessage());
}

// Get statistics (only count active and inactive, not deleted)
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM users WHERE role = 'student' AND deleted_at IS NULL GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND deleted_at IS NULL AND is_class_rep = true");
    $class_reps_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND deleted_at IS NULL AND DATE(created_at) = CURRENT_DATE");
    $today_added = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND deleted_at IS NULL AND last_login IS NOT NULL AND last_login >= CURRENT_DATE - INTERVAL '30 days'");
    $active_last_30d = $stmt->fetchColumn();
} catch (PDOException $e) {
    $status_stats = [];
    $class_reps_count = 0;
    $today_added = 0;
    $active_last_30d = 0;
}

// Academic year options
$academic_years = ['Year 1', 'Year 2', 'Year 3', 'B-Tech', 'M-Tech'];

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
    <title>Students Management - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* All CSS styles remain the same as in your file */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            
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

        /* Students Table */
        .students-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .students-table th,
        .students-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .students-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .students-table tr:hover {
            background: var(--bg-primary);
        }

        /* Status Badges */
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

        .class-rep-badge {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
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

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
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

        /* Import Section */
        .import-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .import-section h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .import-section p {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .import-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
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
            
            .students-table th,
            .students-table td {
                padding: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .import-form {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <!-- All HTML remains exactly the same as your file -->
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
                <li class="menu-item"><a href="students.php" class="active"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
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

            <div class="page-header">
                <h1><i class="fas fa-user-graduate"></i> Students Management</h1>
                <div>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add Student
                    </button>
                    <button class="btn btn-success" onclick="openImportModal()">
                        <i class="fas fa-file-import"></i> Import CSV
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <?php foreach ($status_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['status']); ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $class_reps_count; ?></div>
                    <div class="stat-label">Class Representatives</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $today_added; ?></div>
                    <div class="stat-label">Added Today</div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" action="" class="filters-bar">
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
                <div class="filter-group">
                    <label>Class Rep:</label>
                    <select name="class_rep" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="yes" <?php echo $class_rep_filter === 'yes' ? 'selected' : ''; ?>>Class Representatives</option>
                        <option value="no" <?php echo $class_rep_filter === 'no' ? 'selected' : ''; ?>>Non-Representatives</option>
                    </select>
                </div>
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, reg number, email..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $status_filter || $department_filter || $program_filter || $class_rep_filter): ?>
                        <a href="students.php" class="btn btn-sm">Clear</a>
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
                        <option value="set_class_rep">Set as Class Representative</option>
                        <option value="remove_class_rep">Remove as Class Representative</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                </div>

                <!-- Students Table -->
                <div class="students-table-container">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>Reg Number</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Program</th>
                                <th>Academic Year</th>
                                <th>Class Rep</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class="fas fa-user-graduate"></i>
                                            <h3>No students found</h3>
                                            <p>Click "Add Student" to create one or import from CSV.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $student['id']; ?>" class="student-checkbox"></td>
                                        <td><?php echo htmlspecialchars($student['reg_number'] ?? '-'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($student['username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo htmlspecialchars($student['department_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($student['program_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($student['academic_year'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($student['is_class_rep']): ?>
                                                <span class="class-rep-badge"><i class="fas fa-star"></i> Class Rep</span>
                                            <?php else: ?>
                                                <span class="status-badge" style="background: rgba(107, 114, 128, 0.1);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $student['status']; ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        </td>
                                        <td class="action-buttons">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle_status=1&id=<?php echo $student['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle student status?')">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $student['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this student?')">
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

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&class_rep=<?php echo $class_rep_filter; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&class_rep=<?php echo $class_rep_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&class_rep=<?php echo $class_rep_filter; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit Student Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle">Add Student</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="studentForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="student_id" id="studentId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Registration Number *</label>
                        <input type="text" name="reg_number" id="reg_number" required placeholder="e.g., 2024-001">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" id="username" placeholder="Leave blank to auto-generate">
                        <small>Auto-generated from email if left blank</small>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" id="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="phone">
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
                        <select name="academic_year" id="academic_year">
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" id="date_of_birth">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" id="gender">
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" id="password">
                        <small id="passwordHint">Password is required for new students</small>
                    </div>
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address" id="address" rows="2"></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Bio</label>
                        <textarea name="bio" id="bio" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_contact_name" id="emergency_contact_name">
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Phone</label>
                        <input type="text" name="emergency_contact_phone" id="emergency_contact_phone">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_class_rep" id="is_class_rep" value="1">
                            Class Representative
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="email_notifications" id="email_notifications" value="1" checked>
                            Email Notifications
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="sms_notifications" id="sms_notifications" value="1">
                            SMS Notifications
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2>Import Students from CSV</h2>
                <button class="close-modal" onclick="closeImportModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <div class="form-group">
                    <label>CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required>
                    <small>File must be CSV format with headers: reg_number, full_name, email, phone (optional), academic_year (optional), department_id (optional), program_id (optional)</small>
                </div>
                <div class="form-group">
                    <label>Sample CSV Format</label>
                    <pre style="background: var(--bg-primary); padding: 0.5rem; border-radius: 6px; font-size: 0.7rem; overflow-x: auto;">
reg_number,full_name,email,phone,academic_year
2024-001,John Doe,john.doe@example.com,0788000000,Year 1
2024-002,Jane Smith,jane.smith@example.com,0788000001,Year 2
                    </pre>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeImportModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Students</button>
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
        
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Student';
            document.getElementById('formAction').value = 'add';
            document.getElementById('studentId').value = '';
            document.getElementById('studentForm').reset();
            document.getElementById('password').required = true;
            document.getElementById('passwordHint').textContent = 'Password is required for new students';
            document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
            document.getElementById('studentModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditModal(studentId) {
            fetch(`students.php?get_student=1&id=${studentId}`)
                .then(response => response.json())
                .then(student => {
                    if (student.error) {
                        alert('Error loading student data');
                        return;
                    }
                    
                    document.getElementById('modalTitle').textContent = 'Edit Student';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('studentId').value = student.id;
                    document.getElementById('reg_number').value = student.reg_number || '';
                    document.getElementById('username').value = student.username;
                    document.getElementById('full_name').value = student.full_name;
                    document.getElementById('email').value = student.email;
                    document.getElementById('phone').value = student.phone || '';
                    document.getElementById('department_id').value = student.department_id || '';
                    document.getElementById('date_of_birth').value = student.date_of_birth || '';
                    document.getElementById('gender').value = student.gender || '';
                    document.getElementById('academic_year').value = student.academic_year || '';
                    document.getElementById('address').value = student.address || '';
                    document.getElementById('bio').value = student.bio || '';
                    document.getElementById('emergency_contact_name').value = student.emergency_contact_name || '';
                    document.getElementById('emergency_contact_phone').value = student.emergency_contact_phone || '';
                    document.getElementById('status').value = student.status;
                    document.getElementById('is_class_rep').checked = student.is_class_rep == 1;
                    document.getElementById('email_notifications').checked = student.email_notifications == 1;
                    document.getElementById('sms_notifications').checked = student.sms_notifications == 1;
                    document.getElementById('password').required = false;
                    document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
                    
                    if (student.department_id) {
                        loadPrograms(student.department_id, student.program_id);
                    } else {
                        document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
                    }
                    
                    document.getElementById('studentModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading student data');
                });
        }
        
        function closeModal() {
            document.getElementById('studentModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function openImportModal() {
            document.getElementById('importModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeImportModal() {
            document.getElementById('importModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function loadPrograms(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`students.php?get_programs=1&department_id=${departmentId}`)
                .then(response => response.json())
                .then(programs => {
                    let options = '<option value="">Select Program</option>';
                    if (!programs.error && programs.length > 0) {
                        programs.forEach(program => {
                            const selected = selectedProgramId == program.id ? 'selected' : '';
                            options += `<option value="${program.id}" ${selected}>${escapeHtml(program.name)}</option>`;
                        });
                    }
                    document.getElementById('program_id').innerHTML = options;
                })
                .catch(error => {
                    console.error('Error loading programs:', error);
                });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        document.getElementById('department_id').addEventListener('change', function() {
            loadPrograms(this.value);
        });
        
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.student-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one student');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} student(s)?`);
        }
        
        window.onclick = function(event) {
            const studentModal = document.getElementById('studentModal');
            const importModal = document.getElementById('importModal');
            if (event.target === studentModal) closeModal();
            if (event.target === importModal) closeImportModal();
        }
        
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>