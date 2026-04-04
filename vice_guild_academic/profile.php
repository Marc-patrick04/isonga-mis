<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

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
    // Academic tickets (category_id = 1 for Academic Issues)
    $stmt = $pdo->query("SELECT COUNT(*) as academic_tickets FROM tickets WHERE category_id = 1");
    $academic_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['academic_tickets'];
    
    // Open academic tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_academic_tickets FROM tickets WHERE category_id = 1 AND status = 'open'");
    $open_academic_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_academic_tickets'];
    
    // Academic reports - using the correct report_type and user role
    $stmt = $pdo->query("SELECT COUNT(*) as academic_reports FROM reports WHERE report_type = 'academic' OR user_id IN (SELECT id FROM users WHERE role = 'vice_guild_academic')");
    $academic_reports = $stmt->fetch(PDO::FETCH_ASSOC)['academic_reports'];
    
    // Get unread messages count - using conversation_participants table
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    
} catch (PDOException $e) {
    $academic_tickets = $open_academic_tickets = $academic_reports = $unread_messages = 0;
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
                        
                        // Also update committee_members photo_url if user is a committee member
                        $committeeStmt = $pdo->prepare("UPDATE committee_members SET photo_url = ?, updated_at = NOW() WHERE user_id = ?");
                        $committeeStmt->execute([$avatar_url, $user_id]);
                        
                        $_SESSION['success'] = "Profile picture updated successfully";
                        $_SESSION['avatar_url'] = $avatar_url; // Update session
                    } else {
                        $_SESSION['error'] = "Failed to upload profile picture";
                    }
                } else {
                    $upload_error = $_FILES['avatar']['error'] ?? 'Unknown error';
                    $_SESSION['error'] = "Please select a valid image file. Error: " . $upload_error;
                }
                break;
                
            case 'enable_2fa':
                // In a real implementation, this would set up 2FA
                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = TRUE, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "Two-factor authentication enabled successfully";
                break;
                
            case 'disable_2fa':
                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = FALSE, updated_at = NOW() WHERE id = ?");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Settings - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --border-radius: 8px;
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
            --academic-primary: #4CAF50;
            --academic-secondary: #66BB6A;
            --academic-accent: #2E7D32;
            --academic-light: #1B3E1B;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
            font-size: 0.875rem;
            transition: var(--transition);
            overflow-x: hidden;
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
            color: var(--academic-primary);
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
            border-color: var(--academic-primary);
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
            background: var(--academic-primary);
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
            background: var(--academic-light);
            border-left-color: var(--academic-primary);
            color: var(--academic-primary);
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
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

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Profile Layout */
        .profile-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            margin-top: 1.5rem;
        }

        .profile-sidebar {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 2rem;
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
            background: var(--academic-primary);
            color: white;
            width: 36px;
            height: 36px;
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
            background: var(--academic-accent);
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .profile-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .profile-role {
            color: var(--academic-primary);
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1.5rem 0;
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
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

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
        }

        .profile-tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--dark-gray);
            border-bottom: 3px solid transparent;
        }

        .profile-tab:hover {
            background: var(--white);
            color: var(--text-dark);
        }

        .profile-tab.active {
            color: var(--academic-primary);
            border-bottom-color: var(--academic-primary);
            background: var(--white);
        }

        .tab-content {
            padding: 2rem;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h4 {
            margin-bottom: 1rem;
            color: var(--text-dark);
            border-bottom: 2px solid var(--academic-light);
            padding-bottom: 0.5rem;
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
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

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
            background: var(--academic-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--academic-primary);
        }

        .session-info {
            flex: 1;
        }

        .session-location {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .session-meta {
            font-size: 0.8rem;
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
        .strength-good { background: #17a2b8; width: 75%; }
        .strength-strong { background: var(--success); width: 100%; }

        .btn {
            padding: 0.5rem 1rem;
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
            animation: fadeIn 0.3s;
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 2% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-md);
            animation: slideIn 0.3s;
            position: relative;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 1.2rem;
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
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
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--academic-primary);
            background: var(--academic-light);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* ── Mobile Nav Overlay ── */
        .mobile-nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
            backdrop-filter: blur(2px);
        }
        .mobile-nav-overlay.active { display: block; }

        /* ── Hamburger Button ── */
        .hamburger-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .hamburger-btn:hover {
            background: var(--academic-primary);
            color: white;
        }

        /* ── Sidebar Drawer ── */
        .sidebar { transition: transform 0.3s ease; }

        /* ── Tablet ── */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

        /* ── Tablet & below (drawer threshold) ── */
        @media (max-width: 900px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 260px;
                height: 100vh;
                z-index: 200;
                transform: translateX(-100%);
                padding-top: 1rem;
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .hamburger-btn {
                display: flex;
            }

            .main-content {
                height: auto;
                min-height: calc(100vh - 80px);
            }

            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        /* ── Mobile ── */
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

            .form-row {
                grid-template-columns: 1fr;
            }

            /* Profile tabs: scroll horizontally on mobile */
            .profile-tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                flex-wrap: nowrap;
            }
            .profile-tabs::-webkit-scrollbar { display: none; }

            .profile-tab {
                white-space: nowrap;
                flex-shrink: 0;
                padding: 0.85rem 1rem;
                font-size: 0.8rem;
            }

            .tab-content {
                padding: 1.25rem;
            }

            .modal-content {
                margin: 8% auto;
                width: 95%;
            }

            .modal-actions {
                flex-direction: column-reverse;
            }

            .modal-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .profile-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .login-session {
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .session-status {
                margin-left: auto;
            }

            .page-header {
                padding: 1rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
            }
        }

        /* ── Small phones ── */
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .header {
                height: 68px;
            }

            .logos .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .profile-sidebar {
                padding: 1.25rem;
            }

            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 1.6rem;
            }

            .profile-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .tab-content {
                padding: 1rem;
            }

            .form-section {
                margin-bottom: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger-btn" id="hamburgerBtn" title="Toggle Menu" aria-label="Open navigation menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Affairs</h1>
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
                        <div class="user-role">Vice Guild Academic</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Mobile Nav Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay"></div>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
    <a href="academic_meetings.php">
        <i class="fas fa-calendar-check"></i>
        <span>Meetings</span>
        <?php
        // Count upcoming meetings where user is invited
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as upcoming_meetings 
                FROM meeting_attendees ma 
                JOIN meetings m ON ma.meeting_id = m.id 
                WHERE ma.user_id = ? 
                AND m.meeting_date >= CURDATE() 
                AND m.status = 'scheduled'
                AND ma.attendance_status = 'invited'
            ");
            $stmt->execute([$user_id]);
            $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'];
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
                    <a href="academic_tickets.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academic Tickets</span>
                       
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Academic Reports</span>
                       
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
                    </a>
                </li>
                 <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
              
                <li class="menu-item">
                    <a href="innovation_projects.php">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
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
                    <a href="academic_calendar.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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

        <main class="main-content">
            <div class="container">
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
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
                        <div class="profile-role">Vice Guild Academic</div>
                        
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $academic_tickets; ?></div>
                                <div class="stat-label">Academic Tickets</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $unread_messages; ?></div>
                                <div class="stat-label">Unread Messages</div>
                            </div>
                        </div>
                        
                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
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
                            </button>
                            <button class="profile-tab <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>" 
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
                                                       value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="gender">Gender</label>
                                            <select id="gender" name="gender" class="form-control" required>
                                                <option value="">Select Gender</option>
                                                <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                               
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h4>Additional Information</h4>
                                        <div class="form-group">
                                            <label for="bio">Bio</label>
                                            <textarea id="bio" name="bio" class="form-control" 
                                                      placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
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
                                            <small class="form-hint">Password must be at least 8 characters long</small>
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
                                    <h4>Account Security</h4>
                                    <div style="font-size: 0.9rem; color: var(--dark-gray);">
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
                                        <h4>Notification Preferences</h4>
                                        <div class="form-group">
                                            <div class="checkbox-group">
                                                <input type="checkbox" id="email_notifications" name="email_notifications" value="1" 
                                                       <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                                <label for="email_notifications">Email Notifications</label>
                                            </div>
                                            <small class="form-hint">Receive notifications via email</small>
                                        </div>
                                        
                                        <div class="form-group">
                                            <div class="checkbox-group">
                                                <input type="checkbox" id="sms_notifications" name="sms_notifications" value="1" 
                                                       <?php echo ($user['sms_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                                <label for="sms_notifications">SMS Notifications</label>
                                            </div>
                                            <small class="form-hint">Receive notifications via SMS (when available)</small>
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
                                                <option value="light" <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'selected' : ''; ?>>Light</option>
                                                <option value="dark" <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                                <option value="auto" <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>Auto (System)</option>
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
                                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                            <i class="fas fa-history" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
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
                                                        <?php echo htmlspecialchars($session['user_agent']); ?> • 
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
                                    <div style="padding: 1rem; background: var(--academic-light); border-radius: var(--border-radius);">
                                        <p><strong>IP Address:</strong> <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
                                        <p><strong>User Agent:</strong> <?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT']); ?></p>
                                        <p><strong>Session Started:</strong> <?php echo date('F j, Y g:i A'); ?></p>
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
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Update Profile Picture</h3>
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
                    
                    <label class="file-upload" for="avatar">
                        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif" style="display: none;" onchange="previewAvatar(this)">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div>Click to upload new profile picture</div>
                        <small style="color: var(--dark-gray);">JPG, PNG or GIF (Max 2MB)</small>
                    </label>
                    
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
        // Profile Management JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - initializing profile scripts');

            // ── Mobile Nav (hamburger sidebar) ──
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const navSidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('mobileNavOverlay');

            function openNavSidebar() {
                navSidebar.classList.add('open');
                overlay.classList.add('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-times"></i>';
                document.body.style.overflow = 'hidden';
            }

            function closeNavSidebar() {
                navSidebar.classList.remove('open');
                overlay.classList.remove('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }

            hamburgerBtn?.addEventListener('click', () => {
                navSidebar.classList.contains('open') ? closeNavSidebar() : openNavSidebar();
            });

            overlay?.addEventListener('click', closeNavSidebar);

            window.addEventListener('resize', () => {
                if (window.innerWidth > 900) closeNavSidebar();
            });

            // Modal elements
            const avatarModal = document.getElementById('avatarModal');
            const avatarUploadBtn = document.getElementById('avatarUploadBtn');
            const closeButtons = document.querySelectorAll('.close, .close-modal');
            const avatarForm = document.getElementById('avatarForm');
            const avatarInput = document.getElementById('avatar');
            const uploadBtn = document.getElementById('uploadBtn');
            const uploadError = document.getElementById('uploadError');

            // Debug logging
            console.log('Avatar Modal:', avatarModal);
            console.log('Avatar Upload Button:', avatarUploadBtn);
            console.log('Avatar Form:', avatarForm);
            console.log('Avatar Input:', avatarInput);

            // Close modals
            closeButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    console.log('Close button clicked');
                    closeAllModals();
                });
            });

            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    console.log('Modal background clicked');
                    closeAllModals();
                }
            });

            // Avatar upload trigger - FIXED
            if (avatarUploadBtn) {
                avatarUploadBtn.addEventListener('click', function(e) {
                    console.log('Avatar upload button clicked');
                    e.preventDefault();
                    e.stopPropagation();
                    avatarModal.style.display = 'block';
                    document.body.style.overflow = 'hidden'; // Prevent background scrolling
                });
            } else {
                console.error('Avatar upload button not found!');
            }

            // Avatar form validation
            if (avatarForm) {
                avatarForm.addEventListener('submit', function(e) {
                    console.log('Avatar form submitted');
                    
                    if (!avatarInput.files.length) {
                        e.preventDefault();
                        showUploadError('Please select a file to upload');
                        return false;
                    }

                    const file = avatarInput.files[0];
                    const maxSize = 2 * 1024 * 1024; // 2MB

                    if (file.size > maxSize) {
                        e.preventDefault();
                        showUploadError('File size must be less than 2MB');
                        return false;
                    }

                    // Show loading state
                    uploadBtn.disabled = true;
                    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                    
                    console.log('Form submission proceeding...');
                });
            }

            // File input change handler
            if (avatarInput) {
                avatarInput.addEventListener('change', function(e) {
                    console.log('File input changed');
                    hideUploadError();
                    const file = e.target.files[0];
                    
                    if (file) {
                        console.log('File selected:', file.name, file.type, file.size);
                        
                        // Validate file type
                        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                        if (!allowedTypes.includes(file.type)) {
                            showUploadError('Please select a valid image file (JPG, PNG, GIF)');
                            avatarInput.value = '';
                            return;
                        }

                        // Validate file size
                        const maxSize = 2 * 1024 * 1024;
                        if (file.size > maxSize) {
                            showUploadError('File size must be less than 2MB');
                            avatarInput.value = '';
                            return;
                        }

                        previewAvatar(this);
                    }
                });
            }

            // File upload label click handler
            const fileUploadLabel = document.querySelector('.file-upload');
            if (fileUploadLabel) {
                fileUploadLabel.addEventListener('click', function(e) {
                    console.log('File upload label clicked');
                    if (avatarInput) {
                        avatarInput.click();
                    }
                });
            }

            // Functions
            function closeAllModals() {
                console.log('Closing all modals');
                if (avatarModal) {
                    avatarModal.style.display = 'none';
                }
                document.body.style.overflow = ''; // Restore scrolling
                hideUploadError();
                // Reset form
                if (avatarInput) {
                    avatarInput.value = '';
                }
                if (uploadBtn) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = 'Update Picture';
                }
            }

            function showUploadError(message) {
                console.log('Upload error:', message);
                if (uploadError) {
                    uploadError.textContent = message;
                    uploadError.style.display = 'block';
                }
            }

            function hideUploadError() {
                if (uploadError) {
                    uploadError.style.display = 'none';
                }
            }

           
        });

        // Tab switching
        function switchTab(tabName) {
            console.log('Switching to tab:', tabName);
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
            
            // Hide all tab panes
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.profile-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            const targetTab = document.getElementById(tabName + '-tab');
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Activate selected tab button
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

        // Form validation
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

        // Debug helper
        console.log('Profile scripts loaded successfully');
    </script>
</body>
</html>