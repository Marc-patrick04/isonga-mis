<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    header('Location: student_login');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];


// Handle viewing ticket details with comments
$selected_ticket_id = $_GET['ticket_id'] ?? null;
$selected_ticket = null;
$ticket_comments = [];

if ($selected_ticket_id) {
    // Get specific ticket details
    $ticket_stmt = $pdo->prepare("
        SELECT 
            t.*, 
            ic.name as category_name, 
            t.status as ticket_status,
            cm.name as assigned_to_name,
            cm.role as assigned_to_role,
            ta.assigned_at,
            ta.reason as assignment_reason,
            u.full_name as student_name
        FROM tickets t
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN ticket_assignments ta ON t.id = ta.ticket_id
        LEFT JOIN committee_members cm ON ta.assigned_to = cm.user_id
        LEFT JOIN users u ON t.reg_number = u.reg_number
        WHERE t.id = ? AND t.reg_number = ?
    ");
    $ticket_stmt->execute([$selected_ticket_id, $reg_number]);
    $selected_ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_ticket) {
        // Get all comments for this ticket (both internal and external)
        $comments_stmt = $pdo->prepare("
            SELECT 
                tc.*,
                COALESCE(cm.name, u.full_name) as commenter_name,
                COALESCE(cm.role, 'Student') as commenter_role,
                CASE 
                    WHEN cm.id IS NOT NULL THEN 'committee'
                    WHEN u.role = 'student' THEN 'student'
                    ELSE 'other'
                END as commenter_type
            FROM ticket_comments tc
            LEFT JOIN committee_members cm ON tc.user_id = cm.user_id
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ");
        $comments_stmt->execute([$selected_ticket_id]);
        $ticket_comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get all tickets submitted by the class rep
$tickets_stmt = $pdo->prepare("
    SELECT 
        t.*, 
        ic.name as category_name, 
        t.status as ticket_status,
        cm.name as assigned_to_name,
        cm.role as assigned_to_role,
        ta.assigned_at,
        ta.reason as assignment_reason,
        (SELECT COUNT(*) FROM ticket_comments tc WHERE tc.ticket_id = t.id) as comment_count
    FROM tickets t
    LEFT JOIN issue_categories ic ON t.category_id = ic.id
    LEFT JOIN ticket_assignments ta ON t.id = ta.ticket_id
    LEFT JOIN committee_members cm ON ta.assigned_to = cm.user_id
    WHERE t.reg_number = ?
    ORDER BY t.created_at DESC
");
$tickets_stmt->execute([$reg_number]);
$all_tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ticket statistics for the class rep
$ticket_stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
    FROM tickets 
    WHERE reg_number = ?
");
$ticket_stats_stmt->execute([$reg_number]);
$ticket_stats = $ticket_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

// Format date with time
function formatDateTime($date) {
    return $date ? date('M j, Y g:i A', strtotime($date)) : 'Not updated';
}

// Get status badge class
function getStatusBadge($status) {
    switch ($status) {
        case 'open': return 'status-open';
        case 'in_progress': return 'status-progress';
        case 'resolved': return 'status-resolved';
        default: return 'status-open';
    }
}

// Get commenter badge class
function getCommenterBadge($commenter_type, $commenter_role) {
    if ($commenter_type === 'committee') {
        return 'commenter-committee';
    } elseif ($commenter_type === 'student') {
        return 'commenter-student';
    } else {
        return 'commenter-other';
    }
}

// Format commenter role for display
function formatCommenterRole($commenter_role, $commenter_type) {
    if ($commenter_type === 'committee') {
        return ucfirst(str_replace('_', ' ', $commenter_role));
    } elseif ($commenter_type === 'student') {
        return 'Student';
    } else {
        return 'User';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tickets - Isonga RPSU</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); transition: var(--transition); font-size: 0.875rem; }
        .container { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 260px; height: 100vh; z-index: 1000; overflow-y: auto; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.25rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.75rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); font-size: 0.85rem; }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        .nav-links i { width: 20px; text-align: center; }
        
        /* Main Content */
        .main { grid-column: 2; padding: 1.5rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; background: var(--white); padding: 1.25rem 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.3rem; margin-bottom: 0.25rem; }
        .welcome p { font-size: 0.85rem; color: var(--dark-gray); }
        .actions { display: flex; gap: 1rem; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.85rem; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .btn-info { background: var(--info); color: white; }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); }
        .icon-btn:hover { background: var(--gray); }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.8rem; color: var(--dark-gray); }
        
        /* Cards */
        .card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .card-title { font-size: 1rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.8rem; }
        .link:hover { text-decoration: underline; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 0.75rem; }
        
        /* Tickets List */
        .ticket-list { display: grid; gap: 1rem; }
        .ticket-card { background: var(--light); border-radius: var(--radius); padding: 1.25rem; transition: var(--transition); border-left: 4px solid var(--secondary); }
        .ticket-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
        .ticket-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; }
        .ticket-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .ticket-meta { display: flex; gap: 1rem; font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 1rem; flex-wrap: wrap; }
        .ticket-description { margin-bottom: 1rem; line-height: 1.5; }
        .ticket-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; background: var(--white); padding: 1rem; border-radius: var(--radius); }
        .detail-item { display: flex; flex-direction: column; }
        .detail-label { font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0.3rem; }
        .detail-value { font-weight: 600; }
        
        /* Status */
        .status { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-open { background: rgba(40,167,69,0.1); color: var(--success); }
        .status-progress { background: rgba(255,193,7,0.1); color: var(--warning); }
        .status-resolved { background: rgba(108,117,125,0.1); color: var(--dark-gray); }
        
        /* Assignment Info */
        .assignment-info { background: rgba(30,136,229,0.1); padding: 1rem; border-radius: var(--radius); margin-top: 1rem; }
        .assignment-title { font-weight: 600; margin-bottom: 0.5rem; color: var(--secondary); }
        
        /* Comments Section */
        .comments-section { margin-top: 2rem; }
        .comments-header { display: flex; justify-content: between; align-items: center; margin-bottom: 1rem; }
        .comment-list { display: grid; gap: 1rem; }
        .comment-card { background: var(--light); border-radius: var(--radius); padding: 1rem; border-left: 3px solid; }
        .commenter-committee { border-left-color: var(--info); }
        .commenter-student { border-left-color: var(--success); }
        .commenter-other { border-left-color: var(--warning); }
        .comment-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .commenter-info { display: flex; align-items: center; gap: 0.5rem; }
        .commenter-badge { padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .commenter-committee .commenter-badge { background: rgba(23,162,184,0.2); color: var(--info); }
        .commenter-student .commenter-badge { background: rgba(40,167,69,0.2); color: var(--success); }
        .commenter-other .commenter-badge { background: rgba(255,193,7,0.2); color: var(--warning); }
        .comment-time { font-size: 0.8rem; color: var(--dark-gray); }
        .comment-content { line-height: 1.5; }
        .internal-note { background: rgba(255,193,7,0.1); border: 1px dashed var(--warning); padding: 0.5rem; border-radius: var(--radius); margin-top: 0.5rem; font-size: 0.8rem; color: var(--warning); }
        
        /* Ticket Detail View */
        .ticket-detail-view { background: var(--white); border-radius: var(--radius); padding: 2rem; box-shadow: var(--shadow); }
        .back-button { margin-bottom: 1rem; }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 3rem; color: var(--dark-gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; color: var(--gray); }
        
        /* Comment Count Badge */
        .comment-count { background: var(--info); color: white; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.65rem; font-weight: 600; margin-left: 0.5rem; }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .ticket-details { grid-template-columns: 1fr 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .ticket-header { flex-direction: column; gap: 1rem; }
            .ticket-meta { flex-direction: column; gap: 0.5rem; }
            .ticket-details { grid-template-columns: 1fr; }
            .comment-header { flex-direction: column; align-items: start; gap: 0.5rem; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="brand">
                <div class="brand-logo"><i class="fas fa-user-shield"></i></div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard.php"><i class="fas fa-tachometer-alt"></i> Class Rep Dashboard</a></li>
                <li><a href="my_tickets.php" class="active"><i class="fas fa-ticket-alt"></i> My Tickets</a></li>
                <li><a href="class_tickets.php"><i class="fas fa-users"></i> Class Tickets</a></li>
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
                    <h1>My Submitted Tickets
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p>Track all tickets you've submitted and their current status</p>
                </div>
                <div class="actions">
                    <button class="icon-btn" id="mobileMenuToggle" title="Menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <a href="class_tickets.php" class="btn btn-secondary">
                        <i class="fas fa-chart-bar"></i> Class Statistics
                    </a>
                </div>
            </div>

            <?php if ($selected_ticket): ?>
                <!-- Ticket Detail View -->
                <div class="back-button">
                    <a href="my_tickets.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to All Tickets
                    </a>
                </div>

                <div class="ticket-detail-view">
                    <div class="card-header">
                        <h2 class="card-title">Ticket #<?php echo $selected_ticket['id']; ?>: <?php echo safe_display($selected_ticket['subject']); ?></h2>
                        <div class="status <?php echo getStatusBadge($selected_ticket['ticket_status']); ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $selected_ticket['ticket_status'])); ?>
                        </div>
                    </div>

                    <!-- Ticket Details -->
                    <div class="ticket-details" style="margin-bottom: 2rem;">
                        <div class="detail-item">
                            <span class="detail-label">Category</span>
                            <span class="detail-value"><?php echo safe_display($selected_ticket['category_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Priority</span>
                            <span class="detail-value"><?php echo ucfirst(safe_display($selected_ticket['priority'])); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Submitted</span>
                            <span class="detail-value"><?php echo formatDateTime($selected_ticket['created_at']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Last Updated</span>
                            <span class="detail-value"><?php echo formatDateTime($selected_ticket['updated_at']); ?></span>
                        </div>
                    </div>

                    <!-- Ticket Description -->
                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem;">Description</h3>
                        <div style="background: var(--light); padding: 1.5rem; border-radius: var(--radius);">
                            <?php echo nl2br(safe_display($selected_ticket['description'])); ?>
                        </div>
                    </div>

                    <!-- Assignment Info -->
                    <?php if ($selected_ticket['assigned_to_name']): ?>
                        <div class="assignment-info" style="margin-bottom: 2rem;">
                            <div class="assignment-title">
                                <i class="fas fa-user-check"></i> Assigned To
                            </div>
                            <div><strong><?php echo safe_display($selected_ticket['assigned_to_name']); ?></strong> - <?php echo safe_display($selected_ticket['assigned_to_role']); ?></div>
                            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.3rem;">
                                Assigned on: <?php echo formatDateTime($selected_ticket['assigned_at']); ?>
                                <?php if ($selected_ticket['assignment_reason']): ?>
                                    <br>Reason: <?php echo safe_display($selected_ticket['assignment_reason']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Resolution Notes -->
                    <?php if ($selected_ticket['resolution_notes']): ?>
                        <div style="background: rgba(40,167,69,0.1); padding: 1.5rem; border-radius: var(--radius); margin-bottom: 2rem;">
                            <h3 style="margin-bottom: 1rem; color: var(--success);">
                                <i class="fas fa-check-circle"></i> Resolution Notes
                            </h3>
                            <div><?php echo nl2br(safe_display($selected_ticket['resolution_notes'])); ?></div>
                            <?php if ($selected_ticket['resolved_at']): ?>
                                <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.5rem;">
                                    Resolved on: <?php echo formatDateTime($selected_ticket['resolved_at']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Comments Section -->
                    <div class="comments-section">
                        <div class="comments-header">
                            <h3>Communication History</h3>
                            <span class="link"><?php echo count($ticket_comments); ?> comments</span>
                        </div>

                        <?php if (empty($ticket_comments)): ?>
                            <div class="empty-state" style="padding: 2rem;">
                                <i class="fas fa-comments"></i>
                                <p>No comments yet. Committee members will update you here.</p>
                            </div>
                        <?php else: ?>
                            <div class="comment-list">
                                <?php foreach ($ticket_comments as $comment): ?>
                                    <div class="comment-card <?php echo getCommenterBadge($comment['commenter_type'], $comment['commenter_role']); ?>">
                                        <div class="comment-header">
                                            <div class="commenter-info">
                                                <strong><?php echo safe_display($comment['commenter_name']); ?></strong>
                                                <span class="commenter-badge">
                                                    <?php echo formatCommenterRole($comment['commenter_role'], $comment['commenter_type']); ?>
                                                </span>
                                            </div>
                                            <div class="comment-time">
                                                <?php echo formatDateTime($comment['created_at']); ?>
                                            </div>
                                        </div>
                                        <div class="comment-content">
                                            <?php echo nl2br(safe_display($comment['comment'])); ?>
                                        </div>
                                        <?php if ($comment['is_internal']): ?>
                                            <div class="internal-note">
                                                <i class="fas fa-eye-slash"></i> Internal Note (Visible to committee members only)
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- Ticket List View -->
                <!-- Ticket Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-ticket-alt"></i></div>
                        <div class="stat-number"><?php echo $ticket_stats['total'] ?? 0; ?></div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-clock"></i></div>
                        <div class="stat-number"><?php echo $ticket_stats['open'] ?? 0; ?></div>
                        <div class="stat-label">Open</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-spinner"></i></div>
                        <div class="stat-number"><?php echo $ticket_stats['in_progress'] ?? 0; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: rgba(108,117,125,0.1); color: var(--dark-gray);"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-number"><?php echo $ticket_stats['resolved'] ?? 0; ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>

                <!-- Tickets List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">All My Tickets</h3>
                        <span class="link"><?php echo count($all_tickets); ?> tickets</span>
                    </div>
                    
                    <?php if (empty($all_tickets)): ?>
                        <div class="empty-state">
                            <i class="fas fa-ticket-alt"></i>
                            <h3>No Tickets Yet</h3>
                            <p>You haven't submitted any tickets yet. Submit your first ticket to get started.</p>
                            <a href="class_tickets.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Submit First Ticket
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="ticket-list">
                            <?php foreach ($all_tickets as $ticket): ?>
                                <div class="ticket-card">
                                    <div class="ticket-header">
                                        <div style="flex: 1;">
                                            <div class="ticket-title">
                                                <a href="my_tickets.php?ticket_id=<?php echo $ticket['id']; ?>" style="color: inherit; text-decoration: none;">
                                                    <?php echo safe_display($ticket['subject']); ?>
                                                </a>
                                                <?php if ($ticket['comment_count'] > 0): ?>
                                                    <span class="comment-count"><?php echo $ticket['comment_count']; ?> <i class="fas fa-comment"></i></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ticket-meta">
                                                <span><i class="fas fa-tag"></i> <?php echo safe_display($ticket['category_name']); ?></span>
                                                <span><i class="fas fa-flag"></i> <?php echo ucfirst(safe_display($ticket['priority'])); ?> Priority</span>
                                                <span><i class="fas fa-calendar"></i> Submitted: <?php echo formatDateTime($ticket['created_at']); ?></span>
                                                <?php if ($ticket['updated_at'] && $ticket['updated_at'] != $ticket['created_at']): ?>
                                                    <span><i class="fas fa-sync"></i> Updated: <?php echo formatDateTime($ticket['updated_at']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="status <?php echo getStatusBadge($ticket['ticket_status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="ticket-description">
                                        <?php echo nl2br(safe_display($ticket['description'])); ?>
                                    </div>
                                    
                                    <div class="ticket-details">
                                        <div class="detail-item">
                                            <span class="detail-label">Ticket ID</span>
                                            <span class="detail-value">#<?php echo $ticket['id']; ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Category</span>
                                            <span class="detail-value"><?php echo safe_display($ticket['category_name']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Priority</span>
                                            <span class="detail-value"><?php echo ucfirst(safe_display($ticket['priority'])); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Comments</span>
                                            <span class="detail-value"><?php echo $ticket['comment_count']; ?> responses</span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($ticket['assigned_to_name']): ?>
                                        <div class="assignment-info">
                                            <div class="assignment-title">
                                                <i class="fas fa-user-check"></i> Assigned To
                                            </div>
                                            <div><strong><?php echo safe_display($ticket['assigned_to_name']); ?></strong> - <?php echo safe_display($ticket['assigned_to_role']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.3rem;">
                                                Assigned on: <?php echo formatDateTime($ticket['assigned_at']); ?>
                                                <?php if ($ticket['assignment_reason']): ?>
                                                    <br>Reason: <?php echo safe_display($ticket['assignment_reason']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div style="background: rgba(108,117,125,0.1); padding: 1rem; border-radius: var(--radius); margin-top: 1rem;">
                                            <i class="fas fa-clock"></i> Waiting to be assigned to a committee member
                                        </div>
                                    <?php endif; ?>

                                    <div style="margin-top: 1rem; text-align: right;">
                                        <a href="my_tickets.php?ticket_id=<?php echo $ticket['id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-eye"></i> View Details & Comments
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Mobile Menu Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
            });
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Add loading animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .card');
            cards.forEach((card, index) => {
                card.style.animation = 'fadeInUp 0.4s ease forwards';
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
            });
            
            const style = document.createElement('style');
            style.textContent = `
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
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '1';
                });
            }, 500);
        });
    </script>
</body>
</html>