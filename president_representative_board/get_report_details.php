<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is President of Representative Board
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'president_representative_board') {
    http_response_code(403);
    exit('Access denied');
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Report ID required');
}

$report_id = $_GET['id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            crr.*,
            u.full_name as rep_name,
            u.reg_number,
            d.name as department_name,
            p.name as program_name,
            admin.full_name as reviewed_by_name,
            ct.name as template_name
        FROM class_rep_reports crr
        JOIN users u ON crr.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        LEFT JOIN users admin ON crr.reviewed_by = admin.id
        LEFT JOIN class_rep_templates ct ON crr.template_id = ct.id
        WHERE crr.id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        http_response_code(404);
        exit('Report not found');
    }
    
    // Decode JSON content
    $content = json_decode($report['content'], true);
    
    echo '<div style="font-size: 0.8rem;">';
    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">';
    echo '<div><strong>Representative:</strong><br>' . htmlspecialchars($report['rep_name']) . '</div>';
    echo '<div><strong>Registration Number:</strong><br>' . htmlspecialchars($report['reg_number']) . '</div>';
    echo '<div><strong>Department:</strong><br>' . htmlspecialchars($report['department_name'] ?? 'N/A') . '</div>';
    echo '<div><strong>Program:</strong><br>' . htmlspecialchars($report['program_name'] ?? 'N/A') . '</div>';
    echo '<div><strong>Report Type:</strong><br>' . ucfirst($report['report_type']) . '</div>';
    echo '<div><strong>Template:</strong><br>' . htmlspecialchars($report['template_name'] ?? 'N/A') . '</div>';
    echo '</div>';
    
    if ($report['report_period']) {
        echo '<div style="margin-bottom: 1rem;"><strong>Report Period:</strong> ' . date('F Y', strtotime($report['report_period'])) . '</div>';
    }
    
    if ($report['activity_date']) {
        echo '<div style="margin-bottom: 1rem;"><strong>Activity Date:</strong> ' . date('M j, Y', strtotime($report['activity_date'])) . '</div>';
    }
    
    echo '<div style="margin-bottom: 1rem;"><strong>Status:</strong> <span class="status-badge status-' . $report['status'] . '">' . ucfirst($report['status']) . '</span></div>';
    
    if ($report['submitted_at']) {
        echo '<div style="margin-bottom: 1rem;"><strong>Submitted:</strong> ' . date('M j, Y g:i A', strtotime($report['submitted_at'])) . '</div>';
    }
    
    if ($report['reviewed_by_name']) {
        echo '<div style="margin-bottom: 1rem;"><strong>Reviewed By:</strong> ' . htmlspecialchars($report['reviewed_by_name']) . ' on ' . date('M j, Y g:i A', strtotime($report['reviewed_at'])) . '</div>';
    }
    
    if ($report['feedback']) {
        echo '<div style="margin-bottom: 1.5rem;">';
        echo '<strong>Feedback:</strong>';
        echo '<div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); margin-top: 0.5rem;">' . nl2br(htmlspecialchars($report['feedback'])) . '</div>';
        echo '</div>';
    }
    
    echo '<div><strong>Report Content:</strong></div>';
    echo '<div style="background: var(--light-gray); padding: 1rem; border-radius: var(--border-radius); margin-top: 0.5rem; max-height: 400px; overflow-y: auto;">';
    
    if ($content && isset($content['sections'])) {
        foreach ($content['sections'] as $section) {
            echo '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--medium-gray);">';
            echo '<strong>' . htmlspecialchars($section['title']) . ':</strong><br>';
            if (isset($section['value'])) {
                echo nl2br(htmlspecialchars($section['value']));
            } else {
                echo '<em style="color: var(--dark-gray);">No content provided</em>';
            }
            echo '</div>';
        }
    } else {
        echo '<em style="color: var(--dark-gray);">No structured content available</em>';
    }
    
    echo '</div>';
    echo '</div>';
    
} catch (PDOException $e) {
    http_response_code(500);
    echo '<div style="text-align: center; color: var(--danger); padding: 2rem;">Error loading report details.</div>';
}
?>