<?php
session_start();
require_once '../config/database.php';

// Check if user is part of representative board
$allowed_roles = ['president_representative_board', 'vice_president_representative_board', 'secretary_representative_board'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$report_id = $_GET['id'] ?? 0;

// Get committee member data
try {
    $stmt = $pdo->prepare("SELECT * FROM committee_members WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $committee_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $committee_member_id = $committee_member['id'] ?? null;
} catch (PDOException $e) {
    error_log("Committee member lookup error: " . $e->getMessage());
    header('Location: reports.php');
    exit();
}

// Get report and approval data
try {
    $stmt = $pdo->prepare("
        SELECT r.*, tra.approval_status, tra.comments as approval_comments
        FROM reports r
        LEFT JOIN team_report_approvals tra ON r.id = tra.report_id AND tra.committee_member_id = ?
        WHERE r.id = ? AND r.is_team_report = TRUE
    ");
    $stmt->execute([$committee_member_id, $report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        header('Location: reports.php');
        exit();
    }
    
    // Get all approval statuses
    $stmt = $pdo->prepare("
        SELECT tra.*, cm.name, cm.role
        FROM team_report_approvals tra
        JOIN committee_members cm ON tra.committee_member_id = cm.id
        WHERE tra.report_id = ?
    ");
    $stmt->execute([$report_id]);
    $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Fetch approval data error: " . $e->getMessage());
    header('Location: reports.php');
    exit();
}

// Handle approval/rejection
if ($_POST['action'] ?? '') {
    $approval_status = $_POST['approval_status'];
    $comments = $_POST['comments'] ?? '';
    
    try {
        if ($report['approval_status'] === 'pending') {
            $stmt = $pdo->prepare("
                UPDATE team_report_approvals 
                SET approval_status = ?, comments = ?, 
                    approved_at = " . ($approval_status === 'approved' ? 'NOW()' : 'NULL') . "
                WHERE report_id = ? AND committee_member_id = ?
            ");
            $stmt->execute([$approval_status, $comments, $report_id, $committee_member_id]);
            
            // Check if all approvals are received
            if ($approval_status === 'approved') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as pending_count 
                    FROM team_report_approvals 
                    WHERE report_id = ? AND approval_status = 'pending'
                ");
                $stmt->execute([$report_id]);
                $pending_count = $stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
                
                if ($pending_count === 0) {
                    // All approvals received, mark report as approved
                    $stmt = $pdo->prepare("UPDATE reports SET status = 'approved' WHERE id = ?");
                    $stmt->execute([$report_id]);
                }
            }
            
            $success_message = "Approval submitted successfully!";
        }
    } catch (PDOException $e) {
        error_log("Approval submission error: " . $e->getMessage());
        $error_message = "Failed to submit approval";
    }
}
?>

<!-- Approval interface HTML -->
<div class="approval-interface">
    <h2>Approve Team Report: <?php echo htmlspecialchars($report['title']); ?></h2>
    
    <div class="approval-status">
        <h3>Team Approval Status</h3>
        <?php foreach ($approvals as $approval): ?>
            <div class="member-approval">
                <span><?php echo htmlspecialchars($approval['name']); ?></span>
                <span class="status-badge status-<?php echo $approval['approval_status']; ?>">
                    <?php echo ucfirst($approval['approval_status']); ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    
    <?php if ($report['approval_status'] === 'pending'): ?>
        <div class="approval-form">
            <form method="POST">
                <input type="hidden" name="action" value="approve_report">
                
                <div class="form-group">
                    <label>Your Decision</label>
                    <select name="approval_status" required>
                        <option value="approved">Approve</option>
                        <option value="rejected">Reject</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Comments (Optional)</label>
                    <textarea name="comments" placeholder="Add any comments or feedback..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Submit Approval</button>
            </form>
        </div>
    <?php else: ?>
        <div class="already-approved">
            <p>You have already <?php echo $report['approval_status']; ?> this report.</p>
            <p><strong>Your comments:</strong> <?php echo htmlspecialchars($report['approval_comments']); ?></p>
        </div>
    <?php endif; ?>
</div>