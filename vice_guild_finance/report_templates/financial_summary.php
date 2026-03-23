<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number amount <?php echo ($report_data['total_income'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
            RWF <?php echo number_format($report_data['total_income'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Total Income</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number amount negative">
            RWF <?php echo number_format($report_data['total_expenses'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Total Expenses</div>
    </div>
    
    <div class="stat-card <?php echo ($report_data['net_cash_flow'] ?? 0) >= 0 ? 'success' : 'danger'; ?>">
        <div class="stat-number amount <?php echo ($report_data['net_cash_flow'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
            RWF <?php echo number_format($report_data['net_cash_flow'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Net Cash Flow</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number amount positive">
            RWF <?php echo number_format($report_data['bank_balance'] ?? 0, 2); ?>
        </div>
        <div class="stat-label">Bank Balance</div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card <?php echo ($report_data['budget_utilization'] ?? 0) <= 80 ? 'success' : (($report_data['budget_utilization'] ?? 0) <= 95 ? 'warning' : 'danger'); ?>">
        <div class="stat-number">
            <?php echo number_format($report_data['budget_utilization'] ?? 0, 1); ?>%
        </div>
        <div class="stat-label">Budget Utilization</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            <?php echo number_format($report_data['total_transactions'] ?? 0); ?>
        </div>
        <div class="stat-label">Total Transactions</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-number">
            <?php echo number_format($report_data['income_transactions'] ?? 0); ?>
        </div>
        <div class="stat-label">Income Transactions</div>
    </div>
    
    <div class="stat-card danger">
        <div class="stat-number">
            <?php echo number_format($report_data['expense_transactions'] ?? 0); ?>
        </div>
        <div class="stat-label">Expense Transactions</div>
    </div>
</div>

<div class="chart-container">
    <canvas id="financialSummaryChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('financialSummaryChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Income', 'Expenses', 'Net Cash Flow'],
            datasets: [{
                label: 'Amount (RWF)',
                data: [
                    <?php echo $report_data['total_income'] ?? 0; ?>,
                    <?php echo $report_data['total_expenses'] ?? 0; ?>,
                    <?php echo $report_data['net_cash_flow'] ?? 0; ?>
                ],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(220, 53, 69, 0.8)',
                    'rgba(23, 162, 184, 0.8)'
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(220, 53, 69, 1)',
                    'rgba(23, 162, 184, 1)'
                ],
                borderWidth: 1
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
});
</script>