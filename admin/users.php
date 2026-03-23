<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

// Handle user actions (add, edit, delete, status toggle)
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

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Generate username from email or reg_number if not provided
            $username = !empty($_POST['username']) ? $_POST['username'] : explode('@', $_POST['email'])[0];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    reg_number, username, password, role, full_name, email, phone, 
                    date_of_birth, gender, bio, address, emergency_contact_name, 
                    emergency_contact_phone, email_notifications, sms_notifications, 
                    preferred_language, theme_preference, two_factor_enabled, 
                    academic_year, is_class_rep, status, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            
            $stmt->execute([
                $_POST['reg_number'] ?? null,
                $username,
                $password,
                $_POST['role'],
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
                'active',
                $_SESSION['user_id']
            ]);
            
            $message = "User created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating user: " . $e->getMessage();
            error_log("User creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit User
    elseif ($_POST['action'] === 'edit') {
        try {
            $user_id = $_POST['user_id'];
            $updateFields = [];
            $params = [];
            
            // Build dynamic update query
            $allowedFields = [
                'reg_number', 'username', 'role', 'full_name', 'email', 'phone',
                'date_of_birth', 'gender', 'bio', 'address', 'emergency_contact_name',
                'emergency_contact_phone', 'preferred_language', 'theme_preference',
                'academic_year', 'department_id', 'program_id'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field];
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
            
            // Update password if provided
            if (!empty($_POST['password'])) {
                $updateFields[] = "password = ?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $params[] = $user_id;
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "User updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating user: " . $e->getMessage();
            error_log("User update error: " . $e->getMessage());
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
                    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " users activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " users deactivated.";
                } elseif ($bulk_action === 'delete') {
                    $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'deleted' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " users deleted.";
                }
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No users selected.";
        }
    }
}

// Handle status toggle via GET
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $user_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $user_id]);
        
        $message = "User status updated successfully!";
    } catch (PDOException $e) {
        $error = "Error toggling user status: " . $e->getMessage();
    }
}

// Handle delete via GET (soft delete)
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $user_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'deleted' WHERE id = ?");
        $stmt->execute([$user_id]);
        $message = "User deleted successfully!";
    } catch (PDOException $e) {
        $error = "Error deleting user: " . $e->getMessage();
    }
}

// Get user for editing
$edit_user = null;
if (isset($_GET['edit']) && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching user data: " . $e->getMessage();
    }
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';

// Build WHERE clause for filtering
$where_conditions = ["deleted_at IS NULL"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR username LIKE ? OR reg_number LIKE ?)";
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

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
try {
    $count_sql = "SELECT COUNT(*) FROM users WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_users = $stmt->fetchColumn();
    $total_pages = ceil($total_users / $limit);
} catch (PDOException $e) {
    $total_users = 0;
    $total_pages = 0;
    error_log("Count error: " . $e->getMessage());
}

// Get users with pagination
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
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    error_log("User fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY role");
    $role_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $role_stats = [];
    $status_stats = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Isonga RPSU Admin</title>
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
        }

        /* Header */
        .header {
            background: white;
            box-shadow: var(--shadow);
            padding: 1rem 0;
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
            height: 40px;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid var(--border);
            padding: 1.5rem 0;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: #555;
            text-decoration: none;
            transition: var(--transition);
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
            padding: 1.5rem;
            overflow-x: auto;
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
            color: #333;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
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

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.8rem;
        }

        /* Filters */
        .filters-bar {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-box input {
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            width: 250px;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            box-shadow: var(--shadow);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Users Table */
        .users-table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th,
        .users-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.85rem;
            color: #555;
        }

        .users-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
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

        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .role-admin {
            background: #cce5ff;
            color: #004085;
        }

        .role-student {
            background: #d4edda;
            color: #155724;
        }

        .role-committee {
            background: #fff3cd;
            color: #856404;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.8rem;
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
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
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
            font-size: 0.85rem;
            font-weight: 500;
            color: #555;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
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
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            text-decoration: none;
            color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Checkbox */
        .select-all {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .bulk-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
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
                <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
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
                <li class="menu-item"><a href="users.php" class="active"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
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
                <h1><i class="fas fa-users"></i> User Management</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New User
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <!-- <?php foreach ($role_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['role']); ?>s</div>
                    </div>
                <?php endforeach; ?> -->
            </div>

            <!-- Filters -->
            <form method="GET" action="" class="filters-bar">
                <div class="filter-group">
                    <label>Role:</label>
                    <select name="role" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="committee" <?php echo $role_filter === 'committee' ? 'selected' : ''; ?>>Committee</option>
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
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, email, username..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $role_filter || $status_filter): ?>
                        <a href="users.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Bulk Actions -->
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk">
                <div class="bulk-actions">
                    <select name="bulk_action" id="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                </div>

                <!-- Users Table -->
                <div class="users-table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>ID</th>
                                <th>Reg Number</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 2rem;">No users found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $user['id']; ?>" class="user-checkbox"></td>
                                        <td><?php echo $user['id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['reg_number'] ?? '-'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($user['username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="role-badge role-<?php echo $user['role']; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                            <?php if ($user['is_class_rep']): ?>
                                                <br><small class="badge">Class Rep</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['department_name'] ?? '-'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never'; ?>
                                            <br><small><?php echo $user['login_count'] ?? 0; ?> logins</small>
                                        </td>
                                        <td class="action-buttons">
                                            <a href="?edit=1&id=<?php echo $user['id']; ?>" class="action-btn btn-primary btn-sm" onclick="openEditModal(event, <?php echo $user['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?toggle_status=1&id=<?php echo $user['id']; ?>" class="action-btn btn-warning btn-sm" onclick="return confirm('Toggle user status?')">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $user['id']; ?>" class="action-btn btn-danger btn-sm" onclick="return confirm('Delete this user? This action can be undone.')">
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
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New User</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="userForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="user_id" id="userId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" id="reg_number" placeholder="e.g., 2024-001">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" id="username" required>
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
                        <label>Role *</label>
                        <select name="role" id="role" required>
                            <option value="student">Student</option>
                            <option value="committee">Committee Member</option>
                            <option value="admin">Administrator</option>
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
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" id="academic_year" placeholder="e.g., 2024-2025">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" id="password">
                        <small id="passwordHint">Leave blank to keep current password (for edit)</small>
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
                        <label>Preferred Language</label>
                        <select name="preferred_language" id="preferred_language">
                            <option value="en">English</option>
                            <option value="fr">French</option>
                            <option value="rw">Kinyarwanda</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Theme Preference</label>
                        <select name="theme_preference" id="theme_preference">
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="email_notifications" id="email_notifications" value="1">
                            Email Notifications
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="sms_notifications" id="sms_notifications" value="1">
                            SMS Notifications
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="two_factor_enabled" id="two_factor_enabled" value="1">
                            2FA Enabled
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_class_rep" id="is_class_rep" value="1">
                            Class Representative
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('formAction').value = 'add';
            document.getElementById('userId').value = '';
            document.getElementById('userForm').reset();
            document.getElementById('password').required = true;
            document.getElementById('passwordHint').textContent = 'Password is required for new users';
            document.getElementById('userModal').classList.add('active');
        }
        
        function openEditModal(event, userId) {
            event.preventDefault();
            
            // Fetch user data via AJAX
            fetch(`get_user.php?id=${userId}`)
                .then(response => response.json())
                .then(user => {
                    document.getElementById('modalTitle').textContent = 'Edit User';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('userId').value = user.id;
                    document.getElementById('reg_number').value = user.reg_number || '';
                    document.getElementById('username').value = user.username;
                    document.getElementById('full_name').value = user.full_name;
                    document.getElementById('email').value = user.email;
                    document.getElementById('phone').value = user.phone || '';
                    document.getElementById('role').value = user.role;
                    document.getElementById('department_id').value = user.department_id || '';
                    document.getElementById('date_of_birth').value = user.date_of_birth || '';
                    document.getElementById('gender').value = user.gender || '';
                    document.getElementById('academic_year').value = user.academic_year || '';
                    document.getElementById('address').value = user.address || '';
                    document.getElementById('bio').value = user.bio || '';
                    document.getElementById('emergency_contact_name').value = user.emergency_contact_name || '';
                    document.getElementById('emergency_contact_phone').value = user.emergency_contact_phone || '';
                    document.getElementById('preferred_language').value = user.preferred_language || 'en';
                    document.getElementById('theme_preference').value = user.theme_preference || 'light';
                    document.getElementById('email_notifications').checked = user.email_notifications == 1;
                    document.getElementById('sms_notifications').checked = user.sms_notifications == 1;
                    document.getElementById('two_factor_enabled').checked = user.two_factor_enabled == 1;
                    document.getElementById('is_class_rep').checked = user.is_class_rep == 1;
                    document.getElementById('password').required = false;
                    document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
                    
                    // Load programs based on department
                    if (user.department_id) {
                        loadPrograms(user.department_id, user.program_id);
                    }
                    
                    document.getElementById('userModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading user data');
                });
        }
        
        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
        }
        
        // Load programs based on department
        function loadPrograms(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`get_programs.php?department_id=${departmentId}`)
                .then(response => response.json())
                .then(programs => {
                    let options = '<option value="">Select Program</option>';
                    programs.forEach(program => {
                        options += `<option value="${program.id}" ${selectedProgramId == program.id ? 'selected' : ''}>${program.name}</option>`;
                    });
                    document.getElementById('program_id').innerHTML = options;
                });
        }
        
        document.getElementById('department_id').addEventListener('change', function() {
            loadPrograms(this.value);
        });
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.user-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one user');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} user(s)?`);
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('userModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>