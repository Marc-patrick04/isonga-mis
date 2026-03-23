<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    http_response_code(403);
    exit('Access denied');
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Report ID required');
}

$report_id = intval($_GET['id']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            u.full_name,
            u.role as user_role,
            u.email,
            u.phone,
            rt.name as template_name,
            ru.full_name as reviewer_name,
            ru.role as reviewer_role
        FROM reports r 
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN report_templates rt ON r.template_id = rt.id 
        LEFT JOIN users ru ON r.reviewed_by = ru.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        http_response_code(404);
        exit('Report not found');
    }
    
    // Get report media files if any
    $media_stmt = $pdo->prepare("SELECT * FROM report_media WHERE report_id = ?");
    $media_stmt->execute([$report_id]);
    $media_files = $media_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get team contributions if team report
    $contributions_stmt = $pdo->prepare("
        SELECT trc.*, u.full_name 
        FROM team_report_contributions trc 
        JOIN users u ON trc.user_id = u.id 
        WHERE trc.team_report_id = ?
    ");
    $contributions_stmt->execute([$report_id]);
    $contributions = $contributions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error: ' . $e->getMessage());
}
?>

<div class="report-details">
    <!-- Basic Information -->
    <div class="report-section">
        <h4>Report Information</h4>
        <div class="report-field">
            <strong>Report Title:</strong>
            <div class="report-field-content"><?php echo htmlspecialchars($report['title']); ?></div>
        </div>
        
        <div class="report-field">
            <strong>Author:</strong>
            <div class="report-field-content">
                <?php echo htmlspecialchars($report['full_name']); ?> 
                (<?php echo str_replace('_', ' ', $report['user_role']); ?>)
            </div>
        </div>
        
        <div class="report-field">
            <strong>Report Type:</strong>
            <div class="report-field-content"><?php echo ucfirst($report['report_type']); ?></div>
        </div>
        
        <div class="report-field">
            <strong>Template:</strong>
            <div class="report-field-content"><?php echo htmlspecialchars($report['template_name'] ?? 'Custom Report'); ?></div>
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
            <strong>Submitted Date:</strong>
            <div class="report-field-content">
                <?php echo $report['submitted_at'] ? date('F j, Y g:i A', strtotime($report['submitted_at'])) : 'Not submitted'; ?>
            </div>
        </div>
        
        <?php if ($report['reviewed_by']): ?>
        <div class="report-field">
            <strong>Reviewed By:</strong>
            <div class="report-field-content">
                <?php echo htmlspecialchars($report['reviewer_name']); ?>
                (<?php echo str_replace('_', ' ', $report['reviewer_role']); ?>)
            </div>
        </div>
        
        <div class="report-field">
            <strong>Reviewed Date:</strong>
            <div class="report-field-content">
                <?php echo $report['reviewed_at'] ? date('F j, Y g:i A', strtotime($report['reviewed_at'])) : 'N/A'; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Report Content -->
    <div class="report-section">
        <h4>Report Content</h4>
        <?php
        // Parse JSON content if stored as JSON
        $content = $report['content'];
        if (json_decode($content)) {
            $content_array = json_decode($content, true);
            foreach ($content_array as $section => $value):
        ?>
            <div class="report-field">
                <strong><?php echo ucfirst(str_replace('_', ' ', $section)); ?>:</strong>
                <div class="report-field-content"><?php echo nl2br(htmlspecialchars($value)); ?></div>
            </div>
        <?php
            endforeach;
        } else {
        ?>
            <div class="report-field">
                <div class="report-field-content"><?php echo nl2br(htmlspecialchars($content)); ?></div>
            </div>
        <?php
        }
        ?>
    </div>

    <!-- Feedback Section -->
    <?php if ($report['feedback']): ?>
    <div class="report-section">
        <h4>Review Feedback</h4>
        <div class="report-field">
            <div class="report-field-content"><?php echo nl2br(htmlspecialchars($report['feedback'])); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Media Attachments -->
    <?php if (!empty($media_files)): ?>
    <div class="report-section">
        <h4>Attachments (<?php echo count($media_files); ?>)</h4>
        <div class="media-gallery">
            <?php foreach ($media_files as $media): ?>
            <div class="media-item">
                <?php if (strpos($media['file_type'], 'image/') === 0): ?>
                    <img src="../<?php echo htmlspecialchars($media['file_path']); ?>" alt="<?php echo htmlspecialchars($media['file_name']); ?>">
                <?php else: ?>
                    <div class="file-icon">
                        <i class="fas fa-file"></i>
                    </div>
                <?php endif; ?>
                <div class="media-info">
                    <div class="file-name"><?php echo htmlspecialchars($media['file_name']); ?></div>
                    <div class="file-size"><?php echo formatFileSize($media['file_size']); ?></div>
                    <a href="../<?php echo htmlspecialchars($media['file_path']); ?>" download class="btn btn-primary btn-sm" style="margin-top: 0.5rem;">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Team Contributions -->
    <?php if (!empty($contributions)): ?>
    <div class="report-section">
        <h4>Team Contributions (<?php echo count($contributions); ?>)</h4>
        <?php foreach ($contributions as $contribution): ?>
        <div class="team-contribution">
            <div class="contribution-header">
                <span class="contribution-author"><?php echo htmlspecialchars($contribution['full_name']); ?></span>
            </div>
            <div class="contribution-content">
                <strong><?php echo htmlspecialchars($contribution['section_title']); ?></strong>
                <p><?php echo nl2br(htmlspecialchars($contribution['content'])); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>