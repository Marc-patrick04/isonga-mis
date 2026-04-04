<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login');
    exit();
}

// Redirect class representatives to their dedicated dashboard
if ($_SESSION['is_class_rep'] ?? 0) {
    header('Location: class_rep_dashboard');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: financial_aid');
    exit();
}

$request_id = $_GET['id'];
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

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
    header('Location: financial_aid');
    exit();
}

$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: view_financial_aid?id=' . $request_id);
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
    <title>Financial Aid Request - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --booking-blue: #003b95;
            --booking-blue-light: #006ce4;
            --booking-green: #00a699;
            --booking-orange: #ff5a5f;
            --booking-yellow: #ffb400;
            --booking-gray-50: #f7f7f7;
            --booking-gray-100: #ebebeb;
            --booking-gray-200: #d8d8d8;
            --booking-gray-300: #b0b0b0;
            --booking-gray-400: #717171;
            --booking-gray-500: #2d2d2d;
            --booking-white: #ffffff;
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.16);
            --transition: all 0.2s ease;
        }

        [data-theme="dark"] {
            --booking-gray-50: #1a1a1a;
            --booking-gray-100: #2d2d2d;
            --booking-gray-200: #404040;
            --booking-gray-300: #666666;
            --booking-gray-400: #999999;
            --booking-gray-500: #ffffff;
            --booking-white: #2d2d2d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
            padding: 0 2rem;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

/* Logo Styles */
.logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
}

.logo-image {
    height: 36px; /* Adjust based on your logo's aspect ratio */
    width: auto;
    object-fit: contain;
}

.logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-blue);
    letter-spacing: -0.5px;
}

/* Optional: Different logo for dark theme */
[data-theme="dark"] .logo-text {
    color: white; /* Or keep it blue for consistency */
}

[data-theme="dark"] .logo-image {
    filter: brightness(1.1); /* Slightly brighten logo for dark theme */
}

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--booking-gray-50);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .theme-toggle-btn {
            background: none;
            border: 1px solid var(--booking-gray-200);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .theme-toggle-btn:hover {
            border-color: var(--booking-blue);
            color: var(--booking-blue);
        }

        /* Navigation */
        .nav-container {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-gray-100);
        }

        .main-nav {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .nav-links {
            display: flex;
            gap: 0;
            list-style: none;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--booking-gray-500);
            font-weight: 500;
            font-size: 0.9rem;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
        }

        .nav-link:hover {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue-light);
        }

        .nav-link.active {
            color: var(--booking-blue);
            border-bottom-color: var(--booking-blue);
            font-weight: 600;
        }

        .nav-link i {
            font-size: 0.85rem;
            width: 18px;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Page Header */
        .page-header {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--booking-blue);
        }

        .page-description {
            color: var(--booking-gray-400);
            margin-bottom: 1.5rem;
        }

        .header-actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .dashboard-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            padding: 1.5rem 1.5rem 0;
            border-bottom: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--booking-blue);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .detail-card {
            background: var(--booking-gray-50);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--booking-blue);
        }

        .detail-card.amount {
            border-left-color: var(--booking-green);
        }

        .detail-card.urgency {
            border-left-color: var(--booking-orange);
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .detail-subtext {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
        }

        .status-open { background: #e6ffe6; color: var(--booking-green); }
        .status-progress { background: #fff8e6; color: var(--booking-orange); }
        .status-success { background: #e6fff6; color: #00b894; }
        .status-error { background: #ffe6e6; color: var(--booking-orange); }
        .status-resolved { background: #f0f0f0; color: var(--booking-gray-400); }

        /* Amount Display */
        .amount-display {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .amount-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--booking-white);
            border-radius: var(--border-radius);
            border: 1px solid var(--booking-gray-100);
        }

        .amount-label {
            font-size: 0.9rem;
            color: var(--booking-gray-500);
        }

        .amount-value {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .amount-value.requested {
            color: var(--booking-orange);
        }

        .amount-value.approved {
            color: var(--booking-green);
        }

        /* Content Section */
        .content-section {
            background: var(--booking-white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--booking-gray-100);
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--booking-gray-500);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--booking-blue);
        }

        .section-content {
            color: var(--booking-gray-500);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        /* Document Links */
        .document-links {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .doc-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: var(--booking-gray-50);
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--booking-gray-500);
            transition: var(--transition);
        }

        .doc-link:hover {
            background: var(--booking-white);
            border-color: var(--booking-blue-light);
            color: var(--booking-blue);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .doc-link i {
            font-size: 1rem;
        }

        /* Timeline */
        .timeline {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--booking-blue);
        }

        .timeline-item.disbursed {
            border-left-color: var(--booking-green);
        }

        .timeline-item.rejected {
            border-left-color: var(--booking-orange);
        }

        .timeline-icon {
            width: 40px;
            height: 40px;
            background: var(--booking-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--booking-blue);
            border: 1px solid var(--booking-gray-200);
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .timeline-meta {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--booking-blue);
            color: white;
            border: 1px solid var(--booking-blue);
        }

        .btn-primary:hover {
            background: var(--booking-blue-light);
            border-color: var(--booking-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 107, 228, 0.2);
        }

        .btn-secondary {
            background: var(--booking-white);
            color: var(--booking-gray-500);
            border: 1px solid var(--booking-gray-200);
        }

        .btn-secondary:hover {
            background: var(--booking-gray-50);
            border-color: var(--booking-gray-300);
        }

        .btn-success {
            background: var(--booking-green);
            color: white;
            border: 1px solid var(--booking-green);
        }

        .btn-success:hover {
            background: #00b894;
            border-color: #00b894;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--booking-gray-400);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0 1rem;
            }
            
            .main-nav {
                padding: 0 1rem;
            }
            
            .nav-links {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 0.5rem;
            }
            
            .nav-link {
                padding: 1rem;
                font-size: 0.85rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                padding: 1.5rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .header-actions-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .document-links {
                flex-direction: column;
            }
            
            .doc-link {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .user-name, .user-role {
                display: none;
            }
            
            .timeline-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .timeline-icon {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <!-- Header -->
    <header class="header">
<a href="dashboard" class="logo">
    <img src="../assets/images/logo.png" alt="Isonga Logo" class="logo-image">
    <div class="logo-text">Isonga</div>
</a>
        
<!-- Add this to the header-actions div in dashboard.php -->
<div class="header-actions">
    <form method="POST" style="margin: 0;">
        <button type="submit" name="toggle_theme" class="theme-toggle-btn" title="Toggle Theme">
            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
        </button>
    </form>
    
    <!-- Logout Button - Add this -->
    <a href="../auth/logout" class="logout-btn" title="Logout">
        <i class="fas fa-sign-out-alt"></i>
    </a>
    
    <div class="user-menu">
        <div class="user-avatar">
            <?php echo strtoupper(substr($student_name, 0, 1)); ?>
        </div>
        <div class="user-info">
            <span class="user-name"><?php echo safe_display(explode(' ', $student_name)[0]); ?></span>
            <span class="user-role">Student</span>
        </div>
    </div>
</div>
    </header>
    <!-- Navigation -->
    <nav class="nav-container">
        <div class="main-nav">
            <ul class="nav-links">
                <li class="nav-item">
                    <a href="dashboard" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tickets" class="nav-link">
                        <i class="fas fa-ticket-alt"></i>
                        My Tickets
                    </a>
                </li>
                <li class="nav-item">
                    <a href="financial_aid" class="nav-link active">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile" class="nav-link">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </a>
                </li>
                <?php if ($is_class_rep): ?>
                <li class="nav-item">
                    <a href="class_rep_dashboard" class="nav-link">
                        <i class="fas fa-users"></i>
                        Class Rep
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-actions-row">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid Request #<?php echo $request_id; ?>
                    </h1>
                    <p class="page-description"><?php echo safe_display($request['request_title']); ?></p>
                    
                    <div style="display: flex; align-items: center; gap: 1rem; margin-top: 1rem;">
                        <span class="status-badge <?php echo getStatusBadge($request['status']); ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                        </span>
                        <span class="status-badge <?php echo getUrgencyBadge($request['urgency_level']); ?>">
                            <i class="fas fa-clock"></i>
                            <?php echo ucfirst($request['urgency_level']); ?> Urgency
                        </span>
                    </div>
                </div>
                
                <div style="display: flex; gap: 0.75rem;">
                    <a href="financial_aid" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to List
                    </a>
                    <?php if ($request['status'] === 'approved'): ?>
                        <a href="generate_approval_letter?id=<?php echo $request_id; ?>" class="btn btn-success">
                            <i class="fas fa-download"></i>
                            Approval Letter
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Request Overview -->
        <div class="dashboard-grid">
            <!-- Student Information -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-user-graduate"></i>
                        Student Information
                    </h3>
                </div>
                <div class="card-body">
                    <div class="detail-card">
                        <div class="detail-label">Student Name</div>
                        <div class="detail-value"><?php echo safe_display($request['student_name']); ?></div>
                        
                        <div class="detail-label">Registration Number</div>
                        <div class="detail-value"><?php echo safe_display($request['reg_number']); ?></div>
                        
                        <div class="detail-label">Academic Details</div>
                        <div class="detail-subtext">
                            <?php echo safe_display($request['program_name']); ?><br>
                            <?php echo safe_display($request['department_name']); ?><br>
                            Year <?php echo safe_display($request['academic_year']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Information -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-money-bill-wave"></i>
                        Financial Information
                    </h3>
                </div>
                <div class="card-body">
                    <div class="amount-display">
                        <div class="amount-item">
                            <span class="amount-label">Amount Requested</span>
                            <span class="amount-value requested"><?php echo number_format($request['amount_requested'], 2); ?> Rwf</span>
                        </div>
                        <?php if ($request['amount_approved']): ?>
                            <div class="amount-item">
                                <span class="amount-label">Amount Approved</span>
                                <span class="amount-value approved"><?php echo number_format($request['amount_approved'], 2); ?> Rwf</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Request Timeline -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        Request Timeline
                    </h3>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">Request Submitted</div>
                                <div class="timeline-meta">
                                    <?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($request['review_date']): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <i class="fas fa-search"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Under Review</div>
                                    <div class="timeline-meta">
                                        <?php echo date('F j, Y g:i A', strtotime($request['review_date'])); ?><br>
                                        <?php if ($request['reviewer_name']): ?>
                                            Reviewed by: <?php echo safe_display($request['reviewer_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($request['status'] === 'approved' || $request['status'] === 'rejected'): ?>
                            <div class="timeline-item <?php echo $request['status']; ?>">
                                <div class="timeline-icon">
                                    <i class="fas fa-<?php echo $request['status'] === 'approved' ? 'check-circle' : 'times-circle'; ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Request <?php echo ucfirst($request['status']); ?></div>
                                    <div class="timeline-meta">
                                        <?php if ($request['review_date']): ?>
                                            <?php echo date('F j, Y g:i A', strtotime($request['review_date'])); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($request['disbursement_date']): ?>
                            <div class="timeline-item disbursed">
                                <div class="timeline-icon">
                                    <i class="fas fa-money-check-alt"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Funds Disbursed</div>
                                    <div class="timeline-meta">
                                        <?php echo date('F j, Y g:i A', strtotime($request['disbursement_date'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purpose and Justification -->
        <div class="content-section">
            <h3 class="section-title">
                <i class="fas fa-file-alt"></i>
                Purpose and Justification
            </h3>
            <div class="section-content">
                <?php echo safe_display($request['purpose']); ?>
            </div>
        </div>

        <!-- Review Notes -->
        <?php if ($request['review_notes']): ?>
        <div class="content-section">
            <h3 class="section-title">
                <i class="fas fa-comment-alt"></i>
                Review Notes
            </h3>
            <div class="section-content">
                <?php echo safe_display($request['review_notes']); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attached Documents -->
        <div class="content-section">
            <h3 class="section-title">
                <i class="fas fa-paperclip"></i>
                Attached Documents
            </h3>
            <div class="document-links">
                <?php if ($request['request_letter_path']): ?>
                    <a href="<?php echo $request['request_letter_path']; ?>" class="doc-link" target="_blank">
                        <i class="fas fa-file-pdf"></i>
                        Request Letter
                    </a>
                <?php else: ?>
                    <div style="color: var(--booking-gray-400); padding: 1rem;">
                        <i class="fas fa-file-excel"></i>
                        No request letter attached
                    </div>
                <?php endif; ?>
                
                <?php if ($request['supporting_docs_path']): ?>
                    <a href="<?php echo $request['supporting_docs_path']; ?>" class="doc-link" target="_blank">
                        <i class="fas fa-file-archive"></i>
                        Supporting Documents
                    </a>
                <?php else: ?>
                    <div style="color: var(--booking-gray-400); padding: 1rem;">
                        <i class="fas fa-file-excel"></i>
                        No supporting documents attached
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Review Information -->
        <?php if ($request['reviewed_by']): ?>
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-user-tie"></i>
                        Review Information
                    </h3>
                </div>
                <div class="card-body">
                    <div class="detail-grid">
                        <div class="detail-card">
                            <div class="detail-label">Reviewed By</div>
                            <div class="detail-value"><?php echo safe_display($request['reviewer_name']); ?></div>
                        </div>
                        
                        <?php if ($request['review_date']): ?>
                        <div class="detail-card">
                            <div class="detail-label">Review Date</div>
                            <div class="detail-value"><?php echo date('F j, Y', strtotime($request['review_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($request['disbursement_date']): ?>
                        <div class="detail-card">
                            <div class="detail-label">Disbursement Date</div>
                            <div class="detail-value"><?php echo date('F j, Y', strtotime($request['disbursement_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        // Add smooth scroll animation
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add loading animation to cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe cards
        document.querySelectorAll('.dashboard-card, .content-section, .timeline-item').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(card);
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Handle document link clicks
        document.querySelectorAll('.doc-link').forEach(link => {
            link.addEventListener('click', function(e) {
                if (this.getAttribute('href') === '#') {
                    e.preventDefault();
                    alert('No document attached.');
                }
            });
        });
    </script>
</body>
</html>