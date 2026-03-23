<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: action-funding.php');
    exit();
}

$request_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Get budget request details
try {
    $stmt = $pdo->prepare("
        SELECT cbr.*, cm.name as committee_member_name, cm.role,
               u.full_name as requester_name, u.email as requester_email, u.phone as requester_phone
        FROM committee_budget_requests cbr
        LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
        LEFT JOIN users u ON cbr.requested_by = u.id
        WHERE cbr.id = ? AND cbr.requested_by = ?
    ");
    $stmt->execute([$request_id, $user_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        die('Request not found or access denied');
    }
    
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

// Get messages count for sidebar
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
} catch (PDOException $e) {
    $unread_messages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Budget Request - Isonga RPSU</title>
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
            top: 60px;
            height: calc(100vh - 60px);
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
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

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--light-blue);
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
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-group {
            margin-bottom: 1rem;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-draft { background: #e9ecef; color: #6c757d; }
        .status-submitted { background: #fff3cd; color: var(--warning); }
        .status-under_review { background: #cce7ff; color: var(--primary-blue); }
        .status-approved_by_finance { background: #d4edda; color: var(--success); }
        .status-approved_by_president { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }
        .status-funded { background: #d1ecf1; color: #0c5460; }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--medium-gray);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-blue);
            border: 2px solid var(--white);
            box-shadow: 0 0 0 2px var(--primary-blue);
        }

        .timeline-item.completed::before {
            background: var(--success);
            box-shadow: 0 0 0 2px var(--success);
        }

        .timeline-item.pending::before {
            background: var(--warning);
            box-shadow: 0 0 0 2px var(--warning);
        }

        .timeline-date {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .file-download {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
        }

        .file-download:hover {
            background: var(--light-gray);
            border-color: var(--primary-blue);
        }

        .file-icon {
            width: 40px;
            height: 40px;
            background: var(--light-blue);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-blue);
            font-size: 1.2rem;
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .file-size {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

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
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
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
                        <div class="user-role">Arbitration President</div>
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
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>Arbitration Cases</span>
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
                    <a href="action-funding.php" class="active">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action & Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="election_committee.php">
                        <i class="fas fa-users-cog"></i>
                        <span>Election Committee</span>
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
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Budget Request #<?php echo $request['id']; ?></h1>
                    <p>View detailed information about your funding request</p>
                </div>
                <div class="page-actions">
                    <a href="action-funding.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Requests
                    </a>
                    <?php if ($request['status'] === 'approved_by_president'): ?>
                        <a href="generate_approval_letter.php?id=<?php echo $request_id; ?>" class="btn btn-success">
                            <i class="fas fa-file-pdf"></i> Download Approval Letter
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Details -->
            <div class="card">
                <div class="card-header">
                    <h3>Request Information</h3>
                    <span class="status-badge status-<?php echo $request['status']; ?>">
                        <?php echo str_replace('_', ' ', $request['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div>
                            <div class="info-group">
                                <div class="info-label">Request Title</div>
                                <div class="info-value"><?php echo htmlspecialchars($request['request_title']); ?></div>
                            </div>
                            <div class="info-group">
                                <div class="info-label">Purpose</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($request['purpose'])); ?></div>
                            </div>
                            <div class="info-group">
                                <div class="info-label">Requested Amount</div>
                                <div class="info-value">RWF <?php echo number_format($request['requested_amount'], 2); ?></div>
                            </div>
                            <?php if ($request['approved_amount']): ?>
                            <div class="info-group">
                                <div class="info-label">Approved Amount</div>
                                <div class="info-value">RWF <?php echo number_format($request['approved_amount'], 2); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="info-group">
                                <div class="info-label">Request Date</div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($request['request_date'])); ?></div>
                            </div>
                            <div class="info-group">
                                <div class="info-label">Requester</div>
                                <div class="info-value"><?php echo htmlspecialchars($request['requester_name']); ?></div>
                            </div>
                            <div class="info-group">
                                <div class="info-label">Position</div>
                                <div class="info-value"><?php echo str_replace('_', ' ', $request['role']); ?></div>
                            </div>
                            <div class="info-group">
                                <div class="info-label">Contact</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($request['requester_email']); ?><br>
                                    <?php echo htmlspecialchars($request['requester_phone']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Plan File -->
                    <?php if (!empty($request['action_plan_file_path'])): ?>
                    <div class="info-group">
                        <div class="info-label">Action Plan Document</div>
                        <a href="../<?php echo $request['action_plan_file_path']; ?>" class="file-download" target="_blank">
                            <div class="file-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <div class="file-info">
                                <div class="file-name">Download Action Plan</div>
                                <div class="file-size">Click to view uploaded document</div>
                            </div>
                            <i class="fas fa-download"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Approval Timeline -->
            <div class="card">
                <div class="card-header">
                    <h3>Approval Timeline</h3>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <!-- Submission -->
                        <div class="timeline-item completed">
                            <div class="timeline-date"><?php echo date('F j, Y', strtotime($request['request_date'])); ?></div>
                            <div class="timeline-title">Request Submitted</div>
                            <div class="timeline-content">Budget request was submitted for review</div>
                        </div>

                        <!-- Finance Approval -->
                        <div class="timeline-item <?php echo $request['finance_approval_date'] ? 'completed' : 'pending'; ?>">
                            <div class="timeline-date">
                                <?php echo $request['finance_approval_date'] ? date('F j, Y', strtotime($request['finance_approval_date'])) : 'Pending'; ?>
                            </div>
                            <div class="timeline-title">Finance Committee Review</div>
                            <div class="timeline-content">
                                <?php if ($request['finance_approval_date']): ?>
                                    Approved by Finance Committee
                                    <?php if ($request['finance_approval_notes']): ?>
                                        <br><em><?php echo htmlspecialchars($request['finance_approval_notes']); ?></em>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Awaiting finance committee review
                                <?php endif; ?>
                            </div>
                        </div>

<!-- President Approval -->
<div class="timeline-item <?php echo ($request['status'] === 'approved_by_president' || $request['status'] === 'funded' || $request['president_approval_date']) ? 'completed' : 'pending'; ?>">
    <div class="timeline-date">
        <?php 
        if ($request['status'] === 'approved_by_president' || $request['status'] === 'funded') {
            echo $request['president_approval_date'] 
                ? date('F j, Y', strtotime($request['president_approval_date'])) 
                : date('F j, Y', strtotime($request['updated_at']));
        } else {
            echo 'Pending';
        }
        ?>
    </div>
    <div class="timeline-title">President's Office Review</div>
    <div class="timeline-content">
        <?php if ($request['status'] === 'approved_by_president' || $request['status'] === 'funded'): ?>
            Approved by President's Office
            <?php if ($request['president_approval_notes']): ?>
                <br><em><?php echo htmlspecialchars($request['president_approval_notes']); ?></em>
            <?php endif; ?>
        <?php else: ?>
            Awaiting president's office review
        <?php endif; ?>
    </div>
</div>

                        <!-- Funding -->
                        <div class="timeline-item <?php echo $request['status'] === 'funded' ? 'completed' : 'pending'; ?>">
                            <div class="timeline-date">
                                <?php echo $request['status'] === 'funded' ? 'Completed' : 'Pending'; ?>
                            </div>
                            <div class="timeline-title">Fund Disbursement</div>
                            <div class="timeline-content">
                                <?php if ($request['status'] === 'funded'): ?>
                                    Funds have been disbursed
                                <?php else: ?>
                                    Funds will be disbursed after full approval
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Approval Letter Section -->
            <?php $is_approved = in_array($request['status'], ['approved_by_finance', 'approved_by_president', 'funded']); ?>
            <?php if ($is_approved): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Approval Letter</h3>
                    <span class="status-badge status-<?php echo $request['status']; ?>">
                        <?php echo str_replace('_', ' ', $request['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Approval Letter Process</strong><br>
                        <ol style="margin: 0.5rem 0 0 1rem;">
                            <li>Download the approval letter using the button below</li>
                            <li>Print the letter and get physical signatures from both President Arbitration and Vice Guild Finance</li>
                            <li>Submit the signed letter to the Finance Office for fund disbursement</li>
                            <li>Keep a copy of the signed letter for your records</li>
                        </ol>
                    </div>

                    <a href="generate_approval_letter.php?id=<?php echo $request_id; ?>" class="btn btn-success">
                        <i class="fas fa-download"></i> Download Approval Letter
                    </a>

                    <div class="form-text" style="margin-top: 1rem;">
                        <i class="fas fa-lightbulb"></i> 
                        <strong>Note:</strong> This letter includes space for physical signatures. No file upload is required.
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

        // Auto-refresh page every 5 minutes
        setInterval(() => {
            console.log('View page auto-refresh triggered');
        }, 300000);
    </script>
</body>
</html>