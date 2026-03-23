<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Environment & Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_environment') {
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

// Handle AJAX request for incident details
if (isset($_GET['action']) && $_GET['action'] == 'get_incident_details') {
    header('Content-Type: application/json');
    
    if (isset($_GET['incident_id'])) {
        $incident_id = intval($_GET['incident_id']);
        
        try {
            $stmt = $pdo->prepare("
                SELECT si.*, 
                       u_reporter.full_name as reporter_user_name,
                       u_resolver.full_name as resolver_name
                FROM security_incidents si 
                LEFT JOIN users u_reporter ON si.reported_by_user = u_reporter.id 
                LEFT JOIN users u_resolver ON si.resolved_by = u_resolver.id 
                WHERE si.id = ?
            ");
            $stmt->execute([$incident_id]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($incident) {
                echo json_encode([
                    'success' => true,
                    'data' => $incident
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Incident not found'
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching incident details'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No incident ID provided'
        ]);
    }
    exit();
}

// Handle AJAX request for incident update form
if (isset($_GET['action']) && $_GET['action'] == 'get_incident_update_form') {
    if (isset($_GET['incident_id'])) {
        $incident_id = intval($_GET['incident_id']);
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM security_incidents WHERE id = ?");
            $stmt->execute([$incident_id]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($incident) {
                echo '
                <input type="hidden" name="incident_id" value="' . $incident['id'] . '">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="reported" ' . ($incident['status'] == 'reported' ? 'selected' : '') . '>Reported</option>
                            <option value="under_investigation" ' . ($incident['status'] == 'under_investigation' ? 'selected' : '') . '>Under Investigation</option>
                            <option value="resolved" ' . ($incident['status'] == 'resolved' ? 'selected' : '') . '>Resolved</option>
                            <option value="closed" ' . ($incident['status'] == 'closed' ? 'selected' : '') . '>Closed</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group form-full">
                        <label class="form-label">Action Taken</label>
                        <textarea name="action_taken" class="form-control" placeholder="Describe actions taken to address this incident..." rows="3">' . htmlspecialchars($incident['action_taken'] ?? '') . '</textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group form-full">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" placeholder="Additional notes about resolution..." rows="3">' . htmlspecialchars($incident['resolution_notes'] ?? '') . '</textarea>
                    </div>
                </div>';
            }
        } catch (PDOException $e) {
            echo '<div class="alert alert-danger">Error loading incident data</div>';
        }
    }
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_incident'])) {
        // Add new security incident
        $title = htmlspecialchars(trim($_POST['title']));
        $description = htmlspecialchars(trim($_POST['description']));
        $incident_type = $_POST['incident_type'];
        $location = htmlspecialchars(trim($_POST['location']));
        $severity = $_POST['severity'];
        $reported_by = htmlspecialchars(trim($_POST['reported_by']));
        $reporter_contact = htmlspecialchars(trim($_POST['reporter_contact']));
        
        // Validate required fields
        if (empty($title) || empty($description) || empty($location) || empty($reported_by)) {
            $error_message = "Please fill in all required fields.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO security_incidents 
                    (title, description, incident_type, location, severity, reported_by, reporter_contact, status, reported_by_user, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'reported', ?, NOW())
                ");
                $stmt->execute([$title, $description, $incident_type, $location, $severity, $reported_by, $reporter_contact, $user_id]);
                $success_message = "Security incident reported successfully!";
                
                // Log the action
                logAction($pdo, $user_id, "Reported security incident: " . $title);
            } catch (PDOException $e) {
                $error_message = "Error reporting incident: " . $e->getMessage();
                error_log("Incident reporting error: " . $e->getMessage());
            }
        }
    } elseif (isset($_POST['update_incident'])) {
        // Update incident status
        $incident_id = $_POST['incident_id'];
        $status = $_POST['status'];
        $resolution_notes = htmlspecialchars(trim($_POST['resolution_notes']));
        $action_taken = htmlspecialchars(trim($_POST['action_taken']));
        
        try {
            // Get incident details for logging
            $stmt = $pdo->prepare("SELECT title FROM security_incidents WHERE id = ?");
            $stmt->execute([$incident_id]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                UPDATE security_incidents 
                SET status = ?, resolution_notes = ?, action_taken = ?, resolved_by = ?, resolved_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $resolution_notes, $action_taken, $user_id, $incident_id]);
            $success_message = "Incident status updated successfully!";
            
            // Log the action
            logAction($pdo, $user_id, "Updated incident #" . $incident_id . " (" . $incident['title'] . ") to status: " . $status);
        } catch (PDOException $e) {
            $error_message = "Error updating incident: " . $e->getMessage();
            error_log("Incident update error: " . $e->getMessage());
        }
    } elseif (isset($_POST['add_prevention'])) {
        // Add security prevention measure
        $title = htmlspecialchars(trim($_POST['prevention_title']));
        $description = htmlspecialchars(trim($_POST['prevention_description']));
        $measure_type = $_POST['measure_type'];
        $target_area = htmlspecialchars(trim($_POST['target_area']));
        $implementation_date = $_POST['implementation_date'];
        
        // Validate required fields
        if (empty($title) || empty($description)) {
            $error_message = "Please fill in all required fields.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO security_prevention_measures 
                    (title, description, measure_type, target_area, implementation_date, status, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'planned', ?, NOW())
                ");
                $stmt->execute([$title, $description, $measure_type, $target_area, $implementation_date, $user_id]);
                $success_message = "Security prevention measure added successfully!";
                
                // Log the action
                logAction($pdo, $user_id, "Added prevention measure: " . $title);
            } catch (PDOException $e) {
                $error_message = "Error adding prevention measure: " . $e->getMessage();
                error_log("Prevention measure error: " . $e->getMessage());
            }
        }
    } elseif (isset($_POST['update_prevention'])) {
        // Update prevention measure
        $measure_id = $_POST['measure_id'];
        $status = $_POST['status'];
        $notes = htmlspecialchars(trim($_POST['notes']));
        
        try {
            $stmt = $pdo->prepare("
                UPDATE security_prevention_measures 
                SET status = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $notes, $measure_id]);
            $success_message = "Prevention measure updated successfully!";
            
            // Log the action
            logAction($pdo, $user_id, "Updated prevention measure #" . $measure_id . " to status: " . $status);
        } catch (PDOException $e) {
            $error_message = "Error updating prevention measure: " . $e->getMessage();
            error_log("Prevention update error: " . $e->getMessage());
        }
    }
}

// Handle incident deletion
if (isset($_GET['delete_incident'])) {
    $delete_id = $_GET['delete_incident'];
    
    // Verify ownership before deletion
    try {
        $stmt = $pdo->prepare("SELECT title FROM security_incidents WHERE id = ? AND (reported_by_user = ? OR ? = 1)");
        $stmt->execute([$delete_id, $user_id, $user_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($incident) {
            $stmt = $pdo->prepare("DELETE FROM security_incidents WHERE id = ?");
            $stmt->execute([$delete_id]);
            $success_message = "Incident deleted successfully!";
            
            // Log the action
            logAction($pdo, $user_id, "Deleted incident: " . $incident['title']);
        } else {
            $error_message = "You don't have permission to delete this incident.";
        }
    } catch (PDOException $e) {
        $error_message = "Error deleting incident: " . $e->getMessage();
        error_log("Incident deletion error: " . $e->getMessage());
    }
}

// Handle prevention measure deletion
if (isset($_GET['delete_prevention'])) {
    $delete_id = $_GET['delete_prevention'];
    
    try {
        $stmt = $pdo->prepare("SELECT title FROM security_prevention_measures WHERE id = ? AND created_by = ?");
        $stmt->execute([$delete_id, $user_id]);
        $measure = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($measure) {
            $stmt = $pdo->prepare("DELETE FROM security_prevention_measures WHERE id = ?");
            $stmt->execute([$delete_id]);
            $success_message = "Prevention measure deleted successfully!";
            
            // Log the action
            logAction($pdo, $user_id, "Deleted prevention measure: " . $measure['title']);
        } else {
            $error_message = "You don't have permission to delete this prevention measure.";
        }
    } catch (PDOException $e) {
        $error_message = "Error deleting prevention measure: " . $e->getMessage();
        error_log("Prevention deletion error: " . $e->getMessage());
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$severity_filter = $_GET['severity'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build query for security incidents
$query = "SELECT si.*, u.full_name as reporter_name 
          FROM security_incidents si 
          LEFT JOIN users u ON si.reported_by_user = u.id 
          WHERE 1=1";
$params = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND si.status = ?";
    $params[] = $status_filter;
}

if ($severity_filter !== 'all') {
    $query .= " AND si.severity = ?";
    $params[] = $severity_filter;
}

if ($type_filter !== 'all') {
    $query .= " AND si.incident_type = ?";
    $params[] = $type_filter;
}

if (!empty($search_term)) {
    $query .= " AND (si.title LIKE ? OR si.description LIKE ? OR si.location LIKE ? OR si.reported_by LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY si.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Incidents fetch error: " . $e->getMessage());
    $incidents = [];
}

// Get prevention measures
try {
    $stmt = $pdo->prepare("
        SELECT spm.*, u.full_name as creator_name 
        FROM security_prevention_measures spm 
        LEFT JOIN users u ON spm.created_by = u.id 
        ORDER BY spm.created_at DESC
    ");
    $stmt->execute();
    $prevention_measures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Prevention measures fetch error: " . $e->getMessage());
    $prevention_measures = [];
}

// Get statistics
try {
    // Total incidents
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM security_incidents");
    $total_incidents = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Incidents by status
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM security_incidents GROUP BY status");
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Incidents by severity
    $stmt = $pdo->query("SELECT severity, COUNT(*) as count FROM security_incidents GROUP BY severity");
    $severity_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent incidents (last 7 days)
    $stmt = $pdo->query("SELECT COUNT(*) as recent FROM security_incidents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recent_incidents = $stmt->fetch(PDO::FETCH_ASSOC)['recent'] ?? 0;
    
    // Prevention measures by status
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM security_prevention_measures GROUP BY status");
    $prevention_status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Statistics fetch error: " . $e->getMessage());
    $total_incidents = 0;
    $status_counts = [];
    $severity_counts = [];
    $recent_incidents = 0;
    $prevention_status_counts = [];
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
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    error_log("Unread messages error: " . $e->getMessage());
    $unread_messages = 0;
}

// Create security_incidents table if it doesn't exist
try {
    $stmt = $pdo->query("
        CREATE TABLE IF NOT EXISTS security_incidents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            incident_type ENUM('theft', 'assault', 'vandalism', 'harassment', 'unauthorized_access', 'other') DEFAULT 'other',
            location VARCHAR(255) NOT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            reported_by VARCHAR(100) NOT NULL,
            reporter_contact VARCHAR(100),
            status ENUM('reported', 'under_investigation', 'resolved', 'closed') DEFAULT 'reported',
            resolution_notes TEXT,
            action_taken TEXT,
            reported_by_user INT,
            resolved_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME,
            FOREIGN KEY (reported_by_user) REFERENCES users(id),
            FOREIGN KEY (resolved_by) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Security incidents table creation error: " . $e->getMessage());
}

// Create security_prevention_measures table if it doesn't exist
try {
    $stmt = $pdo->query("
        CREATE TABLE IF NOT EXISTS security_prevention_measures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            measure_type ENUM('awareness', 'patrol', 'equipment', 'policy', 'training') DEFAULT 'awareness',
            target_area VARCHAR(255),
            implementation_date DATE,
            status ENUM('planned', 'in_progress', 'implemented', 'cancelled') DEFAULT 'planned',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Security prevention measures table creation error: " . $e->getMessage());
}

// Create action_logs table if it doesn't exist
try {
    $stmt = $pdo->query("
        CREATE TABLE IF NOT EXISTS action_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(255) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Action logs table creation error: " . $e->getMessage());
}

// Function to log actions
function logAction($pdo, $user_id, $action) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $pdo->prepare("INSERT INTO action_logs (user_id, action, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $action, $ip_address]);
    } catch (PDOException $e) {
        error_log("Action logging error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Security - Minister of Environment & Security</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-green: #4caf50;
            --accent-green: #2e7d32;
            --light-green: #e8f5e9;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-green: #4caf50;
            --secondary-green: #66bb6a;
            --accent-green: #388e3c;
            --light-green: #1b5e20;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 100%);
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
            color: var(--primary-green);
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
            border-color: var(--primary-green);
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
            background: var(--primary-green);
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
            background: var(--light-green);
            border-left-color: var(--primary-green);
            color: var(--primary-green);
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
            background: var(--light-gray);
            color: var(--text-dark);
            border: 1px solid var(--medium-gray);
        }

        .btn-secondary:hover {
            background: var(--medium-gray);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
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
            border-left: 3px solid var(--primary-green);
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
            background: var(--light-green);
            color: var(--primary-green);
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

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 0;
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--dark-gray);
            transition: var(--transition);
            border-bottom: 2px solid transparent;
        }

        .tab.active {
            color: var(--primary-green);
            border-bottom-color: var(--primary-green);
        }

        .tab:hover {
            color: var(--primary-green);
            background: var(--light-green);
        }

        /* Content Sections */
        .content-section {
            display: none;
            background: var(--white);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: var(--shadow-sm);
        }

        .content-section.active {
            display: block;
        }

        .section-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
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
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        /* Table */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-reported {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-under_investigation {
            background: #cce7ff;
            color: var(--primary-green);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-closed {
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .severity-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .severity-critical {
            background: #f8d7da;
            color: var(--danger);
        }

        .severity-high {
            background: #f8d7da;
            color: var(--danger);
        }

        .severity-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .severity-low {
            background: #d4edda;
            color: var(--success);
        }

        .type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #e2e3e5;
            color: var(--dark-gray);
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 4px;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        /* Prevention Measures Grid */
        .prevention-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            padding: 1.25rem;
        }

        .prevention-card {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            border-left: 4px solid var(--primary-green);
        }

        .prevention-card h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .prevention-card p {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 0.75rem;
        }

        .prevention-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--dark-gray);
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
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--medium-gray);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Alert */
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
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .prevention-grid {
                grid-template-columns: 1fr;
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
                align-items: flex-start;
                gap: 1rem;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
        }
        
        /* Additional styles for new features */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .quick-action-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            border-top: 4px solid var(--primary-green);
        }
        
        .quick-action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .quick-action-card i {
            font-size: 2rem;
            color: var(--primary-green);
            margin-bottom: 0.75rem;
        }
        
        .quick-action-card h3 {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        
        .quick-action-card p {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }
        
        .chart-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .incident-details {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 0.5rem;
        }
        
        .detail-label {
            font-weight: 600;
            min-width: 120px;
            color: var(--text-dark);
        }
        
        .detail-value {
            flex: 1;
            color: var(--dark-gray);
        }
        
        .export-options {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
        }
        
        .btn-outline:hover {
            background: var(--primary-green);
            color: white;
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
                    <h1>Isonga - Environment & Security</h1>
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
                        <div class="user-role">Minister of Environment & Security</div>
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
    <a href="tickets.php">
        <i class="fas fa-ticket-alt"></i>
        <span>Student Tickets</span>
        <?php 
        // Get pending tickets count for this minister
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_tickets 
                FROM tickets 
                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
            ");
            $stmt->execute([$user_id]);
            $pending_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['pending_tickets'] ?? 0;
            
            if ($pending_tickets > 0): ?>
                <span class="menu-badge"><?php echo $pending_tickets; ?></span>
            <?php endif;
        } catch (PDOException $e) {
            // Skip badge if error
        }
        ?>
    </a>
</li>
                <li class="menu-item">
                    <a href="projects.php">
                        <i class="fas fa-leaf"></i>
                        <span>Environmental Projects</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Action Funding</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="security.php" class="active">
                        <i class="fas fa-shield-alt"></i>
                        <span>Security</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="maintenance.php">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Environmental Clubs</span>
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
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Campus Security Management</h1>
                    <p>Monitor and manage security incidents, implement prevention measures, and ensure campus safety</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-primary" onclick="openReportIncidentModal()">
                        <i class="fas fa-plus"></i> Report Incident
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>



            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_incidents; ?></div>
                        <div class="stat-label">Total Incidents</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $recent_incidents; ?></div>
                        <div class="stat-label">Last 7 Days</div>
                    </div>
                </div>
                <?php 
                $reported_count = 0;
                $investigation_count = 0;
                foreach ($status_counts as $status) {
                    switch ($status['status']) {
                        case 'reported':
                            $reported_count = $status['count'];
                            break;
                        case 'under_investigation':
                            $investigation_count = $status['count'];
                            break;
                    }
                }
                ?>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $reported_count; ?></div>
                        <div class="stat-label">Pending Investigation</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $investigation_count; ?></div>
                        <div class="stat-label">Under Investigation</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab active" onclick="showTab('incidents')">
                        <i class="fas fa-exclamation-triangle"></i> Security Incidents
                    </button>
                    <button class="tab" onclick="showTab('prevention')">
                        <i class="fas fa-shield-alt"></i> Prevention Measures
                    </button>
                    <button class="tab" onclick="showTab('reports')">
                        <i class="fas fa-chart-bar"></i> Security Reports
                    </button>
                </div>

                <!-- Incidents Tab -->
                <div id="incidents-tab" class="content-section active">
                    <!-- Filters -->
                    <div class="filters-card">
                        <form method="GET" class="filters-form">
                            <div class="form-group">
                                <label class="form-label">Search Incidents</label>
                                <input type="text" name="search" class="form-control" placeholder="Search by title, location, or reporter..." value="<?php echo htmlspecialchars($search_term); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="reported" <?php echo $status_filter === 'reported' ? 'selected' : ''; ?>>Reported</option>
                                    <option value="under_investigation" <?php echo $status_filter === 'under_investigation' ? 'selected' : ''; ?>>Under Investigation</option>
                                    <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Severity</label>
                                <select name="severity" class="form-control">
                                    <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severity</option>
                                    <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                    <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Incident Type</label>
                                <select name="type" class="form-control">
                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="theft" <?php echo $type_filter === 'theft' ? 'selected' : ''; ?>>Theft</option>
                                    <option value="assault" <?php echo $type_filter === 'assault' ? 'selected' : ''; ?>>Assault</option>
                                    <option value="vandalism" <?php echo $type_filter === 'vandalism' ? 'selected' : ''; ?>>Vandalism</option>
                                    <option value="harassment" <?php echo $type_filter === 'harassment' ? 'selected' : ''; ?>>Harassment</option>
                                    <option value="unauthorized_access" <?php echo $type_filter === 'unauthorized_access' ? 'selected' : ''; ?>>Unauthorized Access</option>
                                    <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="security.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Incidents Table -->
                    <div class="table-container">
                        <div class="section-header">
                            <h3>Security Incidents (<?php echo count($incidents); ?>)</h3>
                            <div>

                                <button class="btn btn-primary btn-sm" onclick="openReportIncidentModal()">
                                    <i class="fas fa-plus"></i> New Incident
                                </button>
                            </div>
                        </div>
                        <?php if (empty($incidents)): ?>
                            <div class="empty-state">
                                <i class="fas fa-shield-alt"></i>
                                <h3>No Security Incidents Found</h3>
                                <p>No security incidents match your current filters.</p>
                                <button class="btn btn-primary" onclick="openReportIncidentModal()">
                                    <i class="fas fa-plus"></i> Report First Incident
                                </button>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Incident Title</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Severity</th>
                                        <th>Status</th>
                                        <th>Reported By</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($incidents as $incident): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($incident['title']); ?></strong>
                                                <?php if (strlen($incident['description']) > 100): ?>
                                                    <br><small><?php echo htmlspecialchars(substr($incident['description'], 0, 100)) . '...'; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="type-badge">
                                                    <?php echo str_replace('_', ' ', ucfirst($incident['incident_type'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                            <td>
                                                <span class="severity-badge severity-<?php echo $incident['severity']; ?>">
                                                    <?php echo ucfirst($incident['severity']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $incident['status']; ?>">
                                                    <?php echo str_replace('_', ' ', ucfirst($incident['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($incident['reported_by']); ?>
                                                <?php if ($incident['reporter_contact']): ?>
                                                    <br><small><?php echo htmlspecialchars($incident['reporter_contact']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($incident['created_at'])); ?>
                                                <br><small><?php echo date('g:i A', strtotime($incident['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-info" onclick="viewIncident(<?php echo $incident['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-warning" onclick="updateIncident(<?php echo $incident['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteIncident(<?php echo $incident['id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Prevention Measures Tab -->
                <div id="prevention-tab" class="content-section">
                    <div class="section-header">
                        <h3>Security Prevention Measures</h3>
                        <button class="btn btn-primary btn-sm" onclick="openAddPreventionModal()">
                            <i class="fas fa-plus"></i> Add Measure
                        </button>
                    </div>
                    <div class="prevention-grid">
                        <?php if (empty($prevention_measures)): ?>
                            <div class="empty-state" style="grid-column: 1 / -1;">
                                <i class="fas fa-shield-alt"></i>
                                <h3>No Prevention Measures</h3>
                                <p>No security prevention measures have been added yet.</p>
                                <button class="btn btn-primary" onclick="openAddPreventionModal()">
                                    <i class="fas fa-plus"></i> Add First Measure
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($prevention_measures as $measure): ?>
                                <div class="prevention-card">
                                    <h4><?php echo htmlspecialchars($measure['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($measure['description']); ?></p>
                                    <div class="prevention-meta">
                                        <span>
                                            <i class="fas fa-tag"></i>
                                            <?php echo str_replace('_', ' ', ucfirst($measure['measure_type'])); ?>
                                        </span>
                                        <span>
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($measure['target_area']); ?>
                                        </span>
                                    </div>
                                    <?php if ($measure['implementation_date']): ?>
                                        <div class="prevention-meta">
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('M j, Y', strtotime($measure['implementation_date'])); ?>
                                            </span>
                                            <span class="status-badge status-<?php echo $measure['status']; ?>">
                                                <?php echo ucfirst($measure['status']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="action-buttons" style="margin-top: 0.75rem;">
                                        <button class="btn btn-sm btn-info" onclick="viewPrevention(<?php echo $measure['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="updatePrevention(<?php echo $measure['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deletePrevention(<?php echo $measure['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reports Tab -->
                <div id="reports-tab" class="content-section">
                    <div class="section-header">
                        <h3>Security Reports & Analytics</h3>
                        <div class="export-options">
                            <button class="btn btn-outline" onclick="generateReport('weekly')">
                                <i class="fas fa-file-pdf"></i> Weekly Report
                            </button>
                            <button class="btn btn-outline" onclick="generateReport('monthly')">
                                <i class="fas fa-file-pdf"></i> Monthly Report
                            </button>
                        </div>
                    </div>
                    
                    <div class="charts-grid">
                        <div class="chart-container">
                            <div class="chart-title">Incidents by Status</div>
                            <div id="statusChart" style="height: 300px; display: flex; align-items: center; justify-content: center; background: var(--light-gray); border-radius: var(--border-radius);">
                                <canvas id="statusChartCanvas"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-container">
                            <div class="chart-title">Incidents by Severity</div>
                            <div id="severityChart" style="height: 300px; display: flex; align-items: center; justify-content: center; background: var(--light-gray); border-radius: var(--border-radius);">
                                <canvas id="severityChartCanvas"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filters-card">
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-chart-bar" style="font-size: 3rem; color: var(--primary-green); margin-bottom: 1rem;"></i>
                            <h3>Security Analytics Dashboard</h3>
                            <p>Comprehensive security reports and analytics</p>
                            <div class="stats-grid" style="margin-top: 2rem;">
                                <div class="stat-card">
                                    <div class="stat-content">
                                        <div class="stat-number"><?php echo $total_incidents; ?></div>
                                        <div class="stat-label">Total Incidents</div>
                                    </div>
                                </div>
                                <div class="stat-card warning">
                                    <div class="stat-content">
                                        <div class="stat-number"><?php echo $recent_incidents; ?></div>
                                        <div class="stat-label">Incidents This Week</div>
                                    </div>
                                </div>
                                <div class="stat-card danger">
                                    <div class="stat-content">
                                        <div class="stat-number">
                                            <?php 
                                            $critical_count = 0;
                                            foreach ($severity_counts as $severity) {
                                                if ($severity['severity'] === 'critical' || $severity['severity'] === 'high') {
                                                    $critical_count += $severity['count'];
                                                }
                                            }
                                            echo $critical_count;
                                            ?>
                                        </div>
                                        <div class="stat-label">High/Critical Incidents</div>
                                    </div>
                                </div>
                                <div class="stat-card success">
                                    <div class="stat-content">
                                        <div class="stat-number">
                                            <?php 
                                            $resolved_count = 0;
                                            foreach ($status_counts as $status) {
                                                if ($status['status'] === 'resolved' || $status['status'] === 'closed') {
                                                    $resolved_count += $status['count'];
                                                }
                                            }
                                            echo $resolved_count;
                                            ?>
                                        </div>
                                        <div class="stat-label">Resolved Cases</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Report Incident Modal -->
    <div id="reportIncidentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Report Security Incident</h3>
                <button class="modal-close" onclick="closeReportIncidentModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Incident Title *</label>
                            <input type="text" name="title" class="form-control" required placeholder="Brief description of the incident">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Incident Type *</label>
                            <select name="incident_type" class="form-control" required>
                                <option value="theft">Theft</option>
                                <option value="assault">Assault</option>
                                <option value="vandalism">Vandalism</option>
                                <option value="harassment">Harassment</option>
                                <option value="unauthorized_access">Unauthorized Access</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Severity Level *</label>
                            <select name="severity" class="form-control" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required placeholder="Where did it happen?">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reported By *</label>
                            <input type="text" name="reported_by" class="form-control" required placeholder="Name of reporter">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Contact Information</label>
                            <input type="text" name="reporter_contact" class="form-control" placeholder="Phone or email">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Incident Description *</label>
                            <textarea name="description" class="form-control" required placeholder="Detailed description of what happened, when, and any other relevant information..." rows="4"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeReportIncidentModal()">Cancel</button>
                    <button type="submit" name="add_incident" class="btn btn-primary">Report Incident</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Prevention Measure Modal -->
    <div id="addPreventionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Security Prevention Measure</h3>
                <button class="modal-close" onclick="closeAddPreventionModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Measure Title *</label>
                            <input type="text" name="prevention_title" class="form-control" required placeholder="Name of the prevention measure">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Measure Type *</label>
                            <select name="measure_type" class="form-control" required>
                                <option value="awareness">Awareness Campaign</option>
                                <option value="patrol">Security Patrol</option>
                                <option value="equipment">Security Equipment</option>
                                <option value="policy">Policy Change</option>
                                <option value="training">Training Program</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Target Area</label>
                            <input type="text" name="target_area" class="form-control" placeholder="Specific area or building">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Implementation Date</label>
                            <input type="date" name="implementation_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Description *</label>
                            <textarea name="prevention_description" class="form-control" required placeholder="Detailed description of the prevention measure and its objectives..." rows="4"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddPreventionModal()">Cancel</button>
                    <button type="submit" name="add_prevention" class="btn btn-primary">Add Measure</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Incident Modal -->
    <div id="updateIncidentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Incident Status</h3>
                <button class="modal-close" onclick="closeUpdateIncidentModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="incident_id" id="update_incident_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Status *</label>
                            <select name="status" id="update_status" class="form-control" required>
                                <option value="reported">Reported</option>
                                <option value="under_investigation">Under Investigation</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Action Taken</label>
                            <textarea name="action_taken" id="update_action_taken" class="form-control" placeholder="Describe actions taken to address this incident..." rows="3"></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-full">
                            <label class="form-label">Resolution Notes</label>
                            <textarea name="resolution_notes" id="update_resolution_notes" class="form-control" placeholder="Additional notes about resolution..." rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUpdateIncidentModal()">Cancel</button>
                    <button type="submit" name="update_incident" class="btn btn-primary">Update Incident</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Incident Modal -->
    <div id="viewIncidentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Incident Details</h3>
                <button class="modal-close" onclick="closeViewIncidentModal()">&times;</button>
            </div>
            <div class="modal-body" id="incidentDetails">
                <!-- Incident details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewIncidentModal()">Close</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        // Tab Functions
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.content-section').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
            
            // If showing reports tab, initialize charts
            if (tabName === 'reports') {
                initializeCharts();
            }
        }

        // Modal Functions
        function openReportIncidentModal() {
            document.getElementById('reportIncidentModal').style.display = 'flex';
        }

        function closeReportIncidentModal() {
            document.getElementById('reportIncidentModal').style.display = 'none';
        }

        function openAddPreventionModal() {
            document.getElementById('addPreventionModal').style.display = 'flex';
        }

        function closeAddPreventionModal() {
            document.getElementById('addPreventionModal').style.display = 'none';
        }

        function openUpdateIncidentModal(incidentId) {
            // In a real implementation, you would fetch incident data via AJAX
            document.getElementById('update_incident_id').value = incidentId;
            document.getElementById('updateIncidentModal').style.display = 'flex';
        }

        function closeUpdateIncidentModal() {
            document.getElementById('updateIncidentModal').style.display = 'none';
        }

        function openViewIncidentModal() {
            document.getElementById('viewIncidentModal').style.display = 'flex';
        }

        function closeViewIncidentModal() {
            document.getElementById('viewIncidentModal').style.display = 'none';
        }

    function viewIncident(incidentId) {
    // Show loading state
    document.getElementById('incidentDetails').innerHTML = `
        <div style="text-align: center; padding: 2rem;">
            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary-green);"></i>
            <p>Loading incident details...</p>
        </div>
    `;
    
    // Fetch real incident data
    fetch(`security.php?action=get_incident_details&incident_id=${incidentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const incident = data.data;
                document.getElementById('incidentDetails').innerHTML = `
                    <div class="incident-details">
                        <div class="detail-row">
                            <div class="detail-label">Incident ID:</div>
                            <div class="detail-value">${incident.id}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Title:</div>
                            <div class="detail-value">${escapeHtml(incident.title)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Description:</div>
                            <div class="detail-value">${escapeHtml(incident.description)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Type:</div>
                            <div class="detail-value">${formatIncidentType(incident.incident_type)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value"><span class="status-badge status-${incident.status}">${formatStatus(incident.status)}</span></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Severity:</div>
                            <div class="detail-value"><span class="severity-badge severity-${incident.severity}">${formatSeverity(incident.severity)}</span></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Location:</div>
                            <div class="detail-value">${escapeHtml(incident.location)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Reported By:</div>
                            <div class="detail-value">${escapeHtml(incident.reported_by)} ${incident.reporter_user_name ? `(${escapeHtml(incident.reporter_user_name)})` : ''}</div>
                        </div>
                        ${incident.reporter_contact ? `
                        <div class="detail-row">
                            <div class="detail-label">Contact:</div>
                            <div class="detail-value">${escapeHtml(incident.reporter_contact)}</div>
                        </div>
                        ` : ''}
                        <div class="detail-row">
                            <div class="detail-label">Date Reported:</div>
                            <div class="detail-value">${formatDateTime(incident.created_at)}</div>
                        </div>
                        ${incident.resolved_at ? `
                        <div class="detail-row">
                            <div class="detail-label">Date Resolved:</div>
                            <div class="detail-value">${formatDateTime(incident.resolved_at)}</div>
                        </div>
                        ` : ''}
                        ${incident.resolver_name ? `
                        <div class="detail-row">
                            <div class="detail-label">Resolved By:</div>
                            <div class="detail-value">${escapeHtml(incident.resolver_name)}</div>
                        </div>
                        ` : ''}
                        ${incident.action_taken ? `
                        <div class="detail-row">
                            <div class="detail-label">Action Taken:</div>
                            <div class="detail-value">${escapeHtml(incident.action_taken)}</div>
                        </div>
                        ` : ''}
                        ${incident.resolution_notes ? `
                        <div class="detail-row">
                            <div class="detail-label">Resolution Notes:</div>
                            <div class="detail-value">${escapeHtml(incident.resolution_notes)}</div>
                        </div>
                        ` : ''}
                    </div>
                `;
            } else {
                document.getElementById('incidentDetails').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> ${data.message || 'Error loading incident details'}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('incidentDetails').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> Error loading incident details
                </div>
            `;
        });
    
    openViewIncidentModal();
}

function updateIncident(incidentId) {
    // Show loading state
    document.getElementById('updateIncidentModal').querySelector('.modal-body').innerHTML = `
        <div style="text-align: center; padding: 2rem;">
            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary-green);"></i>
            <p>Loading incident data...</p>
        </div>
    `;
    
    // Fetch incident data for the update form
    fetch(`security.php?action=get_incident_update_form&incident_id=${incidentId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('updateIncidentModal').querySelector('.modal-body').innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('updateIncidentModal').querySelector('.modal-body').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> Error loading incident data
                </div>
            `;
        });
    
    openUpdateIncidentModal();
}

// Helper functions
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatIncidentType(type) {
    return type ? type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : '';
}

function formatStatus(status) {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : '';
}

function formatSeverity(severity) {
    return severity ? severity.charAt(0).toUpperCase() + severity.slice(1) : '';
}

function formatDateTime(dateTimeString) {
    if (!dateTimeString) return 'N/A';
    const date = new Date(dateTimeString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}


        function updateIncident(incidentId) {
            openUpdateIncidentModal(incidentId);
        }

        function deleteIncident(incidentId) {
            if (confirm('Are you sure you want to delete this security incident?')) {
                window.location.href = 'security.php?delete_incident=' + incidentId;
            }
        }

        function viewPrevention(measureId) {
            alert('View prevention measure details for ID: ' + measureId);
            // Implement view functionality
        }

        function updatePrevention(measureId) {
            alert('Update prevention measure with ID: ' + measureId);
            // Implement update functionality
        }

        function deletePrevention(measureId) {
            if (confirm('Are you sure you want to delete this prevention measure?')) {
                window.location.href = 'security.php?delete_prevention=' + measureId;
            }
        }

        function generateReport(type = 'general') {
            alert('Generating ' + type + ' security report...');
            // In a real implementation, this would generate and download a report
        }

        function exportIncidents(format) {
            alert('Exporting incidents as ' + format.toUpperCase() + '...');
            // In a real implementation, this would export the data
        }

        // Initialize Charts
        function initializeCharts() {
            // Status Chart
            const statusCtx = document.getElementById('statusChartCanvas').getContext('2d');
            const statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Reported', 'Under Investigation', 'Resolved', 'Closed'],
                    datasets: [{
                        data: [<?php echo $reported_count; ?>, <?php echo $investigation_count; ?>, 
                               <?php 
                               $resolved_count = 0;
                               $closed_count = 0;
                               foreach ($status_counts as $status) {
                                   if ($status['status'] === 'resolved') $resolved_count = $status['count'];
                                   if ($status['status'] === 'closed') $closed_count = $status['count'];
                               }
                               echo $resolved_count . ', ' . $closed_count;
                               ?>],
                        backgroundColor: [
                            '#ffc107',
                            '#17a2b8',
                            '#28a745',
                            '#6c757d'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Severity Chart
            const severityCtx = document.getElementById('severityChartCanvas').getContext('2d');
            const severityChart = new Chart(severityCtx, {
                type: 'bar',
                data: {
                    labels: ['Low', 'Medium', 'High', 'Critical'],
                    datasets: [{
                        label: 'Incidents by Severity',
                        data: [
                            <?php 
                            $low_count = 0;
                            $medium_count = 0;
                            $high_count = 0;
                            $critical_count = 0;
                            foreach ($severity_counts as $severity) {
                                switch ($severity['severity']) {
                                    case 'low': $low_count = $severity['count']; break;
                                    case 'medium': $medium_count = $severity['count']; break;
                                    case 'high': $high_count = $severity['count']; break;
                                    case 'critical': $critical_count = $severity['count']; break;
                                }
                            }
                            echo $low_count . ', ' . $medium_count . ', ' . $high_count . ', ' . $critical_count;
                            ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#ffc107',
                            '#fd7e14',
                            '#dc3545'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['reportIncidentModal', 'addPreventionModal', 'updateIncidentModal', 'viewIncidentModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'reportIncidentModal') closeReportIncidentModal();
                    if (modalId === 'addPreventionModal') closeAddPreventionModal();
                    if (modalId === 'updateIncidentModal') closeUpdateIncidentModal();
                    if (modalId === 'viewIncidentModal') closeViewIncidentModal();
                }
            });
        }

        // Auto-refresh security data every 5 minutes
        setInterval(() => {
            console.log('Security data auto-refresh triggered');
        }, 300000);

        // Initialize charts if we're on the reports tab
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reports-tab').classList.contains('active')) {
                initializeCharts();
            }
        });
    </script>
</body>
</html>