<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: rep_reports.php');
    exit();
}

// Get available templates
$templates_stmt = $pdo->prepare("SELECT * FROM class_rep_templates WHERE is_active = '1' ORDER BY name");
$templates_stmt->execute();
$templates = $templates_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's previous reports
$reports_stmt = $pdo->prepare("
    SELECT crr.*, crt.name as template_name 
    FROM class_rep_reports crr 
    JOIN class_rep_templates crt ON crr.template_id = crt.id 
    WHERE crr.user_id = ? 
    ORDER BY crr.created_at DESC
    LIMIT 10
");
$reports_stmt->execute([$student_id]);
$previous_reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $template_id = $_POST['template_id'];
    $title = trim($_POST['title']);
    $report_period = $_POST['report_period'] ?: null;
    $activity_date = $_POST['activity_date'] ?: null;
    
    // Get template to validate fields
    $template_stmt = $pdo->prepare("SELECT * FROM class_rep_templates WHERE id = ?");
    $template_stmt->execute([$template_id]);
    $template = $template_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        $error_message = "Invalid template selected.";
    } elseif (empty($title)) {
        $error_message = "Report title is required.";
    } else {
        try {
            // Collect form data based on template fields
            $content = [];
            $template_fields = json_decode($template['fields'], true);
            
            foreach ($template_fields['sections'] as $section) {
                $field_name = strtolower(str_replace(' ', '_', $section['title']));
                $content[$field_name] = $_POST[$field_name] ?? '';
            }
            
            // Insert the report
            $stmt = $pdo->prepare("
                INSERT INTO class_rep_reports 
                (template_id, user_id, title, report_type, report_period, activity_date, content, status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())
            ");
            
            $stmt->execute([
                $template_id,
                $student_id,
                $title,
                $template['report_type'],
                $report_period,
                $activity_date,
                json_encode($content)
            ]);
            
            $report_id = $pdo->lastInsertId();
            
            $_SESSION['success_message'] = "Report submitted successfully! Your report ID is #$report_id";
            header('Location: rep_reports.php');
            exit();
            
        } catch (PDOException $e) {
            $error_message = "Failed to submit report. Please try again.";
        }
    }
}

// Handle template selection for form generation
$selected_template = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_template'])) {
    $template_id = $_POST['template_id'];
    $template_stmt = $pdo->prepare("SELECT * FROM class_rep_templates WHERE id = ?");
    $template_stmt->execute([$template_id]);
    $selected_template = $template_stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper functions
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

function getStatusBadge($status) {
    switch ($status) {
        case 'draft':
            return '<span class="status status-draft">Draft</span>';
        case 'submitted':
            return '<span class="status status-submitted">Submitted</span>';
        case 'reviewed':
            return '<span class="status status-reviewed">Reviewed</span>';
        case 'approved':
            return '<span class="status status-approved">Approved</span>';
        case 'rejected':
            return '<span class="status status-rejected">Rejected</span>';
        default:
            return '<span class="status status-unknown">Unknown</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Representative Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0056b3; --secondary: #1e88e5; --accent: #0d47a1;
            --light: #f8f9fa; --white: #fff; --gray: #e9ecef; 
            --dark-gray: #6c757d; --text: #2c3e50; --success: #28a745;
            --warning: #ffc107; --danger: #dc3545; --info: #17a2b8;
            --shadow: 0 4px 12px rgba(0,0,0,0.1); --radius: 8px; 
            --transition: all 0.3s ease;
        }
        [data-theme="dark"] {
            --white: #1a1a1a; --light: #2d2d2d; --gray: #3d3d3d;
            --dark-gray: #a0a0a0; --text: #e9ecef;
            --shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); transition: var(--transition); }
        .container { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 250px; height: 100vh; z-index: 1000; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.5rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.8rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        
        /* Main Content */
        .main { grid-column: 2; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; background: var(--white); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .actions { display: flex; gap: 1rem; }
        .btn { padding: 0.8rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        .stat-label { font-size: 0.9rem; color: var(--dark-gray); }
        
        /* Cards */
        .card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-title { font-size: 1.2rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.9rem; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 1rem; }
        
        /* Template Grid */
        .template-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .template-card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); transition: var(--transition); border-left: 4px solid var(--secondary); }
        .template-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
        .template-icon { width: 50px; height: 50px; border-radius: 50%; background: rgba(30,136,229,0.1); display: flex; align-items: center; justify-content: center; color: var(--secondary); margin-bottom: 1rem; }
        .template-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .template-description { color: var(--dark-gray); margin-bottom: 1rem; line-height: 1.5; }
        .template-type { display: inline-block; padding: 0.3rem 0.8rem; background: var(--light); color: var(--dark-gray); border-radius: 20px; font-size: 0.8rem; }
        
        /* Form Styles */
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text); }
        .form-control { width: 100%; padding: 0.8rem 1rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); font-family: inherit; transition: var(--transition); }
        .form-control:focus { outline: none; border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(30,136,229,0.1); }
        textarea.form-control { min-height: 120px; resize: vertical; }
        .form-help { font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.3rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        
        /* Report List */
        .report-list { display: grid; gap: 1rem; }
        .report-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--light); border-radius: var(--radius); transition: var(--transition); }
        .report-item:hover { background: var(--gray); }
        .report-info h4 { margin-bottom: 0.3rem; }
        .report-meta { display: flex; gap: 1rem; font-size: 0.8rem; color: var(--dark-gray); }
        
        /* Status Badges */
        .status { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .status-draft { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        .status-submitted { background: rgba(23,162,184,0.1); color: var(--info); }
        .status-reviewed { background: rgba(255,193,7,0.1); color: var(--warning); }
        .status-approved { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-rejected { background: rgba(220,53,69,0.1); color: var(--danger); }
        
        /* Alert */
        .alert { padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; }
        .alert-success { background: rgba(40,167,69,0.1); color: var(--success); border-left-color: var(--success); }
        .alert-error { background: rgba(220,53,69,0.1); color: var(--danger); border-left-color: var(--danger); }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 3rem; color: var(--dark-gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; color: var(--gray); }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .template-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .report-item { flex-direction: column; gap: 0.5rem; align-items: start; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        /* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: var(--white);
    z-index: 1;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid var(--gray);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
}

/* Make report items clickable */
.report-item {
    cursor: pointer;
    transition: all 0.2s ease;
}

.report-item:hover {
    transform: translateX(5px);
    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
}
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="brand">
                <div class="brand-logo"><i class="fas fa-user-shield"></i></div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></li>
                <li><a href="class_tickets.php"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_students.php"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="rep_meetings.php"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="#" class="active"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main">
            <!-- Header -->
            <div class="header">
                <div class="welcome">
                    <h1>Class Representative Reports
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p>Submit reports using templates to streamline your work</p>
                </div>
                <div class="actions">
                    <form method="POST">
                        <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Report Templates:</strong> Select a template below to start creating your report. Templates help ensure you include all necessary information in the right format.
            </div>

            <!-- Report Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-number"><?php echo count($templates); ?></div>
                    <div class="stat-label">Available Templates</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo count($previous_reports); ?></div>
                    <div class="stat-label">Reports Submitted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-clock"></i></div>
                    <div class="stat-number">
                        <?php 
                        $pending_reports = array_filter($previous_reports, function($report) {
                            return in_array($report['status'], ['submitted', 'reviewed']);
                        });
                        echo count($pending_reports);
                        ?>
                    </div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-thumbs-up"></i></div>
                    <div class="stat-number">
                        <?php 
                        $approved_reports = array_filter($previous_reports, function($report) {
                            return $report['status'] === 'approved';
                        });
                        echo count($approved_reports);
                        ?>
                    </div>
                    <div class="stat-label">Approved Reports</div>
                </div>
            </div>

            <!-- Report Templates -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Available Report Templates</h3>
                </div>
                <div class="template-grid">
                    <?php if (empty($templates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Templates Available</h3>
                            <p>Report templates will be available soon.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($templates as $template): ?>
                            <div class="template-card">
                                <div class="template-icon">
                                    <i class="fas fa-<?php 
                                        switch($template['report_type']) {
                                            case 'monthly': echo 'calendar-alt'; break;
                                            case 'meeting': echo 'users'; break;
                                            case 'incident': echo 'exclamation-triangle'; break;
                                            case 'activity': echo 'running'; break;
                                            default: echo 'file-alt';
                                        }
                                    ?>"></i>
                                </div>
                                <div class="template-title"><?php echo safe_display($template['name']); ?></div>
                                <div class="template-description"><?php echo safe_display($template['description']); ?></div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span class="template-type"><?php echo ucfirst($template['report_type']); ?> Report</span>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                        <button type="submit" name="select_template" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                                            <i class="fas fa-plus"></i> Use Template
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Report Form (shown when template is selected) -->
            <?php if ($selected_template): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Create Report: <?php echo safe_display($selected_template['name']); ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="reportForm">
                            <input type="hidden" name="template_id" value="<?php echo $selected_template['id']; ?>">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="title">Report Title *</label>
                                    <input type="text" id="title" name="title" class="form-control" 
                                           placeholder="Enter a descriptive title for your report" required>
                                </div>
                                
                                <?php if ($selected_template['report_type'] === 'monthly'): ?>
                                <div class="form-group">
                                    <label for="report_period">Report Period *</label>
                                    <input type="month" id="report_period" name="report_period" class="form-control" required>
                                </div>
                                <?php elseif (in_array($selected_template['report_type'], ['activity', 'incident', 'meeting'])): ?>
                                <div class="form-group">
                                    <label for="activity_date">Activity/Event Date *</label>
                                    <input type="date" id="activity_date" name="activity_date" class="form-control" required>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php
                            $template_fields = json_decode($selected_template['fields'], true);
                            foreach ($template_fields['sections'] as $section):
                                $field_name = strtolower(str_replace(' ', '_', $section['title']));
                            ?>
                                <div class="form-group">
                                    <label for="<?php echo $field_name; ?>">
                                        <?php echo safe_display($section['title']); ?>
                                        <?php if ($section['required']): ?><span style="color: var(--danger);">*</span><?php endif; ?>
                                    </label>
                                    
                                    <?php if ($section['type'] === 'textarea'): ?>
                                        <textarea id="<?php echo $field_name; ?>" name="<?php echo $field_name; ?>" 
                                                  class="form-control" 
                                                  placeholder="Enter <?php echo strtolower($section['title']); ?>..."
                                                  <?php echo $section['required'] ? 'required' : ''; ?>></textarea>
                                    <?php elseif ($section['type'] === 'number'): ?>
                                        <input type="number" id="<?php echo $field_name; ?>" name="<?php echo $field_name; ?>" 
                                               class="form-control" 
                                               placeholder="Enter <?php echo strtolower($section['title']); ?>..."
                                               <?php echo $section['required'] ? 'required' : ''; ?>>
                                    <?php else: ?>
                                        <input type="<?php echo $section['type']; ?>" id="<?php echo $field_name; ?>" name="<?php echo $field_name; ?>" 
                                               class="form-control" 
                                               placeholder="Enter <?php echo strtolower($section['title']); ?>..."
                                               <?php echo $section['required'] ? 'required' : ''; ?>>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($section['description'])): ?>
                                        <div class="form-help"><?php echo safe_display($section['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                                <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="history.back()">
                                    <i class="fas fa-arrow-left"></i> Back to Templates
                                </button>
                                <button type="submit" name="submit_report" class="btn btn-success" style="flex: 1;">
                                    <i class="fas fa-paper-plane"></i> Submit Report
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Previous Reports -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Your Previous Reports</h3>
                </div>
                <div class="report-list">
                    
                                        <?php if (empty($previous_reports)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Reports Yet</h3>
                            <p>You haven't submitted any reports yet. Select a template above to get started.</p>
                        </div>
                    <?php else: ?>
<?php foreach ($previous_reports as $report): ?>
    <div class="report-item" data-report-id="<?php echo $report['id']; ?>">
                                <div class="report-info">
                                    <h4><?php echo safe_display($report['title']); ?></h4>
                                    <div class="report-meta">
                                        <span><i class="fas fa-file-alt"></i> <?php echo safe_display($report['template_name']); ?></span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($report['created_at'])); ?></span>
                                        <?php if ($report['report_period']): ?>
                                            <span><i class="fas fa-clock"></i> <?php echo date('F Y', strtotime($report['report_period'])); ?></span>
                                        <?php endif; ?>
                                        <?php if ($report['activity_date']): ?>
                                            <span><i class="fas fa-calendar-day"></i> <?php echo date('M j, Y', strtotime($report['activity_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="report-status">
                                    <?php echo getStatusBadge($report['status']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <!-- View Report Modal -->
<div id="viewReportModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--white); border-radius: var(--radius); box-shadow: var(--shadow); width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="padding: 1.5rem; border-bottom: 1px solid var(--gray); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: var(--text);">Report Details</h3>
            <button class="icon-btn close-modal" style="background: none; border: none; color: var(--dark-gray); cursor: pointer; font-size: 1.2rem;">×</button>
        </div>
        <div class="modal-body" id="reportDetails" style="padding: 1.5rem;">
            <!-- Report details will be loaded here -->
        </div>
    </div>
</div>

    <script>
        // Set default dates for forms
        document.addEventListener('DOMContentLoaded', function() {
            // Set default report period to current month
            const reportPeriod = document.getElementById('report_period');
            if (reportPeriod) {
                const now = new Date();
                reportPeriod.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
            }
            
            // Set default activity date to today
            const activityDate = document.getElementById('activity_date');
            if (activityDate) {
                const now = new Date();
                activityDate.value = now.toISOString().split('T')[0];
            }
            
            // Auto-generate title based on template and dates
            const titleInput = document.getElementById('title');
            const reportPeriodInput = document.getElementById('report_period');
            const activityDateInput = document.getElementById('activity_date');
            
            function generateTitle() {
                if (!titleInput || titleInput.value.trim() !== '') return;
                
                const templateName = '<?php echo $selected_template ? safe_display($selected_template["name"]) : ""; ?>';
                let generatedTitle = templateName;
                
                if (reportPeriodInput && reportPeriodInput.value) {
                    const period = new Date(reportPeriodInput.value + '-01');
                    generatedTitle += ' - ' + period.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                } else if (activityDateInput && activityDateInput.value) {
                    const date = new Date(activityDateInput.value);
                    generatedTitle += ' - ' + date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                }
                
                titleInput.value = generatedTitle;
            }
            
            if (reportPeriodInput) reportPeriodInput.addEventListener('change', generateTitle);
            if (activityDateInput) activityDateInput.addEventListener('change', generateTitle);
            
            // Generate title on page load if we have a selected template
            if (<?php echo $selected_template ? 'true' : 'false'; ?>) {
                generateTitle();
            }
        });
        // View Report functionality
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('viewReportModal');
    const reportDetails = document.getElementById('reportDetails');
    
    // Function to fetch and display report details
    async function viewReport(reportId) {
        try {
            // Show loading state
            reportDetails.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--secondary);"></i>
                    <p style="margin-top: 1rem; color: var(--dark-gray);">Loading report details...</p>
                </div>
            `;
            
            modal.style.display = 'flex';
            
            // Fetch report details via AJAX
            const response = await fetch('get_rep_report_details.php?id=' + reportId);
            const html = await response.text();
            
            reportDetails.innerHTML = html;
            
        } catch (error) {
            console.error('Error loading report:', error);
            reportDetails.innerHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load report details. Please try again.
                </div>
            `;
        }
    }
    
    // Add click event to report items
    document.querySelectorAll('.report-item').forEach(item => {
        item.style.cursor = 'pointer';
        item.addEventListener('click', function(e) {
            // Don't trigger if clicking on links/buttons inside
            if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
            
            const reportId = this.dataset.reportId;
            if (reportId) {
                viewReport(reportId);
            }
        });
    });
    
    // Close modal functionality
    document.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    });
    
    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    // Escape key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            modal.style.display = 'none';
        }
    });
});
    </script>
</body>
</html>