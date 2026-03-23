<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is part of representative board
$allowed_roles = ['president_representative_board', 'vice_president_representative_board', 'secretary_representative_board'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$report_id = $_GET['id'] ?? 0;

// Get user and committee member data
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

// Get report data with team information
try {
    $stmt = $pdo->prepare("
        SELECT r.*, cm.name as author_name, cm.role as author_role,
               t.name as template_name, t.fields as template_fields
        FROM reports r
        JOIN committee_members cm ON r.user_id = cm.user_id
        JOIN report_templates t ON r.template_id = t.id
        WHERE r.id = ? AND r.is_team_report = TRUE
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        header('Location: reports.php');
        exit();
    }
    
    // Get report sections with assignee information
    $stmt = $pdo->prepare("
        SELECT rs.*, cm.name as assigned_name, cm.role as assigned_role,
               u.full_name as assigned_full_name
        FROM report_sections rs
        JOIN committee_members cm ON rs.assigned_to = cm.id
        JOIN users u ON cm.user_id = u.id
        WHERE rs.report_id = ?
        ORDER BY rs.order_index
    ");
    $stmt->execute([$report_id]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get template fields
    $template_fields = json_decode($report['template_fields'], true);
    
} catch (PDOException $e) {
    error_log("Fetch team report error: " . $e->getMessage());
    header('Location: reports.php');
    exit();
}

// Handle section updates
if ($_POST['action'] ?? '' === 'update_section') {
    $section_id = $_POST['section_id'] ?? '';
    $content = $_POST['content'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    
    if ($section_id) {
        try {
            // Check if user is assigned to this section
            $stmt = $pdo->prepare("SELECT * FROM report_sections WHERE id = ? AND assigned_to = ?");
            $stmt->execute([$section_id, $committee_member_id]);
            $section = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($section) {
                $stmt = $pdo->prepare("UPDATE report_sections SET content = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$content, $status, $section_id]);
                
                echo json_encode(['success' => true, 'message' => 'Section updated successfully']);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'You are not assigned to this section']);
                exit();
            }
        } catch (PDOException $e) {
            error_log("Update section error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update section']);
            exit();
        }
    }
}

// Handle team approval
if ($_POST['action'] ?? '' === 'approve_report') {
    try {
        $stmt = $pdo->prepare("
            UPDATE team_report_approvals 
            SET approval_status = 'approved', approved_at = NOW(), comments = ?
            WHERE report_id = ? AND committee_member_id = ?
        ");
        $stmt->execute([$_POST['comments'] ?? '', $report_id, $committee_member_id]);
        
        $success_message = "Report approved successfully!";
    } catch (PDOException $e) {
        error_log("Approve report error: " . $e->getMessage());
        $error_message = "Failed to approve report";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Team Report - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .section-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        
        .section-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .section-assignee {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .section-content {
            min-height: 200px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 1rem;
        }
        
        .section-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .your-section {
            border-left-color: #28a745;
        }
        
        .completed-section {
            background: #f8fff8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Edit Team Report: <?php echo htmlspecialchars($report['title']); ?></h1>
        
        <div class="team-progress">
            <h3>Team Progress</h3>
            <!-- Progress bars showing each team member's completion -->
        </div>
        
        <div class="sections-container">
            <?php foreach ($sections as $section): 
                $is_assigned = $section['assigned_to'] == $committee_member_id;
                $section_class = $is_assigned ? 'your-section' : '';
                $section_class .= $section['status'] === 'completed' ? ' completed-section' : '';
            ?>
                <div class="section-container <?php echo $section_class; ?>">
                    <div class="section-header">
                        <h4><?php echo htmlspecialchars($section['section_title']); ?></h4>
                        <div class="section-assignee">
                            <span class="role-badge"><?php echo str_replace('_representative_board', '', $section['assigned_role']); ?></span>
                            <span><?php echo htmlspecialchars($section['assigned_name']); ?></span>
                            <?php if ($is_assigned): ?>
                                <span class="badge">Your Section</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="section-content" contenteditable="<?php echo $is_assigned ? 'true' : 'false'; ?>"
                         onblur="updateSection(<?php echo $section['id']; ?>, this.innerHTML)">
                         <?php echo htmlspecialchars($section['content']); ?>
                    </div>
                    
                    <?php if ($is_assigned): ?>
                        <div class="section-actions">
                            <button onclick="markSectionCompleted(<?php echo $section['id']; ?>)" 
                                    class="btn btn-success">
                                <i class="fas fa-check"></i> Mark Complete
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Team approval section -->
        <div class="approval-section">
            <h3>Team Approval</h3>
            <!-- Show approval status for each team member -->
            <!-- Allow president to submit for final approval -->
        </div>
    </div>

    <script>
        function updateSection(sectionId, content) {
            fetch('edit_team_report.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=update_section&section_id=' + sectionId + '&content=' + encodeURIComponent(content)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Section updated successfully', 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            });
        }
        
        function markSectionCompleted(sectionId) {
            fetch('edit_team_report.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=update_section&section_id=' + sectionId + '&status=completed'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Section marked as completed', 'success');
                    location.reload();
                }
            });
        }
    </script>
</body>
</html>