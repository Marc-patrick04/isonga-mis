<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Check if meeting ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Meeting ID is required']);
    exit();
}

$meeting_id = intval($_GET['id']);
$student_id = $_SESSION['user_id'];

try {
    // Check if user has access to this meeting
    $stmt = $pdo->prepare("
        SELECT rm.id 
        FROM rep_meetings rm
        WHERE rm.id = ?
        AND (
            rm.required_attendees IS NULL 
            OR rm.required_attendees = '' 
            OR rm.required_attendees = '[]' 
            OR rm.required_attendees = 'null'
            OR JSON_CONTAINS(rm.required_attendees, JSON_QUOTE(?))
        )
    ");
    $stmt->execute([$meeting_id, $student_id]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        echo json_encode(['success' => false, 'message' => 'Meeting not found or access denied']);
        exit();
    }
    
    // Get meeting details
    $stmt = $pdo->prepare("
        SELECT rm.*, u.full_name as organizer_name
        FROM rep_meetings rm
        JOIN users u ON rm.organizer_id = u.id
        WHERE rm.id = ?
    ");
    $stmt->execute([$meeting_id]);
    $meeting_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$meeting_details) {
        echo json_encode(['success' => false, 'message' => 'Meeting not found']);
        exit();
    }
    
    // Get agenda items
    $stmt = $pdo->prepare("
        SELECT * FROM rep_meeting_agenda_items 
        WHERE meeting_id = ? 
        ORDER BY order_index ASC
    ");
    $stmt->execute([$meeting_id]);
    $agenda_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $html = '
    <style>
        .agenda-container {
            font-family: "Segoe UI", sans-serif;
        }
        .meeting-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        .meeting-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .agenda-section {
            margin-top: 1.5rem;
        }
        .agenda-list {
            list-style: none;
            padding: 0;
        }
        .agenda-item {
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #1e88e5;
            transition: all 0.2s ease;
        }
        .agenda-item:hover {
            background: #e9ecef;
            transform: translateX(3px);
        }
        .agenda-index {
            display: inline-block;
            width: 24px;
            height: 24px;
            background: #1e88e5;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 24px;
            margin-right: 0.75rem;
            font-weight: 600;
            font-size: 0.8rem;
        }
        .agenda-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }
        .agenda-description {
            color: #6c757d;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
    
    <div class="agenda-container">
        <div class="meeting-title">' . htmlspecialchars($meeting_details['title']) . '</div>
        
        <div class="meeting-meta">
            <div class="meta-item">
                <i class="fas fa-calendar"></i>
                <span>' . date('M j, Y', strtotime($meeting_details['meeting_date'])) . '</span>
            </div>
            <div class="meta-item">
                <i class="fas fa-clock"></i>
                <span>' . date('g:i A', strtotime($meeting_details['start_time'])) . ' - ' . date('g:i A', strtotime($meeting_details['end_time'])) . '</span>
            </div>
            <div class="meta-item">
                <i class="fas fa-user"></i>
                <span>Organized by: ' . htmlspecialchars($meeting_details['organizer_name']) . '</span>
            </div>
        </div>';
    
    if (!empty($meeting_details['description'])) {
        $html .= '<div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; color: #2c3e50;">
                    <strong>Meeting Description:</strong><br>
                    ' . nl2br(htmlspecialchars($meeting_details['description'])) . '
                  </div>';
    }
    
    if (!empty($meeting_details['agenda'])) {
        $html .= '<div style="margin-bottom: 1.5rem;">
                    <strong>Agenda Overview:</strong><br>
                    ' . nl2br(htmlspecialchars($meeting_details['agenda'])) . '
                  </div>';
    }
    
    $html .= '<div class="agenda-section">
                <h3 style="margin-bottom: 1rem; color: #2c3e50; font-size: 1.1rem;">
                    <i class="fas fa-list-ol"></i> Detailed Agenda Items
                </h3>';
    
    if (empty($agenda_items)) {
        $html .= '<div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h4>No Detailed Agenda Items</h4>
                    <p>The agenda for this meeting hasn\'t been broken down into specific items yet.</p>
                  </div>';
    } else {
        $html .= '<ol class="agenda-list">';
        foreach ($agenda_items as $index => $item) {
            $html .= '<li class="agenda-item">
                        <div class="agenda-index">' . ($index + 1) . '</div>
                        <div>
                            <div class="agenda-title">' . htmlspecialchars($item['title']) . '</div>';
            if (!empty($item['description'])) {
                $html .= '<div class="agenda-description">' . nl2br(htmlspecialchars($item['description'])) . '</div>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ol>';
    }
    
    $html .= '</div></div>';
    
    echo json_encode(['success' => true, 'agenda' => $html]);
    
} catch (PDOException $e) {
    error_log("Agenda loading error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error loading agenda']);
}
?>