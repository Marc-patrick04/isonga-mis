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

// Handle filters and search
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$case_type_filter = $_GET['case_type'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "ac.status = ?";
    $params[] = $status_filter;
}

if (!empty($priority_filter)) {
    $where_conditions[] = "ac.priority = ?";
    $params[] = $priority_filter;
}

if (!empty($case_type_filter)) {
    $where_conditions[] = "ac.case_type = ?";
    $params[] = $case_type_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(ac.case_number LIKE ? OR ac.title LIKE ? OR ac.complainant_name LIKE ? OR ac.respondent_name LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM arbitration_cases ac $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_cases = $stmt->fetchColumn();
$total_pages = ceil($total_cases / $per_page);

// Get cases with filters and pagination
$sql = "
    SELECT 
        ac.*,
        u.full_name as assigned_to_name,
        creator.full_name as created_by_name,
        CURRENT_DATE::date - ac.filing_date as days_open,
        (SELECT COUNT(*) FROM case_notes WHERE case_id = ac.id) as notes_count,
        (SELECT COUNT(*) FROM case_documents WHERE case_id = ac.id) as documents_count
    FROM arbitration_cases ac
    LEFT JOIN users u ON ac.assigned_to = u.id
    LEFT JOIN users creator ON ac.created_by = creator.id
    $where_clause
    ORDER BY ac.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for dashboard cards
try {
    // Total cases
    $stmt = $pdo->query("SELECT COUNT(*) as total_cases FROM arbitration_cases");
    $total_cases_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'];
    
    // Pending cases
    $stmt = $pdo->query("SELECT COUNT(*) as pending_cases FROM arbitration_cases WHERE status IN ('filed', 'under_review')");
    $pending_cases_count = $stmt->fetch(PDO::FETCH_ASSOC)['pending_cases'];
    
    // Resolved cases
    $stmt = $pdo->query("SELECT COUNT(*) as resolved_cases FROM arbitration_cases WHERE status = 'resolved'");
    $resolved_cases_count = $stmt->fetch(PDO::FETCH_ASSOC)['resolved_cases'];
    
    // Cases with hearings scheduled
    $stmt = $pdo->query("SELECT COUNT(*) as hearing_cases FROM arbitration_cases WHERE status = 'hearing_scheduled'");
    $hearing_cases_count = $stmt->fetch(PDO::FETCH_ASSOC)['hearing_cases'];
    
} catch (PDOException $e) {
    error_log("Cases statistics error: " . $e->getMessage());
    $total_cases_count = $pending_cases_count = $resolved_cases_count = $hearing_cases_count = 0;
}

// Handle case assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_case'])) {
    $case_id = $_POST['case_id'];
    $assigned_to = $_POST['assigned_to'];
    
    try {
        $stmt = $pdo->prepare("UPDATE arbitration_cases SET assigned_to = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?");
        $stmt->execute([$assigned_to, $user_id, $case_id]);
        
        $_SESSION['success_message'] = "Case assigned successfully!";
        header("Location: cases.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error assigning case: " . $e->getMessage();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Cases - Arbitration Secretary</title>
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
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-blue);
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
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
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
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Filters Card */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            padding: 1.5rem;
        }

        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-title {
            font-weight: 600;
            color: var(--text-dark);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-select, .form-input {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: end;
        }

        /* Cases Table */
        .cases-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
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
            padding: 0;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th, .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr:hover {
            background: var(--light-blue);
        }

        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
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
            background: #fff0f0;
            color: #e83e8c;
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
            background: #ffe6cc;
            color: #fd7e14;
        }

        .priority-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .priority-urgent {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-high {
            background: #ffe6cc;
            color: #fd7e14;
        }

        .priority-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .priority-low {
            background: #d4edda;
            color: var(--success);
        }

        .case-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.4rem 0.6rem;
            border: none;
            border-radius: 4px;
            background: var(--light-gray);
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.7rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .action-btn:hover {
            background: var(--primary-blue);
            color: white;
        }

        .action-btn.view {
            background: #e3f2fd;
            color: var(--primary-blue);
        }

        .action-btn.notes {
            background: #fff3cd;
            color: var(--warning);
        }

        .action-btn.documents {
            background: #d4edda;
            color: var(--success);
        }

        .action-btn.assign {
            background: #e2e3ff;
            color: #6f42c1;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid var(--medium-gray);
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            color: var(--text-dark);
            text-decoration: none;
            border-radius: 4px;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .pagination-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .modal-title {
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
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
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: start;
            }
            
            .page-actions {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .case-actions {
                flex-direction: column;
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
         <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php" class="active">
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php">
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
                    <h1 class="page-title">Manage Arbitration Cases</h1>
                    <p style="color: var(--dark-gray); font-size: 0.9rem; margin-top: 0.25rem;">
                        View and manage all arbitration cases in the system
                    </p>
                </div>
                <div class="page-actions">
                    <a href="case-add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Case
                    </a>
                    <a href="reports.php" class="btn btn-outline">
                        <i class="fas fa-download"></i> Export Report
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

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_cases_count; ?></div>
                        <div class="stat-label">Total Cases</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $pending_cases_count; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $resolved_cases_count; ?></div>
                        <div class="stat-label">Resolved Cases</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $hearing_cases_count; ?></div>
                        <div class="stat-label">Hearings Scheduled</div>
                    </div>
                </div>
            </div>

            <!-- Filters Card -->
            <div class="filters-card">
                <div class="filters-header">
                    <h3 class="filters-title">Filter Cases</h3>
                </div>
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
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
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Case Type</label>
                        <select name="case_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="student_dispute" <?php echo $case_type_filter === 'student_dispute' ? 'selected' : ''; ?>>Student Dispute</option>
                            <option value="committee_conflict" <?php echo $case_type_filter === 'committee_conflict' ? 'selected' : ''; ?>>Committee Conflict</option>
                            <option value="election_dispute" <?php echo $case_type_filter === 'election_dispute' ? 'selected' : ''; ?>>Election Dispute</option>
                            <option value="disciplinary" <?php echo $case_type_filter === 'disciplinary' ? 'selected' : ''; ?>>Disciplinary</option>
                            <option value="other" <?php echo $case_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-input" placeholder="Search cases..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="cases.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Cases Table -->
            <div class="cases-card">
                <div class="card-header">
                    <h3>Arbitration Cases (<?php echo $total_cases; ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($cases)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No cases found matching your criteria.</p>
                            <a href="case-add.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Add New Case
                            </a>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Case #</th>
                                    <th>Title</th>
                                    <th>Parties</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Assigned To</th>
                                    <th>Filed</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases as $case): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($case['case_number']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($case['title']); ?></div>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo $case['notes_count']; ?> notes, <?php echo $case['documents_count']; ?> docs
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <strong>C:</strong> <?php echo htmlspecialchars($case['complainant_name']); ?><br>
                                                <strong>R:</strong> <?php echo htmlspecialchars($case['respondent_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $case['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $case['priority']; ?>">
                                                <?php echo ucfirst($case['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($case['assigned_to_name']): ?>
                                                <?php echo htmlspecialchars($case['assigned_to_name']); ?>
                                            <?php else: ?>
                                                <span style="color: var(--dark-gray); font-style: italic;">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($case['filing_date'])); ?>
                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                <?php echo $case['days_open']; ?> days
                                            </div>
                                        </td>
                                        <td>
                                            <div class="case-actions">
                                                <a href="case-view.php?id=<?php echo $case['id']; ?>" class="action-btn view" title="View Case">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="case-notes.php?case_id=<?php echo $case['id']; ?>" class="action-btn notes" title="Case Notes">
                                                    <i class="fas fa-sticky-note"></i>
                                                </a>
                                                <a href="documents.php?case_id=<?php echo $case['id']; ?>" class="action-btn documents" title="Documents">
                                                    <i class="fas fa-file"></i>
                                                </a>
                                                <button class="action-btn assign" onclick="openAssignModal(<?php echo $case['id']; ?>, '<?php echo htmlspecialchars($case['case_number']); ?>')" title="Assign Case">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">
                                <i class="fas fa-chevron-left"></i> Previous
                            </span>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">
                                Next <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Assign Case Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Assign Case</h3>
                <button class="modal-close" onclick="closeAssignModal()">&times;</button>
            </div>
            <form method="POST" id="assignForm">
                <div class="modal-body">
                    <input type="hidden" name="case_id" id="assignCaseId">
                    <div class="form-group">
                        <label class="form-label">Case Number</label>
                        <input type="text" id="assignCaseNumber" class="form-input" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Assign To</label>
                        <select name="assigned_to" class="form-select" required>
                            <option value="">Select Arbitrator</option>
                            <?php foreach ($arbitrators as $arbitrator): ?>
                                <option value="<?php echo $arbitrator['id']; ?>">
                                    <?php echo htmlspecialchars($arbitrator['full_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $arbitrator['role'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeAssignModal()">Cancel</button>
                    <button type="submit" name="assign_case" class="btn btn-primary">Assign Case</button>
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

        // Assign Case Modal Functions
        function openAssignModal(caseId, caseNumber) {
            document.getElementById('assignCaseId').value = caseId;
            document.getElementById('assignCaseNumber').value = caseNumber;
            document.getElementById('assignModal').style.display = 'flex';
        }

        function closeAssignModal() {
            document.getElementById('assignModal').style.display = 'none';
            document.getElementById('assignForm').reset();
        }

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            const modal = document.getElementById('assignModal');
            if (event.target === modal) {
                closeAssignModal();
            }
        });

        // Auto-refresh page every 5 minutes
        setInterval(() => {
            console.log('Auto-refreshing cases page...');
        }, 300000);
    </script>
</body>
</html>