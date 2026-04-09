<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep (PostgreSQL uses true for boolean)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? false)) {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'] ?? '';
$program = $_SESSION['program'] ?? '';
$academic_year = $_SESSION['academic_year'] ?? '';

// Get available templates (PostgreSQL uses true for boolean)
$templates_stmt = $pdo->prepare("SELECT * FROM class_rep_templates WHERE is_active = true ORDER BY name");
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

// Handle report submission (PostgreSQL uses CURRENT_TIMESTAMP)
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
            
            if (isset($template_fields['sections']) && is_array($template_fields['sections'])) {
                foreach ($template_fields['sections'] as $section) {
                    $field_name = strtolower(preg_replace('/[^a-z0-9]/', '_', $section['title']));
                    $content[$field_name] = $_POST[$field_name] ?? '';
                }
            }
            
            // Insert the report
            $stmt = $pdo->prepare("
                INSERT INTO class_rep_reports 
                (template_id, user_id, title, report_type, report_period, activity_date, content, status, submitted_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
            $error_message = "Failed to submit report. Please try again. Error: " . $e->getMessage();
        }
    }
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Class Representative Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary: #0056b3;
            --secondary: #1e88e5;
            --accent: #0d47a1;
            --light: #f8f9fa;
            --white: #fff;
            --gray: #e9ecef;
            --dark-gray: #6c757d;
            --text: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --purple: #6f42c1;
            --teal: #20c997;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --radius: 8px;
            --transition: all 0.3s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); font-size: 0.875rem; }
        .container { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 260px; height: 100vh; z-index: 1000; overflow-y: auto; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .brand-logo img { width: 100%; height: 100%; object-fit: cover; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.25rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.75rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); font-size: 0.85rem; }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        .nav-links i { width: 20px; text-align: center; }
        
        /* Main Content */
        .main { grid-column: 2; padding: 1.5rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; background: var(--white); padding: 1.25rem 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.3rem; margin-bottom: 0.25rem; }
        .welcome p { font-size: 0.85rem; color: var(--dark-gray); }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.85rem; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); }
        .icon-btn:hover { background: var(--gray); }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.8rem; color: var(--dark-gray); }
        
        /* Cards */
        .card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .card-title { font-size: 1rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.8rem; cursor: pointer; }
        .link:hover { text-decoration: underline; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 0.75rem; }
        
        /* Template Grid */
        .template-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .template-card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; box-shadow: var(--shadow); transition: var(--transition); border-left: 4px solid var(--secondary); cursor: pointer; }
        .template-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
        .template-icon { width: 50px; height: 50px; border-radius: 50%; background: rgba(30,136,229,0.1); display: flex; align-items: center; justify-content: center; color: var(--secondary); margin-bottom: 1rem; font-size: 1.2rem; }
        .template-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .template-description { color: var(--dark-gray); margin-bottom: 1rem; line-height: 1.5; font-size: 0.8rem; }
        .template-type { display: inline-block; padding: 0.25rem 0.75rem; background: var(--light); color: var(--dark-gray); border-radius: 20px; font-size: 0.7rem; }
        
        /* Form Styles */
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text); font-size: 0.8rem; }
        .form-control { width: 100%; padding: 0.75rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); font-family: inherit; transition: var(--transition); font-size: 0.85rem; }
        .form-control:focus { outline: none; border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(30,136,229,0.1); }
        textarea.form-control { min-height: 120px; resize: vertical; }
        .form-help { font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        
        /* Report List */
        .report-list { display: grid; gap: 0.75rem; }
        .report-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--light); border-radius: var(--radius); transition: var(--transition); cursor: pointer; }
        .report-item:hover { background: var(--gray); transform: translateX(5px); }
        .report-info h4 { margin-bottom: 0.25rem; font-size: 0.9rem; }
        .report-meta { display: flex; gap: 1rem; font-size: 0.7rem; color: var(--dark-gray); flex-wrap: wrap; }
        
        /* Status Badges */
        .status { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .status-draft { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        .status-submitted { background: rgba(23,162,184,0.1); color: var(--info); }
        .status-reviewed { background: rgba(255,193,7,0.1); color: var(--warning); }
        .status-approved { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-rejected { background: rgba(220,53,69,0.1); color: var(--danger); }
        
        /* Alert */
        .alert { padding: 0.75rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; }
        .alert-success { background: rgba(40,167,69,0.1); color: var(--success); border-left-color: var(--success); }
        .alert-error { background: rgba(220,53,69,0.1); color: var(--danger); border-left-color: var(--danger); }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 2rem; color: var(--dark-gray); }
        .empty-state i { font-size: 2rem; margin-bottom: 1rem; opacity: 0.5; }
        .empty-state h3 { margin-bottom: 0.5rem; font-size: 1rem; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal-content { background: var(--white); border-radius: var(--radius); box-shadow: var(--shadow); width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; position: relative; }
        .modal-header { padding: 1.25rem; border-bottom: 1px solid var(--gray); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: var(--white); z-index: 1; }
        .modal-header h3 { font-size: 1.1rem; }
        .modal-body { padding: 1.25rem; }
        .modal-footer { padding: 1rem 1.25rem; border-top: 1px solid var(--gray); display: flex; justify-content: flex-end; gap: 1rem; }
        .close-modal { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--dark-gray); width: auto; height: auto; padding: 0.5rem; }
        .close-modal:hover { color: var(--danger); }
        
        /* Loading Spinner */
        .loading-spinner { text-align: center; padding: 2rem; }
        .loading-spinner i { font-size: 2rem; color: var(--secondary); animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        /* Animations */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .template-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .report-item { flex-direction: column; gap: 0.5rem; align-items: flex-start; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="brand">
                <div class="brand-logo">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College">
                </div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard.php"><i class="fas fa-tachometer-alt"></i> Class Rep Dashboard</a></li>
                <li><a href="class_tickets.php"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_rep_financial_aid.php"><i class="fas fa-hand-holding-usd"></i> Financial Aid</a></li>
                <li><a href="class_students.php"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="rep_meetings.php"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports.php" class="active"><i class="fas fa-file-alt"></i> Reports</a></li>
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
                    <p><?php echo safe_display($program); ?> - <?php echo safe_display($academic_year); ?></p>
                </div>
                <div class="actions">
                    <button class="icon-btn" id="mobileMenuToggle" title="Menu">
                        <i class="fas fa-bars"></i>
                    </button>
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
                <strong>Report Templates:</strong> Click on any template below to start creating your report. Templates help ensure you include all necessary information in the right format.
            </div>

            <!-- Report Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-number"><?php echo number_format(count($templates)); ?></div>
                    <div class="stat-label">Available Templates</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo number_format(count($previous_reports)); ?></div>
                    <div class="stat-label">Reports Submitted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-clock"></i></div>
                    <div class="stat-number">
                        <?php 
                        $pending_reports = array_filter($previous_reports, function($report) {
                            return in_array($report['status'], ['submitted', 'reviewed']);
                        });
                        echo number_format(count($pending_reports));
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
                        echo number_format(count($approved_reports));
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
                            <div class="template-card" data-template-id="<?php echo $template['id']; ?>">
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
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                    <span class="template-type"><?php echo ucfirst($template['report_type']); ?> Report</span>
                                    <button class="btn btn-primary use-template-btn" data-template-id="<?php echo $template['id']; ?>" style="padding: 0.5rem 1rem; font-size: 0.75rem;">
                                        <i class="fas fa-plus"></i> Use Template
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

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
                            <p>You haven't submitted any reports yet. Click on a template above to get started.</p>
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
    <div id="viewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Report Details</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="reportDetails">
                <!-- Report details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Template Form Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTemplateTitle">Create Report</h3>
                <button class="close-modal" onclick="closeTemplateModal()">&times;</button>
            </div>
            <div class="modal-body" id="templateModalBody">
                <!-- Template form will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Mobile Menu Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('mobile-open');
            });
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (sidebar && mobileMenuToggle && 
                    !sidebar.contains(event.target) && 
                    !mobileMenuToggle.contains(event.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Template Modal functionality
        const templateModal = document.getElementById('templateModal');
        const templateModalBody = document.getElementById('templateModalBody');
        const modalTemplateTitle = document.getElementById('modalTemplateTitle');

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Function to open template modal
        function openTemplateModal(templateId) {
            console.log('Opening template modal for ID:', templateId);
            
            // Show loading state
            templateModalBody.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p style="margin-top: 1rem; color: var(--dark-gray);">Loading template...</p>
                </div>
            `;
            
            // Display the modal
            templateModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Fetch template form via AJAX
            fetch('get_template_fields.php?template_id=' + templateId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Template data received:', data);
                    
                    if (data.success) {
                        const template = data.template;
                        modalTemplateTitle.textContent = 'Create Report: ' + template.name;
                        
                        // Parse fields safely
                        let templateFields = { sections: [] };
                        try {
                            if (typeof template.fields === 'string') {
                                templateFields = JSON.parse(template.fields);
                            } else if (typeof template.fields === 'object') {
                                templateFields = template.fields;
                            }
                        } catch (e) {
                            console.error('Error parsing template fields:', e);
                        }
                        
                        let formHtml = `
                            <form method="POST" id="reportForm" action="">
                                <input type="hidden" name="template_id" value="${template.id}">
                                <input type="hidden" name="submit_report" value="1">
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="title">Report Title *</label>
                                        <input type="text" id="title" name="title" class="form-control" 
                                               placeholder="Enter a descriptive title for your report" required>
                                    </div>
                        `;
                        
                        if (template.report_type === 'monthly') {
                            const now = new Date();
                            const currentMonth = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
                            formHtml += `
                                <div class="form-group">
                                    <label for="report_period">Report Period *</label>
                                    <input type="month" id="report_period" name="report_period" class="form-control" value="${currentMonth}" required>
                                </div>
                            `;
                        } else if (['activity', 'incident', 'meeting'].includes(template.report_type)) {
                            const today = new Date().toISOString().split('T')[0];
                            formHtml += `
                                <div class="form-group">
                                    <label for="activity_date">Activity/Event Date *</label>
                                    <input type="date" id="activity_date" name="activity_date" class="form-control" value="${today}" required>
                                </div>
                            `;
                        }
                        
                        formHtml += `</div>`;
                        
                        // Add template fields
                        if (templateFields.sections && Array.isArray(templateFields.sections) && templateFields.sections.length > 0) {
                            templateFields.sections.forEach(section => {
                                const fieldName = section.title.toLowerCase().replace(/[^a-z0-9]/g, '_');
                                const required = section.required ? 'required' : '';
                                const requiredStar = section.required ? '<span style="color: var(--danger);">*</span>' : '';
                                
                                formHtml += `
                                    <div class="form-group">
                                        <label for="${fieldName}">
                                            ${escapeHtml(section.title)}
                                            ${requiredStar}
                                        </label>
                                `;
                                
                                if (section.type === 'textarea') {
                                    formHtml += `
                                        <textarea id="${fieldName}" name="${fieldName}" class="form-control" 
                                                  placeholder="Enter ${escapeHtml(section.title.toLowerCase())}..." ${required} rows="4"></textarea>
                                    `;
                                } else if (section.type === 'number') {
                                    formHtml += `
                                        <input type="number" id="${fieldName}" name="${fieldName}" class="form-control" 
                                               placeholder="Enter ${escapeHtml(section.title.toLowerCase())}..." ${required} step="0.01">
                                    `;
                                } else {
                                    formHtml += `
                                        <input type="${section.type}" id="${fieldName}" name="${fieldName}" class="form-control" 
                                               placeholder="Enter ${escapeHtml(section.title.toLowerCase())}..." ${required}>
                                    `;
                                }
                                
                                if (section.description) {
                                    formHtml += `<div class="form-help">${escapeHtml(section.description)}</div>`;
                                }
                                
                                formHtml += `</div>`;
                            });
                        } else {
                            formHtml += `
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    No custom fields defined for this template. You can still submit the report.
                                </div>
                            `;
                        }
                        
                        formHtml += `
                            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeTemplateModal()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-success" style="flex: 1;">
                                    <i class="fas fa-paper-plane"></i> Submit Report
                                </button>
                            </div>
                        </form>
                        `;
                        
                        templateModalBody.innerHTML = formHtml;
                        
                        // Auto-generate title based on template and dates
                        const titleInput = document.getElementById('title');
                        const reportPeriodInput = document.getElementById('report_period');
                        const activityDateInput = document.getElementById('activity_date');
                        
                        function generateTitle() {
                            if (!titleInput) return;
                            
                            let generatedTitle = template.name;
                            
                            if (reportPeriodInput && reportPeriodInput.value) {
                                const period = new Date(reportPeriodInput.value + '-01');
                                generatedTitle += ' - ' + period.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                            } else if (activityDateInput && activityDateInput.value) {
                                const date = new Date(activityDateInput.value);
                                generatedTitle += ' - ' + date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                            }
                            
                            if (!titleInput.value || titleInput.value.trim() === '') {
                                titleInput.value = generatedTitle;
                            }
                        }
                        
                        if (reportPeriodInput) reportPeriodInput.addEventListener('change', generateTitle);
                        if (activityDateInput) activityDateInput.addEventListener('change', generateTitle);
                        generateTitle();
                        
                    } else {
                        templateModalBody.innerHTML = `
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-triangle"></i>
                                Failed to load template: ${escapeHtml(data.message || 'Unknown error')}
                            </div>
                            <div style="margin-top: 1rem; text-align: center;">
                                <button type="button" class="btn btn-secondary" onclick="closeTemplateModal()">Close</button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading template:', error);
                    templateModalBody.innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            Failed to load template. Error: ${escapeHtml(error.message)}
                        </div>
                        <div style="margin-top: 1rem; text-align: center;">
                            <button type="button" class="btn btn-secondary" onclick="closeTemplateModal()">Close</button>
                        </div>
                    `;
                });
        }
        
        // Function to close template modal
        function closeTemplateModal() {
            templateModal.style.display = 'none';
            templateModalBody.innerHTML = '';
            document.body.style.overflow = '';
        }
        
        // Add click event to "Use Template" buttons using event delegation
        document.addEventListener('click', function(e) {
            // Check if clicked on use-template-btn or inside template-card
            if (e.target && e.target.classList && e.target.classList.contains('use-template-btn')) {
                e.preventDefault();
                e.stopPropagation();
                const templateId = e.target.getAttribute('data-template-id');
                if (templateId) {
                    openTemplateModal(templateId);
                }
            }
        });
        
        // Also allow clicking on the template card itself
        document.querySelectorAll('.template-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't trigger if clicking on the button itself (already handled)
                if (e.target.classList && e.target.classList.contains('use-template-btn')) {
                    return;
                }
                const templateId = this.getAttribute('data-template-id');
                if (templateId) {
                    openTemplateModal(templateId);
                }
            });
        });
        
        // Close template modal when clicking outside
        templateModal.addEventListener('click', function(e) {
            if (e.target === templateModal) {
                closeTemplateModal();
            }
        });
        
        // Escape key to close template modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && templateModal.style.display === 'flex') {
                closeTemplateModal();
            }
            if (e.key === 'Escape' && viewModal.style.display === 'flex') {
                closeViewModal();
            }
        });
        
        // View Report functionality
        const viewModal = document.getElementById('viewReportModal');
        const reportDetails = document.getElementById('reportDetails');
        
        function closeViewModal() {
            viewModal.style.display = 'none';
            reportDetails.innerHTML = '';
            document.body.style.overflow = '';
        }
        
        // Function to fetch and display report details
        async function viewReport(reportId) {
            try {
                // Show loading state
                reportDetails.innerHTML = `
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p style="margin-top: 1rem; color: var(--dark-gray);">Loading report details...</p>
                    </div>
                `;
                
                viewModal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                
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
            item.addEventListener('click', function(e) {
                // Don't trigger if clicking on links/buttons inside
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
                
                const reportId = this.dataset.reportId;
                if (reportId) {
                    viewReport(reportId);
                }
            });
        });
        
        // Close view modal when clicking outside
        viewModal.addEventListener('click', function(e) {
            if (e.target === viewModal) {
                closeViewModal();
            }
        });
        
        // Close buttons for modals
        document.querySelectorAll('.close-modal').forEach(button => {
            button.addEventListener('click', function() {
                if (viewModal.style.display === 'flex') {
                    closeViewModal();
                }
            });
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .card');
            cards.forEach((card, index) => {
                card.style.animation = `fadeInUp 0.4s ease forwards`;
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 100);
        });
    </script>
</body>
</html>