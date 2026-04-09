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

// Handle Representative Actions
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

// Handle Add Representative (set student as class rep)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $student_id = $_POST['student_id'];
            $academic_year = $_POST['academic_year'];
            $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            
            // Check if student exists and is a student
            $stmt = $pdo->prepare("SELECT id, full_name, reg_number FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                throw new Exception("Student not found or is not active.");
            }
            
            // Check if student is already a class rep for this academic year/program
            $checkSql = "SELECT id FROM users WHERE is_class_rep = true AND id = ? AND academic_year = ?";
            $checkParams = [$student_id, $academic_year];
            
            if ($program_id !== null) {
                $checkSql .= " AND program_id = ?";
                $checkParams[] = $program_id;
            } else {
                $checkSql .= " AND program_id IS NULL";
            }
            
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute($checkParams);
            if ($stmt->fetch()) {
                throw new Exception("Student is already a class representative for this academic year/program.");
            }
            
            // Update student as class rep
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_class_rep = true, 
                    academic_year = ?, 
                    program_id = ?, 
                    department_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$academic_year, $program_id, $department_id, $student_id]);
            
            $message = "Student '{$student['full_name']}' has been set as Class Representative!";
            header("Location: representative.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error setting class representative: " . $e->getMessage();
            error_log("Class rep assignment error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Representative
    elseif ($_POST['action'] === 'edit') {
        try {
            $student_id = $_POST['student_id'];
            $academic_year = $_POST['academic_year'];
            $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            
            // Get student details
            $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update representative details
            $stmt = $pdo->prepare("
                UPDATE users 
                SET academic_year = ?, 
                    program_id = ?, 
                    department_id = ?,
                    updated_at = NOW()
                WHERE id = ? AND role = 'student' AND is_class_rep = true
            ");
            $stmt->execute([$academic_year, $program_id, $department_id, $student_id]);
            
            $message = "Class Representative '{$student['full_name']}' has been updated!";
            header("Location: representative.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating class representative: " . $e->getMessage();
            error_log("Class rep update error: " . $e->getMessage());
        }
    }
    
    // Handle Remove Representative
    elseif ($_POST['action'] === 'remove') {
        try {
            $student_id = $_POST['student_id'];
            
            // Get student details before removal
            $stmt = $pdo->prepare("SELECT full_name, reg_number FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Remove class rep status
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_class_rep = false, updated_at = NOW()
                WHERE id = ? AND role = 'student'
            ");
            $stmt->execute([$student_id]);
            
            $message = "Class Representative status removed from '{$student['full_name']}'!";
            header("Location: representative.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error removing class representative: " . $e->getMessage();
            error_log("Class rep removal error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'remove') {
                    // Remove class rep status
                    $stmt = $pdo->prepare("UPDATE users SET is_class_rep = false, updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    $message = count($selected_ids) . " class representatives removed.";
                }
                header("Location: representative.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No representatives selected.";
        }
    }
}

// Get representative for editing via AJAX
if (isset($_GET['get_representative']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.reg_number, u.email, u.phone, u.academic_year, 
                   u.department_id, u.program_id, d.name as department_name, p.name as program_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            WHERE u.id = ? AND u.role = 'student' AND u.is_class_rep = true
        ");
        $stmt->execute([$_GET['id']]);
        $representative = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($representative);
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

// Search students for adding as class rep
if (isset($_GET['search_students'])) {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $program_id = $_GET['program_id'] ?? '';
    $academic_year = $_GET['academic_year'] ?? '';
    
    try {
        $sql = "
            SELECT id, full_name, reg_number, email, academic_year, program_id, department_id
            FROM users 
            WHERE role = 'student' 
            AND status = 'active'
            AND is_class_rep = false
            AND (full_name ILIKE ? OR reg_number ILIKE ? OR email ILIKE ?)
        ";
        $params = ["%$search%", "%$search%", "%$search%"];
        
        if (!empty($program_id)) {
            $sql .= " AND program_id = ?";
            $params[] = $program_id;
        }
        
        if (!empty($academic_year)) {
            $sql .= " AND academic_year = ?";
            $params[] = $academic_year;
        }
        
        $sql .= " ORDER BY full_name ASC LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($students);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$program_filter = $_GET['program'] ?? '';
$academic_year_filter = $_GET['academic_year'] ?? '';

// Build WHERE clause for class representatives
$where_conditions = ["role = 'student'", "status = 'active'", "is_class_rep = true"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name ILIKE ? OR reg_number ILIKE ? OR email ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($department_filter)) {
    $where_conditions[] = "department_id = ?";
    $params[] = $department_filter;
}

if (!empty($program_filter)) {
    $where_conditions[] = "program_id = ?";
    $params[] = $program_filter;
}

if (!empty($academic_year_filter)) {
    $where_conditions[] = "academic_year = ?";
    $params[] = $academic_year_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM users WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_reps = $stmt->fetchColumn();
    $total_pages = ceil($total_reps / $limit);
} catch (PDOException $e) {
    $total_reps = 0;
    $total_pages = 0;
}

// Get class representatives
try {
    $sql = "
        SELECT u.*, d.name as department_name, p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE $where_clause
        ORDER BY u.full_name ASC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $representatives = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $representatives = [];
    error_log("Representatives fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active' AND is_class_rep = true");
    $total_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("
        SELECT academic_year, COUNT(*) as count 
        FROM users 
        WHERE role = 'student' AND status = 'active' AND is_class_rep = true 
        GROUP BY academic_year 
        ORDER BY academic_year
    ");
    $year_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT d.name as department_name, COUNT(*) as count 
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.role = 'student' AND u.status = 'active' AND u.is_class_rep = true 
        GROUP BY d.name 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $total_students = 0;
    $total_reps_count = 0;
    $year_stats = [];
    $dept_stats = [];
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
    <title>Class Representatives - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* All existing CSS styles remain the same */
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
            --secondary: #6b7280;
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

        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

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

        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            font-size: 1.75rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

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

        .reps-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .reps-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .reps-table th,
        .reps-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .reps-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .reps-table tr:hover {
            background: var(--bg-primary);
        }

        .rep-badge {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .stats-item {
            background: var(--bg-primary);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        .stats-item .stats-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .stats-item .stats-value {
            font-size: 1.1rem;
            font-weight: 700;
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

        .search-container {
            position: relative;
        }

        .student-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1001;
            display: none;
            box-shadow: var(--shadow-md);
        }

        .student-result-item {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s;
        }

        .student-result-item:hover {
            background: var(--bg-primary);
        }

        .student-result-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .student-result-reg {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

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
            .reps-table th,
            .reps-table td {
                padding: 0.5rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
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
                <li class="menu-item"><a href="representative.php" class="active"><i class="fas fa-user-check"></i> Class Representatives</a></li>
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
                <h1><i class="fas fa-user-check"></i> Class Representatives</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Assign Representative
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_reps_count; ?></div>
                    <div class="stat-label">Class Representatives</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students > 0 ? round(($total_reps_count / $total_students) * 100, 1) : 0; ?>%</div>
                    <div class="stat-label">Representation Rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($year_stats)); ?></div>
                    <div class="stat-label">Years Covered</div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" action="" class="filters-bar">
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
                    <label>Academic Year:</label>
                    <select name="academic_year" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $academic_year_filter === $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, reg number..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $department_filter || $program_filter || $academic_year_filter): ?>
                        <a href="representative.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Bulk Actions -->
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk">
                <div class="bulk-actions-bar">
                    <select name="bulk_action" id="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="remove">Remove as Representatives</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                </div>

                <!-- Representatives Table -->
                <div class="reps-table-container">
                    <table class="reps-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>Reg Number</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Department</th>
                                <th>Program</th>
                                <th>Academic Year</th>
                                <th>Status</th>
                                <th>Actions</th>
                             </thead>
                        <tbody>
                            <?php if (empty($representatives)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class="fas fa-user-check"></i>
                                            <h3>No class representatives found</h3>
                                            <p>Click "Assign Representative" to assign a class representative.</p>
                                        </div>
                                    </td>
                                 </tr>
                            <?php else: ?>
                                <?php foreach ($representatives as $rep): ?>
                                     <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $rep['id']; ?>" class="rep-checkbox"></td>
                                        <td><strong><?php echo htmlspecialchars($rep['reg_number'] ?? '-'); ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($rep['username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($rep['email']); ?></td>
                                        <td><?php echo htmlspecialchars($rep['phone'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($rep['department_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($rep['program_name'] ?? '-'); ?></td>
                                        <td><span class="rep-badge"><?php echo htmlspecialchars($rep['academic_year'] ?? '-'); ?></span></td>
                                        <td>
                                            <span class="status-badge active">Active</span>
                                        </td>
                                        <td class="action-buttons">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $rep['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="student_id" value="<?php echo $rep['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove this class representative?')">
                                                    <i class="fas fa-user-slash"></i> Remove
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

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&academic_year=<?php echo $academic_year_filter; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&academic_year=<?php echo $academic_year_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&academic_year=<?php echo $academic_year_filter; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics by Year -->
            <?php if (!empty($year_stats)): ?>
                <div style="margin-top: 2rem;">
                    <h3 style="font-size: 1rem; margin-bottom: 1rem;"><i class="fas fa-chart-bar"></i> Distribution by Academic Year</h3>
                    <div class="stats-grid">
                        <?php foreach ($year_stats as $stat): ?>
                            <div class="stats-item">
                                <div class="stats-label"><?php echo htmlspecialchars($stat['academic_year']); ?></div>
                                <div class="stats-value"><?php echo $stat['count']; ?> representatives</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics by Department -->
            <?php if (!empty($dept_stats)): ?>
                <div style="margin-top: 2rem;">
                    <h3 style="font-size: 1rem; margin-bottom: 1rem;"><i class="fas fa-building"></i> Top Departments by Representation</h3>
                    <div class="stats-grid">
                        <?php foreach ($dept_stats as $stat): ?>
                            <div class="stats-item">
                                <div class="stats-label"><?php echo htmlspecialchars($stat['department_name'] ?? 'No Department'); ?></div>
                                <div class="stats-value"><?php echo $stat['count']; ?> representatives</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Representative Modal -->
    <div id="addRepModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2>Assign Class Representative</h2>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" action="" id="addRepForm">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="student_id" id="student_id" value="">
                
                <div class="form-group full-width">
                    <label>Search Student</label>
                    <div class="search-container">
                        <input type="text" id="studentSearch" class="search-input" placeholder="Type reg number or name to search..." autocomplete="off">
                        <div id="studentSearchResults" class="student-search-results"></div>
                    </div>
                    <small>Search for students who are not already class representatives</small>
                </div>
                
                <div id="selectedStudentInfo" style="display: none; background: var(--bg-primary); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <p><strong>Selected Student:</strong> <span id="selectedStudentName"></span></p>
                    <p><strong>Registration:</strong> <span id="selectedStudentReg"></span></p>
                    <p><strong>Email:</strong> <span id="selectedStudentEmail"></span></p>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Academic Year *</label>
                        <select name="academic_year" id="academic_year" required>
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                            <?php endforeach; ?>
                        </select>
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
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign as Representative</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Representative Modal -->
    <div id="editRepModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2>Edit Class Representative</h2>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="" id="editRepForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="student_id" id="edit_student_id" value="">
                
                <div class="form-group full-width">
                    <div id="editStudentInfo" style="background: var(--bg-primary); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <p><strong>Student:</strong> <span id="editStudentName"></span></p>
                        <p><strong>Registration:</strong> <span id="editStudentReg"></span></p>
                        <p><strong>Email:</strong> <span id="editStudentEmail"></span></p>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Academic Year *</label>
                        <select name="academic_year" id="edit_academic_year" required>
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" id="edit_department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" id="edit_program_id">
                            <option value="">Select Program</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Representative</button>
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
        
        // Add Modal functions
        function openAddModal() {
            document.getElementById('addRepModal').classList.add('active');
            document.body.classList.add('modal-open');
            document.getElementById('studentSearch').value = '';
            document.getElementById('selectedStudentInfo').style.display = 'none';
            document.getElementById('student_id').value = '';
            document.getElementById('academic_year').value = '';
            document.getElementById('department_id').value = '';
            document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
        }
        
        function closeAddModal() {
            document.getElementById('addRepModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Edit Modal functions
        function openEditModal(repId) {
            fetch(`representative.php?get_representative=1&id=${repId}`)
                .then(response => response.json())
                .then(rep => {
                    if (rep.error) {
                        alert('Error loading representative data');
                        return;
                    }
                    
                    document.getElementById('edit_student_id').value = rep.id;
                    document.getElementById('editStudentName').textContent = rep.full_name;
                    document.getElementById('editStudentReg').textContent = rep.reg_number || 'N/A';
                    document.getElementById('editStudentEmail').textContent = rep.email || 'N/A';
                    document.getElementById('edit_academic_year').value = rep.academic_year || '';
                    document.getElementById('edit_department_id').value = rep.department_id || '';
                    
                    if (rep.department_id) {
                        loadProgramsForEdit(rep.department_id, rep.program_id);
                    } else {
                        document.getElementById('edit_program_id').innerHTML = '<option value="">Select Program</option>';
                    }
                    
                    document.getElementById('editRepModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading representative data');
                });
        }
        
        function closeEditModal() {
            document.getElementById('editRepModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Load programs for edit modal
        function loadProgramsForEdit(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('edit_program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`representative.php?get_programs=1&department_id=${departmentId}`)
                .then(response => response.json())
                .then(programs => {
                    let options = '<option value="">Select Program</option>';
                    if (!programs.error && programs.length > 0) {
                        programs.forEach(program => {
                            const selected = selectedProgramId == program.id ? 'selected' : '';
                            options += `<option value="${program.id}" ${selected}>${escapeHtml(program.name)}</option>`;
                        });
                    }
                    document.getElementById('edit_program_id').innerHTML = options;
                })
                .catch(error => {
                    console.error('Error loading programs:', error);
                });
        }
        
        // Student search for add modal
        let searchTimeout;
        const studentSearch = document.getElementById('studentSearch');
        const searchResults = document.getElementById('studentSearchResults');
        
        if (studentSearch) {
            studentSearch.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    searchResults.style.display = 'none';
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    fetch(`representative.php?search_students=1&search=${encodeURIComponent(query)}`)
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
                                    <div class="student-result-item" onclick="selectStudent(${student.id}, '${escapeHtml(student.full_name)}', '${escapeHtml(student.reg_number || '')}', '${escapeHtml(student.email || '')}', ${student.department_id || 'null'}, ${student.program_id || 'null'})">
                                        <div class="student-result-name">${escapeHtml(student.full_name)}</div>
                                        <div class="student-result-reg">${escapeHtml(student.reg_number || 'No reg number')} | ${escapeHtml(student.academic_year || 'No year')}</div>
                                    </div>
                                `;
                            });
                            searchResults.innerHTML = html;
                            searchResults.style.display = 'block';
                        });
                }, 300);
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function selectStudent(userId, fullName, regNumber, email, departmentId, programId) {
            document.getElementById('student_id').value = userId;
            document.getElementById('selectedStudentName').textContent = fullName;
            document.getElementById('selectedStudentReg').textContent = regNumber;
            document.getElementById('selectedStudentEmail').textContent = email;
            document.getElementById('selectedStudentInfo').style.display = 'block';
            
            if (departmentId && departmentId !== 'null') {
                document.getElementById('department_id').value = departmentId;
                loadPrograms(departmentId, programId !== 'null' ? programId : null);
            }
            
            studentSearch.value = fullName;
            searchResults.style.display = 'none';
        }
        
        // Load programs for add modal
        function loadPrograms(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`representative.php?get_programs=1&department_id=${departmentId}`)
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
        
        document.getElementById('department_id').addEventListener('change', function() {
            loadPrograms(this.value);
        });
        
        document.getElementById('edit_department_id').addEventListener('change', function() {
            const departmentId = this.value;
            if (departmentId) {
                fetch(`representative.php?get_programs=1&department_id=${departmentId}`)
                    .then(response => response.json())
                    .then(programs => {
                        let options = '<option value="">Select Program</option>';
                        if (!programs.error && programs.length > 0) {
                            programs.forEach(program => {
                                options += `<option value="${program.id}">${escapeHtml(program.name)}</option>`;
                            });
                        }
                        document.getElementById('edit_program_id').innerHTML = options;
                    })
                    .catch(error => {
                        console.error('Error loading programs:', error);
                    });
            } else {
                document.getElementById('edit_program_id').innerHTML = '<option value="">Select Program</option>';
            }
        });
        
        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            if (studentSearch && !studentSearch.contains(event.target) && searchResults && !searchResults.contains(event.target)) {
                if (searchResults) searchResults.style.display = 'none';
            }
        });
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.rep-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.rep-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one representative');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} representative(s)?`);
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const addModal = document.getElementById('addRepModal');
            const editModal = document.getElementById('editRepModal');
            if (event.target === addModal) closeAddModal();
            if (event.target === editModal) closeEditModal();
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