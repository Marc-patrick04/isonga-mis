<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    header('Location: student_login');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: class_rep_financial_aid');
    exit();
}

$request_id = $_GET['id'];
$student_id = $_SESSION['user_id'];

// Get financial aid request details
$stmt = $pdo->prepare("
    SELECT sfa.*, u.full_name as student_name, u.reg_number, 
           d.name as department_name, p.name as program_name, 
           u.academic_year, reviewer.full_name as reviewer_name
    FROM student_financial_aid sfa
    JOIN users u ON sfa.student_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    LEFT JOIN users reviewer ON sfa.reviewed_by = reviewer.id
    WHERE sfa.id = ? AND sfa.student_id = ?
");
$stmt->execute([$request_id, $student_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: class_rep_financial_aid');
    exit();
}

$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: class_rep_view_financial_aid?id=' . $request_id);
    exit();
}

function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

function getStatusBadge($status) {
    $badges = [
        'submitted' => 'status-open',
        'under_review' => 'status-progress',
        'approved' => 'status-success',
        'rejected' => 'status-error',
        'disbursed' => 'status-resolved'
    ];
    return $badges[$status] ?? 'status-open';
}

function getUrgencyBadge($urgency) {
    $badges = [
        'low' => 'status-resolved',
        'medium' => 'status-open',
        'high' => 'status-progress',
        'emergency' => 'status-error'
    ];
    return $badges[$urgency] ?? 'status-open';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Financial Aid Request - Class Rep Panel - Isonga RPSU</title>
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
        
        /* Dashboard Grid */
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        .card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-title { font-size: 1.2rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.9rem; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 1rem; }
        
        /* Request Cards */
        .request-card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1rem; box-shadow: var(--shadow); }
        .request-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .request-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .request-meta { display: flex; gap: 1rem; font-size: 0.9rem; color: var(--dark-gray); }
        .request-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
        .amount { font-weight: 700; font-size: 1.1rem; }
        .amount-requested { color: var(--warning); }
        .amount-approved { color: var(--success); }
        
        /* Status badges */
        .status { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .status-success { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-error { background: rgba(220,53,69,0.1); color: var(--danger); }
        
        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: var(--white); border-radius: var(--radius); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--gray); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        .form-control { width: 100%; padding: 0.8rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); }
        .form-control:focus { outline: none; border-color: var(--secondary); }
        textarea.form-control { min-height: 100px; resize: vertical; }
        
        /* File Upload */
        .file-upload { border: 2px dashed var(--gray); border-radius: var(--radius); padding: 1.5rem; text-align: center; margin-bottom: 1rem; }
        .file-upload:hover { border-color: var(--secondary); }
        .file-list { margin-top: 0.5rem; font-size: 0.9rem; color: var(--dark-gray); }
        
        /* Alert */
        .alert { padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; }
        .alert-success { background: rgba(40,167,69,0.1); color: var(--success); border-left-color: var(--success); }
        .alert-error { background: rgba(220,53,69,0.1); color: var(--danger); border-left-color: var(--danger); }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        /* Reuse the same CSS styles from class_rep_financial_aid.php */
        /* Add the detail-grid and other view-specific styles from the regular student view */
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .detail-card { background: var(--white); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); border-left: 4px solid var(--success); }
        .detail-label { font-size: 0.9rem; color: var(--dark-gray); margin-bottom: 0.5rem; }
        .detail-value { font-weight: 600; }
        .document-links { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; }
        .doc-link { display: flex; align-items: center; gap: 0.5rem; padding: 0.8rem 1rem; background: var(--white); border: 1px solid var(--gray); border-radius: var(--radius); text-decoration: none; color: var(--text); transition: var(--transition); }
        .doc-link:hover { border-color: var(--secondary); }
        .amount-requested { color: var(--warning); }
        .amount-approved { color: var(--success); }
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
                <li><a href="class_rep_dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="class_tickets"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_students"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="class_rep_financial_aid" class="active"><i class="fas fa-hand-holding-usd"></i> Financial Aid</a></li>
                <li><a href="rep_meetings"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main">
            <!-- Header -->
            <div class="header">
                <div class="welcome">
                    <h1>Financial Aid Request #<?php echo $request_id; ?>
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p><?php echo safe_display($request['request_title']); ?></p>
                </div>
                <div class="actions">
                    <form method="POST">
                        <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                    <a href="class_rep_financial_aid" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                    <?php if ($request['status'] === 'approved'): ?>
                        <a href="../student/generate_approval_letter?id=<?php echo $request_id; ?>" class="btn btn-success">
                            <i class="fas fa-download"></i> Approval Letter
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Details -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Request Details</h3>
                    <div class="status <?php echo getStatusBadge($request['status']); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                    </div>
                </div>
                
                <div class="detail-grid">
                    <div class="detail-card">
                        <div class="detail-label">Student Information</div>
                        <div class="detail-value"><?php echo safe_display($request['student_name']); ?></div>
                        <div style="font-size: 0.9rem; color: var(--dark-gray);">
                            <?php echo safe_display($request['reg_number']); ?><br>
                            <?php echo safe_display($request['program_name']); ?><br>
                            <?php echo safe_display($request['academic_year']); ?>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">Financial Information</div>
                        <div class="detail-value amount-requested">RWF <?php echo number_format($request['amount_requested'], 2); ?></div>
                        <div class="detail-label">Amount Requested</div>
                        <?php if ($request['amount_approved']): ?>
                            <div class="detail-value amount-approved">RWF <?php echo number_format($request['amount_approved'], 2); ?></div>
                            <div class="detail-label">Amount Approved</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">Request Information</div>
                        <div class="status <?php echo getUrgencyBadge($request['urgency_level']); ?>" style="display: inline-block; margin-bottom: 0.5rem;">
                            <?php echo ucfirst($request['urgency_level']); ?> Urgency
                        </div>
                        <div class="detail-label">Submitted</div>
                        <div class="detail-value"><?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="detail-label">Purpose and Justification</div>
                    <div style="background: var(--light); padding: 1rem; border-radius: var(--radius); white-space: pre-wrap;"><?php echo safe_display($request['purpose']); ?></div>
                </div>
                
                <?php if ($request['review_notes']): ?>
                <div class="form-group">
                    <div class="detail-label">Review Notes</div>
                    <div style="background: var(--light); padding: 1rem; border-radius: var(--radius); white-space: pre-wrap;"><?php echo safe_display($request['review_notes']); ?></div>
                </div>
                <?php endif; ?>
                
                <!-- Documents -->
                <div class="form-group">
                    <div class="detail-label">Attached Documents</div>
                    <div class="document-links">
                        <?php if ($request['request_letter_path']): ?>
                            <a href="<?php echo $request['request_letter_path']; ?>" class="doc-link" target="_blank">
                                <i class="fas fa-file-pdf"></i> Request Letter
                            </a>
                        <?php else: ?>
                            <span class="doc-link" style="background: var(--light); color: var(--dark-gray);">
                                <i class="fas fa-times-circle"></i> No Request Letter
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($request['supporting_docs_path']): ?>
                            <a href="<?php echo $request['supporting_docs_path']; ?>" class="doc-link" target="_blank">
                                <i class="fas fa-file-archive"></i> Supporting Documents
                            </a>
                        <?php else: ?>
                            <span class="doc-link" style="background: var(--light); color: var(--dark-gray);">
                                <i class="fas fa-times-circle"></i> No Supporting Documents
                            </span>
                        <?php endif; ?>

                        <?php if ($request['approval_letter_path']): ?>
                            <a href="<?php echo $request['approval_letter_path']; ?>" class="doc-link" target="_blank" style="border-color: var(--success);">
                                <i class="fas fa-file-contract"></i> Approval Letter
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Review Information -->
                <?php if ($request['reviewed_by']): ?>
                <div class="detail-grid">
                    <div class="detail-card">
                        <div class="detail-label">Reviewed By</div>
                        <div class="detail-value"><?php echo safe_display($request['reviewer_name']); ?></div>
                    </div>
                    
                    <?php if ($request['review_date']): ?>
                    <div class="detail-card">
                        <div class="detail-label">Review Date</div>
                        <div class="detail-value"><?php echo date('F j, Y g:i A', strtotime($request['review_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['disbursement_date']): ?>
                    <div class="detail-card">
                        <div class="detail-label">Disbursement Date</div>
                        <div class="detail-value"><?php echo date('F j, Y g:i A', strtotime($request['disbursement_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>