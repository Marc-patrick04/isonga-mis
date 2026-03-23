<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number">
            RWF <?php echo number_format(array_sum(array_column($report_data['budget_comparison'] ?? [], 'budgeted_amount')), 2); ?>
        </div>
        <div class="stat-label">Total Budget</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            RWF <?php echo number_format(array_sum(array_column($report_data['budget_comparison'] ?? [], 'actual_amount')), 2); ?>
        </div>
        <div class="stat-label">Total Actual</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            RWF <?php echo number_format(array_sum(array_column($report_data['budget_comparison'] ?? [], 'variance')), 2); ?>
        </div>
        <div class="stat-label">Total Variance</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            <?php 
            $total_budget = array_sum(array_column($report_data['budget_comparison'] ?? [], 'budgeted_amount'));
            $total_actual = array_sum(array_column($report_data['budget_comparison'] ?? [], 'actual_amount'));
            echo $total_budget > 0 ? number_format(($total_actual / $total_budget) * 100, 1) : 0; 
            ?>%
        </div>
        <div class="stat-label">Overall Utilization</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Budget vs Actual Comparison</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Budgeted Amount</th>
                    <th>Actual Amount</th>
                    <th>Variance</th>
                    <th>Utilization</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_data['budget_comparison'])): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--dark-gray);">
                            No budget comparison data available
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report_data['budget_comparison'] as $comparison): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($comparison['category_name']); ?></td>
                            <td class="amount">RWF <?php echo number_format($comparison['budgeted_amount'], 2); ?></td>
                            <td class="amount negative">RWF <?php echo number_format($comparison['actual_amount'], 2); ?></td>
                            <td class="amount <?php echo $comparison['variance'] >= 0 ? 'positive' : 'negative'; ?>">
                                RWF <?php echo number_format($comparison['variance'], 2); ?>
                            </td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill 
                                        <?php echo $comparison['utilization_percentage'] < 60 ? 'progress-low' : 
                                              ($comparison['utilization_percentage'] < 85 ? 'progress-medium' : 'progress-high'); ?>"
                                        style="width: <?php echo min(100, $comparison['utilization_percentage']); ?>%">
                                    </div>
                                </div>
                                <div class="progress-text">
                                    <?php echo number_format($comparison['utilization_percentage'], 1); ?>%
                                </div>
                            </td>
                            <td>
                                <span class="status-badge 
                                    <?php echo $comparison['utilization_percentage'] < 60 ? 'status-approved' : 
                                          ($comparison['utilization_percentage'] < 85 ? 'status-under_review' : 'status-rejected'); ?>">
                                    <?php echo $comparison['utilization_percentage'] < 60 ? 'Good' : 
                                           ($comparison['utilization_percentage'] < 85 ? 'Monitor' : 'Over Budget'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="chart-container">
    <canvas id="budgetVsActualChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const budgetData = <?php echo json_encode($report_data['budget_comparison'] ?? []); ?>;
    
    if (budgetData.length > 0) {
        const ctx = document.getElementById('budgetVsActualChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: budgetData.map(item => item.category_name),
                datasets: [
                    {
                        label: 'Budgeted Amount',
                        data: budgetData.map(item => item.budgeted_amount),
                        backgroundColor: 'rgba(25, 118, 210, 0.8)',
                        borderColor: 'rgba(25, 118, 210, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Actual Amount',
                        data: budgetData.map(item => item.actual_amount),
                        backgroundColor: 'rgba(220, 53, 69, 0.8)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'RWF ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>