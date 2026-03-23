<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: class_tickets.php');
    exit();
}

// Handle ticket submission (class rep submitting their own ticket)
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
            // Insert the ticket
            $stmt = $pdo->prepare("
                INSERT INTO tickets (reg_number, name, email, phone, department_id, program_id, academic_year, category_id, subject, description, priority, preferred_contact, status)
                SELECT u.reg_number, u.full_name, u.email, u.phone, u.department_id, u.program_id, u.academic_year, ?, ?, ?, ?, ?, 'open'
                FROM users u 
                WHERE u.id = ?
            ");
            
            $stmt->execute([$category_id, $subject, $description, $priority, $preferred_contact, $student_id]);
            
            $ticket_id = $pdo->lastInsertId();
            
            // ========== CORRECTED AUTO-ASSIGNMENT LOGIC ==========
            // First, find the user to assign based on the issue category
            $findAssigneeStmt = $pdo->prepare("
                SELECT u.id 
                FROM issue_categories ic
                JOIN users u ON ic.auto_assign_role = u.role
                WHERE ic.id = ? 
                AND u.status = 'active'
                ORDER BY u.id
                LIMIT 1
            ");
            
            $findAssigneeStmt->execute([$category_id]);
            $assigned_to = $findAssigneeStmt->fetchColumn();
            
            if ($assigned_to) {
                // Update the ticket with the assigned user
                $updateTicketStmt = $pdo->prepare("
                    UPDATE tickets 
                    SET assigned_to = ?
                    WHERE id = ?
                ");
                $updateTicketStmt->execute([$assigned_to, $ticket_id]);
                
                // Create assignment record
                $assignStmt = $pdo->prepare("
                    INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                    VALUES (?, ?, ?, NOW(), 'Auto-assigned based on issue category')
                ");
                
                $assignStmt->execute([$ticket_id, $assigned_to, $student_id]);
                
                $_SESSION['success_message'] = "Your ticket has been submitted successfully! Ticket ID: #$ticket_id and has been auto-assigned.";
            } else {
                // If no assignee found, just create the ticket without assignment
                $_SESSION['success_message'] = "Your ticket has been submitted successfully! Ticket ID: #$ticket_id. It will be assigned shortly.";
            }
            
            header('Location: class_tickets.php');
            exit();
            
        } catch (PDOException $e) {
            $error_message = "Failed to submit ticket. Please try again. Error: " . $e->getMessage();
        }
    }
}

// Get class ticket statistics (aggregated data only - no individual ticket details)
$class_stats_stmt = $pdo->prepare("
    SELECT 
        ic.name as category_name,
        COUNT(*) as total_tickets,
        SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
        SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets
    FROM tickets t
    JOIN users u ON t.reg_number = u.reg_number
    JOIN issue_categories ic ON t.category_id = ic.id
    WHERE u.program_id = (SELECT program_id FROM users WHERE id = ?)
    AND u.academic_year = (SELECT academic_year FROM users WHERE id = ?)
    AND u.role = 'student'
    GROUP BY ic.id, ic.name
    ORDER BY total_tickets DESC
");
$class_stats_stmt->execute([$student_id, $student_id]);
$class_ticket_stats = $class_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get overall class ticket summary
$overall_stats_stmt = $pdo->prepare("
    SELECT 
    COUNT(*) as total_tickets,
    SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open_tickets,
    SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
    SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
    AVG(EXTRACT(EPOCH FROM (COALESCE(t.updated_at, CURRENT_TIMESTAMP) - t.created_at)) / 3600) as avg_resolution_hours
FROM tickets t
JOIN users u ON t.reg_number = u.reg_number
WHERE u.program_id = (SELECT program_id FROM users WHERE id = ?)
AND u.academic_year = (SELECT academic_year FROM users WHERE id = ?)
AND u.role = 'student'
");
$overall_stats_stmt->execute([$student_id, $student_id]);
$overall_stats = $overall_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get class rep's own tickets
$my_tickets_stmt = $pdo->prepare("
    SELECT t.*, ic.name as category_name, t.status as ticket_status,
           u.full_name as assigned_to_name
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.reg_number = ?
    ORDER BY t.created_at DESC
    LIMIT 10
");
$my_tickets_stmt->execute([$reg_number]);
$my_tickets = $my_tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get issue categories for ticket submission
$categories_stmt = $pdo->prepare("SELECT * FROM issue_categories WHERE id != 10 ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

// Format resolution time
function formatResolutionTime($hours) {
    if ($hours < 1) return "Less than 1 hour";
    if ($hours < 24) return round($hours) . " hours";
    return round($hours / 24) . " days";
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Tickets - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0056b3; --secondary: #1e88e5; --accent: #0d47a1;
            --light: #f8f9fa; --white: #fff; --gray: #e9ecef; 
            --dark-gray: #6c757d; --text: #2c3e50; --success: #28a745;
            --warning: #ffc107; --danger: #dc3545; --info: #17a2b8;
            --shadow: 0 4px 12px rgba(0,0,0,0.1); --radius: 8px; 
            --transition: all 0.3s ease;
        }
        [data-theme="dark"] {
            --white: #1a1a1a; --light: #2d2d2d; --gray: #3d3d3d;
            --dark-gray: #a0a0a0; --text: #e9ecef;
            --shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); transition: var(--transition); }
        .container { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 250px; height: 100vh; z-index: 1000; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.5rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.8rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        
        /* Main Content */
        .main { grid-column: 2; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; background: var(--white); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .actions { display: flex; gap: 1rem; }
        .btn { padding: 0.8rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        .stat-label { font-size: 0.9rem; color: var(--dark-gray); }
        
        /* Dashboard Grid */
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        .card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-title { font-size: 1.2rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.9rem; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 1rem; }
        
        /* Tickets */
        .ticket-item { display: flex; justify-content: space-between; align-items: start; padding: 1rem; border-bottom: 1px solid var(--gray); }
        .ticket-item:last-child { border-bottom: none; }
        .ticket-info h4 { margin-bottom: 0.3rem; }
        .ticket-meta { display: flex; gap: 1rem; font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0.5rem; }
        .status { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .status-open { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-progress { background: rgba(255,193,7,0.1); color: var(--warning); }
        .status-resolved { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        
        /* Statistics Table */
        .stats-table { width: 100%; border-collapse: collapse; }
        .stats-table th, .stats-table td { padding: 0.8rem; text-align: left; border-bottom: 1px solid var(--gray); }
        .stats-table th { background: var(--light); font-weight: 600; }
        .stats-table tr:hover { background: var(--light); }
        
        /* Progress Bars */
        .progress-bar { background: var(--gray); border-radius: 10px; height: 8px; overflow: hidden; margin: 0.5rem 0; }
        .progress-fill { height: 100%; border-radius: 10px; }
        .progress-open { background: var(--success); }
        .progress-progress { background: var(--warning); }
        .progress-resolved { background: var(--dark-gray); }
        
        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: var(--white); border-radius: var(--radius); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--gray); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        .form-control { width: 100%; padding: 0.8rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); }
        .form-control:focus { outline: none; border-color: var(--secondary); }
        textarea.form-control { min-height: 100px; resize: vertical; }
        
        /* Alert */
        .alert { padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; }
        .alert-success { background: rgba(40,167,69,0.1); color: var(--success); border-left-color: var(--success); }
        .alert-error { background: rgba(220,53,69,0.1); color: var(--danger); border-left-color: var(--danger); }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="brand">
                <div class="brand-logo"><i class="fas fa-user-shield"></i></div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></li>
                <li><a href="#" class="active"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_students.php"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="rep_meetings.php"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main">
            <!-- Header -->
<!-- Header -->
<div class="header">
    <div class="welcome">
        <h1>Class Tickets Overview
            <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
        </h1>
        <p>Statistics and ticket management for <?php echo safe_display($program); ?> - <?php echo safe_display($academic_year); ?></p>
    </div>
    <div class="actions">
        <form method="POST">
            <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
            </button>
        </form>
        <a href="my_tickets.php" class="btn btn-success">
            <i class="fas fa-list"></i> View All My Tickets
        </a>
        <button class="btn btn-primary" onclick="openTicketModal()">
            <i class="fas fa-plus"></i> Submit Ticket
        </button>
    </div>
</div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Privacy Notice:</strong> As a Class Representative, you can view aggregated statistics about class tickets but cannot see individual ticket details submitted by other students to protect their privacy.
            </div>

            <!-- Overall Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-ticket-alt"></i></div>
                    <div class="stat-number"><?php echo $overall_stats['total_tickets'] ?? 0; ?></div>
                    <div class="stat-label">Total Class Tickets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo $overall_stats['open_tickets'] ?? 0; ?></div>
                    <div class="stat-label">Open Tickets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-spinner"></i></div>
                    <div class="stat-number"><?php echo $overall_stats['in_progress_tickets'] ?? 0; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(108,117,125,0.1); color: var(--dark-gray);"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo $overall_stats['resolved_tickets'] ?? 0; ?></div>
                    <div class="stat-label">Resolved</div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Class Tickets by Category -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Tickets by Category</h3>
                    </div>
                    <?php if (empty($class_ticket_stats)): ?>
                        <p style="text-align: center; color: var(--dark-gray); padding: 2rem;">No ticket data available</p>
                    <?php else: ?>
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($class_ticket_stats as $stat): ?>
                                    <tr>
                                        <td><?php echo safe_display($stat['category_name']); ?></td>
                                        <td><strong><?php echo $stat['total_tickets']; ?></strong></td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <span style="color: var(--success);"><?php echo $stat['open_tickets']; ?> open</span> • 
                                                <span style="color: var(--warning);"><?php echo $stat['in_progress_tickets']; ?> in progress</span> • 
                                                <span style="color: var(--dark-gray);"><?php echo $stat['resolved_tickets']; ?> resolved</span>
                                            </div>
                                            <div class="progress-bar">
                                                <?php if ($stat['total_tickets'] > 0): ?>
                                                    <div class="progress-fill progress-open" style="width: <?php echo ($stat['open_tickets'] / $stat['total_tickets']) * 100; ?>%"></div>
                                                    <div class="progress-fill progress-progress" style="width: <?php echo ($stat['in_progress_tickets'] / $stat['total_tickets']) * 100; ?>%"></div>
                                                    <div class="progress-fill progress-resolved" style="width: <?php echo ($stat['resolved_tickets'] / $stat['total_tickets']) * 100; ?>%"></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- My Personal Tickets -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">My Tickets</h3>
                        <a href="my_tickets.php" class="link">View All</a>
                    </div>
                    <?php if (empty($my_tickets)): ?>
                        <p style="text-align: center; color: var(--dark-gray); padding: 2rem;">You haven't submitted any tickets yet</p>
                    <?php else: ?>
                        <?php foreach ($my_tickets as $ticket): ?>
                            <div class="ticket-item">
                                <div class="ticket-info" style="flex: 1;">
                                    <h4><?php echo safe_display($ticket['subject']); ?></h4>
                                    <div class="ticket-meta">
                                        <span><?php echo safe_display($ticket['category_name']); ?></span>
                                        <span><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="status status-<?php echo str_replace('_', '-', $ticket['ticket_status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Metrics -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Class Performance Metrics</h3>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div style="text-align: center;">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--secondary);">
                            <?php echo $overall_stats['total_tickets'] > 0 ? round(($overall_stats['resolved_tickets'] / $overall_stats['total_tickets']) * 100, 1) : 0; ?>%
                        </div>
                        <div style="color: var(--dark-gray);">Resolution Rate</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--success);">
                            <?php echo formatResolutionTime($overall_stats['avg_resolution_hours'] ?? 0); ?>
                        </div>
                        <div style="color: var(--dark-gray);">Avg. Resolution Time</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--info);">
                            <?php echo $class_ticket_stats ? count($class_ticket_stats) : 0; ?>
                        </div>
                        <div style="color: var(--dark-gray);">Active Categories</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ticket Modal -->
    <div id="ticketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h3>Submit Your Ticket</h3><button onclick="closeTicketModal()">&times;</button></div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo safe_display($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Subject</label><input type="text" name="subject" class="form-control" placeholder="Brief description of your issue" required></div>
                    <div class="form-group"><label>Description</label><textarea name="description" class="form-control" placeholder="Please provide detailed information about your issue..." required></textarea></div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Preferred Contact Method</label>
                        <select name="preferred_contact" class="form-control" required>
                            <option value="email" selected>Email</option><option value="sms">SMS</option><option value="phone">Phone</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeTicketModal()">Cancel</button>
                        <button type="submit" name="submit_ticket" class="btn btn-primary" style="flex: 1;">Submit Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openTicketModal() { 
            document.getElementById('ticketModal').style.display = 'flex'; 
        }
        function closeTicketModal() { 
            document.getElementById('ticketModal').style.display = 'none'; 
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('ticketModal');
            if (event.target === modal) {
                closeTicketModal();
            }
        }

        <?php if (isset($error_message)): ?>
            document.addEventListener('DOMContentLoaded', openTicketModal);
        <?php endif; ?>
    </script>
</body>
</html>