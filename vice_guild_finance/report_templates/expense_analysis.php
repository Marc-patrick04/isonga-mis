<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number">
            RWF <?php echo number_format(array_sum(array_column($report_data['monthly_trends'] ?? [], 'monthly_expenses')), 2); ?>
        </div>
        <div class="stat-label">Total Period Expenses</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            <?php echo number_format(array_sum(array_column($report_data['monthly_trends'] ?? [], 'transaction_count'))); ?>
        </div>
        <div class="stat-label">Total Transactions</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            <?php echo count($report_data['monthly_trends'] ?? []); ?>
        </div>
        <div class="stat-label">Months Analyzed</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            RWF <?php 
            $monthly_trends = $report_data['monthly_trends'] ?? [];
            $total_months = count($monthly_trends);
            echo $total_months > 0 ? number_format(array_sum(array_column($monthly_trends, 'monthly_expenses')) / $total_months, 2) : 0; 
            ?>
        </div>
        <div class="stat-label">Average Monthly</div>
    </div>
</div>

<div class="content-grid">
    <!-- Monthly Expense Trends -->
    <div class="card">
        <div class="card-header">
            <h3>Monthly Expense Trends</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Total Expenses</th>
                        <th>Transactions</th>
                        <th>Average per Transaction</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['monthly_trends'])): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--dark-gray);">
                                No monthly trend data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data['monthly_trends'] as $trend): ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                                <td class="amount negative">RWF <?php echo number_format($trend['monthly_expenses'], 2); ?></td>
                                <td><?php echo $trend['transaction_count']; ?></td>
                                <td class="amount">
                                    RWF <?php echo $trend['transaction_count'] > 0 ? number_format($trend['monthly_expenses'] / $trend['transaction_count'], 2) : 0; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Expenses -->
    <div class="card">
        <div class="card-header">
            <h3>Top 20 Expenses</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Payee/Payer</th>
                        <th>Payment Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['top_expenses'])): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--dark-gray);">
                                No expense data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data['top_expenses'] as $expense): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td class="amount negative">RWF <?php echo number_format($expense['amount'], 2); ?></td>
                                <td><?php echo date('M j, Y', strtotime($expense['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($expense['payee_payer']); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $expense['payment_method'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="chart-container">
    <canvas id="expenseTrendChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const trendData = <?php echo json_encode($report_data['monthly_trends'] ?? []); ?>;
    
    if (trendData.length > 0) {
        const ctx = document.getElementById('expenseTrendChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: trendData.map(item => {
                    const date = new Date(item.month + '-01');
                    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Monthly Expenses',
                    data: trendData.map(item => item.monthly_expenses),
                    borderColor: '#DC3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
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