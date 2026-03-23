<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
    exit('Unauthorized');
}

$project_id = $_GET['project_id'] ?? 0;

try {
    // Get project details
    $project_stmt = $pdo->prepare("
        SELECT 
            ip.*, 
            u.full_name as student_name,
            u.email as student_email,
            u.department as student_department,
            ic.name as category_name,
            rev.full_name as reviewed_by_name
        FROM innovation_projects ip
        LEFT JOIN users u ON ip.student_id = u.id
        LEFT JOIN innovation_categories ic ON ip.category_id = ic.id
        LEFT JOIN users rev ON ip.reviewed_by = rev.id
        WHERE ip.id = ?
    ");
    $project_stmt->execute([$project_id]);
    $project = $project_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get progress updates
    $progress_stmt = $pdo->prepare("
        SELECT 
            ppu.*,
            u.full_name as updated_by_name
        FROM project_progress_updates ppu
        JOIN users u ON ppu.updated_by = u.id
        WHERE ppu.project_id = ?
        ORDER BY ppu.created_at DESC
    ");
    $progress_stmt->execute([$project_id]);
    $progress_updates = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($project): ?>
        <div class="project-details">
            <h4><?php echo htmlspecialchars($project['title']); ?></h4>
            <div class="project-info">
                <p><strong>Description:</strong><br><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                
                <div class="details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0;">
                    <div><strong>Student:</strong> <?php echo htmlspecialchars($project['student_name']); ?></div>
                    <div><strong>Department:</strong> <?php echo htmlspecialchars($project['student_department']); ?></div>
                    <div><strong>Category:</strong> <?php echo htmlspecialchars($project['category_name'] ?? 'Uncategorized'); ?></div>
                    <div><strong>Priority:</strong> <span class="priority-badge priority-<?php echo $project['priority']; ?>"><?php echo ucfirst($project['priority']); ?></span></div>
                    <div><strong>Status:</strong> <span class="status-badge status-<?php echo $project['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?></span></div>
                    <div><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($project['created_at'])); ?></div>
                </div>
                
                <?php if ($project['feedback']): ?>
                    <div class="feedback" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin: 1rem 0;">
                        <strong>Feedback:</strong>
                        <p style="margin: 0.5rem 0 0 0;"><?php echo nl2br(htmlspecialchars($project['feedback'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="progress-updates" style="margin-top: 2rem;">
                <h5>Progress Updates</h5>
                <?php if (empty($progress_updates)): ?>
                    <p>No progress updates yet.</p>
                <?php else: ?>
                    <?php foreach ($progress_updates as $update): ?>
                        <div class="progress-item" style="border-left: 3px solid #007bff; padding-left: 1rem; margin-bottom: 1rem;">
                            <div style="display: flex; justify-content: between; align-items: center;">
                                <strong><?php echo htmlspecialchars($update['updated_by_name']); ?></strong>
                                <span style="color: #6c757d; font-size: 0.8rem;"><?php echo date('M j, Y g:i A', strtotime($update['created_at'])); ?></span>
                            </div>
                            <p style="margin: 0.5rem 0;"><?php echo nl2br(htmlspecialchars($update['update_text'])); ?></p>
                            <?php if ($update['progress_percentage'] > 0): ?>
                                <div style="background: #e9ecef; border-radius: 4px; height: 8px; margin: 0.5rem 0;">
                                    <div style="background: #007bff; height: 100%; width: <?php echo $update['progress_percentage']; ?>%; border-radius: 4px;"></div>
                                </div>
                                <small>Progress: <?php echo $update['progress_percentage']; ?>%</small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <p>Project not found.</p>
    <?php endif;
    
} catch (PDOException $e) {
    echo '<p>Error loading project details.</p>';
}
?>