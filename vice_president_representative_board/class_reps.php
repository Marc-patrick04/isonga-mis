<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice President of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_president_representative_board') {
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
    error_log("User profile error: " . $e->getMessage());
}

// Handle form actions
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

// Add Class Representative
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class_rep'])) {
    try {
        $student_id = $_POST['student_id'];
        
        // Check if student exists and is active
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception("Student not found or inactive.");
        }
        
        // Check if already a class representative
        if ($student['is_class_rep']) {
            throw new Exception("This student is already a class representative.");
        }
        
        // Update student to class representative (PostgreSQL uses true/false and CURRENT_TIMESTAMP)
        $stmt = $pdo->prepare("UPDATE users SET is_class_rep = true, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$student_id]);
        
        $message = "Class representative added successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Remove Class Representative
if ($action === 'remove' && isset($_GET['id'])) {
    try {
        $rep_id = $_GET['id'];
        
        // Update user to remove class representative status (PostgreSQL uses false)
        $stmt = $pdo->prepare("UPDATE users SET is_class_rep = false, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$rep_id]);
        
        $message = "Class representative removed successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Get all class representatives with their department and program info (PostgreSQL uses true for boolean)
try {
    $stmt = $pdo->query("
        SELECT 
            u.*,
            d.name as department_name,
            p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.is_class_rep = true AND u.status = 'active'
        ORDER BY u.full_name
    ");
    $class_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $total_reps = count($class_reps);
    
    // Get active students who are not class representatives for the add form (PostgreSQL uses false)
    $stmt = $pdo->query("
        SELECT u.id, u.reg_number, u.full_name, u.email, d.name as department_name, p.name as program_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        WHERE u.role = 'student' AND u.status = 'active' AND u.is_class_rep = false
        ORDER BY u.full_name
    ");
    $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $total_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    // Department-wise statistics (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("
        SELECT 
            d.name as department_name,
            COUNT(u.id) as rep_count
        FROM users u
        JOIN departments d ON u.department_id = d.id
        WHERE u.is_class_rep = true AND u.status = 'active'
        GROUP BY d.id, d.name
        ORDER BY rep_count DESC
    ");
    $dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $class_reps = [];
    $available_students = [];
    $total_reps = 0;
    $total_students = 0;
    $total_reps_count = 0;
    $dept_stats = [];
    error_log("Class representatives data error: " . $e->getMessage());
}

// Get dashboard statistics for sidebar (PostgreSQL fixes)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM class_rep_reports WHERE status = 'submitted'");
    $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    
    // PostgreSQL uses CURRENT_DATE instead of CURDATE()
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    // Fix conversation messages query for PostgreSQL
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm 
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id 
        WHERE cp.user_id = ? 
        AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
} catch (PDOException $e) {
    $sidebar_reps_count = 0;
    $pending_reports = 0;
    $upcoming_meetings = 0;
    $unread_messages = 0;
    error_log("Sidebar stats error: " . $e->getMessage());
}

// Ensure variables are defined
$total_reps = $sidebar_reps_count; // For sidebar badge
$total_students = $total_students ?? 0;
$dept_stats = $dept_stats ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Class Representatives - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #007bff;
            --secondary-blue: #0056b3;
            --accent-blue: #0069d9;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
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
            position: relative;
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
            top: 80px;
            height: calc(100vh - 80px);
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

        .menu-divider {
            height: 1px;
            background: var(--medium-gray);
            margin: 1rem 1.5rem;
        }

        .menu-section {
            padding: 0.75rem 1.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--primary-blue);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card .stat-icon {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Department Stats */
        .department-stats {
            display: grid;
            gap: 0.75rem;
        }

        .department-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .department-name {
            font-size: 0.8rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .department-count {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Table Responsive */
        .table-responsive {
            overflow-x: auto;
        }

        /* Overlay for mobile */
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

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none !important;
            }
            
            .sidebar.mobile-open {
                display: flex !important;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }
            
            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .welcome-section h1 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>

    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Representative Board Vice President</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
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
                        <div class="user-role">Vice President - Representative Board</div>
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
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_reps.php" class="active">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Management</span>
                        <?php if ($total_reps > 0): ?>
                            <span class="menu-badge"><?php echo $total_reps; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                
                
                <li class="menu-item">
                    <a href="committee_budget_requests.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-handshake"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
               
                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i> 
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_reps; ?></div>
                        <div class="stat-label">Class Representatives</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $coverage = $total_students > 0 ? round(($total_reps / $total_students) * 100, 1) : 0;
                            echo $coverage; 
                            ?>%
                        </div>
                        <div class="stat-label">Representative Coverage</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($dept_stats); ?></div>
                        <div class="stat-label">Departments Covered</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Add Class Representative Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Add Class Representative</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="form-label" for="student_id">Select Student</label>
                                    <select class="form-control" id="student_id" name="student_id" required>
                                        <option value="">Choose a student...</option>
                                        <?php foreach ($available_students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['full_name']); ?> 
                                                (<?php echo htmlspecialchars($student['reg_number']); ?>)
                                                - <?php echo htmlspecialchars($student['program_name'] ?? 'No Program'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($available_students)): ?>
                                        <small style="color: var(--dark-gray); margin-top: 0.5rem; display: block;">
                                            <i class="fas fa-info-circle"></i> No available students to assign as class representatives.
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" name="add_class_rep" class="btn btn-primary" <?php echo empty($available_students) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-user-plus"></i> Add as Class Representative
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Class Representatives List -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Class Representatives (<?php echo $total_reps; ?>)</h3>
                            <div class="card-header-actions">
                                <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($class_reps)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <h4>No Class Representatives</h4>
                                    <p>No class representatives have been assigned yet. Use the form above to add class representatives.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Registration Number</th>
                                                <th>Program & Year</th>
                                                <th>Department</th>
                                                <th>Last Login</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($class_reps as $rep): ?>
                                                <tr>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                            <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.7rem;">
                                                                <?php if (!empty($rep['avatar_url'])): ?>
                                                                    <img src="../<?php echo htmlspecialchars($rep['avatar_url']); ?>" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                                                <?php else: ?>
                                                                    <?php echo strtoupper(substr($rep['full_name'], 0, 1)); ?>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($rep['full_name']); ?></strong>
                                                                <br>
                                                                <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($rep['email']); ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($rep['reg_number']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($rep['program_name'] ?? 'N/A'); ?>
                                                        <br>
                                                        <small style="color: var(--dark-gray);">Year <?php echo htmlspecialchars($rep['academic_year'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($rep['department_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php if ($rep['last_login']): ?>
                                                            <?php echo date('M j, Y', strtotime($rep['last_login'])); ?>
                                                            <br>
                                                            <small style="color: var(--dark-gray);">
                                                                <?php echo date('g:i A', strtotime($rep['last_login'])); ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span style="color: var(--dark-gray);">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="?action=remove&id=<?php echo $rep['id']; ?>" 
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to remove <?php echo htmlspecialchars($rep['full_name']); ?> as class representative?')">
                                                            <i class="fas fa-user-minus"></i> Remove
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Department Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Representatives by Department</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($dept_stats)): ?>
                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                    <p>No department statistics available</p>
                                </div>
                            <?php else: ?>
                                <div class="department-stats">
                                    <?php foreach ($dept_stats as $dept): ?>
                                        <div class="department-stat">
                                            <span class="department-name"><?php echo htmlspecialchars($dept['department_name']); ?></span>
                                            <span class="department-count"><?php echo $dept['rep_count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <a href="class_rep_meetings.php" class="btn btn-primary">
                                    <i class="fas fa-calendar-alt"></i> Schedule Meeting
                                </a>
                                <a href="class_rep_reports.php" class="btn btn-success">
                                    <i class="fas fa-file-alt"></i> View Reports
                                </a>
                                <a href="messages.php" class="btn btn-info">
                                    <i class="fas fa-envelope"></i> Send Message
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const sidebar = document.getElementById('sidebar');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars"></i>';
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

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .card');
            cards.forEach((card, index) => {
                card.style.animation = `fadeInUp 0.4s ease forwards`;
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 100);
        });
    </script>
</body>
</html>