<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

// Cast to int — prevents type mismatch between session string and DB integer column
$user_id = (int) $_SESSION['user_id'];

// Get user profile data
// Strategy: try a simple query first (most compatible), committee data via subquery
// to avoid duplicate rows when a user appears in committee_members more than once.
$user = null;
try {
    $stmt = $pdo->prepare("
        SELECT u.*,
               d.name  AS department_name,
               p.name  AS program_name,
               cm.role AS committee_role,
               cm.bio  AS committee_bio,
               cm.portfolio_description,
               cm.photo_url AS committee_photo
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs    p ON u.program_id    = p.id
        LEFT JOIN committee_members cm
               ON cm.id = (
                   SELECT id FROM committee_members
                   WHERE  user_id = u.id
                   ORDER  BY id DESC
                   LIMIT  1
               )
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Absolute fallback — fetch user row only, no committee columns
    try {
        $stmt = $pdo->prepare("
            SELECT u.*,
                   d.name AS department_name,
                   p.name AS program_name,
                   NULL   AS committee_role,
                   NULL   AS committee_bio,
                   NULL   AS portfolio_description,
                   NULL   AS committee_photo
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs    p ON u.program_id    = p.id
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        // DB completely unreachable — log and show generic error
        error_log("Profile load failed for user $user_id: " . $e2->getMessage());
    }
}

// If the user row still doesn't exist the session is stale — force re-login
if (!$user) {
    session_destroy();
    header('Location: ../auth/login.php?error=session_invalid');
    exit();
}

// Get sidebar stats (PostgreSQL-compatible)
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];

    $stmt = $pdo->query("SELECT COUNT(*) AS open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS my_tickets FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $my_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['my_tickets'];

    // Unread messages
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS unread_messages
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ?
          AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];

    // Pending meeting minutes
    $stmt = $pdo->query("SELECT COUNT(*) AS pending_minutes FROM meeting_minutes WHERE approval_status = 'draft'");
    $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'];

    // Pending documents
    $stmt = $pdo->query("SELECT COUNT(*) AS pending_docs FROM documents WHERE status = 'draft'");
    $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'];

} catch (PDOException $e) {
    $total_tickets = $open_tickets = $my_tickets = $unread_messages = $pending_minutes = $pending_docs = 0;
}

// Active tab
$active_tab = $_GET['tab'] ?? 'profile';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update_profile':
                $full_name  = $_POST['full_name']  ?? '';
                $email      = $_POST['email']      ?? '';
                $phone      = $_POST['phone']      ?? '';
                $date_of_birth = $_POST['date_of_birth'] ?? '';
                $gender     = $_POST['gender']     ?? '';
                $bio        = $_POST['bio']        ?? '';
                $address    = $_POST['address']    ?? '';
                $emergency_contact_name  = $_POST['emergency_contact_name']  ?? '';
                $emergency_contact_phone = $_POST['emergency_contact_phone'] ?? '';
                $department_id = $_POST['department_id'] ?: null;
                $program_id    = $_POST['program_id']    ?: null;

                if (empty($full_name) || empty($email)) {
                    $_SESSION['error'] = "Full name and email are required";
                    break;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error'] = "Please enter a valid email address";
                    break;
                }

                // Check duplicate email (PostgreSQL placeholder style)
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
                        department_id = ?, program_id = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $full_name, $email, $phone, $date_of_birth ?: null, $gender,
                    $bio, $address, $emergency_contact_name, $emergency_contact_phone,
                    $department_id, $program_id, $user_id
                ]);

                $_SESSION['full_name'] = $full_name;
                $_SESSION['email']     = $email;
                $_SESSION['success']   = "Profile updated successfully";
                break;

            case 'change_password':
                $current_password  = $_POST['current_password']  ?? '';
                $new_password      = $_POST['new_password']      ?? '';
                $confirm_password  = $_POST['confirm_password']  ?? '';

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

                $currentPasswordStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $currentPasswordStmt->execute([$user_id]);
                $currentUser = $currentPasswordStmt->fetch(PDO::FETCH_ASSOC);

                // NOTE: In production use password_verify() with hashed passwords
                if (!$currentUser || $currentUser['password'] !== $current_password) {
                    $_SESSION['error'] = "Current password is incorrect";
                    break;
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET password = ?, last_password_change = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$new_password, $user_id]);
                $_SESSION['success'] = "Password changed successfully";
                break;

            case 'update_preferences':
                $email_notifications = isset($_POST['email_notifications']) ? true : false;
                $sms_notifications   = isset($_POST['sms_notifications'])   ? true : false;
                $preferred_language  = $_POST['preferred_language']  ?? 'en';
                $theme_preference    = $_POST['theme_preference']    ?? 'auto';

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
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                    $file_type = mime_content_type($avatar['tmp_name']);

                    if (!in_array($file_type, $allowed_types)) {
                        $_SESSION['error'] = "Only JPG, PNG, and GIF images are allowed";
                        break;
                    }
                    if ($avatar['size'] > 2 * 1024 * 1024) {
                        $_SESSION['error'] = "Image size must be less than 2MB";
                        break;
                    }

                    $upload_dir = '../assets/uploads/avatars/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $file_extension = pathinfo($avatar['name'], PATHINFO_EXTENSION);
                    $filename  = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;

                    if (move_uploaded_file($avatar['tmp_name'], $file_path)) {
                        $avatar_url = 'assets/uploads/avatars/' . $filename;
                        $stmt = $pdo->prepare("UPDATE users SET avatar_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$avatar_url, $user_id]);

                        $committeeStmt = $pdo->prepare("UPDATE committee_members SET photo_url = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
                        $committeeStmt->execute([$avatar_url, $user_id]);

                        $_SESSION['success'] = "Profile picture updated successfully";
                        $_SESSION['avatar_url'] = $avatar_url;
                    } else {
                        $_SESSION['error'] = "Failed to upload profile picture";
                    }
                } else {
                    $upload_error = $_FILES['avatar']['error'] ?? 'Unknown error';
                    $_SESSION['error'] = "Please select a valid image file. Error: " . $upload_error;
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

        // Refresh user data after any action (same safe subquery approach)
        $stmt = $pdo->prepare("
            SELECT u.*,
                   d.name  AS department_name,
                   p.name  AS program_name,
                   cm.role AS committee_role,
                   cm.bio  AS committee_bio,
                   cm.portfolio_description,
                   cm.photo_url AS committee_photo
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs    p ON u.program_id    = p.id
            LEFT JOIN committee_members cm
                   ON cm.id = (
                       SELECT id FROM committee_members
                       WHERE  user_id = u.id
                       ORDER  BY id DESC
                       LIMIT  1
                   )
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

// Login history (PostgreSQL-compatible — uses ?)
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

// Departments and Programs (PostgreSQL: TRUE instead of 1)
try {
    $deptStmt  = $pdo->query("SELECT id, name FROM departments WHERE is_active = TRUE ORDER BY name");
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

    $progStmt  = $pdo->query("SELECT id, name FROM programs WHERE is_active = TRUE ORDER BY name");
    $programs  = $progStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = $programs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Profile &amp; Settings - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* ============================================================
           CSS VARIABLES — identical to dashboard.php
           ============================================================ */
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
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0,0,0,.10);
            --shadow-md: 0 2px 8px rgba(0,0,0,.12);
            --shadow-lg: 0 4px 16px rgba(0,0,0,.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
            --header-height: 73px;
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
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
        }

        /* ============================================================
           RESET & BASE
           ============================================================ */
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* ============================================================
           HEADER  (mirrors dashboard)
           ============================================================ */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: .75rem 0;
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

        .logo-section { display:flex; align-items:center; gap:.75rem; }
        .logos        { display:flex; gap:.75rem; align-items:center; }
        .logo         { height:40px; width:auto; }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        /* Mobile hamburger */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: .5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .user-menu  { display:flex; align-items:center; gap:1rem; }
        .user-info  { display:flex; align-items:center; gap:.75rem; }

        .user-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex; align-items:center; justify-content:center;
            color: white; font-weight:600; font-size:1rem;
            overflow: hidden;
        }
        .user-avatar img { width:100%; height:100%; object-fit:cover; }

        .user-details { text-align:right; }
        .user-name    { font-weight:600; font-size:.9rem; }
        .user-role    { font-size:.75rem; color:var(--dark-gray); }

        .icon-btn {
            width:40px; height:40px;
            border:1px solid var(--medium-gray);
            background:var(--white);
            border-radius:50%;
            cursor:pointer;
            color:var(--text-dark);
            transition:var(--transition);
            display:inline-flex; align-items:center; justify-content:center;
            position:relative; text-decoration:none;
        }
        .icon-btn:hover { background:var(--primary-blue); color:white; border-color:var(--primary-blue); }

        .notification-badge {
            position:absolute; top:-2px; right:-2px;
            background:var(--danger); color:white;
            border-radius:50%; width:18px; height:18px;
            font-size:.6rem;
            display:flex; align-items:center; justify-content:center;
            font-weight:600;
        }

        .logout-btn {
            background:var(--gradient-primary); color:white;
            padding:.5rem 1rem; border-radius:6px;
            text-decoration:none; font-size:.85rem; font-weight:500;
            transition:var(--transition);
        }
        .logout-btn:hover { transform:translateY(-1px); box-shadow:var(--shadow-sm); }

        /* ============================================================
           LAYOUT — sidebar + main (mirrors dashboard fixed sidebar)
           ============================================================ */
        .dashboard-container { display:flex; min-height:calc(100vh - var(--header-height)); }

        /* ---- SIDEBAR ---- */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - var(--header-height));
            overflow-y: auto;
            z-index: 99;
        }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge { display:none; }
        .sidebar.collapsed .menu-item a { justify-content:center; padding:.75rem; }
        .sidebar.collapsed .menu-item i { margin:0; font-size:1.25rem; }

        .sidebar-toggle {
            position:absolute; right:-12px; top:20px;
            width:24px; height:24px;
            background:var(--primary-blue); border:none; border-radius:50%;
            color:white; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            font-size:.75rem; z-index:100;
        }

        .sidebar-menu { list-style:none; }
        .menu-item    { margin-bottom:.25rem; }
        .menu-item a {
            display:flex; align-items:center; gap:.75rem;
            padding:.75rem 1.5rem;
            color:var(--text-dark); text-decoration:none;
            transition:var(--transition);
            border-left:3px solid transparent;
            font-size:.85rem;
        }
        .menu-item a:hover,
        .menu-item a.active {
            background:var(--light-blue);
            border-left-color:var(--primary-blue);
            color:var(--primary-blue);
        }
        .menu-item i { width:20px; }
        .menu-badge {
            background:var(--danger); color:white;
            border-radius:10px; padding:.1rem .4rem;
            font-size:.7rem; font-weight:600; margin-left:auto;
        }

        /* ---- MAIN CONTENT ---- */
        .main-content {
            flex:1; padding:1.5rem;
            overflow-y:auto;
            margin-left:var(--sidebar-width);
            transition:var(--transition);
        }
        .main-content.sidebar-collapsed { margin-left:var(--sidebar-collapsed-width); }

        /* ============================================================
           PAGE HEADER
           ============================================================ */
        .page-header {
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom:1.5rem;
        }
        .page-title h1 {
            font-size:1.5rem; font-weight:700;
            color:var(--text-dark); margin-bottom:.25rem;
        }
        .page-title p { color:var(--dark-gray); font-size:.9rem; }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn {
            display:inline-flex; align-items:center; gap:.5rem;
            padding:.6rem 1.2rem; border-radius:var(--border-radius);
            text-decoration:none; font-weight:600; font-size:.85rem;
            transition:var(--transition); border:none; cursor:pointer;
        }
        .btn-primary  { background:var(--gradient-primary); color:white; }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
        .btn-outline  { background:transparent; border:1px solid var(--primary-blue); color:var(--primary-blue); }
        .btn-outline:hover { background:var(--primary-blue); color:white; }
        .btn-danger   { background:var(--danger); color:white; }
        .btn-danger:hover  { background:#c82333; transform:translateY(-2px); }
        .btn-success  { background:var(--success); color:white; }
        .btn-success:hover { background:#218838; }
        .btn-secondary { background:var(--medium-gray); color:var(--text-dark); }

        /* ============================================================
           ALERTS
           ============================================================ */
        .alert {
            padding:.75rem 1rem; border-radius:var(--border-radius);
            margin-bottom:1rem; border-left:4px solid;
            display:flex; align-items:center; gap:.75rem;
        }
        .alert-success { background:#d4edda; color:#155724; border-left-color:var(--success); }
        .alert-danger  { background:#f8d7da; color:#721c24; border-left-color:var(--danger); }
        .alert a { color:inherit; font-weight:600; text-decoration:none; }
        .alert a:hover { text-decoration:underline; }

        /* ============================================================
           PROFILE LAYOUT
           ============================================================ */
        .profile-container {
            display:grid;
            grid-template-columns: 280px 1fr;
            gap:1.5rem;
        }

        /* ---- Profile sidebar card ---- */
        .profile-sidebar {
            background:var(--white);
            border-radius:var(--border-radius);
            box-shadow:var(--shadow-sm);
            padding:2rem 1.5rem;
            text-align:center;
            height:fit-content;
        }

        .profile-avatar {
            position:relative;
            width:120px; height:120px;
            margin:0 auto 1.25rem;
            border-radius:50%;
            background:var(--gradient-primary);
            display:flex; align-items:center; justify-content:center;
            color:white; font-size:2.5rem; font-weight:600;
            overflow:hidden; cursor:pointer;
        }
        .profile-avatar img { width:100%; height:100%; object-fit:cover; }

        .avatar-upload {
            position:absolute; bottom:0; right:0;
            background:var(--primary-blue); color:white;
            width:34px; height:34px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:.85rem; cursor:pointer; transition:var(--transition);
        }
        .avatar-upload:hover { background:var(--accent-blue); transform:scale(1.1); }

        .profile-name { font-size:1.2rem; font-weight:700; color:var(--text-dark); margin-bottom:.2rem; }
        .profile-role { color:var(--primary-blue); font-weight:600; margin-bottom:1.25rem; font-size:.85rem; }

        .profile-stats {
            display:grid; grid-template-columns:1fr 1fr;
            gap:.75rem; margin-bottom:1.25rem;
        }
        .stat-item {
            background:var(--light-gray); padding:.75rem;
            border-radius:var(--border-radius); text-align:center;
        }
        .stat-number { font-size:1.3rem; font-weight:700; color:var(--primary-blue); margin-bottom:.2rem; }
        .stat-label  { font-size:.75rem; color:var(--dark-gray); }

        .profile-meta {
            font-size:.8rem; color:var(--dark-gray);
            text-align:left; display:flex; flex-direction:column; gap:.4rem;
        }
        .profile-meta p { display:flex; align-items:center; gap:.5rem; }
        .profile-meta i { width:14px; color:var(--primary-blue); }

        /* ---- Profile content card ---- */
        .profile-content {
            background:var(--white);
            border-radius:var(--border-radius);
            box-shadow:var(--shadow-sm);
            overflow:hidden;
        }

        /* Tabs */
        .profile-tabs {
            display:flex;
            border-bottom:1px solid var(--medium-gray);
            background:var(--light-gray);
            overflow-x:auto;
            -webkit-overflow-scrolling:touch;
        }
        .profile-tabs::-webkit-scrollbar { height:3px; }
        .profile-tabs::-webkit-scrollbar-thumb { background:var(--medium-gray); border-radius:3px; }

        .profile-tab {
            flex:1 0 auto;
            min-width:120px;
            padding:.875rem 1.25rem;
            background:none; border:none; cursor:pointer;
            font-weight:500; color:var(--dark-gray);
            transition:var(--transition);
            display:flex; align-items:center; gap:.4rem; justify-content:center;
            font-size:.82rem; white-space:nowrap;
        }
        .profile-tab:hover  { background:var(--medium-gray); color:var(--text-dark); }
        .profile-tab.active {
            background:var(--white); color:var(--primary-blue);
            border-bottom:2px solid var(--primary-blue);
            font-weight:600;
        }

        /* Tab panes */
        .tab-pane         { display:none; padding:1.75rem; }
        .tab-pane.active  { display:block; }

        /* ============================================================
           FORM STYLES
           ============================================================ */
        .form-section      { margin-bottom:1.75rem; }
        .form-section h4 {
            margin-bottom:.875rem; color:var(--text-dark);
            font-size:1rem; border-bottom:1px solid var(--medium-gray);
            padding-bottom:.5rem;
        }
        .form-row   { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .form-group { margin-bottom:.875rem; }

        .form-label {
            display:block; margin-bottom:.4rem;
            font-weight:600; color:var(--text-dark); font-size:.82rem;
        }

        .form-control {
            width:100%; padding:.65rem .875rem;
            border:1px solid var(--medium-gray);
            border-radius:var(--border-radius);
            background:var(--white); color:var(--text-dark);
            font-size:.85rem; transition:var(--transition);
            font-family:inherit;
        }
        .form-control:focus {
            outline:none; border-color:var(--primary-blue);
            box-shadow:0 0 0 3px rgba(0,86,179,.1);
        }
        textarea.form-control { min-height:90px; resize:vertical; }
        .form-control[readonly] { background:var(--light-gray); cursor:not-allowed; }

        .checkbox-group { display:flex; align-items:center; gap:.5rem; }
        .checkbox-group input[type="checkbox"] { width:16px; height:16px; cursor:pointer; }

        .form-hint { display:block; margin-top:.25rem; font-size:.78rem; color:var(--dark-gray); }

        .form-actions {
            display:flex; gap:.75rem; justify-content:flex-end;
            margin-top:1.25rem; padding-top:1rem;
            border-top:1px solid var(--medium-gray);
        }

        /* Password strength */
        .password-strength        { height:4px; background:var(--medium-gray); border-radius:2px; margin-top:.4rem; overflow:hidden; }
        .password-strength-fill   { height:100%; width:0; transition:width .3s ease; }
        .strength-weak   { background:var(--danger);  width:25%;  }
        .strength-fair   { background:var(--warning); width:50%;  }
        .strength-good   { background:var(--info);    width:75%;  }
        .strength-strong { background:var(--success); width:100%; }

        /* ============================================================
           LOGIN SESSION LIST
           ============================================================ */
        .login-session {
            display:flex; align-items:center; gap:1rem;
            padding:1rem; border:1px solid var(--medium-gray);
            border-radius:var(--border-radius); margin-bottom:.75rem;
        }
        .session-icon {
            width:38px; height:38px; border-radius:50%;
            background:var(--light-blue);
            display:flex; align-items:center; justify-content:center;
            color:var(--primary-blue); flex-shrink:0;
        }
        .session-info { flex:1; }
        .session-location { font-weight:600; color:var(--text-dark); font-size:.85rem; }
        .session-meta     { font-size:.78rem; color:var(--dark-gray); }
        .session-status   { padding:.2rem .65rem; border-radius:20px; font-size:.78rem; font-weight:600; white-space:nowrap; }
        .status-success { background:#d4edda; color:var(--success); }
        .status-failed  { background:#f8d7da; color:var(--danger);  }

        /* ============================================================
           MODAL
           ============================================================ */
        .modal {
            display:none; position:fixed; z-index:1000;
            inset:0; background:rgba(0,0,0,.5);
        }
        .modal-content {
            background:var(--white);
            margin:8% auto; max-width:500px; width:calc(100% - 2rem);
            border-radius:var(--border-radius-lg);
            box-shadow:var(--shadow-lg);
            animation:modalSlideIn .3s ease;
        }
        @keyframes modalSlideIn {
            from { transform:translateY(-40px); opacity:0; }
            to   { transform:translateY(0);     opacity:1; }
        }
        .modal-header {
            display:flex; justify-content:space-between; align-items:center;
            padding:1.25rem 1.5rem; border-bottom:1px solid var(--medium-gray);
        }
        .modal-header h3 { margin:0; color:var(--text-dark); font-size:1rem; }
        .close { color:var(--dark-gray); font-size:1.4rem; font-weight:700; cursor:pointer; transition:var(--transition); }
        .close:hover { color:var(--danger); }
        .modal-body { padding:1.5rem; }

        .avatar-preview {
            width:140px; height:140px; margin:0 auto 1.25rem;
            border-radius:50%; background:var(--light-gray);
            display:flex; align-items:center; justify-content:center; overflow:hidden;
        }
        .avatar-preview img { width:100%; height:100%; object-fit:cover; }

        .file-upload {
            border:2px dashed var(--medium-gray); border-radius:var(--border-radius);
            padding:1.5rem; text-align:center; cursor:pointer; transition:var(--transition);
            display:block;
        }
        .file-upload:hover { border-color:var(--primary-blue); background:var(--light-blue); }
        .file-upload i { font-size:1.75rem; color:var(--dark-gray); margin-bottom:.75rem; display:block; }

        .modal-actions { display:flex; gap:.75rem; justify-content:flex-end; margin-top:1.25rem; }

        /* Security info block */
        .security-info-block {
            padding:1rem; background:var(--light-gray);
            border-radius:var(--border-radius); font-size:.85rem;
        }
        .security-info-block p { display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; }
        .security-info-block p:last-child { margin-bottom:0; }

        /* 2FA row */
        .tfa-row {
            display:flex; justify-content:space-between; align-items:center;
            padding:1rem; background:var(--light-gray); border-radius:var(--border-radius);
            flex-wrap:wrap; gap:.75rem;
        }
        .tfa-label { font-weight:600; margin-bottom:.2rem; }
        .tfa-status { font-size:.85rem; color:var(--dark-gray); }

        /* Current session block */
        .current-session-block {
            padding:1rem; background:var(--light-blue);
            border-radius:var(--border-radius); font-size:.85rem;
        }
        .current-session-block p { margin-bottom:.4rem; }
        .current-session-block p:last-child { margin-bottom:0; }

        /* ============================================================
           MOBILE OVERLAY
           ============================================================ */
        .overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.45); backdrop-filter:blur(2px); z-index:999;
        }
        .overlay.active { display:block; }

        /* ============================================================
           RESPONSIVE BREAKPOINTS (mirrors dashboard)
           ============================================================ */

        /* — Tablet landscape & below — */
        @media (max-width: 992px) {
            .sidebar {
                transform:translateX(-100%);
                position:fixed; top:0;
                height:100vh; z-index:1000; padding-top:1rem;
            }
            .sidebar.mobile-open { transform:translateX(0); }
            .sidebar-toggle { display:none; }

            .main-content        { margin-left:0 !important; }
            .main-content.sidebar-collapsed { margin-left:0 !important; }

            .mobile-menu-toggle {
                display:flex; align-items:center; justify-content:center;
                width:44px; height:44px; border-radius:50%;
                background:var(--light-gray); transition:var(--transition);
            }
            .mobile-menu-toggle:hover { background:var(--primary-blue); color:white; }
        }

        /* — Tablet portrait — */
        @media (max-width: 900px) {
            .profile-container { grid-template-columns:1fr; }
            .profile-sidebar   { display:flex; flex-direction:row; flex-wrap:wrap; gap:1rem; align-items:flex-start; text-align:left; }
            .profile-avatar    { flex-shrink:0; }
            .profile-name, .profile-role { text-align:left; }
            .profile-stats     { flex:1; min-width:180px; }
            .profile-meta      { flex:1; min-width:200px; }
        }

        /* — Mobile — */
        @media (max-width: 768px) {
            .nav-container  { padding:0 1rem; gap:.5rem; }
            .brand-text h1  { font-size:1rem; }
            .user-details   { display:none; }
            .main-content   { padding:1rem; }

            .profile-sidebar {
                flex-direction:column; text-align:center; align-items:center;
            }
            .profile-stats   { grid-template-columns:repeat(2,1fr); width:100%; }
            .profile-meta    { text-align:left; width:100%; }

           
            .profile-tab     { min-width:100px; padding:.75rem .875rem; font-size:.78rem; }
            .profile-tab i   { display:none; }

            .form-row        { grid-template-columns:1fr; }
            .tab-pane        { padding:1.25rem; }

            .tfa-row         { flex-direction:column; align-items:flex-start; }
        }

        /* — Small mobile — */
        @media (max-width: 480px) {
            .main-content    { padding:.75rem; }
            .brand-text h1   { font-size:.9rem; }
            .logo            { height:32px; }
            .profile-tab     { min-width:80px; font-size:.75rem; padding:.65rem .5rem; }
            .modal-content   { margin:5% auto; }
            .form-actions    { flex-direction:column; }
            .form-actions .btn { width:100%; justify-content:center; }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>
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
                    <h1>Isonga - General Secretary Settings</h1>
                </div>
            </div>

            <div class="user-menu">
                <div class="header-actions" style="display:flex;align-items:center;gap:.5rem;">
                   
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                </div>
                <div class="user-info">
                    
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">General Secretary</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">

        <!-- SIDEBAR -->
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle" title="Collapse Sidebar">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i><span>Student Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i><span>Student Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i><span>Meetings &amp; Attendance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i><span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i><span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i><span>Reports &amp; Analytics</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i><span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php" class="active">
                        <i class="fas fa-user-cog"></i><span>Profile &amp; Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- MAIN CONTENT -->
        <main class="main-content" id="mainContent">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Profile &amp; Settings</h1>
                   
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Profile Grid -->
            <div class="profile-container">

                <!-- ---- Profile Sidebar Card ---- -->
                <div class="profile-sidebar">
                    <div class="profile-avatar" id="avatarUploadBtn" title="Change profile picture">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                        <div class="avatar-upload"><i class="fas fa-camera"></i></div>
                    </div>

                    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="profile-role">General Secretary</div>

                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $my_tickets; ?></div>
                            <div class="stat-label">My Tickets</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $unread_messages; ?></div>
                            <div class="stat-label">Messages</div>
                        </div>
                    </div>

                    <div class="profile-meta">
                        <p><i class="fas fa-envelope"></i><?php echo htmlspecialchars($user['email']); ?></p>
                        <p><i class="fas fa-phone"></i><?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?></p>
                        <?php if (!empty($user['department_name'])): ?>
                            <p><i class="fas fa-building"></i><?php echo htmlspecialchars($user['department_name']); ?></p>
                        <?php endif; ?>
                        <p><i class="fas fa-calendar"></i>Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></p>
                    </div>
                </div>

                <!-- ---- Profile Content Card ---- -->
                <div class="profile-content">

                    <!-- Tabs -->
                    <div class="profile-tabs">
                        <button class="profile-tab <?php echo $active_tab === 'profile'     ? 'active' : ''; ?>" onclick="switchTab('profile')">
                            <i class="fas fa-user"></i> Personal Info
                        </button>
                        <button class="profile-tab <?php echo $active_tab === 'security'    ? 'active' : ''; ?>" onclick="switchTab('security')">
                            <i class="fas fa-shield-alt"></i> Security
                        </button>
                        <!-- <button class="profile-tab <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" onclick="switchTab('preferences')">
                            <i class="fas fa-cog"></i> Preferences
                        </button>
                        <button class="profile-tab <?php echo $active_tab === 'sessions'    ? 'active' : ''; ?>" onclick="switchTab('sessions')">
                            <i class="fas fa-history"></i> Login History
                        </button> -->
                    </div>

                    <!-- Tab Content -->
                    <div class="tab-content">

                        <!-- ======================================================
                             TAB: Personal Info
                             ====================================================== -->
                        <div id="profile-tab" class="tab-pane <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                            <form method="POST" id="profileForm">
                                <input type="hidden" name="action" value="update_profile">

                                <div class="form-section">
                                    <h4>Basic Information</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="full_name">Full Name *</label>
                                            <input type="text" id="full_name" name="full_name" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="email">Email Address *</label>
                                            <input type="email" id="email" name="email" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="phone">Phone Number</label>
                                            <input type="tel" id="phone" name="phone" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="date_of_birth">Date of Birth</label>
                                            <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="gender">Gender</label>
                                            <select id="gender" name="gender" class="form-control">
                                                <option value="">Select Gender</option>
                                                <option value="male"   <?php echo ($user['gender'] ?? '') === 'male'   ? 'selected' : ''; ?>>Male</option>
                                                <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="academic_year">Academic Year</label>
                                            <input type="text" id="academic_year" name="academic_year" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['academic_year'] ?? ''); ?>" readonly>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Academic Information</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="department_id">Department</label>
                                            <select id="department_id" name="department_id" class="form-control">
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>"
                                                        <?php echo ($user['department_id'] ?? '') == $dept['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="program_id">Program</label>
                                            <select id="program_id" name="program_id" class="form-control">
                                                <option value="">Select Program</option>
                                                <?php foreach ($programs as $prog): ?>
                                                    <option value="<?php echo $prog['id']; ?>"
                                                        <?php echo ($user['program_id'] ?? '') == $prog['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($prog['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Additional Information</h4>
                                    <div class="form-group">
                                        <label class="form-label" for="bio">Bio</label>
                                        <textarea id="bio" name="bio" class="form-control"
                                                  placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="address">Address</label>
                                        <textarea id="address" name="address" class="form-control"
                                                  placeholder="Your current address..."><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Emergency Contact</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="emergency_contact_name">Contact Name</label>
                                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="emergency_contact_phone">Contact Phone</label>
                                            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control"
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- ======================================================
                             TAB: Security
                             ====================================================== -->
                        <div id="security-tab" class="tab-pane <?php echo $active_tab === 'security' ? 'active' : ''; ?>">

                            <div class="form-section">
                                <h4>Change Password</h4>
                                <form method="POST" id="passwordForm">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label class="form-label" for="current_password">Current Password *</label>
                                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="new_password">New Password *</label>
                                        <input type="password" id="new_password" name="new_password" class="form-control" required
                                               onkeyup="checkPasswordStrength(this.value)">
                                        <div class="password-strength">
                                            <div class="password-strength-fill" id="passwordStrength"></div>
                                        </div>
                                        <small class="form-hint">Minimum 8 characters; mix upper/lower, numbers and symbols for a strong password.</small>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="confirm_password">Confirm New Password *</label>
                                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-key"></i> Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- <div class="form-section">
                                <h4>Two-Factor Authentication</h4>
                                <div class="tfa-row">
                                    <div>
                                        <div class="tfa-label">Two-Factor Authentication</div>
                                        <div class="tfa-status">
                                            <?php echo $user['two_factor_enabled'] ? '✅ Enabled' : '⚠️ Disabled'; ?>
                                        </div>
                                    </div>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action"
                                               value="<?php echo $user['two_factor_enabled'] ? 'disable_2fa' : 'enable_2fa'; ?>">
                                        <button type="submit"
                                                class="btn <?php echo $user['two_factor_enabled'] ? 'btn-danger' : 'btn-success'; ?>">
                                            <?php echo $user['two_factor_enabled'] ? 'Disable 2FA' : 'Enable 2FA'; ?>
                                        </button>
                                    </form>
                                </div> -->
                            </div>

                            <div class="form-section">
                                <h4>Account Security</h4>
                                <div class="security-info-block">
                                    <p><i class="fas fa-check-circle" style="color:var(--success)"></i>
                                       <strong>Last password change:</strong>
                                       <?php echo $user['last_password_change']
                                             ? date('F j, Y g:i A', strtotime($user['last_password_change']))
                                             : 'Never'; ?></p>
                                    <p><i class="fas fa-calendar-plus" style="color:var(--info)"></i>
                                       <strong>Account created:</strong>
                                       <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                                    <p><i class="fas fa-sign-in-alt" style="color:var(--primary-blue)"></i>
                                       <strong>Last login:</strong>
                                       <?php echo $user['last_login']
                                             ? date('F j, Y g:i A', strtotime($user['last_login']))
                                             : 'Never'; ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- ======================================================
                             TAB: Preferences
                             ====================================================== -->
                        <div id="preferences-tab" class="tab-pane <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>">
                            <form method="POST" id="preferencesForm">
                                <input type="hidden" name="action" value="update_preferences">

                                <div class="form-section">
                                    <h4>Notification Preferences</h4>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="email_notifications" name="email_notifications" value="1"
                                                   <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                            <label for="email_notifications" class="form-label" style="margin:0;">Email Notifications</label>
                                        </div>
                                        <small class="form-hint">Receive notifications via email</small>
                                    </div>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="sms_notifications" name="sms_notifications" value="1"
                                                   <?php echo ($user['sms_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                            <label for="sms_notifications" class="form-label" style="margin:0;">SMS Notifications</label>
                                        </div>
                                        <small class="form-hint">Receive notifications via SMS (when available)</small>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h4>Language &amp; Region</h4>
                                    <div class="form-group">
                                        <label class="form-label" for="preferred_language">Preferred Language</label>
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
                                        <label class="form-label" for="theme_preference">Theme Preference</label>
                                        <select id="theme_preference" name="theme_preference" class="form-control">
                                            <option value="light" <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'selected' : ''; ?>>Light</option>
                                            <option value="dark"  <?php echo ($user['theme_preference'] ?? 'auto') === 'dark'  ? 'selected' : ''; ?>>Dark</option>
                                            <option value="auto"  <?php echo ($user['theme_preference'] ?? 'auto') === 'auto'  ? 'selected' : ''; ?>>Auto (System)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- ======================================================
                             TAB: Login History
                             ====================================================== -->
                        <div id="sessions-tab" class="tab-pane <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>">

                            <div class="form-section">
                                <h4>Recent Login Activity</h4>
                                <?php if (empty($login_history)): ?>
                                    <div style="text-align:center;padding:2rem;color:var(--dark-gray);">
                                        <i class="fas fa-history" style="font-size:2.5rem;opacity:.4;display:block;margin-bottom:.75rem;"></i>
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
                                                    <?php echo htmlspecialchars($session['user_agent'] ?? 'Unknown browser'); ?> &bull;
                                                    <?php echo date('F j, Y g:i A', strtotime($session['login_time'])); ?>
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
                                <div class="current-session-block">
                                    <p><strong>IP Address:</strong> <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR']); ?></p>
                                    <p><strong>User Agent:</strong> <?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT']); ?></p>
                                    <p><strong>Session Started:</strong> <?php echo date('F j, Y g:i A'); ?></p>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.tab-content -->
                </div><!-- /.profile-content -->
            </div><!-- /.profile-container -->
        </main>
    </div><!-- /.dashboard-container -->

    <!-- ================================================================
         AVATAR UPLOAD MODAL
         ================================================================ -->
    <div id="avatarModal" class="modal">
        <div class="modal-content">
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
                            <i class="fas fa-user" style="font-size:2.5rem;color:var(--dark-gray);"></i>
                        <?php endif; ?>
                    </div>

                    <label class="file-upload" for="avatar">
                        <input type="file" id="avatar" name="avatar"
                               accept="image/jpeg,image/png,image/gif"
                               style="display:none;" onchange="previewAvatar(this)">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div style="font-weight:600;margin-bottom:.25rem;">Click to upload new profile picture</div>
                        <small style="color:var(--dark-gray);">JPG, PNG or GIF · Max 2 MB</small>
                    </label>

                    <div id="uploadError" style="color:var(--danger);margin-top:.75rem;display:none;font-size:.85rem;"></div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                            <i class="fas fa-upload"></i> Update Picture
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        /* ---- Dark Mode (mirrors dashboard) ---- */
        // const themeToggle = document.getElementById('themeToggle');
        // const body        = document.body;
        // const savedTheme  = localStorage.getItem('theme') ||
        //                     (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

        // if (savedTheme === 'dark') {
        //     body.classList.add('dark-mode');
        //     themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        // }
        // themeToggle.addEventListener('click', () => {
        //     body.classList.toggle('dark-mode');
        //     const isDark = body.classList.contains('dark-mode');
        //     localStorage.setItem('theme', isDark ? 'dark' : 'light');
        //     themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        // });
        // window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        //     if (!localStorage.getItem('theme')) {
        //         body.classList.toggle('dark-mode', e.matches);
        //         themeToggle.innerHTML = e.matches ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        //     }
        // });

        /* ---- Sidebar Toggle (mirrors dashboard) ---- */
        const sidebar        = document.getElementById('sidebar');
        const mainContent    = document.getElementById('mainContent');
        const sidebarToggle  = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');

        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle)    sidebarToggle.innerHTML    = '<i class="fas fa-chevron-right"></i>';
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }

        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle)    sidebarToggle.innerHTML    = icon;
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }
        if (sidebarToggle)    sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);

        /* ---- Mobile Menu (mirrors dashboard) ---- */
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay    = document.getElementById('mobileOverlay');

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
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        /* ---- Avatar Modal ---- */
        const avatarModal     = document.getElementById('avatarModal');
        const avatarUploadBtn = document.getElementById('avatarUploadBtn');
        const closeButtons    = document.querySelectorAll('.close, .close-modal');
        const avatarForm      = document.getElementById('avatarForm');
        const avatarInput     = document.getElementById('avatar');
        const uploadBtn       = document.getElementById('uploadBtn');
        const uploadError     = document.getElementById('uploadError');
        const fileUploadLabel = document.querySelector('.file-upload');

        function openModal()  { avatarModal.style.display = 'block'; document.body.style.overflow = 'hidden'; }
        function closeModal() {
            avatarModal.style.display = 'none'; document.body.style.overflow = '';
            if (avatarInput) avatarInput.value = '';
            if (uploadBtn)   { uploadBtn.disabled = false; uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Update Picture'; }
            uploadError.style.display = 'none';
        }

        if (avatarUploadBtn) avatarUploadBtn.addEventListener('click', e => { e.stopPropagation(); openModal(); });
        closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
        window.addEventListener('click', e => { if (e.target === avatarModal) closeModal(); });

        if (fileUploadLabel) {
            fileUploadLabel.addEventListener('click', e => {
                if (e.target !== avatarInput) avatarInput && avatarInput.click();
            });
        }

        if (avatarInput) {
            avatarInput.addEventListener('change', function (e) {
                uploadError.style.display = 'none';
                const file = e.target.files[0];
                if (!file) return;
                const allowed = ['image/jpeg','image/png','image/gif','image/jpg'];
                if (!allowed.includes(file.type)) {
                    uploadError.textContent = 'Please select a valid image file (JPG, PNG, GIF)';
                    uploadError.style.display = 'block';
                    avatarInput.value = ''; return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    uploadError.textContent = 'File size must be less than 2 MB';
                    uploadError.style.display = 'block';
                    avatarInput.value = ''; return;
                }
                previewAvatar(this);
            });
        }

        if (avatarForm) {
            avatarForm.addEventListener('submit', function (e) {
                if (!avatarInput.files.length) {
                    e.preventDefault();
                    uploadError.textContent = 'Please select a file to upload';
                    uploadError.style.display = 'block'; return;
                }
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
            });
        }

        /* ---- Password form validation ---- */
        document.getElementById('passwordForm')?.addEventListener('submit', function (e) {
            const np = document.getElementById('new_password').value;
            const cp = document.getElementById('confirm_password').value;
            if (np !== cp)      { e.preventDefault(); alert('New passwords do not match'); return; }
            if (np.length < 8)  { e.preventDefault(); alert('Password must be at least 8 characters'); }
        });
    });

    /* ---- Tab switcher ---- */
    function switchTab(tabName) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);

        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.profile-tab').forEach(t => t.classList.remove('active'));

        const pane = document.getElementById(tabName + '-tab');
        if (pane) pane.classList.add('active');
        if (event && event.currentTarget) event.currentTarget.classList.add('active');
    }

    /* ---- Password strength ---- */
    function checkPasswordStrength(password) {
        const bar = document.getElementById('passwordStrength');
        if (!bar) return;
        let s = 0;
        if (password.length >= 8)                               s += 25;
        if (password.match(/[a-z]/) && password.match(/[A-Z]/)) s += 25;
        if (password.match(/\d/))                               s += 25;
        if (password.match(/[^a-zA-Z\d]/))                      s += 25;
        bar.className = 'password-strength-fill';
        bar.classList.add(s <= 25 ? 'strength-weak' : s <= 50 ? 'strength-fair' : s <= 75 ? 'strength-good' : 'strength-strong');
    }

    /* ---- Avatar preview ---- */
    function previewAvatar(input) {
        const preview = document.getElementById('avatarPreview');
        if (!preview || !input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => { preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`; };
        reader.readAsDataURL(input.files[0]);
    }
    </script>
</body>
</html>