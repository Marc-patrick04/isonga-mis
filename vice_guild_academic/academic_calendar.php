<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
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
$event_id = $_GET['event_id'] ?? 0;
$date = $_GET['date'] ?? date('Y-m-d');

// Handle event operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save_event') {
        $event_data = $_POST;
        
        try {
            // Check if we're dealing with a club activity or academic event
            if (isset($event_data['club_id']) && $event_data['club_id']) {
                // This is a club activity
                if ($event_id) {
                    // Update existing activity
                    $stmt = $pdo->prepare("
                        UPDATE club_activities 
                        SET title = ?, description = ?, activity_type = ?, activity_date = ?,
                            start_time = ?, end_time = ?, location = ?, budget = ?, status = ?, updated_at = NOW()
                        WHERE id = ? AND club_id = ?
                    ");
                    $stmt->execute([
                        $event_data['title'],
                        $event_data['description'],
                        $event_data['activity_type'],
                        $event_data['activity_date'],
                        $event_data['start_time'],
                        $event_data['end_time'],
                        $event_data['location'],
                        $event_data['budget'] ?: 0,
                        $event_data['status'],
                        $event_id,
                        $event_data['club_id']
                    ]);
                } else {
                    // Create new activity
                    $stmt = $pdo->prepare("
                        INSERT INTO club_activities (club_id, title, description, activity_type, activity_date,
                                                  start_time, end_time, location, budget, status, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $event_data['club_id'],
                        $event_data['title'],
                        $event_data['description'],
                        $event_data['activity_type'],
                        $event_data['activity_date'],
                        $event_data['start_time'],
                        $event_data['end_time'],
                        $event_data['location'],
                        $event_data['budget'] ?: 0,
                        $user_id
                    ]);
                    
                    $event_id = $pdo->lastInsertId();
                }
            } else {
                // This is an academic event (we'll need to create an academic_events table)
                if ($event_id) {
                    // Update existing academic event
                    $stmt = $pdo->prepare("
                        UPDATE academic_events 
                        SET title = ?, description = ?, event_type = ?, event_date = ?,
                            start_time = ?, end_time = ?, location = ?, priority = ?, status = ?, updated_at = NOW()
                        WHERE id = ? AND created_by = ?
                    ");
                    $stmt->execute([
                        $event_data['title'],
                        $event_data['description'],
                        $event_data['event_type'],
                        $event_data['event_date'],
                        $event_data['start_time'],
                        $event_data['end_time'],
                        $event_data['location'],
                        $event_data['priority'],
                        $event_data['status'],
                        $event_id,
                        $user_id
                    ]);
                } else {
                    // Create new academic event
                    $stmt = $pdo->prepare("
                        INSERT INTO academic_events (title, description, event_type, event_date,
                                                   start_time, end_time, location, priority, status, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $event_data['title'],
                        $event_data['description'],
                        $event_data['event_type'],
                        $event_data['event_date'],
                        $event_data['start_time'],
                        $event_data['end_time'],
                        $event_data['location'],
                        $event_data['priority'],
                        $user_id
                    ]);
                    
                    $event_id = $pdo->lastInsertId();
                }
            }
            
            $_SESSION['success_message'] = "Event " . ($event_id ? 'updated' : 'created') . " successfully!";
            header("Location: academic_calendar.php?date=" . $event_data['event_date']);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error saving event: " . $e->getMessage();
        }
    }
    
    if ($action === 'delete_event' && $event_id) {
        try {
            // Try to delete from club_activities first
            $stmt = $pdo->prepare("DELETE FROM club_activities WHERE id = ? AND created_by = ?");
            $stmt->execute([$event_id, $user_id]);
            
            if ($stmt->rowCount() === 0) {
                // If not found in club_activities, try academic_events
                $stmt = $pdo->prepare("DELETE FROM academic_events WHERE id = ? AND created_by = ?");
                $stmt->execute([$event_id, $user_id]);
            }
            
            $_SESSION['success_message'] = "Event deleted successfully!";
            header('Location: academic_calendar.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting event: " . $e->getMessage();
        }
    }
}

// Create academic_events table if it doesn't exist
try {
    $stmt = $pdo->query("
        CREATE TABLE IF NOT EXISTS academic_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            event_type ENUM('exam', 'holiday', 'meeting', 'workshop', 'deadline', 'celebration', 'other') DEFAULT 'other',
            event_date DATE NOT NULL,
            start_time TIME,
            end_time TIME,
            location VARCHAR(255),
            priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            status ENUM('scheduled', 'ongoing', 'completed', 'cancelled') DEFAULT 'scheduled',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Academic events table creation error: " . $e->getMessage());
}

// Get view type (month, week, day)
$view = $_GET['view'] ?? 'month';
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('n');

// Calculate calendar dates based on view
if ($view === 'month') {
    $first_day = date('Y-m-01', strtotime("$year-$month-01"));
    $last_day = date('Y-m-t', strtotime($first_day));
    $start_date = date('Y-m-d', strtotime('last sunday', strtotime($first_day)));
    $end_date = date('Y-m-d', strtotime('next saturday', strtotime($last_day)));
} elseif ($view === 'week') {
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    $start_date = $week_start;
    $end_date = $week_end;
} else { // day view
    $start_date = $date;
    $end_date = $date;
}

// Get events for the selected period
$events = [];

// Get club activities
try {
    $stmt = $pdo->prepare("
        SELECT 
            ca.id,
            ca.title,
            ca.description,
            ca.activity_type as event_type,
            ca.activity_date as event_date,
            ca.start_time,
            ca.end_time,
            ca.location,
            ca.status,
            ca.budget,
            c.name as club_name,
            c.department,
            'club_activity' as event_source,
            ca.created_by
        FROM club_activities ca
        JOIN clubs c ON ca.club_id = c.id
        WHERE ca.activity_date BETWEEN ? AND ?
        AND (ca.created_by = ? OR ? = 1)
        ORDER BY ca.activity_date, ca.start_time
    ");
    $stmt->execute([$start_date, $end_date, $user_id, $user_id]);
    $club_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $events = array_merge($events, $club_activities);
} catch (PDOException $e) {
    error_log("Club activities query error: " . $e->getMessage());
}

// Get academic events
try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            title,
            description,
            event_type,
            event_date,
            start_time,
            end_time,
            location,
            priority,
            status,
            NULL as club_name,
            NULL as department,
            'academic_event' as event_source,
            created_by
        FROM academic_events
        WHERE event_date BETWEEN ? AND ?
        AND (created_by = ? OR ? = 1)
        ORDER BY event_date, start_time
    ");
    $stmt->execute([$start_date, $end_date, $user_id, $user_id]);
    $academic_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $events = array_merge($events, $academic_events);
} catch (PDOException $e) {
    error_log("Academic events query error: " . $e->getMessage());
}

// Get current event for editing
$current_event = null;
if ($event_id && $action === 'edit') {
    try {
        // Try to get from club_activities first
        $stmt = $pdo->prepare("
            SELECT ca.*, c.name as club_name, c.id as club_id, 'club_activity' as event_source
            FROM club_activities ca
            JOIN clubs c ON ca.club_id = c.id
            WHERE ca.id = ? AND ca.created_by = ?
        ");
        $stmt->execute([$event_id, $user_id]);
        $current_event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_event) {
            // If not found, try academic_events
            $stmt = $pdo->prepare("
                SELECT *, 'academic_event' as event_source
                FROM academic_events
                WHERE id = ? AND created_by = ?
            ");
            $stmt->execute([$event_id, $user_id]);
            $current_event = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Current event query error: " . $e->getMessage());
    }
}

// Get clubs for event creation
try {
    $stmt = $pdo->query("SELECT id, name FROM clubs WHERE status = 'active' ORDER BY name");
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Clubs query error: " . $e->getMessage());
    $clubs = [];
}

// Group events by date for calendar display
$events_by_date = [];
foreach ($events as $event) {
    $event_date = $event['event_date'];
    if (!isset($events_by_date[$event_date])) {
        $events_by_date[$event_date] = [];
    }
    $events_by_date[$event_date][] = $event;
}

// Calculate calendar grid for month view
if ($view === 'month') {
    $calendar_days = [];
    $current = strtotime($start_date);
    $end = strtotime($end_date);
    
    while ($current <= $end) {
        $current_date = date('Y-m-d', $current);
        $calendar_days[] = [
            'date' => $current_date,
            'day' => date('j', $current),
            'month' => date('n', $current),
            'year' => date('Y', $current),
            'is_current_month' => date('n', $current) == $month,
            'is_today' => $current_date == date('Y-m-d'),
            'events' => $events_by_date[$current_date] ?? []
        ];
        $current = strtotime('+1 day', $current);
    }
}

// Get upcoming events (next 7 days)
try {
    $upcoming_start = date('Y-m-d');
    $upcoming_end = date('Y-m-d', strtotime('+7 days'));
    
    $stmt = $pdo->prepare("
        (SELECT 
            ca.id,
            ca.title,
            ca.description,
            ca.activity_type as event_type,
            ca.activity_date as event_date,
            ca.start_time,
            ca.end_time,
            ca.location,
            ca.status,
            c.name as club_name,
            'club_activity' as event_source
         FROM club_activities ca
         JOIN clubs c ON ca.club_id = c.id
         WHERE ca.activity_date BETWEEN ? AND ?
         AND ca.status = 'scheduled'
         AND (ca.created_by = ? OR ? = 1))
         
        UNION
         
        (SELECT 
            id,
            title,
            description,
            event_type,
            event_date,
            start_time,
            end_time,
            location,
            status,
            NULL as club_name,
            'academic_event' as event_source
         FROM academic_events
         WHERE event_date BETWEEN ? AND ?
         AND status = 'scheduled'
         AND (created_by = ? OR ? = 1))
         
        ORDER BY event_date, start_time
        LIMIT 10
    ");
    $stmt->execute([
        $upcoming_start, $upcoming_end, $user_id, $user_id,
        $upcoming_start, $upcoming_end, $user_id, $user_id
    ]);
    $upcoming_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Upcoming events query error: " . $e->getMessage());
    $upcoming_events = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Calendar - Isonga RPSU</title>
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
            --academic-primary: #4CAF50;
            --academic-secondary: #66BB6A;
            --academic-accent: #2E7D32;
            --academic-light: #1B3E1B;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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

        /* Header and Sidebar styles (same as previous pages) */
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
            color: var(--academic-primary);
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
            border-color: var(--academic-primary);
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
            background: var(--academic-primary);
            color: white;
            transform: translateY(-2px);
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

        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

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
            background: var(--academic-light);
            border-left-color: var(--academic-primary);
            color: var(--academic-primary);
        }

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
        }

        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
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
            border: 1px solid var(--academic-primary);
            color: var(--academic-primary);
        }

        .btn-outline:hover {
            background: var(--academic-light);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        /* Calendar Controls */
        .calendar-controls {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .view-controls {
            display: flex;
            gap: 0.5rem;
        }

        .view-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            color: var(--text-dark);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .view-btn:hover {
            border-color: var(--academic-primary);
            color: var(--academic-primary);
        }

        .view-btn.active {
            background: var(--academic-primary);
            border-color: var(--academic-primary);
            color: white;
        }

        .navigation-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .current-period {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 200px;
            text-align: center;
        }

        .nav-btn {
            width: 36px;
            height: 36px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .nav-btn:hover {
            border-color: var(--academic-primary);
            color: var(--academic-primary);
        }

        .today-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--academic-primary);
            background: transparent;
            color: var(--academic-primary);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .today-btn:hover {
            background: var(--academic-light);
        }

        /* Calendar Grid */
        .calendar-container {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 1.5rem;
        }

        .calendar-main {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        /* Month View */
        .month-view {
            width: 100%;
        }

        .month-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: var(--academic-light);
            border-bottom: 1px solid var(--medium-gray);
        }

        .weekday-header {
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .month-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            grid-template-rows: repeat(6, 1fr);
            min-height: 600px;
        }

        .calendar-day {
            border: 1px solid var(--medium-gray);
            padding: 0.5rem;
            min-height: 120px;
            transition: var(--transition);
            background: var(--white);
        }

        .calendar-day:hover {
            background: var(--light-gray);
        }

        .calendar-day.other-month {
            background: var(--light-gray);
            color: var(--dark-gray);
        }

        .calendar-day.today {
            background: var(--academic-light);
            border-color: var(--academic-primary);
        }

        .day-number {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .day-events {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .day-event {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: var(--transition);
            border-left: 3px solid;
        }

        .day-event:hover {
            transform: translateX(2px);
        }

        .event-club {
            border-left-color: var(--academic-primary);
            background: rgba(46, 125, 50, 0.1);
        }

        .event-academic {
            border-left-color: var(--primary-blue);
            background: rgba(0, 86, 179, 0.1);
        }

        .event-exam {
            border-left-color: var(--danger);
            background: rgba(220, 53, 69, 0.1);
        }

        .event-holiday {
            border-left-color: var(--warning);
            background: rgba(255, 193, 7, 0.1);
        }

        .event-title {
            font-weight: 600;
            margin-bottom: 0.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .event-time {
            color: var(--dark-gray);
            font-size: 0.6rem;
        }

        /* Week View */
        .week-view {
            display: none;
        }

        .week-header {
            display: grid;
            grid-template-columns: 100px repeat(7, 1fr);
            background: var(--academic-light);
            border-bottom: 1px solid var(--medium-gray);
        }

        .week-grid {
            display: grid;
            grid-template-columns: 100px repeat(7, 1fr);
            min-height: 600px;
        }

        .time-slot {
            border: 1px solid var(--medium-gray);
            padding: 0.5rem;
            min-height: 80px;
        }

        .time-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        /* Day View */
        .day-view {
            display: none;
        }

        .day-header {
            padding: 1rem;
            background: var(--academic-light);
            border-bottom: 1px solid var(--medium-gray);
            text-align: center;
        }

        .day-grid {
            min-height: 600px;
        }

        .hour-slot {
            border-bottom: 1px solid var(--medium-gray);
            padding: 1rem;
            min-height: 80px;
            display: flex;
            gap: 1rem;
        }

        .hour-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 80px;
        }

        .hour-events {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        /* Sidebar */
        .calendar-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .sidebar-header {
            padding: 1rem 1.25rem;
            background: var(--academic-light);
            border-bottom: 1px solid var(--medium-gray);
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .sidebar-body {
            padding: 1.25rem;
        }

        /* Upcoming Events */
        .upcoming-events {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .upcoming-event {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            transition: var(--transition);
            cursor: pointer;
        }

        .upcoming-event:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .event-date {
            text-align: center;
            min-width: 50px;
        }

        .event-day {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--academic-primary);
            line-height: 1;
        }

        .event-month {
            font-size: 0.7rem;
            color: var(--dark-gray);
            text-transform: uppercase;
            font-weight: 600;
        }

        .event-details {
            flex: 1;
        }

        .event-details h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .event-meta {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--academic-primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--dark-gray);
            font-weight: 500;
        }

        /* Event Form */
        .event-form-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .form-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--academic-light);
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .form-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-select, .form-input, .form-textarea {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
            font-family: inherit;
        }

        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--academic-primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.5rem;
            border-top: 1px solid var(--medium-gray);
            background: var(--light-gray);
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

        /* ── Mobile Nav Overlay ── */
        .mobile-nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
            backdrop-filter: blur(2px);
        }
        .mobile-nav-overlay.active { display: block; }

        /* ── Hamburger Button ── */
        .hamburger-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .hamburger-btn:hover {
            background: var(--academic-primary);
            color: white;
        }

        /* ── Sidebar Drawer ── */
        .sidebar { transition: transform 0.3s ease; }

        /* ── Tablet ── */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }

            .calendar-container {
                grid-template-columns: 1fr;
            }

            .calendar-sidebar {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 1.5rem;
            }
        }

        /* ── Drawer threshold ── */
        @media (max-width: 900px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 260px;
                height: 100vh;
                z-index: 200;
                transform: translateX(-100%);
                padding-top: 1rem;
                box-shadow: var(--shadow-lg);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .hamburger-btn {
                display: flex;
            }

            .main-content {
                height: auto;
                min-height: calc(100vh - 80px);
            }
        }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .page-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .page-actions .btn {
                flex: 1 1 auto;
                justify-content: center;
                min-width: 120px;
            }

            .calendar-controls {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }

            .navigation-controls {
                justify-content: space-between;
            }

            .current-period {
                min-width: 0;
                flex: 1;
                font-size: 0.9rem;
            }

            .view-controls {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 0.5rem;
            }

            .view-btn {
                text-align: center;
                justify-content: center;
            }

            .weekday-header {
                padding: 0.5rem 0.25rem;
                font-size: 0.65rem;
            }

            .month-grid {
                min-height: 400px;
            }

            .calendar-day {
                min-height: 80px;
                padding: 0.3rem;
            }

            .day-number {
                font-size: 0.8rem;
                margin-bottom: 0.25rem;
            }

            .week-header,
            .week-grid {
                grid-template-columns: 50px repeat(7, 1fr);
                font-size: 0.7rem;
                overflow-x: auto;
            }

            .hour-slot {
                padding: 0.5rem;
                gap: 0.5rem;
            }

            .hour-label {
                min-width: 50px;
                font-size: 0.75rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-wrap: wrap;
                justify-content: stretch;
            }

            .form-actions .btn {
                flex: 1 1 auto;
                justify-content: center;
            }

            .calendar-sidebar {
                grid-template-columns: 1fr;
            }
        }

        /* ── Small phones ── */
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .header {
                height: 68px;
            }

            .logos .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .weekday-header {
                padding: 0.4rem 0.1rem;
                font-size: 0.6rem;
            }

            .calendar-day {
                padding: 0.2rem;
                min-height: 60px;
            }

            .day-number {
                font-size: 0.7rem;
            }

            .day-event {
                padding: 0.1rem 0.2rem;
                font-size: 0.58rem;
                border-left-width: 2px;
            }

            .event-title {
                font-size: 0.58rem;
            }

            .day-event .event-time {
                display: none;
            }

            .month-grid {
                min-height: 340px;
            }

            .quick-stats {
                grid-template-columns: 1fr 1fr;
            }

            .upcoming-event {
                padding: 0.75rem;
                gap: 0.75rem;
            }
        }

        /* Show active view */
        .calendar-view {
            display: none;
        }

        .calendar-view.active {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="hamburger-btn" id="hamburgerBtn" title="Toggle Menu" aria-label="Open navigation menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Academic Affairs</h1>
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
                        <div class="user-role">Vice Guild Academic</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Mobile Nav Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay"></div>

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
    <a href="academic_meetings.php">
        <i class="fas fa-calendar-check"></i>
        <span>Meetings</span>
        <?php
        // Count upcoming meetings where user is invited
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as upcoming_meetings 
                FROM meeting_attendees ma 
                JOIN meetings m ON ma.meeting_id = m.id 
                WHERE ma.user_id = ? 
                AND m.meeting_date >= CURDATE() 
                AND m.status = 'scheduled'
                AND ma.attendance_status = 'invited'
            ");
            $stmt->execute([$user_id]);
            $upcoming_meetings = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming_meetings'];
        } catch (PDOException $e) {
            $upcoming_meetings = 0;
        }
        ?>
        <?php if ($upcoming_meetings > 0): ?>
            <span class="menu-badge"><?php echo $upcoming_meetings; ?></span>
        <?php endif; ?>
    </a>
</li>
                <li class="menu-item">
                    <a href="academic_tickets.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academic Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Academic Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Academic Clubs</span>
                    </a>
                </li>
                                                <li class="menu-item">
                    <a href="committee_budget_requests.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="performance_tracking.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Performance Tracking</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="innovation_projects.php">
                        <i class="fas fa-lightbulb"></i>
                        <span>Innovation Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="academic_calendar.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
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
                    <h1>Academic Calendar</h1>
                    <p>Manage and view all academic events, club activities, and important dates</p>
                </div>
                <div class="page-actions">
                    <a href="dashboard.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Event
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

            <?php if ($action === 'new' || $action === 'edit'): ?>
                <!-- Event Form -->
                <div class="event-form-container">
                    <div class="form-header">
                        <h2 class="form-title">
                            <?php echo $current_event ? 'Edit Event' : 'Create New Event'; ?>
                        </h2>
                    </div>
                    <form method="POST" action="?action=save_event<?php echo $current_event ? "&event_id={$current_event['id']}" : ''; ?>" class="form-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Event Title *</label>
                                <input type="text" name="title" class="form-input" 
                                       value="<?php echo htmlspecialchars($current_event['title'] ?? ''); ?>" 
                                       placeholder="Enter event title" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Event Type *</label>
                                <select name="event_type" class="form-select" required>
                                    <option value="">Select Event Type</option>
                                    <option value="meeting" <?php echo ($current_event['event_type'] ?? '') === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                                    <option value="workshop" <?php echo ($current_event['event_type'] ?? '') === 'workshop' ? 'selected' : ''; ?>>Workshop</option>
                                    <option value="exam" <?php echo ($current_event['event_type'] ?? '') === 'exam' ? 'selected' : ''; ?>>Exam</option>
                                    <option value="holiday" <?php echo ($current_event['event_type'] ?? '') === 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                                    <option value="deadline" <?php echo ($current_event['event_type'] ?? '') === 'deadline' ? 'selected' : ''; ?>>Deadline</option>
                                    <option value="celebration" <?php echo ($current_event['event_type'] ?? '') === 'celebration' ? 'selected' : ''; ?>>Celebration</option>
                                    <option value="other" <?php echo ($current_event['event_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Event Date *</label>
                                <input type="date" name="event_date" class="form-input" 
                                       value="<?php echo htmlspecialchars($current_event['event_date'] ?? $date); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Start Time</label>
                                <input type="time" name="start_time" class="form-input" 
                                       value="<?php echo htmlspecialchars($current_event['start_time'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">End Time</label>
                                <input type="time" name="end_time" class="form-input" 
                                       value="<?php echo htmlspecialchars($current_event['end_time'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-input" 
                                       value="<?php echo htmlspecialchars($current_event['location'] ?? ''); ?>" 
                                       placeholder="Event location">
                            </div>

                            <?php if (!$current_event || $current_event['event_source'] === 'academic_event'): ?>
                                <div class="form-group">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="low" <?php echo ($current_event['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo ($current_event['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo ($current_event['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="critical" <?php echo ($current_event['priority'] ?? '') === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                    </select>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <label class="form-label">Club</label>
                                    <select name="club_id" class="form-select" required>
                                        <option value="">Select Club</option>
                                        <?php foreach ($clubs as $club): ?>
                                            <option value="<?php echo $club['id']; ?>" <?php echo ($current_event['club_id'] ?? '') == $club['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($club['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <?php if ($current_event): ?>
                                    <div class="form-group">
                                        <label class="form-label">Budget (RWF)</label>
                                        <input type="number" name="budget" class="form-input" 
                                               value="<?php echo htmlspecialchars($current_event['budget'] ?? '0'); ?>" 
                                               placeholder="Estimated budget" step="0.01">
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="form-group full-width">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-textarea" 
                                          placeholder="Describe the event"><?php echo htmlspecialchars($current_event['description'] ?? ''); ?></textarea>
                            </div>

                            <?php if ($current_event): ?>
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="scheduled" <?php echo ($current_event['status'] ?? 'scheduled') === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="ongoing" <?php echo ($current_event['status'] ?? '') === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                        <option value="completed" <?php echo ($current_event['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo ($current_event['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-actions">
                            <a href="academic_calendar.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo $current_event ? 'Update Event' : 'Create Event'; ?>
                            </button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- Calendar Interface -->
                <div class="calendar-controls">
                    <div class="view-controls">
                        <button class="view-btn <?php echo $view === 'month' ? 'active' : ''; ?>" onclick="changeView('month')">
                            <i class="fas fa-calendar-alt"></i> Month
                        </button>
                        <button class="view-btn <?php echo $view === 'week' ? 'active' : ''; ?>" onclick="changeView('week')">
                            <i class="fas fa-calendar-week"></i> Week
                        </button>
                        <button class="view-btn <?php echo $view === 'day' ? 'active' : ''; ?>" onclick="changeView('day')">
                            <i class="fas fa-calendar-day"></i> Day
                        </button>
                    </div>

                    <div class="navigation-controls">
                        <button class="nav-btn" onclick="navigateCalendar('prev')">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <div class="current-period">
                            <?php if ($view === 'month'): ?>
                                <?php echo date('F Y', strtotime("$year-$month-01")); ?>
                            <?php elseif ($view === 'week'): ?>
                                <?php echo date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?>
                            <?php else: ?>
                                <?php echo date('l, F j, Y', strtotime($date)); ?>
                            <?php endif; ?>
                        </div>
                        
                        <button class="nav-btn" onclick="navigateCalendar('next')">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                        
                        <button class="today-btn" onclick="goToToday()">
                            Today
                        </button>
                    </div>
                </div>

                <div class="calendar-container">
                    <!-- Main Calendar -->
                    <div class="calendar-main">
                        <!-- Month View -->
                        <div class="calendar-view month-view <?php echo $view === 'month' ? 'active' : ''; ?>">
                            <div class="month-header">
                                <div class="weekday-header">Sun</div>
                                <div class="weekday-header">Mon</div>
                                <div class="weekday-header">Tue</div>
                                <div class="weekday-header">Wed</div>
                                <div class="weekday-header">Thu</div>
                                <div class="weekday-header">Fri</div>
                                <div class="weekday-header">Sat</div>
                            </div>
                            <div class="month-grid">
                                <?php foreach ($calendar_days as $day): ?>
                                    <div class="calendar-day <?php echo !$day['is_current_month'] ? 'other-month' : ''; ?> <?php echo $day['is_today'] ? 'today' : ''; ?>">
                                        <div class="day-number"><?php echo $day['day']; ?></div>
                                        <div class="day-events">
                                            <?php foreach ($day['events'] as $event): ?>
                                                <div class="day-event <?php echo 'event-' . $event['event_type']; ?>" 
                                                     onclick="viewEvent(<?php echo $event['id']; ?>, '<?php echo $event['event_source']; ?>')"
                                                     title="<?php echo htmlspecialchars($event['title']); ?>">
                                                    <div class="event-title">
                                                        <?php echo htmlspecialchars($event['title']); ?>
                                                    </div>
                                                    <?php if ($event['start_time']): ?>
                                                        <div class="event-time">
                                                            <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Week View -->
                        <div class="calendar-view week-view <?php echo $view === 'week' ? 'active' : ''; ?>">
                            <div class="week-header">
                                <div class="time-label">Time</div>
                                <?php
                                $current = strtotime($start_date);
                                for ($i = 0; $i < 7; $i++):
                                    $current_date = date('Y-m-d', $current);
                                    $is_today = $current_date == date('Y-m-d');
                                ?>
                                    <div class="weekday-header <?php echo $is_today ? 'today' : ''; ?>">
                                        <?php echo date('D', $current); ?><br>
                                        <?php echo date('M j', $current); ?>
                                    </div>
                                    <?php $current = strtotime('+1 day', $current); ?>
                                <?php endfor; ?>
                            </div>
                            <div class="week-grid">
                                <!-- Time slots would be populated here -->
                                <div class="time-slot" style="grid-row: span 8;">
                                    <div class="time-label">All Day</div>
                                </div>
                                <?php for ($i = 0; $i < 7; $i++): ?>
                                    <div class="time-slot" style="grid-row: span 8;">
                                        <!-- Events for this day would go here -->
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Day View -->
                        <div class="calendar-view day-view <?php echo $view === 'day' ? 'active' : ''; ?>">
                            <div class="day-header">
                                <h3><?php echo date('l, F j, Y', strtotime($date)); ?></h3>
                            </div>
                            <div class="day-grid">
                                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                                    <div class="hour-slot">
                                        <div class="hour-label">
                                            <?php echo date('g A', strtotime("$hour:00")); ?>
                                        </div>
                                        <div class="hour-events">
                                            <?php
                                            $current_hour_events = array_filter($events, function($event) use ($date, $hour) {
                                                if ($event['event_date'] != $date) return false;
                                                if (!$event['start_time']) return false;
                                                $event_hour = (int)date('G', strtotime($event['start_time']));
                                                return $event_hour == $hour;
                                            });
                                            ?>
                                            <?php foreach ($current_hour_events as $event): ?>
                                                <div class="day-event <?php echo 'event-' . $event['event_type']; ?>" 
                                                     onclick="viewEvent(<?php echo $event['id']; ?>, '<?php echo $event['event_source']; ?>')">
                                                    <div class="event-title">
                                                        <?php echo htmlspecialchars($event['title']); ?>
                                                    </div>
                                                    <div class="event-time">
                                                        <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                                        <?php if ($event['end_time']): ?>
                                                            - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Calendar Sidebar -->
                    <div class="calendar-sidebar">
                        <!-- Quick Stats -->
                        <div class="sidebar-card">
                            <div class="sidebar-header">
                                <h3 class="sidebar-title">Calendar Summary</h3>
                            </div>
                            <div class="sidebar-body">
                                <div class="quick-stats">
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo count($events); ?></div>
                                        <div class="stat-label">Total Events</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo count($upcoming_events); ?></div>
                                        <div class="stat-label">Upcoming</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo count(array_filter($events, fn($e) => $e['event_source'] === 'club_activity')); ?></div>
                                        <div class="stat-label">Club Events</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo count(array_filter($events, fn($e) => $e['event_source'] === 'academic_event')); ?></div>
                                        <div class="stat-label">Academic Events</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Upcoming Events -->
                        <div class="sidebar-card">
                            <div class="sidebar-header">
                                <h3 class="sidebar-title">Upcoming Events</h3>
                            </div>
                            <div class="sidebar-body">
                                <div class="upcoming-events">
                                    <?php if (empty($upcoming_events)): ?>
                                        <div style="text-align: center; padding: 1rem; color: var(--dark-gray);">
                                            <i class="fas fa-calendar" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                            <p>No upcoming events</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($upcoming_events as $event): ?>
                                            <div class="upcoming-event" onclick="viewEvent(<?php echo $event['id']; ?>, '<?php echo $event['event_source']; ?>')">
                                                <div class="event-date">
                                                    <div class="event-day"><?php echo date('j', strtotime($event['event_date'])); ?></div>
                                                    <div class="event-month"><?php echo date('M', strtotime($event['event_date'])); ?></div>
                                                </div>
                                                <div class="event-details">
                                                    <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                                                    <div class="event-meta">
                                                        <?php if ($event['start_time']): ?>
                                                            <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                                        <?php endif; ?>
                                                        <?php if ($event['club_name']): ?>
                                                            • <?php echo htmlspecialchars($event['club_name']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="sidebar-card">
                            <div class="sidebar-header">
                                <h3 class="sidebar-title">Quick Actions</h3>
                            </div>
                            <div class="sidebar-body">
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    <a href="?action=new" class="btn btn-primary" style="text-align: center;">
                                        <i class="fas fa-plus"></i> Add New Event
                                    </a>
                                    <a href="academic_clubs.php" class="btn btn-outline" style="text-align: center;">
                                        <i class="fas fa-users"></i> Manage Club Events
                                    </a>
                                    <button class="btn btn-outline" onclick="printCalendar()" style="text-align: center;">
                                        <i class="fas fa-print"></i> Print Calendar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // ── Mobile Nav (hamburger sidebar) ──
        (function() {
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const navSidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('mobileNavOverlay');

            function openNav() {
                navSidebar.classList.add('open');
                overlay.classList.add('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-times"></i>';
                document.body.style.overflow = 'hidden';
            }

            function closeNav() {
                navSidebar.classList.remove('open');
                overlay.classList.remove('active');
                hamburgerBtn.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }

            hamburgerBtn.addEventListener('click', () => {
                navSidebar.classList.contains('open') ? closeNav() : openNav();
            });

            overlay.addEventListener('click', closeNav);

            window.addEventListener('resize', () => {
                if (window.innerWidth > 900) closeNav();
            });
        })();

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

        // Calendar Navigation
        function changeView(newView) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', newView);
            window.location.href = url.toString();
        }

        function navigateCalendar(direction) {
            const url = new URL(window.location.href);
            const currentView = url.searchParams.get('view') || 'month';
            
            if (currentView === 'month') {
                let year = parseInt(url.searchParams.get('year')) || new Date().getFullYear();
                let month = parseInt(url.searchParams.get('month')) || new Date().getMonth() + 1;
                
                if (direction === 'next') {
                    month++;
                    if (month > 12) {
                        month = 1;
                        year++;
                    }
                } else {
                    month--;
                    if (month < 1) {
                        month = 12;
                        year--;
                    }
                }
                
                url.searchParams.set('year', year);
                url.searchParams.set('month', month);
            } else if (currentView === 'week') {
                let date = url.searchParams.get('date') || new Date().toISOString().split('T')[0];
                const currentDate = new Date(date);
                
                if (direction === 'next') {
                    currentDate.setDate(currentDate.getDate() + 7);
                } else {
                    currentDate.setDate(currentDate.getDate() - 7);
                }
                
                url.searchParams.set('date', currentDate.toISOString().split('T')[0]);
            } else { // day view
                let date = url.searchParams.get('date') || new Date().toISOString().split('T')[0];
                const currentDate = new Date(date);
                
                if (direction === 'next') {
                    currentDate.setDate(currentDate.getDate() + 1);
                } else {
                    currentDate.setDate(currentDate.getDate() - 1);
                }
                
                url.searchParams.set('date', currentDate.toISOString().split('T')[0]);
            }
            
            window.location.href = url.toString();
        }

        function goToToday() {
            const url = new URL(window.location.href);
            url.searchParams.delete('year');
            url.searchParams.delete('month');
            url.searchParams.delete('date');
            window.location.href = url.toString();
        }

        function viewEvent(eventId, eventSource) {
            // In a real implementation, this would show event details
            // For now, we'll navigate to the edit page
            window.location.href = `?action=edit&event_id=${eventId}`;
        }

        function printCalendar() {
            window.print();
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        navigateCalendar('prev');
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        navigateCalendar('next');
                        break;
                    case 't':
                        e.preventDefault();
                        goToToday();
                        break;
                    case 'n':
                        e.preventDefault();
                        window.location.href = '?action=new';
                        break;
                }
            }
        });

        // Responsive calendar adjustments
        function handleResize() {
            const calendarDays = document.querySelectorAll('.calendar-day');
            if (window.innerWidth < 768) {
                calendarDays.forEach(day => {
                    day.style.minHeight = '80px';
                });
            } else {
                calendarDays.forEach(day => {
                    day.style.minHeight = '120px';
                });
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize(); // Initial call
    </script>
</body>
</html>