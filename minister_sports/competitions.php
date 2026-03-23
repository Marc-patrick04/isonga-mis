<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Sports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_sports') {
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

// Handle form actions
$action = $_GET['action'] ?? '';
$competition_id = $_GET['id'] ?? '';

// Add new competition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    try {
        $title = $_POST['title'];
        $sport_type = $_POST['sport_type'];
        $competition_type = $_POST['competition_type'];
        $organizer = $_POST['organizer'];
        $location = $_POST['location'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $entry_fee = $_POST['entry_fee'] ?? 0;
        $prize_money = $_POST['prize_money'] ?? 0;
        $description = $_POST['description'];
        
        $stmt = $pdo->prepare("
            INSERT INTO sports_competitions 
            (title, sport_type, competition_type, organizer, location, start_date, end_date, entry_fee, prize_money, description, created_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming')
        ");
        $stmt->execute([$title, $sport_type, $competition_type, $organizer, $location, $start_date, $end_date, $entry_fee, $prize_money, $description, $user_id]);
        
        $_SESSION['success_message'] = "Competition created successfully!";
        header('Location: competitions.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error creating competition: " . $e->getMessage();
    }
}

// Update competition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    try {
        $title = $_POST['title'];
        $sport_type = $_POST['sport_type'];
        $competition_type = $_POST['competition_type'];
        $organizer = $_POST['organizer'];
        $location = $_POST['location'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $entry_fee = $_POST['entry_fee'] ?? 0;
        $prize_money = $_POST['prize_money'] ?? 0;
        $description = $_POST['description'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("
            UPDATE sports_competitions 
            SET title = ?, sport_type = ?, competition_type = ?, organizer = ?, location = ?, 
                start_date = ?, end_date = ?, entry_fee = ?, prize_money = ?, description = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$title, $sport_type, $competition_type, $organizer, $location, $start_date, $end_date, $entry_fee, $prize_money, $description, $status, $competition_id]);
        
        $_SESSION['success_message'] = "Competition updated successfully!";
        header('Location: competitions.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating competition: " . $e->getMessage();
    }
}

// Delete competition
if ($action === 'delete' && $competition_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM sports_competitions WHERE id = ?");
        $stmt->execute([$competition_id]);
        
        $_SESSION['success_message'] = "Competition deleted successfully!";
        header('Location: competitions.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting competition: " . $e->getMessage();
    }
}

// Update competition status
if ($action === 'update_status' && $competition_id) {
    try {
        $status = $_GET['status'];
        $stmt = $pdo->prepare("UPDATE sports_competitions SET status = ? WHERE id = ?");
        $stmt->execute([$status, $competition_id]);
        
        $_SESSION['success_message'] = "Competition status updated successfully!";
        header('Location: competitions.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating competition status: " . $e->getMessage();
    }
}

// Get competitions data
try {
    // All competitions
    $stmt = $pdo->query("
        SELECT sc.*, u.full_name as created_by_name
        FROM sports_competitions sc
        LEFT JOIN users u ON sc.created_by = u.id
        ORDER BY sc.start_date DESC, sc.created_at DESC
    ");
    $competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sports types for dropdown
    $sport_types = ['Football', 'Basketball', 'Volleyball', 'Tennis', 'Athletics', 'Swimming', 'Rugby', 'Cricket', 'Handball', 'Table Tennis', 'Badminton', 'Other'];
    
    // Competition types
    $competition_types = ['tournament', 'league', 'friendly', 'championship', 'cup'];
    
    // Get competition for editing
    if ($action === 'edit' && $competition_id) {
        $stmt = $pdo->prepare("SELECT * FROM sports_competitions WHERE id = ?");
        $stmt->execute([$competition_id]);
        $edit_competition = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get competition for viewing
    if ($action === 'view' && $competition_id) {
        $stmt = $pdo->prepare("
            SELECT sc.*, u.full_name as created_by_name
            FROM sports_competitions sc
            LEFT JOIN users u ON sc.created_by = u.id
            WHERE sc.id = ?
        ");
        $stmt->execute([$competition_id]);
        $current_competition = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get participating teams for this competition
        if ($current_competition && $current_competition['participating_teams']) {
            $team_ids = json_decode($current_competition['participating_teams'], true);
            if (!empty($team_ids)) {
                $placeholders = str_repeat('?,', count($team_ids) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT id, team_name, sport_type 
                    FROM sports_teams 
                    WHERE id IN ($placeholders) AND status = 'active'
                ");
                $stmt->execute($team_ids);
                $participating_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
    
    // Statistics
    $total_competitions = count($competitions);
    $upcoming_competitions = array_filter($competitions, function($comp) {
        return $comp['status'] === 'upcoming' && strtotime($comp['start_date']) >= time();
    });
    $ongoing_competitions = array_filter($competitions, function($comp) {
        return $comp['status'] === 'ongoing';
    });
    $completed_competitions = array_filter($competitions, function($comp) {
        return $comp['status'] === 'completed';
    });
    
    $upcoming_count = count($upcoming_competitions);
    $ongoing_count = count($ongoing_competitions);
    $completed_count = count($completed_competitions);
    
} catch (PDOException $e) {
    error_log("Competitions data error: " . $e->getMessage());
    $competitions = [];
    $sport_types = $competition_types = [];
    $total_competitions = $upcoming_count = $ongoing_count = $completed_count = 0;
}

// Unread messages count
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
    $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sports Competitions Management - Isonga RPSU</title>
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
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
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

        .btn-warning {
            background: var(--warning);
            color: var(--text-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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

        /* Card */
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
        }

        .table tr:hover {
            background: var(--light-gray);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-upcoming {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-ongoing {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .status-cancelled {
            background: #f8d7da;
            color: var(--danger);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .action-btn {
            padding: 0.25rem 0.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .action-btn.view {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .action-btn.edit {
            background: #fff3cd;
            color: var(--warning);
        }

        .action-btn.delete {
            background: #f8d7da;
            color: var(--danger);
        }

        .action-btn.status {
            background: #d4edda;
            color: var(--success);
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        /* Competition Cards */
        .competition-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .competition-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border-left: 4px solid var(--primary-blue);
        }

        .competition-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .competition-card.upcoming {
            border-left-color: var(--primary-blue);
        }

        .competition-card.ongoing {
            border-left-color: var(--warning);
        }

        .competition-card.completed {
            border-left-color: var(--success);
        }

        .competition-card.cancelled {
            border-left-color: var(--danger);
        }

        .competition-header {
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .competition-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .competition-sport {
            font-size: 0.8rem;
            color: var(--dark-gray);
            background: var(--light-blue);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            display: inline-block;
        }

        .competition-status {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
        }

        .competition-body {
            padding: 1rem;
        }

        .competition-info {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .info-item i {
            width: 16px;
            color: var(--dark-gray);
        }

        .competition-footer {
            padding: 1rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Alerts */
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

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--dark-gray);
            cursor: pointer;
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

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .competition-cards {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .competition-cards {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .tabs {
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
            
            .page-actions {
                width: 100%;
                justify-content: space-between;
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
                    <h1>Isonga - Minister of Sports</h1>
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
                        <div class="user-role">Minister of Sports</div>
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
                    <a href="teams.php">
                        <i class="fas fa-users"></i>
                        <span>Sports Teams</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php">
                        <i class="fas fa-building"></i>
                        <span>Sports Facilities</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-music"></i>
                        <span>Entertainment Clubs</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="competitions.php" class="active">
                        <i class="fas fa-trophy"></i>
                        <span>Competitions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="equipment.php">
                        <i class="fas fa-baseball-ball"></i>
                        <span>Equipment</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Funding & Budget</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="training.php">
                        <i class="fas fa-running"></i>
                        <span>Training</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
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
                <div class="page-title">
                    <h1>Sports Competitions Management 🏆</h1>
                    <p>Organize and manage all sports competitions and tournaments</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addCompetitionModal')">
                        <i class="fas fa-plus"></i> New Competition
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_competitions; ?></div>
                        <div class="stat-label">Total Competitions</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $upcoming_count; ?></div>
                        <div class="stat-label">Upcoming</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-running"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $ongoing_count; ?></div>
                        <div class="stat-label">Ongoing</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-flag-checkered"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $completed_count; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('all')">All Competitions</button>
                <button class="tab" onclick="switchTab('upcoming')">Upcoming (<?php echo $upcoming_count; ?>)</button>
                <button class="tab" onclick="switchTab('ongoing')">Ongoing (<?php echo $ongoing_count; ?>)</button>
                <button class="tab" onclick="switchTab('completed')">Completed (<?php echo $completed_count; ?>)</button>
            </div>

            <!-- All Competitions Tab -->
            <div id="all" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>All Competitions</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($competitions)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Competitions Found</h3>
                                <p>Get started by creating your first sports competition.</p>
                                <button class="btn btn-primary" onclick="openModal('addCompetitionModal')" style="margin-top: 1rem;">
                                    <i class="fas fa-plus"></i> Create First Competition
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="competition-cards">
                                <?php foreach ($competitions as $competition): ?>
                                    <div class="competition-card <?php echo $competition['status']; ?>">
                                        <div class="competition-header">
                                            <div>
                                                <div class="competition-title"><?php echo htmlspecialchars($competition['title']); ?></div>
                                                <div class="competition-sport"><?php echo htmlspecialchars($competition['sport_type']); ?></div>
                                            </div>
                                            <span class="status-badge status-<?php echo $competition['status']; ?>">
                                                <?php echo ucfirst($competition['status']); ?>
                                            </span>
                                        </div>
                                        <div class="competition-body">
                                            <div class="competition-info">
                                                <div class="info-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars($competition['location']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <span>
                                                        <?php echo date('M j, Y', strtotime($competition['start_date'])); ?>
                                                        <?php if ($competition['end_date'] && $competition['end_date'] != $competition['start_date']): ?>
                                                            - <?php echo date('M j, Y', strtotime($competition['end_date'])); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-users"></i>
                                                    <span><?php echo ucfirst($competition['competition_type']); ?> Competition</span>
                                                </div>
                                                <?php if ($competition['prize_money'] > 0): ?>
                                                    <div class="info-item">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                        <span>Prize: RWF <?php echo number_format($competition['prize_money'], 2); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($competition['description']): ?>
                                                <p style="font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0;">
                                                    <?php echo htmlspecialchars(substr($competition['description'], 0, 100)); ?>...
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="competition-footer">
                                            <small style="color: var(--dark-gray);">
                                                Created by <?php echo htmlspecialchars($competition['created_by_name'] ?? 'System'); ?>
                                            </small>
                                            <div class="action-buttons">
                                                <a href="competitions.php?action=view&id=<?php echo $competition['id']; ?>" class="action-btn view" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="competitions.php?action=edit&id=<?php echo $competition['id']; ?>" class="action-btn edit" title="Edit Competition">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($competition['status'] === 'upcoming'): ?>
                                                    <a href="competitions.php?action=update_status&id=<?php echo $competition['id']; ?>&status=ongoing" class="action-btn status" title="Start Competition">
                                                        <i class="fas fa-play"></i>
                                                    </a>
                                                <?php elseif ($competition['status'] === 'ongoing'): ?>
                                                    <a href="competitions.php?action=update_status&id=<?php echo $competition['id']; ?>&status=completed" class="action-btn status" title="Complete Competition">
                                                        <i class="fas fa-flag-checkered"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button class="action-btn delete" onclick="confirmDelete(<?php echo $competition['id']; ?>)" title="Delete Competition">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Competitions Tab -->
            <div id="upcoming" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Upcoming Competitions</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_competitions)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Upcoming Competitions</h3>
                                <p>Schedule new competitions to see them here.</p>
                            </div>
                        <?php else: ?>
                            <div class="competition-cards">
                                <?php foreach ($upcoming_competitions as $competition): ?>
                                    <div class="competition-card upcoming">
                                        <div class="competition-header">
                                            <div>
                                                <div class="competition-title"><?php echo htmlspecialchars($competition['title']); ?></div>
                                                <div class="competition-sport"><?php echo htmlspecialchars($competition['sport_type']); ?></div>
                                            </div>
                                            <span class="status-badge status-upcoming">Upcoming</span>
                                        </div>
                                        <div class="competition-body">
                                            <div class="competition-info">
                                                <div class="info-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars($competition['location']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <span><?php echo date('M j, Y', strtotime($competition['start_date'])); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-clock"></i>
                                                    <span>Starts in <?php echo date_diff(new DateTime(), new DateTime($competition['start_date']))->format('%a days'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="competition-footer">
                                            <div class="action-buttons">
                                                <a href="competitions.php?action=view&id=<?php echo $competition['id']; ?>" class="action-btn view">
                                                    <i class="fas fa-eye"></i> Details
                                                </a>
                                                <a href="competitions.php?action=update_status&id=<?php echo $competition['id']; ?>&status=ongoing" class="action-btn status">
                                                    <i class="fas fa-play"></i> Start
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Ongoing Competitions Tab -->
            <div id="ongoing" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Ongoing Competitions</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ongoing_competitions)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <i class="fas fa-running" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Ongoing Competitions</h3>
                                <p>Start competitions to track ongoing events.</p>
                            </div>
                        <?php else: ?>
                            <div class="competition-cards">
                                <?php foreach ($ongoing_competitions as $competition): ?>
                                    <div class="competition-card ongoing">
                                        <div class="competition-header">
                                            <div>
                                                <div class="competition-title"><?php echo htmlspecialchars($competition['title']); ?></div>
                                                <div class="competition-sport"><?php echo htmlspecialchars($competition['sport_type']); ?></div>
                                            </div>
                                            <span class="status-badge status-ongoing">Ongoing</span>
                                        </div>
                                        <div class="competition-body">
                                            <div class="competition-info">
                                                <div class="info-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars($competition['location']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <span>Started: <?php echo date('M j, Y', strtotime($competition['start_date'])); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-fire"></i>
                                                    <span>Live Now</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="competition-footer">
                                            <div class="action-buttons">
                                                <a href="competitions.php?action=view&id=<?php echo $competition['id']; ?>" class="action-btn view">
                                                    <i class="fas fa-eye"></i> Details
                                                </a>
                                                <a href="competitions.php?action=update_status&id=<?php echo $competition['id']; ?>&status=completed" class="action-btn status">
                                                    <i class="fas fa-flag-checkered"></i> Complete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Completed Competitions Tab -->
            <div id="completed" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Completed Competitions</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($completed_competitions)): ?>
                            <div style="text-align: center; color: var(--dark-gray); padding: 3rem;">
                                <i class="fas fa-flag-checkered" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Completed Competitions</h3>
                                <p>Completed competitions will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="competition-cards">
                                <?php foreach ($completed_competitions as $competition): ?>
                                    <div class="competition-card completed">
                                        <div class="competition-header">
                                            <div>
                                                <div class="competition-title"><?php echo htmlspecialchars($competition['title']); ?></div>
                                                <div class="competition-sport"><?php echo htmlspecialchars($competition['sport_type']); ?></div>
                                            </div>
                                            <span class="status-badge status-completed">Completed</span>
                                        </div>
                                        <div class="competition-body">
                                            <div class="competition-info">
                                                <div class="info-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars($competition['location']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <span>Completed: <?php echo date('M j, Y', strtotime($competition['end_date'] ?: $competition['start_date'])); ?></span>
                                                </div>
                                                <?php if ($competition['results']): ?>
                                                    <div class="info-item">
                                                        <i class="fas fa-medal"></i>
                                                        <span>Results Available</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="competition-footer">
                                            <div class="action-buttons">
                                                <a href="competitions.php?action=view&id=<?php echo $competition['id']; ?>" class="action-btn view">
                                                    <i class="fas fa-eye"></i> View Results
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Competition Modal -->
    <div class="modal" id="addCompetitionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Competition</h3>
                <button class="modal-close" onclick="closeModal('addCompetitionModal')">&times;</button>
            </div>
            <form method="POST" action="competitions.php?action=add">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="title">Competition Title *</label>
                        <input type="text" class="form-control" id="title" name="title" placeholder="e.g., Inter-Department Football Tournament" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="sport_type">Sport Type *</label>
                            <select class="form-select" id="sport_type" name="sport_type" required>
                                <option value="">Select Sport Type</option>
                                <?php foreach ($sport_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="competition_type">Competition Type *</label>
                            <select class="form-select" id="competition_type" name="competition_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($competition_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="organizer">Organizer</label>
                            <input type="text" class="form-control" id="organizer" name="organizer" placeholder="e.g., Sports Committee">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="location">Location *</label>
                            <input type="text" class="form-control" id="location" name="location" placeholder="e.g., Main Football Field" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="start_date">Start Date *</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="end_date">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="entry_fee">Entry Fee (RWF)</label>
                            <input type="number" class="form-control" id="entry_fee" name="entry_fee" placeholder="0.00" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="prize_money">Prize Money (RWF)</label>
                            <input type="number" class="form-control" id="prize_money" name="prize_money" placeholder="0.00" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="description">Competition Description</label>
                        <textarea class="form-control form-textarea" id="description" name="description" placeholder="Describe the competition, rules, and any important information..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addCompetitionModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Competition</button>
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

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // Tab Switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Delete Confirmation
        function confirmDelete(competitionId) {
            if (confirm('Are you sure you want to delete this competition? This action cannot be undone.')) {
                window.location.href = 'competitions.php?action=delete&id=' + competitionId;
            }
        }

        // Set minimum date for start date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('start_date').min = today;
            
            // Update end date min when start date changes
            document.getElementById('start_date').addEventListener('change', function() {
                document.getElementById('end_date').min = this.value;
            });
        });

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>