<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Advisor Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'advisor_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$case_id = $_GET['id'] ?? null;

if (!$case_id) {
    header('Location: cases.php');
    exit();
}

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Get case details - verify it's assigned to current advisor
try {
    $stmt = $pdo->prepare("
        SELECT ac.*, 
               u.full_name as assigned_to_name,
               uc.full_name as created_by_name,
               DATEDIFF(CURDATE(), ac.filing_date) as days_open
        FROM arbitration_cases ac 
        LEFT JOIN users u ON ac.assigned_to = u.id 
        LEFT JOIN users uc ON ac.created_by = uc.id
        WHERE ac.id = ? AND ac.assigned_to = ?
    ");
    $stmt->execute([$case_id, $user_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
        $_SESSION['error_message'] = "Case not found or not assigned to you";
        header('Location: cases.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Case details error: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading case details";
    header('Location: cases.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_note':
                $content = $_POST['note_content'];
                $note_type = $_POST['note_type'];
                $is_confidential = isset($_POST['is_confidential']) ? 1 : 0;
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO case_notes (case_id, user_id, note_type, content, is_confidential) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$case_id, $user_id, $note_type, $content, $is_confidential]);
                    $_SESSION['success_message'] = "Note added successfully";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding note: " . $e->getMessage();
                }
                break;
                
            case 'update_status':
                $status = $_POST['status'];
                $resolution_details = $_POST['resolution_details'] ?? null;
                
                try {
                    $update_data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
                    $params = [$status, $case_id];
                    
                    if ($status === 'resolved' || $status === 'dismissed') {
                        $update_data['resolution_date'] = date('Y-m-d');
                        $update_data['resolution_details'] = $resolution_details;
                        $params = [$status, date('Y-m-d'), $resolution_details, $case_id];
                        $stmt = $pdo->prepare("UPDATE arbitration_cases SET status = ?, resolution_date = ?, resolution_details = ?, updated_at = NOW() WHERE id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE arbitration_cases SET status = ?, updated_at = NOW() WHERE id = ?");
                    }
                    
                    $stmt->execute($params);
                    $_SESSION['success_message'] = "Case status updated successfully";
                    $case['status'] = $status; // Update local case data
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
                }
                break;
                
            case 'upload_document':
                if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                    $document_type = $_POST['document_type'];
                    $title = $_POST['document_title'];
                    $description = $_POST['document_description'] ?? '';
                    $is_confidential = isset($_POST['is_confidential_doc']) ? 1 : 0;
                    
                    $file = $_FILES['document_file'];
                    $file_name = $file['name'];
                    $file_tmp = $file['tmp_name'];
                    $file_size = $file['size'];
                    $file_type = $file['type'];
                    
                    // Create uploads directory if it doesn't exist
                    $upload_dir = "../uploads/case_documents/";
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                    $unique_filename = uniqid() . '_' . $case_id . '.' . $file_extension;
                    $file_path = $upload_dir . $unique_filename;
                    
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO case_documents 
                            (case_id, document_type, title, description, file_name, file_path, file_type, file_size, uploaded_by, is_confidential) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $case_id, $document_type, $title, $description, 
                            $file_name, $file_path, $file_type, $file_size, 
                            $user_id, $is_confidential
                        ]);
                        $_SESSION['success_message'] = "Document uploaded successfully";
                    } else {
                        $_SESSION['error_message'] = "Error uploading file";
                    }
                } else {
                    $_SESSION['error_message'] = "Please select a valid file";
                }
                break;
        }
    }
    header("Location: case_details.php?id=" . $case_id);
    exit();
}

// Get case notes
try {
    $stmt = $pdo->prepare("
        SELECT cn.*, u.full_name as author_name 
        FROM case_notes cn 
        JOIN users u ON cn.user_id = u.id 
        WHERE cn.case_id = ? 
        ORDER BY cn.created_at DESC
    ");
    $stmt->execute([$case_id]);
    $case_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Case notes error: " . $e->getMessage());
    $case_notes = [];
}

// Get case documents
try {
    $stmt = $pdo->prepare("
        SELECT cd.*, u.full_name as uploaded_by_name 
        FROM case_documents cd 
        JOIN users u ON cd.uploaded_by = u.id 
        WHERE cd.case_id = ? 
        ORDER BY cd.created_at DESC
    ");
    $stmt->execute([$case_id]);
    $case_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Case documents error: " . $e->getMessage());
    $case_documents = [];
}

// Get case hearings
try {
    $stmt = $pdo->prepare("
        SELECT * FROM arbitration_hearings 
        WHERE case_id = ? 
        ORDER BY hearing_date DESC
    ");
    $stmt->execute([$case_id]);
    $case_hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Case hearings error: " . $e->getMessage());
    $case_hearings = [];
}

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'];
} catch (PDOException $e) {
    $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case <?php echo htmlspecialchars($case['case_number']); ?> - Arbitration Advisor - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
        }

        .dark-mode {
            --primary-blue: #1e88e5;
            --secondary-blue: #64b5f6;
            --accent-blue: #1565c0;
            --light-blue: #0d1b2a;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--text-dark);
            background: var(--light-gray);
            min-height: 100vh;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
            height: 80px;
            display: flex;
            align-items: center;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            width: 100%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            border: 3px solid var(--medium-gray);
            overflow: hidden;
            position: relative;
            transition: var(--transition);
        }

        .user-avatar:hover {
            border-color: var(--primary-blue);
            transform: scale(1.05);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            font-size: 1.1rem;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar */
        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            position: sticky;
            top: 60px;
            height: calc(100vh - 60px);
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
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--light-blue);
            border-left-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .menu-item i {
            width: 16px;
            text-align: center;
            font-size: 0.9rem;
        }

        .menu-badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 0.1rem 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
            border-color: var(--dark-gray);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Case Overview */
        .case-overview {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Case Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--dark-gray);
            font-weight: 600;
            text-transform: uppercase;
        }

        .info-value {
            font-weight: 500;
            color: var(--text-dark);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-filed {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-under_review {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-hearing_scheduled {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-mediation {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-resolved {
            background: #d4edda;
            color: var(--success);
        }

        .status-dismissed {
            background: #f8d7da;
            color: var(--danger);
        }

        .status-appealed {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .priority-low {
            background: #d4edda;
            color: var(--success);
        }

        .priority-medium {
            background: #fff3cd;
            color: var(--warning);
        }

        .priority-high {
            background: #f8d7da;
            color: var(--danger);
        }

        .priority-urgent {
            background: #dc3545;
            color: white;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
            background: var(--light-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Notes List */
        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .note-item {
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
        }

        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .note-author {
            font-weight: 600;
            color: var(--text-dark);
        }

        .note-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .note-type {
            padding: 0.1rem 0.4rem;
            background: var(--light-blue);
            color: var(--primary-blue);
            border-radius: 4px;
            font-weight: 600;
        }

        .note-confidential {
            background: var(--danger);
            color: white;
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .note-content {
            color: var(--text-dark);
            line-height: 1.5;
        }

        /* Documents List */
        .documents-list {
            display: grid;
            gap: 0.75rem;
        }

        .document-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            transition: var(--transition);
        }

        .document-item:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
        }

        .document-icon {
            width: 40px;
            height: 40px;
            background: var(--light-blue);
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-blue);
            font-size: 1.2rem;
        }

        .document-info {
            flex: 1;
        }

        .document-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .document-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        .document-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Hearings List */
        .hearings-list {
            display: grid;
            gap: 1rem;
        }

        .hearing-item {
            padding: 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
        }

        .hearing-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .hearing-date {
            font-weight: 600;
            color: var(--text-dark);
        }

        .hearing-status {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-scheduled {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .hearing-details {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        /* Forms */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 200px 1fr;
            }
            
            .case-overview {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
            }
            
            .page-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration Advisor</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
       <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php" class="active">
                        <i class="fas fa-balance-scale"></i>
                        <span>My Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hearings.php">
                        <i class="fas fa-gavel"></i>
                        <span>Hearings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="elections.php">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="meetings.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Meetings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="messages.php">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="menu-item">
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile & Settings</span>
                    </a>
                </li>
            </ul>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Case: <?php echo htmlspecialchars($case['case_number']); ?> ⚖️</h1>
                    <p><?php echo htmlspecialchars($case['title']); ?></p>
                </div>
                <div class="page-actions">
                    <a href="cases.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Cases
                    </a>
                    <button class="btn btn-primary" onclick="openStatusModal()">
                        <i class="fas fa-edit"></i> Update Status
                    </button>
                    <button class="btn btn-primary" onclick="openNoteModal()">
                        <i class="fas fa-sticky-note"></i> Add Note
                    </button>
                    <button class="btn btn-primary" onclick="openDocumentModal()">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Case Overview -->
            <div class="case-overview">
                <!-- Case Details -->
                <div class="card">
                    <div class="card-header">
                        <h3>Case Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Case Number</span>
                                <span class="info-value"><?php echo htmlspecialchars($case['case_number']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Status</span>
                                <span class="status-badge status-<?php echo $case['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Priority</span>
                                <span class="priority-badge priority-<?php echo $case['priority']; ?>">
                                    <?php echo ucfirst($case['priority']); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Case Type</span>
                                <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $case['case_type'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Days Open</span>
                                <span class="info-value"><?php echo $case['days_open']; ?> days</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Filed Date</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($case['filing_date'])); ?></span>
                            </div>
                            <?php if ($case['hearing_date']): ?>
                            <div class="info-item">
                                <span class="info-label">Hearing Date</span>
                                <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($case['hearing_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($case['resolution_date']): ?>
                            <div class="info-item">
                                <span class="info-label">Resolution Date</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($case['resolution_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <span class="info-label">Description</span>
                            <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                <?php echo nl2br(htmlspecialchars($case['description'])); ?>
                            </div>
                        </div>

                        <?php if ($case['resolution_details']): ?>
                        <div class="form-group">
                            <span class="info-label">Resolution Details</span>
                            <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                <?php echo nl2br(htmlspecialchars($case['resolution_details'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Parties Information -->
                <div class="card">
                    <div class="card-header">
                        <h3>Parties Involved</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; gap: 1.5rem;">
                            <!-- Complainant -->
                            <div>
                                <h4 style="margin-bottom: 0.5rem; color: var(--primary-blue);">Complainant</h4>
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <div style="font-weight: 600; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($case['complainant_name']); ?></div>
                                    <?php if ($case['complainant_contact']): ?>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray);"><?php echo htmlspecialchars($case['complainant_contact']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Respondent -->
                            <div>
                                <h4 style="margin-bottom: 0.5rem; color: var(--danger);">Respondent</h4>
                                <div style="padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                    <div style="font-weight: 600; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($case['respondent_name']); ?></div>
                                    <?php if ($case['respondent_contact']): ?>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray);"><?php echo htmlspecialchars($case['respondent_contact']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="openTab('notes')">Case Notes (<?php echo count($case_notes); ?>)</button>
                <button class="tab" onclick="openTab('documents')">Documents (<?php echo count($case_documents); ?>)</button>
                <button class="tab" onclick="openTab('hearings')">Hearings (<?php echo count($case_hearings); ?>)</button>
                <button class="tab" onclick="openTab('timeline')">Case Timeline</button>
            </div>

            <!-- Notes Tab -->
            <div id="notes" class="tab-content active">
                <?php if (empty($case_notes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-sticky-note"></i>
                        <h3>No Case Notes</h3>
                        <p>No notes have been added to this case yet.</p>
                        <button class="btn btn-primary" onclick="openNoteModal()">
                            <i class="fas fa-plus"></i> Add First Note
                        </button>
                    </div>
                <?php else: ?>
                    <div class="notes-list">
                        <?php foreach ($case_notes as $note): ?>
                            <div class="note-item">
                                <div class="note-header">
                                    <div class="note-author"><?php echo htmlspecialchars($note['author_name']); ?></div>
                                    <div class="note-meta">
                                        <span class="note-type"><?php echo ucfirst($note['note_type']); ?></span>
                                        <?php if ($note['is_confidential']): ?>
                                            <span class="note-confidential">CONFIDENTIAL</span>
                                        <?php endif; ?>
                                        <span><?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="note-content">
                                    <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Documents Tab -->
            <div id="documents" class="tab-content">
                <?php if (empty($case_documents)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file"></i>
                        <h3>No Documents</h3>
                        <p>No documents have been uploaded for this case yet.</p>
                        <button class="btn btn-primary" onclick="openDocumentModal()">
                            <i class="fas fa-upload"></i> Upload First Document
                        </button>
                    </div>
                <?php else: ?>
                    <div class="documents-list">
                        <?php foreach ($case_documents as $doc): ?>
                            <div class="document-item">
                                <div class="document-icon">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <div class="document-info">
                                    <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                    <div class="document-meta">
                                        <span><?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?></span>
                                        <span>Uploaded by <?php echo htmlspecialchars($doc['uploaded_by_name']); ?></span>
                                        <span><?php echo date('M j, Y', strtotime($doc['created_at'])); ?></span>
                                        <?php if ($doc['is_confidential']): ?>
                                            <span style="color: var(--danger); font-weight: 600;">CONFIDENTIAL</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($doc['description']): ?>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                            <?php echo htmlspecialchars($doc['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="document-actions">
                                    <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-outline btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hearings Tab -->
            <div id="hearings" class="tab-content">
                <?php if (empty($case_hearings)): ?>
                    <div class="empty-state">
                        <i class="fas fa-gavel"></i>
                        <h3>No Hearings</h3>
                        <p>No hearings have been scheduled for this case yet.</p>
                    </div>
                <?php else: ?>
                    <div class="hearings-list">
                        <?php foreach ($case_hearings as $hearing): ?>
                            <div class="hearing-item">
                                <div class="hearing-header">
                                    <div class="hearing-date">
                                        <?php echo date('M j, Y g:i A', strtotime($hearing['hearing_date'])); ?>
                                    </div>
                                    <span class="hearing-status status-<?php echo $hearing['status']; ?>">
                                        <?php echo ucfirst($hearing['status']); ?>
                                    </span>
                                </div>
                                <div class="hearing-details">
                                    <div><strong>Location:</strong> <?php echo htmlspecialchars($hearing['location']); ?></div>
                                    <?php if ($hearing['purpose']): ?>
                                        <div><strong>Purpose:</strong> <?php echo htmlspecialchars($hearing['purpose']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($hearing['minutes']): ?>
                                    <div style="margin-top: 0.5rem;">
                                        <strong>Minutes:</strong>
                                        <div style="padding: 0.75rem; background: var(--light-gray); border-radius: var(--border-radius); margin-top: 0.25rem;">
                                            <?php echo nl2br(htmlspecialchars($hearing['minutes'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($hearing['decisions']): ?>
                                    <div style="margin-top: 0.5rem;">
                                        <strong>Decisions:</strong>
                                        <div style="padding: 0.75rem; background: var(--light-gray); border-radius: var(--border-radius); margin-top: 0.25rem;">
                                            <?php echo nl2br(htmlspecialchars($hearing['decisions'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Timeline Tab -->
            <div id="timeline" class="tab-content">
                <div class="empty-state">
                    <i class="fas fa-stream"></i>
                    <h3>Case Timeline</h3>
                    <p>Case timeline and activity log will be displayed here.</p>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Note Modal -->
    <div id="noteModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: var(--white); margin: 5% auto; padding: 2rem; border-radius: var(--border-radius); width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <h3 style="margin-bottom: 1rem;">Add Case Note</h3>
            <form method="POST" id="noteForm">
                <input type="hidden" name="action" value="add_note">
                
                <div class="form-group">
                    <label for="note_type">Note Type</label>
                    <select id="note_type" name="note_type" class="form-control" required>
                        <option value="general">General</option>
                        <option value="hearing">Hearing</option>
                        <option value="evidence">Evidence</option>
                        <option value="decision">Decision</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="note_content">Note Content</label>
                    <textarea id="note_content" name="note_content" class="form-control" rows="8" required placeholder="Enter your detailed notes here..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="is_confidential" value="1">
                        <span>Mark as confidential (only visible to arbitration committee)</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeNoteModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Note</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="statusModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: var(--white); margin: 5% auto; padding: 2rem; border-radius: var(--border-radius); width: 90%; max-width: 500px;">
            <h3 style="margin-bottom: 1rem;">Update Case Status</h3>
            <form method="POST" id="statusForm">
                <input type="hidden" name="action" value="update_status">
                
                <div class="form-group">
                    <label for="status">New Status</label>
                    <select id="status" name="status" class="form-control" required onchange="toggleResolutionDetails()">
                        <option value="filed" <?php echo $case['status'] === 'filed' ? 'selected' : ''; ?>>Filed</option>
                        <option value="under_review" <?php echo $case['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="hearing_scheduled" <?php echo $case['status'] === 'hearing_scheduled' ? 'selected' : ''; ?>>Hearing Scheduled</option>
                        <option value="mediation" <?php echo $case['status'] === 'mediation' ? 'selected' : ''; ?>>Mediation</option>
                        <option value="resolved" <?php echo $case['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="dismissed" <?php echo $case['status'] === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                    </select>
                </div>
                
                <div class="form-group" id="resolutionDetailsGroup" style="display: none;">
                    <label for="resolution_details">Resolution Details</label>
                    <textarea id="resolution_details" name="resolution_details" class="form-control" rows="4" placeholder="Enter resolution details or dismissal reasons..."><?php echo htmlspecialchars($case['resolution_details'] ?? ''); ?></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div id="documentModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: var(--white); margin: 5% auto; padding: 2rem; border-radius: var(--border-radius); width: 90%; max-width: 600px;">
            <h3 style="margin-bottom: 1rem;">Upload Document</h3>
            <form method="POST" id="documentForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_document">
                
                <div class="form-group">
                    <label for="document_type">Document Type</label>
                    <select id="document_type" name="document_type" class="form-control" required>
                        <option value="complaint">Complaint</option>
                        <option value="response">Response</option>
                        <option value="evidence">Evidence</option>
                        <option value="witness_statement">Witness Statement</option>
                        <option value="expert_report">Expert Report</option>
                        <option value="hearing_minutes">Hearing Minutes</option>
                        <option value="decision">Decision</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="document_title">Document Title</label>
                    <input type="text" id="document_title" name="document_title" class="form-control" required placeholder="Enter document title">
                </div>
                
                <div class="form-group">
                    <label for="document_description">Description (Optional)</label>
                    <textarea id="document_description" name="document_description" class="form-control" rows="3" placeholder="Enter document description"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="document_file">Select File</label>
                    <input type="file" id="document_file" name="document_file" class="form-control" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <small style="color: var(--dark-gray);">Supported formats: PDF, DOC, DOCX, JPG, JPEG, PNG (Max: 10MB)</small>
                </div>
                
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="is_confidential_doc" value="1">
                        <span>Mark as confidential (only visible to arbitration committee)</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeDocumentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Document</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;

        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // Tab Management
        function openTab(tabName) {
            // Hide all tab contents
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }

            // Remove active class from all tabs
            const tabs = document.getElementsByClassName('tab');
            for (let i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }

            // Show the specific tab content and activate the tab
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Note Modal Functions
        function openNoteModal() {
            document.getElementById('noteModal').style.display = 'block';
        }

        function closeNoteModal() {
            document.getElementById('noteModal').style.display = 'none';
            document.getElementById('noteForm').reset();
        }

        // Status Modal Functions
        function openStatusModal() {
            document.getElementById('statusModal').style.display = 'block';
            toggleResolutionDetails(); // Initial check
        }

        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        function toggleResolutionDetails() {
            const status = document.getElementById('status').value;
            const resolutionGroup = document.getElementById('resolutionDetailsGroup');
            
            if (status === 'resolved' || status === 'dismissed') {
                resolutionGroup.style.display = 'flex';
            } else {
                resolutionGroup.style.display = 'none';
            }
        }

        // Document Modal Functions
        function openDocumentModal() {
            document.getElementById('documentModal').style.display = 'block';
        }

        function closeDocumentModal() {
            document.getElementById('documentModal').style.display = 'none';
            document.getElementById('documentForm').reset();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const noteModal = document.getElementById('noteModal');
            const statusModal = document.getElementById('statusModal');
            const documentModal = document.getElementById('documentModal');
            
            if (event.target === noteModal) {
                closeNoteModal();
            }
            if (event.target === statusModal) {
                closeStatusModal();
            }
            if (event.target === documentModal) {
                closeDocumentModal();
            }
        }

        // File upload validation
        document.getElementById('document_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const fileSize = file.size / 1024 / 1024; // MB
                if (fileSize > 10) {
                    alert('File size must be less than 10MB');
                    e.target.value = '';
                }
            }
        });
    </script>
</body>
</html>