<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number">
            <?php echo count($report_data['rental_income'] ?? []); ?>
        </div>
        <div class="stat-label">Rental Properties</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-number amount">
            RWF <?php echo number_format(array_sum(array_column($report_data['rental_income'] ?? [], 'total_collected')), 2); ?>
        </div>
        <div class="stat-label">Total Collected</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            <?php echo array_sum(array_column($report_data['rental_income'] ?? [], 'payment_count')); ?>
        </div>
        <div class="stat-label">Total Payments</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number amount">
            RWF <?php 
            $payments = array_sum(array_column($report_data['rental_income'] ?? [], 'payment_count'));
            $total = array_sum(array_column($report_data['rental_income'] ?? [], 'total_collected'));
            echo $payments > 0 ? number_format($total / $payments, 2) : 0; 
            ?>
        </div>
        <div class="stat-label">Average Payment</div>
    </div>
</div>

<div class="content-grid">
    <!-- Rental Income by Property -->
    <div class="card">
        <div class="card-header">
            <h3>Rental Income by Property</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Property</th>
                        <th>Payment Count</th>
                        <th>Total Collected</th>
                        <th>Average Payment</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['rental_income'])): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--dark-gray);">
                                No rental income data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $total_collected = array_sum(array_column($report_data['rental_income'], 'total_collected'));
                        foreach ($report_data['rental_income'] as $income): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($income['property_name']); ?></td>
                                <td><?php echo $income['payment_count']; ?></td>
                                <td class="amount positive">RWF <?php echo number_format($income['total_collected'], 2); ?></td>
                                <td class="amount">RWF <?php echo number_format($income['avg_payment'], 2); ?></td>
                                <td>
                                    <?php echo $total_collected > 0 ? number_format(($income['total_collected'] / $total_collected) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Monthly Rental Income -->
    <div class="card">
        <div class="card-header">
            <h3>Monthly Rental Income Trend</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Rental Income</th>
                        <th>Payment Count</th>
                        <th>Average per Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['monthly_rental_income'])): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--dark-gray);">
                                No monthly rental income data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data['monthly_rental_income'] as $income): ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($income['month'] . '-01')); ?></td>
                                <td class="amount positive">RWF <?php echo number_format($income['monthly_income'], 2); ?></td>
                                <td><?php echo $income['payment_count']; ?></td>
                                <td class="amount">
                                    RWF <?php echo $income['payment_count'] > 0 ? number_format($income['monthly_income'] / $income['payment_count'], 2) : 0; ?>
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
    <canvas id="rentalIncomeChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const monthlyData = <?php echo json_encode($report_data['monthly_rental_income'] ?? []); ?>;
    
    if (monthlyData.length > 0) {
        const ctx = document.getElementById('rentalIncomeChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthlyData.map(item => {
                    const date = new Date(item.month + '-01');
                    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Monthly Rental Income',
                    data: monthlyData.map(item => item.monthly_income),
                    borderColor: '#28A745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
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