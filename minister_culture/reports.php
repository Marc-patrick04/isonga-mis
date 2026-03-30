<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
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

// Get sidebar statistics
try {
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets_all = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets_all = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'] ?? 0;
    
    // Unread messages
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    } catch (PDOException $e) {
        $unread_messages = 0;
    }
    
    // New students count
    $new_students = 0;
    try {
        $new_students_stmt = $pdo->prepare("
            SELECT COUNT(*) as new_students 
            FROM users 
            WHERE role = 'student' 
            AND status = 'active' 
            AND created_at >= NOW() - INTERVAL '7 days'
        ");
        $new_students_stmt->execute();
        $new_students = $new_students_stmt->fetch(PDO::FETCH_ASSOC)['new_students'] ?? 0;
    } catch (PDOException $e) {
        $new_students = 0;
    }
    
    // Upcoming meetings count
    $upcoming_meetings = 0;
    try {
        $upcoming_meetings = $pdo->query("
            SELECT COUNT(*) as count FROM meetings 
            WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $upcoming_meetings = 0;
    }
    
    // Pending minutes count
    $pending_minutes = 0;
    try {
        $pending_minutes = $pdo->query("
            SELECT COUNT(*) as count FROM meeting_minutes 
            WHERE approval_status = 'submitted'
        ")->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        $pending_minutes = 0;
    }
    
    // Pending tickets for badge
    $pending_tickets_badge = 0;
    try {
        $ticketStmt = $pdo->prepare("
            SELECT COUNT(*) as pending_tickets 
            FROM tickets 
            WHERE status IN ('open', 'in_progress') 
            AND (assigned_to = ? OR assigned_to IS NULL)
        ");
        $ticketStmt->execute([$user_id]);
        $pending_tickets_badge = $ticketStmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
    } catch (PDOException $e) {
        $pending_tickets_badge = 0;
    }
    
    // Pending reports
    $pending_reports = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'submitted'");
        $pending_reports = $stmt->fetch(PDO::FETCH_ASSOC)['pending_reports'] ?? 0;
    } catch (PDOException $e) {
        $pending_reports = 0;
    }
    
    // Pending documents
    $pending_docs = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as pending_docs FROM documents WHERE status = 'draft'");
        $pending_docs = $stmt->fetch(PDO::FETCH_ASSOC)['pending_docs'] ?? 0;
    } catch (PDOException $e) {
        $pending_docs = 0;
    }
    
} catch (PDOException $e) {
    $total_tickets_all = $open_tickets_all = $unread_messages = 0;
    $new_students = $upcoming_meetings = $pending_minutes = $pending_tickets_badge = $pending_reports = $pending_docs = 0;
}

// Get available templates for Minister of Culture (PostgreSQL compatible)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'minister_culture' OR role_specific IS NULL)
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
        $title = trim($_POST['title'] ?? '');
        $report_type = $_POST['report_type'] ?? 'activity';
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
                    $field_name = strtolower(str_replace(' ', '_', $section['title']));
                    $content_data[$field_name] = $_POST[$field_name] ?? '';
                }
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, submitted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())
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
                                    INSERT INTO report_media (report_id, file_name, file_path, file_type, file_size, uploaded_by)
                                    VALUES (?, ?, ?, ?, ?, ?)
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
                
                $_SESSION['success_message'] = "Cultural report submitted successfully!";
                header("Location: reports.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error creating report: " . $e->getMessage();
                error_log("Report creation error: " . $e->getMessage());
                header("Location: reports.php");
                exit();
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
            header('Content-Disposition: attachment; filename="cultural_report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV header
            fputcsv($output, ['Cultural Report Export - ' . $report['title']]);
            fputcsv($output, []); // Empty row
            fputcsv($output, ['Template:', $report['template_name']]);
            fputcsv($output, ['Author:', $report['author_name']]);
            fputcsv($output, ['Report Type:', $report['report_type']]);
            fputcsv($output, ['Status:', $report['status']]);
            fputcsv($output, ['Submitted:', $report['submitted_at']]);
            fputcsv($output, []); // Empty row
            
            // Report content
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

// Insert culture-specific templates if they don't exist
try {
    $culture_templates = [
        [
            'name' => 'Cultural Festival Report',
            'description' => 'Comprehensive report for cultural festivals and celebrations',
            'role_specific' => 'minister_culture',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'text', 'title' => 'Festival Name', 'required' => true, 'description' => 'Name of the cultural festival or celebration'],
                    ['type' => 'date', 'title' => 'Festival Date', 'required' => true, 'description' => 'Date when the festival took place'],
                    ['type' => 'text', 'title' => 'Festival Location', 'required' => true, 'description' => 'Venue where the festival was held'],
                    ['type' => 'number', 'title' => 'Participant Count', 'required' => true, 'description' => 'Total number of participants and attendees'],
                    ['type' => 'textarea', 'title' => 'Festival Objectives', 'required' => true, 'description' => 'Main objectives of the cultural festival'],
                    ['type' => 'textarea', 'title' => 'Cultural Activities', 'required' => true, 'description' => 'Detailed description of cultural activities performed'],
                    ['type' => 'textarea', 'title' => 'Cultural Groups Involved', 'required' => true, 'description' => 'List of cultural groups and clubs that participated'],
                    ['type' => 'textarea', 'title' => 'Traditional Elements', 'required' => true, 'description' => 'Traditional dances, music, and cultural elements showcased'],
                    ['type' => 'textarea', 'title' => 'Achievements', 'required' => true, 'description' => 'Key achievements and successes from the festival'],
                    ['type' => 'textarea', 'title' => 'Challenges Faced', 'required' => false, 'description' => 'Any challenges encountered during the festival'],
                    ['type' => 'textarea', 'title' => 'Participant Feedback', 'required' => false, 'description' => 'Feedback received from participants and attendees'],
                    ['type' => 'textarea', 'title' => 'Recommendations', 'required' => true, 'description' => 'Suggestions for future cultural festivals']
                ]
            ])
        ],
        [
            'name' => 'Monthly Cultural Activities Report',
            'description' => 'Monthly summary of all cultural and arts activities',
            'role_specific' => 'minister_culture',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'textarea', 'title' => 'Cultural Activities Completed', 'required' => true, 'description' => 'List all cultural activities completed during the month'],
                    ['type' => 'textarea', 'title' => 'Club Activities', 'required' => true, 'description' => 'Summary of cultural club activities and meetings'],
                    ['type' => 'textarea', 'title' => 'Student Participation', 'required' => true, 'description' => 'Level and diversity of student participation'],
                    ['type' => 'textarea', 'title' => 'Cultural Competitions', 'required' => true, 'description' => 'Cultural competitions organized and results'],
                    ['type' => 'textarea', 'title' => 'Traditional Arts Promotion', 'required' => true, 'description' => 'Efforts to promote traditional Rwandan arts'],
                    ['type' => 'textarea', 'title' => 'Civic Education Activities', 'required' => true, 'description' => 'Civic education and awareness activities'],
                    ['type' => 'textarea', 'title' => 'Challenges Faced', 'required' => false, 'description' => 'Cultural and civic education challenges'],
                    ['type' => 'textarea', 'title' => 'Next Month Plans', 'required' => true, 'description' => 'Planned cultural activities for the coming month'],
                    ['type' => 'textarea', 'title' => 'Budget Utilization', 'required' => false, 'description' => 'How the cultural budget was utilized']
                ]
            ])
        ],
        [
            'name' => 'Cultural Club Visit Report',
            'description' => 'Report for visiting and evaluating cultural clubs',
            'role_specific' => 'minister_culture',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'text', 'title' => 'Club Name', 'required' => true, 'description' => 'Name of the cultural club visited'],
                    ['type' => 'date', 'title' => 'Visit Date', 'required' => true, 'description' => 'Date of the club visit'],
                    ['type' => 'number', 'title' => 'Members Present', 'required' => true, 'description' => 'Number of club members present during visit'],
                    ['type' => 'textarea', 'title' => 'Activities Observed', 'required' => true, 'description' => 'Detailed description of activities observed'],
                    ['type' => 'textarea', 'title' => 'Student Engagement', 'required' => true, 'description' => 'Level and quality of student engagement'],
                    ['type' => 'textarea', 'title' => 'Cultural Skills Development', 'required' => true, 'description' => 'Cultural skills being developed by members'],
                    ['type' => 'textarea', 'title' => 'Club Achievements', 'required' => true, 'description' => 'Notable achievements and successes of the club'],
                    ['type' => 'textarea', 'title' => 'Areas for Improvement', 'required' => true, 'description' => 'Suggestions for club improvement and development'],
                    ['type' => 'textarea', 'title' => 'Support Required', 'required' => false, 'description' => 'Any support needed from student union']
                ]
            ])
        ],
        [
            'name' => 'College Troupe Performance Report',
            'description' => 'Report for college troupe performances and activities',
            'role_specific' => 'minister_culture',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'text', 'title' => 'Performance Title', 'required' => true, 'description' => 'Name or title of the performance'],
                    ['type' => 'date', 'title' => 'Performance Date', 'required' => true, 'description' => 'Date of the performance'],
                    ['type' => 'text', 'title' => 'Performance Venue', 'required' => true, 'description' => 'Location where performance took place'],
                    ['type' => 'number', 'title' => 'Audience Size', 'required' => true, 'description' => 'Estimated number of audience members'],
                    ['type' => 'textarea', 'title' => 'Performance Description', 'required' => true, 'description' => 'Detailed description of the performance'],
                    ['type' => 'textarea', 'title' => 'Cultural Elements', 'required' => true, 'description' => 'Traditional cultural elements featured'],
                    ['type' => 'textarea', 'title' => 'Troupe Members', 'required' => true, 'description' => 'List of troupe members who performed'],
                    ['type' => 'textarea', 'title' => 'Rehearsal Details', 'required' => true, 'description' => 'Rehearsal schedule and preparation'],
                    ['type' => 'textarea', 'title' => 'Audience Response', 'required' => false, 'description' => 'Feedback and response from audience'],
                    ['type' => 'textarea', 'title' => 'Challenges', 'required' => false, 'description' => 'Challenges faced during preparation or performance'],
                    ['type' => 'textarea', 'title' => 'Future Plans', 'required' => true, 'description' => 'Plans for future performances and improvements']
                ]
            ])
        ],
        [
            'name' => 'Civic Education Program Report',
            'description' => 'Report on civic education and awareness programs',
            'role_specific' => 'minister_culture',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    ['type' => 'text', 'title' => 'Program Title', 'required' => true, 'description' => 'Name of the civic education program'],
                    ['type' => 'date', 'title' => 'Program Date', 'required' => true, 'description' => 'Date when program was conducted'],
                    ['type' => 'text', 'title' => 'Target Audience', 'required' => true, 'description' => 'Student groups targeted by the program'],
                    ['type' => 'number', 'title' => 'Participants Count', 'required' => true, 'description' => 'Number of students who participated'],
                    ['type' => 'textarea', 'title' => 'Program Objectives', 'required' => true, 'description' => 'Main objectives of the civic education program'],
                    ['type' => 'textarea', 'title' => 'Topics Covered', 'required' => true, 'description' => 'Civic education topics and themes addressed'],
                    ['type' => 'textarea', 'title' => 'Teaching Methods', 'required' => true, 'description' => 'Methods used to deliver civic education'],
                    ['type' => 'textarea', 'title' => 'Student Engagement', 'required' => true, 'description' => 'Level of student participation and interest'],
                    ['type' => 'textarea', 'title' => 'Impact Assessment', 'required' => true, 'description' => 'Assessment of program impact on students'],
                    ['type' => 'textarea', 'title' => 'Follow-up Activities', 'required' => false, 'description' => 'Planned follow-up and reinforcement activities']
                ]
            ])
        ]
    ];

    foreach ($culture_templates as $template_data) {
        $check_stmt = $pdo->prepare("SELECT id FROM report_templates WHERE name = ? AND role_specific = 'minister_culture'");
        $check_stmt->execute([$template_data['name']]);
        
        if (!$check_stmt->fetch()) {
            $insert_stmt = $pdo->prepare("
                INSERT INTO report_templates (name, description, role_specific, report_type, fields, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, true, NOW())
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
        WHERE (role_specific = 'minister_culture' OR role_specific IS NULL)
        AND is_active = true
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Culture templates setup error: " . $e->getMessage());
}

// Success/Error messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Cultural Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #A78BFA;
            --accent-purple: #7C3AED;
            --light-purple: #f3f0ff;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            --primary-purple: #A78BFA;
            --secondary-purple: #C4B5FD;
            --accent-purple: #8B5CF6;
            --light-purple: #1f1a2e;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #4dd0e1;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.85rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
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

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            border-color: var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.7rem;
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
            border-left: 4px solid var(--primary-purple);
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

        .stat-card.info {
            border-left-color: var(--info);
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
            background: var(--light-purple);
            color: var(--primary-purple);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
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

        /* Card */
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
            background: var(--light-purple);
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
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
            font-weight: 600;
            font-size: 0.85rem;
        }

        .tab:hover {
            color: var(--primary-purple);
        }

        .tab.active {
            color: var(--primary-purple);
            border-bottom-color: var(--primary-purple);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .template-card {
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .template-card:hover {
            border-color: var(--primary-purple);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .template-card.selected {
            border-color: var(--primary-purple);
            background: var(--light-purple);
        }

        .template-check {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 24px;
            height: 24px;
            background: var(--primary-purple);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            opacity: 0;
            transition: var(--transition);
        }

        .template-card.selected .template-check {
            opacity: 1;
        }

        .template-icon {
            width: 48px;
            height: 48px;
            background: var(--light-purple);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-purple);
            font-size: 1.2rem;
            margin-bottom: 0.75rem;
        }

        .template-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .template-description {
            color: var(--dark-gray);
            font-size: 0.75rem;
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }

        .template-type {
            background: var(--light-gray);
            color: var(--dark-gray);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
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
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--primary-purple);
            background: var(--light-purple);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--primary-purple);
            margin-bottom: 0.75rem;
        }

        .file-input {
            display: none;
        }

        .file-list {
            margin-top: 0.75rem;
        }

        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }

        .file-name {
            font-size: 0.75rem;
            color: var(--text-dark);
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
        }

        .file-remove:hover {
            background: var(--danger);
            color: white;
        }

        /* Table */
        .table-wrapper {
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
            background: var(--light-purple);
        }

        /* Status Badges */
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

        /* Alert */
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-purple);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--dark-gray);
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
                background: var(--primary-purple);
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

            .template-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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

            .tabs {
                justify-content: center;
            }

            .template-grid {
                grid-template-columns: 1fr;
            }

            .file-upload {
                padding: 1rem;
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

            .welcome-section h1 {
                font-size: 1.2rem;
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
                    <h1>Isonga - Cultural Reports</h1>
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
                        <div class="user-role">Minister of Culture & Civic Education</div>
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
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Cultural Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                        <?php if ($pending_tickets_badge > 0): ?>
                            <span class="menu-badge"><?php echo $pending_tickets_badge; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="resources.php">
                        <i class="fas fa-palette"></i>
                        <span>Cultural Resources</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="troupe.php">
                        <i class="fas fa-music"></i>
                        <span>College Troupe</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" class="active">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                        <?php if ($upcoming_meetings > 0): ?>
                            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
                        <?php endif; ?>
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

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Cultural Reports & Analytics 📊</h1>
                    <p>Create, manage, and export cultural reports using professional templates</p>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
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
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($templates); ?></div>
                        <div class="stat-label">Templates</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab(event, 'create')">Create New Report</button>
                <button class="tab" onclick="switchTab(event, 'submitted')">Submitted Reports (<?php echo count($submitted_reports); ?>)</button>
            </div>

            <!-- Create Report Tab -->
            <div id="create-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Select Cultural Report Template</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($templates)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No cultural templates available at the moment.
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
                                                echo $template['report_type'] === 'activity' ? 'palette' : 
                                                     ($template['report_type'] === 'monthly' ? 'calendar-alt' : 'file-alt'); 
                                            ?>"></i>
                                        </div>
                                        <h4 class="template-title"><?php echo htmlspecialchars($template['name']); ?></h4>
                                        <p class="template-description"><?php echo htmlspecialchars($template['description'] ?? 'No description available'); ?></p>
                                        <div class="template-type"><?php echo ucfirst($template['report_type']); ?> Report</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Report Form (Initially Hidden) -->
                            <form id="reportForm" method="POST" enctype="multipart/form-data" style="display: none; margin-top: 1.5rem;">
                                <input type="hidden" name="action" value="create_report">
                                <input type="hidden" name="template_id" id="selectedTemplateId">
                                
                                <div class="form-group">
                                    <label class="form-label">Report Title *</label>
                                    <input type="text" class="form-control" name="title" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Report Type</label>
                                    <select class="form-select" name="report_type">
                                        <option value="activity">Activity Report</option>
                                        <option value="monthly">Monthly Report</option>
                                        <option value="special">Special Report</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Report Period</label>
                                    <input type="month" class="form-control" name="report_period">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Activity Date</label>
                                    <input type="date" class="form-control" name="activity_date">
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

                                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Submit Cultural Report
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
            <div id="submitted-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Submitted Cultural Reports</h3>
                        <button class="btn btn-outline btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submitted_reports)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-alt"></i>
                                <p>No cultural reports submitted yet.</p>
                                <p>Use the "Create New Report" tab to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrapper">
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
                                                        <br><small><?php echo date('F Y', strtotime($report['report_period'])); ?></small>
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
                                                    <div class="action-buttons" style="display: flex; gap: 0.5rem;">
                                                        <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export">
                                                            <i class="fas fa-download"></i> Export
                                                        </a>
                                                        <button class="btn btn-outline btn-sm" onclick="viewReport(<?php echo $report['id']; ?>)" title="View">
                                                            <i class="fas fa-eye"></i> View
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
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Cultural Report Details</h3>
                <button class="close-modal" onclick="closeModal('reportModal')">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('reportModal')">Close</button>
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
                    : '<i class="fas fa-bars</i>';
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
        function switchTab(event, tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }
        }

        // Template selection
        const templateCards = document.querySelectorAll('.template-card');
        templateCards.forEach(card => {
            card.addEventListener('click', function() {
                const templateId = this.getAttribute('data-template-id');
                selectTemplate(templateId);
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
            const templatesData = <?php echo json_encode($templates); ?>;
            const selectedTemplate = templatesData.find(t => t.id == templateId);
            
            const fieldsContainer = document.getElementById('templateFields');
            fieldsContainer.innerHTML = '';
            
            if (selectedTemplate && selectedTemplate.fields) {
                let fields;
                try {
                    fields = typeof selectedTemplate.fields === 'string' 
                        ? JSON.parse(selectedTemplate.fields) 
                        : selectedTemplate.fields;
                } catch (e) {
                    fields = null;
                }
                
                if (fields && fields.sections && Array.isArray(fields.sections)) {
                    fields.sections.forEach(section => {
                        const fieldName = section.title.toLowerCase().replace(/ /g, '_');
                        const fieldId = `field_${fieldName}`;
                        
                        const fieldGroup = document.createElement('div');
                        fieldGroup.className = 'form-group';
                        
                        let fieldHtml = `
                            <label class="form-label" for="${fieldId}">${section.title} ${section.required ? '*' : ''}</label>
                        `;
                        
                        if (section.type === 'textarea') {
                            fieldHtml += `
                                <textarea class="form-control" id="${fieldId}" name="${fieldName}" 
                                          ${section.required ? 'required' : ''} 
                                          placeholder="${section.description || ''}"
                                          rows="5"></textarea>
                            `;
                        } else if (section.type === 'date') {
                            fieldHtml += `
                                <input type="date" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''}>
                            `;
                        } else if (section.type === 'number') {
                            fieldHtml += `
                                <input type="number" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''}>
                            `;
                        } else {
                            fieldHtml += `
                                <input type="text" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''}
                                       placeholder="${section.description || ''}">
                            `;
                        }
                        
                        if (section.description) {
                            fieldHtml += `<div class="form-text">${section.description}</div>`;
                        }
                        
                        fieldGroup.innerHTML = fieldHtml;
                        fieldsContainer.appendChild(fieldGroup);
                    });
                } else {
                    fieldsContainer.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Template fields structure is invalid.
                        </div>
                    `;
                }
            } else {
                fieldsContainer.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> No fields defined for this template.
                    </div>
                `;
            }
        }

        // File handling
        function handleFileSelect(input) {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            Array.from(input.files).forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span class="file-name">${file.name}</span>
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
            document.getElementById('templateFields').innerHTML = '';
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
                        <div>${report.template_name || 'Custom'}</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Report Type</label>
                        <div>${report.report_type}</div>
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
                    try {
                        reportContent = typeof report.content === 'string' 
                            ? JSON.parse(report.content) 
                            : report.content;
                    } catch (e) {
                        reportContent = null;
                    }
                    
                    if (reportContent && typeof reportContent === 'object') {
                        Object.keys(reportContent).forEach(key => {
                            if (reportContent[key]) {
                                const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                                content += `
                                    <div class="form-group">
                                        <label class="form-label">${label}</label>
                                        <div style="background: var(--light-gray); padding: 0.75rem; border-radius: var(--border-radius); white-space: pre-wrap;">${escapeHtml(reportContent[key])}</div>
                                    </div>
                                `;
                            }
                        });
                    }
                }
                
                document.getElementById('modalContent').innerHTML = content;
                openModal('reportModal');
            } catch (error) {
                console.error('Error loading report:', error);
                alert('Error loading report details');
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('reportModal');
            if (event.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>