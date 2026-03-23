<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user needs to change password (first login)
    $password_change_required = ($user['last_login'] === null);
    
} catch (PDOException $e) {
    $user = [];
    $password_change_required = false;
    error_log("User profile error: " . $e->getMessage());
}

// Get ALL dashboard statistics dynamically from database
try {
    // ===== USER STATISTICS =====
    // Total users count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total students count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'");
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total committee members from committee_members table
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM committee_members WHERE status = 'active'");
    $active_committees = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // ===== TICKET STATISTICS =====
    // Total tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets");
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Resolved tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'resolved'");
    $resolved_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Open tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'open' OR status = 'pending'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // In-progress tickets
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'in_progress'");
    $in_progress_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // ===== CLUB & ASSOCIATION STATISTICS =====
    // Active clubs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clubs WHERE status = 'active'");
    $active_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total clubs (including inactive)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clubs");
    $total_clubs = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Active associations
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM associations WHERE status = 'active'");
    $active_associations = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // ===== CONTENT STATISTICS =====
    // Published announcements
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM announcements WHERE status = 'published'");
    $total_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Draft announcements
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM announcements WHERE status = 'draft'");
    $draft_announcements = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Published news
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM news WHERE status = 'published'");
    $total_news = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Draft news
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM news WHERE status = 'draft'");
    $draft_news = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Upcoming events (event_date >= today, published)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE event_date >= CURDATE() AND status = 'published'");
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total published events
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE status = 'published'");
    $total_events = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Past events
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE event_date < CURDATE() AND status = 'published'");
    $past_events = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // ===== ARBITRATION CASES =====
    // Total arbitration cases
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases");
    $total_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Open cases (filed, under_review, hearing_scheduled, mediation)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases WHERE status IN ('filed', 'under_review', 'hearing_scheduled', 'mediation')");
    $open_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Resolved cases
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases WHERE status IN ('resolved', 'closed', 'settled')");
    $resolved_cases = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Cases by priority
    $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM arbitration_cases GROUP BY priority");
    $cases_by_priority = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== RECENT DATA (matching index.php structure) =====
    // Recent announcements (published, ordered by created_at DESC, limit 5)
    $stmt = $pdo->query("
        SELECT id, title, excerpt, content, created_at, status 
        FROM announcements 
        WHERE status = 'published' 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent news (published, ordered by created_at DESC, limit 5)
    $stmt = $pdo->query("
        SELECT id, title, excerpt, content, featured_image, created_at, status 
        FROM news 
        WHERE status = 'published' 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_news = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming events list (event_date >= today, published, ordered by event_date ASC, limit 5)
    $stmt = $pdo->query("
        SELECT id, title, description, event_date, start_time, end_time, location, status 
        FROM events 
        WHERE event_date >= CURDATE() AND status = 'published' 
        ORDER BY event_date ASC 
        LIMIT 5
    ");
    $upcoming_events_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Committee members (active, ordered by role_order, name ASC, limit 6)
    $stmt = $pdo->query("
        SELECT id, name, role, role_order, bio, photo_url, status 
        FROM committee_members 
        WHERE status = 'active' 
        ORDER BY role_order ASC, name ASC 
        LIMIT 6
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent tickets (ordered by created_at DESC, limit 5)
    $stmt = $pdo->query("
        SELECT t.*, u.full_name as student_name 
        FROM tickets t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent arbitration cases (ordered by created_at DESC, limit 5)
    $stmt = $pdo->query("
        SELECT ac.*, ac.complainant_name as student_name 
        FROM arbitration_cases ac 
        ORDER BY ac.created_at DESC 
        LIMIT 5
    ");
    $recent_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent login activities
    $stmt = $pdo->query("
        SELECT la.*, u.full_name, u.role, u.email 
        FROM login_activities la 
        JOIN users u ON la.user_id = u.id 
        ORDER BY la.login_time DESC 
        LIMIT 10
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== SYSTEM HEALTH METRICS =====
    // Departments
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM departments WHERE is_active = 1");
    $total_departments = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Programs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM programs WHERE is_active = 1");
    $total_programs = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Financial data (if tables exist)
    $total_budget = 0;
    $total_transactions = 0;
    $total_expenses = 0;
    try {
        $stmt = $pdo->query("SELECT SUM(allocated_amount) as total FROM budget_allocations WHERE status = 'approved'");
        $budget_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_budget = $budget_result['total'] ?? 0;
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM financial_transactions");
        $total_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        $stmt = $pdo->query("SELECT SUM(amount) as total FROM financial_transactions WHERE type = 'expense'");
        $expense_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_expenses = $expense_result['total'] ?? 0;
    } catch (PDOException $e) {
        // Tables might not exist, keep defaults
        error_log("Financial tables may not exist: " . $e->getMessage());
    }
    
    // Gallery images count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM gallery WHERE status = 'published'");
    $total_gallery_images = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Unread messages for admin
    $unread_messages = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_messages 
            FROM conversation_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
        ");
        $stmt->execute([$user_id]);
        $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
    } catch (PDOException $e) {
        // Messages table might not exist
        error_log("Messages table may not exist: " . $e->getMessage());
    }
    
    // ===== CALCULATED METRICS =====
    $resolution_rate = $total_tickets > 0 ? round(($resolved_tickets / $total_tickets) * 100) : 0;
    $case_resolution_rate = $total_cases > 0 ? round(($resolved_cases / $total_cases) * 100) : 0;
    $system_utilization = $total_users > 0 ? round(($total_students / $total_users) * 100) : 0;
    
    // New users in last 30 days
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $new_users_30days = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Active users in last 7 days
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as total FROM login_activities WHERE login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $active_users_7days = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Admin dashboard statistics error: " . $e->getMessage());
    // Initialize all variables to 0 or empty arrays when database error occurs
    $total_users = 0;
    $total_students = 0;
    $active_committees = 0;
    $total_tickets = 0;
    $resolved_tickets = 0;
    $open_tickets = 0;
    $in_progress_tickets = 0;
    $active_clubs = 0;
    $total_clubs = 0;
    $active_associations = 0;
    $total_announcements = 0;
    $draft_announcements = 0;
    $total_news = 0;
    $draft_news = 0;
    $upcoming_events = 0;
    $total_events = 0;
    $past_events = 0;
    $total_cases = 0;
    $open_cases = 0;
    $resolved_cases = 0;
    $cases_by_priority = [];
    $recent_announcements = [];
    $recent_news = [];
    $upcoming_events_list = [];
    $committee_members = [];
    $recent_tickets = [];
    $recent_cases = [];
    $recent_activities = [];
    $total_departments = 0;
    $total_programs = 0;
    $total_budget = 0;
    $total_transactions = 0;
    $total_expenses = 0;
    $total_gallery_images = 0;
    $unread_messages = 0;
    $resolution_rate = 0;
    $case_resolution_rate = 0;
    $system_utilization = 0;
    $new_users_30days = 0;
    $active_users_7days = 0;
    
    echo "<!-- Database Error: " . $e->getMessage() . " -->";
}

// Calculate available budget
$available_budget = $total_budget - $total_expenses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* CSS Variables */
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
            --info: #17a2b8;
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
            --info: #29b6f6;
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

        /* Header styles */
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
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 250px 1fr;
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

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        .menu-badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
        }

        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .welcome-section p {
            color: var(--dark-gray);
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

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--info); }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
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

        .stat-card.info .stat-icon {
            background: #d1ecf1;
            color: var(--info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-header-btn {
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .card-header-btn:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th, .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .data-table th {
            background: var(--light-gray);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active, .status-published, .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-pending, .status-open {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-draft {
            background: var(--medium-gray);
            color: var(--dark-gray);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-text {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Preview Items */
        .preview-item {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }
        
        .preview-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .preview-date {
            color: var(--primary-blue);
            font-weight: 600;
            font-size: 0.7rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .preview-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .preview-excerpt {
            color: var(--dark-gray);
            font-size: 0.75rem;
            line-height: 1.5;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .action-btn i {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--primary-blue);
        }

        .action-label {
            font-weight: 600;
            font-size: 0.7rem;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        /* System Health */
        .system-health {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
        }

        .health-stat {
            text-align: center;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .health-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .health-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
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
            .quick-actions {
                grid-template-columns: 1fr;
            }
            .user-details {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .main-content {
                padding: 1rem;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card {
            animation: fadeInUp 0.3s ease forwards;
            opacity: 0;
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
                    <h1>Isonga - Admin Portal</h1>
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
                            <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></div>
                        <div class="user-role">System Administrator</div>
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
                <li class="menu-item"><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="representative.php"><i class="fas fa-user"></i> Representative</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs & Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="content.php"><i class="fas fa-newspaper"></i> Content Management</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration Cases</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="finance.php"><i class="fas fa-money-bill-wave"></i> Finance</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?>! 👋</h1>
                    <p>Here's what's happening across your campus today.</p>
                </div>
            </div>

            <?php if ($password_change_required): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Security Alert:</strong> Please <a href="profile.php?tab=security">change your password</a> for security reasons.
                </div>
            <?php endif; ?>

            <!-- Primary Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_users); ?></div>
                        <div class="stat-label">Total Active Users</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($total_students); ?></div>
                        <div class="stat-label">Active Students</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($resolved_tickets); ?></div>
                        <div class="stat-label">Issues Resolved</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_tickets + $in_progress_tickets; ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </div>
                </div>
            </div>

            <!-- Secondary Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $active_committees; ?></div>
                        <div class="stat-label">Committee Members</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_events; ?></div>
                        <div class="stat-label">Upcoming Events</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $open_cases; ?></div>
                        <div class="stat-label">Pending Cases</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_announcements; ?></div>
                        <div class="stat-label">Announcements</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Announcements -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
                            <div class="card-header-actions">
                                <a href="content.php?tab=announcements&action=add" class="card-header-btn" title="Add Announcement">
                                    <i class="fas fa-plus"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_announcements)): ?>
                                <p class="preview-excerpt" style="text-align: center;">No announcements yet. Click + to create one.</p>
                            <?php else: ?>
                                <?php foreach ($recent_announcements as $announcement): ?>
                                    <div class="preview-item">
                                        <div class="preview-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                                        </div>
                                        <div class="preview-title"><?php echo htmlspecialchars($announcement['title']); ?></div>
                                        <p class="preview-excerpt"><?php echo htmlspecialchars(substr($announcement['excerpt'] ?? strip_tags($announcement['content']), 0, 100)); ?>...</p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent News -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-newspaper"></i> Recent News</h3>
                            <div class="card-header-actions">
                                <a href="content.php?tab=news&action=add" class="card-header-btn" title="Add News">
                                    <i class="fas fa-plus"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_news)): ?>
                                <p class="preview-excerpt" style="text-align: center;">No news articles yet.</p>
                            <?php else: ?>
                                <?php foreach ($recent_news as $news_item): ?>
                                    <div class="preview-item">
                                        <div class="preview-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($news_item['created_at'])); ?>
                                        </div>
                                        <div class="preview-title"><?php echo htmlspecialchars($news_item['title']); ?></div>
                                        <p class="preview-excerpt"><?php echo htmlspecialchars(substr($news_item['excerpt'] ?? strip_tags($news_item['content']), 0, 100)); ?>...</p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Upcoming Events -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-calendar-alt"></i> Upcoming Events</h3>
                            <div class="card-header-actions">
                                <a href="events.php?action=add" class="card-header-btn" title="Add Event">
                                    <i class="fas fa-plus"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_events_list)): ?>
                                <p class="preview-excerpt" style="text-align: center;">No upcoming events scheduled.</p>
                            <?php else: ?>
                                <?php foreach ($upcoming_events_list as $event): ?>
                                    <div class="preview-item">
                                        <div class="preview-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($event['event_date'])); ?>
                                            <?php if (!empty($event['start_time'])): ?>
                                                <span><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($event['start_time'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="preview-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <?php if (!empty($event['location'])): ?>
                                            <p class="preview-excerpt"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Committee Members Preview -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-users"></i> Committee Leadership</h3>
                            <div class="card-header-actions">
                                <a href="committee.php" class="card-header-btn" title="Manage Committee">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($committee_members)): ?>
                                <p class="preview-excerpt" style="text-align: center;">No committee members found.</p>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem;">
                                    <?php foreach ($committee_members as $member): ?>
                                        <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                            <div style="width: 40px; height: 40px; background: var(--gradient-primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                                <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars($member['name']); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--primary-blue);"><?php echo htmlspecialchars($member['role']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- System Health -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-heartbeat"></i> System Health</h3>
                        </div>
                        <div class="card-body">
                            <div class="system-health">
                                <div class="health-stat">
                                    <div class="health-number"><?php echo $total_departments; ?></div>
                                    <div class="health-label">Departments</div>
                                </div>
                                <div class="health-stat">
                                    <div class="health-number"><?php echo $total_programs; ?></div>
                                    <div class="health-label">Programs</div>
                                </div>
                                <div class="health-stat">
                                    <div class="health-number"><?php echo $active_clubs; ?></div>
                                    <div class="health-label">Active Clubs</div>
                                </div>
                                <div class="health-stat">
                                    <div class="health-number"><?php echo $total_gallery_images; ?></div>
                                    <div class="health-label">Gallery Images</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Tickets -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-ticket-alt"></i> Recent Support Tickets</h3>
                            <div class="card-header-actions">
                                <a href="tickets.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_tickets)): ?>
                                <p class="preview-excerpt" style="text-align: center;">No tickets found.</p>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Subject</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_tickets as $ticket): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ticket['student_name'] ?? 'Unknown'); ?></td>
                                                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($ticket['subject'] ?? $ticket['title'] ?? 'N/A'); ?></td>
                                                <td><span class="status-badge status-<?php echo $ticket['status']; ?>"><?php echo ucfirst($ticket['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Arbitration Cases -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-balance-scale"></i> Recent Cases</h3>
                            <div class="card-header-actions">
                                <a href="arbitration.php" class="card-header-btn" title="View All">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_cases)): ?>
                                <p class="preview-excerpt" style="text-align: center;">No arbitration cases.</p>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Case #</th>
                                            <th>Title</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_cases as $case): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($case['case_number'] ?? substr($case['id'], 0, 8)); ?></td>
                                                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($case['title']); ?></td>
                                                <td><span class="status-badge status-<?php echo str_replace('_', '-', $case['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (empty($recent_activities)): ?>
                                    <li style="text-align: center; color: var(--dark-gray); padding: 1rem;">No recent activity</li>
                                <?php else: ?>
                                    <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                                        <li class="activity-item">
                                            <div class="activity-avatar">
                                                <?php echo strtoupper(substr($activity['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> 
                                                    logged in as <?php echo ucfirst($activity['role']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M j, g:i A', strtotime($activity['login_time'])); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- System Metrics -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-line"></i> System Metrics</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; gap: 0.75rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span class="stat-label">Resolution Rate</span>
                                    <strong><?php echo $resolution_rate; ?>%</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span class="stat-label">Case Resolution</span>
                                    <strong><?php echo $case_resolution_rate; ?>%</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span class="stat-label">System Utilization</span>
                                    <strong><?php echo $system_utilization; ?>%</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span class="stat-label">New Users (30d)</span>
                                    <strong><?php echo $new_users_30days; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span class="stat-label">Active Users (7d)</span>
                                    <strong><?php echo $active_users_7days; ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <a href="users.php?action=add" class="action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span class="action-label">Add User</span>
                        </a>
                        <a href="content.php?tab=announcements&action=add" class="action-btn">
                            <i class="fas fa-bullhorn"></i>
                            <span class="action-label">Post Announcement</span>
                        </a>
                        <a href="events.php?action=add" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="action-label">Create Event</span>
                        </a>
                        <a href="reports.php" class="action-btn">
                            <i class="fas fa-chart-pie"></i>
                            <span class="action-label">Generate Report</span>
                        </a>
                    </div>
                </div>
            </div>
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

        // Animate cards on load
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
            });
        });
    </script>
</body>
</html>