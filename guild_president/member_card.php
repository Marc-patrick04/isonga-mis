<?php
// This file is included in committee.php for each member
?>
<div class="member-card">
    <div class="member-header">
        <div>
            <div class="member-role"><?php echo str_replace('_', ' ', $member['role']); ?></div>
        </div>
        <div>
            <span class="status-badge status-<?php echo $member['status']; ?>">
                <?php echo ucfirst($member['status']); ?>
            </span>
        </div>
    </div>
    
    <div class="member-body">
        <div class="member-avatar">
            <?php if (!empty($member['photo_url'])): ?>
                <img src="<?php echo htmlspecialchars($member['photo_url']); ?>" alt="<?php echo htmlspecialchars($member['full_name']); ?>">
            <?php else: ?>
                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        
        <div class="member-info">
            <div class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
            
            <div class="member-contact">
                <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($member['reg_number'] ?? $member['committee_reg_number'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($member['email']); ?></span>
                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($member['phone'] ?? 'N/A'); ?></span>
            </div>
            
            <?php if (!empty($member['portfolio_description'])): ?>
                <div class="portfolio-description">
                    <?php echo nl2br(htmlspecialchars($member['portfolio_description'])); ?>
                </div>
            <?php endif; ?>
            
            <div class="member-performance">
                <div class="performance-item">
                    <span class="performance-value"><?php echo $member['resolved_tickets'] ?? 0; ?></span>
                    <span class="performance-label">Resolved Tickets</span>
                </div>
                <div class="performance-item">
                    <span class="performance-value"><?php echo $member['open_tickets'] ?? 0; ?></span>
                    <span class="performance-label">Open Tickets</span>
                </div>
                <div class="performance-item">
                    <span class="performance-value"><?php echo $member['approved_reports'] ?? 0; ?></span>
                    <span class="performance-label">Approved Reports</span>
                </div>
                <div class="performance-item">
                    <span class="performance-value"><?php echo $member['pending_reports'] ?? 0; ?></span>
                    <span class="performance-label">Pending Reports</span>
                </div>
            </div>
            
            <div class="member-actions">
                <button class="btn btn-primary btn-send-message" 
                        data-id="<?php echo $member['id']; ?>" 
                        data-name="<?php echo htmlspecialchars($member['full_name']); ?>">
                    <i class="fas fa-envelope"></i> Message
                </button>
                
                <button class="btn btn-warning btn-update-status" 
                        data-id="<?php echo $member['id']; ?>" 
                        data-status="<?php echo $member['status']; ?>">
                    <i class="fas fa-user-cog"></i> Status
                </button>
                
                <button class="btn btn-info btn-update-portfolio" 
                        data-id="<?php echo $member['id']; ?>" 
                        data-portfolio="<?php echo htmlspecialchars($member['portfolio_description'] ?? ''); ?>">
                    <i class="fas fa-edit"></i> Portfolio
                </button>
                
                <?php if (!empty($member['department'])): ?>
                    <span class="btn btn-secondary">
                        <i class="fas fa-graduation-cap"></i> 
                        <?php echo htmlspecialchars($member['department']); ?>
                        <?php if (!empty($member['academic_year'])): ?>
                            - <?php echo htmlspecialchars($member['academic_year']); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>