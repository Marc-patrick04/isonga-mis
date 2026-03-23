<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Advisor Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'advisor_arbitration') {
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

// Handle case actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_note':
                $case_id = $_POST['case_id'];
                $content = $_POST['note_content'];
                $note_type = $_POST['note_type'];
                $is_confidential = isset($_POST['is_confidential']) ? 1 : 0;
                
                try {
                    // Verify the case is assigned to this advisor
                    $stmt = $pdo->prepare("SELECT id FROM arbitration_cases WHERE id = ? AND assigned_to = ?");
                    $stmt->execute([$case_id, $user_id]);
                    $case = $stmt->fetch();
                    
                    if ($case) {
                        $stmt = $pdo->prepare("INSERT INTO case_notes (case_id, user_id, note_type, content, is_confidential) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$case_id, $user_id, $note_type, $content, $is_confidential]);
                        $_SESSION['success_message'] = "Note added successfully";
                    } else {
                        $_SESSION['error_message'] = "Case not found or not assigned to you";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding note: " . $e->getMessage();
                }
                break;
                
            case 'update_status':
                $case_id = $_POST['case_id'];
                $status = $_POST['status'];
                
                try {
                    // Verify the case is assigned to this advisor
                    $stmt = $pdo->prepare("SELECT id FROM arbitration_cases WHERE id = ? AND assigned_to = ?");
                    $stmt->execute([$case_id, $user_id]);
                    $case = $stmt->fetch();
                    
                    if ($case) {
                        $stmt = $pdo->prepare("UPDATE arbitration_cases SET status = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$status, $case_id]);
                        $_SESSION['success_message'] = "Case status updated successfully";
                    } else {
                        $_SESSION['error_message'] = "Case not found or not assigned to you";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
                }
                break;
        }
    }
    header("Location: cases.php");
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$case_type_filter = $_GET['case_type'] ?? '';
$search = $_GET['search'] ?? '';

// Build query for assigned cases
$query = "SELECT ac.*, 
                 u.full_name as assigned_to_name,
                 DATEDIFF(CURDATE(), ac.filing_date) as days_open,
                 (SELECT COUNT(*) FROM case_notes WHERE case_id = ac.id) as note_count,
                 (SELECT COUNT(*) FROM case_documents WHERE case_id = ac.id) as document_count
          FROM arbitration_cases ac 
          LEFT JOIN users u ON ac.assigned_to = u.id 
          WHERE ac.assigned_to = ?";
$params = [$user_id];

// Add filters
if (!empty($status_filter)) {
    $query .= " AND ac.status = ?";
    $params[] = $status_filter;
}

if (!empty($priority_filter)) {
    $query .= " AND ac.priority = ?";
    $params[] = $priority_filter;
}

if (!empty($case_type_filter)) {
    $query .= " AND ac.case_type = ?";
    $params[] = $case_type_filter;
}

if (!empty($search)) {
    $query .= " AND (ac.case_number LIKE ? OR ac.title LIKE ? OR ac.complainant_name LIKE ? OR ac.respondent_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Add sorting
$sort = $_GET['sort'] ?? 'filing_date';
$order = $_GET['order'] ?? 'desc';
$valid_sorts = ['case_number', 'title', 'filing_date', 'priority', 'status'];
$valid_orders = ['asc', 'desc'];

if (in_array($sort, $valid_sorts) && in_array($order, $valid_orders)) {
    $query .= " ORDER BY ac.$sort $order";
} else {
    $query .= " ORDER BY ac.filing_date DESC";
}

// Get cases
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Cases query error: " . $e->getMessage());
    $cases = [];
}

// Get statistics for assigned cases
try {
    // Total assigned cases
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM arbitration_cases WHERE assigned_to = ?");
    $stmt->execute([$user_id]);
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Cases by status
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM arbitration_cases WHERE assigned_to = ? GROUP BY status");
    $stmt->execute([$user_id]);
    $cases_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cases by priority
    $stmt = $pdo->prepare("SELECT priority, COUNT(*) as count FROM arbitration_cases WHERE assigned_to = ? GROUP BY priority");
    $stmt->execute([$user_id]);
    $cases_by_priority = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Cases statistics error: " . $e->getMessage());
    $total_cases = 0;
    $cases_by_status = [];
    $cases_by_priority = [];
}

// Get unread messages count
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
    <title>My Cases - Arbitration Advisor - Isonga RPSU</title>
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

        .stat-content {
            flex: 1;
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

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            padding: 0.5rem 0.75rem;
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
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
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
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Table */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
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
            cursor: pointer;
            user-select: none;
        }

        .table th:hover {
            background: var(--medium-gray);
        }

        .table th i {
            margin-left: 0.25rem;
            opacity: 0.5;
        }

        .table tbody tr {
            transition: var(--transition);
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

        .status-filed {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-under_review {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-hearing_scheduled {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-mediation {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-dismissed {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-appealed {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-low {
            background: #d4edda;
            color: var(--success);
        }

        .priority-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .priority-high {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-urgent {
            background: #dc3545;
            color: white;
        }

        .case-type-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        /* Alert Messages */
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

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .filters-form {
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
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .table {
                display: block;
                overflow-x: auto;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
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
                        <div class="user-role">Arbitration Advisor</div>
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
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php" class="active">
                        <i class="fas fa-balance-scale"></i>
                        <span>My Cases</span>
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
                    <h1>My Arbitration Cases ⚖️</h1>
                    <p>Manage and track cases assigned to you</p>
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

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_cases; ?></div>
                        <div class="stat-label">Total Assigned Cases</div>
                    </div>
                </div>
                <?php foreach ($cases_by_status as $status): ?>
                    <?php if ($status['status'] === 'filed'): ?>
                        <div class="stat-card warning">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $status['count']; ?></div>
                                <div class="stat-label">Filed Cases</div>
                            </div>
                        </div>
                    <?php elseif ($status['status'] === 'under_review'): ?>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-search"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $status['count']; ?></div>
                                <div class="stat-label">Under Review</div>
                            </div>
                        </div>
                    <?php elseif ($status['status'] === 'resolved'): ?>
                        <div class="stat-card success">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number"><?php echo $status['count']; ?></div>
                                <div class="stat-label">Resolved</div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label for="search">Search Cases</label>
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Search by case number, title, or parties..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="filed" <?php echo $status_filter === 'filed' ? 'selected' : ''; ?>>Filed</option>
                            <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="hearing_scheduled" <?php echo $status_filter === 'hearing_scheduled' ? 'selected' : ''; ?>>Hearing Scheduled</option>
                            <option value="mediation" <?php echo $status_filter === 'mediation' ? 'selected' : ''; ?>>Mediation</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="dismissed" <?php echo $status_filter === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority" class="form-control">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="case_type">Case Type</label>
                        <select id="case_type" name="case_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="student_dispute" <?php echo $case_type_filter === 'student_dispute' ? 'selected' : ''; ?>>Student Dispute</option>
                            <option value="committee_conflict" <?php echo $case_type_filter === 'committee_conflict' ? 'selected' : ''; ?>>Committee Conflict</option>
                            <option value="election_dispute" <?php echo $case_type_filter === 'election_dispute' ? 'selected' : ''; ?>>Election Dispute</option>
                            <option value="disciplinary" <?php echo $case_type_filter === 'disciplinary' ? 'selected' : ''; ?>>Disciplinary</option>
                            <option value="other" <?php echo $case_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="cases.php" class="btn btn-outline">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Cases Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Assigned Cases (<?php echo count($cases); ?>)</h3>
                    <div class="table-actions">
                        <button class="btn btn-outline btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <?php if (empty($cases)): ?>
                    <div class="empty-state">
                        <i class="fas fa-balance-scale"></i>
                        <h3>No Cases Assigned</h3>
                        <p>You don't have any arbitration cases assigned to you at the moment.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th onclick="sortTable('case_number')">
                                    Case Number <?php echo $sort === 'case_number' ? '<i class="fas fa-sort-' . $order . '"></i>' : '<i class="fas fa-sort"></i>'; ?>
                                </th>
                                <th onclick="sortTable('title')">
                                    Title <?php echo $sort === 'title' ? '<i class="fas fa-sort-' . $order . '"></i>' : '<i class="fas fa-sort"></i>'; ?>
                                </th>
                                <th>Parties</th>
                                <th>Type</th>
                                <th onclick="sortTable('priority')">
                                    Priority <?php echo $sort === 'priority' ? '<i class="fas fa-sort-' . $order . '"></i>' : '<i class="fas fa-sort"></i>'; ?>
                                </th>
                                <th onclick="sortTable('status')">
                                    Status <?php echo $sort === 'status' ? '<i class="fas fa-sort-' . $order . '"></i>' : '<i class="fas fa-sort"></i>'; ?>
                                </th>
                                <th onclick="sortTable('filing_date')">
                                    Filed <?php echo $sort === 'filing_date' ? '<i class="fas fa-sort-' . $order . '"></i>' : '<i class="fas fa-sort"></i>'; ?>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cases as $case): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($case['case_number']); ?></strong>
                                        <?php if ($case['note_count'] > 0): ?>
                                            <br><small class="text-muted"><?php echo $case['note_count']; ?> notes</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="max-width: 200px;">
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($case['title']); ?></div>
                                            <small style="color: var(--dark-gray);"><?php echo strlen($case['description']) > 100 ? substr($case['description'], 0, 100) . '...' : $case['description']; ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <small>
                                            <div><strong>C:</strong> <?php echo htmlspecialchars($case['complainant_name']); ?></div>
                                            <div><strong>R:</strong> <?php echo htmlspecialchars($case['respondent_name']); ?></div>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="case-type-badge">
                                            <?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="priority-badge priority-<?php echo $case['priority']; ?>">
                                            <?php echo ucfirst($case['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $case['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                                        </span>
                                        <?php if ($case['days_open'] > 30): ?>
                                            <br><small class="text-danger"><?php echo $case['days_open']; ?> days</small>
                                        <?php else: ?>
                                            <br><small class="text-muted"><?php echo $case['days_open']; ?> days</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($case['filing_date'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="case_details.php?id=<?php echo $case['id']; ?>" class="btn btn-outline btn-sm" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button class="btn btn-outline btn-sm" onclick="openStatusModal(<?php echo $case['id']; ?>, '<?php echo $case['status']; ?>')" title="Update Status">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline btn-sm" onclick="openNoteModal(<?php echo $case['id']; ?>)" title="Add Note">
                                                <i class="fas fa-sticky-note"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add Note Modal -->
    <div id="noteModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: var(--white); margin: 5% auto; padding: 2rem; border-radius: var(--border-radius); width: 90%; max-width: 500px;">
            <h3 style="margin-bottom: 1rem;">Add Case Note</h3>
            <form method="POST" id="noteForm">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="case_id" id="noteCaseId">
                
                <div class="form-group">
                    <label for="note_type">Note Type</label>
                    <select id="note_type" name="note_type" class="form-control" required>
                        <option value="general">General</option>
                        <option value="hearing">Hearing</option>
                        <option value="evidence">Evidence</option>
                        <option value="decision">Decision</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="note_content">Note Content</label>
                    <textarea id="note_content" name="note_content" class="form-control" rows="5" required placeholder="Enter your notes here..."></textarea>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="checkbox" name="is_confidential" value="1">
                        <span>Mark as confidential</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeNoteModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Note</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="statusModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: var(--white); margin: 5% auto; padding: 2rem; border-radius: var(--border-radius); width: 90%; max-width: 400px;">
            <h3 style="margin-bottom: 1rem;">Update Case Status</h3>
            <form method="POST" id="statusForm">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="case_id" id="statusCaseId">
                
                <div class="form-group">
                    <label for="status">New Status</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="filed">Filed</option>
                        <option value="under_review">Under Review</option>
                        <option value="hearing_scheduled">Hearing Scheduled</option>
                        <option value="mediation">Mediation</option>
                        <option value="resolved">Resolved</option>
                        <option value="dismissed">Dismissed</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
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

        // Table Sorting
        function sortTable(column) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentSort = urlParams.get('sort');
            const currentOrder = urlParams.get('order');
            
            let newOrder = 'asc';
            if (currentSort === column && currentOrder === 'asc') {
                newOrder = 'desc';
            }
            
            urlParams.set('sort', column);
            urlParams.set('order', newOrder);
            window.location.href = 'cases.php?' + urlParams.toString();
        }

        // Note Modal Functions
        function openNoteModal(caseId) {
            document.getElementById('noteCaseId').value = caseId;
            document.getElementById('noteModal').style.display = 'block';
        }

        function closeNoteModal() {
            document.getElementById('noteModal').style.display = 'none';
            document.getElementById('noteForm').reset();
        }

        // Status Modal Functions
        function openStatusModal(caseId, currentStatus) {
            document.getElementById('statusCaseId').value = caseId;
            document.getElementById('status').value = currentStatus;
            document.getElementById('statusModal').style.display = 'block';
        }

        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const noteModal = document.getElementById('noteModal');
            const statusModal = document.getElementById('statusModal');
            
            if (event.target === noteModal) {
                closeNoteModal();
            }
            if (event.target === statusModal) {
                closeStatusModal();
            }
        }
    </script>
</body>
</html>