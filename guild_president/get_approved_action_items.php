<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit();
}

$current_academic_year = '2024/2025';

try {
    // Get approved action plan items that haven't been compiled yet
    $stmt = $pdo->prepare("
        SELECT 
            api.*,
            ap.committee_role,
            ap.title as plan_title,
            u.full_name as submitted_by
        FROM action_plan_items api
        JOIN action_plans ap ON api.action_plan_id = ap.id
        JOIN users u ON ap.submitted_by = u.id
        WHERE ap.academic_year = ? 
        AND ap.status = 'approved'
        AND api.status = 'approved'
        AND api.id NOT IN (
            SELECT original_item_id FROM compiled_plan_items WHERE original_item_id IS NOT NULL
        )
        ORDER BY 
            CASE 
                WHEN api.priority = 'critical' THEN 1
                WHEN api.priority = 'high' THEN 2
                WHEN api.priority = 'medium' THEN 3
                ELSE 4
            END,
            ap.committee_role,
            api.sequence_order
    ");
    $stmt->execute([$current_academic_year]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        echo '<div class="empty-state">';
        echo '<i class="fas fa-clipboard-check"></i>';
        echo '<p>No approved action items available for compilation.</p>';
        echo '<p>All approved items may have already been included in compiled plans.</p>';
        echo '</div>';
    } else {
        echo '<div class="selected-items-list">';
        foreach ($items as $item) {
            $committee_display = str_replace('_', ' ', $item['committee_role']);
            echo '
            <div class="item-selection-card">
                <div class="item-header">
                    <label class="checkbox-label">
                        <input type="checkbox" name="selected_items[]" value="' . $item['id'] . '">
                        <strong>' . htmlspecialchars($item['title']) . '</strong>
                    </label>
                    <span class="badge priority-' . $item['priority'] . '">' . ucfirst($item['priority']) . '</span>
                </div>
                <div class="item-details">
                    <p>' . htmlspecialchars($item['description']) . '</p>
                    <div class="item-meta">
                        <span class="committee-badge">' . htmlspecialchars($committee_display) . '</span>
                        <span><i class="fas fa-money-bill-wave"></i> RWF ' . number_format($item['total_cost'], 2) . '</span>
                        <span><i class="fas fa-clock"></i> ' . htmlspecialchars($item['implementation_timeline']) . '</span>
                        <span><i class="fas fa-user"></i> ' . htmlspecialchars($item['submitted_by']) . '</span>
                    </div>
                </div>
            </div>';
        }
        echo '</div>';
    }
    
} catch (PDOException $e) {
    error_log("Get approved items error: " . $e->getMessage());
    echo '<div class="alert alert-danger">Error loading approved items: ' . $e->getMessage() . '</div>';
}
?>

<style>
.item-selection-card {
    background: var(--light-gray);
    border: 1px solid var(--medium-gray);
    border-radius: var(--border-radius);
    padding: 1rem;
    margin-bottom: 0.75rem;
    transition: var(--transition);
}

.item-selection-card:hover {
    background: var(--light-blue);
    border-color: var(--primary-blue);
}

.item-selection-card .item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.item-selection-card .checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    margin: 0;
}

.item-selection-card .item-details p {
    margin: 0.5rem 0;
    color: var(--text-dark);
    font-size: 0.9rem;
}

.item-selection-card .item-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.8rem;
    color: var(--dark-gray);
    flex-wrap: wrap;
}

.selected-items-list {
    max-height: 400px;
    overflow-y: auto;
    padding: 0.5rem;
}
</style>