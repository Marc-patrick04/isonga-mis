<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is General Secretary
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'general_secretary') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$ticket_id = $_GET['id'] ?? null;

if (!$ticket_id) {
    header('Location: tickets.php');
    exit();
}

// Get user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User query error: " . $e->getMessage());
}

// Get ticket details
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               d.name as department_name,
               p.name as program_name,
               ic.name as category_name,
               ic.assigned_role as category_role,
               u_assigned.full_name as assigned_to_name,
               u_assigned.role as assigned_to_role,
               u_assigned.email as assigned_to_email,
               u_assigned.phone as assigned_to_phone
        FROM tickets t
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        header('Location: tickets.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Ticket query error: " . $e->getMessage());
    header('Location: tickets.php');
    exit();
}

// Get ticket comments
try {
    $stmt = $pdo->prepare("
        SELECT tc.*, u.full_name, u.role, u.avatar_url
        FROM ticket_comments tc
        LEFT JOIN users u ON tc.user_id = u.id
        WHERE tc.ticket_id = ?
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$ticket_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $comments = [];
    error_log("Comments query error: " . $e->getMessage());
}

// Get ticket assignment history
try {
    $stmt = $pdo->prepare("
        SELECT ta.*, u.full_name as assigner_name, u_assigned.full_name as assignee_name
        FROM ticket_assignments ta
        LEFT JOIN users u ON ta.assigned_by = u.id
        LEFT JOIN users u_assigned ON ta.assigned_to = u_assigned.id
        WHERE ta.ticket_id = ?
        ORDER BY ta.assigned_at DESC
    ");
    $stmt->execute([$ticket_id]);
    $assignment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assignment_history = [];
    error_log("Assignment history query error: " . $e->getMessage());
}

// Get committee members for assignment
try {
    $committeeStmt = $pdo->query("
        SELECT id, full_name, role, email, phone FROM users 
        WHERE (role LIKE '%committee%' OR role = 'guild_president' OR role = 'general_secretary' OR role LIKE '%minister%')
        AND status = 'active'
        ORDER BY 
            CASE 
                WHEN role = 'guild_president' THEN 1
                WHEN role = 'general_secretary' THEN 2
                ELSE 3
            END, full_name
    ");
    $committeeMembers = $committeeStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committeeMembers = [];
    error_log("Committee members query error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_comment':
                $comment = $_POST['comment'] ?? '';
                $is_internal = $_POST['is_internal'] ?? 0;
                
                if (!empty(trim($comment))) {
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$ticket_id, $user_id, trim($comment), $is_internal]);
                    
                    $_SESSION['message'] = "Comment added successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;
                
            case 'assign_ticket':
                $assigned_to = $_POST['assigned_to'] ?? null;
                $assignment_reason = $_POST['assignment_reason'] ?? '';
                
                if ($assigned_to) {
                    // Update ticket assignment and status
                    $stmt = $pdo->prepare("
                        UPDATE tickets 
                        SET assigned_to = ?, status = 'in_progress', updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$assigned_to, $ticket_id]);
                    
                    // Log assignment
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                        VALUES (?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$ticket_id, $assigned_to, $user_id, $assignment_reason]);
                    
                    $_SESSION['message'] = "Ticket assigned successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;
                
            case 'update_status':
                $status = $_POST['status'] ?? '';
                $resolution_notes = $_POST['resolution_notes'] ?? '';
                
                if ($status) {
                    $update_data = [
                        'status' => $status,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    if ($status === 'resolved' || $status === 'closed') {
                        $update_data['resolution_notes'] = $resolution_notes;
                        $update_data['resolved_at'] = date('Y-m-d H:i:s');
                    }
                    
                    if ($status === 'open') {
                        $update_data['assigned_to'] = null;
                    }
                    
                    $set_clause = implode(', ', array_map(function($key) {
                        return "$key = ?";
                    }, array_keys($update_data)));
                    
                    $params = array_values($update_data);
                    $params[] = $ticket_id;
                    
                    $stmt = $pdo->prepare("UPDATE tickets SET $set_clause WHERE id = ?");
                    $stmt->execute($params);
                    
                    $_SESSION['message'] = "Ticket status updated successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;
                
            case 'take_ticket':
                // Assign ticket to current user
                $stmt = $pdo->prepare("
                    UPDATE tickets 
                    SET assigned_to = ?, status = 'in_progress', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$user_id, $ticket_id]);
                
                // Log assignment
                $stmt = $pdo->prepare("
                    INSERT INTO ticket_assignments (ticket_id, assigned_to, assigned_by, assigned_at, reason)
                    VALUES (?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$ticket_id, $user_id, $user_id, 'Taken by General Secretary']);
                
                $_SESSION['message'] = "You have taken ownership of this ticket!";
                $_SESSION['message_type'] = 'success';
                break;
                
            case 'update_priority':
                $priority = $_POST['priority'] ?? '';
                
                if ($priority) {
                    $stmt = $pdo->prepare("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$priority, $ticket_id]);
                    
                    $_SESSION['message'] = "Ticket priority updated successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;
        }
        
        // Redirect to prevent form resubmission
        header("Location: view_ticket.php?id=$ticket_id");
        exit();
        
    } catch (PDOException $e) {
        error_log("Ticket action error: " . $e->getMessage());
        $_SESSION['message'] = "Error processing request: " . $e->getMessage();
        $_SESSION['message_type'] = 'error';
    }
}

// Update the ticket data after potential changes
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               d.name as department_name,
               p.name as program_name,
               ic.name as category_name,
               ic.assigned_role as category_role,
               u_assigned.full_name as assigned_to_name,
               u_assigned.role as assigned_to_role,
               u_assigned.email as assigned_to_email,
               u_assigned.phone as assigned_to_phone
        FROM tickets t
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN programs p ON t.program_id = p.id
        LEFT JOIN issue_categories ic ON t.category_id = ic.id
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Ticket refresh error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $ticket_id; ?> - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
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

        /* Ticket Layout */
        .ticket-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 1.5rem;
        }

        .ticket-main {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .ticket-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        /* Ticket Header */
        .ticket-header {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            border-left: 4px solid;
        }

        .ticket-header.high { border-left-color: var(--danger); }
        .ticket-header.medium { border-left-color: var(--warning); }
        .ticket-header.low { border-left-color: var(--success); }

        .ticket-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.8rem;
            color: var(--dark-gray);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 500;
            color: var(--text-dark);
        }

        .ticket-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Status Badges */
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high { background: var(--danger); color: white; }
        .priority-medium { background: var(--warning); color: black; }
        .priority-low { background: var(--success); color: white; }

        .status-open { background: #d4edda; color: var(--success); }
        .status-in_progress { background: #cce7ff; color: var(--primary-blue); }
        .status-resolved { background: #e8f5e8; color: var(--success); }
        .status-closed { background: #e9ecef; color: var(--dark-gray); }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Comments */
        .comment {
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 3px solid transparent;
        }

        .comment-internal {
            border-left-color: var(--warning);
            background: #fff3cd;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .comment-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .comment-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .comment-date {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .comment-content {
            line-height: 1.5;
        }

        /* Assignment History */
        .assignment-item {
            padding: 0.75rem;
            border-left: 3px solid var(--primary-blue);
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
        }

        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.25rem;
        }

        .assignment-info {
            font-weight: 600;
            color: var(--text-dark);
        }

        .assignment-date {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .assignment-reason {
            font-size: 0.85rem;
            color: var(--dark-gray);
            font-style: italic;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .ticket-layout {
                grid-template-columns: 1fr;
            }
            
            .ticket-sidebar {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .ticket-meta {
                grid-template-columns: 1fr;
            }
            
            .ticket-actions {
                flex-direction: column;
            }
            
            .nav-container {
                padding: 0 1rem;
            }
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Print Styles */
        @media print {
            .header, .ticket-actions, .comment-form, .sidebar-actions {
                display: none !important;
            }
            
            .main-content {
                padding: 0;
                max-width: none;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
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
                    <h1>Isonga - General Secretary</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
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
                        <div class="user-role">General Secretary</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type'] === 'success' ? 'success' : 'error'; ?>">
                <i class="fas fa-<?php echo $_SESSION['message_type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>Ticket #<?php echo $ticket_id; ?> - <?php echo htmlspecialchars($ticket['subject']); ?></h1>
                <p>Manage and resolve student issues and concerns</p>
            </div>
            <div class="page-actions">
                <a href="tickets.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Tickets
                </a>
                <button class="btn btn-outline" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <div class="ticket-layout">
            <!-- Main Content -->
            <div class="ticket-main">
                <!-- Ticket Header -->
                <div class="ticket-header <?php echo $ticket['priority']; ?>">
                    <div class="ticket-title">
                        <?php echo htmlspecialchars($ticket['subject']); ?>
                    </div>
                    
                    <div class="ticket-meta">
                        <div class="meta-item">
                            <span class="meta-label">Student</span>
                            <span class="meta-value">
                                <?php echo htmlspecialchars($ticket['name']); ?> (<?php echo htmlspecialchars($ticket['reg_number']); ?>)
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Contact</span>
                            <span class="meta-value">
                                <?php echo htmlspecialchars($ticket['email']); ?> | <?php echo htmlspecialchars($ticket['phone']); ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Academic Information</span>
                            <span class="meta-value">
                                <?php echo htmlspecialchars($ticket['academic_year']); ?>
                                <?php if ($ticket['department_name']): ?>
                                    • <?php echo htmlspecialchars($ticket['department_name']); ?>
                                <?php endif; ?>
                                <?php if ($ticket['program_name']): ?>
                                    • <?php echo htmlspecialchars($ticket['program_name']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Category</span>
                            <span class="meta-value">
                                <?php echo htmlspecialchars($ticket['category_name'] ?? 'Uncategorized'); ?>
                                <?php if ($ticket['category_role']): ?>
                                    <small>(<?php echo htmlspecialchars(str_replace('_', ' ', $ticket['category_role'])); ?>)</small>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="ticket-actions">
                        <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                            <?php echo ucfirst($ticket['priority']); ?> Priority
                        </span>
                        <span class="badge status-<?php echo $ticket['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                        </span>
                        <?php if ($ticket['assigned_to_name']): ?>
                            <span class="badge" style="background: #e3f2fd; color: var(--primary-blue);">
                                Assigned to: <?php echo htmlspecialchars($ticket['assigned_to_name']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: #fff3cd; color: #856404;">
                                Unassigned
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ticket Description -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-align-left"></i> Ticket Description</h3>
                    </div>
                    <div class="card-body">
                        <div style="white-space: pre-wrap; line-height: 1.6;">
                            <?php echo htmlspecialchars($ticket['description']); ?>
                        </div>
                    </div>
                </div>

                <!-- Comments Section -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-comments"></i> Comments & Updates</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($comments)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                <i class="fas fa-comment-slash" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No comments yet. Be the first to add a comment.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                    <div class="comment-header">
                                        <div class="comment-author">
                                            <div class="comment-avatar">
                                                <?php if (!empty($comment['avatar_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($comment['avatar_url']); ?>" alt="Avatar">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($comment['full_name'] ?? 'U', 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo htmlspecialchars($comment['full_name']); ?>
                                            <?php if ($comment['is_internal']): ?>
                                                <small style="color: var(--warning);">(Internal Note)</small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="comment-date">
                                            <?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="comment-content">
                                        <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Add Comment Form -->
                        <form method="POST" class="comment-form">
                            <input type="hidden" name="action" value="add_comment">
                            <div class="form-group">
                                <label class="form-label" for="comment">Add Comment</label>
                                <textarea class="form-control" id="comment" name="comment" rows="4" 
                                          placeholder="Enter your comment or update..." required></textarea>
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="is_internal" name="is_internal" value="1">
                                    <label for="is_internal">This is an internal note (visible only to committee members)</label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Add Comment
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="ticket-sidebar">
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="sidebar-actions" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <?php if (!$ticket['assigned_to']): ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="take_ticket">
                                    <button type="submit" class="btn btn-success" style="width: 100%;">
                                        <i class="fas fa-hand-paper"></i> Take This Ticket
                                    </button>
                                </form>
                            <?php elseif ($ticket['assigned_to'] == $user_id && $ticket['status'] === 'in_progress'): ?>
                                <button type="button" class="btn btn-primary" style="width: 100%;" 
                                        onclick="document.getElementById('resolveModal').style.display='block'">
                                    <i class="fas fa-check"></i> Mark Resolved
                                </button>
                            <?php endif; ?>

                            <?php if ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed'): ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="open">
                                    <button type="submit" class="btn btn-warning" style="width: 100%;">
                                        <i class="fas fa-redo"></i> Reopen Ticket
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Ticket Information -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle"></i> Ticket Information</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div>
                                <strong>Created:</strong><br>
                                <?php echo date('F j, Y g:i A', strtotime($ticket['created_at'])); ?>
                            </div>
                            <div>
                                <strong>Last Updated:</strong><br>
                                <?php echo date('F j, Y g:i A', strtotime($ticket['updated_at'])); ?>
                            </div>
                            <?php if ($ticket['due_date']): ?>
                                <div>
                                    <strong>Due Date:</strong><br>
                                    <?php echo date('F j, Y', strtotime($ticket['due_date'])); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($ticket['resolved_at']): ?>
                                <div>
                                    <strong>Resolved:</strong><br>
                                    <?php echo date('F j, Y g:i A', strtotime($ticket['resolved_at'])); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong>Preferred Contact:</strong><br>
                                <?php echo ucfirst($ticket['preferred_contact']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assignment & Status -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-cog"></i> Manage Ticket</h3>
                    </div>
                    <div class="card-body">
                        <!-- Assign Ticket Form -->
                        <form method="POST" class="form-group">
                            <input type="hidden" name="action" value="assign_ticket">
                            <div class="form-group">
                                <label class="form-label">Assign To</label>
                                <select name="assigned_to" class="form-control" required>
                                    <option value="">Select Committee Member</option>
                                    <?php foreach ($committeeMembers as $member): ?>
                                        <option value="<?php echo $member['id']; ?>" 
                                            <?php echo $ticket['assigned_to'] == $member['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($member['full_name'] . ' - ' . str_replace('_', ' ', $member['role'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Assignment Reason</label>
                                <input type="text" name="assignment_reason" class="form-control" 
                                       placeholder="Reason for assignment..." value="Assigned by General Secretary">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-user-plus"></i> Assign Ticket
                            </button>
                        </form>

                        <!-- Update Priority Form -->
                        <form method="POST" class="form-group">
                            <input type="hidden" name="action" value="update_priority">
                            <div class="form-group">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-control" onchange="this.form.submit()">
                                    <option value="low" <?php echo $ticket['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo $ticket['priority'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo $ticket['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                </select>
                            </div>
                        </form>

                        <!-- Update Status Form -->
                        <form method="POST">
                            <input type="hidden" name="action" value="update_status">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control" onchange="this.form.submit()">
                                    <option value="open" <?php echo $ticket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo $ticket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="resolved" <?php echo $ticket['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="closed" <?php echo $ticket['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Assignment History -->
                <?php if (!empty($assignment_history)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Assignment History</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($assignment_history as $assignment): ?>
                            <div class="assignment-item">
                                <div class="assignment-header">
                                    <div class="assignment-info">
                                        <?php echo htmlspecialchars($assignment['assignee_name']); ?>
                                    </div>
                                    <div class="assignment-date">
                                        <?php echo date('M j, Y', strtotime($assignment['assigned_at'])); ?>
                                    </div>
                                </div>
                                <?php if ($assignment['reason']): ?>
                                    <div class="assignment-reason">
                                        "<?php echo htmlspecialchars($assignment['reason']); ?>"
                                    </div>
                                <?php endif; ?>
                                <small>Assigned by: <?php echo htmlspecialchars($assignment['assigner_name']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Resolve Modal -->
        <div id="resolveModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
            <div style="background-color: var(--white); margin: 10% auto; padding: 0; border-radius: var(--border-radius); width: 500px; max-width: 90%; box-shadow: var(--shadow-lg);">
                <div class="card-header">
                    <h3><i class="fas fa-check"></i> Resolve Ticket</h3>
                    <span style="cursor: pointer;" onclick="document.getElementById('resolveModal').style.display='none'">&times;</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="resolved">
                        <div class="form-group">
                            <label class="form-label">Resolution Notes</label>
                            <textarea class="form-control" name="resolution_notes" rows="4" 
                                      placeholder="Describe how this ticket was resolved..." required></textarea>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check"></i> Mark as Resolved
                            </button>
                            <button type="button" class="btn btn-outline" 
                                    onclick="document.getElementById('resolveModal').style.display='none'">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

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

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('resolveModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Auto-expand textareas
        document.addEventListener('input', function(e) {
            if (e.target.tagName === 'TEXTAREA') {
                e.target.style.height = 'auto';
                e.target.style.height = (e.target.scrollHeight) + 'px';
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape: Close modals
            if (e.key === 'Escape') {
                document.getElementById('resolveModal').style.display = 'none';
            }
        });

        // Print functionality
        function printTicket() {
            window.print();
        }
    </script>
</body>
</html>