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
    header('Location: class_rep_dashboard.php');
    exit();
}

// Get class representative statistics
// Total students in the same program and year
$total_students_stmt = $pdo->prepare("
    SELECT COUNT(*) as total_students 
    FROM users 
    WHERE program_id = (SELECT program_id FROM users WHERE id = ?) 
    AND academic_year = (SELECT academic_year FROM users WHERE id = ?)
    AND role = 'student' AND status = 'active'
");
$total_students_stmt->execute([$student_id, $student_id]);
$total_students = $total_students_stmt->fetch(PDO::FETCH_ASSOC)['total_students'];

// Tickets from students in same program and year - FIXED QUERY
$class_tickets_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
        SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets
    FROM tickets t
    JOIN users u ON t.reg_number = u.reg_number
    WHERE u.program_id = (SELECT program_id FROM users WHERE id = ?)
    AND u.academic_year = (SELECT academic_year FROM users WHERE id = ?)
    AND u.role = 'student'
");
$class_tickets_stmt->execute([$student_id, $student_id]);
$class_ticket_stats = $class_tickets_stmt->fetch(PDO::FETCH_ASSOC);

// Recent tickets from class students - FIXED QUERY
$recent_class_tickets_stmt = $pdo->prepare("
    SELECT t.*, u.full_name, ic.name as category_name, t.status as ticket_status
    FROM tickets t
    JOIN users u ON t.reg_number = u.reg_number
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    WHERE u.program_id = (SELECT program_id FROM users WHERE id = ?)
    AND u.academic_year = (SELECT academic_year FROM users WHERE id = ?)
    AND u.role = 'student'
    ORDER BY t.created_at DESC
    LIMIT 10
");
$recent_class_tickets_stmt->execute([$student_id, $student_id]);
$recent_class_tickets = $recent_class_tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get representative board members
$board_members_stmt = $pdo->prepare("
    SELECT cm.*, d.name as department_name, p.name as program_name
    FROM committee_members cm
    LEFT JOIN departments d ON cm.department_id = d.id
    LEFT JOIN programs p ON cm.program_id = p.id
    WHERE cm.role IN ('president_representative_board', 'vice_president_representative_board', 'secretary_representative_board')
    AND cm.status = 'active'
    ORDER BY cm.role_order
");
$board_members_stmt->execute();
$board_members = $board_members_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Class Representative Panel - Isonga RPSU</title>
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
        .ticket-student { font-size: 0.9rem; color: var(--text); font-weight: 500; }
        .status { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .status-open { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-progress { background: rgba(255,193,7,0.1); color: var(--warning); }
        .status-resolved { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        
        /* Board Members */
        .board-member { display: flex; align-items: center; gap: 1rem; padding: 1rem; border-bottom: 1px solid var(--gray); }
        .board-member:last-child { border-bottom: none; }
        .member-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; }
        .member-info h4 { margin-bottom: 0.2rem; }
        .member-role { font-size: 0.8rem; color: var(--dark-gray); }
        .member-contact { font-size: 0.8rem; color: var(--secondary); }
        
        /* Alert */
        .alert { padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; }
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
                <li><a href="#" class="active"><i class="fas fa-tachometer-alt"></i> Class Rep Dashboard</a></li>
                <li><a href="class_tickets.php"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_rep_financial_aid.php"><i class="fas fa-hand-holding-usd"></i> Financial Aid</a></li>
                <li><a href="class_students.php"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="rep_meetings.php"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main">
            <!-- Header -->
            <div class="header">
                <div class="welcome">
                    <h1>Class Representative Panel
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p><?php echo safe_display($student_name); ?> | <?php echo safe_display($program); ?> | <?php echo safe_display($academic_year); ?></p>
                </div>
                <div class="actions">
                    <form method="POST">
                        <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Class Representative Access:</strong> You have special access to view and manage issues from students in your class.
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Students in Class</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-ticket-alt"></i></div>
                    <div class="stat-number"><?php echo $class_ticket_stats['total_tickets'] ?? 0; ?></div>
                    <div class="stat-label">Class Tickets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo $class_ticket_stats['open_tickets'] ?? 0; ?></div>
                    <div class="stat-label">Open Tickets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-spinner"></i></div>
                    <div class="stat-number"><?php echo $class_ticket_stats['in_progress_tickets'] ?? 0; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Recent Class Tickets -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Class Tickets</h3>
                        <a href="class_tickets.php" class="link">View All</a>
                    </div>
                    <?php if (empty($recent_class_tickets)): ?>
                        <p style="text-align: center; color: var(--dark-gray); padding: 2rem;">No tickets from class students yet</p>
                    <?php else: ?>
                        <?php foreach ($recent_class_tickets as $ticket): ?>
                            <div class="ticket-item">
                                <div class="ticket-info" style="flex: 1;">
                                    <h4><?php echo safe_display($ticket['subject']); ?></h4>
                                    <div class="ticket-student">By: <?php echo safe_display($ticket['full_name']); ?></div>
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

                <!-- Representative Board -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Representative Board</h3>
                    </div>
                    <?php if (empty($board_members)): ?>
                        <p style="text-align: center; color: var(--dark-gray); padding: 2rem;">No board members found</p>
                    <?php else: ?>
                        <?php foreach ($board_members as $member): ?>
                            <div class="board-member">
                                <div class="member-avatar">
                                    <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                </div>
                                <div class="member-info" style="flex: 1;">
                                    <h4><?php echo safe_display($member['name']); ?></h4>
                                    <div class="member-role">
                                        <?php 
                                        $role_map = [
                                            'president_representative_board' => 'President - Representative Board',
                                            'vice_president_representative_board' => 'Vice President - Representative Board',
                                            'secretary_representative_board' => 'Secretary - Representative Board'
                                        ];
                                        echo $role_map[$member['role']] ?? ucfirst(str_replace('_', ' ', $member['role']));
                                        ?>
                                    </div>
                                    <?php if ($member['email']): ?>
                                        <div class="member-contact"><?php echo safe_display($member['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Quick Actions</h3>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <a href="class_tickets.php" class="btn btn-primary"><i class="fas fa-ticket-alt"></i> View Class Tickets</a>
                    <a href="class_students.php" class="btn btn-success"><i class="fas fa-users"></i> Class Students</a>
                    <a href="rep_meetings.php" class="btn btn-secondary"><i class="fas fa-calendar-alt"></i> Meetings</a>
                    <a href="rep_reports.php" class="btn btn-secondary"><i class="fas fa-file-alt"></i> Submit Report</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add any JavaScript functionality needed for the class rep dashboard
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Class Representative Dashboard loaded');
        });
    </script>
</body>
</html>