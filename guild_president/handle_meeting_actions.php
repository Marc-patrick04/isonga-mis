<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'create_meeting':
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $meeting_type = $_POST['meeting_type'] ?? 'general';
            $committee_role = $_POST['committee_role'] ?? '';
            $location = $_POST['location'] ?? '';
            $meeting_date = $_POST['meeting_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $required_attendees = $_POST['required_attendees'] ?? [];
            
            if (empty($title) || empty($location) || empty($meeting_date) || empty($start_time)) {
                echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                exit();
            }
            
            // Create meeting
            $stmt = $pdo->prepare("
                INSERT INTO meetings (title, description, meeting_type, committee_role, chairperson_id, location, meeting_date, start_time, end_time, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $description, $meeting_type, $committee_role, $user_id, $location, $meeting_date, $start_time, $end_time, $user_id]);
            $meeting_id = $pdo->lastInsertId();
            
            // Add required attendees
            if (is_array($required_attendees)) {
                foreach ($required_attendees as $attendee_id) {
                    if (!empty($attendee_id)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO meeting_attendees (meeting_id, user_id, is_required) 
                            VALUES (?, ?, TRUE)
                        ");
                        $stmt->execute([$meeting_id, $attendee_id]);
                    }
                }
            }
            
            // Add agenda items
            $agenda_items = $_POST['agenda_items'] ?? [];
            if (is_array($agenda_items)) {
                $order_index = 1;
                foreach ($agenda_items as $item) {
                    if (!empty($item['title'])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO meeting_agenda_items (meeting_id, title, description, presenter_id, duration_minutes, order_index) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $meeting_id, 
                            $item['title'], 
                            $item['description'] ?? '', 
                            $item['presenter_id'] ?? null, 
                            $item['duration'] ?? 15, 
                            $order_index++
                        ]);
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Meeting scheduled successfully', 'meeting_id' => $meeting_id]);
            break;
            
        case 'update_meeting_status':
            $meeting_id = $_POST['meeting_id'] ?? '';
            $new_status = $_POST['status'] ?? '';
            $minutes = $_POST['minutes'] ?? '';
            $cancellation_notes = $_POST['cancellation_notes'] ?? '';
            
            $stmt = $pdo->prepare("UPDATE meetings SET status = ?, minutes = ? WHERE id = ?");
            $stmt->execute([$new_status, $minutes, $meeting_id]);
            
            echo json_encode(['success' => true, 'message' => 'Meeting status updated successfully']);
            break;
            
        case 'add_agenda_item':
            $meeting_id = $_POST['meeting_id'] ?? '';
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $presenter_id = $_POST['presenter_id'] ?? null;
            $duration = $_POST['duration'] ?? 15;
            
            if (empty($title)) {
                echo json_encode(['success' => false, 'message' => 'Agenda item title is required']);
                exit();
            }
            
            // Get next order index
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(order_index), 0) + 1 as next_order FROM meeting_agenda_items WHERE meeting_id = ?");
            $orderStmt->execute([$meeting_id]);
            $next_order = $orderStmt->fetch(PDO::FETCH_ASSOC)['next_order'];
            
            $stmt = $pdo->prepare("
                INSERT INTO meeting_agenda_items (meeting_id, title, description, presenter_id, duration_minutes, order_index) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$meeting_id, $title, $description, $presenter_id, $duration, $next_order]);
            
            echo json_encode(['success' => true, 'message' => 'Agenda item added successfully']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Meeting action error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>