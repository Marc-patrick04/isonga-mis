<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get departments and programs for filters
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

// Handle Representative Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Assign as Class Rep
    if ($_POST['action'] === 'assign') {
        try {
            $student_id = $_POST['student_id'];
            
            // Check if student exists
            $stmt = $pdo->prepare("SELECT id, full_name, is_class_rep FROM users WHERE id = ? AND role = 'student' AND deleted_at IS NULL");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                $error = "Student not found.";
            } elseif ($student['is_class_rep'] == 1) {
                $error = "This student is already a class representative.";
            } else {
                // Assign as class rep
                $stmt = $pdo->prepare("UPDATE users SET is_class_rep = 1 WHERE id = ?");
                $stmt->execute([$student_id]);
                $message = $student['full_name'] . " assigned as Class Representative successfully!";
            }
            header("Location: representative.php" . ($message ? "?msg=" . urlencode($message) : "?error=" . urlencode($error)));
            exit();
        } catch (PDOException $e) {
            $error = "Error assigning class rep: " . $e->getMessage();
            header("Location: representative.php?error=" . urlencode($error));
            exit();
        }
    }
    
    // Remove Class Rep Status
    elseif ($_POST['action'] === 'remove') {
        try {
            $student_id = $_POST['student_id'];
            
            $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("UPDATE users SET is_class_rep = 0 WHERE id = ? AND role = 'student'");
            $stmt->execute([$student_id]);
            $message = ($student['full_name'] ?? 'Student') . " removed from Class Representative status successfully!";
            header("Location: representative.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error removing class rep: " . $e->getMessage();
            header("Location: representative.php?error=" . urlencode($error));
            exit();
        }
    }
    
    // Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'remove') {
                    $stmt = $pdo->prepare("UPDATE users SET is_class_rep = 0 WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students removed from Class Representative status.";
                }
                header("Location: representative.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No students selected.";
        }
    }
}

// Handle Status Toggle (active/inactive)
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $student_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $student_id]);
        
        $message = "Student status updated successfully!";
        header("Location: representative.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling student status: " . $e->getMessage();
    }
}

// Get student for viewing via AJAX
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
        $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$_GET['department_id']]);
        $programs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($programs_list);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get students for search (ALL students that are NOT already class reps)
if (isset($_GET['search_students'])) {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $department_filter = $_GET['department'] ?? '';
    $program_filter = $_GET['program'] ?? '';
    
    // IMPORTANT: Only get students that are NOT already class reps
    $conditions = ["role = 'student'", "deleted_at IS NULL", "is_class_rep = 0"];
    $params = [];
    
    if (!empty($search)) {
        $conditions[] = "(full_name LIKE ? OR reg_number LIKE ? OR email LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($department_filter)) {
        $conditions[] = "department_id = ?";
        $params[] = $department_filter;
    }
    
    if (!empty($program_filter)) {
        $conditions[] = "program_id = ?";
        $params[] = $program_filter;
    }
    
    $where_clause = implode(" AND ", $conditions);
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, reg_number, full_name, email, phone, department_id, program_id, academic_year
            FROM users 
            WHERE $where_clause 
            ORDER BY full_name ASC 
            LIMIT 20
        ");
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($students);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get ALL students for dropdown (for debugging or alternative assignment)
if (isset($_GET['get_all_students'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("
            SELECT id, reg_number, full_name, email, department_id, program_id, is_class_rep
            FROM users 
            WHERE role = 'student' AND deleted_at IS NULL
            ORDER BY full_name ASC
            LIMIT 50
        ");
        $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($all_students);
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
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';
$program_filter = $_GET['program'] ?? '';
$year_filter = $_GET['year'] ?? '';

// Build WHERE clause - only class reps
$where_conditions = ["role = 'student'", "deleted_at IS NULL", "is_class_rep = 1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR reg_number LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
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

if (!empty($year_filter)) {
    $where_conditions[] = "academic_year = ?";
    $params[] = $year_filter;
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

// Get class representatives with joins
try {
    $sql = "
        SELECT u.*, d.name as department_name, p.name as program_name,
               (SELECT COUNT(*) FROM tickets WHERE user_id = u.id AND status = 'resolved') as resolved_tickets,
               (SELECT COUNT(*) FROM tickets WHERE user_id = u.id) as total_tickets
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE $where_clause
        ORDER BY u.created_at DESC
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
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND is_class_rep = 1 AND deleted_at IS NULL");
    $total_class_reps = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND is_class_rep = 1 AND status = 'active' AND deleted_at IS NULL");
    $active_reps = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND is_class_rep = 1 AND status = 'inactive' AND deleted_at IS NULL");
    $inactive_reps = $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT d.name as department, COUNT(u.id) as rep_count 
        FROM users u 
        JOIN departments d ON u.department_id = d.id 
        WHERE u.role = 'student' AND u.is_class_rep = 1 AND u.deleted_at IS NULL 
        GROUP BY d.id 
        ORDER BY rep_count DESC 
        LIMIT 5
    ");
    $department_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available students count (non-reps)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND is_class_rep = 0 AND deleted_at IS NULL AND status = 'active'");
    $available_students_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_class_reps = 0;
    $active_reps = 0;
    $inactive_reps = 0;
    $department_stats = [];
    $available_students_count = 0;
}

// Get available students for assignment (non-reps) - for display
try {
    $stmt = $pdo->query("
        SELECT id, reg_number, full_name, email, department_id, program_id, academic_year
        FROM users 
        WHERE role = 'student' AND is_class_rep = 0 AND deleted_at IS NULL AND status = 'active'
        ORDER BY full_name ASC
        LIMIT 10
    ");
    $sample_available = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sample_available = [];
}

// Get academic years for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE role = 'student' AND is_class_rep = 1 AND academic_year IS NOT NULL ORDER BY academic_year DESC");
    $academic_years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $academic_years = [];
}

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
$message = $message ?? '';
$error = $error ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Representatives - Isonga RPSU Admin</title>
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

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 65px);
        }

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

        .main-content {
            flex: 1;
            padding: 1.2rem;
            overflow-x: auto;
        }

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
            min-width: 120px;
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

        .info-card {
            background: #e3f2fd;
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .reps-table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
        }

        .reps-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .reps-table th,
        .reps-table td {
            padding: 0.6rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .reps-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .reps-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .rep-badge {
            background: #fff3cd;
            color: #856404;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }

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
            max-width: 700px;
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
        .form-group select {
            padding: 0.4rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.75rem;
        }

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

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.2rem;
            padding-top: 0.8rem;
            border-top: 1px solid var(--border);
        }

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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

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

        .department-stats {
            background: white;
            border-radius: var(--border-radius);
            padding: 0.8rem;
            margin-top: 1rem;
        }

        .department-stats h4 {
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .stat-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .stat-item {
            background: var(--light);
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            font-size: 0.7rem;
        }

        .available-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 4px;
            margin-top: 0.5rem;
        }

        .available-item {
            padding: 0.4rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
        }

        .available-item:hover {
            background: var(--light);
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
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="representative.php" class="active"><i class="fas fa-chalkboard-user"></i> Class Reps</a></li>
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
            
            <?php if ($available_students_count == 0 && $total_class_reps > 0): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> All students are already assigned as class representatives. To assign a new rep, please remove an existing one first.
                </div>
            <?php endif; ?>

            <div class="page-header">
                <h1><i class="fas fa-chalkboard-user"></i> Class Representatives</h1>
                <button class="btn btn-primary" onclick="openAssignModal()" <?php echo $available_students_count == 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
                    <i class="fas fa-user-plus"></i> Assign Class Rep
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_class_reps; ?></div>
                    <div class="stat-label">Total Class Reps</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_reps; ?></div>
                    <div class="stat-label">Active Reps</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $inactive_reps; ?></div>
                    <div class="stat-label">Inactive Reps</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $available_students_count; ?></div>
                    <div class="stat-label">Available Students</div>
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
                    <label>Academic Year:</label>
                    <select name="year" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $year_filter == $year ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, reg number, email..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $status_filter || $department_filter || $program_filter || $year_filter): ?>
                        <a href="representative.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Bulk Actions -->
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk">
                <div class="bulk-actions">
                    <select name="bulk_action" id="bulk_action" style="font-size: 0.75rem; padding: 0.3rem;">
                        <option value="">Bulk Actions</option>
                        <option value="remove">Remove as Class Rep</option>
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
                                <th>Department</th>
                                <th>Program</th>
                                <th>Academic Year</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($representatives)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem;">
                                        <?php if ($search || $status_filter || $department_filter || $program_filter || $year_filter): ?>
                                            No class representatives match your filters.
                                        <?php else: ?>
                                            No class representatives found. Click "Assign Class Rep" to add one.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($representatives as $rep): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $rep['id']; ?>" class="rep-checkbox"></td>
                                        <td><?php echo htmlspecialchars($rep['reg_number'] ?? '-'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                            <br><small style="color: var(--gray);"><?php echo htmlspecialchars($rep['username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($rep['email']); ?></td>
                                        <td><?php echo htmlspecialchars($rep['department_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($rep['program_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($rep['academic_year'] ?? '-'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $rep['status']; ?>">
                                                <?php echo ucfirst($rep['status']); ?>
                                            </span>
                                        </td>
                                        <td class="action-buttons" style="white-space: nowrap;">
                                            <button class="btn btn-primary btn-sm" onclick="viewStudent(<?php echo $rep['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="student_id" value="<?php echo $rep['id']; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Remove this student as Class Representative?')">
                                                    <i class="fas fa-user-minus"></i>
                                                </button>
                                            </form>
                                            <a href="?toggle_status=1&id=<?php echo $rep['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Toggle student status?')">
                                                <i class="fas fa-toggle-on"></i>
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
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&year=<?php echo $year_filter; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&year=<?php echo $year_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>&program=<?php echo $program_filter; ?>&year=<?php echo $year_filter; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Department Statistics -->
            <?php if (!empty($department_stats)): ?>
                <div class="department-stats">
                    <h4><i class="fas fa-chart-bar"></i> Representatives by Department</h4>
                    <div class="stat-list">
                        <?php foreach ($department_stats as $stat): ?>
                            <div class="stat-item">
                                <?php echo htmlspecialchars($stat['department']); ?>: <?php echo $stat['rep_count']; ?> rep(s)
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Sample of available students (if any) -->
            <?php if (!empty($sample_available) && $available_students_count > 0): ?>
                <div class="department-stats" style="margin-top: 0.5rem;">
                    <h4><i class="fas fa-users"></i> Available Students (Click to assign)</h4>
                    <div class="available-list">
                        <?php foreach ($sample_available as $student): ?>
                            <div class="available-item" onclick="quickAssign(<?php echo $student['id']; ?>, '<?php echo addslashes($student['full_name']); ?>')">
                                <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                <br><small><?php echo htmlspecialchars($student['reg_number'] ?? 'No reg number'); ?> | <?php echo htmlspecialchars($student['email']); ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($available_students_count > 10): ?>
                            <div class="available-item" style="text-align: center; color: var(--primary);">
                                <i class="fas fa-ellipsis-h"></i> And <?php echo $available_students_count - 10; ?> more students. Use search to find them.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Assign Class Rep Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Assign Class Representative</h2>
                <button class="close-modal" onclick="closeAssignModal()">&times;</button>
            </div>
            <form method="POST" action="" id="assignForm">
                <input type="hidden" name="action" value="assign">
                
                <div class="form-group full-width">
                    <label>Search Student (by name or registration number)</label>
                    <div class="search-container">
                        <input type="text" id="studentSearch" class="search-input" placeholder="Type reg number or name to search..." autocomplete="off">
                        <div id="studentSearchResults" class="student-search-results"></div>
                    </div>
                    <small>Search for students who are NOT already class representatives</small>
                </div>
                
                <div class="form-group full-width">
                    <label>Selected Student</label>
                    <input type="text" id="selectedStudentDisplay" readonly placeholder="No student selected" style="background: #f8f9fa;">
                    <input type="hidden" name="student_id" id="selectedStudentId">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeAssignModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="assignBtn" disabled>Assign as Class Rep</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Student Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-circle"></i> Student Details</h2>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="studentDetails">
                <p style="text-align: center; padding: 2rem;">Loading...</p>
            </div>
        </div>
    </div>

    <script>
        // Quick assign function
        function quickAssign(studentId, studentName) {
            document.getElementById('selectedStudentId').value = studentId;
            document.getElementById('selectedStudentDisplay').value = studentName + " (Selected)";
            document.getElementById('assignBtn').disabled = false;
            openAssignModal();
        }
        
        // Student search for assignment
        let searchTimeout;
        const studentSearch = document.getElementById('studentSearch');
        const searchResults = document.getElementById('studentSearchResults');
        const assignBtn = document.getElementById('assignBtn');
        
        if (studentSearch) {
            studentSearch.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    searchResults.style.display = 'none';
                    assignBtn.disabled = true;
                    document.getElementById('selectedStudentId').value = '';
                    document.getElementById('selectedStudentDisplay').value = '';
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    fetch(`representative.php?search_students=1&search=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(students => {
                            if (students.error) {
                                console.error(students.error);
                                searchResults.innerHTML = '<div class="student-result-item">Error loading students</div>';
                                searchResults.style.display = 'block';
                                return;
                            }
                            
                            if (students.length === 0) {
                                searchResults.innerHTML = '<div class="student-result-item">No students found (they may already be class reps)</div>';
                                searchResults.style.display = 'block';
                                return;
                            }
                            
                            let html = '';
                            students.forEach(student => {
                                html += `
                                    <div class="student-result-item" onclick="selectStudent(${student.id}, '${escapeHtml(student.full_name)}', '${escapeHtml(student.reg_number || '')}', '${escapeHtml(student.email || '')}')">
                                        <div class="student-result-name">${escapeHtml(student.full_name)}</div>
                                        <div class="student-result-reg">${escapeHtml(student.reg_number || 'No reg number')} | ${escapeHtml(student.email || 'No email')}</div>
                                    </div>
                                `;
                            });
                            searchResults.innerHTML = html;
                            searchResults.style.display = 'block';
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            searchResults.innerHTML = '<div class="student-result-item">Error searching students</div>';
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
        
        function selectStudent(id, fullName, regNumber, email) {
            document.getElementById('selectedStudentId').value = id;
            document.getElementById('selectedStudentDisplay').value = `${fullName} (${regNumber}) - ${email}`;
            document.getElementById('studentSearch').value = fullName;
            assignBtn.disabled = false;
            searchResults.style.display = 'none';
        }
        
        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            if (studentSearch && !studentSearch.contains(event.target) && searchResults && !searchResults.contains(event.target)) {
                if (searchResults) searchResults.style.display = 'none';
            }
        });
        
        function openAssignModal() {
            document.getElementById('assignModal').classList.add('active');
            // Don't reset if we came from quick assign
            if (!document.getElementById('selectedStudentId').value) {
                document.getElementById('selectedStudentId').value = '';
                document.getElementById('selectedStudentDisplay').value = '';
                document.getElementById('studentSearch').value = '';
                assignBtn.disabled = true;
            }
        }
        
        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('active');
            document.getElementById('selectedStudentId').value = '';
            document.getElementById('selectedStudentDisplay').value = '';
            document.getElementById('studentSearch').value = '';
            assignBtn.disabled = true;
        }
        
        function viewStudent(studentId) {
            fetch(`representative.php?get_student=1&id=${studentId}`)
                .then(response => response.json())
                .then(student => {
                    if (student.error) {
                        document.getElementById('studentDetails').innerHTML = '<p style="color: red;">Error loading student details</p>';
                        return;
                    }
                    
                    document.getElementById('studentDetails').innerHTML = `
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Registration Number</label>
                                <p><strong>${escapeHtml(student.reg_number || '-')}</strong></p>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <p><strong>${escapeHtml(student.full_name)}</strong></p>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <p>${escapeHtml(student.email)}</p>
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <p>${escapeHtml(student.phone || '-')}</p>
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <p>${escapeHtml(student.department_name || '-')}</p>
                            </div>
                            <div class="form-group">
                                <label>Program</label>
                                <p>${escapeHtml(student.program_name || '-')}</p>
                            </div>
                            <div class="form-group">
                                <label>Academic Year</label>
                                <p>${escapeHtml(student.academic_year || '-')}</p>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <p><span class="status-badge status-${student.status}">${student.status}</span></p>
                            </div>
                            <div class="form-group">
                                <label>Class Representative</label>
                                <p><span class="rep-badge">${student.is_class_rep ? 'Yes' : 'No'}</span></p>
                            </div>
                            <div class="form-group">
                                <label>Last Login</label>
                                <p>${student.last_login ? new Date(student.last_login).toLocaleDateString() : 'Never'}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('viewModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('studentDetails').innerHTML = '<p style="color: red;">Error loading student details</p>';
                });
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }
        
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
            const assignModal = document.getElementById('assignModal');
            const viewModal = document.getElementById('viewModal');
            if (event.target === assignModal) {
                closeAssignModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
        }
    </script>
</body>
</html>