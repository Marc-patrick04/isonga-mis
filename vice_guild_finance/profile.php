<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error loading profile: " . $e->getMessage();
    header('Location: dashboard.php');
    exit();
}

// Get current academic year
$current_academic_year = getCurrentAcademicYear();

// Get dashboard statistics for sidebar
try {
    // Pending approvals
    $stmt = $pdo->query("SELECT COUNT(*) as pending_approvals FROM financial_transactions WHERE status = 'approved_by_finance'");
    $pending_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['pending_approvals'] ?? 0;
    
    // Pending budget requests
    $stmt = $pdo->query("SELECT COUNT(*) as pending_requests FROM committee_budget_requests WHERE status IN ('submitted', 'under_review')");
    $pending_requests = $stmt->fetch(PDO::FETCH_ASSOC)['pending_requests'] ?? 0;
    
    // Pending student aid requests
    $stmt = $pdo->query("SELECT COUNT(*) as pending_aid_requests FROM student_financial_aid WHERE status = 'submitted'");
    $pending_aid_requests = $stmt->fetch(PDO::FETCH_ASSOC)['pending_aid_requests'] ?? 0;

} catch (PDOException $e) {
    $pending_approvals = $pending_requests = $pending_aid_requests = 0;
}

// Get active tab from URL
$active_tab = $_GET['tab'] ?? 'profile';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_profile':
                $full_name = $_POST['full_name'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $date_of_birth = $_POST['date_of_birth'] ?? '';
                $gender = $_POST['gender'] ?? '';
                $bio = $_POST['bio'] ?? '';
                $address = $_POST['address'] ?? '';
                $emergency_contact_name = $_POST['emergency_contact_name'] ?? '';
                $emergency_contact_phone = $_POST['emergency_contact_phone'] ?? '';
                
                // Basic validation
                if (empty($full_name) || empty($email)) {
                    $_SESSION['error'] = "Full name and email are required";
                    break;
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error'] = "Please enter a valid email address";
                    break;
                }
                
                // Check if email is already taken by another user
                $emailCheckStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $emailCheckStmt->execute([$email, $user_id]);
                if ($emailCheckStmt->fetch()) {
                    $_SESSION['error'] = "Email address is already taken by another user";
                    break;
                }
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?, date_of_birth = ?, gender = ?, 
                        bio = ?, address = ?, emergency_contact_name = ?, emergency_contact_phone = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $full_name, $email, $phone, $date_of_birth, $gender, 
                    $bio, $address, $emergency_contact_name, $emergency_contact_phone,
                    $user_id
                ]);
                
                // Update session data
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                
                $_SESSION['success'] = "Profile updated successfully";
                break;
                
            case 'change_password':
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $_SESSION['error'] = "All password fields are required";
                    break;
                }
                
                if ($new_password !== $confirm_password) {
                    $_SESSION['error'] = "New passwords do not match";
                    break;
                }
                
                if (strlen($new_password) < 8) {
                    $_SESSION['error'] = "New password must be at least 8 characters long";
                    break;
                }
                
                // Verify current password
                $currentPasswordStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $currentPasswordStmt->execute([$user_id]);
                $currentUser = $currentPasswordStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentUser || $currentUser['password'] !== $current_password) {
                    $_SESSION['error'] = "Current password is incorrect";
                    break;
                }
                
                // Update password
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password = ?, updated_at = NOW(), last_password_change = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$new_password, $user_id]);
                
                $_SESSION['success'] = "Password changed successfully";
                break;
                
            case 'update_preferences':
                $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
                $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
                $preferred_language = $_POST['preferred_language'] ?? 'en';
                $theme_preference = $_POST['theme_preference'] ?? 'auto';
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET email_notifications = ?, sms_notifications = ?, 
                        preferred_language = ?, theme_preference = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$email_notifications, $sms_notifications, $preferred_language, $theme_preference, $user_id]);
                
                $_SESSION['success'] = "Preferences updated successfully";
                break;
                
            case 'update_avatar':
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatar = $_FILES['avatar'];
                    
                    // Validate file type
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                    $file_type = mime_content_type($avatar['tmp_name']);
                    
                    if (!in_array($file_type, $allowed_types)) {
                        $_SESSION['error'] = "Only JPG, PNG, and GIF images are allowed";
                        break;
                    }
                    
                    // Validate file size (max 2MB)
                    if ($avatar['size'] > 2 * 1024 * 1024) {
                        $_SESSION['error'] = "Image size must be less than 2MB";
                        break;
                    }
                    
                    // Create uploads directory if it doesn't exist
                    $upload_dir = '../assets/uploads/avatars/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $file_extension = pathinfo($avatar['name'], PATHINFO_EXTENSION);
                    $filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($avatar['tmp_name'], $file_path)) {
                        // Update database with relative path
                        $avatar_url = 'assets/uploads/avatars/' . $filename;
                        $stmt = $pdo->prepare("UPDATE users SET avatar_url = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$avatar_url, $user_id]);
                        
                        $_SESSION['success'] = "Profile picture updated successfully";
                        $_SESSION['avatar_url'] = $avatar_url; // Update session
                    } else {
                        $_SESSION['error'] = "Failed to upload profile picture";
                    }
                } else {
                    $upload_error = $_FILES['avatar']['error'] ?? 'No file selected';
                    $_SESSION['error'] = "Please select a valid image file. Error code: " . $upload_error;
                }
                break;
        }
        
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        header("Location: profile.php?tab=" . $active_tab);
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
        header("Location: profile.php?tab=" . $active_tab);
        exit();
    }
}

// Get login history
try {
    $loginHistoryStmt = $pdo->prepare("
        SELECT * FROM login_activities 
        WHERE user_id = ? 
        ORDER BY login_time DESC 
        LIMIT 10
    ");
    $loginHistoryStmt->execute([$user_id]);
    $login_history = $loginHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $login_history = [];
}

// Helper function to format user agent
function getDeviceFromUserAgent($user_agent) {
    if (strpos($user_agent, 'Windows') !== false) return 'Windows';
    if (strpos($user_agent, 'Mac') !== false) return 'macOS';
    if (strpos($user_agent, 'Linux') !== false) return 'Linux';
    if (strpos($user_agent, 'iPhone') !== false) return 'iPhone';
    if (strpos($user_agent, 'iPad') !== false) return 'iPad';
    if (strpos($user_agent, 'Android') !== false) return 'Android';
    return 'Unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Profile & Settings - Isonga RPSU</title>
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
            --info: #17a2b8;
            --finance-primary: #1976D2;
            --finance-secondary: #2196F3;
            --finance-accent: #0D47A1;
            --finance-light: #E3F2FD;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            --info: #4dd0e1;
            --finance-primary: #2196F3;
            --finance-secondary: #64B5F6;
            --finance-accent: #1976D2;
            --finance-light: #0D1B2A;
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
            color: var(--finance-primary);
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
        }

        .icon-btn:hover {
            background: var(--finance-primary);
            color: white;
            border-color: var(--finance-primary);
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
            background: var(--finance-primary);
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
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
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

        /* Dashboard Header */
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

        /* Profile Container */
        .profile-container {
            display: grid;
            grid-template-columns: 280px 1fr;
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
            background: var(--finance-light);
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            text-align: center;
        }

        .profile-avatar {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            margin: 0 auto 1rem;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--finance-primary);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid var(--white);
        }

        .avatar-upload:hover {
            background: var(--finance-accent);
            transform: scale(1.1);
        }

        .profile-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .profile-role {
            color: var(--finance-primary);
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin: 1.5rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .stat-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .tab:hover {
            color: var(--finance-primary);
        }

        .tab.active {
            color: var(--finance-primary);
            border-bottom-color: var(--finance-primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            font-size: 0.75rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.7rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        textarea.form-control {
            resize: vertical;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h4 {
            margin-bottom: 1rem;
            color: var(--text-dark);
            border-bottom: 2px solid var(--finance-light);
            padding-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Buttons */
        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
            transform: translateY(-1px);
        }

        /* Password Strength */
        .password-strength {
            height: 4px;
            background: var(--light-gray);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .password-strength-fill {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
        }

        .strength-weak { background: var(--danger); width: 25%; }
        .strength-fair { background: var(--warning); width: 50%; }
        .strength-good { background: var(--info); width: 75%; }
        .strength-strong { background: var(--success); width: 100%; }

        /* Login Sessions */
        .login-session {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
        }

        .session-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--finance-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--finance-primary);
            flex-shrink: 0;
        }

        .session-info {
            flex: 1;
        }

        .session-location {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }

        .session-meta {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .session-status {
            font-size: 0.7rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .status-success {
            background: #d4edda;
            color: var(--success);
        }

        .status-failed {
            background: #f8d7da;
            color: var(--danger);
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

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 450px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--finance-light);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
        }

        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            margin: 0 auto 1rem;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--finance-primary);
            background: var(--finance-light);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 0.75rem;
        }

        .file-upload p {
            margin-bottom: 0.25rem;
            font-weight: 500;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
                background: var(--finance-primary);
                color: white;
            }

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

            #sidebarToggleBtn {
                display: none;
            }

            .profile-container {
                grid-template-columns: 1fr;
            }

            .profile-sidebar {
                max-width: 350px;
                margin: 0 auto;
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

            .form-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .tabs {
                justify-content: center;
            }

            .tab {
                padding: 0.6rem 1rem;
                font-size: 0.75rem;
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

            .profile-sidebar {
                padding: 1rem;
            }

            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 1.5rem;
            }

            .profile-name {
                font-size: 1rem;
            }

            .stat-number {
                font-size: 1rem;
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Profile & Settings</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
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
                        <div class="user-role">Vice Guild Finance</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
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
                    <a href="budget_management.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Budget Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                        <?php if ($pending_approvals > 0): ?>
                            <span class="menu-badge"><?php echo $pending_approvals; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                        <?php if ($pending_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_requests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                        <?php if ($pending_aid_requests > 0): ?>
                            <span class="menu-badge"><?php echo $pending_aid_requests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="rental_management.php">
                        <i class="fas fa-home"></i>
                        <span>Rental Properties</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="allowances.php">
                        <i class="fas fa-money-check"></i>
                        <span>Allowances</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="bank_reconciliation.php">
                        <i class="fas fa-university"></i>
                        <span>Bank Reconciliation</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Official Documents</span>
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
                    <a href="profile.php" class="active">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Profile & Settings ⚙️</h1>
                    <p>Manage your account settings, security preferences, and personal information</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $_SESSION['success']; ?></span>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $_SESSION['error']; ?></span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="profile-container">
                <!-- Profile Sidebar -->
                <div class="profile-sidebar">
                    <div class="profile-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                        <div class="avatar-upload" onclick="openAvatarModal()">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    
                    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="profile-role">Vice Guild Finance</div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $pending_approvals; ?></div>
                            <div class="stat-label">Pending Approvals</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $pending_requests; ?></div>
                            <div class="stat-label">Budget Requests</div>
                        </div>
                    </div>
                    
                    <div style="text-align: left; margin-top: 1rem; font-size: 0.75rem; color: var(--dark-gray);">
                        <div style="margin-bottom: 0.75rem;">
                            <strong>Email:</strong><br>
                            <?php echo htmlspecialchars($user['email'] ?? 'Not specified'); ?>
                        </div>
                        <div style="margin-bottom: 0.75rem;">
                            <strong>Phone:</strong><br>
                            <?php echo htmlspecialchars($user['phone'] ?? 'Not specified'); ?>
                        </div>
                        <div>
                            <strong>Member Since:</strong><br>
                            <?php echo date('M Y', strtotime($user['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Profile Content -->
                <div class="card">
                    <div class="card-body">
                        <!-- Tabs -->
                        <div class="tabs">
                            <button class="tab <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" onclick="openTab(event, 'profile')">
                                <i class="fas fa-user"></i> Personal Info
                            </button>
                            <button class="tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>" onclick="openTab(event, 'security')">
                                <i class="fas fa-shield-alt"></i> Security
                            </button>
                            <button class="tab <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" onclick="openTab(event, 'preferences')">
                                <i class="fas fa-cog"></i> Preferences
                            </button>
                            <button class="tab <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>" onclick="openTab(event, 'sessions')">
                                <i class="fas fa-history"></i> Login History
                            </button>
                        </div>

                        <!-- Personal Info Tab -->
                        <div id="profile-tab" class="tab-content <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="form-section">
                                    <h4>Basic Information</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Full Name *</label>
                                            <input type="text" name="full_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Email Address *</label>
                                            <input type="email" name="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Phone Number</label>
                                            <input type="tel" name="phone" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Date of Birth</label>
                                            <input type="date" name="date_of_birth" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Gender</label>
                                        <select name="gender" class="form-control">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Additional Information</h4>
                                    <div class="form-group">
                                        <label class="form-label">Bio / About Me</label>
                                        <textarea name="bio" class="form-control" rows="3" 
                                                  placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-control" rows="3" 
                                                  placeholder="Enter your address..."><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Emergency Contact</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Contact Name</label>
                                            <input type="text" name="emergency_contact_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Contact Phone</label>
                                            <input type="tel" name="emergency_contact_phone" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Security Tab -->
                        <div id="security-tab" class="tab-content <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
                            <div class="form-section">
                                <h4>Change Password</h4>
                                <form method="POST">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Current Password *</label>
                                        <input type="password" name="current_password" class="form-control" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">New Password *</label>
                                        <input type="password" name="new_password" id="newPassword" class="form-control" required 
                                               onkeyup="checkPasswordStrength(this.value)">
                                        <div class="password-strength">
                                            <div class="password-strength-fill" id="passwordStrength"></div>
                                        </div>
                                        <small style="color: var(--dark-gray);">Password must be at least 8 characters long</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Confirm New Password *</label>
                                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required>
                                    </div>
                                    
                                    <div style="display: flex; justify-content: flex-end;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-key"></i> Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="form-section">
                                <h4>Account Security</h4>
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <span>Last password change:</span>
                                        <strong><?php echo $user['last_password_change'] ? date('M j, Y g:i A', strtotime($user['last_password_change'])) : 'Never'; ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span>Account created:</span>
                                        <strong><?php echo date('M j, Y g:i A', strtotime($user['created_at'])); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Preferences Tab -->
                        <div id="preferences-tab" class="tab-content <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_preferences">
                                
                                <div class="form-section">
                                    <h4>Notification Preferences</h4>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="email_notifications" name="email_notifications" 
                                                   <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                            <label for="email_notifications">Email Notifications</label>
                                        </div>
                                        <small style="color: var(--dark-gray);">Receive notifications via email</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="sms_notifications" name="sms_notifications" 
                                                   <?php echo ($user['sms_notifications'] ?? 0) ? 'checked' : ''; ?>>
                                            <label for="sms_notifications">SMS Notifications</label>
                                        </div>
                                        <small style="color: var(--dark-gray);">Receive notifications via SMS</small>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Language & Region</h4>
                                    <div class="form-group">
                                        <label class="form-label">Preferred Language</label>
                                        <select name="preferred_language" class="form-control">
                                            <option value="en" <?php echo ($user['preferred_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="rw" <?php echo ($user['preferred_language'] ?? 'en') === 'rw' ? 'selected' : ''; ?>>Kinyarwanda</option>
                                            <option value="fr" <?php echo ($user['preferred_language'] ?? 'en') === 'fr' ? 'selected' : ''; ?>>French</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Appearance</h4>
                                    <div class="form-group">
                                        <label class="form-label">Theme Preference</label>
                                        <select name="theme_preference" id="themePreferenceSelect" class="form-control">
                                            <option value="auto" <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>Auto (System Default)</option>
                                            <option value="light" <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'selected' : ''; ?>>Light Mode</option>
                                            <option value="dark" <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'selected' : ''; ?>>Dark Mode</option>
                                        </select>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Login History Tab -->
                        <div id="sessions-tab" class="tab-content <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>">
                            <div class="form-section">
                                <h4>Recent Login Activity</h4>
                                
                                <?php if (empty($login_history)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-history"></i>
                                        <p>No login history available</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($login_history as $session): ?>
                                        <div class="login-session">
                                            <div class="session-icon">
                                                <i class="fas fa-desktop"></i>
                                            </div>
                                            <div class="session-info">
                                                <div class="session-location">
                                                    <?php echo htmlspecialchars($session['ip_address']); ?>
                                                    <span style="color: var(--dark-gray); font-weight: normal;"> • <?php echo getDeviceFromUserAgent($session['user_agent']); ?></span>
                                                </div>
                                                <div class="session-meta">
                                                    <?php echo date('M j, Y g:i A', strtotime($session['login_time'])); ?>
                                                </div>
                                            </div>
                                            <div class="session-status <?php echo $session['success'] ? 'status-success' : 'status-failed'; ?>">
                                                <?php echo $session['success'] ? 'Success' : 'Failed'; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="form-section">
                                <h4>Current Session</h4>
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <span>IP Address:</span>
                                        <strong><?php echo $_SERVER['REMOTE_ADDR']; ?></strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span>Device:</span>
                                        <strong><?php echo getDeviceFromUserAgent($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Avatar Upload Modal -->
    <div id="avatarModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Profile Picture</h3>
                <button class="close" onclick="closeAvatarModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="avatarForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_avatar">
                    
                    <div class="avatar-preview" id="avatarPreview">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Current Avatar">
                        <?php else: ?>
                            <div style="font-size: 3rem; color: var(--dark-gray);">
                                <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="file-upload" onclick="document.getElementById('avatarFile').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click to upload new profile picture</p>
                        <small style="color: var(--dark-gray);">JPG, PNG, GIF (Max 2MB)</small>
                        <input type="file" id="avatarFile" name="avatar" accept="image/*" style="display: none;" onchange="previewAvatar(this)">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="avatarForm" class="btn btn-primary" id="avatarSubmit" disabled>
                    <i class="fas fa-upload"></i> Upload Picture
                </button>
                <button type="button" class="btn" onclick="closeAvatarModal()">Cancel</button>
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
            
            // Update theme preference dropdown
            const themeSelect = document.getElementById('themePreferenceSelect');
            if (themeSelect) {
                if (isDark) {
                    themeSelect.value = 'dark';
                } else {
                    themeSelect.value = 'light';
                }
            }
        });

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
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

        // Tab functionality
        function openTab(event, tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => tab.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            } else {
                // Find the tab button that matches this tabName
                const tabButtons = document.querySelectorAll('.tab');
                tabButtons.forEach(btn => {
                    if (btn.textContent.toLowerCase().includes(tabName.toLowerCase())) {
                        btn.classList.add('active');
                    }
                });
            }
            
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Avatar modal functionality
        function openAvatarModal() {
            document.getElementById('avatarModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAvatarModal() {
            document.getElementById('avatarModal').classList.remove('active');
            document.body.style.overflow = '';
            // Reset form
            document.getElementById('avatarForm').reset();
            document.getElementById('avatarSubmit').disabled = true;
            // Reset preview
            resetAvatarPreview();
        }

        function previewAvatar(input) {
            const preview = document.getElementById('avatarPreview');
            const submitBtn = document.getElementById('avatarSubmit');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Avatar Preview">`;
                    submitBtn.disabled = false;
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        function resetAvatarPreview() {
            const preview = document.getElementById('avatarPreview');
            <?php if (!empty($user['avatar_url'])): ?>
                preview.innerHTML = `<img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Current Avatar">`;
            <?php else: ?>
                preview.innerHTML = `<div style="font-size: 3rem; color: var(--dark-gray);"><?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?></div>`;
            <?php endif; ?>
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;
            
            if (password.length >= 8) strength += 25;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 25;
            if (password.match(/\d/)) strength += 25;
            if (password.match(/[^a-zA-Z\d]/)) strength += 25;
            
            strengthBar.className = 'password-strength-fill';
            
            if (strength <= 25) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 50) {
                strengthBar.classList.add('strength-fair');
            } else if (strength <= 75) {
                strengthBar.classList.add('strength-good');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        }

        // Confirm password validation
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        
        if (confirmPassword) {
            confirmPassword.addEventListener('keyup', function() {
                if (newPassword.value !== this.value) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });
        }

        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('avatarModal');
            if (event.target === modal) {
                closeAvatarModal();
            }
        });

        // Auto-save preferences when theme is changed via dropdown
        const themePreferenceSelect = document.getElementById('themePreferenceSelect');
        if (themePreferenceSelect) {
            themePreferenceSelect.addEventListener('change', function() {
                const theme = this.value;
                if (theme === 'dark') {
                    body.classList.add('dark-mode');
                    localStorage.setItem('theme', 'dark');
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                } else if (theme === 'light') {
                    body.classList.remove('dark-mode');
                    localStorage.setItem('theme', 'light');
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                } else {
                    // Auto mode - respect system preference
                    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (systemPrefersDark) {
                        body.classList.add('dark-mode');
                        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                    } else {
                        body.classList.remove('dark-mode');
                        themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                    }
                    localStorage.removeItem('theme');
                }
            });
        }
    </script>
</body>
</html>