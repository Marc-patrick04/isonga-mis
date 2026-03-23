<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
}

// Get dashboard statistics for sidebar
try {
    // Total tickets - FIXED: Using correct column name
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];
    
    // Open tickets - FIXED: Using correct column name
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];
    
    // Unread messages - FIXED: Using conversation_messages table
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    } catch (PDOException $e) {
        $unread_messages = 0;
    }
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'];
    } catch (PDOException $e) {
        $pending_reports = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];
    } catch (PDOException $e) {
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $unread_messages = $pending_reports = $pending_docs = 0;
}

// Handle actions
$action = $_GET['action'] ?? '';
$student_id = $_GET['id'] ?? '';
$message = '';
$error = '';

// Update Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    try {
        $student_id = $_POST['student_id'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $department_id = $_POST['department_id'] ?? '';
        $program_id = $_POST['program_id'] ?? '';
        $academic_year = $_POST['academic_year'] ?? '';
        $status = $_POST['status'] ?? 'active';
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET full_name = ?, email = ?, phone = ?, department_id = ?, program_id = ?, academic_year = ?, status = ?, updated_at = NOW() 
            WHERE id = ? AND role = 'student'
        ");
        $stmt->execute([$full_name, $email, $phone, $department_id, $program_id, $academic_year, $status, $student_id]);
        
        $message = "Student updated successfully!";
    } catch (PDOException $e) {
        $error = "Error updating student: " . $e->getMessage();
    }
}

// Delete Student
if ($action === 'delete' && $student_id) {
    try {
        // Check if student has any tickets before deleting - FIXED: Using reg_number instead of student_id
        $student_stmt = $pdo->prepare("SELECT reg_number FROM users WHERE id = ?");
        $student_stmt->execute([$student_id]);
        $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            $ticket_check = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE reg_number = ?");
            $ticket_check->execute([$student['reg_number']]);
            $ticket_count = $ticket_check->fetchColumn();
            
            if ($ticket_count > 0) {
                $error = "Cannot delete student. Student has " . $ticket_count . " ticket(s). Archive instead.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
                $stmt->execute([$student_id]);
                $message = "Student deleted successfully!";
            }
        } else {
            $error = "Student not found";
        }
    } catch (PDOException $e) {
        $error = "Error deleting student: " . $e->getMessage();
    }
}

// Reset Password
if ($action === 'reset_password' && $student_id) {
    try {
        $default_password = 'student123';
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'student'");
        $stmt->execute([$default_password, $student_id]);
        $message = "Password reset successfully! New password: " . $default_password;
    } catch (PDOException $e) {
        $error = "Error resetting password: " . $e->getMessage();
    }
}

// Get student data for editing
$student_data = [];
if ($action === 'edit' && $student_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, d.name as department_name, p.name as program_name 
            FROM users u 
            LEFT JOIN departments d ON u.department_id = d.id 
            LEFT JOIN programs p ON u.program_id = p.id 
            WHERE u.id = ? AND u.role = 'student'
        ");
        $stmt->execute([$student_id]);
        $student_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student_data) {
            $error = "Student not found";
            $action = ''; // Reset action if student not found
        }
    } catch (PDOException $e) {
        $error = "Error loading student data: " . $e->getMessage();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$year_filter = $_GET['year'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query for students list - FIXED: Using correct column names for ticket counts
$query = "
    SELECT u.*, d.name as department_name, p.name as program_name,
           (SELECT COUNT(*) FROM tickets t WHERE t.reg_number = u.reg_number) as ticket_count,
           (SELECT COUNT(*) FROM tickets t WHERE t.reg_number = u.reg_number AND t.status = 'resolved') as resolved_tickets
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    LEFT JOIN programs p ON u.program_id = p.id 
    WHERE u.role = 'student'
";

$params = [];
$conditions = [];

// Add filters
if (!empty($search)) {
    $conditions[] = "(u.full_name LIKE ? OR u.reg_number LIKE ? OR u.email LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($department_filter)) {
    $conditions[] = "u.department_id = ?";
    $params[] = $department_filter;
}

if (!empty($year_filter)) {
    $conditions[] = "u.academic_year = ?";
    $params[] = $year_filter;
}

if (!empty($status_filter)) {
    $conditions[] = "u.status = ?";
    $params[] = $status_filter;
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " ORDER BY u.created_at DESC";

// Get students
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error loading students: " . $e->getMessage();
    $students = [];
}

// Get departments and programs for filters and forms
try {
    $departments_stmt = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $programs_stmt = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY name");
    $programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    $programs = [];
}

// Get statistics
try {
    $total_students_stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student'");
    $total_students = $total_students_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $active_students_stmt = $pdo->query("SELECT COUNT(*) as active FROM users WHERE role = 'student' AND status = 'active'");
    $active_students = $active_students_stmt->fetch(PDO::FETCH_ASSOC)['active'];
    
    $new_this_week_stmt = $pdo->query("SELECT COUNT(*) as new_students FROM users WHERE role = 'student' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $new_this_week = $new_this_week_stmt->fetch(PDO::FETCH_ASSOC)['new_students'];
} catch (PDOException $e) {
    $total_students = $active_students = $new_this_week = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    
    <style>
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
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
        }

        .dark-mode {
            --primary-blue: #1e88e5;
            --secondary-blue: #64b5f6;
            --accent-blue: #1565c0;
            --light-blue: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
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

        .container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
            height: 80px;
            display: flex;
            align-items: center;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--primary-blue);
            transform: scale(1.05);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1.1rem;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar */
        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 60px;
            height: calc(100vh - 60px);
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
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
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

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-blue);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border-left: 4px solid var(--primary-blue);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-card .stat-number {
            color: var(--primary-blue);
        }

        .stat-card.success .stat-number {
            color: var(--success);
        }

        .stat-card.warning .stat-number {
            color: var(--warning);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        /* Table */
        .table-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .table-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th {
            background: var(--light-gray);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            border-bottom: 1px solid var(--medium-gray);
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .table tbody tr:hover {
            background: var(--light-gray);
        }

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-suspended {
            background: #fff3cd;
            color: var(--warning);
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-full-width {
            grid-column: 1 / -1;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - General Secretary</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
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
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">General Secretary</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar - GENERAL SECRETARY VERSION -->
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Student Tickets</span>
                        <?php
                        // Get pending tickets count for badge
                        try {
                            $ticketStmt = $pdo->prepare("
                                SELECT COUNT(*) as pending_tickets 
                                FROM tickets 
                                WHERE status IN ('open', 'in_progress') 
                                AND (assigned_to = ? OR assigned_to IS NULL)
                            ");
                            $ticketStmt->execute([$user_id]);
                            $pending_tickets = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'];
                        } catch (PDOException $e) {
                            $pending_tickets = 0;
                        }
                        ?>
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php" class="active">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php 
                        // Get new student registrations count (last 7 days)
                        try {
                            $new_students_stmt = $pdo->prepare("
                                SELECT COUNT(*) as new_students 
                                FROM users 
                                WHERE role = 'student' 
                                AND status = 'active' 
                                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            ");
                            $new_students_stmt->execute();
                            $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'];
                        } catch (PDOException $e) {
                            $new_students = 0;
                        }
                        ?>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                        <?php
                        // Get upcoming meetings count
                        try {
                            $upcoming_meetings = $pdo->query("
                                SELECT COUNT(*) as count FROM meetings 
                                WHERE meeting_date >= CURDATE() AND status = 'scheduled'
                            ")->fetch()['count'];
                        } catch (PDOException $e) {
                            $upcoming_meetings = 0;
                        }
                        ?>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
                        <?php
                        // Count pending minutes (minutes that need to be written/approved)
                        try {
                            $pending_minutes = $pdo->query("
                                SELECT COUNT(*) as count FROM meetings 
                                WHERE status = 'completed' 
                                AND id NOT IN (SELECT meeting_id FROM meeting_minutes WHERE status = 'approved')
                            ")->fetch()['count'];
                        } catch (PDOException $e) {
                            $pending_minutes = 0;
                        }
                        ?>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
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
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Student Management</h1>
            </div>

            <!-- Alerts -->
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?php echo $active_students; ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo $new_this_week; ?></div>
                    <div class="stat-label">New This Week</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_students - $active_students; ?></div>
                    <div class="stat-label">Inactive Students</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, reg number, or email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-control">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Academic Year</label>
                        <select name="year" class="form-control">
                            <option value="">All Years</option>
                            <option value="Year 1" <?php echo $year_filter == 'Year 1' ? 'selected' : ''; ?>>Year 1</option>
                            <option value="Year 2" <?php echo $year_filter == 'Year 2' ? 'selected' : ''; ?>>Year 2</option>
                            <option value="Year 3" <?php echo $year_filter == 'Year 3' ? 'selected' : ''; ?>>Year 3</option>
                            <option value="B-Tech" <?php echo $year_filter == 'B-Tech' ? 'selected' : ''; ?>>B-Tech</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="margin-top: 1.5rem;">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="students.php" class="btn btn-outline" style="margin-top: 1.5rem;">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Students Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">Student List (<?php echo count($students); ?> students)</h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Reg Number</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th>Tickets</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                        <i class="fas fa-user-graduate" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                        <p>No students found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student['reg_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['academic_year']); ?></td>
                                        <td>
                                            <span title="Total: <?php echo $student['ticket_count']; ?>, Resolved: <?php echo $student['resolved_tickets']; ?>">
                                                <?php echo $student['ticket_count']; ?> (<?php echo $student['resolved_tickets']; ?> resolved)
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $student['status']; ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline btn-sm" onclick="editStudent(<?php echo $student['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-outline btn-sm" onclick="resetPassword(<?php echo $student['id']; ?>)">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="deleteStudent(<?php echo $student['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Student Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add New Student</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="studentForm" method="POST">
                    <input type="hidden" name="student_id" id="studentId">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Registration Number *</label>
                            <input type="text" name="reg_number" id="regNumber" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year" id="academicYear" class="form-control">
                                <option value="Year 1">Year 1</option>
                                <option value="Year 2">Year 2</option>
                                <option value="Year 3">Year 3</option>
                                <option value="B-Tech">B-Tech</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full-width">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" id="fullName" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" id="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" id="phone" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Department *</label>
                            <select name="department_id" id="departmentId" class="form-control" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Program</label>
                            <select name="program_id" id="programId" class="form-control">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row" id="statusField" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full-width" style="display: flex; gap: 1rem; margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Student
                            </button>
                            <button type="button" class="btn btn-outline" onclick="closeModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        // Check for saved theme preference or respect OS preference
        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Student';
            document.getElementById('formAction').value = 'add';
            document.getElementById('studentId').value = '';
            document.getElementById('regNumber').value = '';
            document.getElementById('regNumber').readOnly = false;
            document.getElementById('fullName').value = '';
            document.getElementById('email').value = '';
            document.getElementById('phone').value = '';
            document.getElementById('departmentId').value = '';
            document.getElementById('programId').value = '';
            document.getElementById('academicYear').value = 'Year 1';
            document.getElementById('statusField').style.display = 'none';
            document.getElementById('studentModal').classList.add('show');
        }

        function editStudent(studentId) {
            window.location.href = `?action=edit&id=${studentId}`;
        }

        function closeModal() {
            document.getElementById('studentModal').classList.remove('show');
        }

        function resetPassword(studentId) {
            if (confirm('Are you sure you want to reset this student\'s password? The new password will be: student123')) {
                window.location.href = `?action=reset_password&id=${studentId}`;
            }
        }

        function deleteStudent(studentId) {
            if (confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
                window.location.href = `?action=delete&id=${studentId}`;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('studentModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // If we're in edit mode, show the edit form
        <?php if ($action === 'edit' && !empty($student_data)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('modalTitle').textContent = 'Edit Student';
                document.getElementById('formAction').value = 'edit';
                document.getElementById('studentId').value = '<?php echo $student_data["id"]; ?>';
                document.getElementById('regNumber').value = '<?php echo $student_data["reg_number"]; ?>';
                document.getElementById('regNumber').readOnly = true;
                document.getElementById('fullName').value = '<?php echo $student_data["full_name"]; ?>';
                document.getElementById('email').value = '<?php echo $student_data["email"]; ?>';
                document.getElementById('phone').value = '<?php echo $student_data["phone"] ?? ""; ?>';
                document.getElementById('departmentId').value = '<?php echo $student_data["department_id"] ?? ""; ?>';
                document.getElementById('programId').value = '<?php echo $student_data["program_id"] ?? ""; ?>';
                document.getElementById('academicYear').value = '<?php echo $student_data["academic_year"]; ?>';
                document.getElementById('status').value = '<?php echo $student_data["status"]; ?>';
                document.getElementById('statusField').style.display = 'block';
                document.getElementById('studentModal').classList.add('show');
            });
        <?php endif; ?>
    </script>
</body>
</html>