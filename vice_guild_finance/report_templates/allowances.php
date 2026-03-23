<?php
$mission_allowances = $report_data['mission_allowances'] ?? [];
$communication_allowances = $report_data['communication_allowances'] ?? [];

$total_mission_amount = array_sum(array_column($mission_allowances, 'total_amount'));
$total_communication_amount = array_sum(array_column($communication_allowances, 'total_amount'));
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number">
            <?php echo array_sum(array_column($mission_allowances, 'count')); ?>
        </div>
        <div class="stat-label">Total Mission Requests</div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-number amount">
            RWF <?php echo number_format($total_mission_amount, 2); ?>
        </div>
        <div class="stat-label">Total Mission Allowances</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number">
            <?php echo array_sum(array_column($communication_allowances, 'count')); ?>
        </div>
        <div class="stat-label">Communication Allowances</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-number amount">
            RWF <?php echo number_format($total_communication_amount, 2); ?>
        </div>
        <div class="stat-label">Total Communication Allowances</div>
    </div>
</div>

<div class="content-grid">
    <!-- Mission Allowances -->
    <div class="card">
        <div class="card-header">
            <h3>Mission Allowances Summary</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Total Amount</th>
                        <th>Average Amount</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mission_allowances)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--dark-gray);">
                                No mission allowance data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mission_allowances as $allowance): ?>
                            <tr>
                                <td>
                                    <span class="status-badge status-<?php echo $allowance['status']; ?>">
                                        <?php echo ucfirst($allowance['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $allowance['count']; ?></td>
                                <td class="amount">RWF <?php echo number_format($allowance['total_amount'], 2); ?></td>
                                <td class="amount">RWF <?php echo number_format($allowance['avg_amount'], 2); ?></td>
                                <td>
                                    <?php echo $total_mission_amount > 0 ? number_format(($allowance['total_amount'] / $total_mission_amount) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Communication Allowances -->
    <div class="card">
        <div class="card-header">
            <h3>Communication Allowances Summary</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Total Amount</th>
                        <th>Average Amount</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($communication_allowances)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--dark-gray);">
                                No communication allowance data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($communication_allowances as $allowance): ?>
                            <tr>
                                <td>
                                    <span class="status-badge status-<?php echo $allowance['status']; ?>">
                                        <?php echo ucfirst($allowance['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $allowance['count']; ?></td>
                                <td class="amount">RWF <?php echo number_format($allowance['total_amount'], 2); ?></td>
                                <td class="amount">RWF <?php echo number_format($allowance['avg_amount'], 2); ?></td>
                                <td>
                                    <?php echo $total_communication_amount > 0 ? number_format(($allowance['total_amount'] / $total_communication_amount) * 100, 1) : 0; ?>%
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
    <canvas id="allowancesChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const missionData = <?php echo json_encode($mission_allowances); ?>;
    const commData = <?php echo json_encode($communication_allowances); ?>;
    
    if (missionData.length > 0 || commData.length > 0) {
        const ctx = document.getElementById('allowancesChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Mission Allowances', 'Communication Allowances'],
                datasets: [{
                    label: 'Total Amount (RWF)',
                    data: [
                        <?php echo $total_mission_amount; ?>,
                        <?php echo $total_communication_amount; ?>
                    ],
                    backgroundColor: [
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(25, 118, 210, 0.8)'
                    ],
                    borderColor: [
                        'rgba(255, 193, 7, 1)',
                        'rgba(25, 118, 210, 1)'
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
    }
});
</script>