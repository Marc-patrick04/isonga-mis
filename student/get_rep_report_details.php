<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    http_response_code(403);
    echo "Access denied";
    exit();
}

// Check if report ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo "Report ID is required";
    exit();
}

$report_id = intval($_GET['id']);
$student_id = $_SESSION['user_id'];

try {
    // Get report details - ensure it belongs to the student
    $stmt = $pdo->prepare("
        SELECT 
            crr.*,
            u.full_name as rep_name,
            u.reg_number,
            u.email,
            d.name as department_name,
            p.name as program_name,
            admin.full_name as reviewed_by_name,
            crt.name as template_name
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        LEFT JOIN users admin ON crr.reviewed_by = admin.id
        LEFT JOIN class_rep_templates crt ON crr.template_id = crt.id
        WHERE crr.id = ? AND crr.user_id = ?
    ");
    $stmt->execute([$report_id, $student_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        http_response_code(404);
        echo "<div class='alert alert-error'><i class='fas fa-exclamation-triangle'></i> Report not found or you don't have permission to view it.</div>";
        exit();
    }
    
    // Decode JSON content
    $content = json_decode($report['content'], true);
    
    // Format dates
    $submitted_at = $report['submitted_at'] ? date('F j, Y \a\t g:i A', strtotime($report['submitted_at'])) : 'Not submitted';
    $reviewed_at = $report['reviewed_at'] ? date('F j, Y \a\t g:i A', strtotime($report['reviewed_at'])) : 'Not reviewed';
    $activity_date = $report['activity_date'] ? date('F j, Y', strtotime($report['activity_date'])) : 'N/A';
    $report_period = $report['report_period'] ? date('F Y', strtotime($report['report_period'])) : 'N/A';
    
    // Status badge
    $status_classes = [
        'draft' => 'status-draft',
        'submitted' => 'status-submitted',
        'reviewed' => 'status-reviewed',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected'
    ];
    $status_class = $status_classes[$report['status']] ?? 'status-draft';
    
} catch (PDOException $e) {
    http_response_code(500);
    echo "<div class='alert alert-error'><i class='fas fa-exclamation-triangle'></i> Database error occurred.</div>";
    exit();
}
?>
<style>
    .report-details-container {
        font-family: 'Segoe UI', sans-serif;
    }
    
    .report-header {
        border-bottom: 2px solid var(--gray);
        padding-bottom: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .report-title {
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .report-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .meta-label {
        font-weight: 600;
        color: var(--dark-gray);
    }
    
    .meta-value {
        color: var(--text);
    }
    
    .status-badge {
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        display: inline-block;
    }
    
    .content-section {
        margin-bottom: 2rem;
        background: var(--light);
        padding: 1.5rem;
        border-radius: var(--radius);
        border: 1px solid var(--gray);
    }
    
    .section-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--gray);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .field-group {
        margin-bottom: 1.5rem;
    }
    
    .field-label {
        font-weight: 600;
        color: var(--text);
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .field-label i {
        color: var(--secondary);
    }
    
    .field-value {
        background: var(--white);
        padding: 1rem;
        border-radius: var(--radius);
        border: 1px solid var(--gray);
        color: var(--text);
        white-space: pre-wrap;
        word-wrap: break-word;
        line-height: 1.6;
    }
    
    .feedback-section {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 235, 59, 0.1) 100%);
        border: 2px solid var(--warning);
        border-radius: var(--radius);
        padding: 1.5rem;
        margin-top: 1rem;
        position: relative;
    }
    
    .feedback-section:before {
        content: '💬';
        position: absolute;
        top: -12px;
        left: 20px;
        background: var(--white);
        padding: 0 10px;
        font-size: 1.2rem;
    }
    
    .reviewer-info {
        display: flex;
        justify-content: space-between;
        margin-top: 1rem;
        font-size: 0.85rem;
        color: var(--dark-gray);
        padding-top: 0.5rem;
        border-top: 1px dashed var(--warning);
    }
    
    .content-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }
    
    .timeline-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 0.5rem;
        border-radius: var(--radius);
        background: var(--white);
        border: 1px solid var(--gray);
    }
    
    .timeline-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--light);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--secondary);
        font-size: 1rem;
    }
    
    .timeline-content {
        flex: 1;
    }
    
    .timeline-date {
        font-size: 0.85rem;
        color: var(--dark-gray);
        margin-top: 0.25rem;
    }
    
    .badge-success {
        background: var(--success);
        color: white;
        padding: 0.2rem 0.6rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-left: 0.5rem;
    }
    
    .badge-info {
        background: var(--info);
        color: white;
        padding: 0.2rem 0.6rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-left: 0.5rem;
    }
</style>

<div class="report-details-container">
    <!-- Report Header -->
    <div class="report-header">
        <div class="report-title">
            <span><?php echo htmlspecialchars($report['title']); ?></span>
            <span class="status-badge <?php echo $status_class; ?>">
                <?php echo ucfirst($report['status']); ?>
            </span>
        </div>
        
        <div class="report-meta">
            <div class="meta-item">
                <span class="meta-label"><i class="fas fa-file-alt"></i> Type:</span>
                <span class="meta-value"><?php echo ucfirst($report['report_type']); ?></span>
            </div>
            
            <?php if ($report_period !== 'N/A'): ?>
            <div class="meta-item">
                <span class="meta-label"><i class="fas fa-calendar-alt"></i> Period:</span>
                <span class="meta-value"><?php echo $report_period; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($activity_date !== 'N/A'): ?>
            <div class="meta-item">
                <span class="meta-label"><i class="fas fa-calendar-day"></i> Date:</span>
                <span class="meta-value"><?php echo $activity_date; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($report['template_name']): ?>
            <div class="meta-item">
                <span class="meta-label"><i class="fas fa-template"></i> Template:</span>
                <span class="meta-value"><?php echo htmlspecialchars($report['template_name']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Your Information -->
    <div class="content-section">
        <h3 class="section-title"><i class="fas fa-user-circle"></i> Your Information</h3>
        <div class="content-grid">
            <div class="field-group">
                <div class="field-label"><i class="fas fa-user"></i> Name</div>
                <div class="field-value"><?php echo htmlspecialchars($report['rep_name']); ?></div>
            </div>
            
            <div class="field-group">
                <div class="field-label"><i class="fas fa-id-card"></i> Registration Number</div>
                <div class="field-value"><?php echo htmlspecialchars($report['reg_number']); ?></div>
            </div>
            
            <div class="field-group">
                <div class="field-label"><i class="fas fa-envelope"></i> Email</div>
                <div class="field-value"><?php echo htmlspecialchars($report['email']); ?></div>
            </div>
            
            <?php if ($report['department_name']): ?>
            <div class="field-group">
                <div class="field-label"><i class="fas fa-building"></i> Department</div>
                <div class="field-value"><?php echo htmlspecialchars($report['department_name']); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($report['program_name']): ?>
            <div class="field-group">
                <div class="field-label"><i class="fas fa-graduation-cap"></i> Program</div>
                <div class="field-value"><?php echo htmlspecialchars($report['program_name']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Report Content -->
    <div class="content-section">
        <h3 class="section-title"><i class="fas fa-file-text"></i> Report Content</h3>
        
        <?php if (!empty($content) && is_array($content)): ?>
            <?php foreach ($content as $field_name => $field_value): ?>
                <?php if (!empty($field_value) && trim($field_value) !== ''): ?>
                    <div class="field-group">
                        <div class="field-label">
                            <i class="fas fa-arrow-right"></i>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field_name))); ?>
                        </div>
                        <div class="field-value">
                            <?php 
                            if (is_array($field_value)) {
                                echo '<ul>';
                                foreach ($field_value as $item) {
                                    echo '<li>' . htmlspecialchars($item) . '</li>';
                                }
                                echo '</ul>';
                            } else {
                                echo nl2br(htmlspecialchars($field_value));
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="field-group">
                <div class="field-value" style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                    <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>No detailed content available for this report.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Report Timeline -->
    <div class="content-section">
        <h3 class="section-title"><i class="fas fa-history"></i> Report Timeline</h3>
        
        <div class="timeline-item">
            <div class="timeline-icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="timeline-content">
                <strong>Report Created</strong>
                <div class="timeline-date">
                    <?php echo date('F j, Y \a\t g:i A', strtotime($report['created_at'])); ?>
                </div>
            </div>
        </div>
        
        <?php if ($report['submitted_at']): ?>
        <div class="timeline-item">
            <div class="timeline-icon" style="background: rgba(23,162,184,0.1); color: var(--info);">
                <i class="fas fa-paper-plane"></i>
            </div>
            <div class="timeline-content">
                <strong>Report Submitted for Review <span class="badge-info">Submitted</span></strong>
                <div class="timeline-date">
                    <?php echo date('F j, Y \a\t g:i A', strtotime($report['submitted_at'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report['reviewed_at']): ?>
        <div class="timeline-item">
            <div class="timeline-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);">
                <i class="fas fa-eye"></i>
            </div>
            <div class="timeline-content">
                <strong>Report Reviewed <span class="badge-info">Reviewed</span></strong>
                <div class="timeline-date">
                    <?php echo date('F j, Y \a\t g:i A', strtotime($report['reviewed_at'])); ?>
                </div>
                <?php if ($report['reviewed_by_name']): ?>
                <div style="font-size: 0.9rem; color: var(--dark-gray); margin-top: 0.25rem;">
                    By: <?php echo htmlspecialchars($report['reviewed_by_name']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report['status'] === 'approved'): ?>
        <div class="timeline-item">
            <div class="timeline-icon" style="background: rgba(40,167,69,0.1); color: var(--success);">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="timeline-content">
                <strong>Report Approved <span class="badge-success">✓ Approved</span></strong>
                <div class="timeline-date">
                    <?php echo $reviewed_at; ?>
                </div>
                <?php if ($report['reviewed_by_name']): ?>
                <div style="font-size: 0.9rem; color: var(--dark-gray); margin-top: 0.25rem;">
                    Approved by: <?php echo htmlspecialchars($report['reviewed_by_name']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Feedback Section -->
    <?php if ($report['feedback']): ?>
    <div class="content-section">
        <h3 class="section-title"><i class="fas fa-comment-dots"></i> Feedback from Reviewer</h3>
        <div class="feedback-section">
            <div style="font-size: 1rem; line-height: 1.6; color: var(--text);">
                <?php echo nl2br(htmlspecialchars($report['feedback'])); ?>
            </div>
            <?php if ($report['reviewed_by_name']): ?>
            <div class="reviewer-info">
                <span>
                    <i class="fas fa-user-check"></i>
                    <?php echo htmlspecialchars($report['reviewed_by_name']); ?>
                </span>
                <span>
                    <i class="fas fa-clock"></i>
                    <?php echo date('F j, Y', strtotime($report['reviewed_at'])); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($report['status'] === 'rejected'): ?>
        <div class="alert alert-error" style="margin-top: 1rem;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>This report requires revision.</strong> Please address the feedback above and resubmit your report.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div style="display: flex; gap: 1rem; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--gray);">
        <button class="btn btn-secondary close-modal" style="flex: 1;">
            <i class="fas fa-times"></i> Close
        </button>
        <?php if ($report['status'] === 'rejected'): ?>
        <button class="btn btn-primary" style="flex: 1;" onclick="alert('Revision functionality coming soon!')">
            <i class="fas fa-edit"></i> Revise Report
        </button>
        <?php endif; ?>
    </div>
</div>

<script>
    // Add close functionality
    document.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('viewReportModal').style.display = 'none';
        });
    });
</script>