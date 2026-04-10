<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
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

// Get available templates for General Secretary
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'general_secretary' OR role_specific IS NULL)
        AND is_active = true
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $templates = [];
    error_log("Templates query error: " . $e->getMessage());
}

// Get my submitted reports
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
    error_log("My reports query error: " . $e->getMessage());
}

// Get committee reports (reports submitted by other committee members)
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.title,
            r.template_id,
            r.user_id,
            r.report_type,
            r.report_period,
            r.activity_date,
            r.content,
            r.status,
            r.submitted_at,
            r.created_at,
            r.reviewed_by,
            r.reviewed_at,
            r.feedback,
            rt.name as template_name,
            u.full_name as author_name,
            u.role as author_role,
            reviewer.full_name as reviewer_name
        FROM reports r 
        LEFT JOIN report_templates rt ON r.template_id = rt.id
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN users reviewer ON r.reviewed_by = reviewer.id
        WHERE r.user_id != ? 
        AND u.role IN ('minister_environment', 'minister_health', 'minister_sports', 
                       'minister_public_relations', 'minister_culture', 'minister_gender',
                       'president_arbitration', 'vice_president_arbitration')
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $committee_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_reports = [];
    error_log("Committee reports query error: " . $e->getMessage());
}

// Get all reports for overview
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM reports 
        WHERE user_id != ?
    ");
    $stmt->execute([$user_id]);
    $committee_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_stats = ['total' => 0, 'submitted' => 0, 'reviewed' => 0, 'approved' => 0, 'rejected' => 0];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_report') {
        $template_id = $_POST['template_id'] ?? null;
        $title = $_POST['title'] ?? '';
        $report_type = $_POST['report_type'] ?? 'monthly';
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
                    VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
                
                $report_id = $pdo->lastInsertId();
                
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
                header("Location: reports.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error creating report: " . $e->getMessage();
                error_log("Report creation error: " . $e->getMessage());
            }
        }
    }
    
    // Handle review/approve report
    if (isset($_POST['action']) && $_POST['action'] === 'review_report') {
        $report_id = $_POST['report_id'];
        $review_status = $_POST['review_status'] ?? 'reviewed';
        $feedback = $_POST['feedback'] ?? '';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, feedback = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$review_status, $user_id, $feedback, $report_id]);
            
            $_SESSION['success_message'] = "Report " . ($review_status === 'approved' ? 'approved' : 'reviewed') . " successfully!";
            header("Location: reports.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error reviewing report: " . $e->getMessage();
            error_log("Report review error: " . $e->getMessage());
        }
    }
    
    // Handle edit report
    if (isset($_POST['action']) && $_POST['action'] === 'edit_report') {
        $report_id = $_POST['report_id'];
        $title = $_POST['title'] ?? '';
        $report_period = $_POST['report_period'] ?? null;
        $activity_date = $_POST['activity_date'] ?? null;
        
        try {
            $stmt = $pdo->prepare("SELECT template_id FROM reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($report) {
                $stmt = $pdo->prepare("SELECT fields FROM report_templates WHERE id = ?");
                $stmt->execute([$report['template_id']]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $content_data = [];
                if ($template) {
                    $template_fields = json_decode($template['fields'], true);
                    if (isset($template_fields['sections']) && is_array($template_fields['sections'])) {
                        foreach ($template_fields['sections'] as $section) {
                            $field_name = strtolower(preg_replace('/[^a-z0-9]/', '_', $section['title']));
                            $content_data[$field_name] = $_POST[$field_name] ?? '';
                        }
                    }
                }
                
                $stmt = $pdo->prepare("
                    UPDATE reports 
                    SET title = ?, report_period = ?, activity_date = ?, content = ?::jsonb, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$title, $report_period, $activity_date, json_encode($content_data), $report_id]);
                
                $_SESSION['success_message'] = "Report updated successfully!";
                header("Location: reports.php");
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating report: " . $e->getMessage();
            error_log("Report update error: " . $e->getMessage());
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
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            fputcsv($output, ['Report Export - ' . $report['title']]);
            fputcsv($output, []);
            fputcsv($output, ['Author:', $report['author_name']]);
            fputcsv($output, ['Template:', $report['template_name']]);
            fputcsv($output, ['Report Type:', $report['report_type']]);
            fputcsv($output, ['Status:', $report['status']]);
            fputcsv($output, ['Submitted:', $report['submitted_at']]);
            fputcsv($output, []);
            
            $content = json_decode($report['content'], true);
            if (is_array($content)) {
                foreach ($content as $key => $value) {
                    $label = ucwords(str_replace('_', ' ', $key));
                    fputcsv($output, [$label . ':', $value]);
                }
            }
            
            fclose($output);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error exporting report: " . $e->getMessage();
        header("Location: reports.php");
        exit();
    }
}

// Get report statistics for my reports
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_reports,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_reports,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_reports,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports
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
        'rejected_reports' => 0
    ];
}

// Insert general secretary specific templates if they don't exist
try {
    $gs_templates = [
        [
            'name' => 'General Secretary Monthly Report',
            'description' => 'Comprehensive monthly report on secretariat activities, student management, and committee coordination',
            'role_specific' => 'general_secretary',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'textarea', 'title' => 'Secretariat Activities Summary', 'required' => true, 'description' => 'Summary of key secretariat activities conducted during the month'],
                    ['type' => 'textarea', 'title' => 'Student Registrations', 'required' => true, 'description' => 'Number of new student registrations and summary'],
                    ['type' => 'textarea', 'title' => 'Committee Coordination', 'required' => true, 'description' => 'Coordination activities with various committees'],
                    ['type' => 'textarea', 'title' => 'Document Processing', 'required' => true, 'description' => 'Documents processed, issued, and pending'],
                    ['type' => 'textarea', 'title' => 'Meeting Minutes Status', 'required' => true, 'description' => 'Status of meeting minutes preparation and approval'],
                    ['type' => 'textarea', 'title' => 'Challenges Faced', 'required' => false, 'description' => 'Challenges encountered during the month'],
                    ['type' => 'textarea', 'title' => 'Next Month Plans', 'required' => true, 'description' => 'Planned activities for the coming month']
                ]
            ])
        ],
        [
            'name' => 'Student Management Report',
            'description' => 'Report on student registration, records management, and student affairs',
            'role_specific' => 'general_secretary',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'number', 'title' => 'Total Students Registered', 'required' => true, 'description' => 'Total number of students registered'],
                    ['type' => 'number', 'title' => 'New Students This Month', 'required' => true, 'description' => 'Number of new student registrations this month'],
                    ['type' => 'textarea', 'title' => 'Student Records Updates', 'required' => true, 'description' => 'Updates made to student records'],
                    ['type' => 'textarea', 'title' => 'Student Complaints Handled', 'required' => true, 'description' => 'Student complaints received and resolved'],
                    ['type' => 'textarea', 'title' => 'Academic Records Management', 'required' => true, 'description' => 'Management of academic records and transcripts'],
                    ['type' => 'textarea', 'title' => 'Student Welfare Activities', 'required' => false, 'description' => 'Activities related to student welfare']
                ]
            ])
        ],
        [
            'name' => 'Committee Meeting Report',
            'description' => 'Report on committee meetings, attendance, and minutes',
            'role_specific' => 'general_secretary',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'number', 'title' => 'Meetings Held', 'required' => true, 'description' => 'Number of committee meetings held'],
                    ['type' => 'number', 'title' => 'Average Attendance Rate', 'required' => true, 'description' => 'Average attendance percentage across meetings'],
                    ['type' => 'textarea', 'title' => 'Minutes Prepared', 'required' => true, 'description' => 'Meeting minutes prepared and submitted'],
                    ['type' => 'textarea', 'title' => 'Minutes Pending', 'required' => true, 'description' => 'Meeting minutes pending approval'],
                    ['type' => 'textarea', 'title' => 'Key Decisions Made', 'required' => true, 'description' => 'Key decisions from committee meetings'],
                    ['type' => 'textarea', 'title' => 'Action Items Tracking', 'required' => true, 'description' => 'Status of action items from meetings']
                ]
            ])
        ],
        [
            'name' => 'Documentation Report',
            'description' => 'Report on document processing, issuance, and management',
            'role_specific' => 'general_secretary',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'number', 'title' => 'Documents Processed', 'required' => true, 'description' => 'Total documents processed this period'],
                    ['type' => 'number', 'title' => 'Documents Pending', 'required' => true, 'description' => 'Documents pending processing'],
                    ['type' => 'textarea', 'title' => 'Document Types Summary', 'required' => true, 'description' => 'Breakdown of document types processed'],
                    ['type' => 'textarea', 'title' => 'Official Correspondence', 'required' => true, 'description' => 'Official letters and correspondence handled'],
                    ['type' => 'textarea', 'title' => 'Record Keeping Updates', 'required' => true, 'description' => 'Updates to official records and archives'],
                    ['type' => 'textarea', 'title' => 'Digital Document Management', 'required' => false, 'description' => 'Digital document system updates']
                ]
            ])
        ]
    ];

    foreach ($gs_templates as $template_data) {
        $check_stmt = $pdo->prepare("SELECT id FROM report_templates WHERE name = ? AND role_specific = 'general_secretary'");
        $check_stmt->execute([$template_data['name']]);
        
        if (!$check_stmt->fetch()) {
            $insert_stmt = $pdo->prepare("
                INSERT INTO report_templates (name, description, role_specific, report_type, fields, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?::jsonb, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $insert_stmt->execute([
                $template_data['name'],
                $template_data['description'],
                $template_data['role_specific'],
                $template_data['report_type'],
                $template_data['fields']
            ]);
        }
    }
    
    // Refresh templates list
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'general_secretary' OR role_specific IS NULL)
        AND is_active = true
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("General Secretary templates setup error: " . $e->getMessage());
}

// Get specific report for editing if requested
$current_report = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, rt.name as template_name, rt.fields as template_fields
            FROM reports r 
            LEFT JOIN report_templates rt ON r.template_id = rt.id 
            WHERE r.id = ? AND r.user_id = ?
        ");
        $stmt->execute([$_GET['id'], $user_id]);
        $current_report = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Report editing error: " . $e->getMessage());
    }
}

// Get dashboard statistics for sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as new_students FROM users WHERE role = 'student' AND status = 'active' AND created_at >= CURRENT_DATE - INTERVAL '30 days'");
    $new_students = $stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as committee_members FROM committee_members WHERE status = 'active'");
    $committee_members = $stmt->fetch(PDO::FETCH_ASSOC)['committee_members'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
    $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_tickets 
        FROM tickets 
        WHERE status IN ('open', 'in_progress')
    ");
    $stmt->execute();
    $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as pending_minutes 
        FROM meetings 
        WHERE status = 'completed' 
        AND id NOT IN (SELECT meeting_id FROM meeting_minutes WHERE status = 'approved')
    ");
    $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
} catch (PDOException $e) {
    $total_students = $new_students = $committee_members = $upcoming_meetings = $unread_messages = $pending_tickets = $pending_minutes = 0;
    error_log("Sidebar stats error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>General Secretary Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/rp_logo.png">
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
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

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

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

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

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .page-description {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

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
            border-left: 4px solid var(--primary-blue);
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon {
            background: var(--light-blue);
            color: var(--primary-blue);
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
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .tabs-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .tabs {
            display: flex;
            background: var(--white);
            border-bottom: 1px solid var(--medium-gray);
            overflow-x: auto;
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--dark-gray);
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
            background: var(--light-blue);
        }

        .content-section {
            display: none;
            padding: 1.25rem;
        }

        .content-section.active {
            display: block;
        }

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
            background: var(--light-blue);
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

        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .template-card {
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .template-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .template-card.selected {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .template-check {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            opacity: 0;
            transition: var(--transition);
        }

        .template-card.selected .template-check {
            opacity: 1;
        }

        .template-icon {
            width: 48px;
            height: 48px;
            background: var(--light-blue);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--primary-blue);
            margin-bottom: 1rem;
        }

        .template-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .template-description {
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .template-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: var(--light-gray);
            color: var(--dark-gray);
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-text {
            color: var(--dark-gray);
            font-size: 0.75rem;
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

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--light-blue);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-submitted {
            background: #fff3cd;
            color: #856404;
        }

        .status-reviewed {
            background: #cce7ff;
            color: #004085;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .table-responsive {
            overflow-x: auto;
        }

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

        .table tbody tr:hover {
            background: var(--light-blue);
        }

        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .file-list {
            margin-top: 1rem;
        }

        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            padding: 0.25rem;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .alert-info {
            background: #cce7ff;
            color: #004085;
            border-left-color: var(--info);
        }

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

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
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

        .text-muted {
            color: var(--dark-gray);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .template-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .template-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-left: 2px solid transparent;
                border-bottom: none;
            }

            .tab.active {
                border-left-color: var(--primary-blue);
                border-bottom-color: transparent;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .page-title {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="overlay" id="mobileOverlay"></div>
    
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - General Secretary</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">General Secretary</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
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
                    <a href="students.php">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Management</span>
                        <?php if ($new_students > 0): ?>
                            <span class="menu-badge"><?php echo $new_students; ?> new</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings & Attendance</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" class="active">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
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
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <main class="main-content" id="mainContent">
            <div class="page-header">
                <h1 class="page-title">Reports Management</h1>
                <p class="page-description">Create and manage your reports, review committee submissions</p>
            </div>

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

            <!-- Statistics Row -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $report_stats['total_reports']; ?></div>
                        <div class="stat-label">My Reports</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $report_stats['submitted_reports']; ?></div>
                        <div class="stat-label">My Pending</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $committee_stats['total']; ?></div>
                        <div class="stat-label">Committee Reports</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $committee_stats['submitted']; ?></div>
                        <div class="stat-label">Awaiting Review</div>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['action']) && $_GET['action'] === 'edit' && $current_report): ?>
                <!-- Edit Report View -->
                <div class="card">
                    <div class="card-header">
                        <h3>Edit Report: <?php echo htmlspecialchars($current_report['title']); ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="reports.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="edit_report">
                            <input type="hidden" name="report_id" value="<?php echo $current_report['id']; ?>">
                            <input type="hidden" name="template_id" value="<?php echo $current_report['template_id']; ?>">
                            
                            <div class="form-group">
                                <label class="form-label">Report Title *</label>
                                <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars($current_report['title']); ?>" required>
                            </div>

                            <?php
                            $content = json_decode($current_report['content'], true) ?: [];
                            $template_fields = json_decode($current_report['template_fields'] ?? '{"sections": []}', true);
                            
                            if (!empty($template_fields['sections'])) {
                                foreach ($template_fields['sections'] as $section) {
                                    $field_name = strtolower(preg_replace('/[^a-z0-9]/', '_', $section['title']));
                                    $section_value = $content[$field_name] ?? '';
                                    ?>
                                    <div class="form-group">
                                        <label class="form-label"><?php echo htmlspecialchars($section['title']); ?> <?php echo $section['required'] ? '*' : ''; ?></label>
                                        <?php if (!empty($section['description'])): ?>
                                            <div class="form-text"><?php echo htmlspecialchars($section['description']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($section['type'] === 'textarea'): ?>
                                            <textarea name="<?php echo $field_name; ?>" class="form-control" rows="6" <?php echo $section['required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($section_value); ?></textarea>
                                        <?php elseif ($section['type'] === 'number'): ?>
                                            <input type="number" name="<?php echo $field_name; ?>" class="form-control" value="<?php echo htmlspecialchars($section_value); ?>" <?php echo $section['required'] ? 'required' : ''; ?>>
                                        <?php else: ?>
                                            <input type="text" name="<?php echo $field_name; ?>" class="form-control" value="<?php echo htmlspecialchars($section_value); ?>" <?php echo $section['required'] ? 'required' : ''; ?>>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                            
                            <div class="form-group">
                                <label class="form-label">Report Period (Optional)</label>
                                <input type="month" class="form-control" name="report_period" value="<?php echo $current_report['report_period']; ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Activity Date (Optional)</label>
                                <input type="date" class="form-control" name="activity_date" value="<?php echo $current_report['activity_date']; ?>">
                            </div>

                            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Report
                                </button>
                                <a href="reports.php" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Tabs -->
                <div class="tabs-container">
                    <div class="tabs">
                        <button class="tab active" onclick="showTab('create')">
                            <i class="fas fa-plus-circle"></i> Create Report
                        </button>
                        <button class="tab" onclick="showTab('my')">
                            <i class="fas fa-file-alt"></i> My Reports (<?php echo count($submitted_reports); ?>)
                        </button>
                        <button class="tab" onclick="showTab('committee')">
                            <i class="fas fa-users"></i> Committee Reports (<?php echo count($committee_reports); ?>)
                        </button>
                    </div>

                    <!-- Create Report Tab -->
                    <div id="create-tab" class="content-section active">
                        <div class="card">
                            <div class="card-header">
                                <h3>Create New Report</h3>
                            </div>
                            <div class="card-body">
                                <?php if (empty($templates)): ?>
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-triangle"></i> No report templates available.
                                    </div>
                                <?php else: ?>
                                    <div class="template-grid" id="templateGrid">
                                        <?php foreach ($templates as $template): ?>
                                            <div class="template-card" data-template-id="<?php echo $template['id']; ?>">
                                                <div class="template-check">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <div class="template-icon">
                                                    <i class="fas fa-<?php 
                                                        echo $template['report_type'] === 'activity' ? 'tasks' : 
                                                             ($template['report_type'] === 'monthly' ? 'calendar-alt' : 'file-alt'); 
                                                    ?>"></i>
                                                </div>
                                                <h4 class="template-title"><?php echo htmlspecialchars($template['name']); ?></h4>
                                                <p class="template-description"><?php echo htmlspecialchars($template['description'] ?? 'No description available'); ?></p>
                                                <div class="template-type"><?php echo ucfirst($template['report_type']); ?> Report</div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

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
                                                <option value="monthly">Monthly Report</option>
                                                <option value="activity">Activity Report</option>
                                                <option value="team">Team Report</option>
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

                                        <div id="templateFields"></div>

                                        <div class="form-group">
                                            <label class="form-label">Attachments (Optional)</label>
                                            <div class="file-upload" onclick="document.getElementById('report_files').click()">
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <p>Click to upload files</p>
                                                <small class="form-text">Max 10MB. PDF, DOC, DOCX, JPG, PNG</small>
                                                <input type="file" class="file-input" id="report_files" name="report_files[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)" style="display: none;">
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

                    <!-- My Reports Tab -->
                    <div id="my-tab" class="content-section">
                        <div class="card">
                            <div class="card-header">
                                <h3>My Reports</h3>
                                <div class="card-header-actions">
                                    <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($submitted_reports)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No reports submitted yet.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Title</th>
                                                    <th>Template</th>
                                                    <th>Type</th>
                                                    <th>Period/Date</th>
                                                    <th>Status</th>
                                                    <th>Submitted</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($submitted_reports as $report): ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($report['title']); ?></strong></td
                                                        <td><?php echo htmlspecialchars($report['template_name'] ?? 'Custom'); ?></td
                                                        <td><span class="template-type"><?php echo ucfirst($report['report_type']); ?></span></td
                                                        <td>
                                                            <?php 
                                                            if ($report['report_period']) {
                                                                echo date('F Y', strtotime($report['report_period'] . '-01'));
                                                            } elseif ($report['activity_date']) {
                                                                echo date('M j, Y', strtotime($report['activity_date']));
                                                            } else {
                                                                echo '—';
                                                            }
                                                            ?>
                                                        </td
                                                        <td>
                                                            <span class="status-badge status-<?php echo $report['status']; ?>">
                                                                <?php echo ucfirst($report['status']); ?>
                                                            </span>
                                                        </td
                                                        <td><?php echo date('M j, Y', strtotime($report['submitted_at'])); ?></td
                                                        <td>
                                                            <div style="display: flex; gap: 0.25rem;">
                                                                <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export">
                                                                    <i class="fas fa-download"></i>
                                                                </a>
                                                                <?php if ($report['status'] === 'submitted'): ?>
                                                                    <a href="reports.php?action=edit&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Edit">
                                                                        <i class="fas fa-edit"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                                <button class="btn btn-outline btn-sm" onclick="viewReport(<?php echo $report['id']; ?>)" title="View">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                            </div>
                                                        </td
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Committee Reports Tab (for review) -->
                    <div id="committee-tab" class="content-section">
                        <div class="card">
                            <div class="card-header">
                                <h3>Committee Reports - Pending Review</h3>
                                <div class="card-header-actions">
                                    <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($committee_reports)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No committee reports submitted yet.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Author</th>
                                                    <th>Role</th>
                                                    <th>Title</th>
                                                    <th>Template</th>
                                                    <th>Period/Date</th>
                                                    <th>Status</th>
                                                    <th>Submitted</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($committee_reports as $report): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($report['author_name'] ?? 'Unknown'); ?></strong>
                                                            <br><small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $report['author_role'] ?? '')); ?></small>
                                                        </td
                                                        <td><?php echo ucfirst(str_replace('_', ' ', $report['author_role'] ?? '')); ?></td
                                                        <td><?php echo htmlspecialchars($report['title']); ?></td
                                                        <td><?php echo htmlspecialchars($report['template_name'] ?? 'Custom'); ?></td
                                                        <td>
                                                            <?php 
                                                            if ($report['report_period']) {
                                                                echo date('F Y', strtotime($report['report_period'] . '-01'));
                                                            } elseif ($report['activity_date']) {
                                                                echo date('M j, Y', strtotime($report['activity_date']));
                                                            } else {
                                                                echo '—';
                                                            }
                                                            ?>
                                                        </td
                                                        <td>
                                                            <span class="status-badge status-<?php echo $report['status']; ?>">
                                                                <?php echo ucfirst($report['status']); ?>
                                                            </span>
                                                        </td
                                                        <td><?php echo date('M j, Y', strtotime($report['submitted_at'])); ?></td
                                                        <td>
                                                            <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                                <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export">
                                                                    <i class="fas fa-download"></i>
                                                                </a>
                                                                <button class="btn btn-outline btn-sm" onclick="viewReport(<?php echo $report['id']; ?>)" title="View">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <?php if ($report['status'] === 'submitted'): ?>
                                                                    <button class="btn btn-success btn-sm" onclick="openReviewModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['title']); ?>')" title="Review">
                                                                        <i class="fas fa-check-circle"></i> Review
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Review Report</h3>
                <button class="modal-close" onclick="closeReviewModal()">&times;</button>
            </div>
            <form method="POST" action="reports.php">
                <input type="hidden" name="action" value="review_report">
                <input type="hidden" name="report_id" id="reviewReportId">
                <div class="modal-body">
                    <p><strong>Report:</strong> <span id="reviewReportTitle"></span></p>
                    <div class="form-group">
                        <label class="form-label">Review Status</label>
                        <select class="form-control" name="review_status" required>
                            <option value="reviewed">Mark as Reviewed</option>
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Feedback / Comments</label>
                        <textarea class="form-control" name="feedback" rows="4" placeholder="Provide feedback to the committee member..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeReviewModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report View Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent"></div>
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

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Tab functionality
        function showTab(tabName) {
            document.querySelectorAll('.content-section').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Template selection
        document.querySelectorAll('.template-card').forEach(card => {
            card.addEventListener('click', function() {
                const templateId = this.getAttribute('data-template-id');
                selectTemplate(templateId);
            });
        });

        function selectTemplate(templateId) {
            document.querySelectorAll('.template-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`[data-template-id="${templateId}"]`).classList.add('selected');
            document.getElementById('reportForm').style.display = 'block';
            document.getElementById('selectedTemplateId').value = templateId;
            document.getElementById('reportForm').scrollIntoView({ behavior: 'smooth' });
            loadTemplateFields(templateId);
        }

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
                        
                        let fieldHtml = `<label class="form-label" for="${fieldId}">${escapeHtml(section.title)} ${section.required ? '*' : ''}</label>`;
                        
                        if (section.type === 'textarea') {
                            fieldHtml += `<textarea class="form-control" id="${fieldId}" name="${fieldName}" ${section.required ? 'required' : ''} placeholder="${escapeHtml(section.description || '')}" rows="6"></textarea>`;
                        } else if (section.type === 'number') {
                            fieldHtml += `<input type="number" class="form-control" id="${fieldId}" name="${fieldName}" ${section.required ? 'required' : ''} step="0.01">`;
                        } else if (section.type === 'date') {
                            fieldHtml += `<input type="date" class="form-control" id="${fieldId}" name="${fieldName}" ${section.required ? 'required' : ''}>`;
                        } else {
                            fieldHtml += `<input type="text" class="form-control" id="${fieldId}" name="${fieldName}" ${section.required ? 'required' : ''} placeholder="${escapeHtml(section.description || '')}">`;
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
                document.getElementById('templateFields').innerHTML = `<div class="alert alert-danger">Error loading template fields.</div>`;
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function handleFileSelect(input) {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            Array.from(input.files).forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `<span class="file-name">${escapeHtml(file.name)}</span><button type="button" class="file-remove" onclick="removeFile(this)"><i class="fas fa-times"></i></button>`;
                fileList.appendChild(fileItem);
            });
        }

        function removeFile(button) {
            button.closest('.file-item').remove();
        }

        function resetForm() {
            document.querySelectorAll('.template-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.getElementById('reportForm').style.display = 'none';
            document.getElementById('reportForm').reset();
            document.getElementById('fileList').innerHTML = '';
            document.getElementById('templateFields').innerHTML = '';
        }

        async function viewReport(reportId) {
            try {
                const response = await fetch(`../api/get_report.php?id=${reportId}`);
                const report = await response.json();
                document.querySelector('.modal-title').textContent = report.title;
                
                let content = `
                    <div class="form-group"><label class="form-label">Author</label><div>${escapeHtml(report.author_name || 'Unknown')}</div></div>
                    <div class="form-group"><label class="form-label">Template</label><div>${escapeHtml(report.template_name || 'Custom')}</div></div>
                    <div class="form-group"><label class="form-label">Report Type</label><div>${escapeHtml(report.report_type)}</div></div>
                    <div class="form-group"><label class="form-label">Status</label><span class="status-badge status-${report.status}">${report.status}</span></div>
                    <div class="form-group"><label class="form-label">Submitted</label><div>${new Date(report.submitted_at).toLocaleDateString()}</div></div>
                `;
                
                if (report.content) {
                    let reportContent = typeof report.content === 'string' ? JSON.parse(report.content) : report.content;
                    if (reportContent && typeof reportContent === 'object') {
                        Object.keys(reportContent).forEach(key => {
                            if (reportContent[key]) {
                                const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                                content += `<div class="form-group"><label class="form-label">${escapeHtml(label)}</label><div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">${escapeHtml(reportContent[key])}</div></div>`;
                            }
                        });
                    }
                }
                
                if (report.feedback) {
                    content += `<div class="form-group"><label class="form-label">Feedback</label><div style="background: #fff3cd; padding: 1rem; border-radius: var(--border-radius);">${escapeHtml(report.feedback)}</div></div>`;
                }
                
                document.getElementById('modalContent').innerHTML = content;
                document.getElementById('reportModal').classList.add('show');
            } catch (error) {
                console.error('Error loading report:', error);
                document.getElementById('modalContent').innerHTML = `<div class="alert alert-danger">Error loading report details.</div>`;
                document.getElementById('reportModal').classList.add('show');
            }
        }

        function closeModal() {
            document.getElementById('reportModal').classList.remove('show');
        }

        function openReviewModal(reportId, reportTitle) {
            document.getElementById('reviewReportId').value = reportId;
            document.getElementById('reviewReportTitle').textContent = reportTitle;
            document.getElementById('reviewModal').classList.add('show');
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').classList.remove('show');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('reportModal');
            const reviewModal = document.getElementById('reviewModal');
            if (event.target === modal) closeModal();
            if (event.target === reviewModal) closeReviewModal();
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeReviewModal();
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card, .stat-card');
            cards.forEach((card, index) => {
                card.style.animation = `fadeInUp 0.4s ease forwards`;
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            const style = document.createElement('style');
            style.textContent = `@keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }`;
            document.head.appendChild(style);
            
            setTimeout(() => cards.forEach(card => card.style.opacity = '1'), 500);
        });
    </script>
</body>
</html>