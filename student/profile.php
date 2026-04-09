<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: profile');
    exit();
}

// Get complete user data
$user_stmt = $pdo->prepare("
    SELECT u.*, d.name as department_name, p.name as program_name
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE u.id = ?
");
$user_stmt->execute([$student_id]);
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Get ticket statistics
$ticket_stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_tickets
    FROM tickets 
    WHERE reg_number = ?
");
$ticket_stats_stmt->execute([$reg_number]);
$ticket_stats = $ticket_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$student_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
    $date_of_birth = isset($_POST['date_of_birth']) && !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $gender = isset($_POST['gender']) && !empty($_POST['gender']) ? $_POST['gender'] : null;
    $bio = isset($_POST['bio']) && !empty($_POST['bio']) ? trim($_POST['bio']) : null;
    $address = isset($_POST['address']) && !empty($_POST['address']) ? trim($_POST['address']) : null;
    $emergency_contact_name = isset($_POST['emergency_contact_name']) && !empty($_POST['emergency_contact_name']) ? trim($_POST['emergency_contact_name']) : null;
    $emergency_contact_phone = isset($_POST['emergency_contact_phone']) && !empty($_POST['emergency_contact_phone']) ? trim($_POST['emergency_contact_phone']) : null;

    // Validate required fields
    if (empty($full_name) || empty($email)) {
        $error_message = "Full name and email are required fields.";
    } else {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE users SET 
                    full_name = ?, email = ?, phone = ?, date_of_birth = ?, gender = ?, 
                    bio = ?, address = ?, emergency_contact_name = ?, emergency_contact_phone = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $update_stmt->execute([
                $full_name, $email, $phone, $date_of_birth, $gender, $bio, $address,
                $emergency_contact_name, $emergency_contact_phone, $student_id
            ]);
            
            // Update session data
            $_SESSION['full_name'] = $full_name;
            
            $_SESSION['success_message'] = "Profile updated successfully!";
            header('Location: profile');
            exit();
            
        } catch (PDOException $e) {
            $error_message = "Failed to update profile. Please try again.";
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $password_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $password_stmt->execute([$student_id]);
    $user = $password_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $current_password === $user['password']) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                try {
                    $update_password_stmt = $pdo->prepare("
                        UPDATE users SET password = ?, last_password_change = NOW() WHERE id = ?
                    ");
                    $update_password_stmt->execute([$new_password, $student_id]);
                    
                    $_SESSION['success_message'] = "Password changed successfully!";
                    header('Location: profile');
                    exit();
                    
                } catch (PDOException $e) {
                    $password_error = "Failed to change password. Please try again.";
                }
            } else {
                $password_error = "New password must be at least 6 characters long.";
            }
        } else {
            $password_error = "New passwords do not match.";
        }
    } else {
        $password_error = "Current password is incorrect.";
    }
}

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Profile - Isonga RPSU</title>
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

        [data-theme="dark"] {
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
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

        /* Header */
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

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
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

        /* Main Content */
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

        /* Profile Layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            position: sticky;
            top: 1.5rem;
            height: fit-content;
        }

        .profile-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .profile-header {
            background: var(--gradient-primary);
            padding: 2rem;
            text-align: center;
            color: white;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            border: 3px solid white;
        }

        .profile-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .profile-meta {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .profile-body {
            padding: 1.5rem;
        }

        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 0.85rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .stat-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
            display: block;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        /* Tabs Navigation */
        .tabs-nav {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 0.5rem;
            display: flex;
            gap: 0.5rem;
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            padding: 0.75rem 1.25rem;
            border: none;
            background: transparent;
            color: var(--text-dark);
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            border-radius: var(--border-radius);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            background: var(--light-gray);
        }

        .tab-btn.active {
            background: var(--primary-blue);
            color: white;
        }

        .tab-btn i {
            font-size: 0.85rem;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Card */
        .form-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--primary-blue);
        }

        .form-section {
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--text-dark);
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
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-control:disabled {
            background: var(--light-gray);
            color: var(--dark-gray);
            cursor: not-allowed;
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        /* Alerts */
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Password Requirements */
        .password-requirements {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .password-requirements h4 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .password-requirements ul {
            list-style: none;
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .password-requirements li {
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Activity Card */
        .activity-card {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-label {
            font-weight: 500;
            font-size: 0.85rem;
        }

        .activity-value {
            color: var(--dark-gray);
            font-size: 0.85rem;
        }

        /* Overlay */
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

        /* Responsive */
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

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .main-content.sidebar-collapsed {
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

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .profile-layout {
                grid-template-columns: 1fr;
            }

            .profile-sidebar {
                position: static;
            }

            .tabs-nav {
                flex-wrap: wrap;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
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

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
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
                <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo">
                <div class="brand-text">
                    <h1>Isonga RPSU</h1>
                </div>
            </div>
            <div class="user-menu">
               
                <a href="messages" class="icon-btn" title="Messages" style="position: relative;">
                    <i class="fas fa-envelope"></i>
                    <?php if ($unread_messages > 0): ?>
                        <span class="notification-badge"><?php echo $unread_messages; ?></span>
                    <?php endif; ?>
                </a>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></div>
                        <div class="user-role">Student</div>
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
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
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
                        <span>My Tickets</span>
                        <?php if (($ticket_stats['open_tickets'] ?? 0) > 0): ?>
                            <span class="menu-badge"><?php echo $ticket_stats['open_tickets']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_aid.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
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
                    <a href="profile.php" class="active">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
                <?php if ($is_class_rep): ?>
                <li class="menu-item">
                    <a href="class_rep_dashboard.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Dashboard</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>My Profile</h1>
                    <p>Manage your personal information and settings</p>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Profile Layout -->
            <div class="profile-layout">
                <!-- Profile Sidebar -->
                <div class="profile-sidebar">
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <h2 class="profile-name"><?php echo safe_display($user_data['full_name']); ?></h2>
                            <div class="profile-meta"><?php echo safe_display($user_data['reg_number']); ?></div>
                        </div>
                        
                        <div class="profile-body">
                            <div class="profile-stats">
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo $ticket_stats['total_tickets'] ?? 0; ?></span>
                                    <span class="stat-label">Total Tickets</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-number"><?php echo ($ticket_stats['resolved_tickets'] ?? 0) + ($ticket_stats['closed_tickets'] ?? 0); ?></span>
                                    <span class="stat-label">Resolved</span>
                                </div>
                            </div>
                            
                            <div class="profile-info" style="margin-top: 1rem;">
                                <div class="info-item">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?php echo safe_display($user_data['email']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo safe_display($user_data['phone'] ?: 'Not set'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Program</span>
                                    <span class="info-value"><?php echo safe_display($user_data['program_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Year</span>
                                    <span class="info-value">Year <?php echo safe_display($user_data['academic_year']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Content -->
                <div class="profile-content">
                    <!-- Tabs Navigation -->
                    <div class="tabs-nav">
                        <button class="tab-btn active" onclick="switchTab('personal')">
                            <i class="fas fa-user-circle"></i>
                            Personal Info
                        </button>
                        <button class="tab-btn" onclick="switchTab('security')">
                            <i class="fas fa-shield-alt"></i>
                            Security
                        </button>
                    </div>

                    <!-- Personal Info Tab -->
                    <div id="personal-tab" class="tab-content active">
                        <div class="form-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-user-edit"></i>
                                    Personal Information
                                </h3>
                            </div>
                            
                            <form method="POST" id="personalForm">
                                <div class="form-section">
                                    <h4 class="section-title">Basic Information</h4>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label" for="full_name">Full Name *</label>
                                            <input type="text" id="full_name" name="full_name" class="form-control" 
                                                   value="<?php echo safe_display($user_data['full_name']); ?>" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="email">Email Address *</label>
                                            <input type="email" id="email" name="email" class="form-control" 
                                                   value="<?php echo safe_display($user_data['email']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="phone">Phone Number</label>
                                            <input type="tel" id="phone" name="phone" class="form-control" 
                                                   value="<?php echo safe_display($user_data['phone']); ?>" 
                                                   placeholder="+250 78X XXX XXX">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="date_of_birth">Date of Birth</label>
                                            <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" 
                                                   value="<?php echo safe_display($user_data['date_of_birth']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="gender">Gender</label>
                                            <select id="gender" name="gender" class="form-control" disabled>
                                                <option value="">Select Gender</option>
                                                <option value="male" <?php echo ($user_data['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                                <option value="female" <?php echo ($user_data['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="reg_number">Registration Number</label>
                                            <input type="text" id="reg_number" class="form-control" 
                                                   value="<?php echo safe_display($user_data['reg_number']); ?>" disabled>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4 class="section-title">Academic Information</h4>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Program</label>
                                            <input type="text" class="form-control" 
                                                   value="<?php echo safe_display($user_data['program_name']); ?>" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Department</label>
                                            <input type="text" class="form-control" 
                                                   value="<?php echo safe_display($user_data['department_name']); ?>" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Year of Study</label>
                                            <input type="text" class="form-control" 
                                                   value="Year <?php echo safe_display($user_data['academic_year']); ?>" disabled>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4 class="section-title">Additional Information</h4>
                                    <div class="form-grid">
                                        <div class="form-group" style="grid-column: 1 / -1;">
                                            <label class="form-label" for="bio">Bio</label>
                                            <textarea id="bio" name="bio" class="form-control" rows="3" 
                                                      placeholder="Tell us about yourself..."><?php echo safe_display($user_data['bio']); ?></textarea>
                                        </div>
                                        <div class="form-group" style="grid-column: 1 / -1;">
                                            <label class="form-label" for="address">Address</label>
                                            <textarea id="address" name="address" class="form-control" rows="3" 
                                                      placeholder="Your current address..."><?php echo safe_display($user_data['address']); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4 class="section-title">Emergency Contact</h4>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label" for="emergency_contact_name">Contact Name</label>
                                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control" 
                                                   value="<?php echo safe_display($user_data['emergency_contact_name']); ?>" 
                                                   placeholder="Full name of emergency contact">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="emergency_contact_phone">Contact Phone</label>
                                            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control" 
                                                   value="<?php echo safe_display($user_data['emergency_contact_phone']); ?>" 
                                                   placeholder="+250 78X XXX XXX">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="button" class="btn btn-secondary" onclick="resetForm('personalForm')">
                                        <i class="fas fa-redo"></i>
                                        Reset
                                    </button>
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div id="security-tab" class="tab-content">
                        <div class="form-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-shield-alt"></i>
                                    Security Settings
                                </h3>
                            </div>
                            
                            <?php if (isset($password_error)): ?>
                                <div class="alert alert-error">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $password_error; ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" id="securityForm">
                                <div class="form-section">
                                    <h4 class="section-title">Change Password</h4>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label" for="current_password">Current Password *</label>
                                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="new_password">New Password *</label>
                                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="confirm_password">Confirm New Password *</label>
                                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                        </div>
                                    </div>
                                    
                                    <div class="password-requirements">
                                        <h4>Password Requirements:</h4>
                                        <ul>
                                            <li><i class="fas fa-circle"></i> At least 6 characters long</li>
                                            <li><i class="fas fa-circle"></i> Should not be too common</li>
                                            <li><i class="fas fa-circle"></i> Consider using a mix of letters and numbers</li>
                                        </ul>
                                    </div>
                                    
                                    <div style="margin-top: 1.5rem;">
                                        <button type="submit" name="change_password" class="btn btn-primary">
                                            <i class="fas fa-key"></i>
                                            Change Password
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <div class="form-section">
                                <h4 class="section-title">Login Activity</h4>
                                <div class="activity-card">
                                    <div class="activity-item">
                                        <span class="activity-label">Last Password Change</span>
                                        <span class="activity-value"><?php echo $user_data['last_password_change'] ? date('F j, Y g:i A', strtotime($user_data['last_password_change'])) : 'Never changed'; ?></span>
                                    </div>
                                    <div class="activity-item">
                                        <span class="activity-label">Last Login</span>
                                        <span class="activity-value"><?php echo $user_data['last_login'] ? date('M j, Y g:i A', strtotime($user_data['last_login'])) : 'Never'; ?></span>
                                    </div>
                                    <div class="activity-item">
                                        <span class="activity-label">Account Created</span>
                                        <span class="activity-value"><?php echo $user_data['created_at'] ? date('F j, Y', strtotime($user_data['created_at'])) : 'Unknown'; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
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

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Reset form
        function resetForm(formId) {
            const form = document.getElementById(formId);
            if (form) {
                form.reset();
            }
        }

        // Password strength indicator
        const passwordInput = document.getElementById('new_password');
        const confirmInput = document.getElementById('confirm_password');
        
        if (passwordInput && confirmInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                const requirements = document.querySelectorAll('.password-requirements li');
                
                // Check length
                requirements[0].style.color = password.length >= 6 ? 'var(--success)' : 'var(--dark-gray)';
                
                // Check if too common (simple check)
                const commonPasswords = ['password', '123456', '12345678', 'qwerty', 'abc123'];
                requirements[1].style.color = !commonPasswords.includes(password.toLowerCase()) ? 'var(--success)' : 'var(--dark-gray)';
                
                // Check for mix of letters and numbers
                const hasLetters = /[a-zA-Z]/.test(password);
                const hasNumbers = /[0-9]/.test(password);
                requirements[2].style.color = (hasLetters && hasNumbers) ? 'var(--success)' : 'var(--dark-gray)';
            });
            
            confirmInput.addEventListener('input', function() {
                if (passwordInput.value) {
                    this.style.borderColor = passwordInput.value === this.value ? 'var(--success)' : 'var(--danger)';
                }
            });
        }

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>