<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    http_response_code(403);
    exit('Access denied');
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Team Report ID required');
}

$report_id = intval($_GET['id']);

try {
    // CORRECTED QUERY: Added email field and removed non-existent reviewed_by join
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            u.full_name as team_leader_name,
            u.role as team_leader_role,
            u.email as team_leader_email,
            u.phone
        FROM team_reports tr 
        JOIN users u ON tr.team_leader_id = u.id 
        WHERE tr.id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        http_response_code(404);
        exit('Team report not found');
    }
    
    // Get team contributions
    $contributions_stmt = $pdo->prepare("
        SELECT trc.*, u.full_name, u.role 
        FROM team_report_contributions trc 
        JOIN users u ON trc.user_id = u.id 
        WHERE trc.team_report_id = ?
        ORDER BY trc.created_at
    ");
    $contributions_stmt->execute([$report_id]);
    $contributions = $contributions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get approvals if they exist
    $approvals_stmt = $pdo->prepare("
        SELECT tra.*, u.full_name, u.role 
        FROM team_report_approvals tra 
        JOIN users u ON tra.committee_member_id = u.id 
        WHERE tra.report_id = ?
        ORDER BY tra.created_at
    ");
    $approvals_stmt->execute([$report_id]);
    $approvals = $approvals_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error: ' . $e->getMessage());
}
?>

<div class="report-details">
    <!-- Basic Information -->
    <div class="report-section">
        <h4>Team Report Information</h4>
        
        <div class="report-field">
            <strong>Report Title:</strong>
            <div class="report-field-content"><?php echo htmlspecialchars($report['title']); ?></div>
        </div>
        
        <div class="report-field">
            <strong>Team Leader:</strong>
            <div class="report-field-content">
                <?php echo htmlspecialchars($report['team_leader_name']); ?> 
                (<?php echo str_replace('_', ' ', $report['team_leader_role']); ?>)
            </div>
        </div>
        
        <div class="report-field">
            <strong>Report Type:</strong>
            <div class="report-field-content"><?php echo ucfirst($report['report_type']); ?></div>
        </div>
        
        <div class="report-field">
            <strong>Status:</strong>
            <div class="report-field-content">
                <span class="badge status-<?php echo $report['status']; ?>">
                    <?php echo ucfirst($report['status']); ?>
                </span>
            </div>
        </div>
        
        <div class="report-field">
            <strong>Report Period:</strong>
            <div class="report-field-content">
                <?php echo $report['report_period'] ? date('F Y', strtotime($report['report_period'])) : 'N/A'; ?>
            </div>
        </div>
        
        <div class="report-field">
            <strong>Submitted Date:</strong>
            <div class="report-field-content">
                <?php echo $report['submitted_at'] ? date('F j, Y g:i A', strtotime($report['submitted_at'])) : 'Not submitted'; ?>
            </div>
        </div>
        
        <?php if (!empty($report['team_leader_email'])): ?>
        <div class="report-field">
            <strong>Contact Email:</strong>
            <div class="report-field-content"><?php echo htmlspecialchars($report['team_leader_email']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($report['phone'])): ?>
        <div class="report-field">
            <strong>Contact Phone:</strong>
            <div class="report-field-content"><?php echo htmlspecialchars($report['phone']); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Overall Summary -->
    <div class="report-section">
        <h4>Overall Summary</h4>
        <div class="report-field">
            <div class="report-field-content"><?php echo nl2br(htmlspecialchars($report['overall_summary'])); ?></div>
        </div>
    </div>

    <!-- Team Contributions -->
    <div class="report-section">
        <h4>Team Contributions (<?php echo count($contributions); ?>)</h4>
        <?php if (empty($contributions)): ?>
            <div class="report-field">
                <div class="report-field-content" style="text-align: center; color: var(--dark-gray); padding: 1rem;">
                    <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                    <p>No individual contributions recorded</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($contributions as $contribution): ?>
            <div class="team-contribution" style="margin-bottom: 1.5rem; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                <div class="contribution-header" style="margin-bottom: 0.5rem;">
                    <span class="contribution-author" style="font-weight: 600; color: var(--primary-blue);">
                        <?php echo htmlspecialchars($contribution['full_name']); ?>
                        (<?php echo str_replace('_', ' ', $contribution['role']); ?>)
                    </span>
                    <small style="color: var(--dark-gray); float: right;">
                        <?php echo date('M j, Y', strtotime($contribution['created_at'])); ?>
                    </small>
                </div>
                <div class="contribution-content">
                    <strong style="color: var(--text-dark); display: block; margin-bottom: 0.5rem;">
                        <?php echo htmlspecialchars($contribution['section_title']); ?>
                    </strong>
                    <p style="margin: 0; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($contribution['content'])); ?></p>
                    
                    <?php if (!empty($contribution['media_files'])): ?>
                    <div class="media-attachments" style="margin-top: 0.5rem;">
                        <small style="color: var(--dark-gray);">
                            <i class="fas fa-paperclip"></i> Contains attachments
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Committee Approvals -->
    <?php if (!empty($approvals)): ?>
    <div class="report-section">
        <h4>Committee Approvals</h4>
        <?php foreach ($approvals as $approval): ?>
        <div class="approval-item" style="margin-bottom: 1rem; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 600; color: var(--text-dark);">
                    <?php echo htmlspecialchars($approval['full_name']); ?>
                    (<?php echo str_replace('_', ' ', $approval['role']); ?>)
                </span>
                <span class="badge status-<?php echo $approval['approval_status']; ?>">
                    <?php echo ucfirst($approval['approval_status']); ?>
                </span>
            </div>
            <?php if (!empty($approval['comments'])): ?>
            <div style="font-size: 0.9rem; color: var(--text-dark); margin-top: 0.5rem;">
                <strong>Comments:</strong> <?php echo nl2br(htmlspecialchars($approval['comments'])); ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($approval['approved_at'])): ?>
            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.5rem;">
                <i class="fas fa-clock"></i> Approved on: <?php echo date('M j, Y g:i A', strtotime($approval['approved_at'])); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Feedback Section -->
    <?php if (!empty($report['feedback'])): ?>
    <div class="report-section">
        <h4>Review Feedback</h4>
        <div class="report-field">
            <div class="report-field-content">
                <div style="padding: 1rem; background: #fff3cd; border-radius: 4px; border-left: 4px solid var(--warning);">
                    <?php echo nl2br(htmlspecialchars($report['feedback'])); ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="report-section">
        <h4>Actions</h4>
        <div class="report-actions">
            <?php if ($report['status'] === 'submitted'): ?>
                <button class="btn btn-warning" onclick="reviewTeamReport(<?php echo $report['id']; ?>)">
                    <i class="fas fa-check-circle"></i> Review Team Report
                </button>
            <?php endif; ?>
            <div class="export-options">
                <button class="btn btn-info" onclick="printTeamReport()">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <button class="btn btn-secondary" onclick="closeTeamReportModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function printTeamReport() {
    window.print();
}

function reviewTeamReport(reportId) {
    document.getElementById('review_team_report_id').value = reportId;
    document.getElementById('reviewTeamReportModal').style.display = 'block';
    // Close the current modal
    document.getElementById('viewTeamReportModal').style.display = 'none';
}

function closeTeamReportModal() {
    document.getElementById('viewTeamReportModal').style.display = 'none';
}
</script>