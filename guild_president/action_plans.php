<?php
session_start();
require_once '../config/database.php';
// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Location: ../auth/login.php');
    exit();
}
$user_id = $_SESSION['user_id'];
$current_academic_year = '2024/2025'; // Dynamic if needed
// User profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
}
// Dashboard statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_tickets FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total_tickets'];
    $stmt = $pdo->query("SELECT COUNT(*) as open_tickets FROM tickets WHERE status = 'open'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['open_tickets'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_messages FROM messages WHERE recipient_id = ? AND read_status = 0");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
    $pending_reports = 0;
    $pending_docs = 0;
} catch (PDOException $e) {
    $total_tickets = $open_tickets = $unread_messages = $pending_reports = $pending_docs = 0;
}
// Action Plan Statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as submitted_plans FROM action_plans WHERE academic_year = ? AND status = 'submitted'");
    $stmt->execute([$current_academic_year]);
    $submitted_plans = $stmt->fetch(PDO::FETCH_ASSOC)['submitted_plans'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_review FROM action_plans WHERE academic_year = ? AND status IN ('submitted', 'under_review')");
    $stmt->execute([$current_academic_year]);
    $pending_review = $stmt->fetch(PDO::FETCH_ASSOC)['pending_review'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as compiled_plans FROM compiled_action_plans WHERE academic_year = ? AND status != 'draft'");
    $stmt->execute([$current_academic_year]);
    $compiled_plans = $stmt->fetch(PDO::FETCH_ASSOC)['compiled_plans'];
} catch (PDOException $e) {
    $submitted_plans = $pending_review = $compiled_plans = 0;
}
// Action Plans Data
$filter = $_GET['filter'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$committee_filter = $_GET['committee'] ?? 'all';
try {
    $whereConditions = ["ap.academic_year = ?"];
    $params = [$current_academic_year];
    if ($filter === 'submitted') {
        $whereConditions[] = "ap.status IN ('submitted', 'under_review')";
    } elseif ($filter === 'reviewed') {
        $whereConditions[] = "ap.status IN ('approved', 'rejected')";
    }
    if ($status_filter !== 'all') {
        $whereConditions[] = "ap.status = ?";
        $params[] = $status_filter;
    }
    if ($committee_filter !== 'all') {
        $whereConditions[] = "ap.committee_role = ?";
        $params[] = $committee_filter;
    }
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    // Fetch action plans
    $plansStmt = $pdo->prepare("
        SELECT ap.*, u.full_name as submitted_by_name, u.role as submitted_by_role,
               reviewer.full_name as reviewed_by_name,
               (SELECT COUNT(*) FROM action_plan_items api WHERE api.action_plan_id = ap.id) as items_count,
               (SELECT SUM(api.total_cost) FROM action_plan_items api WHERE api.action_plan_id = ap.id) as total_budget
        FROM action_plans ap
        LEFT JOIN users u ON ap.submitted_by = u.id
        LEFT JOIN users reviewer ON ap.reviewed_by = reviewer.id
        $whereClause
        ORDER BY
            CASE
                WHEN ap.status = 'submitted' THEN 1
                WHEN ap.status = 'under_review' THEN 2
                WHEN ap.status = 'approved' THEN 3
                WHEN ap.status = 'rejected' THEN 4
                ELSE 5
            END,
            ap.submission_date DESC
    ");
    $plansStmt->execute($params);
    $action_plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
    // Fetch compiled plans
    $compiledStmt = $pdo->prepare("
        SELECT cap.*, u.full_name as compiled_by_name,
               (SELECT COUNT(*) FROM compiled_plan_items cpi WHERE cpi.compiled_plan_id = cap.id) as items_count
        FROM compiled_action_plans cap
        LEFT JOIN users u ON cap.compiled_by = u.id
        WHERE cap.academic_year = ?
        ORDER BY cap.created_at DESC
    ");
    $compiledStmt->execute([$current_academic_year]);
    $compiled_plans_list = $compiledStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Action plans query error: " . $e->getMessage());
    $action_plans = [];
    $compiled_plans_list = [];
}
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'review_action_plan':
                $plan_id = $_POST['plan_id'];
                $status = $_POST['status'];
                $review_notes = $_POST['review_notes'] ?? '';
                $stmt = $pdo->prepare("UPDATE action_plans SET status = ?, review_notes = ?, reviewed_by = ?, review_date = NOW() WHERE id = ?");
                $stmt->execute([$status, $review_notes, $user_id, $plan_id]);
                $_SESSION['success'] = "Action plan review submitted successfully";
                break;
            case 'create_compiled_plan':
                $title = $_POST['title'];
                $compilation_notes = $_POST['compilation_notes'] ?? '';
                $selected_items = $_POST['selected_items'] ?? [];
                if (empty($selected_items)) {
                    $_SESSION['error'] = "Please select at least one action item";
                    break;
                }
                $total_budget = 0;
                foreach ($selected_items as $item_id) {
                    $costStmt = $pdo->prepare("SELECT total_cost FROM action_plan_items WHERE id = ?");
                    $costStmt->execute([$item_id]);
                    $item_cost = $costStmt->fetch(PDO::FETCH_ASSOC)['total_cost'] ?? 0;
                    $total_budget += $item_cost;
                }
                $stmt = $pdo->prepare("INSERT INTO compiled_action_plans (title, academic_year, total_budget, compiled_by, compilation_notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $current_academic_year, $total_budget, $user_id, $compilation_notes]);
                $compiled_plan_id = $pdo->lastInsertId();
                $order = 1;
                foreach ($selected_items as $item_id) {
                    $itemStmt = $pdo->prepare("SELECT api.*, ap.committee_role FROM action_plan_items api JOIN action_plans ap ON api.action_plan_id = ap.id WHERE api.id = ?");
                    $itemStmt->execute([$item_id]);
                    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
                    if ($item) {
                        $insertStmt = $pdo->prepare("INSERT INTO compiled_plan_items (compiled_plan_id, original_item_id, title, description, committee_role, budget, priority, implementation_quarter, sequence_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $insertStmt->execute([$compiled_plan_id, $item_id, $item['title'], $item['description'], $item['committee_role'], $item['total_cost'], $item['priority'], 'Q1', $order++]);
                    }
                }
                $_SESSION['success'] = "Compiled action plan created successfully";
                break;
            case 'update_compiled_plan':
                $plan_id = $_POST['plan_id'];
                $approved_budget = $_POST['approved_budget'];
                $approval_notes = $_POST['approval_notes'] ?? '';
                $stmt = $pdo->prepare("UPDATE compiled_action_plans SET approved_budget = ?, approval_notes = ?, status = 'submitted' WHERE id = ?");
                $stmt->execute([$approved_budget, $approval_notes, $plan_id]);
                $_SESSION['success'] = "Compiled plan updated successfully";
                break;
        }
        header("Location: action_plans.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Plans - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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


        /* Action Plans Specific Styles */
.section {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.section h3 {
    margin: 0 0 1rem 0;
    color: var(--text-dark);
    border-bottom: 2px solid var(--light-blue);
    padding-bottom: 0.5rem;
}

.btn-block {
    display: block;
    width: 100%;
    margin-bottom: 0.5rem;
}

.quick-stats {
    margin-top: 2rem;
}

.quick-stats h4 {
    margin-bottom: 1rem;
    color: var(--text-dark);
}

/* Status badges for action plans */
.status-draft { background: #e2e3e5; color: var(--dark-gray); }
.status-submitted { background: #fff3cd; color: var(--warning); }
.status-under_review { background: #cce7ff; color: var(--primary-blue); }
.status-approved { background: #d4edda; color: var(--success); }
.status-rejected { background: #f8d7da; color: var(--danger); }
.status-merged { background: #e2e3e5; color: var(--dark-gray); }

/* Priority badges */
.priority-critical { background: var(--danger); color: white; }
.priority-high { background: var(--warning); color: black; }
.priority-medium { background: var(--primary-blue); color: white; }
.priority-low { background: var(--success); color: white; }



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
        /* Action Plans Container */
        .action-plans-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .plans-sidebar {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
        }
        .plan-filters {
            margin-bottom: 2rem;
        }
        .filter-group {
            margin-bottom: 1rem;
        }
        .filter-select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.8rem;
        }
        .plan-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
            border-left: 4px solid;
            transition: var(--transition);
        }
        .plan-card.submitted { border-left-color: var(--warning); }
        .plan-card.under_review { border-left-color: var(--primary-blue); }
        .plan-card.approved { border-left-color: var(--success); }
        .plan-card.rejected { border-left-color: var(--danger); }
        .plan-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .plan-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--text-dark);
        }
        .committee-badge {
            background: var(--primary-blue);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .plan-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-top: 0.5rem;
        }
        .plan-body {
            padding: 1.5rem;
        }
        .item-card {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            border-left: 3px solid;
        }
        .item-card.high { border-left-color: var(--danger); }
        .item-card.medium { border-left-color: var(--warning); }
        .item-card.low { border-left-color: var(--success); }
        .compiled-plan {
            background: var(--light-blue);
            border: 2px solid var(--primary-blue);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .budget-bar {
            background: var(--medium-gray);
            border-radius: 10px;
            height: 8px;
            margin: 0.5rem 0;
            overflow: hidden;
        }
        .budget-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s ease;
        }
        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }
        .modal-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: var(--danger);
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
        }
        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
        }
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
        }
        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }
        .btn-primary:hover {
            background: var(--accent-blue);
        }
        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
        }
        .btn-secondary:hover {
            background: var(--medium-gray);
        }
        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        /* Responsive */
        @media (max-width: 1024px) {
            .action-plans-container {
                grid-template-columns: 1fr;
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
        }
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - President</h1>
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
                        <div class="user-role">Guild President</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirmLogout()">
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>All Tickets</span>
                        <?php if ($open_tickets > 0): ?>
                            <span class="menu-badge"><?php echo $open_tickets; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Committee Reports</span>
                        <?php if ($pending_reports > 0): ?>
                            <span class="menu-badge"><?php echo $pending_reports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
                        <?php if ($pending_docs > 0): ?>
                            <span class="menu-badge"><?php echo $pending_docs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee.php">
                        <i class="fas fa-users"></i>
                        <span>Committee Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action_plans.php" class="active">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Action Plans</span>
                        <?php
                        try {
                            $current_year = '2024/2025';
                            $pending_stmt = $pdo->prepare("SELECT COUNT(*) as pending_count FROM action_plans WHERE academic_year = ? AND status IN ('submitted', 'under_review')");
                            $pending_stmt->execute([$current_year]);
                            $pending_plans = $pending_stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
                        } catch (PDOException $e) {
                            $pending_plans = 0;
                        }
                        ?>
                        <?php if ($pending_plans > 0): ?>
                            <span class="menu-badge"><?php echo $pending_plans; ?></span>
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
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="finance.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finance</span>
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
            <div class="container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Action Plans Management</h1>
                        <p>Review and compile action plans from all committee positions</p>
                    </div>
                </div>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                <!-- Action Plan Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $submitted_plans; ?></div>
                            <div class="stat-label">Submitted Plans</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $pending_review; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $compiled_plans; ?></div>
                            <div class="stat-label">Compiled Plans</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($action_plans); ?></div>
                            <div class="stat-label">Total Plans</div>
                        </div>
                    </div>
                </div>
                <!-- Action Plans Container -->
                <div class="action-plans-container">
                    <!-- Sidebar -->
                    <div class="plans-sidebar">
                        <div class="plan-filters">
                            <h4>Filters</h4>
                            <div class="filter-group">
                                <label class="filter-label">View</label>
                                <select class="filter-select" onchange="updateFilter('filter', this.value)">
                                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Plans</option>
                                    <option value="submitted" <?php echo $filter === 'submitted' ? 'selected' : ''; ?>>Submitted for Review</option>
                                    <option value="reviewed" <?php echo $filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed Plans</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select class="filter-select" onchange="updateFilter('status', this.value)">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                    <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Committee</label>
                                <select class="filter-select" onchange="updateFilter('committee', this.value)">
                                    <option value="all" <?php echo $committee_filter === 'all' ? 'selected' : ''; ?>>All Committees</option>
                                    <option value="guild_president">Guild President</option>
                                    <option value="vice_guild_president">Vice President</option>
                                    <option value="minister_academic">Academic Affairs</option>
                                    <option value="minister_sports">Sports & Entertainment</option>
                                    <option value="minister_environment">Environment</option>
                                    <option value="minister_health">Health & Social</option>
                                    <option value="minister_culture">Culture & Civic</option>
                                    <option value="minister_gender">Gender & Protocol</option>
                                    <option value="class_representative">Representative Board</option>
                                    <option value="arbitration_committee">Arbitration Committee</option>
                                </select>
                            </div>
                        </div>
                        <div class="quick-stats">
                            <h4>Quick Actions</h4>
                            <button class="btn btn-primary btn-block" onclick="openCreateCompiledPlanModal()">
                                <i class="fas fa-compress-alt"></i> Create Compiled Plan
                            </button>
                        </div>
                    </div>
                    <!-- Main Content -->
                    <div class="plans-content">
                        <!-- Submitted Action Plans -->
                        <div class="section">
                            <h3>Submitted Action Plans</h3>
                            <?php if (empty($action_plans)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list"></i>
                                    <h4>No Action Plans Found</h4>
                                    <p>No action plans match your current filters.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($action_plans as $plan): ?>
                                    <div class="plan-card <?php echo $plan['status']; ?>">
                                        <div class="plan-header">
                                            <div>
                                                <div class="plan-title">
                                                    <?php echo htmlspecialchars($plan['title']); ?>
                                                    <span class="committee-badge">
                                                        <?php echo str_replace('_', ' ', $plan['committee_role']); ?>
                                                    </span>
                                                </div>
                                                <div class="plan-meta">
                                                    <span>Submitted by: <?php echo htmlspecialchars($plan['submitted_by_name']); ?></span>
                                                    <span>Items: <?php echo $plan['items_count']; ?></span>
                                                    <span>Budget: RWF <?php echo number_format($plan['total_budget'] ?? 0, 2); ?></span>
                                                    <span>Submitted: <?php echo $plan['submission_date'] ? date('M j, Y', strtotime($plan['submission_date'])) : 'Not submitted'; ?></span>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="status-badge status-<?php echo $plan['status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $plan['status'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="plan-body">
                                            <?php if (!empty($plan['description'])): ?>
                                                <p><?php echo htmlspecialchars($plan['description']); ?></p>
                                            <?php endif; ?>
                                            <!-- Plan Items -->
                                            <div class="items-list">
                                                <h5>Action Items</h5>
                                                <?php
                                                $itemsStmt = $pdo->prepare("SELECT * FROM action_plan_items WHERE action_plan_id = ? ORDER BY sequence_order");
                                                $itemsStmt->execute([$plan['id']]);
                                                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                                                ?>
                                                <?php foreach ($items as $item): ?>
                                                    <div class="item-card <?php echo $item['priority']; ?>">
                                                        <div class="item-header">
                                                            <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                                                            <span class="badge priority-<?php echo $item['priority']; ?>">
                                                                <?php echo ucfirst($item['priority']); ?>
                                                            </span>
                                                        </div>
                                                        <p><?php echo htmlspecialchars($item['description']); ?></p>
                                                        <div class="item-meta">
                                                            <span>Cost: RWF <?php echo number_format($item['total_cost'], 2); ?></span>
                                                            <span>Timeline: <?php echo htmlspecialchars($item['implementation_timeline']); ?></span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="plan-actions">
                                                <?php if (in_array($plan['status'], ['submitted', 'under_review'])): ?>
                                                    <button class="btn btn-primary btn-sm" onclick="openReviewModal(<?php echo $plan['id']; ?>)">
                                                        <i class="fas fa-eye"></i> Review
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-secondary btn-sm" onclick="viewPlanDetails(<?php echo $plan['id']; ?>)">
                                                    <i class="fas fa-info-circle"></i> Details
                                                </button>
                                                <button class="btn btn-success btn-sm" onclick="addToCompilation(<?php echo $plan['id']; ?>)">
                                                    <i class="fas fa-plus"></i> Add to Compilation
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <!-- Compiled Plans -->
                        <div class="section">
                            <h3>Compiled Action Plans</h3>
                            <?php if (empty($compiled_plans_list)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-alt"></i>
                                    <p>No compiled plans yet. Create one to get started.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($compiled_plans_list as $compiled): ?>
                                    <div class="compiled-plan">
                                        <div class="plan-header">
                                            <div>
                                                <div class="plan-title"><?php echo htmlspecialchars($compiled['title']); ?></div>
                                                <div class="plan-meta">
                                                    <span>Compiled by: <?php echo htmlspecialchars($compiled['compiled_by_name']); ?></span>
                                                    <span>Items: <?php echo $compiled['items_count']; ?></span>
                                                    <span>Total Budget: RWF <?php echo number_format($compiled['total_budget'], 2); ?></span>
                                                    <span>Approved Budget: RWF <?php echo number_format($compiled['approved_budget'], 2); ?></span>
                                                </div>
                                            </div>
                                            <span class="status-badge status-<?php echo $compiled['status']; ?>">
                                                <?php echo ucfirst($compiled['status']); ?>
                                            </span>
                                        </div>
                                        <div class="budget-bar">
                                            <div class="budget-fill" style="width: <?php echo $compiled['total_budget'] > 0 ? min(100, ($compiled['approved_budget'] / $compiled['total_budget']) * 100) : 0; ?>%"></div>
                                        </div>
                                        <div class="plan-actions">
                                            <button class="btn btn-primary btn-sm" onclick="viewCompiledPlan(<?php echo $compiled['id']; ?>)">
                                                <i class="fas fa-eye"></i> View Details
                                            </button>
                                            <button class="btn btn-warning btn-sm" onclick="editCompiledPlan(<?php echo $compiled['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-success btn-sm" onclick="submitForApproval(<?php echo $compiled['id']; ?>)">
                                                <i class="fas fa-paper-plane"></i> Submit for Approval
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <!-- Modals -->
    <!-- Review Action Plan Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Review Action Plan</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="reviewForm">
                    <input type="hidden" name="action" value="review_action_plan">
                    <input type="hidden" name="plan_id" id="review_plan_id">
                    <div class="form-group">
                        <label for="review_status">Decision</label>
                        <select name="status" id="review_status" class="form-control" required>
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                            <option value="under_review">Need More Review</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="review_notes">Review Notes</label>
                        <textarea name="review_notes" id="review_notes" class="form-control" rows="4" placeholder="Provide feedback and recommendations..."></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Create Compiled Plan Modal -->
    <div id="createCompiledPlanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Compiled Action Plan</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <form id="createCompiledForm">
                    <input type="hidden" name="action" value="create_compiled_plan">
                    <div class="form-group">
                        <label for="compiled_title">Compiled Plan Title</label>
                        <input type="text" name="title" id="compiled_title" class="form-control" placeholder="e.g., 2024/2025 Guild Council Action Plan" required>
                    </div>
                    <div class="form-group">
                        <label for="compilation_notes">Compilation Notes</label>
                        <textarea name="compilation_notes" id="compilation_notes" class="form-control" rows="3" placeholder="Notes about this compilation..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Select Action Items to Include</label>
                        <div class="selected-items" id="availableItems">
                            <!-- Available items will be loaded here -->
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Create Compiled Plan</button>
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal functionality
            const reviewModal = document.getElementById('reviewModal');
            const createCompiledModal = document.getElementById('createCompiledPlanModal');
            // Close modals
            document.querySelectorAll('.close, .close-modal').forEach(btn => {
                btn.addEventListener('click', closeAllModals);
            });
            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeAllModals();
                }
            });
            // Form submissions
            document.getElementById('reviewForm')?.addEventListener('submit', handleReviewSubmit);
            document.getElementById('createCompiledForm')?.addEventListener('submit', handleCreateCompiled);
            function closeAllModals() {
                reviewModal.style.display = 'none';
                createCompiledModal.style.display = 'none';
            }
            function openReviewModal(planId) {
                document.getElementById('review_plan_id').value = planId;
                reviewModal.style.display = 'block';
            }
            function openCreateCompiledPlanModal() {
                loadAvailableItems();
                createCompiledModal.style.display = 'block';
            }
            function loadAvailableItems() {
                fetch('get_approved_action_items.php')
                    .then(response => response.text())
                    .then(data => {
                        document.getElementById('availableItems').innerHTML = data;
                    })
                    .catch(error => {
                        console.error('Error loading available items:', error);
                    });
            }
            function handleReviewSubmit(e) {
                e.preventDefault();
                const formData = new FormData(this);
                fetch('handle_action_plans.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Review submitted successfully');
                        closeAllModals();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    alert('Error submitting review');
                    console.error('Error:', error);
                });
            }
            function handleCreateCompiled(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const selectedItems = [];
                document.querySelectorAll('input[name="selected_items[]"]:checked').forEach(checkbox => {
                    selectedItems.push(checkbox.value);
                });
                if (selectedItems.length === 0) {
                    alert('Please select at least one action item');
                    return;
                }
                selectedItems.forEach(itemId => {
                    formData.append('selected_items[]', itemId);
                });
                fetch('handle_action_plans.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Compiled plan created successfully');
                        closeAllModals();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    alert('Error creating compiled plan');
                    console.error('Error:', error);
                });
            }
            function updateFilter(type, value) {
                const url = new URL(window.location);
                url.searchParams.set(type, value);
                window.location.href = url.toString();
            }
            // Make functions globally available
            window.openReviewModal = openReviewModal;
            window.openCreateCompiledPlanModal = openCreateCompiledPlanModal;
            window.updateFilter = updateFilter;
        });
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        // Check for saved theme preference or respect OS preference
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
    </script>
</body>
</html>
