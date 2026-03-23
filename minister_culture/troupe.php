<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_troupe'])) {
        // Add new troupe
        $name = $_POST['name'];
        $type = $_POST['type'];
        $description = $_POST['description'];
        $established_date = $_POST['established_date'];
        $practice_schedule = $_POST['practice_schedule'];
        $practice_location = $_POST['practice_location'];
        $director = $_POST['director'];
        $director_contact = $_POST['director_contact'];
        $achievements = $_POST['achievements'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupes (name, type, description, established_date, 
                practice_schedule, practice_location, director, director_contact, 
                achievements, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $type, $description, $established_date,
                $practice_schedule, $practice_location, $director, $director_contact,
                $achievements, $user_id
            ]);
            
            $success_message = "Troupe created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating troupe: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_member'])) {
        // Add member to troupe
        $troupe_id = $_POST['troupe_id'];
        $reg_number = $_POST['reg_number'];
        $name = $_POST['name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $department_id = $_POST['department_id'];
        $program_id = $_POST['program_id'];
        $academic_year = $_POST['academic_year'];
        $role = $_POST['role'];
        $specialization = $_POST['specialization'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupe_members (troupe_id, reg_number, name, email, phone, 
                department_id, program_id, academic_year, role, specialization, join_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ");
            $stmt->execute([
                $troupe_id, $reg_number, $name, $email, $phone,
                $department_id, $program_id, $academic_year, $role, $specialization
            ]);
            
            // Update troupe members count
            $stmt = $pdo->prepare("UPDATE troupes SET members_count = members_count + 1 WHERE id = ?");
            $stmt->execute([$troupe_id]);
            
            $success_message = "Member added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding member: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_activity'])) {
        // Add troupe activity
        $troupe_id = $_POST['troupe_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $activity_type = $_POST['activity_type'];
        $activity_date = $_POST['activity_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $location = $_POST['location'];
        $budget = $_POST['budget'] ?: 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupe_activities (troupe_id, title, description, activity_type, 
                activity_date, start_time, end_time, location, budget, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $troupe_id, $title, $description, $activity_type,
                $activity_date, $start_time, $end_time, $location, $budget, $user_id
            ]);
            
            $success_message = "Activity added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding activity: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_achievement'])) {
        // Add troupe achievement
        $troupe_id = $_POST['troupe_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $achievement_type = $_POST['achievement_type'];
        $event_name = $_POST['event_name'];
        $event_date = $_POST['event_date'];
        $position = $_POST['position'];
        $prize = $_POST['prize'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO troupe_achievements (troupe_id, title, description, achievement_type, 
                event_name, event_date, position, prize, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $troupe_id, $title, $description, $achievement_type,
                $event_name, $event_date, $position, $prize, $user_id
            ]);
            
            $success_message = "Achievement added successfully!";
        } catch (PDOException $e) {
            $error_message = "Error adding achievement: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_troupe_status'])) {
        // Update troupe status
        $troupe_id = $_POST['troupe_id'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE troupes SET status = ? WHERE id = ?");
            $stmt->execute([$status, $troupe_id]);
            $success_message = "Troupe status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating troupe status: " . $e->getMessage();
        }
    }
}

// Get all troupes
try {
    $stmt = $pdo->query("
        SELECT t.*, COUNT(tm.id) as actual_members_count 
        FROM troupes t 
        LEFT JOIN troupe_members tm ON t.id = tm.troupe_id AND tm.status = 'active'
        GROUP BY t.id 
        ORDER BY t.name
    ");
    $troupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $troupes = [];
    error_log("Error fetching troupes: " . $e->getMessage());
}

// Get departments for dropdowns
try {
    $stmt = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Get programs for dropdowns
try {
    $stmt = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
}

// Get troupe members for a specific troupe (if requested)
$troupe_members = [];
if (isset($_GET['view_members']) && is_numeric($_GET['view_members'])) {
    $troupe_id = $_GET['view_members'];
    try {
        $stmt = $pdo->prepare("
            SELECT tm.*, d.name as department_name, p.name as program_name
            FROM troupe_members tm
            LEFT JOIN departments d ON tm.department_id = d.id
            LEFT JOIN programs p ON tm.program_id = p.id
            WHERE tm.troupe_id = ? AND tm.status = 'active'
            ORDER BY tm.role, tm.name
        ");
        $stmt->execute([$troupe_id]);
        $troupe_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching troupe members: " . $e->getMessage());
    }
}

// Get troupe activities for a specific troupe (if requested)
$troupe_activities = [];
if (isset($_GET['view_activities']) && is_numeric($_GET['view_activities'])) {
    $troupe_id = $_GET['view_activities'];
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM troupe_activities 
            WHERE troupe_id = ? 
            ORDER BY activity_date DESC, start_time DESC
            LIMIT 10
        ");
        $stmt->execute([$troupe_id]);
        $troupe_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching troupe activities: " . $e->getMessage());
    }
}

// Get troupe achievements for a specific troupe (if requested)
$troupe_achievements = [];
if (isset($_GET['view_achievements']) && is_numeric($_GET['view_achievements'])) {
    $troupe_id = $_GET['view_achievements'];
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM troupe_achievements 
            WHERE troupe_id = ? 
            ORDER BY event_date DESC
            LIMIT 10
        ");
        $stmt->execute([$troupe_id]);
        $troupe_achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching troupe achievements: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College Troupe Management - Minister of Culture</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #A78BFA;
            --accent-purple: #7C3AED;
            --light-purple: #f3f0ff;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-purple: #A78BFA;
            --secondary-purple: #C4B5FD;
            --accent-purple: #8B5CF6;
            --light-purple: #1f1a2e;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
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

        .status-badge {
            padding: 0.25rem 0.5rem;
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

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            border-bottom: 2px solid transparent;
        }

        .tab.active {
            color: var(--primary-purple);
            border-bottom-color: var(--primary-purple);
        }

        .tab:hover {
            color: var(--primary-purple);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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
            border-left: 3px solid var(--primary-purple);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: var(--light-purple);
            color: var(--primary-purple);
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

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
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
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
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
                    <h1>Isonga - College Troupe Management</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
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
                        <div class="user-role">Minister of Culture & Civic Education</div>
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
          <!-- Sidebar -->
          <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php" >
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php">
                        <i class="fas fa-palette"></i>
                        <span>Cultural Resources</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="troupe.php" class="active">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
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
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <div class="page-title">
                    <h1>College Troupe Management</h1>
                    <p>Manage college troupes, members, activities, and achievements</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addTroupeModal')">
                        <i class="fas fa-plus"></i> Create New Troupe
                    </button>
                    <button class="btn btn-secondary" onclick="openModal('addMemberModal')">
                        <i class="fas fa-user-plus"></i> Add Member
                    </button>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($troupes); ?></div>
                        <div class="stat-label">Total Troupes</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php 
                            $total_members = 0;
                            foreach ($troupes as $troupe) {
                                $total_members += $troupe['actual_members_count'];
                            }
                            echo $total_members;
                            ?>
                        </div>
                        <div class="stat-label">Total Troupe Members</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php
                            $active_troupes = 0;
                            foreach ($troupes as $troupe) {
                                if ($troupe['status'] === 'active') $active_troupes++;
                            }
                            echo $active_troupes;
                            ?>
                        </div>
                        <div class="stat-label">Active Troupes</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number">
                            <?php
                            // Count upcoming activities (next 7 days)
                            try {
                                $stmt = $pdo->query("
                                    SELECT COUNT(*) as upcoming_count 
                                    FROM troupe_activities 
                                    WHERE activity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                    AND status = 'scheduled'
                                ");
                                $upcoming_count = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_count'] ?? 0;
                                echo $upcoming_count;
                            } catch (PDOException $e) {
                                echo '0';
                            }
                            ?>
                        </div>
                        <div class="stat-label">Upcoming Activities</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('troupes-tab')">All Troupes</button>
                <?php if (isset($_GET['view_members'])): ?>
                    <button class="tab" onclick="openTab('members-tab')">Troupe Members</button>
                <?php endif; ?>
                <?php if (isset($_GET['view_activities'])): ?>
                    <button class="tab" onclick="openTab('activities-tab')">Troupe Activities</button>
                <?php endif; ?>
                <?php if (isset($_GET['view_achievements'])): ?>
                    <button class="tab" onclick="openTab('achievements-tab')">Troupe Achievements</button>
                <?php endif; ?>
            </div>

            <!-- Troupes Tab -->
            <div id="troupes-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>College Troupes</h3>
                        <div class="card-header-actions">
                            <button class="btn btn-secondary" onclick="openModal('addActivityModal')">
                                <i class="fas fa-calendar-plus"></i> Add Activity
                            </button>
                            <button class="btn btn-secondary" onclick="openModal('addAchievementModal')">
                                <i class="fas fa-trophy"></i> Add Achievement
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($troupes)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-music" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No troupes found. Create your first troupe to get started.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Troupe Name</th>
                                        <th>Type</th>
                                        <th>Members</th>
                                        <th>Director</th>
                                        <th>Established</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($troupes as $troupe): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($troupe['name']); ?></strong>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($troupe['description']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-active">
                                                    <?php echo ucfirst($troupe['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $troupe['actual_members_count']; ?></td>
                                            <td><?php echo htmlspecialchars($troupe['director']); ?></td>
                                            <td><?php echo date('M Y', strtotime($troupe['established_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $troupe['status']; ?>">
                                                    <?php echo ucfirst($troupe['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="?view_members=<?php echo $troupe['id']; ?>" class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-users"></i> Members
                                                    </a>
                                                    <a href="?view_activities=<?php echo $troupe['id']; ?>" class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-calendar"></i> Activities
                                                    </a>
                                                    <a href="?view_achievements=<?php echo $troupe['id']; ?>" class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-trophy"></i> Achievements
                                                    </a>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="troupe_id" value="<?php echo $troupe['id']; ?>">
                                                        <select name="status" onchange="this.form.submit()" class="form-control" style="width: auto; display: inline-block; padding: 0.3rem;">
                                                            <option value="active" <?php echo $troupe['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                            <option value="inactive" <?php echo $troupe['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                            <option value="suspended" <?php echo $troupe['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                                        </select>
                                                        <input type="hidden" name="update_troupe_status">
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Members Tab -->
            <?php if (isset($_GET['view_members'])): ?>
                <div id="members-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Troupe Members</h3>
                            <a href="troupe.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Troupes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($troupe_members)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-user-friends" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No members found for this troupe.</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Registration Number</th>
                                            <th>Role</th>
                                            <th>Specialization</th>
                                            <th>Department</th>
                                            <th>Academic Year</th>
                                            <th>Join Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($troupe_members as $member): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($member['name']); ?></td>
                                                <td><?php echo htmlspecialchars($member['reg_number']); ?></td>
                                                <td>
                                                    <span class="status-badge status-active">
                                                        <?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['specialization'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($member['department_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($member['academic_year']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($member['join_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Activities Tab -->
            <?php if (isset($_GET['view_activities'])): ?>
                <div id="activities-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Troupe Activities</h3>
                            <a href="troupe.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Troupes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($troupe_activities)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-calendar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No activities found for this troupe.</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Activity</th>
                                            <th>Type</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Budget</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($troupe_activities as $activity): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                        <?php echo htmlspecialchars($activity['description']); ?>
                                                    </div>
                                                </td>
                                                <td><?php echo ucfirst($activity['activity_type']); ?></td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($activity['activity_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($activity['start_time'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($activity['location']); ?></td>
                                                <td><?php echo number_format($activity['budget'], 2); ?> RWF</td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $activity['status']; ?>">
                                                        <?php echo ucfirst($activity['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Achievements Tab -->
            <?php if (isset($_GET['view_achievements'])): ?>
                <div id="achievements-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Troupe Achievements</h3>
                            <a href="troupe.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Troupes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($troupe_achievements)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No achievements found for this troupe.</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Achievement</th>
                                            <th>Type</th>
                                            <th>Event</th>
                                            <th>Date</th>
                                            <th>Position</th>
                                            <th>Prize</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($troupe_achievements as $achievement): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($achievement['title']); ?></strong>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                        <?php echo htmlspecialchars($achievement['description']); ?>
                                                    </div>
                                                </td>
                                                <td><?php echo ucfirst($achievement['achievement_type']); ?></td>
                                                <td><?php echo htmlspecialchars($achievement['event_name']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($achievement['event_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-active">
                                                        <?php echo htmlspecialchars($achievement['position']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($achievement['prize']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Troupe Modal -->
    <div id="addTroupeModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Create New Troupe</h3>
                <button class="icon-btn" onclick="closeModal('addTroupeModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Troupe Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Troupe Type *</label>
                            <select name="type" class="form-control" required>
                                <option value="dance">Dance</option>
                                <option value="music">Music</option>
                                <option value="drama">Drama</option>
                                <option value="traditional">Traditional</option>
                                <option value="multidisciplinary" selected>Multidisciplinary</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the troupe's focus, style, and purpose..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Established Date</label>
                            <input type="date" name="established_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Practice Location</label>
                            <input type="text" name="practice_location" class="form-control" placeholder="e.g., College Auditorium">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Practice Schedule</label>
                        <textarea name="practice_schedule" class="form-control" rows="2" placeholder="e.g., Monday and Wednesday 4:00 PM - 6:00 PM"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Director</label>
                            <input type="text" name="director" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Director Contact</label>
                            <input type="text" name="director_contact" class="form-control" placeholder="Email or phone">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Achievements</label>
                        <textarea name="achievements" class="form-control" rows="2" placeholder="List notable achievements..."></textarea>
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addTroupeModal')">Cancel</button>
                        <button type="submit" name="add_troupe" class="btn btn-primary">Create Troupe</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Add Member to Troupe</h3>
                <button class="icon-btn" onclick="closeModal('addMemberModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Troupe *</label>
                        <select name="troupe_id" class="form-control" required>
                            <option value="">Select a troupe</option>
                            <?php foreach ($troupes as $troupe): ?>
                                <option value="<?php echo $troupe['id']; ?>"><?php echo htmlspecialchars($troupe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Registration Number *</label>
                            <input type="text" name="reg_number" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-control">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Program</label>
                            <select name="program_id" class="form-control">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year" class="form-control">
                                <option value="Year 1">Year 1</option>
                                <option value="Year 2">Year 2</option>
                                <option value="Year 3">Year 3</option>
                                <option value="B-Tech">B-Tech</option>
                                <option value="M-Tech">M-Tech</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control">
                                <option value="member">Member</option>
                                <option value="lead_performer">Lead Performer</option>
                                <option value="choreographer">Choreographer</option>
                                <option value="musician">Musician</option>
                                <option value="vocalist">Vocalist</option>
                                <option value="director">Director</option>
                                <option value="assistant_director">Assistant Director</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Specialization</label>
                        <input type="text" name="specialization" class="form-control" placeholder="e.g., Traditional Dance, Drums, Acting">
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addMemberModal')">Cancel</button>
                        <button type="submit" name="add_member" class="btn btn-primary">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div id="addActivityModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Add Troupe Activity</h3>
                <button class="icon-btn" onclick="closeModal('addActivityModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Troupe *</label>
                        <select name="troupe_id" class="form-control" required>
                            <option value="">Select a troupe</option>
                            <?php foreach ($troupes as $troupe): ?>
                                <option value="<?php echo $troupe['id']; ?>"><?php echo htmlspecialchars($troupe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Activity Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Activity Type *</label>
                            <select name="activity_type" class="form-control" required>
                                <option value="practice">Practice</option>
                                <option value="competition">Competition</option>
                                <option value="performance">Performance</option>
                                <option value="workshop">Workshop</option>
                                <option value="rehearsal">Rehearsal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Activity Date *</label>
                            <input type="date" name="activity_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Budget (RWF)</label>
                        <input type="number" name="budget" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addActivityModal')">Cancel</button>
                        <button type="submit" name="add_activity" class="btn btn-primary">Add Activity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Achievement Modal -->
    <div id="addAchievementModal" class="modal">
        <div class="modal-content">
            <div class="card-header">
                <h3>Add Troupe Achievement</h3>
                <button class="icon-btn" onclick="closeModal('addAchievementModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Select Troupe *</label>
                        <select name="troupe_id" class="form-control" required>
                            <option value="">Select a troupe</option>
                            <?php foreach ($troupes as $troupe): ?>
                                <option value="<?php echo $troupe['id']; ?>"><?php echo htmlspecialchars($troupe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Achievement Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Achievement Type *</label>
                            <select name="achievement_type" class="form-control" required>
                                <option value="competition">Competition</option>
                                <option value="performance">Performance</option>
                                <option value="award">Award</option>
                                <option value="recognition">Recognition</option>
                                <option value="certification">Certification</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Event Name</label>
                            <input type="text" name="event_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Event Date</label>
                            <input type="date" name="event_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Position/Award</label>
                            <input type="text" name="position" class="form-control" placeholder="e.g., 1st Place, Best Performance">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prize</label>
                            <input type="text" name="prize" class="form-control" placeholder="e.g., Trophy, Certificate, Cash Prize">
                        </div>
                    </div>
                    <div class="form-group" style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addAchievementModal')">Cancel</button>
                        <button type="submit" name="add_achievement" class="btn btn-primary">Add Achievement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

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

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Tab Functions
        function openTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>