<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Initialize variables to prevent undefined errors
$unread_messages = 0;
$pending_tickets = 0;
$pr_activities = $news_count = $announcements_count = $associations_count = $gallery_count = 0;

// Get user profile data
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

// Get dashboard statistics for sidebar
try {
    // Public relations activities count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pr_activities 
        FROM events 
        WHERE status = 'published' 
        AND created_at >= (CURRENT_DATE - INTERVAL '30 days')
    ");
    $stmt->execute();
    $pr_activities = $stmt->fetch(PDO::FETCH_ASSOC)['pr_activities'] ?? 0;
    
    // News articles count
    $stmt = $pdo->query("SELECT COUNT(*) as news_count FROM news WHERE status = 'published'");
    $news_count = $stmt->fetch(PDO::FETCH_ASSOC)['news_count'] ?? 0;
    
    // Announcements count
    $stmt = $pdo->query("SELECT COUNT(*) as announcements_count FROM announcements");
    $announcements_count = $stmt->fetch(PDO::FETCH_ASSOC)['announcements_count'] ?? 0;
    
    // Associations count
    $stmt = $pdo->query("SELECT COUNT(*) as associations_count FROM clubs WHERE category = 'cultural' AND status = 'active'");
    $associations_count = $stmt->fetch(PDO::FETCH_ASSOC)['associations_count'] ?? 0;
    
    // Gallery images count
    $stmt = $pdo->query("SELECT COUNT(*) as gallery_count FROM gallery_images WHERE status = 'active'");
    $gallery_count = $stmt->fetch(PDO::FETCH_ASSOC)['gallery_count'] ?? 0;
    
    // Get unread messages count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
    // Get pending tickets count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE assigned_to = ? AND status IN ('open', 'in_progress')
    ");
    $stmt->execute([$user_id]);
    $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    
} catch (PDOException $e) {
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
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $date_of_birth = $_POST['date_of_birth'] ?? null;
                $gender = $_POST['gender'] ?? null;
                $bio = trim($_POST['bio'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
                $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
                
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
                        updated_at = CURRENT_TIMESTAMP
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
                        SET bio = ?, updated_at = CURRENT_TIMESTAMP 
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
                    SET password = ?, last_password_change = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
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
                        preferred_language = ?, theme_preference = ?, updated_at = CURRENT_TIMESTAMP
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
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $file_type = finfo_file($finfo, $avatar['tmp_name']);
                    finfo_close($finfo);
                    
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
                    $file_extension = strtolower(pathinfo($avatar['name'], PATHINFO_EXTENSION));
                    $filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($avatar['tmp_name'], $file_path)) {
                        // Update database with relative path
                        $avatar_url = 'assets/uploads/avatars/' . $filename;
                        $stmt = $pdo->prepare("UPDATE users SET avatar_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$avatar_url, $user_id]);
                        
                        // Also update committee_members photo_url if user is a committee member
                        $committeeStmt = $pdo->prepare("UPDATE committee_members SET photo_url = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
                        $committeeStmt->execute([$avatar_url, $user_id]);
                        
                        $_SESSION['success'] = "Profile picture updated successfully";
                    } else {
                        $_SESSION['error'] = "Failed to upload profile picture";
                    }
                } else {
                    $_SESSION['error'] = "Please select a valid image file";
                }
                break;
                
            case 'enable_2fa':
                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = TRUE, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "Two-factor authentication enabled successfully";
                break;
                
            case 'disable_2fa':
                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
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
        error_log("Profile action error: " . $e->getMessage());
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
    error_log("Login history error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Profile & Settings - Minister of Public Relations - Isonga RPSU</title>
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
            --info: #17a2b8;
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
            font-size: 0.875rem;
            min-height: 100vh;
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

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
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
            animation: fadeInUp 0.3s ease;
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

        /* Profile Layout */
        .profile-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
        }

        .profile-sidebar {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            text-align: center;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
            animation-delay: 0.05s;
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
            background: var(--primary-blue);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid white;
            z-index: 10;
        }

        .avatar-upload:hover {
            background: var(--accent-blue);
            transform: scale(1.1);
        }

        .profile-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .profile-role {
            color: var(--primary-blue);
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1.5rem 0;
            padding: 1rem 0;
            border-top: 1px solid var(--medium-gray);
            border-bottom: 1px solid var(--medium-gray);
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

        .profile-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
            animation-delay: 0.1s;
        }

        .profile-tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
            flex-wrap: wrap;
        }

        .profile-tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--dark-gray);
            border-bottom: 3px solid transparent;
            font-size: 0.85rem;
        }

        .profile-tab:hover {
            background: var(--white);
            color: var(--text-dark);
        }

        .profile-tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
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
            animation: fadeIn 0.3s ease;
        }

        .form-section {
            margin-bottom: 1.5rem;
        }

        .form-section h4 {
            margin-bottom: 1rem;
            color: var(--text-dark);
            border-bottom: 2px solid var(--light-blue);
            padding-bottom: 0.5rem;
            font-size: 1rem;
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
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
            margin: 0;
        }

        .form-hint {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
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

        .login-session {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            transition: var(--transition);
        }

        .login-session:hover {
            background: var(--light-gray);
        }

        .session-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-blue);
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
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .status-success {
            background: #d4edda;
            color: var(--success);
        }

        .status-failed {
            background: #f8d7da;
            color: var(--danger);
        }

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

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: var(--white);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-blue);
            position: sticky;
            top: 0;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            line-height: 1;
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
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
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
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

        /* Animations */
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

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 4rem;
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

            .profile-container {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .profile-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .profile-tab {
                white-space: nowrap;
            }

            .profile-stats {
                grid-template-columns: repeat(2, 1fr);
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

            .profile-stats {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .page-header {
                padding: 1rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
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
                <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Profile & Settings</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Public Relations</div>
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
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                        <?php if ($announcements_count > 0): ?>
                            <span class="menu-badge"><?php echo $announcements_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                        <?php if ($news_count > 0): ?>
                            <span class="menu-badge"><?php echo $news_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                        <?php if ($gallery_count > 0): ?>
                            <span class="menu-badge"><?php echo $gallery_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                        <?php if ($associations_count > 0): ?>
                            <span class="menu-badge"><?php echo $associations_count; ?></span>
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

        <main class="main-content" id="mainContent">
            <div class="container">
                <!-- Page Header -->
                <!-- <div class="page-header">
                    <div class="page-title">
                        <h1><i class="fas fa-user-circle"></i> Profile & Settings</h1>
                      
                    </div>
                </div> -->

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
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
                        <div class="profile-role">Minister of Public Relations & Associations</div>
                        
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($news_count); ?></div>
                                <div class="stat-label">News Articles</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($announcements_count); ?></div>
                                <div class="stat-label">Announcements</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($associations_count); ?></div>
                                <div class="stat-label">Associations</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($gallery_count); ?></div>
                                <div class="stat-label">Gallery Images</div>
                            </div>
                        </div>
                        
                        <div style="font-size: 0.75rem; color: var(--dark-gray); text-align: left;">
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
                            <!-- <button class="profile-tab <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" 
                                    onclick="switchTab('preferences')">
                                <i class="fas fa-cog"></i> Preferences
                            </button> -->
                            <!-- <button class="profile-tab <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>" 
                                    onclick="switchTab('sessions')">
                                <i class="fas fa-history"></i> Login History
                            </button> -->
                        </div>

                        <!-- Tab Content -->
                        <div class="tab-content">
                            <!-- Personal Information Tab -->
                            <div id="profile-tab" class="tab-pane <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                                <form method="POST" id="profileForm">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="form-section">
                                        <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="full_name">Full Name <span style="color: var(--danger);">*</span></label>
                                                <input type="text" id="full_name" name="full_name" class="form-control" 
                                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="email">Email Address <span style="color: var(--danger);">*</span></label>
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
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4><i class="fas fa-bullhorn"></i> Public Relations Portfolio</h4>
                                        <div class="form-group">
                                            <label for="bio">Bio & Public Relations Initiatives</label>
                                            <textarea id="bio" name="bio" class="form-control" 
                                                      placeholder="Tell us about your public relations initiatives, media campaigns, and student communication programs..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="address">Address</label>
                                            <textarea id="address" name="address" class="form-control" 
                                                      placeholder="Your current address..."><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4><i class="fas fa-phone-alt"></i> Emergency Contact</h4>
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
                                    <h4><i class="fas fa-key"></i> Change Password</h4>
                                    <form method="POST" id="passwordForm">
                                        <input type="hidden" name="action" value="change_password">
                                        
                                        <div class="form-group">
                                            <label for="current_password">Current Password <span style="color: var(--danger);">*</span></label>
                                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="new_password">New Password <span style="color: var(--danger);">*</span></label>
                                            <input type="password" id="new_password" name="new_password" class="form-control" required 
                                                   onkeyup="checkPasswordStrength(this.value)">
                                            <div class="password-strength">
                                                <div class="password-strength-fill" id="passwordStrength"></div>
                                            </div>
                                            <div class="form-hint">Password must be at least 8 characters long</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="confirm_password">Confirm New Password <span style="color: var(--danger);">*</span></label>
                                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                        </div>
                                        
                                        <div class="modal-actions">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-key"></i> Change Password
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- <div class="form-section">
                                    <h4><i class="fas fa-shield-alt"></i> Two-Factor Authentication</h4>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--light-blue); border-radius: var(--border-radius);">
                                        <div>
                                            <div style="font-weight: 600; margin-bottom: 0.25rem;">Two-Factor Authentication</div>
                                            <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                                <?php echo $user['two_factor_enabled'] ? 'Enabled - Extra security layer active' : 'Disabled - Recommended for better security'; ?>
                                            </div>
                                        </div>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="<?php echo $user['two_factor_enabled'] ? 'disable_2fa' : 'enable_2fa'; ?>">
                                            <button type="submit" class="btn <?php echo $user['two_factor_enabled'] ? 'btn-secondary' : 'btn-primary'; ?>">
                                                <?php echo $user['two_factor_enabled'] ? 'Disable' : 'Enable'; ?> 2FA
                                            </button>
                                        </form>
                                    </div>
                                </div> -->

                                <div class="form-section">
                                    <h4><i class="fas fa-info-circle"></i> Account Security</h4>
                                    <div style="font-size: 0.85rem; color: var(--dark-gray);">
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
                                <form method="POST" id="preferencesForm">
                                    <input type="hidden" name="action" value="update_preferences">
                                    
                                    <div class="form-section">
                                        <h4><i class="fas fa-bell"></i> Notification Preferences</h4>
                                        <div class="form-group">
                                            <div class="checkbox-group">
                                                <input type="checkbox" id="email_notifications" name="email_notifications" value="1" 
                                                       <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                                <label for="email_notifications">Email Notifications</label>
                                            </div>
                                            <div class="form-hint">Receive public relations alerts and media notifications via email</div>
                                        </div>
                                        
                                        <!-- <div class="form-group">
                                            <div class="checkbox-group">
                                                <input type="checkbox" id="sms_notifications" name="sms_notifications" value="1" 
                                                       <?php echo ($user['sms_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                                <label for="sms_notifications">SMS Notifications</label>
                                            </div>
                                            <div class="form-hint">Receive urgent public relations alerts via SMS</div>
                                        </div> -->
                                    </div>

                                    <div class="form-section">
                                        <h4><i class="fas fa-language"></i> Language & Region</h4>
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
                                        <h4><i class="fas fa-palette"></i> Appearance</h4>
                                        <div class="form-group">
                                            <label for="theme_preference">Theme Preference</label>
                                            <select id="theme_preference" name="theme_preference" class="form-control">
                                                <option value="light" <?php echo ($user['theme_preference'] ?? 'light') === 'light' ? 'selected' : ''; ?>>Light</option>
                                                <option value="dark" <?php echo ($user['theme_preference'] ?? 'light') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                            </select>
                                            <div class="form-hint">Choose your preferred theme for the dashboard</div>
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
                            <!-- <div id="sessions-tab" class="tab-pane <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>">
                                <div class="form-section">
                                    <h4><i class="fas fa-history"></i> Recent Login Activity</h4>
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
                                                    </div>
                                                    <div class="session-meta">
                                                        <?php echo htmlspecialchars(substr($session['user_agent'] ?? '', 0, 50)); ?> • 
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
                                    <h4><i class="fas fa-laptop"></i> Current Session</h4>
                                    <div style="padding: 1rem; background: var(--light-blue); border-radius: var(--border-radius);">
                                        <p><strong>IP Address:</strong> <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
                                        <p><strong>User Agent:</strong> <?php echo htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)); ?></p>
                                        <p><strong>Session Started:</strong> <?php echo date('F j, Y g:i A'); ?></p>
                                    </div>
                                </div>
                            </div> -->
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
                <h3><i class="fas fa-camera"></i> Update Profile Picture</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="avatarForm">
                    <input type="hidden" name="action" value="update_avatar">
                    
                    <div class="avatar-preview" id="avatarPreview">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Current Avatar">
                        <?php else: ?>
                            <i class="fas fa-user" style="font-size: 3rem; color: var(--dark-gray);"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="file-upload" id="fileUploadArea">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div>Click to upload new profile picture</div>
                        <div class="form-hint">JPG, PNG or GIF (Max 2MB)</div>
                        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif" style="display: none;">
                    </div>
                    
                    <div id="uploadError" style="color: var(--danger); margin-top: 1rem; display: none;"></div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary" id="uploadBtn">Update Picture</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
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
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
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

        // Avatar upload modal
        const avatarModal = document.getElementById('avatarModal');
        const avatarUploadBtn = document.getElementById('avatarUploadBtn');
        const avatarInput = document.getElementById('avatar');
        const uploadBtn = document.getElementById('uploadBtn');
        const uploadError = document.getElementById('uploadError');

        function openAvatarModal() {
            avatarModal.classList.add('show');
        }

        function closeAvatarModal() {
            avatarModal.classList.remove('show');
            if (avatarInput) avatarInput.value = '';
            if (uploadError) uploadError.style.display = 'none';
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = 'Update Picture';
            }
        }

        if (avatarUploadBtn) {
            avatarUploadBtn.addEventListener('click', openAvatarModal);
        }

        document.querySelectorAll('.close, .close-modal').forEach(btn => {
            btn.addEventListener('click', closeAvatarModal);
        });

        window.addEventListener('click', function(event) {
            if (event.target === avatarModal) {
                closeAvatarModal();
            }
        });

        // File upload handling
        const fileUploadArea = document.getElementById('fileUploadArea');
        
        if (fileUploadArea) {
            fileUploadArea.addEventListener('click', function() {
                if (avatarInput) avatarInput.click();
            });
        }

        if (avatarInput) {
            avatarInput.addEventListener('change', function(e) {
                if (uploadError) uploadError.style.display = 'none';
                const file = e.target.files[0];
                
                if (file) {
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                    if (!allowedTypes.includes(file.type)) {
                        if (uploadError) {
                            uploadError.textContent = 'Please select a valid image file (JPG, PNG, GIF)';
                            uploadError.style.display = 'block';
                        }
                        avatarInput.value = '';
                        return;
                    }
                    
                    if (file.size > 2 * 1024 * 1024) {
                        if (uploadError) {
                            uploadError.textContent = 'File size must be less than 2MB';
                            uploadError.style.display = 'block';
                        }
                        avatarInput.value = '';
                        return;
                    }
                    
                    // Preview avatar
                    const preview = document.getElementById('avatarPreview');
                    if (preview) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" alt="Avatar Preview">`;
                        }
                        reader.readAsDataURL(file);
                    }
                }
            });
        }

        // Form validation
        const passwordForm = document.getElementById('passwordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
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
        }

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const animatedElements = document.querySelectorAll('.page-header, .profile-sidebar, .profile-content');
            animatedElements.forEach((el, index) => {
                el.style.opacity = '1';
            });
        });
    </script>
</body>
</html>