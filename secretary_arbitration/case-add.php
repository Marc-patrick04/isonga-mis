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
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_case'])) {
    $case_number = $_POST['case_number'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $case_type = $_POST['case_type'];
    $complainant_name = $_POST['complainant_name'];
    $respondent_name = $_POST['respondent_name'];
    $complainant_contact = $_POST['complainant_contact'] ?? '';
    $respondent_contact = $_POST['respondent_contact'] ?? '';
    $complainant_id = $_POST['complainant_id'] ?? null;
    $respondent_id = $_POST['respondent_id'] ?? null;
    $priority = $_POST['priority'];
    $status = $_POST['status'];
    $filing_date = $_POST['filing_date'];
    $assigned_to = $_POST['assigned_to'] ?? null;

    try {
        // Generate case number if not provided
        if (empty($case_number)) {
            $year = date('Y');
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as case_count 
                FROM arbitration_cases 
                WHERE YEAR(created_at) = ?
            ");
            $stmt->execute([$year]);
            $case_count = $stmt->fetch(PDO::FETCH_ASSOC)['case_count'];
            $case_number = 'ARB-' . $year . '-' . str_pad($case_count + 1, 4, '0', STR_PAD_LEFT);
        }

        // Insert new case
        $stmt = $pdo->prepare("
            INSERT INTO arbitration_cases (
                case_number, title, description, case_type, 
                complainant_id, respondent_id, complainant_name, respondent_name,
                complainant_contact, respondent_contact, priority, status, 
                filing_date, assigned_to, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $case_number, $title, $description, $case_type,
            $complainant_id, $respondent_id, $complainant_name, $respondent_name,
            $complainant_contact, $respondent_contact, $priority, $status,
            $filing_date, $assigned_to, $user_id
        ]);

        $new_case_id = $pdo->lastInsertId();

        // If case is assigned, update assignment tracking
        if ($assigned_to) {
            $stmt = $pdo->prepare("
                UPDATE arbitration_cases 
                SET assigned_by = ?, assigned_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $new_case_id]);
        }

        $_SESSION['success_message'] = "Case created successfully! Case Number: " . $case_number;
        header("Location: case-view.php?id=" . $new_case_id);
        exit();

    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error creating case: " . $e->getMessage();
    }
}

// Get available arbitrators for assignment
$arbitrators = [];
try {
    $stmt = $pdo->query("
        SELECT id, full_name, role 
        FROM users 
        WHERE role IN ('president_arbitration', 'vice_president_arbitration', 'advisor_arbitration')
        AND status = 'active'
        ORDER BY full_name
    ");
    $arbitrators = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Arbitrators fetch error: " . $e->getMessage());
}

// Get recent cases for reference
$recent_cases = [];
try {
    $stmt = $pdo->query("
        SELECT case_number, title, case_type, filing_date 
        FROM arbitration_cases 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent cases fetch error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Case - Arbitration Secretary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        /* Include all CSS styles from documents.php */
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

        /* Include all other CSS styles from documents.php */
        .header { background: var(--white); box-shadow: var(--shadow-sm); padding: 1rem 0; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--medium-gray); height: 80px; display: flex; align-items: center; }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 1.5rem; width: 100%; }
        .logo-section { display: flex; align-items: center; gap: 0.75rem; }
        .logos { display: flex; gap: 0.75rem; align-items: center; }
        .logo { height: 40px; width: auto; }
        .brand-text h1 { font-size: 1.3rem; font-weight: 700; color: var(--primary-blue); }
        .user-menu { display: flex; align-items: center; gap: 1.5rem; }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .user-avatar { width: 50px; height: 50px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1.1rem; border: 3px solid var(--medium-gray); overflow: hidden; position: relative; transition: var(--transition); }
        .user-avatar:hover { border-color: var(--primary-blue); transform: scale(1.05); }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: var(--text-dark); font-size: 0.95rem; }
        .user-role { font-size: 0.8rem; color: var(--dark-gray); }
        .header-actions { display: flex; align-items: center; gap: 0.75rem; }
        .icon-btn { width: 44px; height: 44px; border: none; background: var(--light-gray); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-dark); cursor: pointer; transition: var(--transition); position: relative; font-size: 1.1rem; }
        .icon-btn:hover { background: var(--primary-blue); color: white; transform: translateY(-2px); }
        .notification-badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: 600; border: 2px solid var(--white); }
        .logout-btn { background: var(--gradient-primary); color: white; padding: 0.6rem 1.2rem; border-radius: 20px; text-decoration: none; font-weight: 600; transition: var(--transition); font-size: 0.85rem; border: none; cursor: pointer; }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .dashboard-container { display: grid; grid-template-columns: 220px 1fr; min-height: calc(100vh - 80px); }
        .sidebar { background: var(--white); border-right: 1px solid var(--medium-gray); padding: 1.5rem 0; position: sticky; top: 60px; height: calc(100vh - 60px); overflow-y: auto; }
        .sidebar-menu { list-style: none; }
        .menu-item { margin-bottom: 0.25rem; }
        .menu-item a { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1.5rem; color: var(--text-dark); text-decoration: none; transition: var(--transition); border-left: 3px solid transparent; font-size: 0.85rem; }
        .menu-item a:hover, .menu-item a.active { background: var(--light-blue); border-left-color: var(--primary-blue); color: var(--primary-blue); }
        .menu-item i { width: 16px; text-align: center; font-size: 0.9rem; }
        .menu-badge { background: var(--danger); color: white; border-radius: 10px; padding: 0.1rem 0.4rem; font-size: 0.7rem; font-weight: 600; margin-left: auto; }
        .main-content { padding: 1.5rem; overflow-y: auto; height: calc(100vh - 80px); }
        .page-header { display: flex; justify-content: between; align-items: center; margin-bottom: 1.5rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
        .page-actions { display: flex; gap: 1rem; }
        .btn { padding: 0.6rem 1.2rem; border-radius: var(--border-radius); text-decoration: none; font-weight: 600; font-size: 0.85rem; border: none; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: var(--gradient-primary); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; border: 1px solid var(--primary-blue); color: var(--primary-blue); }
        .btn-outline:hover { background: var(--primary-blue); color: white; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        .card { background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--medium-gray); display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { font-size: 1rem; font-weight: 600; color: var(--text-dark); }
        .card-body { padding: 1.5rem; }
        .alert { padding: 0.75rem 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; border-left: 4px solid; font-size: 0.8rem; }
        .alert-success { background: #d4edda; color: #155724; border-left-color: var(--success); }
        .alert-error { background: #f8d7da; color: #721c24; border-left-color: var(--danger); }
        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-dark); }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; transition: var(--transition); }
        .form-control:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1); }
        .form-select { width: 100%; padding: 0.75rem; border: 1px solid var(--medium-gray); border-radius: var(--border-radius); background: var(--white); color: var(--text-dark); font-size: 0.85rem; }
        .form-textarea { min-height: 120px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-help { font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem; }
        .required::after { content: " *"; color: var(--danger); }
        .case-form { max-width: 100%; }
        .section-title { font-size: 1.1rem; font-weight: 600; color: var(--primary-blue); margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--light-blue); }
        .recent-cases { background: var(--light-blue); padding: 1rem; border-radius: var(--border-radius); margin-top: 2rem; }
        .recent-case-item { padding: 0.75rem; border-bottom: 1px solid var(--medium-gray); }
        .recent-case-item:last-child { border-bottom: none; }
        .case-number { font-weight: 600; color: var(--primary-blue); }
        .case-title { color: var(--text-dark); font-size: 0.85rem; }
        .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--medium-gray); }
        @media (max-width: 768px) {
            .dashboard-container { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .page-header { flex-direction: column; gap: 1rem; align-items: start; }
            .page-actions { width: 100%; justify-content: space-between; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
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
                    <h1>Isonga - Arbitration</h1>
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
                        <div class="user-role">Arbitration Secretary</div>
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
                 <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php"class="active">
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php" >
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
                    <a href="reports.php">
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
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Add New Arbitration Case</h1>
                    <p style="color: var(--dark-gray); font-size: 0.9rem; margin-top: 0.25rem;">
                        Create a new arbitration case and manage the dispute resolution process
                    </p>
                </div>
                <div class="page-actions">
                    <a href="cases.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Cases
                    </a>
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
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Case Information</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="case-form">
                        <!-- Basic Case Information -->
                        <div class="section-title">Basic Information</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Case Number</label>
                                <input type="text" name="case_number" class="form-control" placeholder="Leave blank to auto-generate">
                                <div class="form-help">If left blank, system will generate case number automatically</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Filing Date</label>
                                <input type="date" name="filing_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Case Title</label>
                            <input type="text" name="title" class="form-control" placeholder="Enter descriptive case title" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Case Description</label>
                            <textarea name="description" class="form-control form-textarea" placeholder="Provide detailed description of the case, including nature of dispute, background information, and key issues..." required></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Case Type</label>
                                <select name="case_type" class="form-select" required>
                                    <option value="student_dispute">Student Dispute</option>
                                    <option value="committee_conflict">Committee Conflict</option>
                                    <option value="election_dispute">Election Dispute</option>
                                    <option value="disciplinary">Disciplinary Matter</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Priority</label>
                                <select name="priority" class="form-select" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Initial Status</label>
                                <select name="status" class="form-select" required>
                                    <option value="filed" selected>Filed</option>
                                    <option value="under_review">Under Review</option>
                                    <option value="hearing_scheduled">Hearing Scheduled</option>
                                    <option value="mediation">Mediation</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Assign To</label>
                                <select name="assigned_to" class="form-select">
                                    <option value="">Not Assigned</option>
                                    <?php foreach ($arbitrators as $arbitrator): ?>
                                        <option value="<?php echo $arbitrator['id']; ?>">
                                            <?php echo htmlspecialchars($arbitrator['full_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $arbitrator['role'])); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-help">Optional - can assign later</div>
                            </div>
                        </div>

                        <!-- Parties Information -->
                        <div class="section-title" style="margin-top: 2rem;">Parties Information</div>

                        <div class="form-row">
                            <!-- Complainant Information -->
                            <div class="form-group">
                                <label class="form-label required">Complainant Name</label>
                                <input type="text" name="complainant_name" class="form-control" placeholder="Full name of complainant" required>
                                
                                <label class="form-label" style="margin-top: 1rem;">Complainant Contact</label>
                                <input type="text" name="complainant_contact" class="form-control" placeholder="Email or phone number">
                                
                                <label class="form-label" style="margin-top: 1rem;">Complainant ID (Optional)</label>
                                <input type="text" name="complainant_id" class="form-control" placeholder="Student ID or registration number">
                            </div>

                            <!-- Respondent Information -->
                            <div class="form-group">
                                <label class="form-label required">Respondent Name</label>
                                <input type="text" name="respondent_name" class="form-control" placeholder="Full name of respondent" required>
                                
                                <label class="form-label" style="margin-top: 1rem;">Respondent Contact</label>
                                <input type="text" name="respondent_contact" class="form-control" placeholder="Email or phone number">
                                
                                <label class="form-label" style="margin-top: 1rem;">Respondent ID (Optional)</label>
                                <input type="text" name="respondent_id" class="form-control" placeholder="Student ID or registration number">
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <a href="cases.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" name="add_case" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Case
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Cases Reference -->
            <?php if (!empty($recent_cases)): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Recent Cases for Reference</h3>
                </div>
                <div class="card-body">
                    <div class="recent-cases">
                        <?php foreach ($recent_cases as $case): ?>
                            <div class="recent-case-item">
                                <div class="case-number"><?php echo htmlspecialchars($case['case_number']); ?></div>
                                <div class="case-title"><?php echo htmlspecialchars($case['title']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                    Type: <?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?> • 
                                    Filed: <?php echo date('M j, Y', strtotime($case['filing_date'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
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

        // Form validation and enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.case-form');
            
            form.addEventListener('submit', function(e) {
                let isValid = true;
                const requiredFields = form.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = 'var(--danger)';
                    } else {
                        field.style.borderColor = '';
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields marked with *');
                }
            });

            // Auto-generate case number suggestion
            const caseNumberField = document.querySelector('input[name="case_number"]');
            caseNumberField.addEventListener('focus', function() {
                if (!this.value) {
                    const year = new Date().getFullYear();
                    this.placeholder = `ARB-${year}-XXXX (auto-generated if empty)`;
                }
            });

            // Character counter for description
            const descriptionField = document.querySelector('textarea[name="description"]');
            const charCount = document.createElement('div');
            charCount.className = 'form-help';
            charCount.style.textAlign = 'right';
            descriptionField.parentNode.appendChild(charCount);

            descriptionField.addEventListener('input', function() {
                const count = this.value.length;
                charCount.textContent = `${count} characters`;
                
                if (count < 50) {
                    charCount.style.color = 'var(--danger)';
                } else if (count < 200) {
                    charCount.style.color = 'var(--warning)';
                } else {
                    charCount.style.color = 'var(--success)';
                }
            });

            // Trigger initial count
            descriptionField.dispatchEvent(new Event('input'));
        });
    </script>
</body>
</html>