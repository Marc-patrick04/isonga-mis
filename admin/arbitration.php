<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_admin = [];
}

// Handle Arbitration Actions
$message = '';
$error = '';

// Get committee members for assignment
try {
    $stmt = $pdo->query("
        SELECT cm.id, cm.name, cm.role, u.email 
        FROM committee_members cm
        LEFT JOIN users u ON cm.user_id = u.id
        WHERE cm.status = 'active'
        ORDER BY cm.role_order ASC, cm.name ASC
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
    error_log("Error fetching committee members: " . $e->getMessage());
}

// Handle Add Case
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Generate case number
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM arbitration_cases");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
            $case_number = 'ARB-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("
                INSERT INTO arbitration_cases (
                    case_number, title, description, case_type, complainant_name, respondent_name,
                    complainant_contact, respondent_contact, priority, status, assigned_to,
                    filing_date, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                $case_number,
                $_POST['title'],
                $_POST['description'],
                $_POST['case_type'],
                $_POST['complainant_name'],
                $_POST['respondent_name'],
                $_POST['complainant_contact'] ?? null,
                $_POST['respondent_contact'] ?? null,
                $_POST['priority'] ?? 'medium',
                'filed',
                !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
                $_POST['filing_date'] ?? date('Y-m-d'),
                $user_id
            ]);
            
            $message = "Case added successfully! Case Number: $case_number";
            header("Location: arbitration.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error adding case: " . $e->getMessage();
            error_log("Case creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Case
    elseif ($_POST['action'] === 'edit') {
        try {
            $case_id = $_POST['case_id'];
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'title', 'description', 'case_type', 'complainant_name', 'respondent_name',
                'complainant_contact', 'respondent_contact', 'priority', 'status', 'assigned_to',
                'filing_date', 'hearing_date', 'resolution_date', 'resolution_details'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $value = $_POST[$field] !== '' ? $_POST[$field] : null;
                    $params[] = $value;
                }
            }
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $case_id;
            
            $sql = "UPDATE arbitration_cases SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Case updated successfully!";
            header("Location: arbitration.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating case: " . $e->getMessage();
            error_log("Case update error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Case
    elseif ($_POST['action'] === 'delete') {
        try {
            $case_id = $_POST['case_id'];
            
            // Delete related documents first
            $stmt = $pdo->prepare("DELETE FROM arbitration_documents WHERE case_id = ?");
            $stmt->execute([$case_id]);
            
            // Delete hearings
            $stmt = $pdo->prepare("DELETE FROM arbitration_hearings WHERE case_id = ?");
            $stmt->execute([$case_id]);
            
            // Delete case notes
            $stmt = $pdo->prepare("DELETE FROM case_notes WHERE case_id = ?");
            $stmt->execute([$case_id]);
            
            // Delete case
            $stmt = $pdo->prepare("DELETE FROM arbitration_cases WHERE id = ?");
            $stmt->execute([$case_id]);
            
            $message = "Case deleted successfully!";
            header("Location: arbitration.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error deleting case: " . $e->getMessage();
            error_log("Case delete error: " . $e->getMessage());
        }
    }
    
    // Handle Add Hearing
    elseif ($_POST['action'] === 'add_hearing') {
        try {
            $case_id = $_POST['case_id'];
            $hearing_date = $_POST['hearing_date'] . ' ' . ($_POST['hearing_time'] ?? '00:00:00');
            
            $stmt = $pdo->prepare("
                INSERT INTO arbitration_hearings (
                    case_id, hearing_date, location, purpose, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'scheduled', ?, NOW())
            ");
            
            $stmt->execute([
                $case_id,
                $hearing_date,
                $_POST['location'],
                $_POST['purpose'],
                $user_id
            ]);
            
            $message = "Hearing scheduled successfully!";
            header("Location: arbitration.php?action=view&id=" . $case_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error adding hearing: " . $e->getMessage();
            error_log("Hearing creation error: " . $e->getMessage());
        }
    }
    
    // Handle Add Note
    elseif ($_POST['action'] === 'add_note') {
        try {
            $case_id = $_POST['case_id'];
            $content = trim($_POST['content']);
            $note_type = $_POST['note_type'] ?? 'general';
            $is_confidential = isset($_POST['is_confidential']) ? 1 : 0;
            
            if (empty($content)) {
                throw new Exception("Note content cannot be empty.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO case_notes (case_id, user_id, note_type, content, is_confidential, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$case_id, $user_id, $note_type, $content, $is_confidential]);
            
            $message = "Note added successfully!";
            header("Location: arbitration.php?action=view&id=" . $case_id . "&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding note: " . $e->getMessage();
            error_log("Note creation error: " . $e->getMessage());
        }
    }
    
    // Handle Upload Document
    elseif ($_POST['action'] === 'upload_document') {
        try {
            $case_id = $_POST['case_id'];
            $document_type = $_POST['document_type'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $is_confidential = isset($_POST['is_confidential']) ? 1 : 0;
            
            if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a document to upload.");
            }
            
            $upload_dir = '../assets/uploads/arbitration/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Only PDF, DOC, DOCX, JPG, PNG, and TXT files are allowed.");
            }
            
            $file_name = time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_path)) {
                $file_path = 'assets/uploads/arbitration/' . $file_name;
                
                $stmt = $pdo->prepare("
                    INSERT INTO arbitration_documents (
                        case_id, document_type, title, description, file_name, file_path,
                        file_type, file_size, confidential, uploaded_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $case_id,
                    $document_type,
                    $title,
                    $description,
                    $_FILES['document']['name'],
                    $file_path,
                    $file_extension,
                    $_FILES['document']['size'],
                    $is_confidential,
                    $user_id
                ]);
                
                $message = "Document uploaded successfully!";
                header("Location: arbitration.php?action=view&id=" . $case_id . "&msg=" . urlencode($message));
                exit();
            } else {
                throw new Exception("Failed to upload document.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error uploading document: " . $e->getMessage();
            error_log("Document upload error: " . $e->getMessage());
        }
    }
    
    // Handle Assign Case
    elseif ($_POST['action'] === 'assign') {
        try {
            $case_id = $_POST['case_id'];
            $assigned_to = $_POST['assigned_to'];
            $reason = $_POST['reason'] ?? '';
            
            $stmt = $pdo->prepare("
                UPDATE arbitration_cases 
                SET assigned_to = ?, assigned_at = NOW(), status = 'under_review', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$assigned_to, $case_id]);
            
            $message = "Case assigned successfully!";
            header("Location: arbitration.php?action=view&id=" . $case_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error assigning case: " . $e->getMessage();
            error_log("Case assignment error: " . $e->getMessage());
        }
    }
    
    // Handle Change Status
    elseif ($_POST['action'] === 'change_status') {
        try {
            $case_id = $_POST['case_id'];
            $status = $_POST['status'];
            $resolution_details = $_POST['resolution_details'] ?? null;
            
            $updateFields = ["status = ?", "updated_at = NOW()"];
            $params = [$status];
            
            if ($status === 'resolved') {
                $updateFields[] = "resolution_date = NOW()";
                if ($resolution_details) {
                    $updateFields[] = "resolution_details = ?";
                    $params[] = $resolution_details;
                }
            }
            
            $params[] = $case_id;
            
            $sql = "UPDATE arbitration_cases SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Case status updated successfully!";
            header("Location: arbitration.php?action=view&id=" . $case_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating case status: " . $e->getMessage();
            error_log("Status update error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'assign') {
                    $assigned_to = $_POST['assigned_to_bulk'];
                    if (empty($assigned_to)) {
                        throw new Exception("Please select a committee member to assign.");
                    }
                    $stmt = $pdo->prepare("UPDATE arbitration_cases SET assigned_to = ?, assigned_at = NOW(), status = 'under_review' WHERE id IN ($placeholders)");
                    $params = array_merge([$assigned_to], $selected_ids);
                    $stmt->execute($params);
                    $message = count($selected_ids) . " cases assigned.";
                } elseif ($bulk_action === 'resolve') {
                    $stmt = $pdo->prepare("UPDATE arbitration_cases SET status = 'resolved', resolution_date = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " cases resolved.";
                } elseif ($bulk_action === 'reopen') {
                    $stmt = $pdo->prepare("UPDATE arbitration_cases SET status = 'under_review', resolution_date = NULL WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " cases reopened.";
                } elseif ($bulk_action === 'dismiss') {
                    $stmt = $pdo->prepare("UPDATE arbitration_cases SET status = 'dismissed' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " cases dismissed.";
                } elseif ($bulk_action === 'delete') {
                    // Delete related data first
                    $stmt = $pdo->prepare("DELETE FROM arbitration_documents WHERE case_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    $stmt = $pdo->prepare("DELETE FROM arbitration_hearings WHERE case_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    $stmt = $pdo->prepare("DELETE FROM case_notes WHERE case_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    $stmt = $pdo->prepare("DELETE FROM arbitration_cases WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " cases deleted.";
                }
                header("Location: arbitration.php?msg=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No cases selected.";
        }
    }
}

// Handle Delete Document
if (isset($_GET['delete_document']) && isset($_GET['id'])) {
    $doc_id = $_GET['id'];
    try {
        // Get file path to delete
        $stmt = $pdo->prepare("SELECT file_path, case_id FROM arbitration_documents WHERE id = ?");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($doc['file_path'])) {
            $file_path = '../' . $doc['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM arbitration_documents WHERE id = ?");
        $stmt->execute([$doc_id]);
        
        $message = "Document deleted successfully!";
        header("Location: arbitration.php?action=view&id=" . $doc['case_id'] . "&msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting document: " . $e->getMessage();
    }
}

// Get case for viewing
$view_case = null;
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT ac.*, u_assigned.full_name as assigned_to_name, u_created.full_name as created_by_name
            FROM arbitration_cases ac
            LEFT JOIN users u_assigned ON ac.assigned_to = u_assigned.id
            LEFT JOIN users u_created ON ac.created_by = u_created.id
            WHERE ac.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $view_case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get hearings
        $stmt = $pdo->prepare("
            SELECT * FROM arbitration_hearings 
            WHERE case_id = ? 
            ORDER BY hearing_date DESC
        ");
        $stmt->execute([$_GET['id']]);
        $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get documents
        $stmt = $pdo->prepare("
            SELECT * FROM arbitration_documents 
            WHERE case_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$_GET['id']]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get notes
        $stmt = $pdo->prepare("
            SELECT cn.*, u.full_name, u.role
            FROM case_notes cn
            LEFT JOIN users u ON cn.user_id = u.id
            WHERE cn.case_id = ? 
            ORDER BY cn.created_at DESC
        ");
        $stmt->execute([$_GET['id']]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Error loading case: " . $e->getMessage();
        error_log("Case view error: " . $e->getMessage());
    }
}

// Get case for editing via AJAX
if (isset($_GET['get_case']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM arbitration_cases WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($case);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$type_filter = $_GET['type'] ?? '';
$assigned_filter = $_GET['assigned'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(case_number ILIKE ? OR title ILIKE ? OR complainant_name ILIKE ? OR respondent_name ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($priority_filter)) {
    $where_conditions[] = "priority = ?";
    $params[] = $priority_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "case_type = ?";
    $params[] = $type_filter;
}

if (!empty($assigned_filter)) {
    if ($assigned_filter === 'unassigned') {
        $where_conditions[] = "assigned_to IS NULL";
    } else {
        $where_conditions[] = "assigned_to = ?";
        $params[] = $assigned_filter;
    }
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM arbitration_cases WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_cases = $stmt->fetchColumn();
    $total_pages = ceil($total_cases / $limit);
} catch (PDOException $e) {
    $total_cases = 0;
    $total_pages = 0;
}

// Get cases with joins
try {
    $sql = "
        SELECT ac.*, u_assigned.full_name as assigned_to_name
        FROM arbitration_cases ac
        LEFT JOIN users u_assigned ON ac.assigned_to = u_assigned.id
        WHERE $where_clause
        ORDER BY 
            CASE ac.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END ASC,
            ac.filing_date DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cases = [];
    error_log("Cases fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM arbitration_cases GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM arbitration_cases GROUP BY priority");
    $priority_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT case_type, COUNT(*) as count FROM arbitration_cases GROUP BY case_type");
    $type_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases WHERE assigned_to IS NULL");
    $unassigned_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM arbitration_cases WHERE DATE(created_at) = CURRENT_DATE");
    $today_added = $stmt->fetchColumn();
} catch (PDOException $e) {
    $status_stats = [];
    $priority_stats = [];
    $type_stats = [];
    $unassigned_count = 0;
    $today_added = 0;
}

// Case types
$case_types = [
    'student_dispute' => 'Student Dispute',
    'committee_conflict' => 'Committee Conflict',
    'election_dispute' => 'Election Dispute',
    'disciplinary' => 'Disciplinary',
    'other' => 'Other'
];

$case_statuses = [
    'filed' => ['label' => 'Filed', 'color' => 'warning'],
    'under_review' => ['label' => 'Under Review', 'color' => 'info'],
    'hearing_scheduled' => ['label' => 'Hearing Scheduled', 'color' => 'primary'],
    'mediation' => ['label' => 'Mediation', 'color' => 'purple'],
    'resolved' => ['label' => 'Resolved', 'color' => 'success'],
    'dismissed' => ['label' => 'Dismissed', 'color' => 'secondary'],
    'appealed' => ['label' => 'Appealed', 'color' => 'danger']
];

$priorities = [
    'low' => ['label' => 'Low', 'color' => 'success'],
    'medium' => ['label' => 'Medium', 'color' => 'warning'],
    'high' => ['label' => 'High', 'color' => 'danger'],
    'urgent' => ['label' => 'Urgent', 'color' => 'danger']
];

$document_types = [
    'complaint' => 'Complaint',
    'response' => 'Response',
    'evidence' => 'Evidence',
    'witness_statement' => 'Witness Statement',
    'expert_report' => 'Expert Report',
    'hearing_minutes' => 'Hearing Minutes',
    'decision' => 'Decision',
    'other' => 'Other'
];

$note_types = [
    'general' => 'General Note',
    'hearing' => 'Hearing Note',
    'evidence' => 'Evidence Note',
    'decision' => 'Decision Note',
    'other' => 'Other'
];

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'cases';

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Arbitration Cases - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Light Mode (Default) */
        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --secondary: #6b7280;
            
            /* Light Mode Colors */
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        /* Dark Mode */
        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --sidebar-bg: #1f2937;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Header */
        .header {
            background: var(--header-bg);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text p {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border-color);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem 0;
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
            overflow-y: auto;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item {
            margin-bottom: 0.25rem;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover {
            background: var(--bg-primary);
            border-left-color: var(--primary);
        }

        .menu-item a.active {
            background: var(--bg-primary);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* Filters Bar */
        .filters-bar {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.8rem;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-box input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            width: 250px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Bulk Actions */
        .bulk-actions-bar {
            background: var(--card-bg);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .bulk-actions-bar select {
            padding: 0.4rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.75rem;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Cases Table */
        .cases-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .cases-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .cases-table th,
        .cases-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .cases-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .cases-table tr:hover {
            background: var(--bg-primary);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.filed { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-badge.under_review { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .status-badge.hearing_scheduled { background: rgba(0, 86, 179, 0.1); color: var(--primary); }
        .status-badge.mediation { background: rgba(139, 92, 246, 0.1); color: var(--purple); }
        .status-badge.resolved { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-badge.dismissed { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }
        .status-badge.appealed { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .priority-badge.low { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .priority-badge.medium { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .priority-badge.high { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .priority-badge.urgent { background: rgba(239, 68, 68, 0.2); color: var(--danger); font-weight: 700; }

        /* Case View Page */
        .case-view-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .case-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-primary);
        }

        .case-number {
            font-size: 0.85rem;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .case-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .case-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .case-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .case-content {
            padding: 1.5rem;
        }

        .case-section {
            margin-bottom: 1.5rem;
        }

        .case-section h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
        }

        .info-value {
            font-size: 0.9rem;
        }

        /* Documents & Hearings */
        .documents-list, .hearings-list, .notes-list {
            margin-top: 0.5rem;
        }

        .document-item, .hearing-item, .note-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .document-info, .hearing-info, .note-info {
            flex: 1;
        }

        .document-title, .hearing-title, .note-title {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .document-meta, .hearing-meta, .note-meta {
            font-size: 0.7rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .confidential-badge {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.2s;
            padding: 0.5rem;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.85rem;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        body.dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }

        body.dark-mode .alert-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary);
            background: var(--card-bg);
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                margin-left: 0;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .header-container {
                padding: 0.75rem 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .cases-table th,
            .cases-table td {
                padding: 0.5rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .case-header {
                padding: 1rem;
            }
            
            .case-content {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo-img">
                <div class="logo-text">
                    <h1>Isonga Admin</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <div class="user-area">
                <button class="theme-toggle" id="themeToggle" title="Toggle Dark/Light Mode">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_admin['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($current_admin['full_name'] ?? 'Admin'); ?></div>
                        <div class="user-role">System Administrator</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                  <li class="menu-item"><a href="representative.php" class="active"><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php" class="active"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['action']) && $_GET['action'] === 'view' && $view_case): ?>
                <!-- Case View Page -->
                <div class="page-header">
                    <h1><i class="fas fa-balance-scale"></i> Case Details</h1>
                    <a href="arbitration.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Cases
                    </a>
                </div>

                <div class="case-view-container">
                    <div class="case-header">
                        <div class="case-number"><?php echo htmlspecialchars($view_case['case_number']); ?></div>
                        <div class="case-title"><?php echo htmlspecialchars($view_case['title']); ?></div>
                        <div class="case-meta">
                            <span><span class="status-badge <?php echo $view_case['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $view_case['status'])); ?></span></span>
                            <span><span class="priority-badge <?php echo $view_case['priority']; ?>"><?php echo ucfirst($view_case['priority']); ?></span></span>
                            <span><i class="fas fa-calendar"></i> Filed: <?php echo date('M j, Y', strtotime($view_case['filing_date'])); ?></span>
                            <?php if ($view_case['assigned_to_name']): ?>
                                <span><i class="fas fa-user-check"></i> Assigned to: <?php echo htmlspecialchars($view_case['assigned_to_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="case-content">
                        <div class="case-section">
                            <h3>Description</h3>
                            <p><?php echo nl2br(htmlspecialchars($view_case['description'])); ?></p>
                        </div>

                        <div class="case-section">
                            <h3>Parties Involved</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Complainant</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_case['complainant_name']); ?></span>
                                    <?php if ($view_case['complainant_contact']): ?>
                                        <small><?php echo htmlspecialchars($view_case['complainant_contact']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Respondent</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_case['respondent_name']); ?></span>
                                    <?php if ($view_case['respondent_contact']): ?>
                                        <small><?php echo htmlspecialchars($view_case['respondent_contact']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Case Type</span>
                                    <span class="info-value"><?php echo $case_types[$view_case['case_type']] ?? ucfirst(str_replace('_', ' ', $view_case['case_type'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if ($view_case['resolution_details']): ?>
                            <div class="case-section">
                                <h3>Resolution Details</h3>
                                <div class="info-value" style="background: var(--bg-primary); padding: 0.75rem; border-radius: 8px;">
                                    <?php echo nl2br(htmlspecialchars($view_case['resolution_details'])); ?>
                                </div>
                                <?php if ($view_case['resolution_date']): ?>
                                    <small class="info-label" style="margin-top: 0.5rem; display: block;">Resolved on: <?php echo date('M j, Y', strtotime($view_case['resolution_date'])); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="case-section">
                            <h3>Hearings</h3>
                            <button class="btn btn-primary btn-sm" onclick="openAddHearingModal(<?php echo $view_case['id']; ?>)">
                                <i class="fas fa-plus"></i> Schedule Hearing
                            </button>
                            <div class="hearings-list">
                                <?php if (empty($hearings)): ?>
                                    <div class="empty-state" style="padding: 1rem;">
                                        <p>No hearings scheduled yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($hearings as $hearing): ?>
                                        <div class="hearing-item">
                                            <div class="hearing-info">
                                                <div class="hearing-title"><?php echo date('M j, Y g:i A', strtotime($hearing['hearing_date'])); ?></div>
                                                <div class="hearing-meta">
                                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($hearing['location']); ?>
                                                    <?php if ($hearing['purpose']): ?>
                                                        <span>• <?php echo htmlspecialchars($hearing['purpose']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="status-badge <?php echo $hearing['status']; ?>"><?php echo ucfirst($hearing['status']); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="case-section">
                            <h3>Documents</h3>
                            <button class="btn btn-primary btn-sm" onclick="openAddDocumentModal(<?php echo $view_case['id']; ?>)">
                                <i class="fas fa-upload"></i> Upload Document
                            </button>
                            <div class="documents-list">
                                <?php if (empty($documents)): ?>
                                    <div class="empty-state" style="padding: 1rem;">
                                        <p>No documents uploaded yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($documents as $doc): ?>
                                        <div class="document-item">
                                            <div class="document-info">
                                                <div class="document-title">
                                                    <?php echo htmlspecialchars($doc['title']); ?>
                                                    <?php if ($doc['confidential']): ?>
                                                        <span class="confidential-badge">Confidential</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="document-meta">
                                                    <i class="fas fa-file"></i> <?php echo $document_types[$doc['document_type']] ?? ucfirst($doc['document_type']); ?>
                                                    <span>• Uploaded: <?php echo date('M j, Y', strtotime($doc['created_at'])); ?></span>
                                                    <?php if ($doc['description']): ?>
                                                        <span>• <?php echo htmlspecialchars(substr($doc['description'], 0, 50)); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <a href="../<?php echo $doc['file_path']; ?>" class="btn btn-success btn-sm" target="_blank">
                                                    <i class="fas fa-download"></i> View
                                                </a>
                                                <a href="?delete_document=1&id=<?php echo $doc['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this document?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="case-section">
                            <h3>Case Notes</h3>
                            <button class="btn btn-primary btn-sm" onclick="openAddNoteModal(<?php echo $view_case['id']; ?>)">
                                <i class="fas fa-plus"></i> Add Note
                            </button>
                            <div class="notes-list">
                                <?php if (empty($notes)): ?>
                                    <div class="empty-state" style="padding: 1rem;">
                                        <p>No notes added yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notes as $note): ?>
                                        <div class="note-item">
                                            <div class="note-info">
                                                <div class="note-title">
                                                    <?php echo $note_types[$note['note_type']] ?? ucfirst($note['note_type']); ?>
                                                    <?php if ($note['is_confidential']): ?>
                                                        <span class="confidential-badge">Confidential</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="note-meta">
                                                    By <?php echo htmlspecialchars($note['full_name'] ?? 'System'); ?> • <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                                </div>
                                                <div class="note-text" style="margin-top: 0.5rem;">
                                                    <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="case-section">
                            <h3>Actions</h3>
                            <div class="action-buttons">
                                <?php if ($view_case['assigned_to'] === null): ?>
                                    <button class="btn btn-primary" onclick="openAssignModal(<?php echo $view_case['id']; ?>)">
                                        <i class="fas fa-user-check"></i> Assign
                                    </button>
                                <?php endif; ?>
                                
                                <button class="btn btn-warning" onclick="openStatusModal(<?php echo $view_case['id']; ?>, '<?php echo $view_case['status']; ?>')">
                                    <i class="fas fa-exchange-alt"></i> Change Status
                                </button>
                                
                                <button class="btn btn-primary" onclick="openEditCaseModal(<?php echo $view_case['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit Case
                                </button>
                                
                                <button class="btn btn-danger" onclick="confirmDeleteCase(<?php echo $view_case['id']; ?>, '<?php echo addslashes($view_case['title']); ?>')">
                                    <i class="fas fa-trash"></i> Delete Case
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assign Modal -->
                <div id="assignModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Assign Case</h2>
                            <button class="close-modal" onclick="closeAssignModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="case_id" id="assign_case_id" value="">
                            <div class="form-group">
                                <label>Assign To</label>
                                <select name="assigned_to" required>
                                    <option value="">Select Committee Member</option>
                                    <?php foreach ($committee_members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['name']); ?> (<?php echo htmlspecialchars($member['role']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Reason / Notes</label>
                                <textarea name="reason" rows="3" placeholder="Optional: Add notes about this assignment..."></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeAssignModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">Assign Case</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Status Modal -->
                <div id="statusModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Change Case Status</h2>
                            <button class="close-modal" onclick="closeStatusModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="case_id" id="status_case_id" value="">
                            <div class="form-group">
                                <label>New Status</label>
                                <select name="status" id="status_select" required onchange="toggleResolutionField()">
                                    <?php foreach ($case_statuses as $key => $status): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $status['label']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="resolution_field" style="display: none;">
                                <label>Resolution Details</label>
                                <textarea name="resolution_details" rows="4" placeholder="Describe how this case was resolved..."></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeStatusModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">Update Status</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Add Hearing Modal -->
                <div id="hearingModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Schedule Hearing</h2>
                            <button class="close-modal" onclick="closeHearingModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_hearing">
                            <input type="hidden" name="case_id" id="hearing_case_id" value="">
                            <div class="form-group">
                                <label>Hearing Date *</label>
                                <input type="date" name="hearing_date" required>
                            </div>
                            <div class="form-group">
                                <label>Hearing Time</label>
                                <input type="time" name="hearing_time" value="09:00">
                            </div>
                            <div class="form-group">
                                <label>Location *</label>
                                <input type="text" name="location" required placeholder="e.g., Arbitration Room, Main Hall">
                            </div>
                            <div class="form-group">
                                <label>Purpose</label>
                                <textarea name="purpose" rows="2" placeholder="Purpose of the hearing..."></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeHearingModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">Schedule Hearing</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Add Note Modal -->
                <div id="noteModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Add Case Note</h2>
                            <button class="close-modal" onclick="closeNoteModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_note">
                            <input type="hidden" name="case_id" id="note_case_id" value="">
                            <div class="form-group">
                                <label>Note Type</label>
                                <select name="note_type">
                                    <?php foreach ($note_types as $key => $type): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Content *</label>
                                <textarea name="content" rows="4" required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_confidential" value="1">
                                    Confidential Note (only visible to staff)
                                </label>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeNoteModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">Add Note</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Upload Document Modal -->
                <div id="documentModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Upload Document</h2>
                            <button class="close-modal" onclick="closeDocumentModal()">&times;</button>
                        </div>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_document">
                            <input type="hidden" name="case_id" id="document_case_id" value="">
                            <div class="form-group">
                                <label>Document Type *</label>
                                <select name="document_type" required>
                                    <?php foreach ($document_types as $key => $type): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Title *</label>
                                <input type="text" name="title" required>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label>File *</label>
                                <input type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt" required>
                                <small>Allowed: PDF, DOC, DOCX, JPG, PNG, TXT (Max 10MB)</small>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_confidential" value="1">
                                    Confidential Document (restricted access)
                                </label>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeDocumentModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">Upload Document</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Cases List Page -->
                <div class="page-header">
                    <h1><i class="fas fa-balance-scale"></i> Arbitration Cases</h1>
                    <button class="btn btn-primary" onclick="openAddCaseModal()">
                        <i class="fas fa-plus"></i> New Case
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_cases; ?></div>
                        <div class="stat-label">Total Cases</div>
                    </div>
                    <?php foreach ($status_stats as $stat): ?>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $stat['count']; ?></div>
                            <div class="stat-label"><?php echo ucfirst(str_replace('_', ' ', $stat['status'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $unassigned_count; ?></div>
                        <div class="stat-label">Unassigned</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $today_added; ?></div>
                        <div class="stat-label">Filed Today</div>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" action="" class="filters-bar">
                    <input type="hidden" name="tab" value="cases">
                    <div class="filter-group">
                        <label>Status:</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <?php foreach ($case_statuses as $key => $status): ?>
                                <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo $status['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Priority:</label>
                        <select name="priority" onchange="this.form.submit()">
                            <option value="">All Priority</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Type:</label>
                        <select name="type" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach ($case_types as $key => $type): ?>
                                <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Assigned:</label>
                        <select name="assigned" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="unassigned" <?php echo $assigned_filter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            <?php foreach ($committee_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" <?php echo $assigned_filter == $member['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search by case number, title, parties..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                        <?php if ($search || $status_filter || $priority_filter || $type_filter || $assigned_filter): ?>
                            <a href="arbitration.php" class="btn btn-sm">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Bulk Actions -->
                <form method="POST" action="" id="bulkForm">
                    <input type="hidden" name="action" value="bulk">
                    <div class="bulk-actions-bar">
                        <select name="bulk_action" id="bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="assign">Assign</option>
                            <option value="resolve">Resolve</option>
                            <option value="reopen">Reopen</option>
                            <option value="dismiss">Dismiss</option>
                            <option value="delete">Delete</option>
                        </select>
                        <select name="assigned_to_bulk" id="assigned_to_bulk" style="display: none;">
                            <option value="">Select Committee Member</option>
                            <?php foreach ($committee_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                    </div>

                    <div class="cases-table-container">
                        <table class="cases-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                    <th>Case #</th>
                                    <th>Title</th>
                                    <th>Complainant</th>
                                    <th>Respondent</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Filed Date</th>
                                    <th>Actions</th>
                                 </thead>
                            <tbody>
                                <?php if (empty($cases)): ?>
                                    <tr>
                                        <td colspan="11">
                                            <div class="empty-state">
                                                <i class="fas fa-balance-scale"></i>
                                                <h3>No cases found</h3>
                                                <p>Click "New Case" to create one.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cases as $case): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $case['id']; ?>" class="case-checkbox"></td>
                                            <td><strong><?php echo htmlspecialchars($case['case_number']); ?></strong></td>
                                            <td>
                                                <a href="arbitration.php?action=view&id=<?php echo $case['id']; ?>" style="color: var(--primary); text-decoration: none;">
                                                    <?php echo htmlspecialchars(substr($case['title'], 0, 50)); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($case['complainant_name']); ?></td>
                                            <td><?php echo htmlspecialchars($case['respondent_name']); ?></td>
                                            <td><?php echo $case_types[$case['case_type']] ?? ucfirst(str_replace('_', ' ', $case['case_type'])); ?></td>
                                            <td><span class="priority-badge <?php echo $case['priority']; ?>"><?php echo ucfirst($case['priority']); ?></span></td>
                                            <td><span class="status-badge <?php echo $case['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($case['assigned_to_name'] ?? '<span class="status-badge" style="background: rgba(107, 114, 128, 0.1);">Unassigned</span>'); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($case['filing_date'])); ?></td>
                                            <td class="action-buttons">
                                                <a href="arbitration.php?action=view&id=<?php echo $case['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="openEditCaseModal(<?php echo $case['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&type=<?php echo $type_filter; ?>&assigned=<?php echo $assigned_filter; ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&type=<?php echo $type_filter; ?>&assigned=<?php echo $assigned_filter; ?>" 
                               class="<?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&type=<?php echo $type_filter; ?>&assigned=<?php echo $assigned_filter; ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit Case Modal -->
    <div id="caseModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="caseModalTitle">Add New Case</h2>
                <button class="close-modal" onclick="closeCaseModal()">&times;</button>
            </div>
            <form method="POST" action="" id="caseForm">
                <input type="hidden" name="action" id="caseAction" value="add">
                <input type="hidden" name="case_id" id="caseId" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Case Title *</label>
                        <input type="text" name="title" id="case_title" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Description *</label>
                        <textarea name="description" id="case_description" rows="4" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Case Type</label>
                        <select name="case_type" id="case_type">
                            <?php foreach ($case_types as $key => $type): ?>
                                <option value="<?php echo $key; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" id="case_priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Complainant Name *</label>
                        <input type="text" name="complainant_name" id="case_complainant_name" required>
                    </div>
                    <div class="form-group">
                        <label>Complainant Contact</label>
                        <input type="text" name="complainant_contact" id="case_complainant_contact" placeholder="Phone or Email">
                    </div>
                    <div class="form-group">
                        <label>Respondent Name *</label>
                        <input type="text" name="respondent_name" id="case_respondent_name" required>
                    </div>
                    <div class="form-group">
                        <label>Respondent Contact</label>
                        <input type="text" name="respondent_contact" id="case_respondent_contact" placeholder="Phone or Email">
                    </div>
                    <div class="form-group">
                        <label>Filing Date</label>
                        <input type="date" name="filing_date" id="case_filing_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Assign To (Optional)</label>
                        <select name="assigned_to" id="case_assigned_to">
                            <option value="">Select Committee Member</option>
                            <?php foreach ($committee_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['name']); ?> (<?php echo htmlspecialchars($member['role']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeCaseModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Case</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        
        // Case Modal functions
        function openAddCaseModal() {
            document.getElementById('caseModalTitle').textContent = 'Add New Case';
            document.getElementById('caseAction').value = 'add';
            document.getElementById('caseId').value = '';
            document.getElementById('caseForm').reset();
            document.getElementById('case_filing_date').value = new Date().toISOString().split('T')[0];
            document.getElementById('caseModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditCaseModal(caseId) {
            fetch(`arbitration.php?get_case=1&id=${caseId}`)
                .then(response => response.json())
                .then(caseData => {
                    if (caseData.error) {
                        alert('Error loading case data');
                        return;
                    }
                    document.getElementById('caseModalTitle').textContent = 'Edit Case';
                    document.getElementById('caseAction').value = 'edit';
                    document.getElementById('caseId').value = caseData.id;
                    document.getElementById('case_title').value = caseData.title;
                    document.getElementById('case_description').value = caseData.description;
                    document.getElementById('case_type').value = caseData.case_type;
                    document.getElementById('case_priority').value = caseData.priority;
                    document.getElementById('case_complainant_name').value = caseData.complainant_name;
                    document.getElementById('case_complainant_contact').value = caseData.complainant_contact || '';
                    document.getElementById('case_respondent_name').value = caseData.respondent_name;
                    document.getElementById('case_respondent_contact').value = caseData.respondent_contact || '';
                    document.getElementById('case_filing_date').value = caseData.filing_date;
                    document.getElementById('case_assigned_to').value = caseData.assigned_to || '';
                    document.getElementById('caseModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading case data');
                });
        }
        
        function closeCaseModal() {
            document.getElementById('caseModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Assign Modal
        function openAssignModal(caseId) {
            document.getElementById('assign_case_id').value = caseId;
            document.getElementById('assignModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Status Modal
        function openStatusModal(caseId, currentStatus) {
            document.getElementById('status_case_id').value = caseId;
            document.getElementById('status_select').value = currentStatus;
            toggleResolutionField();
            document.getElementById('statusModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function toggleResolutionField() {
            const status = document.getElementById('status_select').value;
            const resolutionField = document.getElementById('resolution_field');
            if (status === 'resolved') {
                resolutionField.style.display = 'block';
            } else {
                resolutionField.style.display = 'none';
            }
        }
        
        // Hearing Modal
        function openAddHearingModal(caseId) {
            document.getElementById('hearing_case_id').value = caseId;
            document.getElementById('hearingModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeHearingModal() {
            document.getElementById('hearingModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Note Modal
        function openAddNoteModal(caseId) {
            document.getElementById('note_case_id').value = caseId;
            document.getElementById('noteModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeNoteModal() {
            document.getElementById('noteModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Document Modal
        function openAddDocumentModal(caseId) {
            document.getElementById('document_case_id').value = caseId;
            document.getElementById('documentModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeDocumentModal() {
            document.getElementById('documentModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function confirmDeleteCase(caseId, caseTitle) {
            if (confirm(`Are you sure you want to delete case "${caseTitle}"? This will also delete all hearings, documents, and notes.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="case_id" value="${caseId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.case-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        document.getElementById('bulk_action').addEventListener('change', function() {
            const assignSelect = document.getElementById('assigned_to_bulk');
            if (this.value === 'assign') {
                assignSelect.style.display = 'inline-block';
                assignSelect.required = true;
            } else {
                assignSelect.style.display = 'none';
                assignSelect.required = false;
            }
        });
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.case-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one case');
                return false;
            }
            
            if (action === 'assign') {
                const assignedTo = document.getElementById('assigned_to_bulk').value;
                if (!assignedTo) {
                    alert('Please select a committee member to assign to');
                    return false;
                }
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} case(s)?`);
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const caseModal = document.getElementById('caseModal');
            const assignModal = document.getElementById('assignModal');
            const statusModal = document.getElementById('statusModal');
            const hearingModal = document.getElementById('hearingModal');
            const noteModal = document.getElementById('noteModal');
            const documentModal = document.getElementById('documentModal');
            
            if (event.target === caseModal) closeCaseModal();
            if (event.target === assignModal) closeAssignModal();
            if (event.target === statusModal) closeStatusModal();
            if (event.target === hearingModal) closeHearingModal();
            if (event.target === noteModal) closeNoteModal();
            if (event.target === documentModal) closeDocumentModal();
        }
        
        // Prevent modal content click from bubbling
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>