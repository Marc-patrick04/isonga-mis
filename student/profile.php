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

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: profile.php');
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

// Get real ticket statistics
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
            header('Location: profile.php');
            exit();
            
        } catch (PDOException $e) {
            $error_message = "Failed to update profile. Please try again.";
        }
    }
}

// Handle preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preferences'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $preferred_language = $_POST['preferred_language'];
    $theme_preference = $_POST['theme_preference'];

    try {
        $update_stmt = $pdo->prepare("
            UPDATE users SET 
                email_notifications = ?, sms_notifications = ?, preferred_language = ?, 
                theme_preference = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $update_stmt->execute([
            $email_notifications, $sms_notifications, $preferred_language, 
            $theme_preference, $student_id
        ]);
        
        $_SESSION['success_message'] = "Preferences updated successfully!";
        header('Location: profile.php');
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Failed to update preferences. Please try again.";
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
                    header('Location: profile.php');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --booking-blue: #003b95;
            --booking-blue-light: #006ce4;
            --booking-green: #00a699;
            --booking-orange: #ff5a5f;
            --booking-yellow: #ffb400;
            --booking-gray-50: #f7f7f7;
            --booking-gray-100: #ebebeb;
            --booking-gray-200: #d8d8d8;
            --booking-gray-300: #b0b0b0;
            --booking-gray-400: #717171;
            --booking-gray-500: #2d2d2d;
            --booking-white: #ffffff;
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.16);
            --transition: all 0.2s ease;
        }

        [data-theme="dark"] {
            --booking-gray-50: #1a1a1a;
            --booking-gray-100: #2d2d2d;
            --booking-gray-200: #404040;
            --booking-gray-300: #666666;
            --booking-gray-400: #999999;
            --booking-gray-500: #ffffff;
            --booking-white: #2d2d2d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
            padding: 0 2rem;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

       /* Logo Styles */
.logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
}

.logo-image {
    height: 36px; /* Adjust based on your logo's aspect ratio */
    width: auto;
    object-fit: contain;
}

.logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-blue);
    letter-spacing: -0.5px;
}

/* Optional: Different logo for dark theme */
[data-theme="dark"] .logo-text {
    color: white; /* Or keep it blue for consistency */
}

[data-theme="dark"] .logo-image {
    filter: brightness(1.1); /* Slightly brighten logo for dark theme */
}

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--booking-gray-50);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .theme-toggle-btn {
            background: none;
            border: 1px solid var(--booking-gray-200);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .theme-toggle-btn:hover {
            border-color: var(--booking-blue);
            color: var(--booking-blue);
        }

        /* Navigation */
        .nav-container {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .main-nav {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .nav-links {
            display: flex;
            gap: 0;
            list-style: none;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--booking-gray-500);
            font-weight: 500;
            font-size: 0.9rem;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .nav-link:hover {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue-light);
        }

        .nav-link.active {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue);
            font-weight: 600;
        }

        .nav-link i {
            font-size: 0.85rem;
            width: 18px;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-500);
        }

        .page-subtitle {
            color: var(--booking-gray-400);
            font-size: 0.95rem;
        }

        .header-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
        }

        /* Profile Layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            position: sticky;
            top: 2rem;
            height: fit-content;
        }

        .profile-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .profile-header {
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            padding: 2rem;
            text-align: center;
            color: white;
            position: relative;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            border: 3px solid white;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .profile-meta {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .profile-body {
            padding: 2rem;
        }

        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
            margin-bottom: 0.25rem;
            font-weight: 500;
        }

        .info-value {
            font-size: 0.95rem;
            color: var(--booking-gray-500);
            font-weight: 500;
        }

        /* Profile Stats */
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
            border: 1px solid var(--booking-gray-100);
            transition: var(--transition);
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--booking-gray-200);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--booking-blue);
            margin-bottom: 0.25rem;
            display: block;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        /* Tabs Navigation */
        .tabs-nav {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            padding: 0.75rem;
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            background: transparent;
            color: var(--booking-gray-500);
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            border-radius: var(--border-radius);
            transition: var(--transition);
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            background: var(--booking-gray-50);
            color: var(--booking-blue);
        }

        .tab-btn.active {
            background: var(--booking-blue);
            color: white;
        }

        .tab-btn i {
            font-size: 0.9rem;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form Card */
        .form-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--booking-gray-500);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--booking-blue);
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            color: var(--booking-gray-500);
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--booking-gray-500);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-control:disabled {
            background: var(--booking-gray-50);
            color: var(--booking-gray-400);
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--booking-gray-100);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--booking-blue);
            color: white;
            border: 1px solid var(--booking-blue);
        }

        .btn-primary:hover {
            background: var(--booking-blue-light);
            border-color: var(--booking-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 107, 228, 0.2);
        }

        .btn-secondary {
            background: var(--booking-gray-100);
            color: var(--booking-gray-500);
            border: 1px solid var(--booking-gray-200);
        }

        .btn-secondary:hover {
            background: var(--booking-gray-200);
        }

        .btn-outline {
            background: var(--booking-white);
            color: var(--booking-blue);
            border: 1px solid var(--booking-blue);
        }

        .btn-outline:hover {
            background: var(--booking-blue);
            color: white;
        }

        /* Checkbox and Radio Styles */
        .checkbox-group, .radio-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .checkbox-group input[type="checkbox"],
        .radio-group input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: var(--booking-blue);
        }

        /* Alert Styles */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid;
        }

        .alert-success {
            border-color: var(--booking-green);
            background: #f0fffc;
            color: var(--booking-green);
        }

        .alert-error {
            border-color: var(--booking-orange);
            background: #fff5f5;
            color: var(--booking-orange);
        }

        .alert i {
            font-size: 1rem;
            margin-top: 0.125rem;
        }

        /* Helper Text */
        .helper-text {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        /* Password Requirements */
        .password-requirements {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
            border: 1px solid var(--booking-gray-200);
        }

        .password-requirements h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--booking-gray-500);
        }

        .password-requirements ul {
            list-style: none;
            font-size: 0.8rem;
            color: var(--booking-gray-400);
        }

        .password-requirements li {
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .password-requirements li i {
            font-size: 0.6rem;
        }

        /* Login Activity */
        .activity-card {
            background: var(--booking-gray-50);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius);
            padding: 1.5rem;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-label {
            font-weight: 500;
            color: var(--booking-gray-500);
            font-size: 0.9rem;
        }

        .activity-value {
            color: var(--booking-gray-400);
            font-size: 0.9rem;
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .profile-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .profile-sidebar {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .header {
                padding: 0 1rem;
            }
            
            .main-nav {
                padding: 0 1rem;
            }
            
            .nav-links {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 0.5rem;
            }
            
            .nav-link {
                padding: 1rem;
                font-size: 0.85rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .tabs-nav {
                flex-wrap: wrap;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .user-name, .user-role {
                display: none;
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
    <!-- Header -->
    <!-- Header -->
    <header class="header">
<a href="dashboard.php" class="logo">
    <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo-image">
    <div class="logo-text">Isonga</div>
</a>
        
<!-- Add this to the header-actions div in dashboard.php -->
<div class="header-actions">
    <form method="POST" style="margin: 0;">
        <button type="submit" name="toggle_theme" class="theme-toggle-btn" title="Toggle Theme">
            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
        </button>
    </form>
    
    <!-- Logout Button - Add this -->
    <a href="../auth/logout.php" class="logout-btn" title="Logout">
        <i class="fas fa-sign-out-alt"></i>
    </a>
    
    <div class="user-menu">
        <div class="user-avatar">
            <?php echo strtoupper(substr($student_name, 0, 1)); ?>
        </div>
        <div class="user-info">
            <span class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></span>
            <span class="user-role">Student</span>
        </div>
    </div>
</div>
    </header>

    <!-- Navigation -->
    <nav class="nav-container">
        <div class="main-nav">
            <ul class="nav-links">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tickets.php" class="nav-link">
                        <i class="fas fa-ticket-alt"></i>
                        My Tickets
                    </a>
                </li>
                <li class="nav-item">
                    <a href="financial_aid.php" class="nav-link">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link active">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Manage your personal information and settings</p>
            </div>
            <div class="header-actions-row">
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
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
                        <div class="profile-meta"><?php echo safe_display($user_data['program_name']); ?></div>
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
                        
                        <div class="profile-info">
                            <div class="info-item">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo safe_display($user_data['email']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?php echo safe_display($user_data['phone'] ?: 'Not set'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Academic Year</span>
                                <span class="info-value">Year <?php echo safe_display($user_data['academic_year']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Member Since</span>
                                <span class="info-value"><?php echo $user_data['created_at'] ? date('F Y', strtotime($user_data['created_at'])) : 'N/A'; ?></span>
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
                    <button class="tab-btn" onclick="switchTab('preferences')">
                        <i class="fas fa-sliders-h"></i>
                        Preferences
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
                                               value="<?php echo safe_display($user_data['full_name']); ?>" required>
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
                                        <select id="gender" name="gender" class="form-control">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo ($user_data['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($user_data['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($user_data['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="reg_number">Registration Number</label>
                                        <input type="text" id="reg_number" class="form-control" 
                                               value="<?php echo safe_display($user_data['reg_number']); ?>" disabled>
                                        <span class="helper-text">Registration number cannot be changed</span>
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
                                        <label class="form-label">Academic Year</label>
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
                                    <span class="activity-label">Login Count</span>
                                    <span class="activity-value"><?php echo $user_data['login_count'] ?? 0; ?> times</span>
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

                <!-- Preferences Tab -->
                <div id="preferences-tab" class="tab-content">
                    <div class="form-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-sliders-h"></i>
                                Preferences
                            </h3>
                        </div>
                        
                        <form method="POST" id="preferencesForm">
                            <div class="form-section">
                                <h4 class="section-title">Notification Preferences</h4>
                                <div class="form-grid">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="email_notifications" name="email_notifications" 
                                               value="1" <?php echo ($user_data['email_notifications'] ?? 0) ? 'checked' : ''; ?>>
                                        <label for="email_notifications">Email Notifications</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="sms_notifications" name="sms_notifications" 
                                               value="1" <?php echo ($user_data['sms_notifications'] ?? 0) ? 'checked' : ''; ?>>
                                        <label for="sms_notifications">SMS Notifications</label>
                                    </div>
                                </div>
                                <span class="helper-text">Receive notifications about ticket updates and announcements</span>
                            </div>

                            <div class="form-section">
                                <h4 class="section-title">Display Preferences</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="preferred_language">Preferred Language</label>
                                        <select id="preferred_language" name="preferred_language" class="form-control">
                                            <option value="en" <?php echo ($user_data['preferred_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="rw" <?php echo ($user_data['preferred_language'] ?? 'en') === 'rw' ? 'selected' : ''; ?>>Kinyarwanda</option>
                                            <option value="fr" <?php echo ($user_data['preferred_language'] ?? 'en') === 'fr' ? 'selected' : ''; ?>>French</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="theme_preference">Theme Preference</label>
                                        <select id="theme_preference" name="theme_preference" class="form-control">
                                            <option value="auto" <?php echo ($user_data['theme_preference'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>Auto (Follow system)</option>
                                            <option value="light" <?php echo ($user_data['theme_preference'] ?? 'auto') === 'light' ? 'selected' : ''; ?>>Light Mode</option>
                                            <option value="dark" <?php echo ($user_data['theme_preference'] ?? 'auto') === 'dark' ? 'selected' : ''; ?>>Dark Mode</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetForm('preferencesForm')">
                                    <i class="fas fa-redo"></i>
                                    Reset
                                </button>
                                <button type="submit" name="update_preferences" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
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
            
            // Smooth scroll to top of tab content
            document.getElementById(tabName + '-tab').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        // Reset form to original values
        function resetForm(formId) {
            const form = document.getElementById(formId);
            if (form) {
                form.reset();
                // Show confirmation
                alert('Form has been reset to original values');
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
                requirements[0].style.color = password.length >= 6 ? 'var(--booking-green)' : 'var(--booking-gray-400)';
                
                // Check if too common (simple check)
                const commonPasswords = ['password', '123456', '12345678', 'qwerty', 'abc123'];
                requirements[1].style.color = !commonPasswords.includes(password.toLowerCase()) ? 'var(--booking-green)' : 'var(--booking-gray-400)';
                
                // Check for mix of letters and numbers
                const hasLetters = /[a-zA-Z]/.test(password);
                const hasNumbers = /[0-9]/.test(password);
                requirements[2].style.color = (hasLetters && hasNumbers) ? 'var(--booking-green)' : 'var(--booking-gray-400)';
                
                // Check confirmation match
                if (confirmInput.value) {
                    confirmInput.style.borderColor = password === confirmInput.value ? 'var(--booking-green)' : 'var(--booking-orange)';
                }
            });
            
            confirmInput.addEventListener('input', function() {
                if (passwordInput.value) {
                    this.style.borderColor = passwordInput.value === this.value ? 'var(--booking-green)' : 'var(--booking-orange)';
                }
            });
        }

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.style.borderColor = 'var(--booking-orange)';
                        isValid = false;
                        
                        // Add error message
                        if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
                            const errorMsg = document.createElement('span');
                            errorMsg.className = 'error-message';
                            errorMsg.style.color = 'var(--booking-orange)';
                            errorMsg.style.fontSize = '0.8rem';
                            errorMsg.style.display = 'block';
                            errorMsg.style.marginTop = '0.25rem';
                            errorMsg.textContent = 'This field is required';
                            field.parentNode.appendChild(errorMsg);
                        }
                    } else {
                        field.style.borderColor = '';
                        const errorMsg = field.parentNode.querySelector('.error-message');
                        if (errorMsg) {
                            errorMsg.remove();
                        }
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields marked with *');
                }
            });
        });

        // Auto-fill today's date in date fields for easier testing
        document.addEventListener('DOMContentLoaded', function() {
            const dateField = document.getElementById('date_of_birth');
            if (dateField && !dateField.value) {
                // Set max date to 16 years ago
                const today = new Date();
                const minDate = new Date(today.getFullYear() - 60, today.getMonth(), today.getDate());
                const maxDate = new Date(today.getFullYear() - 16, today.getMonth(), today.getDate());
                
                dateField.min = minDate.toISOString().split('T')[0];
                dateField.max = maxDate.toISOString().split('T')[0];
            }
        });

        // Add smooth animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe form sections
        document.querySelectorAll('.form-section').forEach(section => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';
            section.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(section);
        });
    </script>
</body>
</html>