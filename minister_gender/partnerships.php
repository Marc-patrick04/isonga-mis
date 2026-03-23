
<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_partnership':
                $partner_name = $_POST['partner_name'];
                $partner_type = $_POST['partner_type'];
                $contact_person = $_POST['contact_person'];
                $contact_email = $_POST['contact_email'];
                $contact_phone = $_POST['contact_phone'];
                $partner_website = $_POST['partner_website'] ?? '';
                $address = $_POST['address'] ?? '';
                $focus_areas = $_POST['focus_areas'] ?? '';
                $agreement_details = $_POST['agreement_details'] ?? '';
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;
                $status = $_POST['status'] ?? 'active';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO partnerships (partner_name, partner_type, contact_person, contact_email, 
                                                contact_phone, partner_website, address, focus_areas, 
                                                agreement_details, start_date, end_date, status, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $partner_name, $partner_type, $contact_person, $contact_email, $contact_phone,
                        $partner_website, $address, $focus_areas, $agreement_details, $start_date, 
                        $end_date, $status, $user_id
                    ]);
                    
                    $partnership_id = $pdo->lastInsertId();
                    $_SESSION['success_message'] = "Partnership created successfully!";
                    
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error creating partnership: " . $e->getMessage();
                }
                break;
                
            case 'update_partnership':
                $partnership_id = $_POST['partnership_id'];
                $partner_name = $_POST['partner_name'];
                $partner_type = $_POST['partner_type'];
                $contact_person = $_POST['contact_person'];
                $contact_email = $_POST['contact_email'];
                $contact_phone = $_POST['contact_phone'];
                $partner_website = $_POST['partner_website'] ?? '';
                $address = $_POST['address'] ?? '';
                $focus_areas = $_POST['focus_areas'] ?? '';
                $agreement_details = $_POST['agreement_details'] ?? '';
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;
                $status = $_POST['status'] ?? 'active';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE partnerships 
                        SET partner_name = ?, partner_type = ?, contact_person = ?, contact_email = ?,
                            contact_phone = ?, partner_website = ?, address = ?, focus_areas = ?,
                            agreement_details = ?, start_date = ?, end_date = ?, status = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND created_by = ?
                    ");
                    $stmt->execute([
                        $partner_name, $partner_type, $contact_person, $contact_email, $contact_phone,
                        $partner_website, $address, $focus_areas, $agreement_details, $start_date, 
                        $end_date, $status, $partnership_id, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Partnership updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating partnership: " . $e->getMessage();
                }
                break;
                
            case 'add_meeting':
                $partnership_id = $_POST['partnership_id'];
                $meeting_date = $_POST['meeting_date'];
                $meeting_time = $_POST['meeting_time'];
                $meeting_type = $_POST['meeting_type'];
                $location = $_POST['location'];
                $agenda = $_POST['agenda'] ?? '';
                $discussion_points = $_POST['discussion_points'] ?? '';
                $action_items = $_POST['action_items'] ?? '';
                $next_steps = $_POST['next_steps'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO partnership_meetings (partnership_id, meeting_date, meeting_time, 
                                                         meeting_type, location, agenda, discussion_points,
                                                         action_items, next_steps, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $partnership_id, $meeting_date, $meeting_time, $meeting_type, $location,
                        $agenda, $discussion_points, $action_items, $next_steps, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Meeting recorded successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error recording meeting: " . $e->getMessage();
                }
                break;
                
            case 'add_activity':
                $partnership_id = $_POST['partnership_id'];
                $activity_title = $_POST['activity_title'];
                $activity_type = $_POST['activity_type'];
                $activity_date = $_POST['activity_date'];
                $description = $_POST['description'] ?? '';
                $participants_count = $_POST['participants_count'] ?? 0;
                $budget = $_POST['budget'] ?? 0;
                $outcomes = $_POST['outcomes'] ?? '';
                $challenges = $_POST['challenges'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO partnership_activities (partnership_id, activity_title, activity_type,
                                                           activity_date, description, participants_count,
                                                           budget, outcomes, challenges, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $partnership_id, $activity_title, $activity_type, $activity_date, $description,
                        $participants_count, $budget, $outcomes, $challenges, $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Activity recorded successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error recording activity: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: partnerships.php" . (isset($_POST['partnership_id']) ? "?view=details&id=" . $_POST['partnership_id'] : ""));
        exit();
    }
}

// Check if partnerships table exists, if not create it
try {
    $stmt = $pdo->query("SELECT 1 FROM partnerships LIMIT 1");
} catch (PDOException $e) {
    // Create partnerships table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS partnerships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_name VARCHAR(255) NOT NULL,
            partner_type ENUM('university', 'secondary_school', 'ngo', 'government', 'private_sector', 'community', 'other') NOT NULL,
            contact_person VARCHAR(255),
            contact_email VARCHAR(100),
            contact_phone VARCHAR(20),
            partner_website VARCHAR(500),
            address TEXT,
            focus_areas TEXT,
            agreement_details TEXT,
            start_date DATE,
            end_date DATE,
            status ENUM('active', 'inactive', 'pending', 'completed') DEFAULT 'active',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
    
    // Create partnership_meetings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS partnership_meetings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partnership_id INT NOT NULL,
            meeting_date DATE NOT NULL,
            meeting_time TIME NOT NULL,
            meeting_type ENUM('planning', 'review', 'coordination', 'evaluation', 'other') NOT NULL,
            location VARCHAR(255),
            agenda TEXT,
            discussion_points TEXT,
            action_items TEXT,
            next_steps TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (partnership_id) REFERENCES partnerships(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
    
    // Create partnership_activities table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS partnership_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partnership_id INT NOT NULL,
            activity_title VARCHAR(255) NOT NULL,
            activity_type ENUM('workshop', 'training', 'event', 'visit', 'project', 'other') NOT NULL,
            activity_date DATE NOT NULL,
            description TEXT,
            participants_count INT DEFAULT 0,
            budget DECIMAL(10,2) DEFAULT 0.00,
            outcomes TEXT,
            challenges TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (partnership_id) REFERENCES partnerships(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
}

// Get view and action parameters
$view = $_GET['view'] ?? 'list';
$partnership_id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

// Get partnerships
try {
    $query = "
        SELECT p.*, u.full_name as creator_name
        FROM partnerships p
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.created_by = ?
    ";
    
    $params = [$user_id];
    
    if ($partnership_id) {
        $query .= " AND p.id = ?";
        $params[] = $partnership_id;
    }
    
    $query .= " ORDER BY p.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    if ($partnership_id) {
        $partnership = $stmt->fetch(PDO::FETCH_ASSOC);
        $partnerships = $partnership ? [$partnership] : [];
    } else {
        $partnerships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Partnerships query error: " . $e->getMessage());
    $partnerships = [];
    $partnership = null;
}

// Get partnership meetings and activities if viewing details
$partnership_meetings = [];
$partnership_activities = [];
if ($partnership_id && $partnership) {
    try {
        // Get partnership meetings
        $stmt = $pdo->prepare("
            SELECT * FROM partnership_meetings 
            WHERE partnership_id = ? 
            ORDER BY meeting_date DESC, meeting_time DESC
            LIMIT 10
        ");
        $stmt->execute([$partnership_id]);
        $partnership_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get partnership activities
        $stmt = $pdo->prepare("
            SELECT * FROM partnership_activities 
            WHERE partnership_id = ? 
            ORDER BY activity_date DESC
            LIMIT 10
        ");
        $stmt->execute([$partnership_id]);
        $partnership_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Partnership details error: " . $e->getMessage());
    }
}

// Get partnership statistics
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_partnerships,
               SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_partnerships,
               SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_partnerships,
               COUNT(DISTINCT partner_type) as partner_types
        FROM partnerships 
        WHERE created_by = ?
    ");
    $stmt->execute([$user_id]);
    $partnership_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get partnerships by type
    $stmt = $pdo->prepare("
        SELECT partner_type, COUNT(*) as count 
        FROM partnerships 
        WHERE created_by = ?
        GROUP BY partner_type
        ORDER BY count DESC
    ");
    $stmt->execute([$user_id]);
    $partnerships_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Partnership statistics error: " . $e->getMessage());
    $partnership_stats = ['total_partnerships' => 0, 'active_partnerships' => 0, 'pending_partnerships' => 0, 'partner_types' => 0];
    $partnerships_by_type = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partnerships Management - Minister of Gender & Protocol</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #a78bfa;
            --accent-purple: #7c3aed;
            --light-purple: #f3f4f6;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-purple: #a78bfa;
            --secondary-purple: #c4b5fd;
            --accent-purple: #8b5cf6;
            --light-purple: #1f2937;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
        }

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
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
            border-left: 3px solid var(--primary-purple);
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
            background: var(--light-purple);
            color: var(--primary-purple);
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

        /* Cards */
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

        .card-body {
            padding: 1.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-purple);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-purple);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
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
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-select, .form-input, .form-textarea {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: var(--success);
        }

        .status-inactive {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .status-pending {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }

        .type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #dbeafe;
            color: var(--primary-purple);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Partnership Grid */
        .partnership-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .partnership-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .partnership-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .partnership-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--light-purple);
        }

        .partnership-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .partnership-type {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background: var(--primary-purple);
            color: white;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .partnership-content {
            padding: 1.25rem;
        }

        .partnership-contact {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .contact-item i {
            width: 16px;
            color: var(--primary-purple);
        }

        .partnership-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        /* Progress Bars */
        .progress-bar {
            height: 6px;
            background: var(--medium-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
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
            
            .partnership-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .action-buttons {
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
                    <h1>Isonga - Minister of Gender & Protocol</h1>
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
                        <div class="user-role">Minister of Gender & Protocol</div>
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Gender Issues</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="protocol.php">
                        <i class="fas fa-handshake"></i>
                        <span>Protocol & Visitors</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Gender Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="partnerships.php" class="active">
                        <i class="fas fa-handshake"></i>
                        <span>Partnerships</span>
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

            <?php if ($view === 'list' || $view === 'create'): ?>
                <!-- Partnerships List View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>Partnerships Management</h1>
                        <p>Manage partnerships with universities, schools, NGOs, and other organizations</p>
                    </div>
                    <div class="page-actions">
                        <button class="btn btn-primary" onclick="openModal('createPartnershipModal')">
                            <i class="fas fa-plus"></i> New Partnership
                        </button>
                    </div>
                </div>

                <!-- Statistics Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $partnership_stats['total_partnerships']; ?></div>
                            <div class="stat-label">Total Partnerships</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $partnership_stats['active_partnerships']; ?></div>
                            <div class="stat-label">Active Partnerships</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $partnership_stats['pending_partnerships']; ?></div>
                            <div class="stat-label">Pending Partnerships</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $partnership_stats['partner_types']; ?></div>
                            <div class="stat-label">Partner Types</div>
                        </div>
                    </div>
                </div>

                <!-- Partnerships by Type -->
                <?php if (!empty($partnerships_by_type)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Partnerships by Type</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; gap: 1rem;">
                            <?php foreach ($partnerships_by_type as $type): ?>
                                <div>
                                    <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 0.5rem;">
                                        <span style="font-weight: 600; text-transform: capitalize;">
                                            <?php echo str_replace('_', ' ', $type['partner_type']); ?>
                                        </span>
                                        <span style="font-weight: 600; color: var(--primary-purple);">
                                            <?php echo $type['count']; ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo ($type['count'] / $partnership_stats['total_partnerships']) * 100; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Partnerships Grid -->
                <?php if (empty($partnerships)): ?>
                    <div class="card">
                        <div class="card-body" style="text-align: center; padding: 3rem;">
                            <i class="fas fa-handshake" style="font-size: 4rem; color: var(--dark-gray); margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3 style="color: var(--dark-gray); margin-bottom: 1rem;">No Partnerships Found</h3>
                            <p style="color: var(--dark-gray); margin-bottom: 2rem;">Create your first partnership to get started with collaboration.</p>
                            <button class="btn btn-primary" onclick="openModal('createPartnershipModal')">
                                <i class="fas fa-plus"></i> Create Your First Partnership
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="partnership-grid">
                        <?php foreach ($partnerships as $partnership): ?>
                            <div class="partnership-card">
                                <div class="partnership-header">
                                    <h3 class="partnership-title"><?php echo htmlspecialchars($partnership['partner_name']); ?></h3>
                                    <span class="partnership-type">
                                        <?php echo ucfirst(str_replace('_', ' ', $partnership['partner_type'])); ?>
                                    </span>
                                </div>
                                <div class="partnership-content">
                                    <div class="partnership-contact">
                                        <?php if ($partnership['contact_person']): ?>
                                            <div class="contact-item">
                                                <i class="fas fa-user"></i>
                                                <span><?php echo htmlspecialchars($partnership['contact_person']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($partnership['contact_email']): ?>
                                            <div class="contact-item">
                                                <i class="fas fa-envelope"></i>
                                                <span><?php echo htmlspecialchars($partnership['contact_email']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($partnership['contact_phone']): ?>
                                            <div class="contact-item">
                                                <i class="fas fa-phone"></i>
                                                <span><?php echo htmlspecialchars($partnership['contact_phone']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($partnership['focus_areas']): ?>
                                        <div style="margin-bottom: 1rem;">
                                            <strong style="font-size: 0.8rem;">Focus Areas:</strong>
                                            <p style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($partnership['focus_areas']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="partnership-meta">
                                        <span class="status-badge status-<?php echo $partnership['status']; ?>">
                                            <?php echo ucfirst($partnership['status']); ?>
                                        </span>
                                        <span>
                                            <?php if ($partnership['start_date']): ?>
                                                Since <?php echo date('M Y', strtotime($partnership['start_date'])); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                        <a href="partnerships.php?view=details&id=<?php echo $partnership['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="partnerships.php?view=edit&id=<?php echo $partnership['id']; ?>" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'details' && $partnership): ?>
                <!-- Partnership Details View -->
                <div class="page-header">
                    <div class="page-title">
                        <h1><?php echo htmlspecialchars($partnership['partner_name']); ?></h1>
                        <p>Partnership Details and Collaboration Activities</p>
                    </div>
                    <div class="page-actions">
                        <a href="partnerships.php?view=edit&id=<?php echo $partnership['id']; ?>" class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Edit Partnership
                        </a>
                        <a href="partnerships.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Partnerships
                        </a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($partnership_meetings); ?></div>
                            <div class="stat-label">Meetings</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($partnership_activities); ?></div>
                            <div class="stat-label">Activities</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php 
                                if ($partnership['start_date']) {
                                    $start = new DateTime($partnership['start_date']);
                                    $now = new DateTime();
                                    $interval = $start->diff($now);
                                    echo $interval->format('%y years %m months');
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </div>
                            <div class="stat-label">Duration</div>
                        </div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php
                                $total_budget = 0;
                                foreach ($partnership_activities as $activity) {
                                    $total_budget += $activity['budget'];
                                }
                                echo number_format($total_budget);
                                ?>
                            </div>
                            <div class="stat-label">Total Budget (RWF)</div>
                        </div>
                    </div>
                </div>

                <!-- Partnership Information -->
                <div class="card">
                    <div class="card-header">
                        <h3>Partnership Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Partner Name</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($partnership['partner_name']); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Partner Type</label>
                                <div class="form-input" style="background: var(--light-gray); text-transform: capitalize;">
                                    <?php echo str_replace('_', ' ', $partnership['partner_type']); ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Person</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($partnership['contact_person'] ?: 'Not specified'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Email</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($partnership['contact_email'] ?: 'Not specified'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Phone</label>
                                <div class="form-input" style="background: var(--light-gray);"><?php echo htmlspecialchars($partnership['contact_phone'] ?: 'Not specified'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Website</label>
                                <div class="form-input" style="background: var(--light-gray);">
                                    <?php if ($partnership['partner_website']): ?>
                                        <a href="<?php echo htmlspecialchars($partnership['partner_website']); ?>" target="_blank">
                                            <?php echo htmlspecialchars($partnership['partner_website']); ?>
                                        </a>
                                    <?php else: ?>
                                        Not specified
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Start Date</label>
                                <div class="form-input" style="background: var(--light-gray);">
                                    <?php echo $partnership['start_date'] ? date('F j, Y', strtotime($partnership['start_date'])) : 'Not specified'; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">End Date</label>
                                <div class="form-input" style="background: var(--light-gray);">
                                    <?php echo $partnership['end_date'] ? date('F j, Y', strtotime($partnership['end_date'])) : 'Ongoing'; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <div class="form-input" style="background: var(--light-gray);">
                                    <span class="status-badge status-<?php echo $partnership['status']; ?>">
                                        <?php echo ucfirst($partnership['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Address</label>
                                <div class="form-input" style="background: var(--light-gray); min-height: 60px;">
                                    <?php echo htmlspecialchars($partnership['address'] ?: 'Not specified'); ?>
                                </div>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Focus Areas</label>
                                <div class="form-input" style="background: var(--light-gray); min-height: 80px;">
                                    <?php echo htmlspecialchars($partnership['focus_areas'] ?: 'Not specified'); ?>
                                </div>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Agreement Details</label>
                                <div class="form-input" style="background: var(--light-gray); min-height: 100px;">
                                    <?php echo htmlspecialchars($partnership['agreement_details'] ?: 'Not specified'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Meetings and Activities -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <!-- Meetings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Partnership Meetings</h3>
                            <button class="btn btn-primary btn-sm" onclick="openModal('addMeetingModal')">
                                <i class="fas fa-plus"></i> Add Meeting
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($partnership_meetings)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-calendar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No meetings recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Location</th>
                                                <th>Agenda</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($partnership_meetings as $meeting): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-size: 0.8rem;"><?php echo date('M j, Y', strtotime($meeting['meeting_date'])); ?></div>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray);"><?php echo date('g:i A', strtotime($meeting['meeting_time'])); ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="type-badge">
                                                            <?php echo ucfirst($meeting['meeting_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($meeting['location']); ?></td>
                                                    <td>
                                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                            <?php echo htmlspecialchars($meeting['agenda'] ?: 'No agenda'); ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Activities -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Partnership Activities</h3>
                            <button class="btn btn-primary btn-sm" onclick="openModal('addActivityModal')">
                                <i class="fas fa-plus"></i> Add Activity
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($partnership_activities)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                    <i class="fas fa-tasks" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No activities recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Activity</th>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Participants</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($partnership_activities as $activity): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight: 600; font-size: 0.8rem;"><?php echo htmlspecialchars($activity['activity_title']); ?></div>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                            <?php echo htmlspecialchars(substr($activity['description'], 0, 50)); ?>...
                                                        </div>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></td>
                                                    <td>
                                                        <span class="type-badge">
                                                            <?php echo ucfirst($activity['activity_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $activity['participants_count']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php elseif ($view === 'edit' && $partnership): ?>
                <!-- Edit Partnership View -->
                <div class="card">
                    <div class="card-header">
                        <h3>Edit Partnership: <?php echo htmlspecialchars($partnership['partner_name']); ?></h3>
                        <a href="partnerships.php?view=details&id=<?php echo $partnership['id']; ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Partnership
                        </a>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="update_partnership">
                            <input type="hidden" name="partnership_id" value="<?php echo $partnership['id']; ?>">
                            
                            <div class="form-group">
                                <label class="form-label">Partner Name *</label>
                                <input type="text" name="partner_name" class="form-input" value="<?php echo htmlspecialchars($partnership['partner_name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Partner Type *</label>
                                <select name="partner_type" class="form-select" required>
                                    <option value="university" <?php echo $partnership['partner_type'] === 'university' ? 'selected' : ''; ?>>University</option>
                                    <option value="secondary_school" <?php echo $partnership['partner_type'] === 'secondary_school' ? 'selected' : ''; ?>>Secondary School</option>
                                    <option value="ngo" <?php echo $partnership['partner_type'] === 'ngo' ? 'selected' : ''; ?>>NGO</option>
                                    <option value="government" <?php echo $partnership['partner_type'] === 'government' ? 'selected' : ''; ?>>Government</option>
                                    <option value="private_sector" <?php echo $partnership['partner_type'] === 'private_sector' ? 'selected' : ''; ?>>Private Sector</option>
                                    <option value="community" <?php echo $partnership['partner_type'] === 'community' ? 'selected' : ''; ?>>Community</option>
                                    <option value="other" <?php echo $partnership['partner_type'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Contact Person</label>
                                <input type="text" name="contact_person" class="form-input" value="<?php echo htmlspecialchars($partnership['contact_person'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Contact Email</label>
                                <input type="email" name="contact_email" class="form-input" value="<?php echo htmlspecialchars($partnership['contact_email'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Contact Phone</label>
                                <input type="text" name="contact_phone" class="form-input" value="<?php echo htmlspecialchars($partnership['contact_phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Website</label>
                                <input type="url" name="partner_website" class="form-input" value="<?php echo htmlspecialchars($partnership['partner_website'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($partnership['start_date'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($partnership['end_date'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" required>
                                    <option value="active" <?php echo $partnership['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $partnership['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="pending" <?php echo $partnership['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="completed" <?php echo $partnership['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-textarea"><?php echo htmlspecialchars($partnership['address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Focus Areas</label>
                                <textarea name="focus_areas" class="form-textarea" placeholder="Describe the main areas of collaboration..."><?php echo htmlspecialchars($partnership['focus_areas'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Agreement Details</label>
                                <textarea name="agreement_details" class="form-textarea" placeholder="Describe the partnership agreement terms..."><?php echo htmlspecialchars($partnership['agreement_details'] ?? ''); ?></textarea>
                            </div>
                            
                            <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Partnership
                                </button>
                                <a href="partnerships.php?view=details&id=<?php echo $partnership['id']; ?>" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Create Partnership Modal -->
    <div id="createPartnershipModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Partnership</h3>
                <button class="modal-close" onclick="closeModal('createPartnershipModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="create_partnership">
                    
                    <div class="form-group">
                        <label class="form-label">Partner Name *</label>
                        <input type="text" name="partner_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Partner Type *</label>
                        <select name="partner_type" class="form-select" required>
                            <option value="university">University</option>
                            <option value="secondary_school">Secondary School</option>
                            <option value="ngo">NGO</option>
                            <option value="government">Government</option>
                            <option value="private_sector">Private Sector</option>
                            <option value="community">Community</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Email</label>
                        <input type="email" name="contact_email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input type="url" name="partner_website" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="inactive">Inactive</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-textarea"></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Focus Areas</label>
                        <textarea name="focus_areas" class="form-textarea" placeholder="Describe the main areas of collaboration..."></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Agreement Details</label>
                        <textarea name="agreement_details" class="form-textarea" placeholder="Describe the partnership agreement terms..."></textarea>
                    </div>
                    
                    <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Partnership
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('createPartnershipModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Meeting Modal -->
    <div id="addMeetingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Record Partnership Meeting</h3>
                <button class="modal-close" onclick="closeModal('addMeetingModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="add_meeting">
                    <input type="hidden" name="partnership_id" value="<?php echo $partnership_id; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Meeting Date *</label>
                        <input type="date" name="meeting_date" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Meeting Time *</label>
                        <input type="time" name="meeting_time" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Meeting Type *</label>
                        <select name="meeting_type" class="form-select" required>
                            <option value="planning">Planning</option>
                            <option value="review">Review</option>
                            <option value="coordination">Coordination</option>
                            <option value="evaluation">Evaluation</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-input">
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Agenda</label>
                        <textarea name="agenda" class="form-textarea" placeholder="Meeting agenda..."></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Discussion Points</label>
                        <textarea name="discussion_points" class="form-textarea" placeholder="Key discussion points..."></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Action Items</label>
                        <textarea name="action_items" class="form-textarea" placeholder="Action items and responsibilities..."></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Next Steps</label>
                        <textarea name="next_steps" class="form-textarea" placeholder="Next steps and follow-up..."></textarea>
                    </div>
                    
                    <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Record Meeting
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addMeetingModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div id="addActivityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Record Partnership Activity</h3>
                <button class="modal-close" onclick="closeModal('addActivityModal')">&times;</button>
            </div>
            <div class="modal-body">
                                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="add_activity">
                    <input type="hidden" name="partnership_id" value="<?php echo $partnership_id; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Activity Title *</label>
                        <input type="text" name="activity_title" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Activity Type *</label>
                        <select name="activity_type" class="form-select" required>
                            <option value="workshop">Workshop</option>
                            <option value="training">Training</option>
                            <option value="event">Event</option>
                            <option value="visit">Visit</option>
                            <option value="project">Project</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Activity Date *</label>
                        <input type="date" name="activity_date" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Participants Count</label>
                        <input type="number" name="participants_count" class="form-input" min="0" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Budget (RWF)</label>
                        <input type="number" name="budget" class="form-input" min="0" step="0.01" value="0">
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Describe the activity..."></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Outcomes</label>
                        <textarea name="outcomes" class="form-textarea" placeholder="Activity outcomes and achievements..."></textarea>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Challenges</label>
                        <textarea name="challenges" class="form-textarea" placeholder="Any challenges faced..."></textarea>
                    </div>
                    
                    <div style="grid-column: 1 / -1; display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Record Activity
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addActivityModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
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
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }

        // Set today's date as default for new meetings and activities
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const meetingDateInput = document.querySelector('input[name="meeting_date"]');
            const activityDateInput = document.querySelector('input[name="activity_date"]');
            
            if (meetingDateInput && !meetingDateInput.value) {
                meetingDateInput.value = today;
            }
            if (activityDateInput && !activityDateInput.value) {
                activityDateInput.value = today;
            }

            // Set current time for meeting time
            const now = new Date();
            const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                             now.getMinutes().toString().padStart(2, '0');
            const meetingTimeInput = document.querySelector('input[name="meeting_time"]');
            if (meetingTimeInput && !meetingTimeInput.value) {
                meetingTimeInput.value = timeString;
            }
        });

        // Auto-format phone numbers
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInputs = document.querySelectorAll('input[name="contact_phone"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.startsWith('250')) {
                        value = '+' + value;
                    } else if (value.startsWith('0')) {
                        value = '+25' + value;
                    }
                    e.target.value = value;
                });
            });
        });

        // Validate email format
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const emailInputs = form.querySelectorAll('input[type="email"]');
                    let isValid = true;

                    emailInputs.forEach(input => {
                        if (input.value && !validateEmail(input.value)) {
                            isValid = false;
                            input.style.borderColor = 'var(--danger)';
                            alert('Please enter a valid email address');
                        } else {
                            input.style.borderColor = '';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Auto-save form data
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                const inputs = form.querySelectorAll('input, textarea, select');
                const formId = form.id || 'form_' + Math.random().toString(36).substr(2, 9);
                
                // Load saved data
                inputs.forEach(input => {
                    const savedValue = localStorage.getItem(formId + '_' + input.name);
                    if (savedValue && !input.value) {
                        input.value = savedValue;
                    }
                });

                // Save data on input
                inputs.forEach(input => {
                    input.addEventListener('input', function() {
                        localStorage.setItem(formId + '_' + input.name, input.value);
                    });
                });

                // Clear saved data on form submit
                form.addEventListener('submit', function() {
                    inputs.forEach(input => {
                        localStorage.removeItem(formId + '_' + input.name);
                    });
                });
            });
        });

        // Export partnerships data
        function exportPartnerships(format) {
            const partnerships = <?php echo json_encode($partnerships); ?>;
            
            if (format === 'csv') {
                // Convert to CSV
                const headers = ['Partner Name', 'Type', 'Contact Person', 'Email', 'Phone', 'Status', 'Start Date'];
                const csvContent = [
                    headers.join(','),
                    ...partnerships.map(p => [
                        `"${p.partner_name}"`,
                        `"${p.partner_type}"`,
                        `"${p.contact_person || ''}"`,
                        `"${p.contact_email || ''}"`,
                        `"${p.contact_phone || ''}"`,
                        `"${p.status}"`,
                        `"${p.start_date || ''}"`
                    ].join(','))
                ].join('\n');

                // Download CSV
                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'partnerships_' + new Date().toISOString().split('T')[0] + '.csv';
                a.click();
                window.URL.revokeObjectURL(url);
            }
        }

        // Print partnership details
        function printPartnership(partnershipId) {
            const url = `print_partnership.php?id=${partnershipId}`;
            const printWindow = window.open(url, '_blank');
            printWindow.onload = function() {
                printWindow.print();
            };
        }

        // Search and filter functionality
        function filterPartnerships() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const typeFilter = document.getElementById('typeFilter').value;
            
            const partnershipCards = document.querySelectorAll('.partnership-card');
            
            partnershipCards.forEach(card => {
                const partnerName = card.querySelector('.partnership-title').textContent.toLowerCase();
                const partnerType = card.querySelector('.partnership-type').textContent.toLowerCase();
                const status = card.querySelector('.status-badge').textContent.toLowerCase();
                
                const matchesSearch = partnerName.includes(searchTerm);
                const matchesStatus = statusFilter === 'all' || status === statusFilter;
                const matchesType = typeFilter === 'all' || partnerType === typeFilter;
                
                if (matchesSearch && matchesStatus && matchesType) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Initialize filters if they exist
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const typeFilter = document.getElementById('typeFilter');
            
            if (searchInput) searchInput.addEventListener('input', filterPartnerships);
            if (statusFilter) statusFilter.addEventListener('change', filterPartnerships);
            if (typeFilter) typeFilter.addEventListener('change', filterPartnerships);
        });

        // Quick actions
        function quickAction(action, partnershipId) {
            switch(action) {
                case 'send_email':
                    const partnership = <?php echo json_encode($partnerships); ?>.find(p => p.id == partnershipId);
                    if (partnership && partnership.contact_email) {
                        window.location.href = `mailto:${partnership.contact_email}?subject=Partnership Update - ${partnership.partner_name}`;
                    } else {
                        alert('No email address available for this partner');
                    }
                    break;
                    
                case 'schedule_meeting':
                    openModal('addMeetingModal');
                    break;
                    
                case 'add_note':
                    // Implement add note functionality
                    const note = prompt('Enter your note:');
                    if (note) {
                        // Save note to database
                        console.log('Note saved:', note);
                    }
                    break;
            }
        }

        // Statistics chart (simple implementation)
        function renderPartnershipStats() {
            const stats = <?php echo json_encode($partnerships_by_type); ?>;
            if (stats.length > 0) {
                console.log('Rendering partnership statistics:', stats);
                // This is where you would integrate with a charting library
                // For now, we'll just log the data
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            renderPartnershipStats();
            
            // Add loading animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Responsive menu toggle for mobile
        function toggleMobileMenu() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.style.display = sidebar.style.display === 'none' ? 'block' : 'none';
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth > 768) {
                sidebar.style.display = 'block';
            }
        });
    </script>
</body>
</html>