<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Environment & Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_environment') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data (PostgreSQL syntax)
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               cm.role as committee_role,
               cm.bio as committee_bio,
               cm.portfolio_description,
               cm.photo_url as committee_photo
        FROM users u 
        LEFT JOIN committee_members cm ON u.id = cm.user_id 
        WHERE u.id = ?
    ");
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

// Get dashboard statistics for sidebar (PostgreSQL syntax)
try {
    // Security incidents
    $stmt = $pdo->query("SELECT COUNT(*) as security_incidents FROM security_incidents");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $security_incidents = $result['security_incidents'] ?? 0;
    
    // Pending maintenance requests - PostgreSQL uses ILIKE
    $stmt = $pdo->query("SELECT COUNT(*) as pending_maintenance FROM facility_bookings WHERE status = 'pending' AND purpose ILIKE '%maintenance%'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending_maintenance = $result['pending_maintenance'] ?? 0;
    
    // Environmental projects
    $stmt = $pdo->query("SELECT COUNT(*) as environmental_projects FROM innovation_projects WHERE category_id = 2");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $environmental_projects = $result['environmental_projects'] ?? 0;
    
    // Get unread messages count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $unread_messages = $result['unread_messages'] ?? 0;
    
} catch (PDOException $e) {
    $security_incidents = $pending_maintenance = $environmental_projects = $unread_messages = 0;
    error_log("Dashboard stats error: " . $e->getMessage());
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
                
                // Also update committee_members table if bio is updated
                if (!empty($bio)) {
                    $committeeStmt = $pdo->prepare("
                        UPDATE committee_members 
                        SET bio = ?, updated_at = NOW() 
                        WHERE user_id = ?
                    ");
                    $committeeStmt->execute([$bio, $user_id]);
                }
                
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
                    SET password = ?, last_password_change = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$new_password, $user_id]);
                
                $_SESSION['success'] = "Password changed successfully";
                break;
                
            case 'update_preferences':
                $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
                $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
                $preferred_language = $_POST['preferred_language'] ?? 'en';
                $theme_preference = $_POST['theme_preference'] ?? 'light';
                
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
                        
                        // Also update committee_members photo_url if user is a committee member
                        $committeeStmt = $pdo->prepare("UPDATE committee_members SET photo_url = ?, updated_at = NOW() WHERE user_id = ?");
                        $committeeStmt->execute([$avatar_url, $user_id]);
                        
                        $_SESSION['success'] = "Profile picture updated successfully";
                        $_SESSION['avatar_url'] = $avatar_url;
                    } else {
                        $_SESSION['error'] = "Failed to upload profile picture";
                    }
                } else {
                    $_SESSION['error'] = "Please select a valid image file";
                }
                break;
                
            case 'enable_2fa':
                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = true, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "Two-factor authentication enabled successfully";
                break;
                
            case 'disable_2fa':
                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = false, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "Two-factor authentication disabled successfully";
                break;
        }
        
        // Refresh user data
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   cm.role as committee_role,
                   cm.bio as committee_bio,
                   cm.portfolio_description,
                   cm.photo_url as committee_photo
            FROM users u 
            LEFT JOIN committee_members cm ON u.id = cm.user_id 
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        header("Location: profile.php?tab=" . $active_tab);
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    }
}

// Get login history (PostgreSQL syntax)
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
    error_log("Login history error: " . $e->getMessage());
}

// Get pending tickets count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE assigned_to = ? AND status IN ('open', 'in_progress')
    ");
    $stmt->execute([$user_id]);
    $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
} catch (PDOException $e) {
    $pending_tickets = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Profile & Settings - Minister of Environment & Security</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #4caf50;
            --accent-green: #2e7d32;
            --light-green: #e8f5e9;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-green);
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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

        /* Profile Layout */
        .profile-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
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
            background: var(--primary-green);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid var(--white);
            z-index: 10;
        }

        .avatar-upload:hover {
            background: var(--accent-green);
            transform: scale(1.1);
        }

        .profile-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .profile-role {
            color: var(--primary-green);
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1.5rem 0;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        .stat-item {
            text-align: center;
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

        .profile-info {
            font-size: 0.8rem;
            color: var(--dark-gray);
            text-align: left;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        .profile-info p {
            margin-bottom: 0.5rem;
        }

        .profile-info i {
            width: 20px;
            color: var(--primary-green);
        }

        /* Profile Content */
        .profile-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .profile-tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
            overflow-x: auto;
        }

        .profile-tab {
            padding: 1rem 1.25rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            color: var(--dark-gray);
            border-bottom: 2px solid transparent;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .profile-tab:hover {
            background: var(--white);
            color: var(--text-dark);
        }

        .profile-tab.active {
            color: var(--primary-green);
            border-bottom-color: var(--primary-green);
            background: var(--white);
        }

        .tab-content {
            padding: 1.5rem;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Form Styles */
        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h4 {
            margin-bottom: 1rem;
            color: var(--text-dark);
            border-bottom: 2px solid var(--light-green);
            padding-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        select.form-control {
            cursor: pointer;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            cursor: pointer;
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }

        .form-hint {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* Buttons */
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

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
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
            gap: 0.75rem;
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
            transition: var(--transition);
        }

        .strength-weak { background: var(--danger); width: 25%; }
        .strength-fair { background: var(--warning); width: 50%; }
        .strength-good { background: var(--info); width: 75%; }
        .strength-strong { background: var(--success); width: 100%; }

        /* Security Badge */
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
        }

        .security-badge.enabled {
            background: #d4edda;
            color: var(--success);
        }

        .security-badge.disabled {
            background: #f8d7da;
            color: var(--danger);
        }

        /* Login Session */
        .login-session {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
        }

        .session-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light-green);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            flex-shrink: 0;
        }

        .session-info {
            flex: 1;
        }

        .session-location {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
        }

        .session-meta {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .session-status {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .status-success {
            background: #d4edda;
            color: var(--success);
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

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.25rem;
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

        .avatar-preview i {
            font-size: 3rem;
            color: var(--dark-gray);
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
            border-color: var(--primary-green);
            background: var(--light-green);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .file-upload small {
            font-size: 0.7rem;
            color: var(--dark-gray);
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
                background: var(--primary-green);
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

            .profile-container {
                grid-template-columns: 1fr;
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
            }

            .profile-tabs {
                flex-wrap: wrap;
            }

            .profile-tab {
                flex: 1;
                text-align: center;
            }

            .profile-sidebar {
                order: 2;
            }

            .profile-content {
                order: 1;
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

            .profile-sidebar {
                padding: 1rem;
            }

            .profile-avatar {
                width: 100px;
                height: 100px;
            }

            .tab-content {
                padding: 1rem;
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
                    <h1>Isonga - Environment & Security</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
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
                        <div class="user-role">Minister of Environment & Security</div>
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
                        <span>Student Tickets</span>
                        <?php if ($pending_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="security.php">
                        <i class="fas fa-shield-alt"></i>
                        <span>Security</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Environmental Clubs</span>
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
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Profile & Settings 👤</h1>
                    <p>Manage your personal information and account preferences</p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
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
                        <div class="avatar-upload" id="avatarUploadBtn" title="Change Profile Picture">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    
                    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="profile-role">Minister of Environment & Security</div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $security_incidents; ?></div>
                            <div class="stat-label">Security Incidents</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $environmental_projects; ?></div>
                            <div class="stat-label">Env. Projects</div>
                        </div>
                    </div>
                    
                    <div class="profile-info">
                        <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?></p>
                        <p><i class="fas fa-calendar"></i> Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></p>
                    </div>
                </div>

                <!-- Profile Content -->
                <div class="profile-content">
                    <!-- Tabs -->
                    <div class="profile-tabs">
                        <button class="profile-tab <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" 
                                onclick="switchTab('profile')">
                            <i class="fas fa-user"></i> Personal Info
                        </button>
                        <button class="profile-tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>" 
                                onclick="switchTab('security')">
                            <i class="fas fa-shield-alt"></i> Security
                        </button>
                        <button class="profile-tab <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" 
                                onclick="switchTab('preferences')">
                            <i class="fas fa-cog"></i> Preferences
                        </button>
                        <button class="profile-tab <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>" 
                                onclick="switchTab('sessions')">
                            <i class="fas fa-history"></i> Login History
                        </button>
                    </div>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- Personal Information Tab -->
                        <div id="profile-tab" class="tab-pane <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="form-section">
                                    <h4>Basic Information</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="full_name">Full Name *</label>
                                            <input type="text" id="full_name" name="full_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="email">Email Address *</label>
                                            <input type="email" id="email" name="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="phone">Phone Number</label>
                                            <input type="tel" id="phone" name="phone" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="date_of_birth">Date of Birth</label>
                                            <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="gender">Gender</label>
                                        <select id="gender" name="gender" class="form-control">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($user['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Additional Information</h4>
                                    <div class="form-group">
                                        <label for="bio">Bio</label>
                                        <textarea id="bio" name="bio" class="form-control" 
                                                  placeholder="Tell us about yourself and your environmental initiatives..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="address">Address</label>
                                        <textarea id="address" name="address" class="form-control" 
                                                  placeholder="Your current address..."><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Emergency Contact</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="emergency_contact_name">Contact Name</label>
                                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="emergency_contact_phone">Contact Phone</label>
                                            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Security Tab -->
                        <div id="security-tab" class="tab-pane <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
                            <div class="form-section">
                                <h4>Change Password</h4>
                                <form method="POST" id="passwordForm">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="form-group">
                                        <label for="current_password">Current Password *</label>
                                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="new_password">New Password *</label>
                                        <input type="password" id="new_password" name="new_password" class="form-control" required 
                                               onkeyup="checkPasswordStrength(this.value)">
                                        <div class="password-strength">
                                            <div class="password-strength-fill" id="passwordStrength"></div>
                                        </div>
                                        <div class="form-hint">Password must be at least 8 characters long</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="confirm_password">Confirm New Password *</label>
                                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                    </div>
                                    
                                    <div class="modal-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-key"></i> Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="form-section">
                                <h4>Two-Factor Authentication</h4>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--light-green); border-radius: var(--border-radius); flex-wrap: wrap; gap: 1rem;">
                                    <div>
                                        <div style="font-weight: 600; margin-bottom: 0.25rem;">Two-Factor Authentication</div>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                            <?php echo ($user['two_factor_enabled'] ?? false) ? 'Enabled' : 'Disabled'; ?>
                                        </div>
                                    </div>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="<?php echo ($user['two_factor_enabled'] ?? false) ? 'disable_2fa' : 'enable_2fa'; ?>">
                                        <button type="submit" class="btn <?php echo ($user['two_factor_enabled'] ?? false) ? 'btn-secondary' : 'btn-primary'; ?>">
                                            <?php echo ($user['two_factor_enabled'] ?? false) ? 'Disable' : 'Enable'; ?> 2FA
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="form-section">
                                <h4>Account Security</h4>
                                <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                    <p><i class="fas fa-check-circle" style="color: var(--success);"></i> Last password change: 
                                        <?php echo $user['last_password_change'] ? date('F j, Y g:i A', strtotime($user['last_password_change'])) : 'Never'; ?>
                                    </p>
                                    <p><i class="fas fa-check-circle" style="color: var(--success);"></i> Account created: 
                                        <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                                    </p>
                                    <p><i class="fas fa-check-circle" style="color: var(--success);"></i> Last login: 
                                        <?php echo $user['last_login'] ? date('F j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Preferences Tab -->
                        <div id="preferences-tab" class="tab-pane <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_preferences">
                                
                                <div class="form-section">
                                    <h4>Notification Preferences</h4>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="email_notifications" name="email_notifications" value="1" 
                                                   <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                            <label for="email_notifications">Email Notifications</label>
                                        </div>
                                        <div class="form-hint">Receive notifications via email</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="sms_notifications" name="sms_notifications" value="1" 
                                                   <?php echo ($user['sms_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                            <label for="sms_notifications">SMS Notifications</label>
                                        </div>
                                        <div class="form-hint">Receive notifications via SMS (when available)</div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Language & Region</h4>
                                    <div class="form-group">
                                        <label for="preferred_language">Preferred Language</label>
                                        <select id="preferred_language" name="preferred_language" class="form-control">
                                            <option value="en" <?php echo ($user['preferred_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="rw" <?php echo ($user['preferred_language'] ?? 'en') === 'rw' ? 'selected' : ''; ?>>Kinyarwanda</option>
                                            <option value="fr" <?php echo ($user['preferred_language'] ?? 'en') === 'fr' ? 'selected' : ''; ?>>French</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Appearance</h4>
                                    <div class="form-group">
                                        <label for="theme_preference">Theme Preference</label>
                                        <select id="theme_preference" name="theme_preference" class="form-control">
                                            <option value="light" <?php echo ($user['theme_preference'] ?? 'light') === 'light' ? 'selected' : ''; ?>>Light</option>
                                            <option value="auto" <?php echo ($user['theme_preference'] ?? 'light') === 'auto' ? 'selected' : ''; ?>>Auto (System)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="modal-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Login History Tab -->
                        <div id="sessions-tab" class="tab-pane <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>">
                            <div class="form-section">
                                <h4>Recent Login Activity</h4>
                                <?php if (empty($login_history)): ?>
                                    <div class="empty-state" style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                        <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
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
                                                </div>
                                                <div class="session-meta">
                                                    <?php echo date('F j, Y g:i A', strtotime($session['login_time'])); ?>
                                                </div>
                                            </div>
                                            <div class="session-status status-success">
                                                <?php echo $session['success'] ? 'Success' : 'Failed'; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="form-section">
                                <h4>Current Session</h4>
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <p><strong>IP Address:</strong> <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
                                    <p><strong>Browser:</strong> <?php echo htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'], 0, 100)); ?>...</p>
                                    <p><strong>Session Started:</strong> <?php echo date('F j, Y g:i A'); ?></p>
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
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="avatarForm">
                    <input type="hidden" name="action" value="update_avatar">
                    
                    <div class="avatar-preview" id="avatarPreview">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Current Avatar">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="file-upload" onclick="document.getElementById('avatar').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div>Click to upload new profile picture</div>
                        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif" style="display: none;" onchange="previewAvatar(this)">
                        <small>JPG, PNG or GIF (Max 2MB)</small>
                    </div>
                    
                    <div id="uploadError" style="color: var(--danger); margin-top: 1rem; display: none;"></div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary" id="uploadBtn">Update Picture</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
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

        // Tab switching
        function switchTab(tabName) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
            
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            document.querySelectorAll('.profile-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            const targetTab = document.getElementById(tabName + '-tab');
            if (targetTab) {
                targetTab.classList.add('active');
            }
            if (event && event.target) {
                event.target.classList.add('active');
            }
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            if (!strengthBar) return;
            
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

        // Avatar preview
        function previewAvatar(input) {
            const preview = document.getElementById('avatarPreview');
            if (!preview) return;
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Avatar Preview">`;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Modal functions
        const avatarModal = document.getElementById('avatarModal');
        const avatarUploadBtn = document.getElementById('avatarUploadBtn');
        
        function openModal() {
            if (avatarModal) avatarModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            if (avatarModal) avatarModal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        if (avatarUploadBtn) {
            avatarUploadBtn.addEventListener('click', openModal);
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === avatarModal) {
                closeModal();
            }
        });

        // Password form validation
        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match');
                return false;
            }
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const containers = document.querySelectorAll('.profile-container, .profile-sidebar, .profile-content');
            containers.forEach((container, index) => {
                container.style.animation = 'fadeInUp 0.4s ease forwards';
                container.style.animationDelay = `${index * 0.05}s`;
                container.style.opacity = '0';
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
                containers.forEach(container => {
                    container.style.opacity = '1';
                });
            }, 500);
        });
    </script>
</body>
</html>