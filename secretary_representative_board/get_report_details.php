<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_representative_board') {
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
$user_id = $_SESSION['user_id'];

try {
    // Get report details with related information
    $stmt = $pdo->prepare("
        SELECT 
            crr.*,
            u.full_name as rep_name,
            u.reg_number,
            u.email,
            u.phone,
            d.name as department_name,
            p.name as program_name,
            admin.full_name as reviewed_by_name,
            admin.email as reviewed_by_email,
            crt.name as template_name
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        LEFT JOIN users admin ON crr.reviewed_by = admin.id
        LEFT JOIN class_rep_templates crt ON crr.template_id = crt.id
        WHERE crr.id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        http_response_code(404);
        echo "Report not found";
        exit();
    }
    
    // Decode JSON content
    $content = json_decode($report['content'], true);
    
    // Format dates
    $submitted_at = $report['submitted_at'] ? date('F j, Y \a\t g:i A', strtotime($report['submitted_at'])) : 'Not submitted';
    $reviewed_at = $report['reviewed_at'] ? date('F j, Y \a\t g:i A', strtotime($report['reviewed_at'])) : 'Not reviewed';
    $activity_date = $report['activity_date'] ? date('F j, Y', strtotime($report['activity_date'])) : 'N/A';
    $report_period = $report['report_period'] ? date('F Y', strtotime($report['report_period'])) : 'N/A';
    
    // Status badge class
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
    echo "Database error: " . $e->getMessage();
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .report-details-container {
            font-family: 'Inter', sans-serif;
        }
        
        .report-header {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .report-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
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
            color: #6c757d;
        }
        
        .meta-value {
            color: #2c3e50;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-draft { background: #cce7ff; color: #17a2b8; }
        .status-submitted { background: #fff3cd; color: #856404; }
        .status-reviewed { background: #d4edda; color: #155724; }
        .status-approved { background: #d1f2eb; color: #0069d9; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .content-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .field-group {
            margin-bottom: 1rem;
        }
        
        .field-label {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }
        
        .field-value {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            color: #2c3e50;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .feedback-section {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .reviewer-info {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px dashed #dee2e6;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }
    </style>
</head>
<body>
    <div class="report-details-container">
        <!-- Report Header -->
        <div class="report-header">
            <h1 class="report-title"><?php echo htmlspecialchars($report['title']); ?></h1>
            
            <div class="report-meta">
                <div class="meta-item">
                    <span class="meta-label">Status:</span>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo ucfirst($report['status']); ?>
                    </span>
                </div>
                
                <div class="meta-item">
                    <span class="meta-label">Type:</span>
                    <span class="meta-value"><?php echo ucfirst($report['report_type']); ?></span>
                </div>
                
                <?php if ($report_period !== 'N/A'): ?>
                <div class="meta-item">
                    <span class="meta-label">Period:</span>
                    <span class="meta-value"><?php echo $report_period; ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($activity_date !== 'N/A'): ?>
                <div class="meta-item">
                    <span class="meta-label">Activity Date:</span>
                    <span class="meta-value"><?php echo $activity_date; ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Representative Information -->
        <div class="content-section">
            <h3 class="section-title">Representative Information</h3>
            <div class="content-grid">
                <div class="field-group">
                    <div class="field-label">Name</div>
                    <div class="field-value"><?php echo htmlspecialchars($report['rep_name']); ?></div>
                </div>
                
                <div class="field-group">
                    <div class="field-label">Registration Number</div>
                    <div class="field-value"><?php echo htmlspecialchars($report['reg_number']); ?></div>
                </div>
                
                <div class="field-group">
                    <div class="field-label">Email</div>
                    <div class="field-value"><?php echo htmlspecialchars($report['email']); ?></div>
                </div>
                
                <?php if ($report['phone']): ?>
                <div class="field-group">
                    <div class="field-label">Phone</div>
                    <div class="field-value"><?php echo htmlspecialchars($report['phone']); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($report['department_name']): ?>
                <div class="field-group">
                    <div class="field-label">Department</div>
                    <div class="field-value"><?php echo htmlspecialchars($report['department_name']); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($report['program_name']): ?>
                <div class="field-group">
                    <div class="field-label">Program</div>
                    <div class="field-value"><?php echo htmlspecialchars($report['program_name']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Report Content -->
        <div class="content-section">
            <h3 class="section-title">Report Content</h3>
            
            <?php if (!empty($content) && is_array($content)): ?>
                <?php foreach ($content as $field_name => $field_value): ?>
                    <?php if (!empty($field_value) && trim($field_value) !== ''): ?>
                        <div class="field-group">
                            <div class="field-label">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field_name))); ?>
                            </div>
                            <div class="field-value">
                                <?php 
                                if (is_array($field_value)) {
                                    echo htmlspecialchars(implode(', ', $field_value));
                                } else {
                                    echo nl2br(htmlspecialchars($field_value));
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>No content available for this report.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Timeline Information -->
        <div class="content-section">
            <h3 class="section-title">Timeline</h3>
            <div class="content-grid">
                <div class="field-group">
                    <div class="field-label">Created</div>
                    <div class="field-value">
                        <?php echo date('F j, Y \a\t g:i A', strtotime($report['created_at'])); ?>
                    </div>
                </div>
                
                <div class="field-group">
                    <div class="field-label">Submitted</div>
                    <div class="field-value"><?php echo $submitted_at; ?></div>
                </div>
                
                <div class="field-group">
                    <div class="field-label">Reviewed</div>
                    <div class="field-value"><?php echo $reviewed_at; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Feedback Section -->
        <?php if ($report['feedback']): ?>
        <div class="content-section">
            <h3 class="section-title">Feedback</h3>
            <div class="feedback-section">
                <?php echo nl2br(htmlspecialchars($report['feedback'])); ?>
                <?php if ($report['reviewed_by_name']): ?>
                <div class="reviewer-info">
                    <span>Reviewed by: <?php echo htmlspecialchars($report['reviewed_by_name']); ?></span>
                    <span><?php echo date('F j, Y', strtotime($report['reviewed_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report['template_name']): ?>
        <div class="content-section">
            <div class="field-group">
                <div class="field-label">Report Template</div>
                <div class="field-value"><?php echo htmlspecialchars($report['template_name']); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>