<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit();
}

$report_id = $_GET['id'] ?? null;

if (!$report_id) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Report ID is required';
    exit();
}

try {
    // Get report details with user and template information
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            u.full_name,
            u.role as user_role,
            u.email as user_email,
            u.phone as user_phone,
            rt.name as template_name,
            rt.description as template_description,
            rt.report_type as template_type,
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
        header('HTTP/1.1 404 Not Found');
        echo 'Report not found';
        exit();
    }

    // Get report media files
    $mediaStmt = $pdo->prepare("
        SELECT * FROM report_media 
        WHERE report_id = ? 
        ORDER BY created_at DESC
    ");
    $mediaStmt->execute([$report_id]);
    $media_files = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get team contributions if it's a team report
    $contributions = [];
    if ($report['report_type'] === 'team') {
        $contribStmt = $pdo->prepare("
            SELECT trc.*, u.full_name, u.role
            FROM team_report_contributions trc
            JOIN users u ON trc.user_id = u.id
            WHERE trc.team_report_id = ?
            ORDER BY trc.created_at ASC
        ");
        $contribStmt->execute([$report_id]);
        $contributions = $contribStmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Report details error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Error loading report details';
    exit();
}

// Function to format JSON content for display
function formatReportContent($content) {
    if (empty($content)) {
        return '<p style="color: var(--dark-gray); font-style: italic;">No content available</p>';
    }
    
    // Try to decode as JSON first
    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $html = '';
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $html .= '<div class="report-field">';
                $html .= '<strong>' . ucfirst(str_replace('_', ' ', $key)) . ':</strong>';
                $html .= '<div class="report-field-content">';
                foreach ($value as $subKey => $subValue) {
                    $html .= '<p><strong>' . ucfirst(str_replace('_', ' ', $subKey)) . ':</strong> ' . nl2br(htmlspecialchars($subValue)) . '</p>';
                }
                $html .= '</div>';
                $html .= '</div>';
            } else {
                $html .= '<div class="report-field">';
                $html .= '<strong>' . ucfirst(str_replace('_', ' ', $key)) . ':</strong>';
                $html .= '<div class="report-field-content">' . nl2br(htmlspecialchars($value)) . '</div>';
                $html .= '</div>';
            }
        }
        return $html;
    }
    
    // If not JSON, display as plain text
    return '<div class="report-field-content">' . nl2br(htmlspecialchars($content)) . '</div>';
}
?>

<div class="report-details">
    <!-- Report Header Information -->
    <div class="report-section">
        <h4>Report Information</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
            <div class="report-field">
                <strong>Report Title:</strong>
                <div class="report-field-content"><?php echo htmlspecialchars($report['title']); ?></div>
            </div>
            <div class="report-field">
                <strong>Author:</strong>
                <div class="report-field-content">
                    <?php echo htmlspecialchars($report['full_name']); ?><br>
                    <small style="color: var(--dark-gray);"><?php echo str_replace('_', ' ', $report['user_role']); ?></small>
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
                <strong>Submitted:</strong>
                <div class="report-field-content">
                    <?php echo $report['submitted_at'] ? date('F j, Y g:i A', strtotime($report['submitted_at'])) : 'Not submitted'; ?>
                </div>
            </div>
            <?php if ($report['report_period']): ?>
            <div class="report-field">
                <strong>Report Period:</strong>
                <div class="report-field-content">
                    <?php echo date('F Y', strtotime($report['report_period'])); ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($report['activity_date']): ?>
            <div class="report-field">
                <strong>Activity Date:</strong>
                <div class="report-field-content">
                    <?php echo date('F j, Y', strtotime($report['activity_date'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Report Content -->
    <div class="report-section">
        <h4>Report Content</h4>
        <?php echo formatReportContent($report['content']); ?>
    </div>

    <!-- Team Contributions (for team reports) -->
    <?php if (!empty($contributions)): ?>
    <div class="report-section">
        <h4>Team Contributions</h4>
        <?php foreach ($contributions as $contribution): ?>
            <div class="report-field" style="margin-bottom: 1.5rem; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                <strong><?php echo htmlspecialchars($contribution['section_title']); ?></strong>
                <div style="font-size: 0.8rem; color: var(--dark-gray); margin-bottom: 0.5rem;">
                    By <?php echo htmlspecialchars($contribution['full_name']); ?> 
                    (<?php echo str_replace('_', ' ', $contribution['role']); ?>)
                </div>
                <div class="report-field-content">
                    <?php echo nl2br(htmlspecialchars($contribution['content'])); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Media Files -->
    <?php if (!empty($media_files)): ?>
    <div class="report-section">
        <h4>Attached Files</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <?php foreach ($media_files as $file): ?>
                <div class="report-field" style="text-align: center; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                    <?php if (strpos($file['file_type'], 'image/') === 0): ?>
                        <i class="fas fa-image" style="font-size: 2rem; color: var(--primary-blue); margin-bottom: 0.5rem;"></i>
                    <?php elseif (strpos($file['file_type'], 'application/pdf') === 0): ?>
                        <i class="fas fa-file-pdf" style="font-size: 2rem; color: var(--danger); margin-bottom: 0.5rem;"></i>
                    <?php elseif (strpos($file['file_type'], 'application/vnd.openxmlformats') !== false): ?>
                        <i class="fas fa-file-excel" style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                    <?php else: ?>
                        <i class="fas fa-file" style="font-size: 2rem; color: var(--dark-gray); margin-bottom: 0.5rem;"></i>
                    <?php endif; ?>
                    
                    <div style="font-weight: 600; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($file['file_name']); ?></div>
                    <div style="font-size: 0.8rem; color: var(--dark-gray);">
                        <?php echo round($file['file_size'] / 1024, 1); ?> KB
                    </div>
                    <a href="../<?php echo htmlspecialchars($file['file_path']); ?>" 
                       class="btn btn-primary btn-sm" 
                       style="margin-top: 0.5rem;"
                       target="_blank"
                       download="<?php echo htmlspecialchars($file['file_name']); ?>">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Review Information -->
    <?php if ($report['status'] === 'reviewed' || $report['status'] === 'approved' || $report['status'] === 'rejected'): ?>
    <div class="report-section">
        <h4>Review Information</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
            <div class="report-field">
                <strong>Reviewed By:</strong>
                <div class="report-field-content">
                    <?php echo htmlspecialchars($report['reviewer_name'] ?? 'N/A'); ?><br>
                    <small style="color: var(--dark-gray);"><?php echo str_replace('_', ' ', $report['reviewer_role'] ?? ''); ?></small>
                </div>
            </div>
            <div class="report-field">
                <strong>Reviewed At:</strong>
                <div class="report-field-content">
                    <?php echo $report['reviewed_at'] ? date('F j, Y g:i A', strtotime($report['reviewed_at'])) : 'N/A'; ?>
                </div>
            </div>
        </div>
        
        <?php if (!empty($report['feedback'])): ?>
        <div class="report-field" style="margin-top: 1rem;">
            <strong>Feedback:</strong>
            <div class="report-field-content" style="background: #fff3cd; border-left: 4px solid var(--warning);">
                <?php echo nl2br(htmlspecialchars($report['feedback'])); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Raw JSON Content (for debugging) -->
    <div class="report-section" style="display: none;" id="rawJsonSection">
        <h4>Raw JSON Content</h4>
        <div class="report-json-content">
            <?php echo htmlspecialchars($report['content']); ?>
        </div>
    </div>

    <!-- Debug Toggle -->
    <div style="text-align: center; margin-top: 1rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleRawJson()">
            <i class="fas fa-code"></i> Toggle Raw JSON
        </button>
    </div>
</div>

<script>
function toggleRawJson() {
    const rawSection = document.getElementById('rawJsonSection');
    rawSection.style.display = rawSection.style.display === 'none' ? 'block' : 'none';
}
</script>