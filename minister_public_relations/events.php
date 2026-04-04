<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Public Relations
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_public_relations') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User profile error: " . $e->getMessage());
    $user = [];
}

// Get unread messages count for badge
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

// Handle event actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_event':
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                $category_id = $_POST['category_id'];
                $event_date = $_POST['event_date'];
                $start_time = $_POST['start_time'];
                $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
                $location = trim($_POST['location']);
                $organizer = trim($_POST['organizer'] ?? '');
                $contact_person = trim($_POST['contact_person'] ?? '');
                $contact_email = trim($_POST['contact_email'] ?? '');
                $contact_phone = trim($_POST['contact_phone'] ?? '');
                $max_participants = !empty($_POST['max_participants']) ? $_POST['max_participants'] : null;
                $is_featured = isset($_POST['is_featured']) ? 1 : 0;
                $registration_required = isset($_POST['registration_required']) ? 1 : 0;
                $registration_deadline = !empty($_POST['registration_deadline']) ? $_POST['registration_deadline'] : null;
                $status = $_POST['status'] ?? 'published';
                
                // Validate required fields
                if (empty($title) || empty($description) || empty($category_id) || empty($event_date) || empty($start_time) || empty($location)) {
                    $_SESSION['error_message'] = "Please fill in all required fields.";
                    break;
                }
                
                // Handle image upload
                $image_url = null;
                if (isset($_FILES['image_url']) && $_FILES['image_url']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/events/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['image_url']['name'], PATHINFO_EXTENSION);
                    $file_name = 'event_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    // Validate image type
                    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array(strtolower($file_extension), $allowed_types)) {
                        if (move_uploaded_file($_FILES['image_url']['tmp_name'], $file_path)) {
                            $image_url = 'assets/uploads/events/' . $file_name;
                        }
                    }
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO events (
                            category_id, title, description, excerpt, image_url, event_date, start_time, end_time,
                            location, organizer, contact_person, contact_email, contact_phone, max_participants,
                            is_featured, status, registration_required, registration_deadline, registered_participants, created_by, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    
                    $stmt->execute([
                        $category_id, $title, $description, $excerpt, $image_url, $event_date, $start_time, $end_time,
                        $location, $organizer, $contact_person, $contact_email, $contact_phone, $max_participants,
                        $is_featured, $status, $registration_required, $registration_deadline, $user_id
                    ]);
                    
                    $event_id = $pdo->lastInsertId();
                    
                    $_SESSION['success_message'] = "Event created successfully!";
                    header("Location: events.php?view=" . $event_id);
                    exit();
                    
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating event: " . $e->getMessage();
                }
                break;
                
            case 'update_event':
                $event_id = $_POST['event_id'];
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $excerpt = trim($_POST['excerpt'] ?? '');
                $category_id = $_POST['category_id'];
                $event_date = $_POST['event_date'];
                $start_time = $_POST['start_time'];
                $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
                $location = trim($_POST['location']);
                $organizer = trim($_POST['organizer'] ?? '');
                $contact_person = trim($_POST['contact_person'] ?? '');
                $contact_email = trim($_POST['contact_email'] ?? '');
                $contact_phone = trim($_POST['contact_phone'] ?? '');
                $max_participants = !empty($_POST['max_participants']) ? $_POST['max_participants'] : null;
                $is_featured = isset($_POST['is_featured']) ? 1 : 0;
                $registration_required = isset($_POST['registration_required']) ? 1 : 0;
                $registration_deadline = !empty($_POST['registration_deadline']) ? $_POST['registration_deadline'] : null;
                $status = $_POST['status'] ?? 'published';
                $remove_image = isset($_POST['remove_image']) ? 1 : 0;
                
                // Validate required fields
                if (empty($title) || empty($description) || empty($category_id) || empty($event_date) || empty($start_time) || empty($location)) {
                    $_SESSION['error_message'] = "Please fill in all required fields.";
                    break;
                }
                
                // Get current event data
                try {
                    $stmt = $pdo->prepare("SELECT image_url FROM events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    $current_event = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error loading event: " . $e->getMessage();
                    break;
                }
                
                $image_url = $current_event['image_url'] ?? null;
                
                // Handle image removal
                if ($remove_image && $image_url) {
                    $old_image_path = '../' . $image_url;
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                    $image_url = null;
                }
                
                // Handle new image upload
                if (isset($_FILES['image_url']) && $_FILES['image_url']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../assets/uploads/events/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['image_url']['name'], PATHINFO_EXTENSION);
                    $file_name = 'event_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    // Validate image type
                    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array(strtolower($file_extension), $allowed_types)) {
                        if (move_uploaded_file($_FILES['image_url']['tmp_name'], $file_path)) {
                            // Delete old image if exists
                            if ($image_url && file_exists('../' . $image_url)) {
                                unlink('../' . $image_url);
                            }
                            $image_url = 'assets/uploads/events/' . $file_name;
                        }
                    }
                }
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE events 
                        SET category_id = ?, title = ?, description = ?, excerpt = ?, image_url = ?, 
                            event_date = ?, start_time = ?, end_time = ?, location = ?, organizer = ?,
                            contact_person = ?, contact_email = ?, contact_phone = ?, max_participants = ?,
                            is_featured = ?, status = ?, registration_required = ?, registration_deadline = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $category_id, $title, $description, $excerpt, $image_url, $event_date, $start_time, $end_time,
                        $location, $organizer, $contact_person, $contact_email, $contact_phone, $max_participants,
                        $is_featured, $status, $registration_required, $registration_deadline, $event_id
                    ]);
                    
                    $_SESSION['success_message'] = "Event updated successfully!";
                    header("Location: events.php?view=" . $event_id);
                    exit();
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating event: " . $e->getMessage();
                }
                break;
                
            case 'delete_event':
                $event_id = $_POST['event_id'];
                
                try {
                    // Get event image to delete it
                    $stmt = $pdo->prepare("SELECT image_url FROM events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    $event = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($event && !empty($event['image_url'])) {
                        $image_path = '../' . $event['image_url'];
                        if (file_exists($image_path)) {
                            unlink($image_path);
                        }
                    }
                    
                    // Delete event registrations first
                    $stmt = $pdo->prepare("DELETE FROM event_registrations WHERE event_id = ?");
                    $stmt->execute([$event_id]);
                    
                    // Delete the event
                    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    
                    $_SESSION['success_message'] = "Event deleted successfully!";
                    header("Location: events.php");
                    exit();
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting event: " . $e->getMessage();
                }
                break;
                
            case 'update_registration_status':
                $registration_id = $_POST['registration_id'];
                $status = $_POST['status'];
                $event_id = $_POST['event_id'] ?? null;
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE event_registrations 
                        SET status = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$status, $registration_id]);
                    
                    // Update registered participants count if status changed to/from registered
                    if ($event_id) {
                        $stmt = $pdo->prepare("
                            UPDATE events 
                            SET registered_participants = (
                                SELECT COUNT(*) FROM event_registrations 
                                WHERE event_id = ? AND status = 'registered'
                            )
                            WHERE id = ?
                        ");
                        $stmt->execute([$event_id, $event_id]);
                    }
                    
                    $_SESSION['success_message'] = "Registration status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating registration: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: events.php" . (isset($_POST['event_id']) && $_POST['action'] !== 'delete_event' ? "?view=" . $_POST['event_id'] : ""));
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$featured_filter = $_GET['featured'] ?? 'all';

// Build query for events
$query = "
    SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon,
           u.full_name as creator_name
    FROM events e
    LEFT JOIN event_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($search)) {
    $query .= " AND (e.title ILIKE ? OR e.description ILIKE ? OR e.location ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($category_filter !== 'all') {
    $query .= " AND e.category_id = ?";
    $params[] = $category_filter;
}

if ($status_filter !== 'all') {
    $query .= " AND e.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $query .= " AND e.event_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND e.event_date <= ?";
    $params[] = $date_to;
}

if ($featured_filter !== 'all') {
    $query .= " AND e.is_featured = ?";
    $params[] = ($featured_filter === 'featured') ? 1 : 0;
}

$query .= " ORDER BY 
    CASE 
        WHEN e.event_date >= CURRENT_DATE THEN 0 
        ELSE 1 
    END,
    e.event_date ASC,
    e.start_time ASC";

// Get events
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Events query error: " . $e->getMessage());
    $events = [];
}

// Get event categories for filter and form
$event_categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM event_categories WHERE is_active = true ORDER BY name");
    $event_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Event categories query error: " . $e->getMessage());
    $event_categories = [];
}

// Get statistics for dashboard
try {
    // Total events
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events");
    $total_events = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // My events
    $stmt = $pdo->prepare("SELECT COUNT(*) as my_events FROM events WHERE created_by = ?");
    $stmt->execute([$user_id]);
    $my_events = $stmt->fetch(PDO::FETCH_ASSOC)['my_events'] ?? 0;
    
    // Upcoming events
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming 
        FROM events 
        WHERE event_date >= CURRENT_DATE AND status = 'published'
    ");
    $stmt->execute();
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming'] ?? 0;
    
    // Featured events
    $stmt = $pdo->query("SELECT COUNT(*) as featured FROM events WHERE is_featured = 1 AND status = 'published'");
    $featured_events = $stmt->fetch(PDO::FETCH_ASSOC)['featured'] ?? 0;
    
    // Total registrations
    $stmt = $pdo->query("SELECT COUNT(*) as total_registrations FROM event_registrations WHERE status = 'registered'");
    $total_registrations = $stmt->fetch(PDO::FETCH_ASSOC)['total_registrations'] ?? 0;
    
} catch (PDOException $e) {
    $total_events = $my_events = $upcoming_events = $featured_events = $total_registrations = 0;
}

// Check if we're viewing a specific event
$view_event = null;
$event_registrations = [];
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon,
                   u.full_name as creator_name
            FROM events e
            LEFT JOIN event_categories ec ON e.category_id = ec.id
            LEFT JOIN users u ON e.created_by = u.id
            WHERE e.id = ?
        ");
        $stmt->execute([$_GET['view']]);
        $view_event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($view_event) {
            // Get event registrations
            $stmt = $pdo->prepare("
                SELECT er.* 
                FROM event_registrations er
                WHERE er.event_id = ?
                ORDER BY er.registration_date DESC
            ");
            $stmt->execute([$_GET['view']]);
            $event_registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error loading event: " . $e->getMessage();
        header("Location: events.php");
        exit();
    }
}

// Check if we're editing an event (new or existing)
$edit_event = null;
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        // New event mode
        $edit_event = [];
    } elseif (is_numeric($_GET['edit'])) {
        // Edit existing event
        try {
            $stmt = $pdo->prepare("
                SELECT e.*, ec.name as category_name
                FROM events e
                LEFT JOIN event_categories ec ON e.category_id = ec.id
                WHERE e.id = ?
            ");
            $stmt->execute([$_GET['edit']]);
            $edit_event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$edit_event) {
                $_SESSION['error_message'] = "Event not found.";
                header("Location: events.php");
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error loading event: " . $e->getMessage();
            header("Location: events.php");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Events Management - Minister of Public Relations</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #3B82F6;
            --secondary-blue: #60A5FA;
            --accent-blue: #1D4ED8;
            --light-blue: #EFF6FF;
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
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
        }

        .dark-mode {
            --primary-blue: #60A5FA;
            --secondary-blue: #93C5FD;
            --accent-blue: #3B82F6;
            --light-blue: #1E3A8A;
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
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
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
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        .dashboard-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
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

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
            transform: translateY(-1px);
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
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
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
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

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-dark);
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
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Event Form */
        .event-form {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .form-full-width {
            grid-column: 1 / -1;
        }

        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-file {
            padding: 1.5rem;
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--light-gray);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .form-file:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .form-file input {
            display: none;
        }

        .form-checkbox {
            margin-right: 0.5rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
            flex-wrap: wrap;
        }

        /* Events Grid */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .event-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .event-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .event-image {
            height: 200px;
            background: var(--medium-gray);
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .event-featured {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--warning);
            color: var(--text-dark);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .event-category {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .event-content {
            padding: 1.25rem;
        }

        .event-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .event-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .event-excerpt {
            color: var(--text-dark);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .event-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .event-registrations {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .event-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Event Details */
        .event-details {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .event-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-gray);
        }

        .event-header-content {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: start;
        }

        .event-main-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .event-main-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--dark-gray);
        }

        .event-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .event-body {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .event-main {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .event-description h3 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .description-content {
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .event-sidebar {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--border-radius);
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section:last-child {
            margin-bottom: 0;
        }

        .sidebar-section h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .info-label {
            color: var(--dark-gray);
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-dark);
            text-align: right;
        }

        /* Registrations Section */
        .registrations-section {
            margin-top: 1rem;
        }

        .registrations-section h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        .table-container {
            overflow-x: auto;
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
        }

        .table tbody tr:hover {
            background: var(--light-blue);
        }

        .registration-status {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-registered {
            background: #d4edda;
            color: #155724;
        }

        .status-attended {
            background: #cce7ff;
            color: #004085;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        /* Animations */
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

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }

            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(2px);
                z-index: 999;
            }

            .overlay.active {
                display: block;
            }

            .event-body {
                grid-template-columns: 1fr;
            }

            .events-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

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

            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .form-actions {
                flex-direction: column;
            }

            .event-header-content {
                grid-template-columns: 1fr;
            }

            .events-grid {
                grid-template-columns: 1fr;
            }

            .event-meta {
                flex-direction: column;
                gap: 0.5rem;
            }

            .event-actions {
                flex-direction: column;
            }

            .stat-number {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .table {
                font-size: 0.7rem;
            }

            .table th, .table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>
    
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <img src="../assets/images/logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Events Management</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Public Relations</div>
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
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
                        <span>Student Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="announcements.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="news.php">
                        <i class="fas fa-newspaper"></i>
                        <span>News</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="events.php" class="active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                        <?php if ($total_events > 0): ?>
                            <span class="menu-badge"><?php echo $total_events; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="gallery.php">
                        <i class="fas fa-images"></i>
                        <span>Gallery</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="associations.php">
                        <i class="fas fa-church"></i>
                        <span>Associations</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_budget_requests.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Events Management</h1>
                    <p>Create and manage events for the student community</p>
                </div>
                <div class="header-actions">
                    <?php if ($view_event || $edit_event): ?>
                        <a href="events.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Events
                        </a>
                    <?php else: ?>
                        <a href="?edit=new" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Event
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <?php if (!$view_event && !$edit_event): ?>
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_events); ?></div>
                            <div class="stat-label">Total Events</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($my_events); ?></div>
                            <div class="stat-label">My Events</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($upcoming_events); ?></div>
                            <div class="stat-label">Upcoming Events</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($featured_events); ?></div>
                            <div class="stat-label">Featured Events</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-input" placeholder="Search events..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($event_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Featured</label>
                            <select name="featured" class="form-select">
                                <option value="all" <?php echo $featured_filter === 'all' ? 'selected' : ''; ?>>All Events</option>
                                <option value="featured" <?php echo $featured_filter === 'featured' ? 'selected' : ''; ?>>Featured Only</option>
                                <option value="regular" <?php echo $featured_filter === 'regular' ? 'selected' : ''; ?>>Regular Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-input" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-input" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="events.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Events Grid -->
                <div class="events-grid">
                    <?php if (empty($events)): ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <i class="fas fa-calendar-alt"></i>
                            <h3>No Events Found</h3>
                            <p>There are no events matching your criteria.</p>
                            <a href="?edit=new" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create First Event
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <div class="event-card">
                                <div class="event-image" style="background-image: url('<?php echo !empty($event['image_url']) ? '../' . htmlspecialchars($event['image_url']) : '../assets/images/event-placeholder.jpg'; ?>')">
                                    <?php if ($event['is_featured']): ?>
                                        <span class="event-featured">
                                            <i class="fas fa-star"></i> Featured
                                        </span>
                                    <?php endif; ?>
                                    <span class="event-category" style="background: <?php echo htmlspecialchars($event['category_color'] ?? '#3B82F6'); ?>">
                                        <i class="<?php echo htmlspecialchars($event['category_icon'] ?? 'fas fa-calendar'); ?>"></i>
                                        <?php echo htmlspecialchars($event['category_name']); ?>
                                    </span>
                                </div>
                                
                                <div class="event-content">
                                    <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                    
                                    <div class="event-meta">
                                        <div class="event-meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($event['event_date'])); ?>
                                        </div>
                                        <div class="event-meta-item">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                            <?php if ($event['end_time']): ?>
                                                - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="event-meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($event['location']); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($event['excerpt'])): ?>
                                        <div class="event-excerpt">
                                            <?php echo htmlspecialchars($event['excerpt']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="event-stats">
                                        <div class="event-registrations">
                                            <i class="fas fa-users"></i>
                                            <?php echo number_format($event['registered_participants']); ?> registered
                                            <?php if ($event['max_participants']): ?>
                                                / <?php echo number_format($event['max_participants']); ?> max
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="event-actions">
                                            <a href="?view=<?php echo $event['id']; ?>" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.75rem;">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="?edit=<?php echo $event['id']; ?>" class="btn btn-warning" style="padding: 0.4rem 0.8rem; font-size: 0.75rem;">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($edit_event !== null): ?>
                <!-- Event Form (New or Edit) -->
                <div class="event-form">
                    <h2 class="form-title"><?php echo isset($edit_event['id']) ? 'Edit Event' : 'Create New Event'; ?></h2>
                    <form method="POST" enctype="multipart/form-data" id="eventForm">
                        <input type="hidden" name="action" value="<?php echo isset($edit_event['id']) ? 'update_event' : 'create_event'; ?>">
                        <?php if (isset($edit_event['id'])): ?>
                            <input type="hidden" name="event_id" value="<?php echo $edit_event['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Event Title *</label>
                                <input type="text" name="title" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['title'] ?? ''); ?>" 
                                       placeholder="Enter event title" required maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($event_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                <?php echo (isset($edit_event['category_id']) && $edit_event['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Event Date *</label>
                                <input type="date" name="event_date" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['event_date'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Start Time *</label>
                                <input type="time" name="start_time" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['start_time'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">End Time</label>
                                <input type="time" name="end_time" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['end_time'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Location *</label>
                                <input type="text" name="location" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['location'] ?? ''); ?>" 
                                       placeholder="Enter event location" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Organizer</label>
                                <input type="text" name="organizer" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['organizer'] ?? ''); ?>" 
                                       placeholder="Event organizer">
                            </div>
                            
                            <div class="form-group form-full-width">
                                <label class="form-label">Event Image</label>
                                <div class="form-file" onclick="document.getElementById('imageInput').click()">
                                    <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                    <p>Click to upload event image</p>
                                    <small>Recommended: 800x400px, JPG/PNG/WEBP (Max 5MB)</small>
                                    <input type="file" id="imageInput" name="image_url" accept="image/*">
                                </div>
                                <?php if (isset($edit_event['image_url']) && !empty($edit_event['image_url'])): ?>
                                    <div style="margin-top: 1rem;">
                                        <p>Current Image:</p>
                                        <img src="../<?php echo htmlspecialchars($edit_event['image_url']); ?>" 
                                             alt="Event Image" style="max-width: 200px; border-radius: var(--border-radius);">
                                        <div style="margin-top: 0.5rem;">
                                            <label>
                                                <input type="checkbox" name="remove_image" value="1"> Remove current image
                                            </label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group form-full-width">
                                <label class="form-label">Excerpt</label>
                                <textarea name="excerpt" class="form-textarea" 
                                          placeholder="Brief description of the event (appears in event listings)" 
                                          maxlength="500"><?php echo htmlspecialchars($edit_event['excerpt'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group form-full-width">
                                <label class="form-label">Event Description *</label>
                                <textarea name="description" class="form-textarea" 
                                          placeholder="Full event description" 
                                          required><?php echo htmlspecialchars($edit_event['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Contact Person</label>
                                <input type="text" name="contact_person" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['contact_person'] ?? ''); ?>" 
                                       placeholder="Contact person name">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Contact Email</label>
                                <input type="email" name="contact_email" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['contact_email'] ?? ''); ?>" 
                                       placeholder="Contact email address">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Contact Phone</label>
                                <input type="tel" name="contact_phone" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['contact_phone'] ?? ''); ?>" 
                                       placeholder="Contact phone number">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Max Participants</label>
                                <input type="number" name="max_participants" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['max_participants'] ?? ''); ?>" 
                                       placeholder="Leave empty for unlimited" min="1">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Registration Deadline</label>
                                <input type="date" name="registration_deadline" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_event['registration_deadline'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="published" <?php echo ($edit_event['status'] ?? 'published') === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="draft" <?php echo ($edit_event['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="cancelled" <?php echo ($edit_event['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                                <div>
                                    <input type="checkbox" name="is_featured" value="1" class="form-checkbox" 
                                           <?php echo (isset($edit_event['is_featured']) && $edit_event['is_featured']) ? 'checked' : ''; ?>>
                                    <label class="form-label" style="display: inline; margin: 0;">Featured Event</label>
                                </div>
                                
                                <div>
                                    <input type="checkbox" name="registration_required" value="1" class="form-checkbox" 
                                           <?php echo (isset($edit_event['registration_required']) && $edit_event['registration_required']) ? 'checked' : ''; ?>>
                                    <label class="form-label" style="display: inline; margin: 0;">Registration Required</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="events.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo isset($edit_event['id']) ? 'Update Event' : 'Create Event'; ?>
                            </button>
                            <?php if (isset($edit_event['id'])): ?>
                                <button type="button" class="btn btn-danger" onclick="confirmDelete(<?php echo $edit_event['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete Event
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

            <?php elseif ($view_event): ?>
                <!-- Event Details View -->
                <div class="event-details">
                    <div class="event-header">
                        <div class="event-header-content">
                            <div>
                                <h1 class="event-main-title"><?php echo htmlspecialchars($view_event['title']); ?></h1>
                                <div class="event-main-meta">
                                    <span><strong>Category:</strong> <?php echo htmlspecialchars($view_event['category_name']); ?></span>
                                    <span><strong>Date:</strong> <?php echo date('F j, Y', strtotime($view_event['event_date'])); ?></span>
                                    <span><strong>Time:</strong> <?php echo date('g:i A', strtotime($view_event['start_time'])); ?>
                                        <?php if ($view_event['end_time']): ?>
                                            - <?php echo date('g:i A', strtotime($view_event['end_time'])); ?>
                                        <?php endif; ?>
                                    </span>
                                    <span><strong>Location:</strong> <?php echo htmlspecialchars($view_event['location']); ?></span>
                                </div>
                            </div>
                            <div class="event-actions">
                                <span class="event-status status-<?php echo $view_event['status']; ?>">
                                    <?php echo ucfirst($view_event['status']); ?>
                                </span>
                                <a href="?edit=<?php echo $view_event['id']; ?>" class="btn btn-warning">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button type="button" class="btn btn-danger" onclick="confirmDelete(<?php echo $view_event['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="event-body">
                        <div class="event-main">
                            <?php if (!empty($view_event['image_url'])): ?>
                                <div style="margin-bottom: 2rem;">
                                    <img src="../<?php echo htmlspecialchars($view_event['image_url']); ?>" 
                                         alt="Event Image" style="width: 100%; border-radius: var(--border-radius);">
                                </div>
                            <?php endif; ?>
                            
                            <div class="event-description">
                                <h3>Event Description</h3>
                                <div class="description-content">
                                    <?php echo nl2br(htmlspecialchars($view_event['description'])); ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($view_event['excerpt'])): ?>
                                <div class="event-description">
                                    <h3>Summary</h3>
                                    <div class="description-content">
                                        <?php echo htmlspecialchars($view_event['excerpt']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Registrations Section -->
                            <?php if ($view_event['registration_required']): ?>
                                <div class="registrations-section">
                                    <h3>Event Registrations (<?php echo count($event_registrations); ?>)</h3>
                                    
                                    <?php if (empty($event_registrations)): ?>
                                        <div class="empty-state" style="padding: 2rem;">
                                            <i class="fas fa-users"></i>
                                            <p>No registrations yet</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-container">
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Email</th>
                                                        <th>Registration Number</th>
                                                        <th>Department</th>
                                                        <th>Phone</th>
                                                        <th>Registration Date</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($event_registrations as $registration): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($registration['student_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($registration['student_email']); ?></td>
                                                            <td><?php echo htmlspecialchars($registration['student_reg_number']); ?></td>
                                                            <td><?php echo htmlspecialchars($registration['department'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($registration['phone'] ?? 'N/A'); ?></td>
                                                            <td><?php echo date('M j, Y', strtotime($registration['registration_date'])); ?></td>
                                                            <td>
                                                                <span class="registration-status status-<?php echo $registration['status']; ?>">
                                                                    <?php echo ucfirst($registration['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="update_registration_status">
                                                                    <input type="hidden" name="registration_id" value="<?php echo $registration['id']; ?>">
                                                                    <input type="hidden" name="event_id" value="<?php echo $view_event['id']; ?>">
                                                                    <select name="status" class="form-select" style="font-size: 0.7rem; padding: 0.25rem; width: auto;" onchange="this.form.submit()">
                                                                        <option value="registered" <?php echo $registration['status'] === 'registered' ? 'selected' : ''; ?>>Registered</option>
                                                                        <option value="attended" <?php echo $registration['status'] === 'attended' ? 'selected' : ''; ?>>Attended</option>
                                                                        <option value="cancelled" <?php echo $registration['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                                    </select>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="event-sidebar">
                            <!-- Event Information -->
                            <div class="sidebar-section">
                                <h4>Event Information</h4>
                                <div class="info-item">
                                    <span class="info-label">Status:</span>
                                    <span class="info-value">
                                        <span class="event-status status-<?php echo $view_event['status']; ?>">
                                            <?php echo ucfirst($view_event['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if ($view_event['is_featured']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Featured:</span>
                                        <span class="info-value">Yes <i class="fas fa-star" style="color: var(--warning);"></i></span>
                                    </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <span class="info-label">Registration:</span>
                                    <span class="info-value">
                                        <?php echo $view_event['registration_required'] ? 'Required' : 'Not Required'; ?>
                                    </span>
                                </div>
                                <?php if ($view_event['max_participants']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Capacity:</span>
                                        <span class="info-value">
                                            <?php echo number_format($view_event['registered_participants']); ?> / <?php echo number_format($view_event['max_participants']); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="info-item">
                                        <span class="info-label">Participants:</span>
                                        <span class="info-value"><?php echo number_format($view_event['registered_participants']); ?> registered</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Contact Information -->
                            <?php if ($view_event['contact_person'] || $view_event['contact_email'] || $view_event['contact_phone']): ?>
                                <div class="sidebar-section">
                                    <h4>Contact Information</h4>
                                    <?php if ($view_event['contact_person']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Contact Person:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($view_event['contact_person']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($view_event['contact_email']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Email:</span>
                                            <span class="info-value">
                                                <a href="mailto:<?php echo htmlspecialchars($view_event['contact_email']); ?>">
                                                    <?php echo htmlspecialchars($view_event['contact_email']); ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($view_event['contact_phone']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Phone:</span>
                                            <span class="info-value">
                                                <a href="tel:<?php echo htmlspecialchars($view_event['contact_phone']); ?>">
                                                    <?php echo htmlspecialchars($view_event['contact_phone']); ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Organizer Information -->
                            <?php if ($view_event['organizer']): ?>
                                <div class="sidebar-section">
                                    <h4>Organizer</h4>
                                    <div class="info-item">
                                        <span class="info-label">Organized By:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($view_event['organizer']); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Registration Information -->
                            <?php if ($view_event['registration_required']): ?>
                                <div class="sidebar-section">
                                    <h4>Registration</h4>
                                    <div class="info-item">
                                        <span class="info-label">Required:</span>
                                        <span class="info-value">Yes</span>
                                    </div>
                                    <?php if ($view_event['registration_deadline']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Deadline:</span>
                                            <span class="info-value"><?php echo date('M j, Y', strtotime($view_event['registration_deadline'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($view_event['max_participants']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Available Spots:</span>
                                            <span class="info-value">
                                                <?php echo max(0, $view_event['max_participants'] - $view_event['registered_participants']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Event Creator -->
                            <div class="sidebar-section">
                                <h4>Event Creator</h4>
                                <div class="info-item">
                                    <span class="info-label">Created On:</span>
                                    <span class="info-value"><?php echo date('M j, Y', strtotime($view_event['created_at'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Last Updated:</span>
                                    <span class="info-value"><?php echo date('M j, Y', strtotime($view_event['updated_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Delete Confirmation Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_event">
        <input type="hidden" name="event_id" id="deleteEventId">
    </form>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Delete confirmation function
        function confirmDelete(eventId) {
            if (confirm('Are you sure you want to delete this event? This will also delete all registrations.')) {
                document.getElementById('deleteEventId').value = eventId;
                document.getElementById('deleteForm').submit();
            }
        }

        // Image preview for file upload
        document.addEventListener('DOMContentLoaded', function() {
            const imageInput = document.getElementById('imageInput');
            if (imageInput) {
                imageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        // Validate file size (max 5MB)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('File size must be less than 5MB');
                            this.value = '';
                            return;
                        }
                        
                        // Validate file type
                        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if (!allowedTypes.includes(file.type)) {
                            alert('Please upload a valid image file (JPG, PNG, GIF, WEBP)');
                            this.value = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // Create preview image
                            const preview = document.createElement('img');
                            preview.src = e.target.result;
                            preview.style.maxWidth = '200px';
                            preview.style.borderRadius = 'var(--border-radius)';
                            preview.style.marginTop = '1rem';
                            
                            // Remove existing preview
                            const existingPreview = document.querySelector('#imageInput + div img');
                            if (existingPreview) {
                                existingPreview.remove();
                            }
                            
                            // Add new preview
                            const fileDiv = document.querySelector('.form-file');
                            fileDiv.parentNode.insertBefore(preview, fileDiv.nextSibling);
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }

            // Auto-set end time based on start time
            const startTimeInput = document.querySelector('input[name="start_time"]');
            const endTimeInput = document.querySelector('input[name="end_time"]');
            
            if (startTimeInput && endTimeInput) {
                startTimeInput.addEventListener('change', function() {
                    if (!endTimeInput.value) {
                        // Set end time to 2 hours after start time by default
                        const startTime = this.value;
                        if (startTime) {
                            const [hours, minutes] = startTime.split(':').map(Number);
                            let endHours = hours + 2;
                            if (endHours >= 24) endHours -= 24;
                            const endTime = `${endHours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
                            endTimeInput.value = endTime;
                        }
                    }
                });
            }

            // Add loading animations
            const cards = document.querySelectorAll('.event-card, .event-form, .event-details');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '1';
            });
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const eventForm = document.getElementById('eventForm');
            if (eventForm) {
                eventForm.addEventListener('submit', function(e) {
                    const title = document.querySelector('input[name="title"]');
                    const description = document.querySelector('textarea[name="description"]');
                    const category = document.querySelector('select[name="category_id"]');
                    const eventDate = document.querySelector('input[name="event_date"]');
                    const startTime = document.querySelector('input[name="start_time"]');
                    const location = document.querySelector('input[name="location"]');
                    
                    let isValid = true;
                    
                    if (!title.value.trim()) {
                        showError(title, 'Event title is required');
                        isValid = false;
                    }
                    
                    if (!description.value.trim()) {
                        showError(description, 'Event description is required');
                        isValid = false;
                    }
                    
                    if (!category.value) {
                        showError(category, 'Please select a category');
                        isValid = false;
                    }
                    
                    if (!eventDate.value) {
                        showError(eventDate, 'Event date is required');
                        isValid = false;
                    }
                    
                    if (!startTime.value) {
                        showError(startTime, 'Start time is required');
                        isValid = false;
                    }
                    
                    if (!location.value.trim()) {
                        showError(location, 'Location is required');
                        isValid = false;
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        // Scroll to first error
                        const firstError = document.querySelector('.error-message');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
                
                function showError(element, message) {
                    // Remove existing error
                    const existingError = element.parentNode.querySelector('.error-message');
                    if (existingError) {
                        existingError.remove();
                    }
                    
                    // Add error styling
                    element.style.borderColor = 'var(--danger)';
                    
                    // Create error message
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'error-message';
                    errorDiv.style.color = 'var(--danger)';
                    errorDiv.style.fontSize = '0.75rem';
                    errorDiv.style.marginTop = '0.25rem';
                    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
                    
                    element.parentNode.appendChild(errorDiv);
                }
                
                // Remove error on input
                const inputs = eventForm.querySelectorAll('input, textarea, select');
                inputs.forEach(input => {
                    input.addEventListener('input', function() {
                        this.style.borderColor = '';
                        const error = this.parentNode.querySelector('.error-message');
                        if (error) {
                            error.remove();
                        }
                    });
                });
            }
        });

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    if (alert.parentNode) alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>