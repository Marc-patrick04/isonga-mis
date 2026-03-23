<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit();
}

$plan_id = $_GET['id'] ?? null;

if (!$plan_id) {
    echo '<div class="alert alert-danger">Plan ID is required</div>';
    exit();
}

try {
    // Get plan details
    $planStmt = $pdo->prepare("
        SELECT 
            ap.*,
            u.full_name as submitted_by_name,
            u.role as submitted_by_role,
            u.department as submitted_by_department,
            reviewer.full_name as reviewed_by_name,
            (SELECT COUNT(*) FROM action_plan_items api WHERE api.action_plan_id = ap.id) as items_count,
            (SELECT SUM(api.total_cost) FROM action_plan_items api WHERE api.action_plan_id = ap.id) as total_budget
        FROM action_plans ap
        LEFT JOIN users u ON ap.submitted_by = u.id
        LEFT JOIN users reviewer ON ap.reviewed_by = reviewer.id
        WHERE ap.id = ?
    ");
    $planStmt->execute([$plan_id]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        echo '<div class="alert alert-danger">Action plan not found</div>';
        exit();
    }
    
    // Get plan items
    $itemsStmt = $pdo->prepare("
        SELECT * FROM action_plan_items 
        WHERE action_plan_id = ? 
        ORDER BY sequence_order
    ");
    $itemsStmt->execute([$plan_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<div class="plan-details">';
    
    // Plan Header
    echo '<div class="plan-header-details">';
    echo '<h4>' . htmlspecialchars($plan['title']) . '</h4>';
    echo '<div class="plan-meta-grid">';
    echo '<div class="meta-item"><strong>Committee:</strong> ' . htmlspecialchars(str_replace('_', ' ', $plan['committee_role'])) . '</div>';
    echo '<div class="meta-item"><strong>Submitted by:</strong> ' . htmlspecialchars($plan['submitted_by_name']) . '</div>';
    echo '<div class="meta-item"><strong>Academic Year:</strong> ' . htmlspecialchars($plan['academic_year']) . '</div>';
    echo '<div class="meta-item"><strong>Status:</strong> <span class="status-badge status-' . $plan['status'] . '">' . ucfirst($plan['status']) . '</span></div>';
    echo '<div class="meta-item"><strong>Total Items:</strong> ' . $plan['items_count'] . '</div>';
    echo '<div class="meta-item"><strong>Total Budget:</strong> RWF ' . number_format($plan['total_budget'], 2) . '</div>';
    echo '</div>';
    echo '</div>';
    
    // Plan Description
    if (!empty($plan['description'])) {
        echo '<div class="plan-description">';
        echo '<h5>Description</h5>';
        echo '<p>' . nl2br(htmlspecialchars($plan['description'])) . '</p>';
        echo '</div>';
    }
    
    // Review Information
    if (in_array($plan['status'], ['approved', 'rejected', 'under_review'])) {
        echo '<div class="review-info">';
        echo '<h5>Review Information</h5>';
        echo '<div class="review-details">';
        if ($plan['reviewed_by_name']) {
            echo '<p><strong>Reviewed by:</strong> ' . htmlspecialchars($plan['reviewed_by_name']) . '</p>';
        }
        if ($plan['review_date']) {
            echo '<p><strong>Review date:</strong> ' . date('F j, Y g:i A', strtotime($plan['review_date'])) . '</p>';
        }
        if (!empty($plan['review_notes'])) {
            echo '<p><strong>Review notes:</strong> ' . nl2br(htmlspecialchars($plan['review_notes'])) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }
    
    // Action Items
    echo '<div class="plan-items-section">';
    echo '<h5>Action Items (' . count($items) . ')</h5>';
    
    if (empty($items)) {
        echo '<div class="empty-state">';
        echo '<i class="fas fa-tasks"></i>';
        echo '<p>No action items found for this plan.</p>';
        echo '</div>';
    } else {
        foreach ($items as $index => $item) {
            echo '<div class="item-detail-card ' . $item['priority'] . '">';
            echo '<div class="item-header">';
            echo '<h6>' . ($index + 1) . '. ' . htmlspecialchars($item['title']) . '</h6>';
            echo '<span class="badge priority-' . $item['priority'] . '">' . ucfirst($item['priority']) . ' Priority</span>';
            echo '</div>';
            
            echo '<div class="item-content">';
            echo '<p><strong>Description:</strong> ' . nl2br(htmlspecialchars($item['description'])) . '</p>';
            
            if (!empty($item['objectives'])) {
                echo '<p><strong>Objectives:</strong> ' . nl2br(htmlspecialchars($item['objectives'])) . '</p>';
            }
            
            if (!empty($item['expected_outcomes'])) {
                echo '<p><strong>Expected Outcomes:</strong> ' . nl2br(htmlspecialchars($item['expected_outcomes'])) . '</p>';
            }
            
            echo '<div class="item-meta-grid">';
            echo '<div class="meta-item"><strong>Target Audience:</strong> ' . htmlspecialchars($item['target_audience'] ?? 'Not specified') . '</div>';
            echo '<div class="meta-item"><strong>Timeline:</strong> ' . htmlspecialchars($item['implementation_timeline'] ?? 'Not specified') . '</div>';
            echo '<div class="meta-item"><strong>Budget:</strong> RWF ' . number_format($item['total_cost'], 2) . '</div>';
            echo '<div class="meta-item"><strong>Status:</strong> <span class="badge status-' . $item['status'] . '">' . ucfirst($item['status']) . '</span></div>';
            echo '</div>';
            
            // Budget Breakdown
            if (!empty($item['budget_breakdown'])) {
                $breakdown = json_decode($item['budget_breakdown'], true);
                if ($breakdown && is_array($breakdown)) {
                    echo '<div class="budget-breakdown">';
                    echo '<strong>Budget Breakdown:</strong>';
                    echo '<ul>';
                    foreach ($breakdown as $category => $amount) {
                        echo '<li>' . htmlspecialchars($category) . ': RWF ' . number_format($amount, 2) . '</li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
    
    echo '</div>'; // Close plan-details
    
} catch (PDOException $e) {
    error_log("Get plan details error: " . $e->getMessage());
    echo '<div class="alert alert-danger">Error loading plan details: ' . $e->getMessage() . '</div>';
}
?>

<style>
.plan-details {
    max-height: 70vh;
    overflow-y: auto;
}

.plan-header-details {
    background: var(--light-blue);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
}

.plan-header-details h4 {
    margin: 0 0 1rem 0;
    color: var(--text-dark);
}

.plan-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.meta-item {
    font-size: 0.9rem;
}

.plan-description,
.review-info,
.plan-items-section {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: var(--white);
    border-radius: var(--border-radius);
    border: 1px solid var(--medium-gray);
}

.plan-description h5,
.review-info h5,
.plan-items-section h5 {
    margin: 0 0 1rem 0;
    color: var(--primary-blue);
    border-bottom: 2px solid var(--light-blue);
    padding-bottom: 0.5rem;
}

.item-detail-card {
    background: var(--light-gray);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 1rem;
    border-left: 4px solid;
}

.item-detail-card.high { border-left-color: var(--danger); }
.item-detail-card.medium { border-left-color: var(--warning); }
.item-detail-card.low { border-left-color: var(--success); }

.item-detail-card .item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.item-detail-card .item-header h6 {
    margin: 0;
    color: var(--text-dark);
    flex: 1;
}

.item-content p {
    margin-bottom: 0.75rem;
    line-height: 1.5;
}

.item-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin: 1rem 0;
    padding: 1rem;
    background: var(--white);
    border-radius: var(--border-radius);
}

.budget-breakdown {
    margin-top: 1rem;
    padding: 1rem;
    background: var(--white);
    border-radius: var(--border-radius);
    border: 1px solid var(--medium-gray);
}

.budget-breakdown ul {
    margin: 0.5rem 0 0 1.5rem;
}

.budget-breakdown li {
    margin-bottom: 0.25rem;
}

.review-details {
    background: var(--light-gray);
    padding: 1rem;
    border-radius: var(--border-radius);
}
</style>