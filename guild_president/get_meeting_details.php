<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    die('Unauthorized');
}

$meeting_id = $_GET['id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            u.full_name as chairperson_name,
            u.role as chairperson_role,
            creator.full_name as created_by_name
        FROM meetings m 
        JOIN users u ON m.chairperson_id = u.id 
        JOIN users creator ON m.created_by = creator.id 
        WHERE m.id = ?
    ");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($meeting) {
        echo '
        <div class="meeting-details">
            <div class="meeting-header" style="margin-bottom: 2rem;">
                <h2 style="margin: 0 0 1rem 0; color: var(--text-dark);">' . htmlspecialchars($meeting['title']) . '</h2>
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="status-badge status-' . $meeting['status'] . '" style="margin-bottom: 0.5rem;">
                            ' . ucfirst($meeting['status']) . '
                        </div>
                        <div style="color: var(--dark-gray);">
                            <strong>Type:</strong> ' . ucfirst($meeting['meeting_type']) . '<br>
                            <strong>Location:</strong> ' . htmlspecialchars($meeting['location']) . '<br>
                            <strong>Chairperson:</strong> ' . htmlspecialchars($meeting['chairperson_name']) . '
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="meeting-datetime" style="margin-bottom: 0.5rem;">
                            <i class="fas fa-calendar"></i> ' . date('F j, Y', strtotime($meeting['meeting_date'])) . '<br>
                            <i class="fas fa-clock"></i> ' . date('g:i A', strtotime($meeting['start_time'])) . ' - ' . date('g:i A', strtotime($meeting['end_time'])) . '
                        </div>
                        <small style="color: var(--dark-gray);">
                            Created by: ' . htmlspecialchars($meeting['created_by_name']) . '<br>
                            ' . date('M j, Y g:i A', strtotime($meeting['created_at'])) . '
                        </small>
                    </div>
                </div>
            </div>';

        if (!empty($meeting['description'])) {
            echo '<div class="meeting-section">
                    <h4>Description</h4>
                    <div class="meeting-content">' . nl2br(htmlspecialchars($meeting['description'])) . '</div>
                  </div>';
        }

        // Agenda items
        $agendaStmt = $pdo->prepare("
            SELECT 
                mai.*,
                u.full_name as presenter_name
            FROM meeting_agenda_items mai 
            LEFT JOIN users u ON mai.presenter_id = u.id 
            WHERE mai.meeting_id = ? 
            ORDER BY mai.order_index
        ");
        $agendaStmt->execute([$meeting_id]);
        $agendaItems = $agendaStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($agendaItems) {
            echo '<div class="meeting-section">
                    <h4>Agenda</h4>';
            
            foreach ($agendaItems as $item) {
                echo '<div class="agenda-item">
                        <div class="agenda-item-header">
                            <strong>' . htmlspecialchars($item['title']) . '</strong>
                            <span>' . $item['duration_minutes'] . ' min</span>
                        </div>';
                
                if (!empty($item['description'])) {
                    echo '<div class="agenda-description">' . nl2br(htmlspecialchars($item['description'])) . '</div>';
                }
                
                if (!empty($item['presenter_name'])) {
                    echo '<div class="agenda-presenter"><small>Presenter: ' . htmlspecialchars($item['presenter_name']) . '</small></div>';
                }
                
                echo '</div>';
            }
            
            echo '</div>';
        }

        // Attendees
        $attendeesStmt = $pdo->prepare("
            SELECT 
                ma.*,
                u.full_name,
                u.role
            FROM meeting_attendees ma 
            JOIN users u ON ma.user_id = u.id 
            WHERE ma.meeting_id = ? 
            ORDER BY ma.is_required DESC, u.full_name
        ");
        $attendeesStmt->execute([$meeting_id]);
        $attendees = $attendeesStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($attendees) {
            echo '<div class="meeting-section">
                    <h4>Attendees (' . count($attendees) . ')</h4>
                    <div class="attendees-list">';
            
            foreach ($attendees as $attendee) {
                $statusBadge = '<span class="status-badge" style="background: #e2e3e5; color: var(--dark-gray); font-size: 0.7rem;">' . ucfirst($attendee['attendance_status']) . '</span>';
                
                echo '<div class="attendee-item">
                        <div>
                            <strong>' . htmlspecialchars($attendee['full_name']) . '</strong>
                            <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                ' . str_replace('_', ' ', $attendee['role']) . '
                                ' . ($attendee['is_required'] ? ' • Required' : ' • Optional') . '
                            </div>
                        </div>
                        ' . $statusBadge . '
                      </div>';
            }
            
            echo '</div></div>';
        }

        echo '</div>';
    } else {
        echo '<p>Meeting not found.</p>';
    }
} catch (PDOException $e) {
    echo '<p>Error loading meeting details.</p>';
}
?>