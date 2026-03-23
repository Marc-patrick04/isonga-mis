<?php
$aid_summary = $report_data['aid_summary'] ?? [];
$total_requests = array_sum(array_column($aid_summary, 'request_count'));
$total_requested = array_sum(array_column($aid_summary, 'total_requested'));
$total_approved = array_sum(array_column($aid_summary, 'total_approved'));
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number">
            <?php echo $total_requests; ?>
        </div>
        <div class="stat-label">Total Requests</div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-number">
            <?php echo $aid_summary[array_search('submitted', array_column($aid_summary, 'status'))]['request_count'] ?? 0; ?>
        </div>
        <div class="stat-label">Pending Review</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-number">
            <?php echo ($aid_summary[array_search('approved', array_column($aid_summary, 'status'))]['request_count'] ?? 0) + 
                      ($aid_summary[array_search('disbursed', array_column($aid_summary, 'status'))]['request_count'] ?? 0); ?>
        </div>
        <div class="stat-label">Approved Requests</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-number amount positive">
            RWF <?php echo number_format($total_approved, 2); ?>
        </div>
        <div class="stat-label">Total Approved</div>
    </div>
</div>

<div class="content-grid">
    <!-- Aid Summary by Status -->
    <div class="card">
        <div class="card-header">
            <h3>Student Aid Summary by Status</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Request Count</th>
                        <th>Total Requested</th>
                        <th>Total Approved</th>
                        <th>Avg Requested</th>
                        <th>Avg Approved</th>
                        <th>Approval Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($aid_summary)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--dark-gray);">
                                No student aid data available
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($aid_summary as $summary): ?>
                            <tr>
                                <td>
                                    <span class="status-badge status-<?php echo $summary['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $summary['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo $summary['request_count']; ?></td>
                                <td class="amount">RWF <?php echo number_format($summary['total_requested'], 2); ?></td>
                                <td class="amount positive">RWF <?php echo number_format($summary['total_approved'], 2); ?></td>
                                <td class="amount">RWF <?php echo number_format($summary['avg_requested'], 2); ?></td>
                                <td class="amount positive">RWF <?php echo number_format($summary['avg_approved'], 2); ?></td>
                                <td>
                                    <?php echo $summary['total_requested'] > 0 ? number_format(($summary['total_approved'] / $summary['total_requested']) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Requests -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Student Aid Requests</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Request Title</th>
                        <th>Amount Requested</th>
                        <th>Amount Approved</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data['recent_requests'])): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--dark-gray);">
                                No recent student aid requests
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report_data['recent_requests'] as $request): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['student_name']); ?></strong>
                                    <br>
                                    <small style="color: var(--dark-gray);"><?php echo htmlspecialchars($request['registration_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($request['request_title']); ?></td>
                                <td class="amount">RWF <?php echo number_format($request['amount_requested'], 2); ?></td>
                                <td class="amount positive">RWF <?php echo number_format($request['amount_approved'], 2); ?></td>
                                <td>
                                    <span class="urgency-badge urgency-<?php echo $request['urgency_level']; ?>">
                                        <?php echo ucfirst($request['urgency_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="chart-container">
    <canvas id="studentAidChart"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const aidData = <?php echo json_encode($aid_summary); ?>;
    
    if (aidData.length > 0) {
        const ctx = document.getElementById('studentAidChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: aidData.map(item => item.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())),
                datasets: [{
                    data: aidData.map(item => item.request_count),
                    backgroundColor: [
                        '#FFC107', '#17A2B8', '#28A745', '#DC3545', '#6C757D'
                    ]
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
    }
});
</script>