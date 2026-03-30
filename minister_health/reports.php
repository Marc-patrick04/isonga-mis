<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Health
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_health') {
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

// Get available templates for Minister of Health
try {
    $stmt = $pdo->prepare("
        SELECT * FROM report_templates 
        WHERE (role_specific = 'minister_health' OR role_specific IS NULL)
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
        $report_type = $_POST['report_type'] ?? 'activity';
        $report_period = !empty($_POST['report_period']) ? $_POST['report_period'] : null;
        $activity_date = !empty($_POST['activity_date']) ? $_POST['activity_date'] : null;
        
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
                    INSERT INTO reports (title, template_id, user_id, report_type, report_period, activity_date, content, status, submitted_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
                
                $_SESSION['success_message'] = "Health report submitted successfully!";
                header("Location: reports.php");
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
            header('Content-Disposition: attachment; filename="health_report_' . $report_id . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV header
            fputcsv($output, ['Health Report Export - ' . $report['title']]);
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
            COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted_reports,
            COUNT(CASE WHEN status = 'reviewed' THEN 1 END) as reviewed_reports,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_reports,
            COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_reports
        FROM reports 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $report_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report_stats) {
        $report_stats = [
            'total_reports' => 0,
            'submitted_reports' => 0,
            'reviewed_reports' => 0,
            'approved_reports' => 0,
            'draft_reports' => 0
        ];
    }
} catch (PDOException $e) {
    $report_stats = [
        'total_reports' => 0,
        'submitted_reports' => 0,
        'reviewed_reports' => 0,
        'approved_reports' => 0,
        'draft_reports' => 0
    ];
}

// Insert health-specific templates if they don't exist
try {
    $health_templates = [
        [
            'name' => 'Health Campaign Report',
            'description' => 'Comprehensive report for health awareness campaigns and programs',
            'role_specific' => 'minister_health',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'text',
                        'title' => 'Campaign Name',
                        'required' => true,
                        'description' => 'Name of the health campaign or program'
                    ],
                    [
                        'type' => 'date',
                        'title' => 'Campaign Date',
                        'required' => true,
                        'description' => 'Date when the campaign took place'
                    ],
                    [
                        'type' => 'text',
                        'title' => 'Campaign Location',
                        'required' => true,
                        'description' => 'Venue where the campaign was held'
                    ],
                    [
                        'type' => 'number',
                        'title' => 'Participant Count',
                        'required' => true,
                        'description' => 'Total number of participants'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Campaign Objectives',
                        'required' => true,
                        'description' => 'Main objectives of the health campaign'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Activities Conducted',
                        'required' => true,
                        'description' => 'Detailed description of campaign activities'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Health Topics Covered',
                        'required' => true,
                        'description' => 'Health topics and awareness areas covered'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Achievements',
                        'required' => true,
                        'description' => 'Key achievements and successes from the campaign'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Challenges Faced',
                        'required' => false,
                        'description' => 'Any challenges encountered during the campaign'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Participant Feedback',
                        'required' => false,
                        'description' => 'Feedback received from participants'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Recommendations',
                        'required' => true,
                        'description' => 'Suggestions for future health campaigns'
                    ]
                ]
            ])
        ],
        [
            'name' => 'Monthly Health Activities Report',
            'description' => 'Monthly summary of all health and welfare activities',
            'role_specific' => 'minister_health',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'textarea',
                        'title' => 'Health Activities Completed',
                        'required' => true,
                        'description' => 'List all health activities completed during the month'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Student Health Issues',
                        'required' => true,
                        'description' => 'Summary of student health issues addressed'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Hostel Health Inspections',
                        'required' => true,
                        'description' => 'Results of hostel health and sanitation inspections'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Restaurant Complaints',
                        'required' => true,
                        'description' => 'Food and restaurant complaints handled'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Health Education',
                        'required' => true,
                        'description' => 'Health education and awareness activities'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Challenges Faced',
                        'required' => false,
                        'description' => 'Health-related challenges and obstacles'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Next Month Plans',
                        'required' => true,
                        'description' => 'Planned health activities for the coming month'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Budget Utilization',
                        'required' => false,
                        'description' => 'How the health budget was utilized'
                    ]
                ]
            ])
        ],
        [
            'name' => 'Hostel Health Inspection Report',
            'description' => 'Report for hostel health and sanitation inspections',
            'role_specific' => 'minister_health',
            'report_type' => 'activity',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'text',
                        'title' => 'Hostel Name',
                        'required' => true,
                        'description' => 'Name of the hostel inspected'
                    ],
                    [
                        'type' => 'date',
                        'title' => 'Inspection Date',
                        'required' => true,
                        'description' => 'Date of the health inspection'
                    ],
                    [
                        'type' => 'number',
                        'title' => 'Rooms Inspected',
                        'required' => true,
                        'description' => 'Number of rooms inspected'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Inspection Areas',
                        'required' => true,
                        'description' => 'Specific areas covered during inspection'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Sanitation Findings',
                        'required' => true,
                        'description' => 'Detailed sanitation and cleanliness findings'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Health Hazards',
                        'required' => true,
                        'description' => 'Health hazards and safety concerns identified'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Student Feedback',
                        'required' => false,
                        'description' => 'Feedback received from hostel residents'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Corrective Actions',
                        'required' => true,
                        'description' => 'Actions taken to address identified issues'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Follow-up Required',
                        'required' => true,
                        'description' => 'Areas requiring follow-up inspection'
                    ]
                ]
            ])
        ],
        [
            'name' => 'Epidemic Prevention Report',
            'description' => 'Report on epidemic prevention measures and outbreaks',
            'role_specific' => 'minister_health',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'textarea',
                        'title' => 'Disease Surveillance',
                        'required' => true,
                        'description' => 'Summary of disease surveillance activities'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Prevention Measures',
                        'required' => true,
                        'description' => 'Epidemic prevention measures implemented'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Reported Cases',
                        'required' => true,
                        'description' => 'Summary of reported health cases'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Awareness Campaigns',
                        'required' => true,
                        'description' => 'Health awareness campaigns conducted'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Vaccination Status',
                        'required' => false,
                        'description' => 'Vaccination and immunization status'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Emergency Preparedness',
                        'required' => true,
                        'description' => 'Emergency health preparedness measures'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Challenges',
                        'required' => false,
                        'description' => 'Challenges in epidemic prevention'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Recommendations',
                        'required' => true,
                        'description' => 'Recommendations for improved prevention'
                    ]
                ]
            ])
        ],
        [
            'name' => 'Student Health Welfare Report',
            'description' => 'Comprehensive report on student health and welfare issues',
            'role_specific' => 'minister_health',
            'report_type' => 'monthly',
            'fields' => json_encode([
                'sections' => [
                    [
                        'type' => 'textarea',
                        'title' => 'Welfare Overview',
                        'required' => true,
                        'description' => 'Overall student health and welfare status'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Common Health Issues',
                        'required' => true,
                        'description' => 'Most common student health complaints'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Mental Health Support',
                        'required' => true,
                        'description' => 'Mental health and counseling services provided'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Accommodation Issues',
                        'required' => true,
                        'description' => 'Hostel and accommodation health concerns'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Food Safety',
                        'required' => true,
                        'description' => 'Food safety and restaurant health standards'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Student Support',
                        'required' => true,
                        'description' => 'Support provided to students with health issues'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Improvement Areas',
                        'required' => true,
                        'description' => 'Areas needing health and welfare improvements'
                    ],
                    [
                        'type' => 'textarea',
                        'title' => 'Success Stories',
                        'required' => false,
                        'description' => 'Positive health and welfare outcomes'
                    ]
                ]
            ])
        ]
    ];

    foreach ($health_templates as $template_data) {
        $check_stmt = $pdo->prepare("SELECT id FROM report_templates WHERE name = ? AND role_specific = 'minister_health'");
        $check_stmt->execute([$template_data['name']]);
        
        if (!$check_stmt->fetch()) {
            $insert_stmt = $pdo->prepare("
                INSERT INTO report_templates (name, description, role_specific, report_type, fields, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
        WHERE (role_specific = 'minister_health' OR role_specific IS NULL)
        AND is_active = true
        ORDER BY name
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Health templates setup error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Health Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #20c997;
            --accent-green: #198754;
            --light-green: #d1f2eb;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            --primary-green: #20c997;
            --secondary-green: #3dd9a7;
            --accent-green: #198754;
            --light-green: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            color: var(--primary-green);
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
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
        }

        .menu-item i {
            width: 20px;
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
            border-left: 4px solid var(--primary-green);
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
            background: var(--light-green);
            color: var(--primary-green);
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

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
            text-align: center;
            white-space: nowrap;
        }

        .tab:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .tab.active {
            background: var(--primary-green);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--light-green);
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

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .template-card {
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .template-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .template-card.selected {
            border-color: var(--primary-green);
            background: var(--light-green);
        }

        .template-check {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 24px;
            height: 24px;
            background: var(--primary-green);
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
            background: var(--light-green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
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
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }

        .template-type {
            background: var(--light-gray);
            color: var(--dark-gray);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
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
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'><path fill='%236c757d' d='M2 0L0 2h4zm0 5L0 3h4z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }

        .form-text {
            color: var(--dark-gray);
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
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
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
            border-color: var(--dark-gray);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
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
            border-color: var(--primary-green);
            background: var(--light-green);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--primary-green);
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
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
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
            border-radius: 4px;
        }

        .file-remove:hover {
            background: var(--danger);
            color: white;
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
            background: var(--light-green);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
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
            color: var(--primary-green);
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
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

        .text-muted {
            color: var(--dark-gray);
        }

        /* Modal */
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

        .modal-content {
            background-color: var(--white);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-green);
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-close:hover {
            color: var(--text-dark);
        }

        .modal-body {
            padding: 1.25rem;
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
                background: var(--primary-green);
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

            .template-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab {
                flex: 1;
                min-width: 80px;
                padding: 0.5rem;
                font-size: 0.75rem;
            }

            .stat-number {
                font-size: 1.1rem;
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

            .modal-content {
                width: 95%;
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
                    <h1>Isonga - Health Reports</h1>
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
                        <div class="user-role">Minister of Health</div>
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
                    <a href="health_tickets.php">
                        <i class="fas fa-heartbeat"></i>
                        <span>Health Issues</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostels.php">
                        <i class="fas fa-bed"></i>
                        <span>Hostel Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="campaigns.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Health Campaigns</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="epidemics.php">
                        <i class="fas fa-virus"></i>
                        <span>Epidemic Prevention</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php" class="active">
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
                    <h1>Health Reports & Analytics</h1>
                    <p>Create, manage, and export health reports using professional templates</p>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Report Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-medical"></i>
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
                <button class="tab active" data-tab="create">Create New Report</button>
                <button class="tab" data-tab="submitted">Submitted Reports (<?php echo count($submitted_reports); ?>)</button>
            </div>

            <!-- Create Report Tab -->
            <div class="tab-content active" id="create-tab">
                <div class="card">
                    <div class="card-header">
                        <h3>Select Health Report Template</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($templates)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No health templates available at the moment.
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
                                                echo $template['report_type'] === 'activity' ? 'heartbeat' : 
                                                     ($template['report_type'] === 'monthly' ? 'calendar-alt' : 'file-medical'); 
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
                                        <option value="activity">Activity Report</option>
                                        <option value="monthly">Monthly Report</option>
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
                                        <i class="fas fa-paper-plane"></i> Submit Health Report
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
                        <h3>Submitted Health Reports</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submitted_reports)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No health reports submitted yet.
                            </div>
                        <?php else: ?>
                            <div class="table-container">
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
                                                        <a href="reports.php?export=1&id=<?php echo $report['id']; ?>" class="btn btn-outline btn-sm" title="Export">
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
    <div class="modal" id="reportModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Health Report Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
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

            // Add loading animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
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
                // Since we don't have an API endpoint, we'll use the templates data from PHP
                const templatesData = <?php echo json_encode($templates); ?>;
                const template = templatesData.find(t => t.id == templateId);
                
                const fieldsContainer = document.getElementById('templateFields');
                fieldsContainer.innerHTML = '';
                
                if (template && template.fields) {
                    const fields = JSON.parse(template.fields);
                    if (fields.sections && fields.sections.length) {
                        fields.sections.forEach(section => {
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
            document.getElementById('templateFields').innerHTML = '';
        }

        // View report in modal
        async function viewReport(reportId) {
            try {
                // Fetch report data
                const response = await fetch(`../api/get_report.php?id=${reportId}`);
                const report = await response.json();
                
                document.getElementById('modalTitle').textContent = report.title || 'Health Report Details';
                
                let content = '';
                
                if (report.content) {
                    let reportContent;
                    if (typeof report.content === 'string') {
                        reportContent = JSON.parse(report.content);
                    } else {
                        reportContent = report.content;
                    }
                    
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
                
                if (!content) {
                    content = '<div class="alert alert-warning">No content available for this report.</div>';
                }
                
                document.getElementById('modalContent').innerHTML = content;
                document.getElementById('reportModal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            } catch (error) {
                console.error('Error loading report:', error);
                document.getElementById('modalContent').innerHTML = '<div class="alert alert-danger">Error loading report details.</div>';
                document.getElementById('reportModal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal() {
            document.getElementById('reportModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        document.getElementById('reportModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>