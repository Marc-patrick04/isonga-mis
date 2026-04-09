<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_admin = [];
}

// Handle Settings Actions
$message = '';
$error = '';

// Get current settings from database (if settings table exists)
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    $settings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings_rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Settings table might not exist yet
    $settings = [];
}

// Handle General Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_general') {
        try {
            $site_name = trim($_POST['site_name']);
            $site_tagline = trim($_POST['site_tagline']);
            $site_email = trim($_POST['site_email']);
            $site_phone = trim($_POST['site_phone']);
            $site_address = trim($_POST['site_address']);
            $timezone = $_POST['timezone'];
            $date_format = $_POST['date_format'];
            
            // Update settings in database
            $settings_data = [
                'site_name' => $site_name,
                'site_tagline' => $site_tagline,
                'site_email' => $site_email,
                'site_phone' => $site_phone,
                'site_address' => $site_address,
                'timezone' => $timezone,
                'date_format' => $date_format
            ];
            
            foreach ($settings_data as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) DO UPDATE 
                    SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
            
            $message = "General settings updated successfully!";
            header("Location: settings.php?tab=general&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating settings: " . $e->getMessage();
            error_log("Settings update error: " . $e->getMessage());
        }
    }
    
    // Handle Academic Settings Update
    elseif ($_POST['action'] === 'update_academic') {
        try {
            $current_academic_year = trim($_POST['current_academic_year']);
            $semester = $_POST['semester'];
            $semester_start = $_POST['semester_start'];
            $semester_end = $_POST['semester_end'];
            $registration_deadline = $_POST['registration_deadline'];
            
            $settings_data = [
                'current_academic_year' => $current_academic_year,
                'semester' => $semester,
                'semester_start' => $semester_start,
                'semester_end' => $semester_end,
                'registration_deadline' => $registration_deadline
            ];
            
            foreach ($settings_data as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) DO UPDATE 
                    SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
            
            $message = "Academic settings updated successfully!";
            header("Location: settings.php?tab=academic&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating academic settings: " . $e->getMessage();
            error_log("Academic settings update error: " . $e->getMessage());
        }
    }
    
    // Handle Email Settings Update
    elseif ($_POST['action'] === 'update_email') {
        try {
            $smtp_host = trim($_POST['smtp_host']);
            $smtp_port = trim($_POST['smtp_port']);
            $smtp_user = trim($_POST['smtp_user']);
            $smtp_password = trim($_POST['smtp_password']);
            $smtp_encryption = $_POST['smtp_encryption'];
            $from_email = trim($_POST['from_email']);
            $from_name = trim($_POST['from_name']);
            
            $settings_data = [
                'smtp_host' => $smtp_host,
                'smtp_port' => $smtp_port,
                'smtp_user' => $smtp_user,
                'smtp_password' => $smtp_password,
                'smtp_encryption' => $smtp_encryption,
                'from_email' => $from_email,
                'from_name' => $from_name
            ];
            
            foreach ($settings_data as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) DO UPDATE 
                    SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
            
            $message = "Email settings updated successfully!";
            header("Location: settings.php?tab=email&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating email settings: " . $e->getMessage();
            error_log("Email settings update error: " . $e->getMessage());
        }
    }
    
    // Handle Notification Settings Update
    elseif ($_POST['action'] === 'update_notifications') {
        try {
            $ticket_assignment = isset($_POST['ticket_assignment']) ? 1 : 0;
            $ticket_resolution = isset($_POST['ticket_resolution']) ? 1 : 0;
            $case_assignment = isset($_POST['case_assignment']) ? 1 : 0;
            $case_update = isset($_POST['case_update']) ? 1 : 0;
            $event_reminder = isset($_POST['event_reminder']) ? 1 : 0;
            $report_submission = isset($_POST['report_submission']) ? 1 : 0;
            $report_approval = isset($_POST['report_approval']) ? 1 : 0;
            $weekly_digest = isset($_POST['weekly_digest']) ? 1 : 0;
            
            $settings_data = [
                'notify_ticket_assignment' => $ticket_assignment,
                'notify_ticket_resolution' => $ticket_resolution,
                'notify_case_assignment' => $case_assignment,
                'notify_case_update' => $case_update,
                'notify_event_reminder' => $event_reminder,
                'notify_report_submission' => $report_submission,
                'notify_report_approval' => $report_approval,
                'notify_weekly_digest' => $weekly_digest
            ];
            
            foreach ($settings_data as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) DO UPDATE 
                    SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
            
            $message = "Notification settings updated successfully!";
            header("Location: settings.php?tab=notifications&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating notification settings: " . $e->getMessage();
            error_log("Notification settings update error: " . $e->getMessage());
        }
    }
    
    // Handle Security Settings Update
    elseif ($_POST['action'] === 'update_security') {
        try {
            $password_expiry_days = (int)$_POST['password_expiry_days'];
            $session_timeout = (int)$_POST['session_timeout'];
            $max_login_attempts = (int)$_POST['max_login_attempts'];
            $lockout_duration = (int)$_POST['lockout_duration'];
            $require_2fa_admin = isset($_POST['require_2fa_admin']) ? 1 : 0;
            $require_2fa_committee = isset($_POST['require_2fa_committee']) ? 1 : 0;
            $enable_recaptcha = isset($_POST['enable_recaptcha']) ? 1 : 0;
            $recaptcha_site_key = trim($_POST['recaptcha_site_key'] ?? '');
            $recaptcha_secret_key = trim($_POST['recaptcha_secret_key'] ?? '');
            
            $settings_data = [
                'password_expiry_days' => $password_expiry_days,
                'session_timeout' => $session_timeout,
                'max_login_attempts' => $max_login_attempts,
                'lockout_duration' => $lockout_duration,
                'require_2fa_admin' => $require_2fa_admin,
                'require_2fa_committee' => $require_2fa_committee,
                'enable_recaptcha' => $enable_recaptcha,
                'recaptcha_site_key' => $recaptcha_site_key,
                'recaptcha_secret_key' => $recaptcha_secret_key
            ];
            
            foreach ($settings_data as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) DO UPDATE 
                    SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
            
            $message = "Security settings updated successfully!";
            header("Location: settings.php?tab=security&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating security settings: " . $e->getMessage();
            error_log("Security settings update error: " . $e->getMessage());
        }
    }
    
    // Handle Backup
    elseif ($_POST['action'] === 'backup') {
        try {
            // Create backup directory if not exists
            $backup_dir = '../backups/';
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0755, true);
            }
            
            $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            // Get database credentials from config
            $db_config = require '../config/database.php';
            $dbname = $db_config['dbname'] ?? '';
            $dbuser = $db_config['user'] ?? '';
            $dbpass = $db_config['password'] ?? '';
            $dbhost = $db_config['host'] ?? 'localhost';
            
            // Create backup using pg_dump
            $command = "PGPASSWORD='$dbpass' pg_dump -h $dbhost -U $dbuser $dbname > $backup_file 2>&1";
            exec($command, $output, $return_var);
            
            if ($return_var === 0 && file_exists($backup_file)) {
                $message = "Database backup created successfully!";
            } else {
                throw new Exception("Backup failed: " . implode("\n", $output));
            }
            
            header("Location: settings.php?tab=system&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error creating backup: " . $e->getMessage();
            error_log("Backup error: " . $e->getMessage());
        }
    }
    
    // Handle Clear Cache
    elseif ($_POST['action'] === 'clear_cache') {
        try {
            $cache_dir = '../cache/';
            if (file_exists($cache_dir)) {
                $files = glob($cache_dir . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            
            $message = "Cache cleared successfully!";
            header("Location: settings.php?tab=system&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = "Error clearing cache: " . $e->getMessage();
        }
    }
    
    // Handle Test Email
    elseif ($_POST['action'] === 'test_email') {
        try {
            $test_email = $_POST['test_email'];
            $subject = "Test Email from Isonga RPSU System";
            $message = "This is a test email to verify that the email settings are configured correctly.\n\nSent at: " . date('Y-m-d H:i:s');
            
            // Send test email
            $headers = "From: " . ($settings['from_name'] ?? 'Isonga RPSU') . " <" . ($settings['from_email'] ?? 'noreply@isonga.rw') . ">\r\n";
            $headers .= "Reply-To: " . ($settings['from_email'] ?? 'noreply@isonga.rw') . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            if (mail($test_email, $subject, $message, $headers)) {
                $message = "Test email sent successfully to $test_email!";
            } else {
                throw new Exception("Failed to send test email.");
            }
            
            header("Location: settings.php?tab=email&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = "Error sending test email: " . $e->getMessage();
        }
    }
    
    // Handle System Info
    elseif ($_POST['action'] === 'system_info') {
        // Just refresh the page to show updated info
        header("Location: settings.php?tab=system");
        exit();
    }
}

// Get system statistics
try {
    // Get database size
    $stmt = $pdo->query("SELECT pg_database_size(current_database()) as size");
    $db_size = $stmt->fetch(PDO::FETCH_ASSOC)['size'] ?? 0;
    $db_size_formatted = $db_size ? round($db_size / 1024 / 1024, 2) . ' MB' : 'N/A';
    
    // Get table counts
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM events");
    $total_events = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM arbitration_cases");
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Get last backup date (check backups folder)
    $backup_dir = '../backups/';
    $last_backup = 'Never';
    if (file_exists($backup_dir)) {
        $backup_files = glob($backup_dir . '*.sql');
        if (!empty($backup_files)) {
            $latest_backup = max($backup_files);
            $last_backup = date('M j, Y g:i A', filemtime($latest_backup));
        }
    }
    
} catch (PDOException $e) {
    $db_size_formatted = 'N/A';
    $total_users = 0;
    $total_tickets = 0;
    $total_events = 0;
    $total_cases = 0;
    $last_backup = 'Never';
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Default settings values
$default_settings = [
    'site_name' => 'Isonga RPSU',
    'site_tagline' => 'Rwanda Polytechnic Musanze College',
    'site_email' => 'info@isonga.rw',
    'site_phone' => '+250 788 123 456',
    'site_address' => 'Musanze, Rwanda',
    'timezone' => 'Africa/Kigali',
    'date_format' => 'Y-m-d',
    'current_academic_year' => date('Y') . '-' . (date('Y') + 1),
    'semester' => '1',
    'semester_start' => date('Y-m-d'),
    'semester_end' => date('Y-m-d', strtotime('+6 months')),
    'registration_deadline' => date('Y-m-d', strtotime('+1 month')),
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls',
    'from_email' => 'noreply@isonga.rw',
    'from_name' => 'Isonga RPSU',
    'password_expiry_days' => '90',
    'session_timeout' => '30',
    'max_login_attempts' => '5',
    'lockout_duration' => '15'
];

// Merge settings with defaults
$settings = array_merge($default_settings, $settings);

// Timezone options
$timezones = [
    'Africa/Kigali' => 'Africa/Kigali (Rwanda)',
    'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
    'UTC' => 'UTC',
    'Europe/London' => 'Europe/London (GMT)',
    'America/New_York' => 'America/New_York (EST)'
];

// Date format options
$date_formats = [
    'Y-m-d' => 'YYYY-MM-DD (2024-01-15)',
    'd/m/Y' => 'DD/MM/YYYY (15/01/2024)',
    'm/d/Y' => 'MM/DD/YYYY (01/15/2024)',
    'F j, Y' => 'Month Day, Year (January 15, 2024)',
    'M j, Y' => 'Mon Day, Year (Jan 15, 2024)'
];

// Academic year options
$academic_years = [];
$current_year = date('Y');
for ($i = -2; $i <= 2; $i++) {
    $year = $current_year + $i;
    $academic_years[] = $year . '-' . ($year + 1);
}

// Encryption options
$encryption_options = [
    'tls' => 'TLS',
    'ssl' => 'SSL',
    'none' => 'None'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>System Settings - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Light Mode (Default) */
        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --secondary: #6b7280;
            --purple: #8b5cf6;
            
            /* Light Mode Colors */
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        /* Dark Mode */
        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --sidebar-bg: #1f2937;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Header */
        .header {
            background: var(--header-bg);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text p {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border-color);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
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
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem 0;
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
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
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover {
            background: var(--bg-primary);
            border-left-color: var(--primary);
        }

        .menu-item a.active {
            background: var(--bg-primary);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-color);
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
            position: relative;
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Settings Cards */
        .settings-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .settings-card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-primary);
        }

        .settings-card-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .settings-card-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.85rem;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-group small {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-label input {
            width: auto;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* System Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .info-card {
            background: var(--bg-primary);
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        .info-card .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .info-card .info-value {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        body.dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }

        body.dark-mode .alert-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .header-container {
                padding: 0.75rem 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                padding: 0.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo-img">
                <div class="logo-text">
                    <h1>Isonga Admin</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <div class="user-area">
                <button class="theme-toggle" id="themeToggle" title="Toggle Dark/Light Mode">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_admin['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($current_admin['full_name'] ?? 'Admin'); ?></div>
                        <div class="user-role">System Administrator</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="hero.php"><i class="fas fa-images"></i> Hero Images</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                  <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php" class="active"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="page-header">
                <h1><i class="fas fa-cogs"></i> System Settings</h1>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn <?php echo $active_tab === 'general' ? 'active' : ''; ?>" onclick="switchTab('general')">
                    <i class="fas fa-globe"></i> General
                </button>
                <button class="tab-btn <?php echo $active_tab === 'academic' ? 'active' : ''; ?>" onclick="switchTab('academic')">
                    <i class="fas fa-graduation-cap"></i> Academic
                </button>
                <button class="tab-btn <?php echo $active_tab === 'email' ? 'active' : ''; ?>" onclick="switchTab('email')">
                    <i class="fas fa-envelope"></i> Email
                </button>
                <button class="tab-btn <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>" onclick="switchTab('notifications')">
                    <i class="fas fa-bell"></i> Notifications
                </button>
                <button class="tab-btn <?php echo $active_tab === 'security' ? 'active' : ''; ?>" onclick="switchTab('security')">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="tab-btn <?php echo $active_tab === 'system' ? 'active' : ''; ?>" onclick="switchTab('system')">
                    <i class="fas fa-server"></i> System
                </button>
            </div>

            <!-- General Settings Tab -->
            <div id="generalTab" class="tab-pane <?php echo $active_tab === 'general' ? 'active' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_general">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h2><i class="fas fa-globe"></i> General Settings</h2>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>Site Name</label>
                                    <input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                                </div>
                                <div class="form-group full-width">
                                    <label>Site Tagline</label>
                                    <input type="text" name="site_tagline" value="<?php echo htmlspecialchars($settings['site_tagline']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Site Email</label>
                                    <input type="email" name="site_email" value="<?php echo htmlspecialchars($settings['site_email']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Site Phone</label>
                                    <input type="text" name="site_phone" value="<?php echo htmlspecialchars($settings['site_phone']); ?>">
                                </div>
                                <div class="form-group full-width">
                                    <label>Site Address</label>
                                    <textarea name="site_address" rows="2"><?php echo htmlspecialchars($settings['site_address']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Timezone</label>
                                    <select name="timezone">
                                        <?php foreach ($timezones as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $settings['timezone'] === $key ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Date Format</label>
                                    <select name="date_format">
                                        <?php foreach ($date_formats as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $settings['date_format'] === $key ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Academic Settings Tab -->
            <div id="academicTab" class="tab-pane <?php echo $active_tab === 'academic' ? 'active' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_academic">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h2><i class="fas fa-graduation-cap"></i> Academic Settings</h2>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Current Academic Year</label>
                                    <select name="current_academic_year">
                                        <?php foreach ($academic_years as $year): ?>
                                            <option value="<?php echo $year; ?>" <?php echo $settings['current_academic_year'] === $year ? 'selected' : ''; ?>>
                                                <?php echo $year; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Current Semester</label>
                                    <select name="semester">
                                        <option value="1" <?php echo $settings['semester'] == '1' ? 'selected' : ''; ?>>Semester 1</option>
                                        <option value="2" <?php echo $settings['semester'] == '2' ? 'selected' : ''; ?>>Semester 2</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Semester Start Date</label>
                                    <input type="date" name="semester_start" value="<?php echo htmlspecialchars($settings['semester_start']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Semester End Date</label>
                                    <input type="date" name="semester_end" value="<?php echo htmlspecialchars($settings['semester_end']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Registration Deadline</label>
                                    <input type="date" name="registration_deadline" value="<?php echo htmlspecialchars($settings['registration_deadline']); ?>">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Email Settings Tab -->
            <div id="emailTab" class="tab-pane <?php echo $active_tab === 'email' ? 'active' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_email">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h2><i class="fas fa-envelope"></i> Email Settings</h2>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>SMTP Host</label>
                                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>SMTP Port</label>
                                    <input type="text" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>SMTP Username</label>
                                    <input type="text" name="smtp_user" value="<?php echo htmlspecialchars($settings['smtp_user']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>SMTP Password</label>
                                    <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($settings['smtp_password']); ?>">
                                    <small>Leave blank to keep current password</small>
                                </div>
                                <div class="form-group">
                                    <label>Encryption</label>
                                    <select name="smtp_encryption">
                                        <?php foreach ($encryption_options as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $settings['smtp_encryption'] === $key ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>From Email</label>
                                    <input type="email" name="from_email" value="<?php echo htmlspecialchars($settings['from_email']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>From Name</label>
                                    <input type="text" name="from_name" value="<?php echo htmlspecialchars($settings['from_name']); ?>">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                <!-- <button type="button" class="btn btn-success" onclick="openTestEmailModal()">Send Test Email</button> -->
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Notification Settings Tab -->
            <div id="notificationsTab" class="tab-pane <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_notifications">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h2><i class="fas fa-bell"></i> Notification Settings</h2>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="ticket_assignment" value="1" <?php echo $settings['notify_ticket_assignment'] ?? 0 ? 'checked' : ''; ?>>
                                        Ticket Assignment
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="ticket_resolution" value="1" <?php echo $settings['notify_ticket_resolution'] ?? 0 ? 'checked' : ''; ?>>
                                        Ticket Resolution
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="case_assignment" value="1" <?php echo $settings['notify_case_assignment'] ?? 0 ? 'checked' : ''; ?>>
                                        Case Assignment
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="case_update" value="1" <?php echo $settings['notify_case_update'] ?? 0 ? 'checked' : ''; ?>>
                                        Case Status Update
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="event_reminder" value="1" <?php echo $settings['notify_event_reminder'] ?? 0 ? 'checked' : ''; ?>>
                                        Event Reminders
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="report_submission" value="1" <?php echo $settings['notify_report_submission'] ?? 0 ? 'checked' : ''; ?>>
                                        Report Submission
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="report_approval" value="1" <?php echo $settings['notify_report_approval'] ?? 0 ? 'checked' : ''; ?>>
                                        Report Approval/Rejection
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="weekly_digest" value="1" <?php echo $settings['notify_weekly_digest'] ?? 0 ? 'checked' : ''; ?>>
                                        Weekly Digest
                                    </label>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Security Settings Tab -->
            <div id="securityTab" class="tab-pane <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_security">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h2><i class="fas fa-shield-alt"></i> Security Settings</h2>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Password Expiry (Days)</label>
                                    <input type="number" name="password_expiry_days" value="<?php echo $settings['password_expiry_days']; ?>" min="0">
                                    <small>0 = Never expire</small>
                                </div>
                                <div class="form-group">
                                    <label>Session Timeout (Minutes)</label>
                                    <input type="number" name="session_timeout" value="<?php echo $settings['session_timeout']; ?>" min="1">
                                </div>
                                <div class="form-group">
                                    <label>Max Login Attempts</label>
                                    <input type="number" name="max_login_attempts" value="<?php echo $settings['max_login_attempts']; ?>" min="1">
                                </div>
                                <div class="form-group">
                                    <label>Lockout Duration (Minutes)</label>
                                    <input type="number" name="lockout_duration" value="<?php echo $settings['lockout_duration']; ?>" min="1">
                                </div>
                                <div class="form-group full-width">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="require_2fa_admin" value="1" <?php echo $settings['require_2fa_admin'] ?? 0 ? 'checked' : ''; ?>>
                                        Require 2FA for Admin Accounts
                                    </label>
                                </div>
                                <div class="form-group full-width">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="require_2fa_committee" value="1" <?php echo $settings['require_2fa_committee'] ?? 0 ? 'checked' : ''; ?>>
                                        Require 2FA for Committee Members
                                    </label>
                                </div>
                                <div class="form-group full-width">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="enable_recaptcha" value="1" <?php echo $settings['enable_recaptcha'] ?? 0 ? 'checked' : ''; ?>>
                                        Enable reCAPTCHA on Login
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label>reCAPTCHA Site Key</label>
                                    <input type="text" name="recaptcha_site_key" value="<?php echo htmlspecialchars($settings['recaptcha_site_key'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>reCAPTCHA Secret Key</label>
                                    <input type="text" name="recaptcha_secret_key" value="<?php echo htmlspecialchars($settings['recaptcha_secret_key'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- System Settings Tab -->
            <div id="systemTab" class="tab-pane <?php echo $active_tab === 'system' ? 'active' : ''; ?>">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h2><i class="fas fa-server"></i> System Information</h2>
                    </div>
                    <div class="settings-card-body">
                        <div class="info-grid">
                            <div class="info-card">
                                <div class="info-label">PHP Version</div>
                                <div class="info-value"><?php echo phpversion(); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">PostgreSQL Version</div>
                                <div class="info-value">
                                    <?php
                                    $version = $pdo->query("SELECT version()")->fetchColumn();
                                    echo substr($version, 0, strpos($version, ','));
                                    ?>
                                </div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Database Size</div>
                                <div class="info-value"><?php echo $db_size_formatted; ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Total Users</div>
                                <div class="info-value"><?php echo number_format($total_users); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Total Tickets</div>
                                <div class="info-value"><?php echo number_format($total_tickets); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Total Events</div>
                                <div class="info-value"><?php echo number_format($total_events); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Total Arbitration Cases</div>
                                <div class="info-value"><?php echo number_format($total_cases); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Last Backup</div>
                                <div class="info-value"><?php echo $last_backup; ?></div>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="action" value="backup">
                                <button type="submit" class="btn btn-primary" onclick="return confirm('Create a database backup?')">
                                    <i class="fas fa-database"></i> Create Backup
                                </button>
                            </form>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="action" value="clear_cache">
                                <button type="submit" class="btn btn-warning" onclick="return confirm('Clear system cache?')">
                                    <i class="fas fa-broom"></i> Clear Cache
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Test Email Modal
    <div id="testEmailModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2>Send Test Email</h2>
                <button class="close-modal" onclick="closeTestEmailModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="test_email">
                <div class="form-group">
                    <label>Recipient Email</label>
                    <input type="email" name="test_email" required placeholder="Enter email address">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeTestEmailModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Test Email</button>
                </div>
            </form>
        </div>
    </div> -->

    <script>
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        
        // Tab switching
        function switchTab(tab) {
            window.location.href = `settings.php?tab=${tab}`;
        }
        
        // Test Email Modal
        function openTestEmailModal() {
            document.getElementById('testEmailModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeTestEmailModal() {
            document.getElementById('testEmailModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            const testEmailModal = document.getElementById('testEmailModal');
            if (event.target === testEmailModal) {
                closeTestEmailModal();
            }
        }
        
        // Prevent modal content click from bubbling
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>