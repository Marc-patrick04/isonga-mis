<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_representative_board') {
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

// Get available templates for Secretary (PostgreSQL compatible - uses true for boolean)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'secretary_representative_board' OR role_specific IS NULL)
        AND is_active = true
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
        WHERE cm.role IN ('president_representative_board', 'vice_president_representative_board', 'secretary_representative_board')
        AND cm.status = 'active'
        ORDER BY 
            CASE cm.role 
                WHEN 'president_representative_board' THEN 1
                WHEN 'vice_president_representative_board' THEN 2
                WHEN 'secretary_representative_board' THEN 3
                ELSE 4
            END
    ");
    $stmt->execute();
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $team_members = [];
    error_log("Team members query error: " . $e->getMessage());
}

// Get submitted reports (both individual and team reports) (PostgreSQL compatible)
try {
    $stmt = $pdo->prepare("
        SELECT r.*, rt.name as template_name, rt.report_type,
               u.full_name as reviewer_name,
               cm.name as team_lead_name
        FROM reports r 
        LEFT JOIN report_templates rt ON r.template_id = rt.id
        LEFT JOIN users u ON r.reviewed_by = u.id
        LEFT JOIN committee_members cm ON r.user_id = cm.id
        WHERE r.user_id = ? OR (r.is_team_report = true AND r.team_role = 'combined' AND r.user_id IN (
            SELECT id FROM committee_members 
            WHERE role IN ('president_representative_board', 'vice_president_representative_board', 'secretary_representative_board')
        ))
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
        $report_type = $_POST['report_type'] ?? 'monthly';
        $report_period = $_POST['report_period'] ?? null;
        $activity_date = $_POST['activity_date'] ?? null;
        $is_team_report = isset($_POST['is_team_report']) ? true : false;
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
                    
                    // Create main team report (PostgreSQL uses CURRENT_TIMESTAMP)
                    $stmt = $pdo->prepare("
                        INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, is_team_report, team_role, submitted_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', true, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
                    $_SESSION['success_message'] = "Team report created successfully! Team members can now collaborate.";
                    
                } else {
                    // Create individual report (PostgreSQL uses CURRENT_TIMESTAMP)
                    $stmt = $pdo->prepare("
                        INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, submitted_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
            $stmt = $pdo->prepare("UPDATE reports SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$report_id]);
            $_SESSION['success_message'] = "Team report submitted successfully!";
            header("Location: reports.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error submitting team report: " . $e->getMessage();
        }
    }
    
    // Handle section save (PostgreSQL uses CURRENT_TIMESTAMP)
    if (isset($_POST['action']) && $_POST['action'] === 'save_section') {
        $section_id = $_POST['section_id'];
        $content = $_POST['content'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE report_sections SET content = ?, status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$content, $section_id]);
            $_SESSION['success_message'] = "Section saved successfully!";
            header("Location: reports.php?action=edit&id=" . $_POST['report_id']);
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
            WHERE r.id = ? AND (r.user_id = ? OR r.is_team_report = true)
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
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="secretary_report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for proper encoding
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV header
            fputcsv($output, ['Secretary Report - ' . $report['title']]);
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
                fputcsv($output, ['TEAM SECTIONS']);
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

// Get report statistics (PostgreSQL compatible)
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_reports,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_reports,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_reports,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_reports,
            SUM(CASE WHEN is_team_report = true THEN 1 ELSE 0 END) as team_reports
        FROM reports 
        WHERE user_id = ? OR (is_team_report = true AND team_role = 'combined' AND user_id IN (
            SELECT id FROM committee_members 
            WHERE role IN ('president_representative_board', 'vice_president_representative_board', 'secretary_representative_board')
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
    error_log("Report stats error: " . $e->getMessage());
}

// Insert secretary specific templates if they don't exist (PostgreSQL compatible)
try {
    // First check if templates table exists
    $stmt = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = 'report_templates'
        ) as table_exists
    ");
    $table_exists = $stmt->fetch(PDO::FETCH_ASSOC)['table_exists'] ?? false;
    
    if ($table_exists) {
        $secretary_templates = [
            [
                'name' => 'Monthly Secretary Report',
                'description' => 'Comprehensive monthly report covering documentation, meeting minutes, and administrative activities',
                'role_specific' => 'secretary_representative_board',
                'report_type' => 'monthly',
                'fields' => json_encode([
                    'sections' => [
                        [
                            'type' => 'textarea',
                            'title' => 'Meeting Documentation',
                            'required' => true,
                            'description' => 'Summary of meetings documented and minutes prepared'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Record Keeping',
                            'required' => true,
                            'description' => 'Status of record keeping and document management'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Communication Management',
                            'required' => true,
                            'description' => 'Communication activities with class representatives and executive committee'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Report Compilation',
                            'required' => true,
                            'description' => 'Reports compiled and distributed to relevant stakeholders'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Administrative Support',
                            'required' => true,
                            'description' => 'Administrative support provided to the representative board'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Documentation Challenges',
                            'required' => false,
                            'description' => 'Challenges faced in documentation and record keeping'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Next Month Documentation Plans',
                            'required' => true,
                            'description' => 'Planned documentation activities for the coming month'
                        ]
                    ]
                ])
            ],
            [
                'name' => 'Meeting Minutes Summary Report',
                'description' => 'Report summarizing meeting minutes and key decisions documented',
                'role_specific' => 'secretary_representative_board',
                'report_type' => 'activity',
                'fields' => json_encode([
                    'sections' => [
                        [
                            'type' => 'text',
                            'title' => 'Meeting Type',
                            'required' => true,
                            'description' => 'Type of meeting documented (Class Rep, Executive, General, etc.)'
                        ],
                        [
                            'type' => 'date',
                            'title' => 'Meeting Date',
                            'required' => true,
                            'description' => 'Date when the meeting was held'
                        ],
                        [
                            'type' => 'number',
                            'title' => 'Minutes Pages',
                            'required' => true,
                            'description' => 'Number of pages in the meeting minutes'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Key Decisions Documented',
                            'required' => true,
                            'description' => 'Main decisions and resolutions documented'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Action Items Recorded',
                            'required' => true,
                            'description' => 'Action items and responsibilities documented'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Follow-up Documentation',
                            'required' => true,
                            'description' => 'Follow-up documentation and tracking requirements'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Distribution Status',
                            'required' => true,
                            'description' => 'Status of minutes distribution to relevant parties'
                        ]
                    ]
                ])
            ],
            [
                'name' => 'Class Representative Communication Report',
                'description' => 'Report on communication activities with class representatives',
                'role_specific' => 'secretary_representative_board',
                'report_type' => 'monthly',
                'fields' => json_encode([
                    'sections' => [
                        [
                            'type' => 'textarea',
                            'title' => 'Communication Channels Used',
                            'required' => true,
                            'description' => 'Communication channels utilized (email, meetings, messaging, etc.)'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Information Disseminated',
                            'required' => true,
                            'description' => 'Key information shared with class representatives'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Feedback Collection',
                            'required' => true,
                            'description' => 'Feedback collected from class representatives'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Communication Effectiveness',
                            'required' => true,
                            'description' => 'Assessment of communication effectiveness'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Communication Challenges',
                            'required' => false,
                            'description' => 'Challenges faced in communication'
                        ]
                    ]
                ])
            ],
            [
                'name' => 'Document Management Report',
                'description' => 'Report on document management and record keeping activities',
                'role_specific' => 'secretary_representative_board',
                'report_type' => 'monthly',
                'fields' => json_encode([
                    'sections' => [
                        [
                            'type' => 'textarea',
                            'title' => 'Documents Processed',
                            'required' => true,
                            'description' => 'Types and quantities of documents processed'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Record Organization',
                            'required' => true,
                            'description' => 'Status of record organization and categorization'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Archiving Activities',
                            'required' => true,
                            'description' => 'Document archiving and storage activities'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Retrieval Efficiency',
                            'required' => true,
                            'description' => 'Efficiency of document retrieval when needed'
                        ],
                        [
                            'type' => 'textarea',
                            'title' => 'Document Security',
                            'required' => true,
                            'description' => 'Measures taken to ensure document security'
                        ]
                    ]
                ])
            ]
        ];

        foreach ($secretary_templates as $template_data) {
            $check_stmt = $pdo->prepare("SELECT id FROM report_templates WHERE name = ? AND role_specific = 'secretary_representative_board'");
            $check_stmt->execute([$template_data['name']]);
            
            if (!$check_stmt->fetch()) {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO report_templates (name, description, role_specific, report_type, fields, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, true, CURRENT_TIMESTAMP)
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
            WHERE (role_specific = 'secretary_representative_board' OR role_specific IS NULL)
            AND is_active = true
            ORDER BY name
        ");
        $stmt->execute();
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Secretary templates setup error: " . $e->getMessage());
}

// Get specific report for editing if requested
$current_report = null;
$report_sections = [];
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, rt.name as template_name, rt.fields as template_fields
            FROM reports r 
            LEFT JOIN report_templates rt ON r.template_id = rt.id 
            WHERE r.id = ? AND (r.user_id = ? OR r.is_team_report = true)
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
}

// Get dashboard statistics for sidebar (PostgreSQL compatible)
try {
    // Total reps count (PostgreSQL uses true for boolean)
    $stmt = $pdo->query("SELECT COUNT(*) as total_reps FROM users WHERE is_class_rep = true AND status = 'active'");
    $sidebar_reps_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_reps'] ?? 0;
    
    // Check for meeting_minutes table
    $stmt = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = 'meeting_minutes'
        ) as table_exists
    ");
    $meeting_minutes_exists = $stmt->fetch(PDO::FETCH_ASSOC)['table_exists'] ?? false;
    
    $pending_minutes = 0;
    if ($meeting_minutes_exists) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as pending_minutes FROM meeting_minutes WHERE approval_status = 'draft' AND prepared_by = ?");
        $stmt->execute([$user_id]);
        $pending_minutes = $stmt->fetch(PDO::FETCH_ASSOC)['pending_minutes'] ?? 0;
    }
    
    // Check for rep_meetings table
    $stmt = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = 'rep_meetings'
        ) as rep_meetings_exists
    ");
    $rep_meetings_exists = $stmt->fetch(PDO::FETCH_ASSOC)['rep_meetings_exists'] ?? false;
    
    $sidebar_upcoming_meetings = 0;
    if ($rep_meetings_exists) {
        $stmt = $pdo->query("SELECT COUNT(*) as upcoming_meetings FROM rep_meetings WHERE meeting_date >= CURRENT_DATE AND status = 'scheduled'");
        $sidebar_upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'] ?? 0;
    }
    
    // Unread messages (PostgreSQL compatible)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'] ?? 0;
    
} catch (PDOException $e) {
    $sidebar_reps_count = 0;
    $pending_minutes = 0;
    $sidebar_upcoming_meetings = 0;
    $unread_messages = 0;
    error_log("Sidebar stats error: " . $e->getMessage());
}

// Set variables for sidebar display
$total_reps = $sidebar_reps_count;
$upcoming_meetings = $sidebar_upcoming_meetings;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Secretary Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #007bff;
            --secondary-blue: #0056b3;
            --accent-blue: #0069d9;
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

        .dark-mode {
            --primary-blue: #4dabf7;
            --secondary-blue: #339af0;
            --accent-blue: #228be6;
            --light-blue: #1a365d;
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

        .menu-divider {
            height: 1px;
            background: var(--medium-gray);
            margin: 1rem 1.5rem;
        }

        .menu-section {
            padding: 0.75rem 1.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
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

        /* Tables */
        .table-container {
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #cce7ff;
            color: #004085;
        }

        .status-submitted {
            background: #fff3cd;
            color: #856404;
        }

        .status-reviewed {
            background: #d4edda;
            color: #155724;
        }

        .status-approved {
            background: #d1f2eb;
            color: #0c5460;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
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
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
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
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.7rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert-info {
            background: #cce7ff;
            color: #004085;
            border-left-color: var(--info);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
            top: 0.5rem;
            right: 0.5rem;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
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
            width: 50px;
            height: 50px;
            background: var(--light-blue);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            color: var(--primary-blue);
            font-size: 1.25rem;
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
            display: inline-block;
            background: var(--light-gray);
            color: var(--dark-gray);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Team Options */
        .team-options {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }

        .team-options h4 {
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .team-member-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .team-member-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--medium-gray);
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
            font-size: 1.25rem;
            margin: 0 auto 0.5rem;
            overflow: hidden;
        }

        .team-member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .team-member-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .team-member-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
            text-transform: capitalize;
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
            border-bottom: 2px solid transparent;
            font-weight: 600;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
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

        /* File Upload */
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

        /* Team Section Editor */
        .team-section-editor {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .section-status {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .section-status.draft {
            background: #cce7ff;
            color: var(--info);
        }

        .section-status.completed {
            background: #d4edda;
            color: var(--success);
        }

        .section-assignee {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .form-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
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

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-bottom: 1px solid var(--medium-gray);
                border-left: 2px solid transparent;
            }

            .tab.active {
                border-left-color: var(--primary-blue);
                border-bottom-color: var(--medium-gray);
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

            .welcome-section h1 {
                font-size: 1.2rem;
            }

            .template-grid {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Secretary Reports</h1>
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
                        <div class="user-role">Secretary - Representative Board</div>
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
                    <a href="class_reps.php">
                        <i class="fas fa-users"></i>
                        <span>Class Rep Management</span>
                        <?php if ($total_reps > 0): ?>
                            <span class="menu-badge"><?php echo $total_reps; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Class Rep Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meeting_minutes.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Meeting Minutes</span>
                        <?php if ($pending_minutes > 0): ?>
                            <span class="menu-badge"><?php echo $pending_minutes; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Class Rep Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="class_rep_performance.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Class Rep Performance</span>
                    </a>
                </li>
                
                <li class="menu-divider"></li>
                <li class="menu-section">Other Features</li>
                
                <li class="menu-item">
                    <a href="reports.php" class="active">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-handshake"></i>
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
            

            <!-- Display Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
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
                        <div class="stat-number"><?php echo number_format($report_stats['total_reports']); ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($report_stats['submitted_reports']); ?></div>
                        <div class="stat-label">Submitted</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($report_stats['approved_reports']); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($report_stats['team_reports']); ?></div>
                        <div class="stat-label">Team Reports</div>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['action']) && $_GET['action'] === 'edit' && $current_report): ?>
                <!-- Edit Report View -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            Editing: <?php echo htmlspecialchars($current_report['title']); ?>
                            <?php if ($current_report['is_team_report']): ?>
                                <span style="color: var(--info); font-size: 0.9rem; margin-left: 1rem;">
                                    <i class="fas fa-users"></i> Team Report
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
                            <!-- Team Report Editor -->
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Team Collaboration:</strong> This is a collaborative report. Different sections are assigned to team members.
                            </div>
                            
                            <!-- Main Report Content -->
                            <div class="team-section-editor">
                                <div class="section-header">
                                    <h4>Main Report Content</h4>
                                    <div class="section-status <?php echo $current_report['status']; ?>">
                                        <?php echo ucfirst($current_report['status']); ?>
                                    </div>
                                </div>
                                <form method="POST" action="reports.php">
                                    <input type="hidden" name="action" value="create_report">
                                    <input type="hidden" name="template_id" value="<?php echo $current_report['template_id']; ?>">
                                    
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
                                            <i class="fas fa-save"></i> Update Main Content
                                        </button>
                                        <?php if ($current_report['status'] === 'draft'): ?>
                                            <button type="submit" name="submit_team_report" class="btn btn-success" onclick="return confirm('Submit this team report?')">
                                                <i class="fas fa-paper-plane"></i> Submit Team Report
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Team Sections -->
                            <h4 style="margin: 2rem 0 1rem 0;">Team Member Sections</h4>
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
                                    <a href="reports.php" class="btn" style="background: var(--medium-gray);">
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
                    <button class="tab" data-tab="team">Team Collaboration</button>
                </div>

                <!-- Create Report Tab -->
                <div class="tab-content active" id="create-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Create New Secretary Report</h3>
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
                                                         ($template['report_type'] === 'monthly' ? 'calendar-alt' : 'users'); 
                                                ?>"></i>
                                            </div>
                                            <h4 class="template-title"><?php echo htmlspecialchars($template['name']); ?></h4>
                                            <p class="template-description"><?php echo htmlspecialchars($template['description'] ?? 'No description available'); ?></p>
                                            <div class="template-type"><?php echo ucfirst($template['report_type']); ?> Report</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Team Members Overview -->
                                <div class="team-options">
                                    <h4><i class="fas fa-users"></i> Representative Board Team</h4>
                                    <p>Your team consists of <?php echo count($team_members); ?> members who can collaborate on reports:</p>
                                    
                                    <div class="team-member-grid">
                                        <?php foreach ($team_members as $member): ?>
                                            <div class="team-member-card">
                                                <div class="team-member-avatar">
                                                    <?php if (!empty($member['avatar_url'])): ?>
                                                        <img src="../<?php echo htmlspecialchars($member['avatar_url']); ?>" alt="<?php echo htmlspecialchars($member['name']); ?>">
                                                    <?php else: ?>
                                                        <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="team-member-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                                <div class="team-member-role"><?php echo htmlspecialchars(str_replace('_', ' ', $member['role'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
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
                                        <select class="form-control" id="report_type" name="report_type">
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

                                    <div class="form-group">
                                        <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                            <input type="checkbox" name="is_team_report" id="is_team_report" value="1" onchange="toggleTeamOptions()">
                                            Create as Team Report (Collaborative)
                                        </label>
                                        <small style="color: var(--dark-gray); font-size: 0.75rem;">
                                            Team reports allow collaborative work with other Representative Board members
                                        </small>
                                    </div>

                                    <div id="team_options" style="display: none;">
                                        <div class="form-group">
                                            <label class="form-label">Team Report Type</label>
                                            <select name="team_role" class="form-control">
                                                <option value="combined">Combined Report (All members work together)</option>
                                                <option value="president">President Section</option>
                                                <option value="vice_president">Vice President Section</option>
                                                <option value="secretary">Secretary Section</option>
                                            </select>
                                            <small style="color: var(--dark-gray); font-size: 0.75rem;">
                                                Choose how to divide the report among team members
                                            </small>
                                        </div>

                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i>
                                            <strong>Team Report Information:</strong> This will create a collaborative report where different sections can be assigned to different Representative Board members. Team members will be able to contribute to their assigned sections.
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
                                        <button type="button" class="btn" style="background: var(--medium-gray);" onclick="resetForm()">
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
                            <div class="table-container">
                                <?php if (empty($submitted_reports)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-file-alt"></i>
                                        <p>No reports submitted yet.</p>
                                    </div>
                                <?php else: ?>
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Template</th>
                                                <th>Type</th>
                                                <th>Team</th>
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
                                                            <br><small style="color: var(--dark-gray);"><?php echo date('F Y', strtotime($report['report_period'])); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($report['template_name'] ?? 'Custom'); ?></td>
                                                    <td>
                                                        <span class="template-type"><?php echo ucfirst($report['report_type']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($report['is_team_report']): ?>
                                                            <span style="color: var(--success);"><i class="fas fa-users"></i> Team</span>
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
                                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                            <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-sm" title="Export" style="background: var(--light-gray);">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php if ($report['status'] === 'draft' || $report['is_team_report']): ?>
                                                                <a href="reports.php?action=edit&id=<?php echo $report['id']; ?>" class="btn btn-sm" title="Edit" style="background: var(--light-gray);">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <button class="btn btn-sm" onclick="viewReport(<?php echo $report['id']; ?>)" title="View" style="background: var(--light-gray);">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team Collaboration Tab -->
                <div class="tab-content" id="team-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Team Collaboration</h3>
                        </div>
                        <div class="card-body">
                            
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1.5rem;">
                                <div style="background: var(--light-blue); padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid var(--primary-blue);">
                                    <h4><i class="fas fa-user-tie"></i> President Role</h4>
                                    <p>Overall coordination, executive oversight, and final report compilation.</p>
                                </div>
                                
                                <div style="background: #e8f5e8; padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid var(--success);">
                                    <h4><i class="fas fa-user-check"></i> Vice President Role</h4>
                                    <p>Class representative coordination, student issue tracking, and meeting management.</p>
                                </div>
                                
                                <div style="background: #fff3cd; padding: 1.5rem; border-radius: var(--border-radius); border-left: 4px solid var(--warning);">
                                    <h4><i class="fas fa-clipboard-list"></i> Secretary Role</h4>
                                    <p>Documentation, record keeping, and communication coordination.</p>
                                </div>
                            </div>
                            
                            <div style="margin-top: 2rem;">
                                <h4>Team Report Workflow</h4>
                                <ol style="margin-left: 1.5rem; color: var(--text-dark);">
                                    <li><strong>Secretary</strong> creates team report and assigns sections</li>
                                    <li><strong>Team Members</strong> contribute to their assigned sections</li>
                                    <li><strong>Secretary</strong> reviews and compiles all sections</li>
                                    <li><strong>Team</strong> reviews the complete report</li>
                                    <li><strong>Secretary</strong> submits the final report</li>
                                </ol>
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
                    <div class="alert alert-error">
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
                        <label class="form-label">Team Report</label>
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

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animation = 'fadeInUp 0.4s ease forwards';
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
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
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 500);
        });
    </script>
</body>
</html>