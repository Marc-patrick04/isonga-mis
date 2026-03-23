<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Redirect class representatives to their dedicated dashboard
if ($_SESSION['is_class_rep'] ?? 0) {
    header('Location: class_rep_dashboard.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];
$is_class_rep = $_SESSION['is_class_rep'] ?? 0;

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: dashboard.php');
    exit();
}

// Handle ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $category_id = $_POST['category_id'];
    $subject = trim($_POST['subject']);
    $description = trim($_POST['description']);
    $priority = $_POST['priority'];
    $preferred_contact = $_POST['preferred_contact'];
    
    if (empty($subject) || empty($description)) {
        $error_message = "Subject and description are required.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tickets (reg_number, name, email, phone, department_id, program_id, academic_year, category_id, subject, description, priority, preferred_contact, status)
                SELECT u.reg_number, u.full_name, u.email, u.phone, u.department_id, u.program_id, u.academic_year, ?, ?, ?, ?, ?, 'open'
                FROM users u 
                WHERE u.id = ?
            ");
            
            $stmt->execute([$category_id, $subject, $description, $priority, $preferred_contact, $student_id]);
            
            $ticket_id = $pdo->lastInsertId();
            
            // Auto-assign ticket
            $assignStmt = $pdo->prepare("
                INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                SELECT ?, cm.user_id, ?, NOW(), 'Auto-assigned based on issue category'
                FROM issue_categories ic
                JOIN committee_members cm ON ic.auto_assign_role = cm.role
                WHERE ic.id = ? AND cm.status = 'active'
                LIMIT 1
            ");
            
            $assignStmt->execute([$ticket_id, $student_id, $category_id]);
            
            $_SESSION['success_message'] = "Ticket submitted successfully! Your ticket ID is #$ticket_id";
            header('Location: dashboard.php');
            exit();
            
        } catch (PDOException $e) {
            $error_message = "Failed to submit ticket. Please try again.";
        }
    }
}

// Get student's recent tickets
$tickets_stmt = $pdo->prepare("
    SELECT t.*, ic.name as category_name, t.status as ticket_status
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    WHERE t.reg_number = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");
$tickets_stmt->execute([$reg_number]);
$recent_tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ticket statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
    FROM tickets 
    WHERE reg_number = ?
");
$stats_stmt->execute([$reg_number]);
$ticket_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get issue categories
$categories_stmt = $pdo->prepare("SELECT * FROM issue_categories WHERE id != 10 ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming events
$events_stmt = $pdo->prepare("
    SELECT * FROM events 
    WHERE event_date >= CURRENT_DATE 
    AND status = 'active'
    ORDER BY event_date ASC 
    LIMIT 3
");
$events_stmt->execute();
$upcoming_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Isonga RPSU</title>
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

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z' fill='%23006ce4' fill-opacity='0.1' fill-rule='evenodd'/%3E%3C/svg%3E");
            opacity: 0.1;
        }

        .welcome-content h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .welcome-content p {
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }

        .student-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        .class-rep-badge {
            background: var(--booking-yellow);
            color: var(--booking-gray-500);
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-left: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--booking-white);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--booking-gray-200);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.total { background: #e6f2ff; color: var(--booking-blue); }
        .stat-icon.open { background: #e6ffe6; color: var(--booking-green); }
        .stat-icon.progress { background: #fff8e6; color: var(--booking-orange); }
        .stat-icon.resolved { background: #f0f0f0; color: var(--booking-gray-400); }

        .stat-content h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.85rem;
            color: var(--booking-gray-400);
            font-weight: 500;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
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

        .view-all-link {
            font-size: 0.85rem;
            color: var(--booking-blue-light);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .view-all-link:hover {
            text-decoration: underline;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            gap: 0.75rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--booking-gray-50);
            border: 1px solid var(--booking-gray-100);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--booking-gray-500);
            transition: var(--transition);
        }

        .action-btn:hover {
            background: var(--booking-white);
            border-color: var(--booking-blue-light);
            transform: translateX(4px);
        }

        .action-btn i {
            color: var(--booking-blue);
            width: 20px;
            text-align: center;
        }

        .action-text {
            flex: 1;
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Tickets List */
        .tickets-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .ticket-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
            border-left: 4px solid;
            transition: var(--transition);
        }

        .ticket-item:hover {
            background: var(--booking-white);
            box-shadow: var(--shadow-sm);
        }

        .ticket-item.open { border-left-color: var(--booking-green); }
        .ticket-item.in_progress { border-left-color: var(--booking-orange); }
        .ticket-item.resolved { border-left-color: var(--booking-gray-300); }

        .ticket-info {
            flex: 1;
        }

        .ticket-info h4 {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .ticket-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--booking-gray-400);
        }

        .ticket-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-open { background: #e6ffe6; color: var(--booking-green); }
        .status-in_progress { background: #fff8e6; color: var(--booking-orange); }
        .status-resolved { background: #f0f0f0; color: var(--booking-gray-400); }

        /* Events List */
        .events-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .event-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--booking-gray-50);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .event-item:hover {
            background: var(--booking-white);
            box-shadow: var(--shadow-sm);
        }

        .event-date {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 60px;
            background: linear-gradient(135deg, var(--booking-blue) 0%, var(--booking-blue-light) 100%);
            color: white;
            border-radius: var(--border-radius);
            padding: 0.75rem;
        }

        .event-date .day {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1;
        }

        .event-date .month {
            font-size: 0.75rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .event-details {
            flex: 1;
        }

        .event-details h4 {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .event-details p {
            font-size: 0.8rem;
            color: var(--booking-gray-400);
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .event-time {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            color: var(--booking-blue-light);
            font-weight: 500;
        }

        /* Primary Action Button */
        .primary-action-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--booking-blue);
            color: white;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 107, 228, 0.3);
            transition: var(--transition);
            z-index: 90;
        }

        .primary-action-btn:hover {
            background: var(--booking-blue-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 107, 228, 0.4);
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--booking-white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--booking-gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--booking-gray-500);
        }

        .modal-close {
            background: none;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--booking-gray-400);
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--booking-gray-50);
            color: var(--booking-gray-500);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            max-height: calc(90vh - 120px);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--booking-gray-500);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--booking-gray-200);
            border-radius: var(--border-radius);
            background: var(--booking-white);
            color: var(--booking-gray-500);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--booking-blue);
            box-shadow: 0 0 0 3px rgba(0, 107, 228, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
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

        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid;
            background: var(--booking-white);
        }

        .alert-success {
            border-color: var(--booking-green);
            background: #f0fffc;
            color: var(--booking-green);
        }

        .alert-error {
            border-color: var(--booking-orange);
            background: #fff5f5;
            color: var(--booking-orange);
        }

        .alert i {
            font-size: 1rem;
            margin-top: 0.125rem;
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

        .empty-state p {
            font-size: 0.9rem;
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
            
            .welcome-banner {
                padding: 1.5rem;
            }
            
            .welcome-content h1 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .primary-action-btn {
                bottom: 1rem;
                right: 1rem;
                width: 48px;
                height: 48px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .user-name, .user-role {
                display: none;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
<a href="dashboard.php" class="logo">
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
    <a href="../auth/logout.php" class="logout-btn" title="Logout">
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
                    <a href="#" class="nav-link active">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tickets.php" class="nav-link">
                        <i class="fas fa-ticket-alt"></i>
                        My Tickets
                    </a>
                </li>
                <li class="nav-item">
                    <a href="financial_aid.php" class="nav-link">
                        <i class="fas fa-hand-holding-usd"></i>
                        Financial Aid
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </a>
                </li>
                <?php if ($is_class_rep): ?>
                <li class="nav-item">
                    <a href="class_rep_dashboard.php" class="nav-link">
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
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-content">
                <h1>Welcome back, <?php echo safe_display($student_name); ?>!</h1>
                <p><?php echo safe_display($program); ?> • Year <?php echo safe_display($academic_year); ?></p>
                <div class="student-badge">
                    <i class="fas fa-id-card"></i>
                    <?php echo safe_display($reg_number); ?>
                    <?php if ($is_class_rep): ?>
                    <span class="class-rep-badge">
                        <i class="fas fa-user-shield"></i>
                        Class Representative
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon total">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $ticket_stats['total'] ?? 0; ?></h3>
                        <p>Total Tickets</p>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon open">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $ticket_stats['open'] ?? 0; ?></h3>
                        <p>Open Tickets</p>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon progress">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $ticket_stats['in_progress'] ?? 0; ?></h3>
                        <p>In Progress</p>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon resolved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $ticket_stats['resolved'] ?? 0; ?></h3>
                        <p>Resolved</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Quick Actions -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </h3>
                </div>
                <div class="card-body">
                    <div class="quick-actions-grid">
                        <a href="tickets.php" class="action-btn">
                            <i class="fas fa-ticket-alt"></i>
                            <span class="action-text">View All Tickets</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <a href="profile.php" class="action-btn">
                            <i class="fas fa-user"></i>
                            <span class="action-text">Update Profile</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <a href="calendar.php" class="action-btn">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="action-text">Academic Calendar</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <a href="announcements.php" class="action-btn">
                            <i class="fas fa-bullhorn"></i>
                            <span class="action-text">Campus News</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Tickets -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        Recent Tickets
                    </h3>
                    <a href="tickets.php" class="view-all-link">View all →</a>
                </div>
                <div class="card-body">
                    <div class="tickets-list">
                        <?php if (empty($recent_tickets)): ?>
                            <div class="empty-state">
                                <i class="fas fa-ticket-alt"></i>
                                <p>No tickets submitted yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_tickets as $ticket): ?>
                                <div class="ticket-item <?php echo $ticket['ticket_status']; ?>">
                                    <div class="ticket-info">
                                        <h4><?php echo safe_display($ticket['subject']); ?></h4>
                                        <div class="ticket-meta">
                                            <span><?php echo safe_display($ticket['category_name']); ?></span>
                                            <span><?php echo date('M j', strtotime($ticket['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="ticket-status status-<?php echo str_replace('_', '-', $ticket['ticket_status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Events -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        Upcoming Events
                    </h3>
                    <a href="calendar.php" class="view-all-link">View all →</a>
                </div>
                <div class="card-body">
                    <div class="events-list">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar"></i>
                                <p>No upcoming events</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): ?>
                                <div class="event-item">
                                    <div class="event-date">
                                        <span class="day"><?php echo date('j', strtotime($event['event_date'])); ?></span>
                                        <span class="month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                                    </div>
                                    <div class="event-details">
                                        <h4><?php echo safe_display($event['title']); ?></h4>
                                        <p><?php echo safe_display($event['description']); ?></p>
                                        <div class="event-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Primary Action Button -->
    <a href="#" class="primary-action-btn" onclick="openTicketModal(event)">
        <i class="fas fa-plus"></i>
    </a>

    <!-- Ticket Modal -->
    <div id="ticketModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Submit New Support Ticket</h3>
                <button class="modal-close" onclick="closeTicketModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="ticketForm">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo safe_display($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Brief description of your issue" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" placeholder="Provide detailed information about your issue..." rows="4" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Preferred Contact Method</label>
                        <select name="preferred_contact" class="form-control" required>
                            <option value="email" selected>Email</option>
                            <option value="sms">SMS</option>
                            <option value="phone">Phone Call</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeTicketModal()">Cancel</button>
                        <button type="submit" name="submit_ticket" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                            Submit Ticket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openTicketModal(e) {
            if (e) e.preventDefault();
            document.getElementById('ticketModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeTicketModal() {
            document.getElementById('ticketModal').style.display = 'none';
            document.getElementById('ticketForm').reset();
            document.body.style.overflow = 'auto';
        }

        // Close modal on ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeTicketModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('ticketModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeTicketModal();
            }
        });

        // Auto-open ticket modal if there's an error
        <?php if (isset($error_message)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                openTicketModal();
            }, 500);
        });
        <?php endif; ?>

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

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
        document.querySelectorAll('.stat-card, .dashboard-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>