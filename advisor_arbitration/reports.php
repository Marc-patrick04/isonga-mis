<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Arbitration Advisor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'advisor_arbitration') {
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

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}

// Get available templates for Arbitration Advisor with categories
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'advisor_arbitration' OR role_specific IS NULL OR role_specific = '')
        AND is_active = TRUE
        ORDER BY 
            CASE category 
                WHEN 'arbitration' THEN 1
                WHEN 'case_review' THEN 2
                WHEN 'hearing' THEN 3
                WHEN 'settlement' THEN 4
                WHEN 'appeal' THEN 5
                ELSE 6
            END,
            name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $templates = [];
    error_log("Templates query error: " . $e->getMessage());
}

// Group templates by category
$templates_by_category = [];
foreach ($templates as $template) {
    $category = $template['category'] ?? 'general';
    if (!isset($templates_by_category[$category])) {
        $templates_by_category[$category] = [];
    }
    $templates_by_category[$category][] = $template;
}

// Get team members for collaborative reports
try {
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.name, cm.role, cm.email, cm.phone, u.avatar_url
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        WHERE cm.role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        AND cm.status = 'active'
        ORDER BY 
            CASE cm.role 
                WHEN 'president_arbitration' THEN 1
                WHEN 'vice_president_arbitration' THEN 2
                WHEN 'advisor_arbitration' THEN 3
                WHEN 'secretary_arbitration' THEN 4
                ELSE 5
            END
    ");
    $stmt->execute();
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $team_members = [];
    error_log("Team members query error: " . $e->getMessage());
}

// Get submitted reports
try {
    $stmt = $pdo->prepare("
        SELECT r.*, rt.name as template_name, rt.report_type, rt.category as template_category,
               u.full_name as reviewer_name,
               cm.name as team_lead_name
        FROM reports r 
        LEFT JOIN report_templates rt ON r.template_id = rt.id
        LEFT JOIN users u ON r.reviewed_by = u.id
        LEFT JOIN committee_members cm ON r.user_id = cm.id
        WHERE (r.user_id = ? OR (r.is_team_report = 1 AND r.team_role = 'combined' AND r.user_id IN (
            SELECT id FROM committee_members 
            WHERE role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        )))
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $submitted_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $submitted_reports = [];
    error_log("Reports query error: " . $e->getMessage());
}

// Get assigned sections for team reports
try {
    $stmt = $pdo->prepare("
        SELECT rs.*, r.title as report_title, r.status as report_status,
               cm.name as assigner_name, cm.role as assigner_role
        FROM report_sections rs
        JOIN reports r ON rs.report_id = r.id
        JOIN committee_members cm ON r.user_id = cm.id
        WHERE rs.assigned_to = ?
        AND rs.status != 'completed'
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $assigned_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assigned_sections = [];
    error_log("Assigned sections error: " . $e->getMessage());
}

// Get reports that need advisor review
try {
    $stmt = $pdo->prepare("
        SELECT r.*, rt.name as template_name, cm.name as author_name, cm.role as author_role
        FROM reports r 
        LEFT JOIN report_templates rt ON r.template_id = rt.id
        JOIN committee_members cm ON r.user_id = cm.id
        WHERE r.status = 'submitted' 
        AND r.review_required = 1
        AND (r.advisor_reviewer = ? OR r.advisor_reviewer IS NULL)
        ORDER BY r.submitted_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $reports_for_review = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reports_for_review = [];
    error_log("Reports for review error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_report') {
        $template_id = $_POST['template_id'] ?? null;
        $title = $_POST['title'] ?? '';
        $report_type = $_POST['report_type'] ?? 'monthly';
        $report_period = $_POST['report_period'] ?? null;
        $activity_date = $_POST['activity_date'] ?? null;
        $is_team_report = isset($_POST['is_team_report']) ? 1 : 0;
        $team_role = $_POST['team_role'] ?? 'combined';
        
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
            
            if (isset($template_fields['sections']) && is_array($template_fields['sections'])) {
                foreach ($template_fields['sections'] as $section) {
                    $field_name = strtolower(str_replace(' ', '_', $section['title']));
                    $content_data[$field_name] = $_POST[$field_name] ?? '';
                }
            }
            
            try {
                if ($is_team_report) {
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, is_team_report, team_role, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 1, ?, CURRENT_TIMESTAMP)
                    ");
                    
                    $stmt->execute([
                        $title,
                        $template_id,
                        $user_id,
                        $report_type,
                        $report_period,
                        $activity_date,
                        json_encode($content_data),
                        $team_role
                    ]);
                    
                    $report_id = $pdo->lastInsertId();
                    
                    if ($team_role !== 'combined') {
                        $sections = [];
                        foreach ($team_members as $member) {
                            if ($member['id'] != $user_id) {
                                $section_title = "Section for " . $member['name'] . " (" . $member['role'] . ")";
                                $stmt = $pdo->prepare("
                                    INSERT INTO report_sections (report_id, section_title, assigned_to, content, status, order_index)
                                    VALUES (?, ?, ?, '', 'draft', ?)
                                ");
                                $stmt->execute([$report_id, $section_title, $member['id'], count($sections) + 1]);
                                $section_id = $pdo->lastInsertId();
                                $sections[] = $section_id;
                            }
                        }
                        
                        $stmt = $pdo->prepare("UPDATE reports SET assigned_sections = ? WHERE id = ?");
                        $stmt->execute([json_encode($sections), $report_id]);
                    }
                    
                    $pdo->commit();
                    $_SESSION['success_message'] = "Team report created successfully! Arbitration committee members can now collaborate.";
                    
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)
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
                    $_SESSION['success_message'] = "Individual report submitted successfully!";
                }
                
                // Handle file uploads
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
                
                header("Location: reports.php");
                exit();
                
            } catch (PDOException $e) {
                if ($is_team_report) {
                    $pdo->rollBack();
                }
                $_SESSION['error_message'] = "Error creating report: " . $e->getMessage();
                error_log("Report creation error: " . $e->getMessage());
            }
        }
    }
    
    // Handle team report submission
    if (isset($_POST['action']) && $_POST['action'] === 'submit_team_report') {
        $report_id = $_POST['report_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE reports SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$report_id]);
            $_SESSION['success_message'] = "Team report submitted successfully!";
            header("Location: reports.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error submitting team report: " . $e->getMessage();
        }
    }
    
    // Handle section save
    if (isset($_POST['action']) && $_POST['action'] === 'save_section') {
        $section_id = $_POST['section_id'];
        $content = $_POST['content'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE report_sections SET content = ?, status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$content, $section_id]);
            $_SESSION['success_message'] = "Section saved successfully!";
            header("Location: reports.php?action=edit_section&id=" . $section_id);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error saving section: " . $e->getMessage();
        }
    }
    
    // Handle report review
    if (isset($_POST['action']) && $_POST['action'] === 'review_report') {
        $report_id = $_POST['report_id'];
        $review_comments = $_POST['review_comments'] ?? '';
        $review_status = $_POST['review_status'] ?? 'reviewed';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET status = ?, review_comments = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, advisor_reviewer = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $review_status,
                $review_comments,
                $user_id,
                $user_id,
                $report_id
            ]);
            
            $_SESSION['success_message'] = "Report review submitted successfully!";
            header("Location: reports.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error submitting review: " . $e->getMessage();
        }
    }
}

// Handle export request
if (isset($_GET['export']) && isset($_GET['id'])) {
    $report_id = (int)$_GET['id'];
    $format = $_GET['format'] ?? 'csv';
    
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, rt.name as template_name, cm.name as author_name, cm.role as author_role
            FROM reports r 
            LEFT JOIN report_templates rt ON r.template_id = rt.id
            JOIN committee_members cm ON r.user_id = cm.id
            WHERE r.id = ? AND (r.user_id = ? OR r.is_team_report = 1)
        ");
        $stmt->execute([$report_id, $user_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            $team_sections = [];
            if ($report['is_team_report']) {
                $stmt = $pdo->prepare("
                    SELECT rs.*, cm.name as assigned_name, cm.role as assigned_role
                    FROM report_sections rs
                    JOIN committee_members cm ON rs.assigned_to = cm.id
                    WHERE rs.report_id = ?
                    ORDER BY rs.order_index
                ");
                $stmt->execute([$report_id]);
                $team_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            if ($format === 'pdf') {
                // For PDF export, you would typically use a library like Dompdf
                // This is a placeholder for PDF export functionality
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="arbitration_report_' . $report_id . '_' . date('Y-m-d') . '.pdf"');
                // Implement PDF generation here
                echo "PDF export coming soon";
                exit();
            } else {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="arbitration_report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                fputcsv($output, ['Arbitration Committee Report - ' . $report['title']]);
                fputcsv($output, []);
                fputcsv($output, ['Template:', $report['template_name']]);
                fputcsv($output, ['Author:', $report['author_name'] . ' (' . $report['author_role'] . ')']);
                fputcsv($output, ['Report Type:', $report['report_type']]);
                fputcsv($output, ['Team Report:', $report['is_team_report'] ? 'Yes' : 'No']);
                fputcsv($output, ['Status:', $report['status']]);
                fputcsv($output, ['Submitted:', $report['submitted_at']]);
                fputcsv($output, []);
                
                $content = json_decode($report['content'], true);
                foreach ($content as $key => $value) {
                    $label = ucwords(str_replace('_', ' ', $key));
                    fputcsv($output, [$label . ':', $value]);
                }
                
                if (!empty($team_sections)) {
                    fputcsv($output, []);
                    fputcsv($output, ['ARBITRATION COMMITTEE SECTIONS']);
                    foreach ($team_sections as $section) {
                        fputcsv($output, []);
                        fputcsv($output, [$section['section_title'] . ' - ' . $section['assigned_name']]);
                        fputcsv($output, ['Content:', $section['content']]);
                        fputcsv($output, ['Status:', $section['status']]);
                    }
                }
                
                fclose($output);
                exit();
            }
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
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_reports,
            SUM(CASE WHEN is_team_report = 1 THEN 1 ELSE 0 END) as team_reports
        FROM reports 
        WHERE user_id = ? OR (is_team_report = 1 AND team_role = 'combined' AND user_id IN (
            SELECT id FROM committee_members 
            WHERE role IN ('president_arbitration', 'vice_president_arbitration', 'secretary_arbitration', 'advisor_arbitration')
        ))
    ");
    $stmt->execute([$user_id]);
    $report_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $report_stats = [
        'total_reports' => 0,
        'submitted_reports' => 0,
        'reviewed_reports' => 0,
        'approved_reports' => 0,
        'draft_reports' => 0,
        'team_reports' => 0
    ];
}

// Get advisor review statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_reviews,
            SUM(CASE WHEN status = 'reviewed' AND reviewed_by = ? THEN 1 ELSE 0 END) as completed_reviews,
            SUM(CASE WHEN status = 'submitted' AND review_required = 1 AND (advisor_reviewer = ? OR advisor_reviewer IS NULL) THEN 1 ELSE 0 END) as pending_reviews
        FROM reports 
        WHERE review_required = 1
    ");
    $stmt->execute([$user_id, $user_id]);
    $review_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $review_stats = [
        'total_reviews' => 0,
        'completed_reviews' => 0,
        'pending_reviews' => 0
    ];
}

// Get specific report for editing
$current_report = null;
$report_sections = [];
$current_section = null;
$current_review = null;

if (isset($_GET['action']) && isset($_GET['id'])) {
    if ($_GET['action'] === 'edit' && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT r.*, rt.name as template_name, rt.fields as template_fields
                FROM reports r 
                LEFT JOIN report_templates rt ON r.template_id = rt.id 
                WHERE r.id = ? AND (r.user_id = ? OR r.is_team_report = 1)
            ");
            $stmt->execute([$_GET['id'], $user_id]);
            $current_report = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($current_report && $current_report['is_team_report']) {
                $stmt = $pdo->prepare("
                    SELECT rs.*, cm.name as assigned_name, cm.role as assigned_role
                    FROM report_sections rs
                    JOIN committee_members cm ON rs.assigned_to = cm.id
                    WHERE rs.report_id = ?
                    ORDER BY rs.order_index
                ");
                $stmt->execute([$_GET['id']]);
                $report_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("Report editing error: " . $e->getMessage());
        }
    } elseif ($_GET['action'] === 'edit_section' && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT rs.*, r.title as report_title, r.status as report_status,
                       cm.name as assigner_name, cm.role as assigner_role
                FROM report_sections rs
                JOIN reports r ON rs.report_id = r.id
                JOIN committee_members cm ON r.user_id = cm.id
                WHERE rs.id = ? AND rs.assigned_to = ?
            ");
            $stmt->execute([$_GET['id'], $user_id]);
            $current_section = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Section editing error: " . $e->getMessage());
        }
    } elseif ($_GET['action'] === 'review' && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT r.*, rt.name as template_name, cm.name as author_name, cm.role as author_role
                FROM reports r 
                LEFT JOIN report_templates rt ON r.template_id = rt.id
                JOIN committee_members cm ON r.user_id = cm.id
                WHERE r.id = ? AND r.status = 'submitted' 
                AND r.review_required = 1
                AND (r.advisor_reviewer = ? OR r.advisor_reviewer IS NULL)
            ");
            $stmt->execute([$_GET['id'], $user_id]);
            $current_review = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Review report error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Arbitration Advisor Reports - Isonga RPSU</title>
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
            --purple: #6f42c1;
            --orange: #fd7e14;
            --teal: #20c997;
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
            --info: #29b6f6;
            --purple: #9c27b0;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
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
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--primary-blue);
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
            font-size: 0.9rem;
            color: var(--text-dark);
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
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            transform: translateY(-1px);
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
            text-align: center;
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .page-title p {
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

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-card.purple {
            border-left-color: var(--purple);
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
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
        }

        .stat-card.purple .stat-icon {
            background: #f0e6ff;
            color: var(--purple);
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

        /* Category Section */
        .template-category {
            margin-bottom: 2rem;
        }

        .category-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--medium-gray);
        }

        .category-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .category-icon.arbitration { background: #d4edda; color: var(--success); }
        .category-icon.case_review { background: #cce7ff; color: var(--info); }
        .category-icon.hearing { background: #fff3cd; color: #856404; }
        .category-icon.settlement { background: #e2e3ff; color: var(--purple); }
        .category-icon.appeal { background: #f8d7da; color: var(--danger); }
        .category-icon.general { background: var(--light-gray); color: var(--dark-gray); }

        .category-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .category-description {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-left: auto;
        }

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
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
            font-size: 0.8rem;
            opacity: 0;
            transition: var(--transition);
        }

        .template-card.selected .template-check {
            opacity: 1;
        }

        .template-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .template-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .template-description {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .template-type {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            background: var(--light-gray);
            color: var(--dark-gray);
            border-radius: 12px;
            display: inline-block;
        }

        .template-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            margin-left: 0.5rem;
        }

        .template-badge.legal {
            background: #d4edda;
            color: var(--success);
        }

        .template-badge.procedural {
            background: #cce7ff;
            color: var(--info);
        }

        .template-badge.advisory {
            background: #f0e6ff;
            color: var(--purple);
        }

        /* Team Options */
        .team-options {
            background: var(--light-blue);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin: 1rem 0;
            border-left: 4px solid var(--primary-blue);
        }

        .team-member-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .team-member-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--medium-gray);
            text-align: center;
        }

        .team-member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin: 0 auto 0.5rem;
            font-size: 1rem;
        }

        .team-member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .team-member-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }

        .team-member-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            gap: 0.25rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--dark-gray);
            transition: var(--transition);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
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
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        .form-text {
            font-size: 0.75rem;
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
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .file-upload p {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            line-height: 1;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
            border-color: var(--dark-gray);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Alert */
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

        .alert-info {
            background: #cce7ff;
            color: #004085;
            border-left-color: var(--info);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
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
                background: var(--primary-blue);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .template-grid {
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .table {
                display: none;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-bottom: none;
                border-left: 2px solid transparent;
            }

            .tab.active {
                border-left-color: var(--primary-blue);
                border-bottom-color: transparent;
            }

            .team-member-grid {
                grid-template-columns: repeat(2, 1fr);
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

            .page-title h1 {
                font-size: 1.2rem;
            }

            .team-member-grid {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Arbitration Reports</h1>
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
                        <div class="user-role">Advisor - Arbitration</div>
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
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>My Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" class="active">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
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
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Arbitration Advisor Reports</h1>
                    <p>Create, manage, and review arbitration reports and legal documents</p>
                </div>
            </div>

            <!-- Alert Messages -->
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
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $review_stats['pending_reviews']; ?></div>
                        <div class="stat-label">Pending Reviews</div>
                    </div>
                </div>
                
                <div class="stat-card purple">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $report_stats['team_reports']; ?></div>
                        <div class="stat-label">Team Reports</div>
                    </div>
                </div>
            </div>

            <?php if ($current_review): ?>
                <!-- Review Report View -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-search"></i> Review Report: <?php echo htmlspecialchars($current_review['title']); ?>
                        </h3>
                        <div>
                            <span class="status-badge status-<?php echo $current_review['status']; ?>">
                                <?php echo ucfirst($current_review['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Advisor Review Required:</strong> Please review this report for legal accuracy and compliance.
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Report Author</label>
                            <div><?php echo htmlspecialchars($current_review['author_name']); ?> (<?php echo htmlspecialchars(str_replace('_', ' ', $current_review['author_role'])); ?>)</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Template</label>
                            <div><?php echo htmlspecialchars($current_review['template_name'] ?? 'Custom'); ?></div>
                        </div>
                        
                        <h4 style="margin: 1.5rem 0 1rem 0;">Report Content</h4>
                        
                        <?php
                        $content = json_decode($current_review['content'], true) ?: [];
                        if (!empty($content)) {
                            foreach ($content as $key => $value) {
                                $label = ucwords(str_replace('_', ' ', $key));
                                ?>
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                    <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">
                                        <?php echo htmlspecialchars($value); ?>
                                    </div>
                                </div>
                                <?php
                            }
                        }
                        ?>
                        
                        <hr style="margin: 2rem 0;">
                        <h4 style="margin-bottom: 1rem;">Advisor Review</h4>
                        
                        <form method="POST" action="reports.php">
                            <input type="hidden" name="action" value="review_report">
                            <input type="hidden" name="report_id" value="<?php echo $current_review['id']; ?>">
                            
                            <div class="form-group">
                                <label class="form-label">Review Comments *</label>
                                <textarea name="review_comments" class="form-control" rows="6" required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Review Decision *</label>
                                <select name="review_status" class="form-control form-select" required>
                                    <option value="reviewed">Approve with Comments</option>
                                    <option value="approved">Approve</option>
                                    <option value="returned">Return for Revision</option>
                                </select>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check-circle"></i> Submit Review
                                </button>
                                <a href="reports.php" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($current_section): ?>
                <!-- Edit Assigned Section View -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-edit"></i> Complete Assigned Section
                        </h3>
                        <div>
                            <span class="status-badge status-<?php echo $current_section['status']; ?>">
                                <?php echo ucfirst($current_section['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            This section is part of "<?php echo htmlspecialchars($current_section['report_title']); ?>" assigned by <?php echo htmlspecialchars($current_section['assigner_name']); ?>.
                        </div>
                        
                        <form method="POST" action="reports.php">
                            <input type="hidden" name="action" value="save_section">
                            <input type="hidden" name="section_id" value="<?php echo $current_section['id']; ?>">
                            
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars($current_section['section_title']); ?></label>
                                <textarea name="content" class="form-control" rows="10" required><?php echo htmlspecialchars($current_section['content']); ?></textarea>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save & Complete Section
                                </button>
                                <a href="reports.php" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Main Reports Interface -->
                <div class="tabs">
                    <button class="tab active" data-tab="create">Create New Report</button>
                    <button class="tab" data-tab="submitted">Submitted Reports (<?php echo count($submitted_reports); ?>)</button>
                    <button class="tab" data-tab="reviews">Reviews (<?php echo count($reports_for_review); ?>)</button>
                    <button class="tab" data-tab="assignments">My Assignments (<?php echo count($assigned_sections); ?>)</button>
                </div>

                <!-- Create Report Tab -->
                <div class="tab-content active" id="create-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Create New Advisory Report</h3>
                            <div>
                                <button type="button" class="btn btn-outline btn-sm" onclick="showTemplateInfo()">
                                    <i class="fas fa-info-circle"></i> Template Guide
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($templates)): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> No report templates available. Please contact the system administrator.
                                </div>
                            <?php else: ?>
                                <!-- Template Categories -->
                                <?php foreach ($templates_by_category as $category => $category_templates): ?>
                                    <div class="template-category">
                                        <div class="category-header">
                                            <div class="category-icon <?php echo $category; ?>">
                                                <i class="fas 
                                                    <?php 
                                                    echo match($category) {
                                                        'arbitration' => 'fa-gavel',
                                                        'case_review' => 'fa-search',
                                                        'hearing' => 'fa-calendar-alt',
                                                        'settlement' => 'fa-handshake',
                                                        'appeal' => 'fa-exclamation-triangle',
                                                        default => 'fa-file-alt'
                                                    };
                                                    ?>
                                                "></i>
                                            </div>
                                            <h4 class="category-title">
                                                <?php 
                                                echo match($category) {
                                                    'arbitration' => 'Arbitration Reports',
                                                    'case_review' => 'Case Review Reports',
                                                    'hearing' => 'Hearing Reports',
                                                    'settlement' => 'Settlement Reports',
                                                    'appeal' => 'Appeal Reports',
                                                    default => 'General Reports'
                                                };
                                                ?>
                                            </h4>
                                            <div class="category-description">
                                                <?php 
                                                echo match($category) {
                                                    'arbitration' => 'Official arbitration documents and rulings',
                                                    'case_review' => 'Case analysis and review documents',
                                                    'hearing' => 'Hearing minutes and proceedings',
                                                    'settlement' => 'Settlement agreements and mediation reports',
                                                    'appeal' => 'Appeal case documents and decisions',
                                                    default => 'General committee reports'
                                                };
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="template-grid">
                                            <?php foreach ($category_templates as $template): ?>
                                                <div class="template-card" data-template-id="<?php echo $template['id']; ?>">
                                                    <div class="template-check">
                                                        <i class="fas fa-check"></i>
                                                    </div>
                                                    <div class="template-icon">
                                                        <i class="fas 
                                                            <?php 
                                                            echo match($template['report_type'] ?? 'monthly') {
                                                                'monthly' => 'fa-calendar-month',
                                                                'quarterly' => 'fa-chart-line',
                                                                'annual' => 'fa-chart-simple',
                                                                'case' => 'fa-gavel',
                                                                'hearing' => 'fa-microphone',
                                                                default => 'fa-file-alt'
                                                            };
                                                            ?>
                                                        "></i>
                                                    </div>
                                                    <h4 class="template-title"><?php echo htmlspecialchars($template['name']); ?></h4>
                                                    <p class="template-description"><?php echo htmlspecialchars($template['description'] ?? 'No description available'); ?></p>
                                                    <div>
                                                        <span class="template-type">
                                                            <?php echo ucfirst($template['report_type'] ?? 'Standard'); ?>
                                                        </span>
                                                        <?php if ($template['requires_legal_review'] ?? false): ?>
                                                            <span class="template-badge legal">Legal Review Required</span>
                                                        <?php endif; ?>
                                                        <?php if ($template['is_confidential'] ?? false): ?>
                                                            <span class="template-badge procedural">Confidential</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Team Options -->
                                <div class="team-options">
                                    <h4><i class="fas fa-users"></i> Arbitration Committee</h4>
                                    <p>Your committee consists of <?php echo count($team_members); ?> members. Collaborate on team reports for better outcomes.</p>
                                    
                                    <div class="team-member-grid">
                                        <?php foreach ($team_members as $member): ?>
                                            <div class="team-member-card">
                                                <div class="team-member-avatar">
                                                    <?php if (!empty($member['avatar_url'])): ?>
                                                        <img src="../<?php echo htmlspecialchars($member['avatar_url']); ?>" alt="Avatar">
                                                    <?php else: ?>
                                                        <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="team-member-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                                <div class="team-member-role"><?php echo htmlspecialchars(str_replace('_', ' ', $member['role'])); ?></div>
                                                <div class="team-member-email" style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($member['email'] ?? ''); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Report Form -->
                                <form id="reportForm" method="POST" enctype="multipart/form-data" style="display: none; margin-top: 2rem;">
                                    <input type="hidden" name="action" value="create_report">
                                    <input type="hidden" name="template_id" id="selectedTemplateId">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Report Title *</label>
                                        <input type="text" class="form-control" name="title" required placeholder="Enter a descriptive title for this report">
                                        <div class="form-text">Choose a clear and descriptive title that reflects the content of this report.</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Report Type</label>
                                        <select class="form-control form-select" name="report_type">
                                            <option value="monthly">Monthly Report</option>
                                            <option value="quarterly">Quarterly Report</option>
                                            <option value="annual">Annual Report</option>
                                            <option value="case">Case Report</option>
                                            <option value="hearing">Hearing Report</option>
                                            <option value="advisory">Advisory Opinion</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Reporting Period (if applicable)</label>
                                        <input type="text" class="form-control" name="report_period" placeholder="e.g., January 2024">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            <input type="checkbox" name="is_team_report" value="1" onchange="toggleTeamOptions()">
                                            Create as Committee Report (Collaborative)
                                        </label>
                                        <div class="form-text">Team reports allow multiple committee members to contribute to different sections.</div>
                                    </div>

                                    <div id="team_options" style="display: none;">
                                        <div class="form-group">
                                            <label class="form-label">Committee Report Type</label>
                                            <select name="team_role" class="form-control form-select">
                                                <option value="combined">Combined Report (All members work together)</option>
                                                <option value="advisor">Advisor Section (You lead, others contribute)</option>
                                            </select>
                                            <div class="form-text">Choose how the committee will collaborate on this report.</div>
                                        </div>
                                    </div>

                                    <div id="templateFields"></div>

                                    <div class="form-group">
                                        <label class="form-label">Supporting Documents</label>
                                        <div class="file-upload" onclick="document.getElementById('fileInput').click()">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <p>Click to upload supporting documents</p>
                                            <p style="font-size: 0.7rem;">PDF, DOC, DOCX, JPG, PNG (Max 10MB)</p>
                                        </div>
                                        <input type="file" id="fileInput" name="report_files[]" multiple style="display: none;" onchange="updateFileList()">
                                        <div id="fileList" style="margin-top: 0.5rem; font-size: 0.75rem;"></div>
                                    </div>

                                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Create & Submit Report
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
                                <button class="card-header-btn" onclick="window.location.reload()">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($submitted_reports)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-alt"></i>
                                    <h3>No reports submitted yet</h3>
                                    <p>Create your first report using the templates above.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Template</th>
                                                <th>Type</th>
                                                <th>Committee</th>
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
                                                    </td>
                                                    <td><?php echo htmlspecialchars($report['template_name'] ?? 'Custom'); ?></td>
                                                    <td>
                                                        <span class="template-type"><?php echo ucfirst($report['report_type']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($report['is_team_report']): ?>
                                                            <span style="color: var(--success);"><i class="fas fa-users"></i> Committee</span>
                                                        <?php else: ?>
                                                            <span style="color: var(--dark-gray);"><i class="fas fa-user"></i> Individual</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $report['status']; ?>">
                                                            <?php echo ucfirst($report['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($report['submitted_at'])); ?></td>
                                                    <td>
                                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                            <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export CSV">
                                                                <i class="fas fa-file-csv"></i>
                                                            </a>
                                                            <?php if ($report['status'] === 'draft'): ?>
                                                                <a href="reports.php?action=edit&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Edit">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <button onclick="viewReport(<?php echo $report['id']; ?>)" class="btn btn-outline btn-sm" title="View">
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

                <!-- Reviews Tab -->
                <div class="tab-content" id="reviews-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Reports for Review</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($reports_for_review)): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> No reports pending review. All caught up!
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Report Title</th>
                                                <th>Author</th>
                                                <th>Type</th>
                                                <th>Submitted</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reports_for_review as $report): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($report['title']); ?></strong>
                                                     </td>
                                                    <td><?php echo htmlspecialchars($report['author_name']); ?></td>
                                                    <td>
                                                        <span class="template-type"><?php echo ucfirst($report['report_type']); ?></span>
                                                     </td>
                                                    <td><?php echo date('M j, Y', strtotime($report['submitted_at'])); ?></td>
                                                    <td>
                                                        <a href="reports.php?action=review&id=<?php echo $report['id']; ?>" class="btn btn-warning btn-sm">
                                                            <i class="fas fa-search"></i> Review Report
                                                        </a>
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

                <!-- Assignments Tab -->
                <div class="tab-content" id="assignments-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>My Assigned Report Sections</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($assigned_sections)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> No pending report assignments. You're all caught up!
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Report Title</th>
                                                <th>Section Title</th>
                                                <th>Assigned By</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assigned_sections as $section): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($section['report_title']); ?></strong>
                                                     </td>
                                                    <td><?php echo htmlspecialchars($section['section_title']); ?></td>
                                                    <td><?php echo htmlspecialchars($section['assigner_name']); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $section['status']; ?>">
                                                            <?php echo ucfirst($section['status']); ?>
                                                        </span>
                                                     </td>
                                                    <td>
                                                        <a href="reports.php?action=edit_section&id=<?php echo $section['id']; ?>" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-edit"></i> Complete Section
                                                        </a>
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
            <?php endif; ?>
        </main>
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

        function selectTemplate(templateId) {
            document.querySelectorAll('.template-card').forEach(card => {
                card.classList.remove('selected');
            });
            const selectedCard = document.querySelector(`[data-template-id="${templateId}"]`);
            if (selectedCard) {
                selectedCard.classList.add('selected');
            }
            
            document.getElementById('reportForm').style.display = 'block';
            document.getElementById('selectedTemplateId').value = templateId;
            
            document.getElementById('reportForm').scrollIntoView({ behavior: 'smooth' });
            loadTemplateFields(templateId);
        }

        function toggleTeamOptions() {
            const teamOptions = document.getElementById('team_options');
            const isTeamReport = document.querySelector('input[name="is_team_report"]').checked;
            teamOptions.style.display = isTeamReport ? 'block' : 'none';
        }

        async function loadTemplateFields(templateId) {
            try {
                const response = await fetch(`../api/get_template_fields.php?template_id=${templateId}`);
                const data = await response.json();
                
                const fieldsContainer = document.getElementById('templateFields');
                fieldsContainer.innerHTML = '';
                
                if (data.fields && data.fields.sections) {
                    data.fields.sections.forEach(section => {
                        const fieldName = section.title.toLowerCase().replace(/ /g, '_');
                        const fieldId = `field_${fieldName}`;
                        
                        const fieldGroup = document.createElement('div');
                        fieldGroup.className = 'form-group';
                        
                        let fieldHtml = `
                            <label class="form-label">${section.title} ${section.required ? '*' : ''}</label>
                        `;
                        
                        if (section.type === 'textarea' || section.type === 'richtext') {
                            fieldHtml += `
                                <textarea class="form-control" id="${fieldId}" name="${fieldName}" 
                                          ${section.required ? 'required' : ''} rows="6" placeholder="Enter ${section.title.toLowerCase()}..."></textarea>
                            `;
                        } else if (section.type === 'date') {
                            fieldHtml += `
                                <input type="date" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''}>
                            `;
                        } else {
                            fieldHtml += `
                                <input type="text" class="form-control" id="${fieldId}" name="${fieldName}" 
                                       ${section.required ? 'required' : ''} placeholder="Enter ${section.title.toLowerCase()}">
                            `;
                        }
                        
                        if (section.description) {
                            fieldHtml += `<div class="form-text">${section.description}</div>`;
                        }
                        
                        fieldGroup.innerHTML = fieldHtml;
                        fieldsContainer.appendChild(fieldGroup);
                    });
                }
            } catch (error) {
                console.error('Error loading template fields:', error);
                document.getElementById('templateFields').innerHTML = '<div class="alert alert-danger">Error loading template fields. Please try again.</div>';
            }
        }

        function resetForm() {
            document.querySelectorAll('.template-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.getElementById('reportForm').style.display = 'none';
            document.getElementById('reportForm').reset();
            document.getElementById('team_options').style.display = 'none';
            document.getElementById('fileList').innerHTML = '';
        }

        function updateFileList() {
            const fileInput = document.getElementById('fileInput');
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            if (fileInput.files.length > 0) {
                for (let i = 0; i < fileInput.files.length; i++) {
                    const file = fileInput.files[i];
                    const fileItem = document.createElement('div');
                    fileItem.style.display = 'flex';
                    fileItem.style.alignItems = 'center';
                    fileItem.style.gap = '0.5rem';
                    fileItem.style.padding = '0.25rem 0';
                    fileItem.innerHTML = `
                        <i class="fas fa-file" style="color: var(--dark-gray);"></i>
                        <span style="flex: 1;">${file.name}</span>
                        <span style="font-size: 0.7rem; color: var(--dark-gray);">${(file.size / 1024).toFixed(1)} KB</span>
                        <button type="button" onclick="removeFile(${i})" style="background: none; border: none; color: var(--danger); cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    fileList.appendChild(fileItem);
                }
            }
        }

        function removeFile(index) {
            const fileInput = document.getElementById('fileInput');
            const dt = new DataTransfer();
            for (let i = 0; i < fileInput.files.length; i++) {
                if (i !== index) {
                    dt.items.add(fileInput.files[i]);
                }
            }
            fileInput.files = dt.files;
            updateFileList();
        }

        function showTemplateInfo() {
            alert("Report Templates Guide:\n\n" +
                  "• Arbitration Reports: Official arbitration documents and rulings\n" +
                  "• Case Review Reports: Case analysis and review documents\n" +
                  "• Hearing Reports: Hearing minutes and proceedings\n" +
                  "• Settlement Reports: Settlement agreements and mediation reports\n" +
                  "• Appeal Reports: Appeal case documents and decisions\n\n" +
                  "Select a template to get started. Each template has specific fields tailored to the report type.");
        }

        function viewReport(reportId) {
            window.open(`view_report.php?id=${reportId}`, '_blank', 'width=800,height=600');
        }
    </script>
</body>
</html>