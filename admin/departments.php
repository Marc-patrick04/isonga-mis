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

// Handle Department and Program Actions
$message = '';
$error = '';

// ==================== DEPARTMENT CRUD OPERATIONS ====================

// Handle Add Department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_department') {
        try {
            $code = trim($_POST['code']);
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? true : false;
            
            // Check if code already exists
            $stmt = $pdo->prepare("SELECT id FROM departments WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetch()) {
                throw new Exception("Department code '$code' already exists.");
            }
            
            // Check if name already exists
            $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception("Department name '$name' already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO departments (code, name, description, is_active, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$code, $name, $description, $is_active]);
            
            $message = "Department '$name' added successfully!";
            header("Location: departments.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding department: " . $e->getMessage();
            error_log("Department creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Department
    elseif ($_POST['action'] === 'edit_department') {
        try {
            $department_id = $_POST['department_id'];
            $code = trim($_POST['code']);
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? true : false;
            
            // Check if code already exists for other department
            $stmt = $pdo->prepare("SELECT id FROM departments WHERE code = ? AND id != ?");
            $stmt->execute([$code, $department_id]);
            if ($stmt->fetch()) {
                throw new Exception("Department code '$code' already exists.");
            }
            
            // Check if name already exists for other department
            $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
            $stmt->execute([$name, $department_id]);
            if ($stmt->fetch()) {
                throw new Exception("Department name '$name' already exists.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE departments 
                SET code = ?, name = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$code, $name, $description, $is_active, $department_id]);
            
            $message = "Department '$name' updated successfully!";
            header("Location: departments.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error updating department: " . $e->getMessage();
        }
    }
    
    // Handle Delete Department
    elseif ($_POST['action'] === 'delete_department') {
        try {
            $department_id = $_POST['department_id'];
            
            // Check if department has programs
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE department_id = ?");
            $stmt->execute([$department_id]);
            $program_count = $stmt->fetchColumn();
            
            if ($program_count > 0) {
                throw new Exception("Cannot delete department with $program_count associated program(s). Delete or reassign programs first.");
            }
            
            // Check if department has committee members
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM committee_members WHERE department_id = ?");
            $stmt->execute([$department_id]);
            $member_count = $stmt->fetchColumn();
            
            if ($member_count > 0) {
                throw new Exception("Cannot delete department with $member_count associated committee member(s).");
            }
            
            // Check if department has users
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
            $stmt->execute([$department_id]);
            $user_count = $stmt->fetchColumn();
            
            if ($user_count > 0) {
                throw new Exception("Cannot delete department with $user_count associated user(s).");
            }
            
            $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
            $stmt->execute([$department_id]);
            
            $message = "Department deleted successfully!";
            header("Location: departments.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error deleting department: " . $e->getMessage();
        }
    }
    
    // ==================== PROGRAM CRUD OPERATIONS ====================
    
    // Handle Add Program
    elseif ($_POST['action'] === 'add_program') {
        try {
            $department_id = $_POST['department_id'];
            $code = trim($_POST['code']);
            $name = trim($_POST['name']);
            $duration_years = (int)($_POST['duration_years'] ?? 3);
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? true : false;
            
            // Check if department exists
            $stmt = $pdo->prepare("SELECT id FROM departments WHERE id = ?");
            $stmt->execute([$department_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Selected department does not exist.");
            }
            
            // Check if code already exists
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetch()) {
                throw new Exception("Program code '$code' already exists.");
            }
            
            // Check if name already exists in same department
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE name = ? AND department_id = ?");
            $stmt->execute([$name, $department_id]);
            if ($stmt->fetch()) {
                throw new Exception("Program '$name' already exists in this department.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO programs (department_id, code, name, duration_years, description, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$department_id, $code, $name, $duration_years, $description, $is_active]);
            
            $message = "Program '$name' added successfully!";
            header("Location: departments.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding program: " . $e->getMessage();
        }
    }
    
    // Handle Edit Program
    elseif ($_POST['action'] === 'edit_program') {
        try {
            $program_id = $_POST['program_id'];
            $department_id = $_POST['department_id'];
            $code = trim($_POST['code']);
            $name = trim($_POST['name']);
            $duration_years = (int)($_POST['duration_years'] ?? 3);
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? true : false;
            
            // Check if code already exists for other program
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE code = ? AND id != ?");
            $stmt->execute([$code, $program_id]);
            if ($stmt->fetch()) {
                throw new Exception("Program code '$code' already exists.");
            }
            
            // Check if name already exists in same department for other program
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE name = ? AND department_id = ? AND id != ?");
            $stmt->execute([$name, $department_id, $program_id]);
            if ($stmt->fetch()) {
                throw new Exception("Program '$name' already exists in this department.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE programs 
                SET department_id = ?, code = ?, name = ?, duration_years = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$department_id, $code, $name, $duration_years, $description, $is_active, $program_id]);
            
            $message = "Program '$name' updated successfully!";
            header("Location: departments.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error updating program: " . $e->getMessage();
        }
    }
    
    // Handle Delete Program
    elseif ($_POST['action'] === 'delete_program') {
        try {
            $program_id = $_POST['program_id'];
            
            // Check if program has committee members
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM committee_members WHERE program_id = ?");
            $stmt->execute([$program_id]);
            $member_count = $stmt->fetchColumn();
            
            if ($member_count > 0) {
                throw new Exception("Cannot delete program with $member_count associated committee member(s).");
            }
            
            // Check if program has users
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE program_id = ?");
            $stmt->execute([$program_id]);
            $user_count = $stmt->fetchColumn();
            
            if ($user_count > 0) {
                throw new Exception("Cannot delete program with $user_count associated user(s).");
            }
            
            // Check if program has tickets
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE program_id = ?");
            $stmt->execute([$program_id]);
            $ticket_count = $stmt->fetchColumn();
            
            if ($ticket_count > 0) {
                throw new Exception("Cannot delete program with $ticket_count associated ticket(s).");
            }
            
            $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$program_id]);
            
            $message = "Program deleted successfully!";
            header("Location: departments.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error deleting program: " . $e->getMessage();
        }
    }
    
    // Handle Bulk Actions for Departments
    elseif ($_POST['action'] === 'bulk_departments') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE departments SET is_active = true WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " departments activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE departments SET is_active = false WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " departments deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Check if any departments have programs
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE department_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $program_count = $stmt->fetchColumn();
                    
                    if ($program_count > 0) {
                        throw new Exception("Cannot delete departments with $program_count associated programs. Delete or reassign programs first.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM departments WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " departments deleted.";
                }
                header("Location: departments.php?msg=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No departments selected.";
        }
    }
    
    // Handle Bulk Actions for Programs
    elseif ($_POST['action'] === 'bulk_programs') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE programs SET is_active = true WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " programs activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE programs SET is_active = false WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " programs deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Check if any programs have committee members
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM committee_members WHERE program_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $member_count = $stmt->fetchColumn();
                    
                    if ($member_count > 0) {
                        throw new Exception("Cannot delete programs with $member_count associated committee members.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM programs WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " programs deleted.";
                }
                header("Location: departments.php?msg=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No programs selected.";
        }
    }
}

// Handle Status Toggle for Department
if (isset($_GET['toggle_dept_status']) && isset($_GET['id'])) {
    $dept_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM departments WHERE id = ?");
        $stmt->execute([$dept_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status == true ? false : true;
        $stmt = $pdo->prepare("UPDATE departments SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_status, $dept_id]);
        
        $message = "Department status updated!";
        header("Location: departments.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling department status: " . $e->getMessage();
    }
}

// Handle Status Toggle for Program
if (isset($_GET['toggle_prog_status']) && isset($_GET['id'])) {
    $prog_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM programs WHERE id = ?");
        $stmt->execute([$prog_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status == true ? false : true;
        $stmt = $pdo->prepare("UPDATE programs SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_status, $prog_id]);
        
        $message = "Program status updated!";
        header("Location: departments.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling program status: " . $e->getMessage();
    }
}

// Get department for editing via AJAX
if (isset($_GET['get_department']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($department);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get program for editing via AJAX
if (isset($_GET['get_program']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, d.name as department_name 
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($program);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// ==================== FETCH DATA ====================

// Get departments with statistics
try {
    $stmt = $pdo->query("
        SELECT d.*, 
               (SELECT COUNT(*) FROM programs WHERE department_id = d.id) as program_count,
               (SELECT COUNT(*) FROM users WHERE department_id = d.id AND role = 'student') as student_count,
               (SELECT COUNT(*) FROM committee_members WHERE department_id = d.id) as committee_count
        FROM departments d
        ORDER BY d.name ASC
    ");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    error_log("Error fetching departments: " . $e->getMessage());
}

// Get programs with department info
try {
    $stmt = $pdo->query("
        SELECT p.*, d.name as department_name, d.code as department_code
        FROM programs p
        LEFT JOIN departments d ON p.department_id = d.id
        ORDER BY d.name ASC, p.name ASC
    ");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
    error_log("Error fetching programs: " . $e->getMessage());
}

// Get statistics
$total_departments = count($departments);
$total_programs = count($programs);
$active_departments = 0;
$active_programs = 0;

foreach ($departments as $dept) {
    if ($dept['is_active']) $active_departments++;
}
foreach ($programs as $prog) {
    if ($prog['is_active']) $active_programs++;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Departments & Programs - Isonga RPSU Admin</title>
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
            --secondary: #6b7280;
            
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

        /* Tables */
        .data-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .data-table tr:hover {
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

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .status-inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            max-width: 600px;
            max-height: 85vh;
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

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.85rem;
            font-family: inherit;
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

        /* Checkbox */
        .select-all, .row-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
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

        /* Prevent body scroll when modal is open */
        body.modal-open {
            overflow: hidden;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .data-table {
                font-size: 0.75rem;
            }
            
            .data-table th,
            .data-table td {
                padding: 0.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
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
                  <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php" class="active"><i class="fas fa-building"></i> Departments</a></li>
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

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_departments; ?></div>
                    <div class="stat-label">Total Departments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_departments; ?></div>
                    <div class="stat-label">Active Departments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_programs; ?></div>
                    <div class="stat-label">Total Programs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_programs; ?></div>
                    <div class="stat-label">Active Programs</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="showTab('departments')">
                    <i class="fas fa-building"></i> Departments
                </button>
                <button class="tab-btn" onclick="showTab('programs')">
                    <i class="fas fa-graduation-cap"></i> Programs
                </button>
            </div>

            <!-- Departments Tab -->
            <div id="departmentsTab" class="tab-pane active">
                <div class="page-header">
                    <h1><i class="fas fa-building"></i> Departments Management</h1>
                    <button class="btn btn-primary" onclick="openAddDepartmentModal()">
                        <i class="fas fa-plus"></i> Add Department
                    </button>
                </div>

                <form method="POST" action="" id="departmentsBulkForm">
                    <input type="hidden" name="action" value="bulk_departments">
                    <div class="bulk-actions-bar">
                        <select name="bulk_action" id="dept_bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk('dept')">Apply</button>
                    </div>

                    <div class="data-table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="select-all" data-group="departments" onclick="toggleAll(this, 'dept')"></th>
                                    <th>Code</th>
                                    <th>Department Name</th>
                                    <th>Description</th>
                                    <th>Programs</th>
                                    <th>Students</th>
                                    <th>Committee</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                 </thead>
                            <tbody>
                                <?php if (empty($departments)): ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i class="fas fa-building"></i>
                                                <h3>No departments found</h3>
                                                <p>Click "Add Department" to create one.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($departments as $dept): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $dept['id']; ?>" class="row-checkbox dept-checkbox"></td>
                                            <td><strong><?php echo htmlspecialchars($dept['code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($dept['name']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($dept['description'] ?? '', 0, 50)); ?></td>
                                            <td><span class="status-badge" style="background: rgba(59, 130, 246, 0.1); color: var(--info);"><?php echo $dept['program_count']; ?> programs</span></td>
                                            <td><?php echo $dept['student_count']; ?></td>
                                            <td><?php echo $dept['committee_count']; ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $dept['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $dept['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="action-buttons">
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openEditDepartmentModal(<?php echo $dept['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?toggle_dept_status=1&id=<?php echo $dept['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle department status?')">
                                                    <i class="fas fa-toggle-on"></i>
                                                </a>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteDepartment(<?php echo $dept['id']; ?>, '<?php echo addslashes($dept['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <!-- Programs Tab -->
            <div id="programsTab" class="tab-pane">
                <div class="page-header">
                    <h1><i class="fas fa-graduation-cap"></i> Programs Management</h1>
                    <button class="btn btn-primary" onclick="openAddProgramModal()">
                        <i class="fas fa-plus"></i> Add Program
                    </button>
                </div>

                <form method="POST" action="" id="programsBulkForm">
                    <input type="hidden" name="action" value="bulk_programs">
                    <div class="bulk-actions-bar">
                        <select name="bulk_action" id="prog_bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk('prog')">Apply</button>
                    </div>

                    <div class="data-table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="select-all" data-group="programs" onclick="toggleAll(this, 'prog')"></th>
                                    <th>Code</th>
                                    <th>Program Name</th>
                                    <th>Department</th>
                                    <th>Duration</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($programs)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="fas fa-graduation-cap"></i>
                                                <h3>No programs found</h3>
                                                <p>Click "Add Program" to create one.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($programs as $prog): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $prog['id']; ?>" class="row-checkbox prog-checkbox"></td>
                                            <td><strong><?php echo htmlspecialchars($prog['code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($prog['name']); ?></td>
                                            <td><?php echo htmlspecialchars($prog['department_name'] ?? '-'); ?></td>
                                            <td><?php echo $prog['duration_years']; ?> year(s)</td>
                                            <td><?php echo htmlspecialchars(substr($prog['description'] ?? '', 0, 40)); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $prog['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $prog['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="action-buttons">
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openEditProgramModal(<?php echo $prog['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?toggle_prog_status=1&id=<?php echo $prog['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle program status?')">
                                                    <i class="fas fa-toggle-on"></i>
                                                </a>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteProgram(<?php echo $prog['id']; ?>, '<?php echo addslashes($prog['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- Add/Edit Department Modal -->
    <div id="departmentModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="deptModalTitle">Add Department</h2>
                <button class="close-modal" onclick="closeDepartmentModal()">&times;</button>
            </div>
            <form method="POST" action="" id="departmentForm">
                <input type="hidden" name="action" id="deptAction" value="add_department">
                <input type="hidden" name="department_id" id="department_id" value="">
                
                <div class="form-group">
                    <label>Department Code *</label>
                    <input type="text" name="code" id="dept_code" required placeholder="e.g., CIT, MEC, BBA">
                </div>
                <div class="form-group">
                    <label>Department Name *</label>
                    <input type="text" name="name" id="dept_name" required placeholder="e.g., Computer and Information Technology">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="dept_description" placeholder="Brief description of the department..."></textarea>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="dept_is_active" value="1" checked>
                        Active
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeDepartmentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Department</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Program Modal -->
    <div id="programModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="progModalTitle">Add Program</h2>
                <button class="close-modal" onclick="closeProgramModal()">&times;</button>
            </div>
            <form method="POST" action="" id="programForm">
                <input type="hidden" name="action" id="progAction" value="add_program">
                <input type="hidden" name="program_id" id="program_id" value="">
                
                <div class="form-group">
                    <label>Department *</label>
                    <select name="department_id" id="prog_department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Program Code *</label>
                    <input type="text" name="code" id="prog_code" required placeholder="e.g., CIT1, MEC1, BBA1">
                </div>
                <div class="form-group">
                    <label>Program Name *</label>
                    <input type="text" name="name" id="prog_name" required placeholder="e.g., Computer Science, Mechanical Engineering">
                </div>
                <div class="form-group">
                    <label>Duration (Years)</label>
                    <select name="duration_years" id="prog_duration_years">
                        <option value="1">1 Year</option>
                        <option value="2">2 Years</option>
                        <option value="3" selected>3 Years</option>
                        <option value="4">4 Years</option>
                        <option value="5">5 Years</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="prog_description" placeholder="Brief description of the program..."></textarea>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="prog_is_active" value="1" checked>
                        Active
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeProgramModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Program</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Form -->
    <form id="deleteForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="">
        <input type="hidden" name="department_id" id="delete_dept_id" value="">
        <input type="hidden" name="program_id" id="delete_prog_id" value="">
    </form>

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
        function showTab(tab) {
            const deptTab = document.getElementById('departmentsTab');
            const progTab = document.getElementById('programsTab');
            const tabs = document.querySelectorAll('.tab-btn');
            
            if (tab === 'departments') {
                deptTab.classList.add('active');
                progTab.classList.remove('active');
                tabs[0].classList.add('active');
                tabs[1].classList.remove('active');
            } else {
                deptTab.classList.remove('active');
                progTab.classList.add('active');
                tabs[0].classList.remove('active');
                tabs[1].classList.add('active');
            }
        }
        
        // Department Modal functions
        function openAddDepartmentModal() {
            document.getElementById('deptModalTitle').textContent = 'Add Department';
            document.getElementById('deptAction').value = 'add_department';
            document.getElementById('department_id').value = '';
            document.getElementById('dept_code').value = '';
            document.getElementById('dept_name').value = '';
            document.getElementById('dept_description').value = '';
            document.getElementById('dept_is_active').checked = true;
            document.getElementById('departmentModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditDepartmentModal(deptId) {
            fetch(`departments.php?get_department=1&id=${deptId}`)
                .then(response => response.json())
                .then(dept => {
                    if (dept.error) {
                        alert('Error loading department data');
                        return;
                    }
                    document.getElementById('deptModalTitle').textContent = 'Edit Department';
                    document.getElementById('deptAction').value = 'edit_department';
                    document.getElementById('department_id').value = dept.id;
                    document.getElementById('dept_code').value = dept.code;
                    document.getElementById('dept_name').value = dept.name;
                    document.getElementById('dept_description').value = dept.description || '';
                    document.getElementById('dept_is_active').checked = dept.is_active == true;
                    document.getElementById('departmentModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading department data');
                });
        }
        
        function closeDepartmentModal() {
            document.getElementById('departmentModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function confirmDeleteDepartment(deptId, deptName) {
            if (confirm(`Are you sure you want to delete department "${deptName}"? This will also delete all associated programs.`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete_department';
                form.querySelector('[name="department_id"]').value = deptId;
                form.submit();
            }
        }
        
        // Program Modal functions
        function openAddProgramModal() {
            document.getElementById('progModalTitle').textContent = 'Add Program';
            document.getElementById('progAction').value = 'add_program';
            document.getElementById('program_id').value = '';
            document.getElementById('prog_department_id').value = '';
            document.getElementById('prog_code').value = '';
            document.getElementById('prog_name').value = '';
            document.getElementById('prog_duration_years').value = '3';
            document.getElementById('prog_description').value = '';
            document.getElementById('prog_is_active').checked = true;
            document.getElementById('programModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditProgramModal(progId) {
            fetch(`departments.php?get_program=1&id=${progId}`)
                .then(response => response.json())
                .then(prog => {
                    if (prog.error) {
                        alert('Error loading program data');
                        return;
                    }
                    document.getElementById('progModalTitle').textContent = 'Edit Program';
                    document.getElementById('progAction').value = 'edit_program';
                    document.getElementById('program_id').value = prog.id;
                    document.getElementById('prog_department_id').value = prog.department_id;
                    document.getElementById('prog_code').value = prog.code;
                    document.getElementById('prog_name').value = prog.name;
                    document.getElementById('prog_duration_years').value = prog.duration_years;
                    document.getElementById('prog_description').value = prog.description || '';
                    document.getElementById('prog_is_active').checked = prog.is_active == true;
                    document.getElementById('programModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading program data');
                });
        }
        
        function closeProgramModal() {
            document.getElementById('programModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function confirmDeleteProgram(progId, progName) {
            if (confirm(`Are you sure you want to delete program "${progName}"?`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete_program';
                form.querySelector('[name="program_id"]').value = progId;
                form.submit();
            }
        }
        
        // Bulk actions
        function toggleAll(source, type) {
            const checkboxes = document.querySelectorAll(`.${type}-checkbox`);
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk(type) {
            const action = document.getElementById(`${type}_bulk_action`).value;
            const checked = document.querySelectorAll(`.${type}-checkbox:checked`).length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert(`Please select at least one ${type === 'dept' ? 'department' : 'program'}`);
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} ${type === 'dept' ? 'department(s)' : 'program(s)'}?`);
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const deptModal = document.getElementById('departmentModal');
            const progModal = document.getElementById('programModal');
            
            if (event.target === deptModal) {
                closeDepartmentModal();
            }
            if (event.target === progModal) {
                closeProgramModal();
            }
        }
        
        // Initialize select-all checkboxes
        document.querySelectorAll('.select-all').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const group = this.dataset.group;
                const checkboxes = document.querySelectorAll(`.${group}-checkbox`);
                checkboxes.forEach(cb => cb.checked = this.checked);
            });
        });
        
        // Prevent modal content click from bubbling to backdrop
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>