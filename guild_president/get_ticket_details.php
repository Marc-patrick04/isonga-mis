<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    die('Unauthorized');
}

$ticket_id = $_GET['id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            ic.name as category_name,
            u.full_name as assigned_name,
            u.role as assigned_role,
            DATEDIFF(NOW(), t.created_at) as days_open,
            DATEDIFF(t.due_date, NOW()) as days_remaining
        FROM tickets t 
        LEFT JOIN issue_categories ic ON t.category_id = ic.id 
        LEFT JOIN users u ON t.assigned_to = u.id 
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ticket) {
        // Get ticket comments
        $commentsStmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.role 
            FROM ticket_comments tc 
            LEFT JOIN users u ON tc.user_id = u.id 
            WHERE tc.ticket_id = ? 
            ORDER BY tc.created_at DESC
        ");
        $commentsStmt->execute([$ticket_id]);
        $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get assignment history
        $assignmentsStmt = $pdo->prepare("
            SELECT ta.*, u.full_name as assigned_by_name 
            FROM ticket_assignments ta 
            LEFT JOIN users u ON ta.assigned_by = u.id 
            WHERE ta.ticket_id = ? 
            ORDER BY ta.assigned_at DESC
        ");
        $assignmentsStmt->execute([$ticket_id]);
        $assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo '
        <div class="ticket-details">
            <div class="detail-section">
                <h4>Basic Information</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Ticket ID:</strong> #' . $ticket['id'] . '
                    </div>
                    <div class="detail-item">
                        <strong>Status:</strong> <span class="badge status-' . $ticket['status'] . '">' . ucfirst(str_replace('_', ' ', $ticket['status'])) . '</span>
                    </div>
                    <div class="detail-item">
                        <strong>Priority:</strong> <span class="badge priority-' . $ticket['priority'] . '">' . ucfirst($ticket['priority']) . '</span>
                    </div>
                    <div class="detail-item">
                        <strong>Category:</strong> ' . htmlspecialchars($ticket['category_name'] ?? 'Uncategorized') . '
                    </div>
                    <div class="detail-item">
                        <strong>Preferred Contact:</strong> ' . ucfirst($ticket['preferred_contact'] ?? 'email') . '
                    </div>
                    <div class="detail-item">
                        <strong>Escalation Level:</strong> ' . ($ticket['escalation_level'] ?? 0) . '
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h4>Student Information</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Registration No:</strong> ' . htmlspecialchars($ticket['reg_number']) . '
                    </div>
                    <div class="detail-item">
                        <strong>Name:</strong> ' . htmlspecialchars($ticket['name']) . '
                    </div>
                    <div class="detail-item">
                        <strong>Email:</strong> ' . htmlspecialchars($ticket['email']) . '
                    </div>
                    <div class="detail-item">
                        <strong>Phone:</strong> ' . htmlspecialchars($ticket['phone']) . '
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h4>Academic Information</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Department:</strong> ' . htmlspecialchars($ticket['department']) . '
                    </div>
                    <div class="detail-item">
                        <strong>Program:</strong> ' . htmlspecialchars($ticket['program']) . '
                    </div>
                    <div class="detail-item">
                        <strong>Academic Year:</strong> ' . htmlspecialchars($ticket['academic_year']) . '
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h4>Issue Details</h4>
                <div class="detail-item">
                    <strong>Subject:</strong> ' . htmlspecialchars($ticket['subject']) . '
                </div>
                <div class="detail-item">
                    <strong>Description:</strong>
                    <div class="description-box">' . nl2br(htmlspecialchars($ticket['description'])) . '</div>
                </div>
            </div>

            <div class="detail-section">
                <h4>Assignment & Timeline</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Assigned To:</strong> ' . ($ticket['assigned_name'] ? htmlspecialchars($ticket['assigned_name'] . ' (' . str_replace('_', ' ', $ticket['assigned_role']) . ')') : '<em>Unassigned</em>') . '
                    </div>
                    <div class="detail-item">
                        <strong>Created:</strong> ' . date('M j, Y g:i A', strtotime($ticket['created_at'])) . '
                    </div>
                    <div class="detail-item">
                        <strong>Due Date:</strong> ' . ($ticket['due_date'] ? date('M j, Y', strtotime($ticket['due_date'])) : 'Not set') . '
                    </div>
                    <div class="detail-item">
                        <strong>Days Open:</strong> ' . $ticket['days_open'] . ' days
                    </div>
                    <div class="detail-item">
                        <strong>Days Remaining:</strong> ' . ($ticket['days_remaining'] > 0 ? $ticket['days_remaining'] . ' days' : '<span style="color: #dc3545;">Overdue</span>') . '
                    </div>
                </div>
            </div>';

        // Resolution details if resolved or closed
        if (in_array($ticket['status'], ['resolved', 'closed']) && $ticket['resolved_at']) {
            echo '
            <div class="detail-section">
                <h4>Resolution Details</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Resolved At:</strong> ' . date('M j, Y g:i A', strtotime($ticket['resolved_at'])) . '
                    </div>';
            
            if ($ticket['resolution_notes']) {
                echo '
                    <div class="detail-item">
                        <strong>Resolution Notes:</strong>
                        <div class="description-box">' . nl2br(htmlspecialchars($ticket['resolution_notes'])) . '</div>
                    </div>';
            }
            
            if ($ticket['student_feedback']) {
                echo '
                    <div class="detail-item">
                        <strong>Student Feedback:</strong>
                        <div class="description-box">' . nl2br(htmlspecialchars($ticket['student_feedback'])) . '</div>
                    </div>';
            }
            
            if ($ticket['feedback_rating']) {
                echo '
                    <div class="detail-item">
                        <strong>Feedback Rating:</strong> ' . str_repeat('⭐', $ticket['feedback_rating']) . ' (' . $ticket['feedback_rating'] . '/5)
                    </div>';
            }
            
            echo '
                </div>
            </div>';
        }

        // Assignment History
        if (!empty($assignments)) {
            echo '
            <div class="detail-section">
                <h4>Assignment History</h4>
                <div class="assignment-history">';
            
            foreach ($assignments as $assignment) {
                echo '
                    <div class="assignment-item">
                        <div class="assignment-info">
                            <strong>Assigned to:</strong> User ID ' . $assignment['assigned_to'] . '
                            <strong>by:</strong> ' . htmlspecialchars($assignment['assigned_by_name']) . '
                            <strong>on:</strong> ' . date('M j, Y g:i A', strtotime($assignment['assigned_at'])) . '
                        </div>';
                
                if ($assignment['reason']) {
                    echo '<div class="assignment-reason">' . htmlspecialchars($assignment['reason']) . '</div>';
                }
                
                echo '
                    </div>';
            }
            
            echo '
                </div>
            </div>';
        }

        // Comments Section
        echo '
            <div class="detail-section">
                <h4>Comments & Notes</h4>';
        
        if (!empty($comments)) {
            echo '<div class="comments-list">';
            
            foreach ($comments as $comment) {
                $commentClass = $comment['is_internal'] ? 'internal-comment' : 'external-comment';
                echo '
                <div class="comment-item ' . $commentClass . '">
                    <div class="comment-header">
                        <strong>' . htmlspecialchars($comment['full_name']) . '</strong>
                        <span class="comment-role">(' . str_replace('_', ' ', $comment['role']) . ')</span>
                        <span class="comment-time">' . date('M j, Y g:i A', strtotime($comment['created_at'])) . '</span>';
                
                if ($comment['is_internal']) {
                    echo '<span class="internal-badge">Internal</span>';
                }
                
                echo '
                    </div>
                    <div class="comment-content">' . nl2br(htmlspecialchars($comment['comment'])) . '</div>
                </div>';
            }
            
            echo '</div>';
        } else {
            echo '<p>No comments yet.</p>';
        }
        
        echo '
            </div>
        </div>';
    } else {
        echo '<p>Ticket not found.</p>';
    }
} catch (PDOException $e) {
    error_log("Error loading ticket details: " . $e->getMessage());
    echo '<p>Error loading ticket details. Please try again.</p>';
}
?>