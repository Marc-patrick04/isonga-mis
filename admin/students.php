<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get departments and programs for filters and dropdowns
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

// Handle Add Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $username = !empty($_POST['username']) ? $_POST['username'] : explode('@', $_POST['email'])[0];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    reg_number, username, password, role, full_name, email, phone,
                    date_of_birth, gender, address, emergency_contact_name,
                    emergency_contact_phone, academic_year, is_class_rep,
                    department_id, program_id, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, 'student', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW()
                )
            ");
            
            $stmt->execute([
                $_POST['reg_number'],
                $username,
                $password,
                $_POST['full_name'],
                $_POST['email'],
                $_POST['phone'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['gender'] ?? null,
                $_POST['address'] ?? null,
                $_POST['emergency_contact_name'] ?? null,
                $_POST['emergency_contact_phone'] ?? null,
                $_POST['academic_year'] ?? null,
                isset($_POST['is_class_rep']) ? 1 : 0,
                !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                $user_id
            ]);
            
            $message = "Student added successfully!";
            header("Location: students.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error adding student: " . $e->getMessage();
        }
    }
    
    // Handle Edit Student
    elseif ($_POST['action'] === 'edit') {
        try {
            $student_id = $_POST['student_id'];
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'reg_number', 'full_name', 'email', 'phone', 'date_of_birth',
                'gender', 'address', 'emergency_contact_name', 'emergency_contact_phone',
                'academic_year', 'department_id', 'program_id'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field] !== '' ? $_POST[$field] : null;
                }
            }
            
            $updateFields[] = "is_class_rep = ?";
            $params[] = isset($_POST['is_class_rep']) ? 1 : 0;
            
            if (!empty($_POST['password'])) {
                $updateFields[] = "password = ?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $params[] = $student_id;
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ? AND role = 'student'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Student updated successfully!";
            header("Location: students.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating student: " . $e->getMessage();
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
                    $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'deleted' WHERE id IN ($placeholders) AND role = 'student'");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " students deleted.";
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
}

// Handle Status Toggle
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
        header("Location: students.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling student status: " . $e->getMessage();
    }
}

// Handle Delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $student_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'deleted' WHERE id = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        $message = "Student deleted successfully!";
        header("Location: students.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting student: " . $e->getMessage();
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
        $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND is_active = 1 ORDER BY name");
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
$year_filter = $_GET['year'] ?? '';

// Build WHERE clause
$where_conditions = ["role = 'student'", "deleted_at IS NULL"];
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
    $total_students = $stmt->fetchColumn();
    $total_pages = ceil($total_students / $limit);
} catch (PDOException $e) {
    $total_students = 0;
    $total_pages = 0;
}

// Get students with joins
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

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND deleted_at IS NULL");
    $total_students_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active' AND deleted_at IS NULL");
    $active_students = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND is_class_rep = 1 AND deleted_at IS NULL");
    $class_reps = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'inactive' AND deleted_at IS NULL");
    $inactive_students = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_students_count = 0;
    $active_students = 0;
    $class_reps = 0;
    $inactive_students = 0;
}

// Get academic years for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE role = 'student' AND academic_year IS NOT NULL AND academic_year != '' ORDER BY academic_year DESC");
    $academic_years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $academic_years = [];
}

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
    <title>Student Management - Isonga RPSU Admin</title>
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

        .btn-warning {
            background: var(--warning);
            color: #333;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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

        .students-table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .students-table th,
        .students-table td {
            padding: 0.6rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .students-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .students-table tr:hover {
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
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
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
                <li class="menu-item"><a href="students.php" class="active"><i class="fas fa-user-graduate"></i> Students</a></li>
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
                <h1><i class="fas fa-user-graduate"></i> Student Management</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add Student
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students_count; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_students; ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $inactive_students; ?></div>
                    <div class="stat-label">Inactive Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $class_reps; ?></div>
                    <div class="stat-label">Class Reps</div>
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
                        <a href="students.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

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

                <!-- Students Table -->
                <div class="students-table-container">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>ID</th>
                                <th>Reg Number</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Program</th>
                                <th>Academic Year</th>
                                <th>Class Rep</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="12" style="text-align: center; padding: 2rem;">No students found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $student['id']; ?>" class="student-checkbox"></td>
                                        <td><?php echo $student['id']; ?></td>
                                        <td><?php echo htmlspecialchars($student['reg_number'] ?? '-'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                            <br><small style="color: var(--gray);"><?php echo htmlspecialchars($student['username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo htmlspecialchars($student['department_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($student['program_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($student['academic_year'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($student['is_class_rep']): ?>
                                                <span class="rep-badge"><i class="fas fa-star"></i> Class Rep</span>
                                            <?php else: ?>
                                                <span style="color: var(--gray);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $student['status']; ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $student['last_login'] ? date('M j, Y', strtotime($student['last_login'])) : 'Never'; ?>
                                            <br><small><?php echo $student['login_count'] ?? 0; ?> logins</small>
                                                                                </td>
                                        <td class="actions">
                                            <button class="btn btn-sm btn-primary" onclick="editStudent(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle_status=1&id=<?php echo $student['id']; ?>" 
                                               class="btn btn-sm btn-warning" 
                                               onclick="return confirm('Toggle student status?')">
                                                <i class="fas fa-toggle-<?php echo $student['status'] === 'active' ? 'off' : 'on'; ?>"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $student['id']; ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Delete this student? This action can be undone.')">
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
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>&program=<?php echo urlencode($program_filter); ?>&year=<?php echo urlencode($year_filter); ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>&program=<?php echo urlencode($program_filter); ?>&year=<?php echo urlencode($year_filter); ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>&program=<?php echo urlencode($program_filter); ?>&year=<?php echo urlencode($year_filter); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Student Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Add New Student</h2>
                <button class="close-modal" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST" action="" id="addStudentForm">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Registration Number *</label>
                        <input type="text" name="reg_number" required placeholder="e.g., 2024-01-001">
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required minlength="6">
                        <small>Min. 6 characters. Username auto-generated from email if not provided.</small>
                    </div>
                    <div class="form-group">
                        <label>Username (optional)</label>
                        <input type="text" name="username" placeholder="Leave blank to auto-generate">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone">
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" id="add_department_id" onchange="loadPrograms('add')">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" id="add_program_id">
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['id']; ?>" data-dept="<?php echo $prog['department_id']; ?>">
                                    <?php echo htmlspecialchars($prog['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="academic_year">
                            <option value="">Select Year</option>
                            <option value="Year 1">Year 1</option>
                            <option value="Year 2">Year 2</option>
                            <option value="Year 3">Year 3</option>
                            <option value="Year 4">Year 4</option>
                            <option value="Year 5">Year 5</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class Representative</label>
                        <label style="flex-direction: row; align-items: center;">
                            <input type="checkbox" name="is_class_rep" value="1"> Yes
                        </label>
                    </div>
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_contact_name">
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Phone</label>
                        <input type="text" name="emergency_contact_phone">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-edit"></i> Edit Student</h2>
                <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST" action="" id="editStudentForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="student_id" id="edit_student_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Registration Number *</label>
                        <input type="text" name="reg_number" id="edit_reg_number" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" id="edit_full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    <div class="form-group">
                        <label>New Password (optional)</label>
                        <input type="password" name="password" placeholder="Leave blank to keep current">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="edit_phone">
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" id="edit_date_of_birth">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" id="edit_gender">
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" id="edit_department_id" onchange="loadPrograms('edit')">
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
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['id']; ?>" data-dept="<?php echo $prog['department_id']; ?>">
                                    <?php echo htmlspecialchars($prog['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="academic_year" id="edit_academic_year">
                            <option value="">Select Year</option>
                            <option value="Year 1">Year 1</option>
                            <option value="Year 2">Year 2</option>
                            <option value="Year 3">Year 3</option>
                            <option value="Year 4">Year 4</option>
                            <option value="Year 5">Year 5</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class Representative</label>
                        <label style="flex-direction: row; align-items: center;">
                            <input type="checkbox" name="is_class_rep" id="edit_is_class_rep" value="1"> Yes
                        </label>
                    </div>
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address" id="edit_address" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_contact_name" id="edit_emergency_contact_name">
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Phone</label>
                        <input type="text" name="emergency_contact_phone" id="edit_emergency_contact_phone">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Student</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Initialize programs filtering on page load
        function filterProgramsByDepartment(selectElement, programSelectId) {
            const departmentId = selectElement.value;
            const programSelect = document.getElementById(programSelectId);
            const options = programSelect.querySelectorAll('option[data-dept]');
            
            // Reset program select
            programSelect.value = '';
            
            // Show/hide programs based on department
            options.forEach(option => {
                const optionDept = option.getAttribute('data-dept');
                if (departmentId === '' || optionDept === departmentId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        }
        
        function loadPrograms(modalType) {
            const departmentSelect = document.getElementById(`${modalType}_department_id`);
            const programSelect = document.getElementById(`${modalType}_program_id`);
            filterProgramsByDepartment(departmentSelect, `${modalType}_program_id`);
        }
        
        // Add event listeners for department changes
        document.addEventListener('DOMContentLoaded', function() {
            const addDept = document.getElementById('add_department_id');
            const editDept = document.getElementById('edit_department_id');
            
            if (addDept) {
                addDept.addEventListener('change', function() {
                    loadPrograms('add');
                });
                // Initial filter
                loadPrograms('add');
            }
            
            if (editDept) {
                editDept.addEventListener('change', function() {
                    loadPrograms('edit');
                });
            }
        });
        
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
            document.getElementById('addStudentForm').reset();
            // Reset program filtering
            if (document.getElementById('add_department_id')) {
                loadPrograms('add');
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function editStudent(studentId) {
            // Fetch student data via AJAX
            fetch(`students.php?get_student=1&id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error loading student data: ' + data.error);
                        return;
                    }
                    
                    // Populate edit form
                    document.getElementById('edit_student_id').value = data.id;
                    document.getElementById('edit_reg_number').value = data.reg_number || '';
                    document.getElementById('edit_full_name').value = data.full_name || '';
                    document.getElementById('edit_email').value = data.email || '';
                    document.getElementById('edit_phone').value = data.phone || '';
                    document.getElementById('edit_date_of_birth').value = data.date_of_birth || '';
                    document.getElementById('edit_gender').value = data.gender || '';
                    document.getElementById('edit_department_id').value = data.department_id || '';
                    document.getElementById('edit_program_id').value = data.program_id || '';
                    document.getElementById('edit_academic_year').value = data.academic_year || '';
                    document.getElementById('edit_address').value = data.address || '';
                    document.getElementById('edit_emergency_contact_name').value = data.emergency_contact_name || '';
                    document.getElementById('edit_emergency_contact_phone').value = data.emergency_contact_phone || '';
                    document.getElementById('edit_is_class_rep').checked = data.is_class_rep == 1;
                    
                    // Load programs based on department
                    if (data.department_id) {
                        loadPrograms('edit');
                    }
                    
                    // Open modal
                    document.getElementById('editModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading student data');
                });
        }
        
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const selected = document.querySelectorAll('.student-checkbox:checked');
            
            if (!action) {
                alert('Please select a bulk action');
                return false;
            }
            
            if (selected.length === 0) {
                alert('Please select at least one student');
                return false;
            }
            
            let message = '';
            if (action === 'activate') message = `Activate ${selected.length} student(s)?`;
            else if (action === 'deactivate') message = `Deactivate ${selected.length} student(s)?`;
            else if (action === 'delete') message = `Delete ${selected.length} student(s)? This can be undone later.`;
            
            return confirm(message);
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Form validation for add student
        document.getElementById('addStudentForm')?.addEventListener('submit', function(e) {
            const regNumber = this.reg_number.value.trim();
            const fullName = this.full_name.value.trim();
            const email = this.email.value.trim();
            const password = this.password.value;
            
            if (!regNumber || !fullName || !email || !password) {
                e.preventDefault();
                alert('Please fill in all required fields (*)');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters');
                return false;
            }
            
            if (!email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
        });
        
        // Form validation for edit student
        document.getElementById('editStudentForm')?.addEventListener('submit', function(e) {
            const regNumber = this.reg_number.value.trim();
            const fullName = this.full_name.value.trim();
            const email = this.email.value.trim();
            
            if (!regNumber || !fullName || !email) {
                e.preventDefault();
                alert('Please fill in all required fields (*)');
                return false;
            }
            
            if (!email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
        });
    </script>
</body>
</html>
