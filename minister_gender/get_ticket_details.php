<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

if (!isset($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit('Ticket ID required');
}

$ticket_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get ticket details
    $stmt = $pdo->prepare("
        SELECT t.*, 
               ic.name as category_name,
               d.name as department_name,
               p.name as program_name,
               u_assigned.full_name as assigned_to_name,
               u_assigned.role as assigned_to_role,
               u_assigned.email as assigned_to_email
        FROM tickets t
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        WHERE t.id = ? AND t.category_id = 7
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        header('HTTP/1.1 404 Not Found');
        exit('Ticket not found');
    }
    
    // Get ticket comments
    $stmt = $pdo->prepare("
        SELECT tc.*, u.full_name, u.role 
        FROM ticket_comments tc 
        LEFT JOIN users u ON tc.user_id = u.id 
        WHERE tc.ticket_id = ? 
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$ticket_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Database error: ' . $e->getMessage());
}
?>

<div class="ticket-details">
    <div class="detail-section">
        <h4>Student Information</h4>
        <div class="detail-row">
            <div class="detail-label">Name:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['name']); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Registration No:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['reg_number']); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Email:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['email']); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Phone:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['phone']); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Department:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['department_name']); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Program:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['program_name']); ?></div>
        </div>
    </div>
    
    <div class="detail-section">
        <h4>Issue Details</h4>
        <div class="detail-row">
            <div class="detail-label">Category:</div>
            <div class="detail-value"><?php echo htmlspecialchars($ticket['category_name']); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Subject:</div>
            <div class="detail-value"><strong><?php echo htmlspecialchars($ticket['subject']); ?></strong></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Description:</div>
            <div class="detail-value"><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Priority:</div>
            <div class="detail-value">
                <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                    <?php echo ucfirst($ticket['priority']); ?>
                </span>
            </div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Status:</div>
            <div class="detail-value">
                <span class="status-badge status-<?php echo $ticket['status']; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                </span>
            </div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Preferred Contact:</div>
            <div class="detail-value"><?php echo ucfirst($ticket['preferred_contact']); ?></div>
        </div>
    </div>
    
    <div class="detail-section">
        <h4>Assignment & Resolution</h4>
        <div class="detail-row">
            <div class="detail-label">Assigned To:</div>
            <div class="detail-value">
                <?php if ($ticket['assigned_to_name']): ?>
                    <?php echo htmlspecialchars($ticket['assigned_to_name']); ?> (<?php echo htmlspecialchars(str_replace('_', ' ', $ticket['assigned_to_role'])); ?>)
                <?php else: ?>
                    <span style="color: var(--dark-gray); font-style: italic;">Unassigned</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($ticket['resolution_notes']): ?>
        <div class="detail-row">
            <div class="detail-label">Resolution Notes:</div>
            <div class="detail-value"><?php echo nl2br(htmlspecialchars($ticket['resolution_notes'])); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($ticket['resolved_at']): ?>
        <div class="detail-row">
            <div class="detail-label">Resolved At:</div>
            <div class="detail-value"><?php echo date('M j, Y g:i A', strtotime($ticket['resolved_at'])); ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="detail-section">
        <h4>Comments (<?php echo count($comments); ?>)</h4>
        <div class="comments-section">
            <?php if (empty($comments)): ?>
                <p style="text-align: center; color: var(--dark-gray); padding: 1rem;">No comments yet.</p>
            <?php else: ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                        <div class="comment-header">
                            <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?> (<?php echo htmlspecialchars(str_replace('_', ' ', $comment['role'])); ?>)</span>
                            <span class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                        </div>
                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
                        <?php if ($comment['is_internal']): ?>
                            <div style="font-size: 0.7rem; color: var(--warning); margin-top: 0.5rem;">
                                <i class="fas fa-lock"></i> Internal Comment
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 1rem;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openCommentModal(<?php echo $ticket_id; ?>)">
                <i class="fas fa-comment"></i> Add Comment
            </button>
            <?php if ($ticket['status'] !== 'resolved' && $ticket['status'] !== 'closed'): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openEscalateModal(<?php echo $ticket_id; ?>)">
                    <i class="fas fa-level-up-alt"></i> Escalate
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>