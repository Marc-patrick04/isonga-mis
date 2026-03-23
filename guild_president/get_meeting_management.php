<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    die('Unauthorized');
}

$meeting_id = $_GET['id'] ?? 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($meeting) {
        echo '
        <div class="management-container">
            <div class="meeting-info" style="margin-bottom: 2rem; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                <h4 style="margin: 0 0 0.5rem 0;">' . htmlspecialchars($meeting['title']) . '</h4>
                <div style="color: var(--dark-gray); font-size: 0.9rem;">
                    ' . date('F j, Y', strtotime($meeting['meeting_date'])) . ' • ' . date('g:i A', strtotime($meeting['start_time'])) . ' • ' . htmlspecialchars($meeting['location']) . '
                </div>
            </div>

            <div class="management-options">
                <div class="management-option" onclick="openUpdateStatusModal(' . $meeting['id'] . ', \'' . $meeting['status'] . '\')">
                    <i class="fas fa-sync-alt"></i>
                    <span>Update Status</span>
                </div>
                <div class="management-option" onclick="openAddAgendaModal(' . $meeting['id'] . ')">
                    <i class="fas fa-plus"></i>
                    <span>Add Agenda Item</span>
                </div>
                <div class="management-option" onclick="manageAttendees(' . $meeting['id'] . ')">
                    <i class="fas fa-users"></i>
                    <span>Manage Attendees</span>
                </div>
                <div class="management-option" onclick="viewActionItems(' . $meeting['id'] . ')">
                    <i class="fas fa-tasks"></i>
                    <span>Action Items</span>
                </div>
                <div class="management-option" onclick="uploadDocuments(' . $meeting['id'] . ')">
                    <i class="fas fa-file-upload"></i>
                    <span>Upload Documents</span>
                </div>
                <div class="management-option" onclick="sendReminders(' . $meeting['id'] . ')">
                    <i class="fas fa-bell"></i>
                    <span>Send Reminders</span>
                </div>
            </div>

            <div class="quick-actions" style="margin-top: 2rem;">
                <h5>Quick Actions</h5>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button class="btn btn-sm btn-primary" onclick="startMeeting(' . $meeting['id'] . ')">
                        <i class="fas fa-play"></i> Start Meeting
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="postponeMeeting(' . $meeting['id'] . ')">
                        <i class="fas fa-clock"></i> Postpone
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="cancelMeeting(' . $meeting['id'] . ')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="duplicateMeeting(' . $meeting['id'] . ')">
                        <i class="fas fa-copy"></i> Duplicate
                    </button>
                </div>
            </div>
        </div>';
    } else {
        echo '<p>Meeting not found.</p>';
    }
} catch (PDOException $e) {
    echo '<p>Error loading management options.</p>';
}
?>