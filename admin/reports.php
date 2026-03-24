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

// Handle Report Actions
$message = '';
$error = '';

// Get report templates
try {
    $stmt = $pdo->query("SELECT * FROM report_templates WHERE is_active = true ORDER BY name ASC");
    $report_templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $report_templates = [];
    error_log("Error fetching report templates: " . $e->getMessage());
}

// Get committee members for report assignments
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

// Handle Add Report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $title = trim($_POST['title']);
            $report_type = $_POST['report_type'];
            $report_period = !empty($_POST['report_period']) ? $_POST['report_period'] : null;
            $activity_date = !empty($_POST['activity_date']) ? $_POST['activity_date'] : null;
            $content = json_encode([
                'summary' => $_POST['summary'] ?? '',
                'achievements' => $_POST['achievements'] ?? '',
                'challenges' => $_POST['challenges'] ?? '',
                'recommendations' => $_POST['recommendations'] ?? '',
                'budget_used' => $_POST['budget_used'] ?? null,
                'next_actions' => $_POST['next_actions'] ?? ''
            ]);
            $template_id = !empty($_POST['template_id']) ? $_POST['template_id'] : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO reports (
                    title, user_id, report_type, report_period, activity_date,
                    content, template_id, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NOW())
            ");
            
            $stmt->execute([
                $title,
                $user_id,
                $report_type,
                $report_period,
                $activity_date,
                $content,
                $template_id
            ]);
            
            $message = "Report created successfully!";
            header("Location: reports.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error creating report: " . $e->getMessage();
            error_log("Report creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Report
    elseif ($_POST['action'] === 'edit') {
        try {
            $report_id = $_POST['report_id'];
            $title = trim($_POST['title']);
            $report_period = !empty($_POST['report_period']) ? $_POST['report_period'] : null;
            $activity_date = !empty($_POST['activity_date']) ? $_POST['activity_date'] : null;
            $content = json_encode([
                'summary' => $_POST['summary'] ?? '',
                'achievements' => $_POST['achievements'] ?? '',
                'challenges' => $_POST['challenges'] ?? '',
                'recommendations' => $_POST['recommendations'] ?? '',
                'budget_used' => $_POST['budget_used'] ?? null,
                'next_actions' => $_POST['next_actions'] ?? ''
            ]);
            
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET title = ?, report_period = ?, activity_date = ?, content = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$title, $report_period, $activity_date, $content, $report_id]);
            
            $message = "Report updated successfully!";
            header("Location: reports.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating report: " . $e->getMessage();
            error_log("Report update error: " . $e->getMessage());
        }
    }
    
    // Handle Submit Report
    elseif ($_POST['action'] === 'submit') {
        try {
            $report_id = $_POST['report_id'];
            
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$report_id]);
            
            $message = "Report submitted for review!";
            header("Location: reports.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error submitting report: " . $e->getMessage();
            error_log("Report submit error: " . $e->getMessage());
        }
    }
    
    // Handle Approve Report
    elseif ($_POST['action'] === 'approve') {
        try {
            $report_id = $_POST['report_id'];
            $feedback = trim($_POST['feedback'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), feedback = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $feedback, $report_id]);
            
            $message = "Report approved successfully!";
            header("Location: reports.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error approving report: " . $e->getMessage();
            error_log("Report approve error: " . $e->getMessage());
        }
    }
    
    // Handle Reject Report
    elseif ($_POST['action'] === 'reject') {
        try {
            $report_id = $_POST['report_id'];
            $feedback = trim($_POST['feedback']);
            
            if (empty($feedback)) {
                throw new Exception("Feedback is required when rejecting a report.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), feedback = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $feedback, $report_id]);
            
            $message = "Report rejected. Feedback sent to author.";
            header("Location: reports.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error rejecting report: " . $e->getMessage();
            error_log("Report reject error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Report
    elseif ($_POST['action'] === 'delete') {
        try {
            $report_id = $_POST['report_id'];
            
            // Delete report media
            $stmt = $pdo->prepare("DELETE FROM report_media WHERE report_id = ?");
            $stmt->execute([$report_id]);
            
            // Delete report
            $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
            $stmt->execute([$report_id]);
            
            $message = "Report deleted successfully!";
            header("Location: reports.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error deleting report: " . $e->getMessage();
            error_log("Report delete error: " . $e->getMessage());
        }
    }
    
    // Handle Add Template
    elseif ($_POST['action'] === 'add_template') {
        try {
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $role_specific = $_POST['role_specific'] ?? null;
            $report_type = $_POST['report_type'];
            $fields = json_encode([
                'summary' => ['label' => 'Executive Summary', 'type' => 'textarea', 'required' => true],
                'achievements' => ['label' => 'Key Achievements', 'type' => 'textarea', 'required' => true],
                'challenges' => ['label' => 'Challenges Faced', 'type' => 'textarea', 'required' => false],
                'recommendations' => ['label' => 'Recommendations', 'type' => 'textarea', 'required' => false],
                'budget_used' => ['label' => 'Budget Used', 'type' => 'number', 'required' => false],
                'next_actions' => ['label' => 'Next Actions', 'type' => 'textarea', 'required' => false]
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO report_templates (name, description, role_specific, report_type, fields, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, true, NOW())
            ");
            $stmt->execute([$name, $description, $role_specific, $report_type, $fields]);
            
            $message = "Template added successfully!";
            header("Location: reports.php?tab=templates&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error adding template: " . $e->getMessage();
            error_log("Template creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Template
    elseif ($_POST['action'] === 'edit_template') {
        try {
            $template_id = $_POST['template_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $role_specific = $_POST['role_specific'] ?? null;
            $report_type = $_POST['report_type'];
            $is_active = isset($_POST['is_active']) ? true : false;
            
            $stmt = $pdo->prepare("
                UPDATE report_templates 
                SET name = ?, description = ?, role_specific = ?, report_type = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $role_specific, $report_type, $is_active, $template_id]);
            
            $message = "Template updated successfully!";
            header("Location: reports.php?tab=templates&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating template: " . $e->getMessage();
            error_log("Template update error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Template
    elseif ($_POST['action'] === 'delete_template') {
        try {
            $template_id = $_POST['template_id'];
            
            // Check if template has reports
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE template_id = ?");
            $stmt->execute([$template_id]);
            $report_count = $stmt->fetchColumn();
            
            if ($report_count > 0) {
                throw new Exception("Cannot delete template with $report_count associated reports.");
            }
            
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            
            $message = "Template deleted successfully!";
            header("Location: reports.php?tab=templates&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error deleting template: " . $e->getMessage();
            error_log("Template delete error: " . $e->getMessage());
        }
    }
    
    // Handle Upload Media
    elseif ($_POST['action'] === 'upload_media') {
        try {
            $report_id = $_POST['report_id'];
            
            if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a file to upload.");
            }
            
            $upload_dir = '../assets/uploads/reports/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("File type not allowed. Allowed: JPG, PNG, GIF, PDF, DOC, XLS");
            }
            
            $file_name = time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['media']['tmp_name'], $upload_path)) {
                $file_path = 'assets/uploads/reports/' . $file_name;
                
                $stmt = $pdo->prepare("
                    INSERT INTO report_media (report_id, file_name, file_path, file_type, file_size, uploaded_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $report_id,
                    $_FILES['media']['name'],
                    $file_path,
                    $file_extension,
                    $_FILES['media']['size'],
                    $user_id
                ]);
                
                $message = "File uploaded successfully!";
                header("Location: reports.php?action=view&id=" . $report_id . "&msg=" . urlencode($message));
                exit();
            } else {
                throw new Exception("Failed to upload file.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error uploading file: " . $e->getMessage();
            error_log("Media upload error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Media
    elseif ($_POST['action'] === 'delete_media') {
        try {
            $media_id = $_POST['media_id'];
            $report_id = $_POST['report_id'];
            
            // Get file path
            $stmt = $pdo->prepare("SELECT file_path FROM report_media WHERE id = ?");
            $stmt->execute([$media_id]);
            $media = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($media['file_path'])) {
                $file_path = '../' . $media['file_path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM report_media WHERE id = ?");
            $stmt->execute([$media_id]);
            
            $message = "File deleted successfully!";
            header("Location: reports.php?action=view&id=" . $report_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error deleting file: " . $e->getMessage();
            error_log("Media delete error: " . $e->getMessage());
        }
    }
}

// Get report for viewing
$view_report = null;
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, u.full_name as author_name, u.role as author_role,
                   ru.full_name as reviewer_name
            FROM reports r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users ru ON r.reviewed_by = ru.id
            WHERE r.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $view_report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($view_report && $view_report['content']) {
            $view_report['content_data'] = json_decode($view_report['content'], true);
        }
        
        // Get media
        $stmt = $pdo->prepare("
            SELECT * FROM report_media 
            WHERE report_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$_GET['id']]);
        $report_media = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Error loading report: " . $e->getMessage();
        error_log("Report view error: " . $e->getMessage());
    }
}

// Get report for editing via AJAX
if (isset($_GET['get_report']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($report && $report['content']) {
            $content = json_decode($report['content'], true);
            $report = array_merge($report, $content);
        }
        echo json_encode($report);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get template for editing via AJAX
if (isset($_GET['get_template']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($template);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering for Reports
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$author_filter = $_GET['author'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(title ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "report_type = ?";
    $params[] = $type_filter;
}

if (!empty($author_filter)) {
    $where_conditions[] = "user_id = ?";
    $params[] = $author_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM reports WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_reports = $stmt->fetchColumn();
    $total_pages = ceil($total_reports / $limit);
} catch (PDOException $e) {
    $total_reports = 0;
    $total_pages = 0;
}

// Get reports with joins
try {
    $sql = "
        SELECT r.*, u.full_name as author_name
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE $where_clause
        ORDER BY 
            CASE r.status
                WHEN 'submitted' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'reviewed' THEN 3
                WHEN 'approved' THEN 4
                WHEN 'rejected' THEN 5
            END ASC,
            r.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reports = [];
    error_log("Reports fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM reports GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT report_type, COUNT(*) as count FROM reports GROUP BY report_type");
    $type_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM reports WHERE status = 'submitted'");
    $pending_review = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM reports WHERE DATE(created_at) = CURRENT_DATE");
    $today_added = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM report_templates WHERE is_active = true");
    $total_templates = $stmt->fetchColumn();
} catch (PDOException $e) {
    $status_stats = [];
    $type_stats = [];
    $pending_review = 0;
    $today_added = 0;
    $total_templates = 0;
}

// Get all users for author filter
try {
    $stmt = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'committee') ORDER BY full_name ASC");
    $report_authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $report_authors = [];
}

// Report types
$report_types = [
    'monthly' => 'Monthly Report',
    'activity' => 'Activity Report',
    'team' => 'Team Report',
    'incident' => 'Incident Report',
    'financial' => 'Financial Report',
    'academic' => 'Academic Report'
];

$report_statuses = [
    'draft' => ['label' => 'Draft', 'color' => 'secondary'],
    'submitted' => ['label' => 'Submitted', 'color' => 'warning'],
    'reviewed' => ['label' => 'Reviewed', 'color' => 'info'],
    'approved' => ['label' => 'Approved', 'color' => 'success'],
    'rejected' => ['label' => 'Rejected', 'color' => 'danger']
];

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'reports';

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
    <title>Reports - Isonga RPSU Admin</title>
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
            --secondary: #6b7280;
            --purple: #8b5cf6;
            
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
            position: relative;
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
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

        /* Reports Table */
        .reports-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .reports-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .reports-table th,
        .reports-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .reports-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .reports-table tr:hover {
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

        .status-badge.draft { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }
        .status-badge.submitted { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-badge.reviewed { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .status-badge.approved { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        /* Report View Page */
        .report-view-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .report-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-primary);
        }

        .report-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .report-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .report-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .report-content {
            padding: 1.5rem;
        }

        .report-section {
            margin-bottom: 1.5rem;
        }

        .report-section h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .report-section p {
            font-size: 0.85rem;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        /* Feedback Section */
        .feedback-box {
            background: var(--bg-primary);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 1rem;
        }

        /* Media Gallery */
        .media-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .media-item {
            background: var(--bg-primary);
            border-radius: var(--border-radius);
            padding: 0.5rem;
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .media-item img {
            max-width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: 6px;
        }

        .media-item .file-icon {
            font-size: 3rem;
            color: var(--primary);
        }

        .media-item .file-name {
            font-size: 0.7rem;
            margin-top: 0.5rem;
            word-break: break-all;
        }

        /* Templates Table */
        .templates-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .templates-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .templates-table th,
        .templates-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .templates-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
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
            
            .reports-table th,
            .reports-table td {
                padding: 0.5rem;
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
            
            .report-header {
                padding: 1rem;
            }
            
            .report-content {
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
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> Reports</a></li>
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

            <?php if (isset($_GET['action']) && $_GET['action'] === 'view' && $view_report): ?>
                <!-- Report View Page -->
                <div class="page-header">
                    <h1><i class="fas fa-chart-bar"></i> Report Details</h1>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                </div>

                <div class="report-view-container">
                    <div class="report-header">
                        <div class="report-title"><?php echo htmlspecialchars($view_report['title']); ?></div>
                        <div class="report-meta">
                            <span><i class="fas fa-user"></i> Author: <?php echo htmlspecialchars($view_report['author_name'] ?? 'Unknown'); ?></span>
                            <span><i class="fas fa-calendar"></i> Created: <?php echo date('M j, Y g:i A', strtotime($view_report['created_at'])); ?></span>
                            <span><i class="fas fa-tag"></i> Type: <?php echo $report_types[$view_report['report_type']] ?? ucfirst($view_report['report_type']); ?></span>
                            <span><span class="status-badge <?php echo $view_report['status']; ?>"><?php echo ucfirst($view_report['status']); ?></span></span>
                            <?php if ($view_report['submitted_at']): ?>
                                <span><i class="fas fa-paper-plane"></i> Submitted: <?php echo date('M j, Y g:i A', strtotime($view_report['submitted_at'])); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="report-content">
                        <?php if ($view_report['report_period']): ?>
                            <div class="report-section">
                                <h3>Reporting Period</h3>
                                <p><?php echo date('M j, Y', strtotime($view_report['report_period'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($view_report['activity_date']): ?>
                            <div class="report-section">
                                <h3>Activity Date</h3>
                                <p><?php echo date('M j, Y', strtotime($view_report['activity_date'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($view_report['content_data']['summary'])): ?>
                            <div class="report-section">
                                <h3>Executive Summary</h3>
                                <p><?php echo nl2br(htmlspecialchars($view_report['content_data']['summary'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($view_report['content_data']['achievements'])): ?>
                            <div class="report-section">
                                <h3>Key Achievements</h3>
                                <p><?php echo nl2br(htmlspecialchars($view_report['content_data']['achievements'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($view_report['content_data']['challenges'])): ?>
                            <div class="report-section">
                                <h3>Challenges Faced</h3>
                                <p><?php echo nl2br(htmlspecialchars($view_report['content_data']['challenges'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($view_report['content_data']['recommendations'])): ?>
                            <div class="report-section">
                                <h3>Recommendations</h3>
                                <p><?php echo nl2br(htmlspecialchars($view_report['content_data']['recommendations'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($view_report['content_data']['budget_used'])): ?>
                            <div class="report-section">
                                <h3>Budget Used</h3>
                                <p>$<?php echo number_format($view_report['content_data']['budget_used'], 2); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($view_report['content_data']['next_actions'])): ?>
                            <div class="report-section">
                                <h3>Next Actions</h3>
                                <p><?php echo nl2br(htmlspecialchars($view_report['content_data']['next_actions'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($report_media)): ?>
                            <div class="report-section">
                                <h3>Attached Files</h3>
                                <div class="media-gallery">
                                    <?php foreach ($report_media as $media): ?>
                                        <div class="media-item">
                                            <?php if (in_array($media['file_type'], ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                <img src="../<?php echo htmlspecialchars($media['file_path']); ?>" alt="<?php echo htmlspecialchars($media['file_name']); ?>">
                                            <?php else: ?>
                                                <div class="file-icon">
                                                    <i class="fas fa-file-<?php echo $media['file_type']; ?>"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="file-name">
                                                <a href="../<?php echo htmlspecialchars($media['file_path']); ?>" target="_blank" class="btn btn-sm btn-primary" style="margin-top: 0.5rem;">
                                                    <i class="fas fa-download"></i> View
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($view_report['feedback']): ?>
                            <div class="report-section">
                                <h3>Reviewer Feedback</h3>
                                <div class="feedback-box">
                                    <p><?php echo nl2br(htmlspecialchars($view_report['feedback'])); ?></p>
                                    <?php if ($view_report['reviewer_name']): ?>
                                        <small class="info-label" style="margin-top: 0.5rem; display: block;">Reviewed by: <?php echo htmlspecialchars($view_report['reviewer_name']); ?> on <?php echo date('M j, Y g:i A', strtotime($view_report['reviewed_at'])); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="report-section">
                            <h3>Actions</h3>
                            <div class="action-buttons">
                                <?php if ($view_report['status'] === 'draft'): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="submit">
                                        <input type="hidden" name="report_id" value="<?php echo $view_report['id']; ?>">
                                        <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this report for review?')">
                                            <i class="fas fa-paper-plane"></i> Submit for Review
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-warning" onclick="openEditReportModal(<?php echo $view_report['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit Report
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($view_report['status'] === 'submitted'): ?>
                                    <button type="button" class="btn btn-success" onclick="openApproveModal(<?php echo $view_report['id']; ?>)">
                                        <i class="fas fa-check-circle"></i> Approve
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="openRejectModal(<?php echo $view_report['id']; ?>)">
                                        <i class="fas fa-times-circle"></i> Reject
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($view_report['status'] === 'rejected'): ?>
                                    <button type="button" class="btn btn-warning" onclick="openEditReportModal(<?php echo $view_report['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit & Resubmit
                                    </button>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-primary" onclick="openUploadMediaModal(<?php echo $view_report['id']; ?>)">
                                    <i class="fas fa-upload"></i> Upload File
                                </button>
                                
                                <button type="button" class="btn btn-danger" onclick="confirmDeleteReport(<?php echo $view_report['id']; ?>, '<?php echo addslashes($view_report['title']); ?>')">
                                    <i class="fas fa-trash"></i> Delete Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Approve Modal -->
                <div id="approveModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Approve Report</h2>
                            <button class="close-modal" onclick="closeApproveModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="report_id" id="approve_report_id" value="">
                            <div class="form-group">
                                <label>Feedback (Optional)</label>
                                <textarea name="feedback" rows="3" placeholder="Add any feedback or notes..."></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeApproveModal()">Cancel</button>
                                <button type="submit" class="btn btn-success">Approve Report</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Reject Modal -->
                <div id="rejectModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Reject Report</h2>
                            <button class="close-modal" onclick="closeRejectModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="report_id" id="reject_report_id" value="">
                            <div class="form-group">
                                <label>Feedback *</label>
                                <textarea name="feedback" rows="4" placeholder="Please provide reason for rejection..." required></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeRejectModal()">Cancel</button>
                                <button type="submit" class="btn btn-danger">Reject Report</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Upload Media Modal -->
                <div id="mediaModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Upload File</h2>
                            <button class="close-modal" onclick="closeMediaModal()">&times;</button>
                        </div>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_media">
                            <input type="hidden" name="report_id" id="media_report_id" value="">
                            <div class="form-group">
                                <label>Select File</label>
                                <input type="file" name="media" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx" required>
                                <small>Allowed: JPG, PNG, GIF, PDF, DOC, XLS (Max 10MB)</small>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeMediaModal()">Cancel</button>
                                <button type="submit" class="btn btn-primary">Upload</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Reports List Page -->
                <div class="page-header">
                    <h1><i class="fas fa-chart-bar"></i> Reports</h1>
                    <button class="btn btn-primary" onclick="openAddReportModal()">
                        <i class="fas fa-plus"></i> Create Report
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_reports; ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                    <?php foreach ($status_stats as $stat): ?>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $stat['count']; ?></div>
                            <div class="stat-label"><?php echo ucfirst($stat['status']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $pending_review; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_templates; ?></div>
                        <div class="stat-label">Templates</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn <?php echo $active_tab === 'reports' ? 'active' : ''; ?>" onclick="switchTab('reports')">
                        <i class="fas fa-list"></i> Reports
                    </button>
                    <button class="tab-btn <?php echo $active_tab === 'templates' ? 'active' : ''; ?>" onclick="switchTab('templates')">
                        <i class="fas fa-file-alt"></i> Templates
                    </button>
                </div>

                <!-- Reports Tab -->
                <div id="reportsTab" class="tab-pane <?php echo $active_tab === 'reports' ? 'active' : ''; ?>">
                    <!-- Filters -->
                    <form method="GET" action="" class="filters-bar">
                        <input type="hidden" name="tab" value="reports">
                        <div class="filter-group">
                            <label>Status:</label>
                            <select name="status" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <?php foreach ($report_statuses as $key => $status): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo $status['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Type:</label>
                            <select name="type" onchange="this.form.submit()">
                                <option value="">All Types</option>
                                <?php foreach ($report_types as $key => $type): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Author:</label>
                            <select name="author" onchange="this.form.submit()">
                                <option value="">All Authors</option>
                                <?php foreach ($report_authors as $author): ?>
                                    <option value="<?php echo $author['id']; ?>" <?php echo $author_filter == $author['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($author['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="search-box">
                            <input type="text" name="search" placeholder="Search by title..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                            <?php if ($search || $status_filter || $type_filter || $author_filter): ?>
                                <a href="reports.php?tab=reports" class="btn btn-sm">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="reports-table-container">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th>Type</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                 </thead>
                            <tbody>
                                <?php if (empty($reports)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="fas fa-chart-bar"></i>
                                                <h3>No reports found</h3>
                                                <p>Click "Create Report" to create one.</p>
                                            </div>
                                         </td>
                                     </tr>
                                <?php else: ?>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td>#<?php echo $report['id']; ?></td>
                                            <td>
                                                <a href="reports.php?action=view&id=<?php echo $report['id']; ?>" style="color: var(--primary); text-decoration: none;">
                                                    <?php echo htmlspecialchars($report['title']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['author_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo $report_types[$report['report_type']] ?? ucfirst($report['report_type']); ?></td>
                                            <td><?php echo $report['report_period'] ? date('M Y', strtotime($report['report_period'])) : '-'; ?></td>
                                            <td><span class="status-badge <?php echo $report['status']; ?>"><?php echo ucfirst($report['status']); ?></span></td>
                                            <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                                            <td class="action-buttons">
                                                <a href="reports.php?action=view&id=<?php echo $report['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($report['status'] === 'draft' || $report['status'] === 'rejected'): ?>
                                                    <button type="button" class="btn btn-warning btn-sm" onclick="openEditReportModal(<?php echo $report['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteReport(<?php echo $report['id']; ?>, '<?php echo addslashes($report['title']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page-1; ?>&tab=reports&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&type=<?php echo $type_filter; ?>&author=<?php echo $author_filter; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&tab=reports&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&type=<?php echo $type_filter; ?>&author=<?php echo $author_filter; ?>" 
                                   class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page+1; ?>&tab=reports&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&type=<?php echo $type_filter; ?>&author=<?php echo $author_filter; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Templates Tab -->
                <div id="templatesTab" class="tab-pane <?php echo $active_tab === 'templates' ? 'active' : ''; ?>">
                    <div class="page-header" style="margin-bottom: 1rem;">
                        <h2><i class="fas fa-file-alt"></i> Report Templates</h2>
                        <button class="btn btn-primary" onclick="openAddTemplateModal()">
                            <i class="fas fa-plus"></i> Add Template
                        </button>
                    </div>

                    <div class="templates-table-container">
                        <table class="templates-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Role Specific</th>
                                    <th>Report Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                 </thead>
                            <tbody>
                                <?php if (empty($report_templates)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fas fa-file-alt"></i>
                                                <h3>No templates found</h3>
                                                <p>Click "Add Template" to create one.</p>
                                            </div>
                                         </td>
                                     </tr>
                                <?php else: ?>
                                    <?php foreach ($report_templates as $template): ?>
                                        <tr>
                                            <td>#<?php echo $template['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($template['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars(substr($template['description'] ?? '', 0, 50)); ?></td>
                                            <td><?php echo htmlspecialchars($template['role_specific'] ?? 'General'); ?></td>
                                            <td><?php echo $report_types[$template['report_type']] ?? ucfirst($template['report_type']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $template['is_active'] ? 'approved' : 'draft'; ?>">
                                                    <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="action-buttons">
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openEditTemplateModal(<?php echo $template['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="reportModalTitle">Create Report</h2>
                <button class="close-modal" onclick="closeReportModal()">&times;</button>
            </div>
            <form method="POST" action="" id="reportForm">
                <input type="hidden" name="action" id="reportAction" value="add">
                <input type="hidden" name="report_id" id="reportId" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Title *</label>
                        <input type="text" name="title" id="report_title" required>
                    </div>
                    <div class="form-group">
                        <label>Report Type *</label>
                        <select name="report_type" id="report_type" required>
                            <?php foreach ($report_types as $key => $type): ?>
                                <option value="<?php echo $key; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Template (Optional)</label>
                        <select name="template_id" id="report_template_id">
                            <option value="">Select Template</option>
                            <?php foreach ($report_templates as $template): ?>
                                <option value="<?php echo $template['id']; ?>"><?php echo htmlspecialchars($template['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Report Period</label>
                        <input type="date" name="report_period" id="report_period">
                    </div>
                    <div class="form-group">
                        <label>Activity Date</label>
                        <input type="date" name="activity_date" id="report_activity_date">
                    </div>
                    <div class="form-group full-width">
                        <label>Executive Summary *</label>
                        <textarea name="summary" id="report_summary" rows="3" required></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Key Achievements</label>
                        <textarea name="achievements" id="report_achievements" rows="3"></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Challenges Faced</label>
                        <textarea name="challenges" id="report_challenges" rows="2"></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Recommendations</label>
                        <textarea name="recommendations" id="report_recommendations" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Budget Used</label>
                        <input type="number" name="budget_used" id="report_budget_used" step="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group full-width">
                        <label>Next Actions</label>
                        <textarea name="next_actions" id="report_next_actions" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeReportModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Report</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Template Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="templateModalTitle">Add Template</h2>
                <button class="close-modal" onclick="closeTemplateModal()">&times;</button>
            </div>
            <form method="POST" action="" id="templateForm">
                <input type="hidden" name="action" id="templateAction" value="add_template">
                <input type="hidden" name="template_id" id="templateId" value="">
                
                <div class="form-group">
                    <label>Template Name *</label>
                    <input type="text" name="name" id="template_name" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="template_description" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Role Specific (Optional)</label>
                    <input type="text" name="role_specific" id="template_role" placeholder="e.g., President, Secretary">
                </div>
                <div class="form-group">
                    <label>Report Type</label>
                    <select name="report_type" id="template_type" required>
                        <?php foreach ($report_types as $key => $type): ?>
                            <option value="<?php echo $key; ?>"><?php echo $type; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="template_is_active" value="1" checked>
                        Active
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeTemplateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Template</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="">
        <input type="hidden" name="report_id" id="delete_report_id" value="">
        <input type="hidden" name="template_id" id="delete_template_id" value="">
    </form>

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
        
        // Tab switching
        function switchTab(tab) {
            if (tab === 'reports') {
                window.location.href = 'reports.php?tab=reports';
            } else {
                window.location.href = 'reports.php?tab=templates';
            }
        }
        
        // Report Modal functions
        function openAddReportModal() {
            document.getElementById('reportModalTitle').textContent = 'Create Report';
            document.getElementById('reportAction').value = 'add';
            document.getElementById('reportId').value = '';
            document.getElementById('reportForm').reset();
            document.getElementById('reportModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditReportModal(reportId) {
            fetch(`reports.php?get_report=1&id=${reportId}`)
                .then(response => response.json())
                .then(report => {
                    if (report.error) {
                        alert('Error loading report data');
                        return;
                    }
                    document.getElementById('reportModalTitle').textContent = 'Edit Report';
                    document.getElementById('reportAction').value = 'edit';
                    document.getElementById('reportId').value = report.id;
                    document.getElementById('report_title').value = report.title;
                    document.getElementById('report_type').value = report.report_type;
                    document.getElementById('report_template_id').value = report.template_id || '';
                    document.getElementById('report_period').value = report.report_period || '';
                    document.getElementById('report_activity_date').value = report.activity_date || '';
                    document.getElementById('report_summary').value = report.summary || '';
                    document.getElementById('report_achievements').value = report.achievements || '';
                    document.getElementById('report_challenges').value = report.challenges || '';
                    document.getElementById('report_recommendations').value = report.recommendations || '';
                    document.getElementById('report_budget_used').value = report.budget_used || '';
                    document.getElementById('report_next_actions').value = report.next_actions || '';
                    document.getElementById('reportModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading report data');
                });
        }
        
        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Approve/Reject Modals
        function openApproveModal(reportId) {
            document.getElementById('approve_report_id').value = reportId;
            document.getElementById('approveModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeApproveModal() {
            document.getElementById('approveModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function openRejectModal(reportId) {
            document.getElementById('reject_report_id').value = reportId;
            document.getElementById('rejectModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Media Modal
        function openUploadMediaModal(reportId) {
            document.getElementById('media_report_id').value = reportId;
            document.getElementById('mediaModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeMediaModal() {
            document.getElementById('mediaModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Template Modal functions
        function openAddTemplateModal() {
            document.getElementById('templateModalTitle').textContent = 'Add Template';
            document.getElementById('templateAction').value = 'add_template';
            document.getElementById('templateId').value = '';
            document.getElementById('templateForm').reset();
            document.getElementById('template_is_active').checked = true;
            document.getElementById('templateModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditTemplateModal(templateId) {
            fetch(`reports.php?get_template=1&id=${templateId}`)
                .then(response => response.json())
                .then(template => {
                    if (template.error) {
                        alert('Error loading template data');
                        return;
                    }
                    document.getElementById('templateModalTitle').textContent = 'Edit Template';
                    document.getElementById('templateAction').value = 'edit_template';
                    document.getElementById('templateId').value = template.id;
                    document.getElementById('template_name').value = template.name;
                    document.getElementById('template_description').value = template.description || '';
                    document.getElementById('template_role').value = template.role_specific || '';
                    document.getElementById('template_type').value = template.report_type;
                    document.getElementById('template_is_active').checked = template.is_active == true;
                    document.getElementById('templateModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading template data');
                });
        }
        
        function closeTemplateModal() {
            document.getElementById('templateModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function confirmDeleteReport(reportId, reportTitle) {
            if (confirm(`Are you sure you want to delete report "${reportTitle}"? This action cannot be undone.`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete';
                form.querySelector('[name="report_id"]').value = reportId;
                form.submit();
            }
        }
        
        function confirmDeleteTemplate(templateId, templateName) {
            if (confirm(`Are you sure you want to delete template "${templateName}"?`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete_template';
                form.querySelector('[name="template_id"]').value = templateId;
                form.submit();
            }
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const reportModal = document.getElementById('reportModal');
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            const mediaModal = document.getElementById('mediaModal');
            const templateModal = document.getElementById('templateModal');
            
            if (event.target === reportModal) closeReportModal();
            if (event.target === approveModal) closeApproveModal();
            if (event.target === rejectModal) closeRejectModal();
            if (event.target === mediaModal) closeMediaModal();
            if (event.target === templateModal) closeTemplateModal();
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