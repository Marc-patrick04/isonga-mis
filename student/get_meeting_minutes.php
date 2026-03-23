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
    
    // Get meeting details and minutes
    $stmt = $pdo->prepare("
        SELECT 
            rm.*, 
            u.full_name as organizer_name,
            u.email as organizer_email,
            mm.content as minutes_content,
            mm.action_items,
            mm.resolutions,
            mm.meeting_outcome,
            mm.status as minutes_status,
            mm.created_at as minutes_created,
            mm.updated_at as minutes_updated
        FROM rep_meetings rm
        JOIN users u ON rm.organizer_id = u.id
        LEFT JOIN meeting_minutes mm ON rm.id = mm.meeting_id
        WHERE rm.id = ?
    ");
    $stmt->execute([$meeting_id]);
    $meeting_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$meeting_details) {
        echo json_encode(['success' => false, 'message' => 'Meeting not found']);
        exit();
    }
    
    // Get attendance for this meeting
    $stmt = $pdo->prepare("
        SELECT 
            rma.*,
            u.full_name,
            u.reg_number,
            d.name as department_name
        FROM rep_meeting_attendance rma
        JOIN users u ON rma.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE rma.meeting_id = ?
        ORDER BY 
            CASE WHEN rma.attendance_status = 'present' THEN 1
                 WHEN rma.attendance_status = 'late' THEN 2
                 WHEN rma.attendance_status = 'excused' THEN 3
                 ELSE 4 END,
            u.full_name
    ");
    $stmt->execute([$meeting_id]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get action items for this meeting
    $stmt = $pdo->prepare("
        SELECT 
            rmai.*,
            u.full_name as assigned_to_name
        FROM rep_meeting_action_items rmai
        LEFT JOIN users u ON rmai.assigned_to = u.id
        WHERE rmai.meeting_id = ?
        ORDER BY rmai.priority DESC, rmai.due_date ASC
    ");
    $stmt->execute([$meeting_id]);
    $action_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $html = '
    <style>
        .minutes-container {
            font-family: "Segoe UI", sans-serif;
        }
        .minutes-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        .minutes-subtitle {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e88e5;
            margin: 1.5rem 0 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
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
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        .status-completed { background: #d4edda; color: #155724; }
        .status-draft { background: #cce7ff; color: #0c5460; }
        .status-pending { background: #fff3cd; color: #856404; }
        
        .attendance-section {
            margin: 1.5rem 0;
        }
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 0.75rem;
            margin-top: 0.75rem;
        }
        .attendance-item {
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #28a745;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .attendance-item.absent { border-left-color: #dc3545; }
        .attendance-item.excused { border-left-color: #6c757d; }
        .attendance-item.late { border-left-color: #ffc107; }
        
        .action-items-section {
            margin: 1.5rem 0;
        }
        .action-item {
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #1e88e5;
        }
        .action-item.high { border-left-color: #dc3545; }
        .action-item.medium { border-left-color: #ffc107; }
        .action-item.low { border-left-color: #28a745; }
        
        .content-section {
            margin: 1.5rem 0;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 6px;
            line-height: 1.6;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            text-align: center;
        }
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
    </style>
    
    <div class="minutes-container">
        <div class="minutes-title">' . htmlspecialchars($meeting_details['title']) . ' - Meeting Minutes</div>';
    
    // Status badge
    if ($meeting_details['minutes_status']) {
        $status_class = 'status-' . $meeting_details['minutes_status'];
        $html .= '<div class="status-badge ' . $status_class . '" style="margin-bottom: 1rem;">
                    ' . ucfirst($meeting_details['minutes_status']) . ' Minutes
                  </div>';
    }
    
    $html .= '<div class="meeting-meta">
                <div class="meta-item">
                    <i class="fas fa-calendar"></i>
                    <span>Meeting Date: ' . date('M j, Y', strtotime($meeting_details['meeting_date'])) . '</span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-clock"></i>
                    <span>Time: ' . date('g:i A', strtotime($meeting_details['start_time'])) . ' - ' . date('g:i A', strtotime($meeting_details['end_time'])) . '</span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-user"></i>
                    <span>Organized by: ' . htmlspecialchars($meeting_details['organizer_name']) . '</span>
                </div>
            </div>';
    
    if (!empty($meeting_details['description'])) {
        $html .= '<div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                    <strong>Meeting Purpose:</strong><br>
                    ' . nl2br(htmlspecialchars($meeting_details['description'])) . '
                  </div>';
    }
    
    // Attendance statistics
    if (!empty($attendance)) {
        $present_count = array_filter($attendance, function($a) { return $a['attendance_status'] === 'present'; });
        $absent_count = array_filter($attendance, function($a) { return $a['attendance_status'] === 'absent'; });
        $excused_count = array_filter($attendance, function($a) { return $a['attendance_status'] === 'excused'; });
        $late_count = array_filter($attendance, function($a) { return $a['attendance_status'] === 'late'; });
        
        $html .= '<div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">' . count($attendance) . '</div>
                        <div class="stat-label">Total Invited</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">' . count($present_count) . '</div>
                        <div class="stat-label">Present</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">' . count($absent_count) . '</div>
                        <div class="stat-label">Absent</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">' . count($excused_count) . '</div>
                        <div class="stat-label">Excused</div>
                    </div>
                  </div>';
    }
    
    // Minutes content
    if (!empty($meeting_details['minutes_content'])) {
        $html .= '<h3 class="minutes-subtitle"><i class="fas fa-file-alt"></i> Meeting Summary</h3>
                  <div class="content-section">
                    ' . nl2br(htmlspecialchars($meeting_details['minutes_content'])) . '
                  </div>';
    }
    
    // Resolutions
    if (!empty($meeting_details['resolutions'])) {
        $resolutions = json_decode($meeting_details['resolutions'], true);
        if (is_array($resolutions) && !empty($resolutions)) {
            $html .= '<h3 class="minutes-subtitle"><i class="fas fa-gavel"></i> Resolutions</h3>
                      <div class="content-section">';
            foreach ($resolutions as $index => $resolution) {
                $html .= '<div style="margin-bottom: 0.75rem; padding-left: 1rem; border-left: 2px solid #28a745;">
                            <strong>Resolution ' . ($index + 1) . ':</strong> ' . htmlspecialchars($resolution) . '
                          </div>';
            }
            $html .= '</div>';
        }
    }
    
    // Action Items
    if (!empty($action_items)) {
        $html .= '<h3 class="minutes-subtitle"><i class="fas fa-tasks"></i> Action Items</h3>
                  <div class="action-items-section">';
        
        foreach ($action_items as $item) {
            $priority_class = strtolower($item['priority'] ?? 'medium');
            $html .= '<div class="action-item ' . $priority_class . '">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <strong>' . htmlspecialchars($item['title']) . '</strong>
                            <span class="status-badge" style="background: #e9ecef; color: #6c757d; font-size: 0.7rem; text-transform: uppercase;">
                                ' . ($item['priority'] ?? 'Medium') . ' Priority
                            </span>
                        </div>';
            
            if (!empty($item['description'])) {
                $html .= '<div style="margin-bottom: 0.5rem; color: #6c757d;">' . nl2br(htmlspecialchars($item['description'])) . '</div>';
            }
            
            $html .= '<div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #6c757d;">
                        <span>';
            if (!empty($item['assigned_to_name'])) {
                $html .= '<i class="fas fa-user"></i> Assigned to: ' . htmlspecialchars($item['assigned_to_name']);
            }
            $html .= '</span>
                        <span>';
            if (!empty($item['due_date'])) {
                $html .= '<i class="fas fa-calendar"></i> Due: ' . date('M j, Y', strtotime($item['due_date']));
            }
            $html .= '</span>
                      </div>';
            
            if (!empty($item['status'])) {
                $status_color = $item['status'] === 'completed' ? '#28a745' : 
                               ($item['status'] === 'in_progress' ? '#ffc107' : '#6c757d');
                $html .= '<div style="margin-top: 0.5rem;">
                            <span style="background: ' . $status_color . '; color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600;">
                                ' . ucfirst($item['status']) . '
                            </span>
                          </div>';
            }
            
            $html .= '</div>';
        }
        $html .= '</div>';
    } elseif (!empty($meeting_details['action_items'])) {
        // Fallback to JSON action items
        $action_items_json = json_decode($meeting_details['action_items'], true);
        if (is_array($action_items_json) && !empty($action_items_json)) {
            $html .= '<h3 class="minutes-subtitle"><i class="fas fa-tasks"></i> Action Items</h3>
                      <div class="content-section">';
            foreach ($action_items_json as $index => $action_item) {
                $html .= '<div style="margin-bottom: 0.75rem; padding-left: 1rem; border-left: 2px solid #1e88e5;">
                            <strong>Action ' . ($index + 1) . ':</strong> ' . htmlspecialchars($action_item) . '
                          </div>';
            }
            $html .= '</div>';
        }
    }
    
    // Meeting Outcome
    if (!empty($meeting_details['meeting_outcome'])) {
        $html .= '<h3 class="minutes-subtitle"><i class="fas fa-flag"></i> Meeting Outcome</h3>
                  <div class="content-section">
                    ' . nl2br(htmlspecialchars($meeting_details['meeting_outcome'])) . '
                  </div>';
    }
    
    // Last updated info
    if (!empty($meeting_details['minutes_updated'])) {
        $html .= '<div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e9ecef; font-size: 0.85rem; color: #6c757d;">
                    <i class="fas fa-clock"></i> Minutes last updated: ' . date('M j, Y \a\t g:i A', strtotime($meeting_details['minutes_updated'])) . '
                  </div>';
    }
    
    $html .= '</div>';
    
    echo json_encode(['success' => true, 'minutes' => $html]);
    
} catch (PDOException $e) {
    error_log("Minutes loading error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error loading minutes']);
}
?>