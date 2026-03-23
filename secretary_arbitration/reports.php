<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_arbitration') {
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

// Get available templates for Secretary Arbitration
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE role_specific = 'secretary_arbitration' OR role_specific IS NULL
        AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $templates = [];
    error_log("Templates query error: " . $e->getMessage());
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

// Get submitted reports (both individual and team reports)
try {
    $stmt = $pdo->prepare("
        SELECT r.*, rt.name as template_name, rt.report_type,
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
            foreach ($template_fields['sections'] as $section) {
                $field_name = strtolower(str_replace(' ', '_', $section['title']));
                $content_data[$field_name] = $_POST[$field_name] ?? '';
            }
            
            try {
                if ($is_team_report) {
                    // Start transaction for team report
                    $pdo->beginTransaction();
                    
                    // Create main team report
                    $stmt = $pdo->prepare("
                        INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, is_team_report, team_role, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 1, ?, NOW())
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
                    
                    // Create report sections for team members if not combined report
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
                        
                        // Update assigned sections
                        $stmt = $pdo->prepare("UPDATE reports SET assigned_sections = ? WHERE id = ?");
                        $stmt->execute([json_encode($sections), $report_id]);
                    }
                    
                    $pdo->commit();
                    $_SESSION['success_message'] = "Team report created successfully! Arbitration committee members can now collaborate.";
                    
                } else {
                    // Create individual report
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
                    $_SESSION['success_message'] = "Individual report submitted successfully!";
                }
                
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
            $stmt = $pdo->prepare("UPDATE reports SET status = 'submitted', submitted_at = NOW() WHERE id = ?");
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
            $stmt = $pdo->prepare("UPDATE report_sections SET content = ?, status = 'completed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$content, $section_id]);
            $_SESSION['success_message'] = "Section saved successfully!";
            header("Location: reports.php?action=edit_section&id=" . $section_id);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error saving section: " . $e->getMessage();
        }
    }
}

// Handle export request
if (isset($_GET['export']) && isset($_GET['id'])) {
    $report_id = (int)$_GET['id'];
    
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
            // Get team sections if team report
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
            
            // Generate CSV export
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="arbitration_report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV header
            fputcsv($output, ['Arbitration Committee Report - ' . $report['title']]);
            fputcsv($output, []); // Empty row
            fputcsv($output, ['Template:', $report['template_name']]);
            fputcsv($output, ['Author:', $report['author_name'] . ' (' . $report['author_role'] . ')']);
            fputcsv($output, ['Report Type:', $report['report_type']]);
            fputcsv($output, ['Team Report:', $report['is_team_report'] ? 'Yes' : 'No']);
            fputcsv($output, ['Status:', $report['status']]);
            fputcsv($output, ['Submitted:', $report['submitted_at']]);
            fputcsv($output, []); // Empty row
            
            // Main report content
            $content = json_decode($report['content'], true);
            foreach ($content as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                fputcsv($output, [$label . ':', $value]);
            }
            
            // Team sections
            if (!empty($team_sections)) {
                fputcsv($output, []); // Empty row
                fputcsv($output, ['ARBITRATION COMMITTEE SECTIONS']);
                foreach ($team_sections as $section) {
                    fputcsv($output, []); // Empty row
                    fputcsv($output, [$section['section_title'] . ' - ' . $section['assigned_name']]);
                    fputcsv($output, ['Content:', $section['content']]);
                    fputcsv($output, ['Status:', $section['status']]);
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

// Insert secretary specific templates if they don't exist
try {
    $secretary_templates = [
        [
            'name' => 'Monthly Secretary Arbitration Report',
            'description' => 'Monthly report covering documentation, case records, meeting minutes, and administrative activities',
            'role_specific' => 'secretary_arbitration',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'textarea',
                        'title' => 'Documentation Summary',
                        'required' => true,
                        'description' => 'Overview of all documents filed, organized, and maintained this month'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Case Records Management',
                        'required' => true,
                        'description' => 'Details of case files created, updated, and archived'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Meeting Minutes',
                        'required' => true,
                        'description' => 'Summary of all arbitration committee meetings and minutes recorded'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Hearing Documentation',
                        'required' => true,
                        'description' => 'Documentation of hearings including notices, attendance, and proceedings'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Communication Records',
                        'required' => true,
                        'description' => 'Records of all official communications sent and received'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Administrative Challenges',
                        'required' => false,
                        'description' => 'Challenges faced in documentation and administrative tasks'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Next Month Documentation Plan',
                        'required' => true,
                        'description' => 'Planned documentation and administrative activities for next month'
                    ]
                ]
            ])
        ],
        [
            'name' => 'Case Documentation Report',
            'description' => 'Detailed report on specific case documentation and record keeping',
            'role_specific' => 'secretary_arbitration',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'text',
                        'title' => 'Case Number',
                        'required' => true,
                        'description' => 'Official case reference number'
                    ],
                    [
                        'type' => 'text',
                        'title' => 'Case Title',
                        'required' => true,
                        'description' => 'Title of the arbitration case'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Documents Filed',
                        'required' => true,
                        'description' => 'List all documents filed for this case'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Document Organization',
                        'required' => true,
                        'description' => 'How documents are organized and stored'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Access and Retrieval',
                        'required' => true,
                        'description' => 'Information about document access and retrieval systems'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Confidentiality Measures',
                        'required' => true,
                        'description' => 'Measures taken to ensure document confidentiality'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Documentation Challenges',
                        'required' => false,
                        'description' => 'Any challenges in documentation process'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Recommendations',
                        'required' => true,
                        'description' => 'Recommendations for improved documentation'
                    ]
                ]
            ])
        ],
        [
            'name' => 'Meeting Minutes Report',
            'description' => 'Report on arbitration committee meetings and minutes',
            'role_specific' => 'secretary_arbitration',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'text',
                        'title' => 'Meeting Type',
                        'required' => true,
                        'description' => 'Type of meeting (regular, emergency, special)'
                    ],
                    [
                        'type' => 'date',
                        'title' => 'Meeting Date',
                        'required' => true,
                        'description' => 'Date when meeting was held'
                    ],
                    [
                        'type' => 'text',
                        'title' => 'Meeting Time',
                        'required' => true,
                        'description' => 'Start and end time of meeting'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Attendance',
                        'required' => true,
                        'description' => 'List of attendees and their roles'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Agenda Items',
                        'required' => true,
                        'description' => 'All items discussed in the meeting'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Decisions Made',
                        'required' => true,
                        'description' => 'Decisions and resolutions from the meeting'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Action Items',
                        'required' => true,
                        'description' => 'Action items assigned with deadlines'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Next Meeting',
                        'required' => true,
                        'description' => 'Date and agenda for next meeting'
                    ]
                ]
            ])
        ]
    ];

    foreach ($secretary_templates as $template_data) {
        $check_stmt = $pdo->prepare("SELECT id FROM report_templates WHERE name = ? AND role_specific = 'secretary_arbitration'");
        $check_stmt->execute([$template_data['name']]);
        
        if (!$check_stmt->fetch()) {
            $insert_stmt = $pdo->prepare("
                INSERT INTO report_templates (name, description, role_specific, report_type, fields, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
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
        WHERE role_specific = 'secretary_arbitration' OR role_specific IS NULL
        AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Secretary templates setup error: " . $e->getMessage());
}

// Get specific report for editing if requested
$current_report = null;
$report_sections = [];
$current_section = null;

if (isset($_GET['action']) && isset($_GET['id'])) {
    if ($_GET['action'] === 'edit' && isset($_GET['id'])) {
        // Edit entire report
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
                // Get report sections for team report
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
        // Edit specific section assigned to secretary
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
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arbitration Secretary Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Use the same CSS as the arbitration dashboard */
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
            color: var(--primary-blue);
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
            background: var(--primary-blue);
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
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
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
            border-left: 3px solid var(--primary-blue);
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

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
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
            grid-template-columns: 2fr 1fr;
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

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
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
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Tables */
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
            background: #cce7ff;
            color: var(--info);
        }

        .status-submitted {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-reviewed {
            background: #d4edda;
            color: var(--success);
        }

        .status-approved {
            background: #d1f2eb;
            color: var(--primary-blue);
        }

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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

        .team-options {
            background: var(--light-blue);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin: 1rem 0;
            border-left: 4px solid var(--primary-blue);
        }

        .team-member-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin: 0 auto 0.5rem;
            font-size: 1.25rem;
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
        }

        .team-member-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
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

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
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

        .team-section-editor {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border: 1px solid var(--medium-gray);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .section-assignee {
            font-size: 0.9rem;
            color: var(--dark-gray);
        }

        .section-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-weight: 600;
        }

        .section-status.draft {
            background: #cce7ff;
            color: var(--info);
        }

        .section-status.completed {
            background: #d4edda;
            color: var(--success);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .text-muted {
            color: var(--dark-gray) !important;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .template-grid {
                grid-template-columns: 1fr;
            }
            
            .team-member-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                text-align: left;
                border-bottom: none;
                border-left: 2px solid transparent;
            }
            
            .tab.active {
                border-left-color: var(--primary-blue);
                border-bottom-color: transparent;
            }
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
                    <h1>Isonga - Arbitration Reports</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
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
                        <div class="user-role">Secretary - Arbitration</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i>
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
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php">
                        <i class="fas fa-sticky-note"></i>
                        <span>Case Notes</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
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
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Arbitration Secretary Reports 📋</h1>
                    <p>Create documentation reports and collaborate with the arbitration committee on comprehensive reporting</p>
                </div>
            </div>

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
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo count($assigned_sections); ?></div>
                        <div class="stat-label">Assigned Sections</div>
                    </div>
                </div>
            </div>

            <?php if ($current_section): ?>
                <!-- Edit Assigned Section View -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-edit"></i> Complete Assigned Section
                            <span style="color: var(--info); font-size: 0.9rem; margin-left: 1rem;">
                                <i class="fas fa-users"></i> Committee Report Section
                            </span>
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
                            <strong>Section Assignment:</strong> This section is part of the report "<?php echo htmlspecialchars($current_section['report_title']); ?>" assigned by <?php echo htmlspecialchars($current_section['assigner_name']); ?>.
                        </div>
                        
                        <div class="team-section-editor">
                            <div class="section-header">
                                <div>
                                    <h4><?php echo htmlspecialchars($current_section['section_title']); ?></h4>
                                    <div class="section-assignee">
                                        Report Status: <span class="status-badge status-<?php echo $current_section['report_status']; ?>">
                                            <?php echo ucfirst($current_section['report_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="section-status <?php echo $current_section['status']; ?>">
                                    <?php echo ucfirst($current_section['status']); ?>
                                </div>
                            </div>
                            
                            <form method="POST" action="reports.php">
                                <input type="hidden" name="action" value="save_section">
                                <input type="hidden" name="section_id" value="<?php echo $current_section['id']; ?>">
                                <div class="form-group">
                                    <label class="form-label">Section Content *</label>
                                    <textarea name="content" class="form-control" rows="10" placeholder="Enter your section content here..." required><?php echo htmlspecialchars($current_section['content']); ?></textarea>
                                    <div class="form-text">
                                        This content will be included in the committee report. Please provide comprehensive information about your assigned documentation area.
                                    </div>
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
                </div>

            <?php elseif (isset($_GET['action']) && $_GET['action'] === 'edit' && $current_report): ?>
                <!-- Edit Report View -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            Editing: <?php echo htmlspecialchars($current_report['title']); ?>
                            <?php if ($current_report['is_team_report']): ?>
                                <span style="color: var(--info); font-size: 0.9rem; margin-left: 1rem;">
                                    <i class="fas fa-users"></i> Committee Report
                                </span>
                            <?php endif; ?>
                        </h3>
                        <div>
                            <span class="status-badge status-<?php echo $current_report['status']; ?>">
                                <?php echo ucfirst($current_report['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($current_report['is_team_report'] && !empty($report_sections)): ?>
                            <!-- Committee Report Editor -->
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Committee Collaboration:</strong> This is a collaborative report. Different sections are assigned to arbitration committee members.
                            </div>
                            
                            <!-- Main Report Content -->
                            <div class="team-section-editor">
                                <div class="section-header">
                                    <h4>Main Report Content</h4>
                                    <div class="section-status <?php echo $current_report['status']; ?>">
                                        <?php echo ucfirst($current_report['status']); ?>
                                    </div>
                                </div>
                                
                                <?php
                                $content = json_decode($current_report['content'], true) ?: [];
                                $template_fields = json_decode($current_report['template_fields'] ?? '{"sections": []}', true);
                                
                                if (!empty($template_fields['sections'])) {
                                    foreach ($template_fields['sections'] as $section) {
                                        $field_name = strtolower(str_replace(' ', '_', $section['title']));
                                        $section_value = $content[$field_name] ?? '';
                                        ?>
                                        <div class="form-group">
                                            <label class="form-label"><?php echo htmlspecialchars($section['title']); ?> <?php echo $section['required'] ? '*' : ''; ?></label>
                                            <?php if (!empty($section['description'])): ?>
                                                <div class="form-text"><?php echo htmlspecialchars($section['description']); ?></div>
                                            <?php endif; ?>
                                            
                                            <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">
                                                <?php echo htmlspecialchars($section_value); ?>
                                            </div>
                                        </div>
                                        <?php
                                    }
                                }
                                ?>
                            </div>
                            
                            <!-- Committee Sections -->
                            <h4 style="margin: 2rem 0 1rem 0;">Arbitration Committee Sections</h4>
                            <?php foreach ($report_sections as $section): ?>
                                <div class="team-section-editor">
                                    <div class="section-header">
                                        <div>
                                            <h5><?php echo htmlspecialchars($section['section_title']); ?></h5>
                                            <div class="section-assignee">
                                                Assigned to: <?php echo htmlspecialchars($section['assigned_name']); ?> (<?php echo htmlspecialchars($section['assigned_role']); ?>)
                                            </div>
                                        </div>
                                        <div class="section-status <?php echo $section['status']; ?>">
                                            <?php echo ucfirst($section['status']); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($section['assigned_to'] == $user_id): ?>
                                        <form method="POST" action="reports.php">
                                            <input type="hidden" name="action" value="save_section">
                                            <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                                            <input type="hidden" name="report_id" value="<?php echo $current_report['id']; ?>">
                                            <div class="form-group">
                                                <textarea name="content" class="form-control" rows="6" placeholder="Enter your section content here..."><?php echo htmlspecialchars($section['content']); ?></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="fas fa-save"></i> Save Section
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div style="background: var(--white); padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--medium-gray); min-height: 100px;">
                                            <?php if (!empty($section['content'])): ?>
                                                <?php echo nl2br(htmlspecialchars($section['content'])); ?>
                                            <?php else: ?>
                                                <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                                                    <i class="fas fa-clock"></i><br>
                                                    Waiting for <?php echo htmlspecialchars($section['assigned_name']); ?> to add content
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                        <?php else: ?>
                            <!-- Individual Report Editor -->
                            <form method="POST" action="reports.php">
                                <input type="hidden" name="action" value="create_report">
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
                                        $field_name = strtolower(str_replace(' ', '_', $section['title']));
                                        $section_value = $content[$field_name] ?? '';
                                        ?>
                                        <div class="form-group">
                                            <label class="form-label"><?php echo htmlspecialchars($section['title']); ?> <?php echo $section['required'] ? '*' : ''; ?></label>
                                            <?php if (!empty($section['description'])): ?>
                                                <div class="form-text"><?php echo htmlspecialchars($section['description']); ?></div>
                                            <?php endif; ?>
                                            
                                            <?php if ($section['type'] === 'textarea' || $section['type'] === 'richtext'): ?>
                                                <textarea name="<?php echo $field_name; ?>" class="form-control" rows="6" <?php echo $section['required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($section_value); ?></textarea>
                                            <?php elseif ($section['type'] === 'text'): ?>
                                                <input type="text" name="<?php echo $field_name; ?>" class="form-control" value="<?php echo htmlspecialchars($section_value); ?>" <?php echo $section['required'] ? 'required' : ''; ?>>
                                            <?php elseif ($section['type'] === 'date'): ?>
                                                <input type="date" name="<?php echo $field_name; ?>" class="form-control" value="<?php echo htmlspecialchars($section_value); ?>" <?php echo $section['required'] ? 'required' : ''; ?>>
                                            <?php elseif ($section['type'] === 'number'): ?>
                                                <input type="number" name="<?php echo $field_name; ?>" class="form-control" value="<?php echo htmlspecialchars($section_value); ?>" <?php echo $section['required'] ? 'required' : ''; ?>>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                    }
                                }
                                ?>
                                
                                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Report
                                    </button>
                                    <a href="reports.php" class="btn btn-outline">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- Main Reports Interface -->
                <div class="tabs">
                    <button class="tab active" data-tab="create">Create New Report</button>
                    <button class="tab" data-tab="submitted">Submitted Reports (<?php echo count($submitted_reports); ?>)</button>
                    <button class="tab" data-tab="assignments">My Assignments (<?php echo count($assigned_sections); ?>)</button>
                    <button class="tab" data-tab="team">Committee Collaboration</button>
                </div>

                <!-- Create Report Tab -->
                <div class="tab-content active" id="create-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Create New Report</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($templates)): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> No report templates available at the moment.
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
                                                         ($template['report_type'] === 'monthly' ? 'calendar-alt' : 'clipboard-list'); 
                                                ?>"></i>
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
                                            <option value="monthly">Monthly Report</option>
                                            <option value="activity">Activity Report</option>
                                            <option value="team">Committee Report</option>
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

                                    <div class="form-group">
                                        <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
                                            <input type="checkbox" name="is_team_report" id="is_team_report" value="1" onchange="toggleTeamOptions()">
                                            Create as Committee Report (Collaborative)
                                        </label>
                                        <small style="color: var(--dark-gray); font-size: 0.75rem;">
                                            Committee reports allow collaborative work with other Arbitration Committee members
                                        </small>
                                    </div>

                                    <div id="team_options" style="display: none;">
                                        <div class="form-group">
                                            <label class="form-label">Committee Report Type</label>
                                            <select name="team_role" class="form-control form-select">
                                                <option value="combined">Combined Report (All members work together)</option>
                                                <option value="secretary">Secretary Documentation Section</option>
                                                <option value="president">President Section</option>
                                                <option value="vice_president">Vice President Section</option>
                                                <option value="advisor">Advisor Section</option>
                                            </select>
                                            <small style="color: var(--dark-gray); font-size: 0.75rem;">
                                                Choose how to divide the report among committee members
                                            </small>
                                        </div>

                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i>
                                            <strong>Committee Report Information:</strong> This will create a collaborative report where different sections can be assigned to different Arbitration Committee members.
                                        </div>
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
                                            <input type="file" class="file-input" id="report_files" name="report_files[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)" style="display: none;">
                                        </div>
                                        <div class="file-list" id="fileList"></div>
                                    </div>

                                    <div class="form-group" style="margin-top: 2rem;">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Create Report
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
                                                        <?php if ($report['report_period']): ?>
                                                            <br><small class="text-muted"><?php echo date('F Y', strtotime($report['report_period'])); ?></small>
                                                        <?php endif; ?>
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
                                                        <?php if ($report['reviewer_name']): ?>
                                                            <br><small>by <?php echo htmlspecialchars($report['reviewer_name']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($report['submitted_at'])); ?></td>
                                                    <td>
                                                        <div style="display: flex; gap: 0.25rem;">
                                                            <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php if ($report['status'] === 'draft' || $report['is_team_report']): ?>
                                                                <a href="reports.php?action=edit&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Edit">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                            <?php endif; ?>
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

                <!-- Assignments Tab -->
                <div class="tab-content" id="assignments-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>My Assigned Report Sections</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($assigned_sections)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> You don't have any pending report assignments at the moment.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Report Title</th>
                                                <th>Section Title</th>
                                                <th>Assigned By</th>
                                                <th>Report Status</th>
                                                <th>Section Status</th>
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
                                                    <td><?php echo htmlspecialchars($section['assigner_name']); ?> (<?php echo htmlspecialchars(str_replace('_', ' ', $section['assigner_role'])); ?>)</td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $section['report_status']; ?>">
                                                            <?php echo ucfirst($section['report_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $section['status']; ?>">
                                                            <?php echo ucfirst($section['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 0.25rem;">
                                                            <a href="reports.php?action=edit_section&id=<?php echo $section['id']; ?>" class="btn btn-primary btn-sm">
                                                                <i class="fas fa-edit"></i> Complete Section
                                                            </a>
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

                <!-- Committee Collaboration Tab -->
                <div class="tab-content" id="team-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Committee Collaboration</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Secretary Role:</strong> As Secretary of the Arbitration Committee, you play a crucial role in documentation, record keeping, and supporting committee activities.
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1.5rem;">
                                <div style="background: var(--light-blue); padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid var(--primary-blue);">
                                    <h4><i class="fas fa-user-tie"></i> President Role</h4>
                                    <p>Overall coordination, case oversight, and final report compilation.</p>
                                </div>
                                
                                <div style="background: #e8f5e8; padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid var(--success);">
                                    <h4><i class="fas fa-user-check"></i> Vice President Role</h4>
                                    <p>Case management, hearing coordination, and dispute resolution support.</p>
                                </div>
                                
                                <div style="background: #fff3cd; padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid var(--warning);">
                                    <h4><i class="fas fa-user-graduate"></i> Advisor Role</h4>
                                    <p>Legal guidance, policy compliance, and complex case consultation.</p>
                                </div>

                                <div style="background: #f0e6ff; padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid #6f42c1;">
                                    <h4><i class="fas fa-clipboard-list"></i> Your Role (Secretary)</h4>
                                    <p>Documentation, record keeping, meeting minutes, and administrative support.</p>
                                </div>
                            </div>
                            
                            <div style="margin-top: 2rem;">
                                <h4>Your Responsibilities in Committee Reporting</h4>
                                <ul style="margin-left: 1.5rem; color: var(--text-dark);">
                                    <li>Maintain accurate documentation for all reports</li>
                                    <li>Record and organize meeting minutes</li>
                                    <li>Manage case documentation and records</li>
                                    <li>Collaborate on committee reports with other members</li>
                                    <li>Complete assigned documentation sections in collaborative reports</li>
                                    <li>Ensure proper filing and archiving of all reports</li>
                                    <li>Prepare administrative sections of committee reports</li>
                                </ul>
                            </div>
                            
                            <div style="margin-top: 2rem;">
                                <h4>Documentation Standards</h4>
                                <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius);">
                                    <p><strong>File Naming Convention:</strong> Case_Report_YYYYMMDD_MemberName</p>
                                    <p><strong>Meeting Minutes Format:</strong> Date, Time, Attendance, Agenda, Decisions, Action Items</p>
                                    <p><strong>Document Categories:</strong> Case Files, Meeting Minutes, Hearing Records, Communications</p>
                                    <p><strong>Confidentiality Level:</strong> Public, Committee Only, Confidential, Highly Confidential</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Report View Modal -->
    <div id="reportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--white); border-radius: var(--border-radius); width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
            <div style="padding: 1.5rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: between; align-items: center;">
                <h3 id="modalTitle">Report Details</h3>
                <button onclick="closeModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--dark-gray); cursor: pointer;">&times;</button>
            </div>
            <div style="padding: 1.5rem;" id="modalContent">
                <!-- Content will be loaded here -->
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

        // Toggle team options
        function toggleTeamOptions() {
            const teamOptions = document.getElementById('team_options');
            const isTeamReport = document.getElementById('is_team_report').checked;
            teamOptions.style.display = isTeamReport ? 'block' : 'none';
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
                        const fieldName = section.title.toLowerCase().replace(/ /g, '_');
                        const fieldId = `field_${fieldName}`;
                        
                        const fieldGroup = document.createElement('div');
                        fieldGroup.className = 'form-group';
                        
                        let fieldHtml = `
                            <label class="form-label" for="${fieldId}">${section.title} ${section.required ? '*' : ''}</label>
                        `;
                        
                        if (section.type === 'textarea' || section.type === 'richtext') {
                            fieldHtml += `
                                <textarea class="form-control" id="${fieldId}" name="${fieldName}" 
                                          ${section.required ? 'required' : ''} 
                                          placeholder="${section.description || ''}"
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
            document.getElementById('team_options').style.display = 'none';
            document.getElementById('is_team_report').checked = false;
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
                        <label class="form-label">Committee Report</label>
                        <div>${report.is_team_report ? 'Yes' : 'No'}</div>
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
                    const reportContent = JSON.parse(report.content);
                    Object.keys(reportContent).forEach(key => {
                        if (reportContent[key]) {
                            const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                            content += `
                                <div class="form-group">
                                    <label class="form-label">${label}</label>
                                    <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); white-space: pre-wrap;">${reportContent[key]}</div>
                                </div>
                            `;
                        }
                    });
                }
                
                document.getElementById('modalContent').innerHTML = content;
                document.getElementById('reportModal').style.display = 'flex';
            } catch (error) {
                console.error('Error loading report:', error);
                alert('Error loading report details');
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
    </script>
</body>
</html>