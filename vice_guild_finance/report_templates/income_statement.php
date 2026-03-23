<div class="stats-grid">
    <div class="stat-card success">
        <div class="stat-number amount positive">
            RWF <?php echo number_format($report_data['total_income'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Total Income</div>
    </div>
    
    <div class="stat-card danger">
        <div class="stat-number amount negative">
            RWF <?php echo number_format($report_data['total_expenses'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Total Expenses</div>
    </div>
    
    <div class="stat-card <?php echo ($report_data['net_income'] ?? 0) >= 0 ? 'success' : 'danger'; ?>">
        <div class="stat-number amount <?php echo ($report_data['net_income'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
            RWF <?php echo number_format($report_data['net_income'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Net Income</div>
    </div>
</div>

<div class="content-grid">
    <!-- Income by Category -->
    <div class="card">
        <div class="card-header">
            <h3>Income by Category</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Transactions</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['income_categories'])): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--dark-gray);">
                                No income data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data['income_categories'] as $category): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                <td class="amount positive">RWF <?php echo number_format($category['amount'], 2); ?></td>
                                <td><?php echo $category['transaction_count']; ?></td>
                                <td>
                                    <?php echo $report_data['total_income'] > 0 ? number_format(($category['amount'] / $report_data['total_income']) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Expenses by Category -->
    <div class="card">
        <div class="card-header">
            <h3>Expenses by Category</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Transactions</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['expense_categories'])): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--dark-gray);">
                                No expense data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data['expense_categories'] as $category): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                <td class="amount negative">RWF <?php echo number_format($category['amount'], 2); ?></td>
                                <td><?php echo $category['transaction_count']; ?></td>
                                <td>
                                    <?php echo $report_data['total_expenses'] > 0 ? number_format(($category['amount'] / $report_data['total_expenses']) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="chart-container">
    <canvas id="incomeExpenseChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('incomeExpenseChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Total Income', 'Total Expenses'],
            datasets: [{
                data: [
                    <?php echo $report_data['total_income'] ?? 0; ?>,
                    <?php echo $report_data['total_expenses'] ?? 0; ?>
                ],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(220, 53, 69, 0.8)'
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(220, 53, 69, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
});
</script>