<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    header('Location: student_login');
    exit();
}

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
    header('Location: class_rep_financial_aid');
    exit();
}

// Handle financial aid request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_financial_aid'])) {
    $request_title = trim($_POST['request_title']);
    $amount_requested = $_POST['amount_requested'];
    $urgency_level = $_POST['urgency_level'];
    $purpose = trim($_POST['purpose']);
    
    // File upload handling
    $supporting_docs_path = null;
    $request_letter_path = null;
    
    if (empty($request_title) || empty($amount_requested) || empty($purpose)) {
        $error_message = "All fields are required.";
    } else {
        try {
            // Handle supporting documents upload
            if (isset($_FILES['supporting_docs']) && $_FILES['supporting_docs']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/supporting_docs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['supporting_docs']['name'], PATHINFO_EXTENSION);
                $file_name = 'support_' . $reg_number . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['supporting_docs']['tmp_name'], $file_path)) {
                    $supporting_docs_path = $file_path;
                } else {
                    throw new Exception("Failed to upload supporting documents.");
                }
            }
            
            // Handle request letter upload
            if (isset($_FILES['request_letter']) && $_FILES['request_letter']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/request_letters/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['request_letter']['name'], PATHINFO_EXTENSION);
                $file_name = 'letter_' . $reg_number . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['request_letter']['tmp_name'], $file_path)) {
                    $request_letter_path = $file_path;
                } else {
                    throw new Exception("Failed to upload request letter.");
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO student_financial_aid 
                (student_id, request_title, request_letter_path, amount_requested, urgency_level, purpose, supporting_docs_path, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted')
            ");
            
            $stmt->execute([
                $student_id, 
                $request_title, 
                $request_letter_path, 
                $amount_requested, 
                $urgency_level, 
                $purpose, 
                $supporting_docs_path
            ]);
            
            $request_id = $pdo->lastInsertId();
            
            $_SESSION['success_message'] = "Financial aid request submitted successfully! Request ID: #$request_id";
            header('Location: class_rep_financial_aid');
            exit();
            
        } catch (PDOException $e) {
            $error_message = "Failed to submit financial aid request. Please try again.";
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Get student's financial aid requests
$requests_stmt = $pdo->prepare("
    SELECT * FROM student_financial_aid 
    WHERE student_id = ? 
    ORDER BY created_at DESC
");
$requests_stmt->execute([$student_id]);
$financial_aid_requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function
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
    <title>Financial Aid - Class Rep Panel - Isonga RPSU</title>
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
                    <h1>Financial Aid Requests
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p><?php echo safe_display($student_name); ?> | <?php echo safe_display($program); ?> | <?php echo safe_display($academic_year); ?></p>
                </div>
                <div class="actions">
                    <form method="POST">
                        <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                    <button class="btn btn-primary" onclick="openRequestModal()"><i class="fas fa-plus"></i> New Request</button>
                </div>
            </div>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Financial Aid Information:</strong> As a class representative, you can submit financial aid requests for your personal needs. This is separate from your class representative duties.
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Statistics -->
            <?php
            $total_requests = count($financial_aid_requests);
            $pending_requests = count(array_filter($financial_aid_requests, function($req) {
                return $req['status'] === 'submitted' || $req['status'] === 'under_review';
            }));
            $approved_requests = count(array_filter($financial_aid_requests, function($req) {
                return $req['status'] === 'approved' || $req['status'] === 'disbursed';
            }));
            $total_approved = array_sum(array_column($financial_aid_requests, 'amount_approved'));
            ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-number"><?php echo $total_requests; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo $pending_requests; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo $approved_requests; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(220,53,69,0.1); color: var(--danger);"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-number">RWF <?php echo number_format($total_approved, 2); ?></div>
                    <div class="stat-label">Total Approved</div>
                </div>
            </div>

            <!-- Financial Aid Requests -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">My Financial Aid Requests</h3>
                </div>
                
                <?php if (empty($financial_aid_requests)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                        <i class="fas fa-hand-holding-usd" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>No financial aid requests yet.</p>
                        <button class="btn btn-primary" onclick="openRequestModal()" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Submit Your First Request
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($financial_aid_requests as $request): ?>
                        <div class="request-card">
                            <div class="request-header">
                                <div>
                                    <div class="request-title"><?php echo safe_display($request['request_title']); ?></div>
                                    <div class="request-meta">
                                        <span>Submitted: <?php echo date('M j, Y', strtotime($request['created_at'])); ?></span>
                                        <span>Urgency: <span class="status <?php echo getUrgencyBadge($request['urgency_level']); ?>"><?php echo ucfirst($request['urgency_level']); ?></span></span>
                                    </div>
                                </div>
                                <div class="status <?php echo getStatusBadge($request['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                </div>
                            </div>
                            
                            <p style="margin-bottom: 1rem; color: var(--text);"><?php echo safe_display($request['purpose']); ?></p>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <span class="amount amount-requested">RWF <?php echo number_format($request['amount_requested'], 2); ?></span>
                                    <?php if ($request['amount_approved']): ?>
                                        <span style="margin: 0 0.5rem;">→</span>
                                        <span class="amount amount-approved">RWF <?php echo number_format($request['amount_approved'], 2); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="request-actions">
                                    <a href="class_rep_view_financial_aid?id=<?php echo $request['id']; ?>" class="btn btn-secondary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($request['status'] === 'approved'): ?>
                                        <a href="../student/generate_approval_letter?id=<?php echo $request['id']; ?>" class="btn btn-success">
                                            <i class="fas fa-download"></i> Approval Letter
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Request Modal -->
    <div id="requestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Submit Financial Aid Request</h3>
                <button onclick="closeRequestModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Request Title</label>
                        <input type="text" name="request_title" class="form-control" placeholder="e.g., Tuition Fee Assistance" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Amount Requested (RWF)</label>
                        <input type="number" name="amount_requested" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Urgency Level</label>
                        <select name="urgency_level" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Purpose and Justification</label>
                        <textarea name="purpose" class="form-control" placeholder="Explain why you need financial assistance..." required rows="4"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Request Letter (Optional)</label>
                        <div class="file-upload">
                            <input type="file" name="request_letter" id="request_letter" accept=".pdf,.doc,.docx,.txt" style="display: none;">
                            <label for="request_letter" style="cursor: pointer;">
                                <i class="fas fa-upload" style="font-size: 2rem; margin-bottom: 0.5rem; color: var(--dark-gray);"></i>
                                <p>Click to upload request letter</p>
                                <p style="font-size: 0.8rem; color: var(--dark-gray);">PDF, DOC, DOCX, TXT (Max: 5MB)</p>
                            </label>
                            <div id="request_letter_name" class="file-list"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Supporting Documents (Optional)</label>
                        <div class="file-upload">
                            <input type="file" name="supporting_docs" id="supporting_docs" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="display: none;">
                            <label for="supporting_docs" style="cursor: pointer;">
                                <i class="fas fa-file-upload" style="font-size: 2rem; margin-bottom: 0.5rem; color: var(--dark-gray);"></i>
                                <p>Click to upload supporting documents</p>
                                <p style="font-size: 0.8rem; color: var(--dark-gray);">PDF, Images, DOC (Max: 10MB)</p>
                            </label>
                            <div id="supporting_docs_name" class="file-list"></div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeRequestModal()">Cancel</button>
                        <button type="submit" name="submit_financial_aid" class="btn btn-primary" style="flex: 1;">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openRequestModal() { document.getElementById('requestModal').style.display = 'flex'; }
        function closeRequestModal() { document.getElementById('requestModal').style.display = 'none'; }
        
        // File input display
        document.getElementById('request_letter').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'No file chosen';
            document.getElementById('request_letter_name').textContent = fileName;
        });
        
        document.getElementById('supporting_docs').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'No file chosen';
            document.getElementById('supporting_docs_name').textContent = fileName;
        });
        
        window.onclick = function(event) {
            if (event.target === document.getElementById('requestModal')) {
                closeRequestModal();
            }
        }

        <?php if (isset($error_message)): ?>
            document.addEventListener('DOMContentLoaded', openRequestModal);
        <?php endif; ?>
    </script>
</body>
</html>