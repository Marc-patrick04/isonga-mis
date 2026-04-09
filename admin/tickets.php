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

// Handle Ticket Actions
$message = '';
$error = '';

// Get issue categories
try {
    $stmt = $pdo->query("SELECT * FROM issue_categories ORDER BY name ASC");
    $issue_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $issue_categories = [];
    error_log("Error fetching issue categories: " . $e->getMessage());
}

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

// Handle Add Ticket (from admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, full_name, email, phone, department_id, program_id FROM users WHERE reg_number = ? AND status = 'active'");
            $stmt->execute([$_POST['reg_number']]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                throw new Exception("Student with registration number '{$_POST['reg_number']}' not found.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO tickets (
                    reg_number, name, email, phone, department_id, program_id,
                    academic_year, category_id, subject, description, priority,
                    preferred_contact, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())
            ");
            
            $stmt->execute([
                $_POST['reg_number'],
                $student['full_name'],
                $student['email'],
                $student['phone'],
                $student['department_id'],
                $student['program_id'],
                $_POST['academic_year'] ?? null,
                $_POST['category_id'] ?? null,
                $_POST['subject'],
                $_POST['description'],
                $_POST['priority'] ?? 'medium',
                $_POST['preferred_contact'] ?? 'email'
            ]);
            
            $message = "Ticket created successfully!";
            header("Location: tickets.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error creating ticket: " . $e->getMessage();
            error_log("Ticket creation error: " . $e->getMessage());
        }
    }
    
    // Handle Assign Ticket
    elseif ($_POST['action'] === 'assign') {
        try {
            $ticket_id = $_POST['ticket_id'];
            $assigned_to = $_POST['assigned_to'];
            
            // Update ticket
            $stmt = $pdo->prepare("
                UPDATE tickets 
                SET assigned_to = ?, status = 'in_progress', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$assigned_to, $ticket_id]);
            
            // Record assignment
            $stmt = $pdo->prepare("
                INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                VALUES (?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$ticket_id, $assigned_to, $user_id, $_POST['reason'] ?? 'Assigned by admin']);
            
            $message = "Ticket assigned successfully!";
            header("Location: tickets.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error assigning ticket: " . $e->getMessage();
            error_log("Ticket assignment error: " . $e->getMessage());
        }
    }
    
    // Handle Add Comment
    elseif ($_POST['action'] === 'add_comment') {
        try {
            $ticket_id = $_POST['ticket_id'];
            $comment = trim($_POST['comment']);
            $is_internal = isset($_POST['is_internal']) ? 1 : 0;
            
            if (empty($comment)) {
                throw new Exception("Comment cannot be empty.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
            
            // Update ticket updated_at
            $stmt = $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
            $stmt->execute([$ticket_id]);
            
            $message = "Comment added successfully!";
            header("Location: tickets.php?action=view&id=" . $ticket_id . "&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding comment: " . $e->getMessage();
            error_log("Comment error: " . $e->getMessage());
        }
    }
    
    // Handle Resolve Ticket
    elseif ($_POST['action'] === 'resolve') {
        try {
            $ticket_id = $_POST['ticket_id'];
            $resolution_notes = trim($_POST['resolution_notes']);
            
            if (empty($resolution_notes)) {
                throw new Exception("Resolution notes are required.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE tickets 
                SET status = 'resolved', resolution_notes = ?, resolved_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$resolution_notes, $ticket_id]);
            
            $message = "Ticket resolved successfully!";
            header("Location: tickets.php?action=view&id=" . $ticket_id . "&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error resolving ticket: " . $e->getMessage();
            error_log("Resolution error: " . $e->getMessage());
        }
    }
    
    // Handle Reopen Ticket
    elseif ($_POST['action'] === 'reopen') {
        try {
            $ticket_id = $_POST['ticket_id'];
            
            $stmt = $pdo->prepare("
                UPDATE tickets 
                SET status = 'open', resolved_at = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$ticket_id]);
            
            $message = "Ticket reopened successfully!";
            header("Location: tickets.php?action=view&id=" . $ticket_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error reopening ticket: " . $e->getMessage();
            error_log("Reopen error: " . $e->getMessage());
        }
    }
    
    // Handle Close Ticket
    elseif ($_POST['action'] === 'close') {
        try {
            $ticket_id = $_POST['ticket_id'];
            
            $stmt = $pdo->prepare("
                UPDATE tickets 
                SET status = 'closed', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$ticket_id]);
            
            $message = "Ticket closed successfully!";
            header("Location: tickets.php?action=view&id=" . $ticket_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error closing ticket: " . $e->getMessage();
            error_log("Close error: " . $e->getMessage());
        }
    }
    
    // Handle Add Category
    elseif ($_POST['action'] === 'add_category') {
        try {
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $assigned_role = $_POST['assigned_role'] ?? null;
            $sla_days = (int)($_POST['sla_days'] ?? 3);
            
            // Check if category exists
            $stmt = $pdo->prepare("SELECT id FROM issue_categories WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception("Category name already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO issue_categories (name, description, assigned_role, sla_days)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $description, $assigned_role, $sla_days]);
            
            $message = "Category added successfully!";
            header("Location: tickets.php?tab=categories&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding category: " . $e->getMessage();
            error_log("Category creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Category
    elseif ($_POST['action'] === 'edit_category') {
        try {
            $category_id = $_POST['category_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $assigned_role = $_POST['assigned_role'] ?? null;
            $sla_days = (int)($_POST['sla_days'] ?? 3);
            
            $stmt = $pdo->prepare("
                UPDATE issue_categories 
                SET name = ?, description = ?, assigned_role = ?, sla_days = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $assigned_role, $sla_days, $category_id]);
            
            $message = "Category updated successfully!";
            header("Location: tickets.php?tab=categories&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating category: " . $e->getMessage();
            error_log("Category update error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Category
    elseif ($_POST['action'] === 'delete_category') {
        try {
            $category_id = $_POST['category_id'];
            
            // Check if category has tickets
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $ticket_count = $stmt->fetchColumn();
            
            if ($ticket_count > 0) {
                throw new Exception("Cannot delete category with $ticket_count associated tickets.");
            }
            
            $stmt = $pdo->prepare("DELETE FROM issue_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            
            $message = "Category deleted successfully!";
            header("Location: tickets.php?tab=categories&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error deleting category: " . $e->getMessage();
            error_log("Category delete error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions for Tickets
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
                    $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id IN ($placeholders)");
                    $params = array_merge([$assigned_to], $selected_ids);
                    $stmt->execute($params);
                    $message = count($selected_ids) . " tickets assigned.";
                } elseif ($bulk_action === 'resolve') {
                    $stmt = $pdo->prepare("UPDATE tickets SET status = 'resolved', resolved_at = NOW(), updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " tickets resolved.";
                } elseif ($bulk_action === 'reopen') {
                    $stmt = $pdo->prepare("UPDATE tickets SET status = 'open', resolved_at = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " tickets reopened.";
                } elseif ($bulk_action === 'close') {
                    $stmt = $pdo->prepare("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " tickets closed.";
                } elseif ($bulk_action === 'delete') {
                    // Get comments to delete
                    $stmt = $pdo->prepare("DELETE FROM ticket_comments WHERE ticket_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    $stmt = $pdo->prepare("DELETE FROM ticket_assignments WHERE ticket_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    $stmt = $pdo->prepare("DELETE FROM tickets WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " tickets deleted.";
                }
                header("Location: tickets.php?msg=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No tickets selected.";
        }
    }
    
    // Handle Bulk Actions for Categories
    elseif ($_POST['action'] === 'bulk_categories') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'delete') {
                    // Check if categories have tickets
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE category_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $ticket_count = $stmt->fetchColumn();
                    
                    if ($ticket_count > 0) {
                        throw new Exception("Cannot delete categories with associated tickets.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM issue_categories WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories deleted.";
                }
                header("Location: tickets.php?tab=categories&msg=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No categories selected.";
        }
    }
}

// Get single ticket for viewing
$view_ticket = null;
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   c.name as category_name,
                   u_assigned.full_name as assigned_to_name
            FROM tickets t
            LEFT JOIN issue_categories c ON t.category_id = c.id
            LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
            WHERE t.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $view_ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get comments
        $stmt = $pdo->prepare("
            SELECT tc.*, u.full_name, u.avatar_url, u.role
            FROM ticket_comments tc
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ");
        $stmt->execute([$_GET['id']]);
        $ticket_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get assignment history
        $stmt = $pdo->prepare("
            SELECT ta.*, u_assigned.full_name as assigned_to_name, u_assigned_by.full_name as assigned_by_name
            FROM ticket_assignments ta
            LEFT JOIN users u_assigned ON ta.assigned_to = u_assigned.id
            LEFT JOIN users u_assigned_by ON ta.assigned_by = u_assigned_by.id
            WHERE ta.ticket_id = ?
            ORDER BY ta.assigned_at DESC
        ");
        $stmt->execute([$_GET['id']]);
        $assignment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Error loading ticket: " . $e->getMessage();
        error_log("Ticket view error: " . $e->getMessage());
    }
}

// Get category for editing via AJAX
if (isset($_GET['get_category']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM issue_categories WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($category);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering for Tickets List
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$category_filter = $_GET['category'] ?? '';
$assigned_filter = $_GET['assigned'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(subject ILIKE ? OR description ILIKE ? OR name ILIKE ? OR reg_number ILIKE ?)";
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

if (!empty($category_filter)) {
    $where_conditions[] = "category_id = ?";
    $params[] = $category_filter;
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
    $count_sql = "SELECT COUNT(*) FROM tickets WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_tickets = $stmt->fetchColumn();
    $total_pages = ceil($total_tickets / $limit);
} catch (PDOException $e) {
    $total_tickets = 0;
    $total_pages = 0;
}

// Get tickets with joins
try {
    $sql = "
        SELECT t.*, 
               c.name as category_name,
               u_assigned.full_name as assigned_to_name,
               (SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = t.id) as comment_count
        FROM tickets t
        LEFT JOIN issue_categories c ON t.category_id = c.id
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        WHERE $where_clause
        ORDER BY 
            CASE t.status
                WHEN 'open' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'resolved' THEN 3
                WHEN 'closed' THEN 4
            END ASC,
            t.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tickets = [];
    error_log("Tickets fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM tickets GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM tickets GROUP BY priority");
    $priority_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE assigned_to IS NULL");
    $unassigned_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets WHERE DATE(created_at) = CURRENT_DATE");
    $today_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    $status_stats = [];
    $priority_stats = [];
    $unassigned_count = 0;
    $today_count = 0;
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'tickets';

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Define priority labels and colors
$priority_labels = [
    'low' => ['label' => 'Low', 'color' => 'success'],
    'medium' => ['label' => 'Medium', 'color' => 'warning'],
    'high' => ['label' => 'High', 'color' => 'danger'],
    'urgent' => ['label' => 'Urgent', 'color' => 'danger']
];

$status_labels = [
    'open' => ['label' => 'Open', 'color' => 'warning'],
    'in_progress' => ['label' => 'In Progress', 'color' => 'info'],
    'resolved' => ['label' => 'Resolved', 'color' => 'success'],
    'closed' => ['label' => 'Closed', 'color' => 'secondary']
];

$preferred_contact_labels = [
    'email' => 'Email',
    'sms' => 'SMS',
    'phone' => 'Phone'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Support Tickets - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* [Keep all the CSS from the previous version - it remains the same] */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --secondary: #6b7280;
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

        .btn-secondary {
            background: var(--secondary);
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

        /* Tickets Table */
        .tickets-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .tickets-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .tickets-table th,
        .tickets-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .tickets-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .tickets-table tr:hover {
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

        .status-badge.open { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-badge.in_progress { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .status-badge.resolved { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-badge.closed { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }

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

        /* Ticket View Page */
        .ticket-view-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .ticket-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-primary);
        }

        .ticket-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .ticket-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .ticket-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .ticket-content {
            padding: 1.5rem;
        }

        .ticket-section {
            margin-bottom: 1.5rem;
        }

        .ticket-section h3 {
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

        /* Comments Section */
        .comments-list {
            margin-top: 1rem;
        }

        .comment-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            flex-shrink: 0;
        }

        .comment-content {
            flex: 1;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .comment-date {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .comment-text {
            font-size: 0.85rem;
            line-height: 1.5;
            white-space: pre-wrap;
        }

        .internal-badge {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        /* Assignment History */
        .history-list {
            margin-top: 0.5rem;
        }

        .history-item {
            padding: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
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
            max-width: 600px;
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
            min-height: 80px;
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

        /* Categories Table */
        .categories-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .categories-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .categories-table th,
        .categories-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .categories-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
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
            
            .tickets-table th,
            .tickets-table td {
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
            
            .ticket-header {
                padding: 1rem;
            }
            
            .ticket-content {
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
                <li class="menu-item"><a href="hero.php"><i class="fas fa-images"></i> Hero Images</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                  <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php" class="active"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
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

            <?php if (isset($_GET['action']) && $_GET['action'] === 'view' && $view_ticket): ?>
                <!-- Ticket View Page -->
                <div class="page-header">
                    <h1><i class="fas fa-ticket-alt"></i> Ticket #<?php echo $view_ticket['id']; ?></h1>
                    <a href="tickets.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                </div>

                <div class="ticket-view-container">
                    <div class="ticket-header">
                        <div class="ticket-title"><?php echo htmlspecialchars($view_ticket['subject']); ?></div>
                        <div class="ticket-meta">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($view_ticket['name']); ?></span>
                            <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($view_ticket['reg_number']); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y g:i A', strtotime($view_ticket['created_at'])); ?></span>
                            <span><span class="status-badge <?php echo $view_ticket['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $view_ticket['status'])); ?></span></span>
                            <span><span class="priority-badge <?php echo $view_ticket['priority']; ?>"><?php echo ucfirst($view_ticket['priority']); ?></span></span>
                        </div>
                    </div>

                    <div class="ticket-content">
                        <div class="ticket-section">
                            <h3>Description</h3>
                            <p><?php echo nl2br(htmlspecialchars($view_ticket['description'])); ?></p>
                        </div>

                        <div class="ticket-section">
                            <h3>Student Information</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Registration Number</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['reg_number']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['email']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['phone']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Academic Year</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['academic_year'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Preferred Contact</span>
                                    <span class="info-value"><?php echo $preferred_contact_labels[$view_ticket['preferred_contact']] ?? ucfirst($view_ticket['preferred_contact']); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="ticket-section">
                            <h3>Assignment Details</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Assigned To</span>
                                    <span class="info-value"><?php echo $view_ticket['assigned_to_name'] ?? '<span class="status-badge" style="background: rgba(107, 114, 128, 0.1);">Unassigned</span>'; ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Category</span>
                                    <span class="info-value"><?php echo htmlspecialchars($view_ticket['category_name'] ?? 'General'); ?></span>
                                </div>
                                <?php if ($view_ticket['resolved_at']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Resolved At</span>
                                        <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($view_ticket['resolved_at'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($assignment_history)): ?>
                            <div class="ticket-section">
                                <h3>Assignment History</h3>
                                <div class="history-list">
                                    <?php foreach ($assignment_history as $history): ?>
                                        <div class="history-item">
                                            <i class="fas fa-exchange-alt"></i>
                                            Assigned to <strong><?php echo htmlspecialchars($history['assigned_to_name']); ?></strong>
                                            by <strong><?php echo htmlspecialchars($history['assigned_by_name']); ?></strong>
                                            on <?php echo date('M j, Y g:i A', strtotime($history['assigned_at'])); ?>
                                            <?php if ($history['reason']): ?>
                                                <br><small>Reason: <?php echo htmlspecialchars($history['reason']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($view_ticket['resolution_notes']): ?>
                            <div class="ticket-section">
                                <h3>Resolution Notes</h3>
                                <div class="info-value" style="background: var(--bg-primary); padding: 0.75rem; border-radius: 8px;">
                                    <?php echo nl2br(htmlspecialchars($view_ticket['resolution_notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="ticket-section">
                            <h3>Comments</h3>
                            <div class="comments-list">
                                <?php if (empty($ticket_comments)): ?>
                                    <div class="empty-state" style="padding: 1rem;">
                                        <p>No comments yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($ticket_comments as $comment): ?>
                                        <div class="comment-item">
                                            <div class="comment-avatar">
                                                <?php echo strtoupper(substr($comment['full_name'] ?? 'U', 0, 1)); ?>
                                            </div>
                                            <div class="comment-content">
                                                <div class="comment-header">
                                                    <div>
                                                        <span class="comment-author"><?php echo htmlspecialchars($comment['full_name'] ?? 'System'); ?></span>
                                                        <?php if ($comment['role'] === 'admin'): ?>
                                                            <span class="internal-badge">Admin</span>
                                                        <?php endif; ?>
                                                        <?php if ($comment['is_internal']): ?>
                                                            <span class="internal-badge">Internal Note</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></div>
                                                </div>
                                                <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Add Comment Form -->
                            <form method="POST" action="" style="margin-top: 1rem;">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                <div class="form-group">
                                    <label>Add Comment</label>
                                    <textarea name="comment" rows="3" placeholder="Type your comment here..." required></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_internal" value="1">
                                        Internal Note (only visible to staff)
                                    </label>
                                </div>
                                <div class="action-buttons">
                                    <button type="submit" class="btn btn-primary">Post Comment</button>
                                </div>
                            </form>
                        </div>

                        <div class="ticket-section">
                            <h3>Actions</h3>
                            <div class="action-buttons">
                                <?php if ($view_ticket['assigned_to'] === null): ?>
                                    <button class="btn btn-primary" onclick="openAssignModal(<?php echo $view_ticket['id']; ?>)">
                                        <i class="fas fa-user-check"></i> Assign
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($view_ticket['status'] === 'open' || $view_ticket['status'] === 'in_progress'): ?>
                                    <button class="btn btn-success" onclick="openResolveModal(<?php echo $view_ticket['id']; ?>)">
                                        <i class="fas fa-check-circle"></i> Resolve
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($view_ticket['status'] === 'resolved'): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="reopen">
                                        <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                        <button type="submit" class="btn btn-warning" onclick="return confirm('Reopen this ticket?')">
                                            <i class="fas fa-redo-alt"></i> Reopen
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="close">
                                        <input type="hidden" name="ticket_id" value="<?php echo $view_ticket['id']; ?>">
                                        <button type="submit" class="btn btn-secondary" onclick="return confirm('Close this ticket?')">
                                            <i class="fas fa-times-circle"></i> Close
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assign Modal -->
                <div id="assignModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Assign Ticket</h2>
                            <button class="close-modal" onclick="closeAssignModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="ticket_id" id="assign_ticket_id" value="">
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
                                <button type="submit" class="btn btn-primary">Assign</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Resolve Modal -->
                <div id="resolveModal" class="modal">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h2>Resolve Ticket</h2>
                            <button class="close-modal" onclick="closeResolveModal()">&times;</button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="ticket_id" id="resolve_ticket_id" value="">
                            <div class="form-group">
                                <label>Resolution Notes *</label>
                                <textarea name="resolution_notes" rows="4" placeholder="Describe how this issue was resolved..." required></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn" onclick="closeResolveModal()">Cancel</button>
                                <button type="submit" class="btn btn-success">Resolve Ticket</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Tickets List Page -->
                <div class="page-header">
                    <h1><i class="fas fa-ticket-alt"></i> Support Tickets</h1>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add Ticket
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_tickets; ?></div>
                        <div class="stat-label">Total Tickets</div>
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
                        <div class="stat-number"><?php echo $today_count; ?></div>
                        <div class="stat-label">Today</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn <?php echo $active_tab === 'tickets' ? 'active' : ''; ?>" onclick="switchTab('tickets')">
                        <i class="fas fa-list"></i> Tickets
                    </button>
                    <button class="tab-btn <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" onclick="switchTab('categories')">
                        <i class="fas fa-tags"></i> Categories
                    </button>
                </div>

                <!-- Tickets Tab -->
                <div id="ticketsTab" class="tab-pane <?php echo $active_tab === 'tickets' ? 'active' : ''; ?>">
                    <!-- Filters -->
                    <form method="GET" action="" class="filters-bar">
                        <input type="hidden" name="tab" value="tickets">
                        <div class="filter-group">
                            <label>Status:</label>
                            <select name="status" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
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
                            <label>Category:</label>
                            <select name="category" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <?php foreach ($issue_categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
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
                            <input type="text" name="search" placeholder="Search by subject, name, reg number..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                            <?php if ($search || $status_filter || $priority_filter || $category_filter || $assigned_filter): ?>
                                <a href="tickets.php?tab=tickets" class="btn btn-sm">Clear</a>
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
                                <option value="close">Close</option>
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

                        <div class="tickets-table-container">
                            <table class="tickets-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                        <th>ID</th>
                                        <th>Student</th>
                                        <th>Subject</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </thead>
                                <tbody>
                                    <?php if (empty($tickets)): ?>
                                        <tr>
                                            <td colspan="10">
                                                <div class="empty-state">
                                                    <i class="fas fa-ticket-alt"></i>
                                                    <h3>No tickets found</h3>
                                                    <p>Click "Add Ticket" to create one.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tickets as $ticket): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo $ticket['id']; ?>" class="ticket-checkbox"></td>
                                                <td>#<?php echo $ticket['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($ticket['name']); ?></strong>
                                                    <br><small><?php echo htmlspecialchars($ticket['reg_number']); ?></small>
                                                </td>
                                                <td>
                                                    <a href="tickets.php?action=view&id=<?php echo $ticket['id']; ?>" style="color: var(--primary); text-decoration: none;">
                                                        <?php echo htmlspecialchars(substr($ticket['subject'], 0, 50)); ?>
                                                    </a>
                                                    <?php if ($ticket['comment_count'] > 0): ?>
                                                        <br><small><i class="fas fa-comment"></i> <?php echo $ticket['comment_count']; ?> comments</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($ticket['category_name'] ?? 'General'); ?></td>
                                                <td><span class="priority-badge <?php echo $ticket['priority']; ?>"><?php echo ucfirst($ticket['priority']); ?></span></td>
                                                <td><span class="status-badge <?php echo $ticket['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span></td>
                                                <td><?php echo htmlspecialchars($ticket['assigned_to_name'] ?? '<span class="status-badge" style="background: rgba(107, 114, 128, 0.1);">Unassigned</span>'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                                <td class="action-buttons">
                                                    <a href="tickets.php?action=view&id=<?php echo $ticket['id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($ticket['assigned_to'] === null): ?>
                                                        <button type="button" class="btn btn-warning btn-sm" onclick="openAssignModal(<?php echo $ticket['id']; ?>)">
                                                            <i class="fas fa-user-check"></i>
                                                        </button>
                                                    <?php endif; ?>
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
                                <a href="?page=<?php echo $page-1; ?>&tab=tickets&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&assigned=<?php echo $assigned_filter; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&tab=tickets&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&assigned=<?php echo $assigned_filter; ?>" 
                                   class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page+1; ?>&tab=tickets&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&assigned=<?php echo $assigned_filter; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Categories Tab -->
                <div id="categoriesTab" class="tab-pane <?php echo $active_tab === 'categories' ? 'active' : ''; ?>">
                    <div class="page-header" style="margin-bottom: 1rem;">
                        <h2><i class="fas fa-tags"></i> Ticket Categories</h2>
                        <button class="btn btn-primary" onclick="openAddCategoryModal()">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                    </div>

                    <form method="POST" action="" id="categoriesBulkForm">
                        <input type="hidden" name="action" value="bulk_categories">
                        <div class="bulk-actions-bar">
                            <select name="bulk_action" id="categories_bulk_action">
                                <option value="">Bulk Actions</option>
                                <option value="delete">Delete</option>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkCategories()">Apply</button>
                        </div>

                        <div class="categories-table-container">
                            <table class="categories-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" class="select-all-cats" onclick="toggleAllCategories(this)"></th>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Assigned Role</th>
                                        <th>SLA (Days)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($issue_categories)): ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fas fa-tags"></i>
                                                    <h3>No categories found</h3>
                                                    <p>Click "Add Category" to create one.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($issue_categories as $cat): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo $cat['id']; ?>" class="category-checkbox"></td>
                                                <td><?php echo $cat['id']; ?></td>
                                                <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars(substr($cat['description'] ?? '', 0, 50)); ?></td>
                                                <td><?php echo htmlspecialchars($cat['assigned_role'] ?? 'General'); ?></td>
                                                <td><?php echo $cat['sla_days'] ?? 3; ?></td>
                                                <td class="action-buttons">
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="openEditCategoryModal(<?php echo $cat['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteCategory(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Ticket Modal -->
    <div id="addTicketModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2>Add New Ticket</h2>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Student Registration Number *</label>
                        <input type="text" name="reg_number" required placeholder="e.g., 2024-001">
                        <small>Enter the student's registration number to auto-fill information</small>
                    </div>
                    <div class="form-group full-width">
                        <label>Subject *</label>
                        <input type="text" name="subject" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Description *</label>
                        <textarea name="description" rows="4" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id">
                            <option value="">Select Category</option>
                            <?php foreach ($issue_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" placeholder="e.g., Year 2">
                    </div>
                    <div class="form-group">
                        <label>Preferred Contact</label>
                        <select name="preferred_contact">
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                            <option value="phone">Phone</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Category Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="categoryModalTitle">Add Category</h2>
                <button class="close-modal" onclick="closeCategoryModal()">&times;</button>
            </div>
            <form method="POST" action="" id="categoryForm">
                <input type="hidden" name="action" id="categoryAction" value="add_category">
                <input type="hidden" name="category_id" id="categoryId" value="">
                <div class="form-group">
                    <label>Category Name *</label>
                    <input type="text" name="name" id="category_name" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="category_description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Assigned Role (Optional)</label>
                    <input type="text" name="assigned_role" id="category_assigned_role" placeholder="e.g., Academic Committee">
                </div>
                <div class="form-group">
                    <label>SLA Days</label>
                    <input type="number" name="sla_days" id="category_sla_days" value="3" min="1">
                    <small>Days within which tickets should be resolved</small>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeCategoryModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
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
        
        // Tab switching
        function switchTab(tab) {
            if (tab === 'tickets') {
                window.location.href = 'tickets.php?tab=tickets';
            } else {
                window.location.href = 'tickets.php?tab=categories';
            }
        }
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addTicketModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeAddModal() {
            document.getElementById('addTicketModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function openAssignModal(ticketId) {
            document.getElementById('assign_ticket_id').value = ticketId;
            document.getElementById('assignModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function openResolveModal(ticketId) {
            document.getElementById('resolve_ticket_id').value = ticketId;
            document.getElementById('resolveModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function closeResolveModal() {
            document.getElementById('resolveModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        // Category Modal functions
        function openAddCategoryModal() {
            document.getElementById('categoryModalTitle').textContent = 'Add Category';
            document.getElementById('categoryAction').value = 'add_category';
            document.getElementById('categoryId').value = '';
            document.getElementById('category_name').value = '';
            document.getElementById('category_description').value = '';
            document.getElementById('category_assigned_role').value = '';
            document.getElementById('category_sla_days').value = '3';
            document.getElementById('categoryModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditCategoryModal(catId) {
            fetch(`tickets.php?get_category=1&id=${catId}`)
                .then(response => response.json())
                .then(cat => {
                    if (cat.error) {
                        alert('Error loading category data');
                        return;
                    }
                    document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                    document.getElementById('categoryAction').value = 'edit_category';
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('category_name').value = cat.name;
                    document.getElementById('category_description').value = cat.description || '';
                    document.getElementById('category_assigned_role').value = cat.assigned_role || '';
                    document.getElementById('category_sla_days').value = cat.sla_days || 3;
                    document.getElementById('categoryModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading category data');
                });
        }
        
        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function confirmDeleteCategory(catId, catName) {
            if (confirm(`Are you sure you want to delete category "${catName}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" value="${catId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.ticket-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function toggleAllCategories(source) {
            const checkboxes = document.querySelectorAll('.category-checkbox');
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
            const checked = document.querySelectorAll('.ticket-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one ticket');
                return false;
            }
            
            if (action === 'assign') {
                const assignedTo = document.getElementById('assigned_to_bulk').value;
                if (!assignedTo) {
                    alert('Please select a committee member to assign to');
                    return false;
                }
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} ticket(s)?`);
        }
        
        function confirmBulkCategories() {
            const action = document.getElementById('categories_bulk_action').value;
            const checked = document.querySelectorAll('.category-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one category');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} category(s)?`);
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const addModal = document.getElementById('addTicketModal');
            const assignModal = document.getElementById('assignModal');
            const resolveModal = document.getElementById('resolveModal');
            const categoryModal = document.getElementById('categoryModal');
            
            if (event.target === addModal) closeAddModal();
            if (event.target === assignModal) closeAssignModal();
            if (event.target === resolveModal) closeResolveModal();
            if (event.target === categoryModal) closeCategoryModal();
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