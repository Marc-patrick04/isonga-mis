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
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Get available templates for Vice Guild Academic - FIXED QUERY
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'vice_guild_academic' OR role_specific IS NULL)
        AND is_active = true
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $templates = [];
    error_log("Templates query error: " . $e->getMessage());
}

// Get submitted reports
try {
    $stmt = $pdo->prepare("
        SELECT r.*, rt.name as template_name, rt.report_type,
               u.full_name as reviewer_name
        FROM reports r 
        LEFT JOIN report_templates rt ON r.template_id = rt.id
        LEFT JOIN users u ON r.reviewed_by = u.id
        WHERE r.user_id = ? 
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $submitted_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $submitted_reports = [];
    error_log("Reports query error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_report') {
        $template_id = $_POST['template_id'] ?? null;
        $title = $_POST['title'] ?? '';
        $report_type = $_POST['report_type'] ?? 'academic';
        $report_period = $_POST['report_period'] ?? null;
        $activity_date = $_POST['activity_date'] ?? null;
        
        // Get template to validate fields
        $selected_template = null;
        foreach ($templates as $template) {
            if ($template['id'] == $template_id) {
                $selected_template = $template;
                break;
            }
        }
        
        if ($selected_template) {
            $content_data = [];
            $template_fields = json_decode($selected_template['fields'], true);
            
            // Collect form data based on template fields
            if (isset($template_fields['sections']) && is_array($template_fields['sections'])) {
                foreach ($template_fields['sections'] as $section) {
                    $field_name = strtolower(preg_replace('/[^a-z0-9]/', '_', $section['title']));
                    $content_data[$field_name] = $_POST[$field_name] ?? '';
                }
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, submitted_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    RETURNING id
                ");
                
                $stmt->execute([
                    $title,
                    $template_id,
                    $user_id,
                    $report_type,
                    $report_period,
                    $activity_date,
                    json_encode($content_data)
                ]);
                
                $report_id = $stmt->fetchColumn();
                
                // Handle file uploads if any
                if (!empty($_FILES['report_files']['name'][0])) {
                    $upload_dir = "../assets/uploads/reports/";
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    foreach ($_FILES['report_files']['name'] as $key => $name) {
                        if ($_FILES['report_files']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_tmp = $_FILES['report_files']['tmp_name'][$key];
                            $file_size = $_FILES['report_files']['size'][$key];
                            $file_type = $_FILES['report_files']['type'][$key];
                            $file_name = time() . '_' . preg_replace("/[^a-zA-Z0-9\.]/", "_", $name);
                            $file_path = $upload_dir . $file_name;
                            
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO report_media (report_id, file_name, file_path, file_type, file_size, uploaded_by, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                                ");
                                $stmt->execute([
                                    $report_id,
                                    $name,
                                    'assets/uploads/reports/' . $file_name,
                                    $file_type,
                                    $file_size,
                                    $user_id
                                ]);
                            }
                        }
                    }
                }
                
                $_SESSION['success_message'] = "Report submitted successfully!";
                header("Location: academic_reports.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error creating report: " . $e->getMessage();
                error_log("Report creation error: " . $e->getMessage());
            }
        }
    }
}

// Handle export request
if (isset($_GET['export']) && isset($_GET['id'])) {
    $report_id = (int)$_GET['id'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, rt.name as template_name, u.full_name as author_name
            FROM reports r 
            LEFT JOIN report_templates rt ON r.template_id = rt.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ? AND r.user_id = ?
        ");
        $stmt->execute([$report_id, $user_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            // Get media files
            $stmt = $pdo->prepare("SELECT * FROM report_media WHERE report_id = ?");
            $stmt->execute([$report_id]);
            $media_files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate CSV export
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="academic_report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV header
            fputcsv($output, ['Academic Report Export - ' . $report['title']]);
            fputcsv($output, []);
            fputcsv($output, ['Template:', $report['template_name']]);
            fputcsv($output, ['Author:', $report['author_name']]);
            fputcsv($output, ['Report Type:', $report['report_type']]);
            fputcsv($output, ['Status:', $report['status']]);
            fputcsv($output, ['Submitted:', $report['submitted_at']]);
            fputcsv($output, []);
            
            // Report content
            $content = json_decode($report['content'], true);
            foreach ($content as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                fputcsv($output, [$label . ':', $value]);
            }
            
            fclose($output);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error exporting report: " . $e->getMessage();
        header("Location: academic_reports.php");
        exit();
    }
}

// Get report statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_reports,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_reports,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_reports,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_reports
        FROM reports 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $report_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $report_stats = [
        'total_reports' => 0,
        'submitted_reports' => 0,
        'reviewed_reports' => 0,
        'approved_reports' => 0,
        'draft_reports' => 0
    ];
}

// Get upcoming meetings count for sidebar badge
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_meetings 
        FROM meeting_attendees ma 
        JOIN meetings m ON ma.meeting_id = m.id 
        WHERE ma.user_id = ? 
        AND m.meeting_date >= CURRENT_DATE 
        AND m.status = 'scheduled'
        AND ma.attendance_status = 'invited'
    ");
    $stmt->execute([$user_id]);
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
} catch (PDOException $e) {
    $upcoming_meetings = 0;
}

// Debug: Check if templates were found
if (empty($templates)) {
    error_log("No templates found for role: vice_guild_academic");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Reports - Isonga RPSU</title>
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
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
            border-left: 3px solid var(--academic-primary);
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
            background: var(--academic-light);
            color: var(--academic-primary);
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
            grid-template-columns: 1fr;
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

        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
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
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.25rem;
            margin-top: 1rem;
        }

        .template-card {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
        }

        .template-card:hover {
            border-color: var(--academic-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .template-card.selected {
            border-color: var(--academic-primary);
            background: var(--academic-light);
        }

        .template-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--academic-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--academic-primary);
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .template-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .template-description {
            font-size: 0.8rem;
            color: var(--dark-gray);
            line-height: 1.4;
            margin-bottom: 1rem;
        }

        .template-type {
            display: inline-block;
            background: var(--light-gray);
            color: var(--dark-gray);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .template-check {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 20px;
            height: 20px;
            border: 2px solid var(--medium-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .template-card.selected .template-check {
            background: var(--academic-primary);
            border-color: var(--academic-primary);
            color: white;
        }

        .template-check i {
            font-size: 0.7rem;
            display: none;
        }

        .template-card.selected .template-check i {
            display: block;
        }

        /* Reports Table */
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

        .status-draft {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-submitted {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-reviewed {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-approved {
            background: #d4edda;
            color: var(--success);
        }

        .status-rejected {
            background: #f8d7da;
            color: var(--danger);
        }

        /* Buttons */
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
            background: var(--academic-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--academic-accent);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Alert Messages */
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
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
            font-weight: 500;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .tab.active {
            color: var(--academic-primary);
            border-bottom-color: var(--academic-primary);
        }

        .tab:hover {
            color: var(--academic-primary);
            background: var(--academic-light);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--academic-primary);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .file-input {
            display: none;
        }

        .file-list {
            margin-top: 1rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }

        .file-name {
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            padding: 0.25rem;
        }

        /* Table Responsive */
        .table-responsive {
            overflow-x: auto;
        }

        /* Modal */
        #reportModal {
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

        #reportModal.show {
            display: flex;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .template-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-container { grid-template-columns: 200px 1fr; }
            .brand-text h1 { font-size: 1.05rem; }
        }

        @media (max-width: 768px) {
            .dashboard-container { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main-content { padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .template-grid { grid-template-columns: 1fr; }
            .nav-container { padding: 0 1rem; }
            .user-details { display: none; }
            .header { height: 70px; }
            .dashboard-container { min-height: calc(100vh - 70px); }
            .tabs { overflow-x: auto; flex-wrap: nowrap; }
            .tab { white-space: nowrap; flex-shrink: 0; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .main-content { padding: 0.75rem; }
            .brand-text h1 { font-size: 0.9rem; }
            .welcome-section h1 { font-size: 1.15rem; }
            .stat-card { padding: 0.75rem; gap: 0.75rem; }
            .stat-number { font-size: 1.25rem; }
            .template-card { padding: 1rem; }
            .table th, .table td { padding: 0.6rem 0.5rem; font-size: 0.75rem; }
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
                    <h1>Isonga - Academic Reports</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
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
                        <div class="user-role">Vice Guild Academic</div>
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
                    <a href="academic_reports.php" class="active">
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
                    <a href="committee_budget_requests.php">
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
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_calendar.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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
            <!-- Display Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Report Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $report_stats['total_reports']; ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $report_stats['submitted_reports']; ?></div>
                        <div class="stat-label">Submitted</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $report_stats['approved_reports']; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($templates); ?></div>
                        <div class="stat-label">Templates</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="create">Create New Report</button>
                <button class="tab" data-tab="submitted">Submitted Reports (<?php echo count($submitted_reports); ?>)</button>
            </div>

            <!-- Create Report Tab -->
            <div class="tab-content active" id="create-tab">
                <div class="card">
                    <div class="card-header">
                        <h3>Select Report Template</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($templates)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No templates available at the moment. 
                                <?php if (isset($error_message)) echo " " . $error_message; ?>
                            </div>
                        <?php else: ?>
                            <div class="template-grid" id="templateGrid">
                                <?php foreach ($templates as $template): ?>
                                    <div class="template-card" data-template-id="<?php echo $template['id']; ?>">
                                        <div class="template-check">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div class="template-icon">
                                            <i class="fas fa-<?php echo $template['report_type'] === 'academic' ? 'graduation-cap' : 'file-alt'; ?>"></i>
                                        </div>
                                        <h4 class="template-title"><?php echo htmlspecialchars($template['name']); ?></h4>
                                        <p class="template-description"><?php echo htmlspecialchars($template['description'] ?? 'No description available'); ?></p>
                                        <div class="template-type"><?php echo ucfirst($template['report_type']); ?> Report</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Report Form (Initially Hidden) -->
                            <form id="reportForm" method="POST" enctype="multipart/form-data" style="display: none; margin-top: 2rem;">
                                <input type="hidden" name="action" value="create_report">
                                <input type="hidden" name="template_id" id="selectedTemplateId">
                                
                                <div class="form-group">
                                    <label class="form-label" for="title">Report Title *</label>
                                    <input type="text" class="form-control" id="title" name="title" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="report_type">Report Type</label>
                                    <select class="form-control form-select" id="report_type" name="report_type">
                                        <option value="academic">Academic Report</option>
                                        <option value="monthly">Monthly Report</option>
                                        <option value="activity">Activity Report</option>
                                        <option value="special">Special Report</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="report_period">Report Period</label>
                                    <input type="month" class="form-control" id="report_period" name="report_period">
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="activity_date">Activity Date</label>
                                    <input type="date" class="form-control" id="activity_date" name="activity_date">
                                </div>

                                <div id="templateFields">
                                    <!-- Dynamic fields will be inserted here based on template -->
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Attachments</label>
                                    <div class="file-upload" onclick="document.getElementById('report_files').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to upload files or drag and drop</p>
                                        <small class="form-text">Maximum file size: 10MB. Supported formats: PDF, DOC, DOCX, JPG, PNG</small>
                                        <input type="file" class="file-input" id="report_files" name="report_files[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
                                    </div>
                                    <div class="file-list" id="fileList"></div>
                                </div>

                                <div class="form-group" style="margin-top: 2rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Submit Report
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="resetForm()">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Submitted Reports Tab -->
            <div class="tab-content" id="submitted-tab">
                <div class="card">
                    <div class="card-header">
                        <h3>Submitted Reports</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submitted_reports)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No reports submitted yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Template</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submitted_reports as $report): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($report['title']); ?></strong>
                                                    <?php if ($report['report_period']): ?>
                                                        <br><small class="text-muted"><?php echo date('F Y', strtotime($report['report_period'])); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($report['template_name'] ?? 'Custom'); ?></td>
                                                <td>
                                                    <span class="template-type"><?php echo ucfirst($report['report_type']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $report['status']; ?>">
                                                        <?php echo ucfirst($report['status']); ?>
                                                    </span>
                                                    <?php if ($report['reviewer_name']): ?>
                                                        <br><small>by <?php echo htmlspecialchars($report['reviewer_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($report['submitted_at'])); ?></td>
                                                <td>
                                                    <div style="display: flex; gap: 0.25rem;">
                                                        <a href="academic_reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <button class="btn btn-outline btn-sm" onclick="viewReport(<?php echo $report['id']; ?>)" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
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
        </main>
    </div>

    <!-- Report View Modal -->
    <div id="reportModal">
        <div style="background: var(--white); border-radius: var(--border-radius); width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
            <div style="padding: 1.5rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center;">
                <h3 id="modalTitle">Report Details</h3>
                <button onclick="closeModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--dark-gray); cursor: pointer;">&times;</button>
            </div>
            <div style="padding: 1.5rem;" id="modalContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const tabId = tab.getAttribute('data-tab');
                    
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    tab.classList.add('active');
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                });
            });

            // Template selection
            const templateCards = document.querySelectorAll('.template-card');
            templateCards.forEach(card => {
                card.addEventListener('click', function() {
                    const templateId = this.getAttribute('data-template-id');
                    selectTemplate(templateId);
                });
            });
        });

        // Template selection function
        function selectTemplate(templateId) {
            // Update UI
            document.querySelectorAll('.template-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`[data-template-id="${templateId}"]`).classList.add('selected');
            
            // Show form
            document.getElementById('reportForm').style.display = 'block';
            document.getElementById('selectedTemplateId').value = templateId;
            
            // Scroll to form
            document.getElementById('reportForm').scrollIntoView({ behavior: 'smooth' });
            
            // Load template fields
            loadTemplateFields(templateId);
        }

        // Load template fields via AJAX
        async function loadTemplateFields(templateId) {
            try {
                const response = await fetch(`../api/get_template_fields.php?template_id=${templateId}`);
                const data = await response.json();
                
                const fieldsContainer = document.getElementById('templateFields');
                fieldsContainer.innerHTML = '';
                
                if (data.fields && data.fields.sections) {
                    data.fields.sections.forEach(section => {
                        const fieldName = section.title.toLowerCase().replace(/[^a-z0-9]/g, '_');
                        const fieldId = `field_${fieldName}`;
                        
                        const fieldGroup = document.createElement('div');
                        fieldGroup.className = 'form-group';
                        
                        let fieldHtml = `
                            <label class="form-label" for="${fieldId}">${escapeHtml(section.title)} ${section.required ? '*' : ''}</label>
                        `;
                        
                        if (section.type === 'textarea' || section.type === 'richtext') {
                            fieldHtml += `
                                <textarea class="form-control" id="${fieldId}" name="${fieldName}" 
                                          ${section.required ? 'required' : ''} 
                                          placeholder="${escapeHtml(section.description || '')}"
                                          rows="6"></textarea>
                            `;
                        } else if (section.type === 'date') {
                            fieldHtml += `
                                <input type="date" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''}>
                            `;
                        } else if (section.type === 'number') {
                            fieldHtml += `
                                <input type="number" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''} step="0.01">
                            `;
                        } else {
                            fieldHtml += `
                                <input type="text" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''}
                                       placeholder="${escapeHtml(section.description || '')}">
                            `;
                        }
                        
                        if (section.description) {
                            fieldHtml += `<div class="form-text">${escapeHtml(section.description)}</div>`;
                        }
                        
                        fieldGroup.innerHTML = fieldHtml;
                        fieldsContainer.appendChild(fieldGroup);
                    });
                }
            } catch (error) {
                console.error('Error loading template fields:', error);
                document.getElementById('templateFields').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> Error loading template fields.
                    </div>
                `;
            }
        }

        // Escape HTML helper
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // File handling
        function handleFileSelect(input) {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            Array.from(input.files).forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span class="file-name">${escapeHtml(file.name)}</span>
                    <button type="button" class="file-remove" onclick="removeFile(this)">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                fileList.appendChild(fileItem);
            });
        }

        function removeFile(button) {
            const fileItem = button.closest('.file-item');
            fileItem.remove();
        }

        // Form reset
        function resetForm() {
            document.querySelectorAll('.template-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.getElementById('reportForm').style.display = 'none';
            document.getElementById('reportForm').reset();
            document.getElementById('fileList').innerHTML = '';
        }

        // View report in modal
        async function viewReport(reportId) {
            try {
                const response = await fetch(`../api/get_report.php?id=${reportId}`);
                const report = await response.json();
                
                document.getElementById('modalTitle').textContent = report.title;
                
                let content = `
                    <div class="form-group">
                        <label class="form-label">Template</label>
                        <div>${escapeHtml(report.template_name || 'Custom')}</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Report Type</label>
                        <div>${escapeHtml(report.report_type)}</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <span class="status-badge status-${report.status}">${report.status}</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Submitted</label>
                        <div>${new Date(report.submitted_at).toLocaleDateString()}</div>
                    </div>
                `;
                
                if (report.content) {
                    let reportContent;
                    if (typeof report.content === 'string') {
                        reportContent = JSON.parse(report.content);
                    } else {
                        reportContent = report.content;
                    }
                    
                    if (reportContent && typeof reportContent === 'object') {
                        Object.keys(reportContent).forEach(key => {
                            if (reportContent[key]) {
                                const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                                content += `
                                    <div class="form-group">
                                        <label class="form-label">${escapeHtml(label)}</label>
                                        <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">${escapeHtml(reportContent[key])}</div>
                                    </div>
                                `;
                            }
                        });
                    }
                }
                
                document.getElementById('modalContent').innerHTML = content;
                document.getElementById('reportModal').style.display = 'flex';
            } catch (error) {
                console.error('Error loading report:', error);
                document.getElementById('modalContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> Error loading report details.
                    </div>
                `;
                document.getElementById('reportModal').style.display = 'flex';
            }
        }

        function closeModal() {
            document.getElementById('reportModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('reportModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('reportModal').style.display === 'flex') {
                closeModal();
            }
        });
    </script>
</body>
</html>