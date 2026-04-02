<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Advisor Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'advisor_arbitration') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Define all RPSU positions
$rpsu_positions = [
    'guild_president' => 'Guild President',
    'vice_guild_academic' => 'Vice Guild President - Academic',
    'vice_guild_finance' => 'Vice Guild President - Finance',
    'general_secretary' => 'General Secretary',
    'minister_sports' => 'Minister of Sports & Entertainment',
    'minister_environment' => 'Minister of Environment & Security',
    'minister_public_relations' => 'Minister of Public Relations',
    'minister_health' => 'Minister of Health & Welfare',
    'minister_culture' => 'Minister of Culture & Civic Education',
    'minister_gender' => 'Minister of Gender & Protocol',
    'president_representative_board' => 'President - Representative Board',
    'vice_president_representative_board' => 'Vice President - Representative Board',
    'secretary_representative_board' => 'Secretary - Representative Board',
    'president_arbitration' => 'President - Arbitration Committee',
    'vice_president_arbitration' => 'Vice President - Arbitration Committee',
    'advisor_arbitration' => 'Advisor - Arbitration Committee',
    'secretary_arbitration' => 'Secretary - Arbitration Committee'
];

// Handle form submissions for advisor-specific actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_candidate_status']) && in_array($_POST['status'], ['approved', 'rejected'])) {
        // Update candidate status (approve/reject) - only for chairperson/secretary
        $candidate_id = $_POST['candidate_id'];
        $status = $_POST['status'];
        $approval_notes = $_POST['approval_notes'] ?? '';
        
        try {
            // Check if advisor has permission (chairperson or secretary)
            $stmt = $pdo->prepare("
                SELECT ecm.role 
                FROM election_committee_members ecm
                JOIN election_candidates ec ON ecm.election_committee_id = (
                    SELECT election_committee_id FROM elections WHERE id = (
                        SELECT election_id FROM election_candidates WHERE id = ?
                    )
                )
                WHERE ecm.user_id = ? AND ecm.role IN ('chairperson', 'secretary')
            ");
            $stmt->execute([$candidate_id, $user_id]);
            $permission = $stmt->fetch();
            
            if ($permission) {
                // PostgreSQL uses CURRENT_TIMESTAMP instead of NOW()
                $stmt = $pdo->prepare("
                    UPDATE election_candidates 
                    SET status = ?, approval_notes = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$status, $approval_notes, $user_id, $candidate_id]);
                
                $_SESSION['success_message'] = "Candidate status updated to " . ucfirst($status) . "!";
            } else {
                $_SESSION['error_message'] = "You don't have permission to approve/reject candidates.";
            }
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating candidate: " . $e->getMessage();
        }
        
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$election_id = $_GET['id'] ?? 0;

// Get elections list - only elections where advisor is committee member
try {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               (SELECT COUNT(*) FROM election_candidates WHERE election_id = e.id) as candidate_count,
               (SELECT COUNT(*) FROM election_voters WHERE election_id = e.id AND has_voted = 1) as votes_cast,
               ecm.role as committee_role
        FROM elections e 
        JOIN election_committee_members ecm ON e.election_committee_id = ecm.election_committee_id
        WHERE ecm.user_id = ? AND ecm.status = 'active'
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $elections = [];
}

// Get specific election data if managing
$election = null;
$candidates = [];
$results = [];
$total_voters = 0;
$committee_role = '';

if ($election_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT e.*, ecm.role as committee_role 
            FROM elections e 
            JOIN election_committee_members ecm ON e.election_committee_id = ecm.election_committee_id
            WHERE e.id = ? AND ecm.user_id = ?
        ");
        $stmt->execute([$election_id, $user_id]);
        $election = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($election) {
            $committee_role = $election['committee_role'];
            
            // Get candidates
            $stmt = $pdo->prepare("
                SELECT ec.*, 
                       (SELECT COUNT(*) FROM election_votes WHERE candidate_id = ec.id) as vote_count
                FROM election_candidates ec 
                WHERE ec.election_id = ? 
                ORDER BY ec.position, ec.student_name
            ");
            $stmt->execute([$election_id]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total voters
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM election_voters WHERE election_id = ?");
            $stmt->execute([$election_id]);
            $total_voters = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // Calculate results if election is completed
            if ($election['status'] === 'completed') {
                $stmt = $pdo->prepare("
                    SELECT ec.position, ec.student_name, ec.reg_number, COUNT(ev.id) as votes,
                           ROUND((COUNT(ev.id) * 100.0 / NULLIF((
                               SELECT COUNT(*) FROM election_votes ev2 
                               JOIN election_candidates ec2 ON ev2.candidate_id = ec2.id 
                               WHERE ec2.position = ec.position AND ec2.election_id = ?
                           ), 0)), 2) as percentage
                    FROM election_candidates ec
                    LEFT JOIN election_votes ev ON ec.id = ev.candidate_id
                    WHERE ec.election_id = ?
                    GROUP BY ec.id, ec.position, ec.student_name, ec.reg_number
                    ORDER BY ec.position, votes DESC
                ");
                $stmt->execute([$election_id, $election_id]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching election data: " . $e->getMessage());
    }
}

// Get statistics for advisor's elections only
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM elections e 
        JOIN election_committee_members ecm ON e.election_committee_id = ecm.election_committee_id
        WHERE ecm.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $total_elections = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as active 
        FROM elections e 
        JOIN election_committee_members ecm ON e.election_committee_id = ecm.election_committee_id
        WHERE ecm.user_id = ? AND e.status IN ('nomination', 'campaign', 'voting')
    ");
    $stmt->execute([$user_id]);
    $active_elections = $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as completed 
        FROM elections e 
        JOIN election_committee_members ecm ON e.election_committee_id = ecm.election_committee_id
        WHERE ecm.user_id = ? AND e.status = 'completed'
    ");
    $stmt->execute([$user_id]);
    $completed_elections = $stmt->fetch(PDO::FETCH_ASSOC)['completed'] ?? 0;
    
    // Get candidates for advisor's elections
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_candidates 
        FROM election_candidates ec
        JOIN elections e ON ec.election_id = e.id
        JOIN election_committee_members ecm ON e.election_committee_id = ecm.election_committee_id
        WHERE ecm.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $total_candidates = $stmt->fetch(PDO::FETCH_ASSOC)['total_candidates'] ?? 0;
} catch (PDOException $e) {
    $total_elections = $active_elections = $completed_elections = $total_candidates = 0;
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

// Get unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Elections - Arbitration Advisor - Isonga RPSU</title>
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
            --info: #17a2b8;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
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
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            line-height: 1;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
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
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--medium-gray);
            background: var(--white);
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-dark);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            transform: translateY(-1px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .logout-btn {
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 73px);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--medium-gray);
            padding: 1.5rem 0;
            transition: var(--transition);
            position: fixed;
            height: calc(100vh - 73px);
            overflow-y: auto;
            z-index: 99;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item a {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .menu-item i {
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: var(--primary-blue);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 100;
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
            width: 20px;
            text-align: center;
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
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon {
            background: var(--light-blue);
            color: var(--primary-blue);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Card */
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
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 900px;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
            white-space: nowrap;
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.75rem;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background: var(--light-blue);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-draft {
            background: #e9ecef;
            color: #495057;
        }

        .status-nomination {
            background: #cce7ff;
            color: #004085;
        }

        .status-campaign {
            background: #fff3cd;
            color: #856404;
        }

        .status-voting {
            background: #d4edda;
            color: #155724;
        }

        .status-results {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-nominated {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        /* Role Badges */
        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .role-chairperson {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .role-secretary {
            background: #cce7ff;
            color: #004085;
        }

        .role-member {
            background: #d4edda;
            color: #155724;
        }

        .role-observer {
            background: #e9ecef;
            color: #495057;
        }

        /* Position Badges */
        .position-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--light-blue);
            color: var(--primary-blue);
            white-space: nowrap;
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .alert-info {
            background: #cce7ff;
            color: #0c5460;
            border-left-color: var(--primary-blue);
        }

        /* Buttons */
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Timeline */
        .timeline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            position: relative;
            overflow-x: auto;
            padding: 1rem 0;
        }

        .timeline::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--medium-gray);
            z-index: 1;
        }

        .timeline-phase {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
            flex: 1;
            min-width: 80px;
        }

        .phase-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--medium-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-bottom: 0.5rem;
            transition: var(--transition);
        }

        .phase-indicator.active {
            background: var(--primary-blue);
            transform: scale(1.1);
        }

        .phase-indicator.completed {
            background: var(--success);
        }

        .phase-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-align: center;
            color: var(--dark-gray);
        }

        .phase-label.active {
            color: var(--primary-blue);
        }

        .phase-date {
            font-size: 0.6rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--medium-gray);
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        /* Results Grid */
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .position-results {
            background: var(--white);
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
        }

        .position-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .candidate-result {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .candidate-result:last-child {
            border-bottom: none;
        }

        .candidate-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .winner-badge {
            background: var(--success);
            color: white;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .candidate-votes {
            text-align: right;
        }

        .vote-count {
            font-weight: 600;
            color: var(--text-dark);
        }

        .percentage-bar {
            width: 100px;
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            margin-top: 0.25rem;
            overflow: hidden;
        }

        .percentage-fill {
            height: 100%;
            background: var(--primary-blue);
            transition: width 0.3s ease;
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
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                height: 100vh;
                z-index: 1000;
                padding-top: 1rem;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: none;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--light-gray);
                transition: var(--transition);
            }

            .mobile-menu-toggle:hover {
                background: var(--primary-blue);
                color: white;
            }

            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(2px);
                z-index: 999;
            }

            .overlay.active {
                display: block;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .brand-text h1 {
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-number {
                font-size: 1.1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .table {
                display: none;
            }

            .timeline {
                flex-wrap: wrap;
                gap: 1rem;
            }

            .timeline::before {
                display: none;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-bottom: none;
                border-left: 2px solid transparent;
            }

            .tab.active {
                border-left-color: var(--primary-blue);
                border-bottom-color: transparent;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 0.75rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }

            .stat-number {
                font-size: 1rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay for mobile -->
    <div class="overlay" id="mobileOverlay"></div>
    
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Arbitration</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
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
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php">
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
                    <a href="elections.php" class="active">
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
        <main class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Elections Committee ⚖️</h1>
                    <p>Monitor and participate in RPSU elections as committee member</p>
                </div>
                <?php if ($action === 'list'): ?>
                    <div class="btn btn-outline" style="cursor: default;">
                        <i class="fas fa-info-circle"></i> Committee Member Access
                    </div>
                <?php else: ?>
                    <a href="elections.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Elections
                    </a>
                <?php endif; ?>
            </div>

            <!-- Success/Error Messages -->
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

            <?php if ($action === 'list'): ?>
                <!-- Elections List View -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Committee Member Access:</strong> You can monitor elections where you are assigned as a committee member. 
                    Your permissions vary based on your assigned role (Chairperson, Secretary, Member, or Observer).
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-vote-yea"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_elections); ?></div>
                            <div class="stat-label">My Committee Elections</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($active_elections); ?></div>
                            <div class="stat-label">Active Elections</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($completed_elections); ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_candidates); ?></div>
                            <div class="stat-label">Total Candidates</div>
                        </div>
                    </div>
                </div>

                <!-- Elections Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>My Committee Elections</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($elections)): ?>
                            <div class="empty-state">
                                <i class="fas fa-vote-yea"></i>
                                <h3>No committee assignments</h3>
                                <p>You are not currently assigned to any election committees.</p>
                                <p style="font-size: 0.8rem; margin-top: 0.5rem;">
                                    Contact the Arbitration President for committee assignments.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Academic Year</th>
                                            <th>Title</th>
                                            <th>Type</th>
                                            <th>My Role</th>
                                            <th>Status</th>
                                            <th>Candidates</th>
                                            <th>Votes Cast</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($elections as $election): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($election['academic_year']); ?></strong></td>
                                                <td>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($election['title']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px;">
                                                        <?php echo htmlspecialchars($election['description'] ?? ''); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="position-badge">
                                                        <?php echo ucfirst(str_replace('_', ' ', $election['election_type'] ?? '')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="role-badge role-<?php echo $election['committee_role']; ?>">
                                                        <?php echo ucfirst($election['committee_role']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $election['status']; ?>">
                                                        <?php echo ucfirst($election['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $election['candidate_count']; ?></td>
                                                <td><?php echo $election['votes_cast']; ?></td>
                                                <td>
                                                    <div style="display: flex; gap: 0.25rem;">
                                                        <a href="elections.php?action=manage&id=<?php echo $election['id']; ?>" 
                                                           class="btn btn-outline btn-sm" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="elections.php?action=results&id=<?php echo $election['id']; ?>" 
                                                           class="btn btn-outline btn-sm" title="Results">
                                                            <i class="fas fa-chart-bar"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif (in_array($action, ['manage', 'results']) && $election): ?>
                <!-- Election Management View -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3><?php echo htmlspecialchars($election['title']); ?> - <?php echo htmlspecialchars($election['academic_year']); ?></h3>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem; flex-wrap: wrap;">
                                <span class="status-badge status-<?php echo $election['status']; ?>">
                                    <?php echo ucfirst($election['status']); ?>
                                </span>
                                <span class="role-badge role-<?php echo $committee_role; ?>">
                                    Your Role: <?php echo ucfirst($committee_role); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Election Timeline -->
                        <div class="timeline">
                            <?php
                            $phases = [
                                'nomination' => ['icon' => 'user-plus', 'label' => 'Nomination', 'date' => $election['nomination_start_date']],
                                'campaign' => ['icon' => 'bullhorn', 'label' => 'Campaign', 'date' => $election['campaign_start_date']],
                                'voting' => ['icon' => 'vote-yea', 'label' => 'Voting', 'date' => $election['voting_start_date']],
                                'results' => ['icon' => 'chart-bar', 'label' => 'Results', 'date' => $election['results_announcement_date']]
                            ];
                            
                            foreach ($phases as $phase => $data):
                                $isActive = $election['status'] === $phase;
                                $isCompleted = array_search($election['status'], array_keys($phases)) > array_search($phase, array_keys($phases));
                            ?>
                                <div class="timeline-phase">
                                    <div class="phase-indicator <?php echo $isActive ? 'active' : ($isCompleted ? 'completed' : ''); ?>">
                                        <i class="fas fa-<?php echo $data['icon']; ?>"></i>
                                    </div>
                                    <div class="phase-label <?php echo $isActive ? 'active' : ''; ?>">
                                        <?php echo $data['label']; ?>
                                    </div>
                                    <?php if (!empty($data['date'])): ?>
                                        <div class="phase-date">
                                            <?php echo date('M j', strtotime($data['date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Tabs -->
                        <div class="tabs">
                            <button class="tab <?php echo $action === 'manage' ? 'active' : ''; ?>" 
                                    onclick="window.location.href='elections.php?action=manage&id=<?php echo $election_id; ?>'">
                                <i class="fas fa-eye"></i> View Election
                            </button>
                            <button class="tab <?php echo $action === 'results' ? 'active' : ''; ?>" 
                                    onclick="window.location.href='elections.php?action=results&id=<?php echo $election_id; ?>'">
                                <i class="fas fa-chart-bar"></i> Results & Analytics
                            </button>
                        </div>

                        <?php if ($action === 'manage'): ?>
                            <!-- Management Tab -->
                            <?php if (in_array($committee_role, ['observer'])): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> 
                                    As an <strong><?php echo ucfirst($committee_role); ?></strong>, you have read-only access to election details.
                                </div>
                            <?php endif; ?>

                            <!-- Candidates List -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Candidates (<?php echo count($candidates); ?>)</h3>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($candidates)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <h3>No candidates added yet</h3>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-wrapper">
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>Position</th>
                                                        <th>Candidate</th>
                                                        <th>Registration No.</th>
                                                        <th>Votes</th>
                                                        <th>Status</th>
                                                        <?php if (in_array($committee_role, ['chairperson', 'secretary'])): ?>
                                                            <th>Actions</th>
                                                        <?php endif; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($candidates as $candidate): ?>
                                                        <tr>
                                                            <td>
                                                                <span class="position-badge">
                                                                    <?php echo $rpsu_positions[$candidate['position']] ?? $candidate['position']; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($candidate['student_name']); ?></div>
                                                                <?php if (!empty($candidate['manifesto'])): ?>
                                                                    <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px;">
                                                                        <?php echo htmlspecialchars($candidate['manifesto']); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($candidate['reg_number']); ?></td>
                                                            <td class="vote-count"><?php echo $candidate['vote_count']; ?></td>
                                                            <td>
                                                                <span class="status-badge status-<?php echo $candidate['status']; ?>">
                                                                    <?php echo ucfirst($candidate['status']); ?>
                                                                </span>
                                                            </td>
                                                            <?php if (in_array($committee_role, ['chairperson', 'secretary'])): ?>
                                                                <td>
                                                                    <?php if ($candidate['status'] === 'nominated'): ?>
                                                                        <div style="display: flex; gap: 0.25rem;">
                                                                            <form method="POST" style="display: inline;">
                                                                                <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                                                                <button type="submit" name="update_candidate_status" value="approved" 
                                                                                        class="btn btn-success btn-sm" title="Approve">
                                                                                    <i class="fas fa-check"></i>
                                                                                </button>
                                                                            </form>
                                                                            <form method="POST" style="display: inline;">
                                                                                <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                                                                <button type="submit" name="update_candidate_status" value="rejected" 
                                                                                        class="btn btn-danger btn-sm" title="Reject">
                                                                                    <i class="fas fa-times"></i>
                                                                                </button>
                                                                            </form>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <?php elseif ($action === 'results'): ?>
                            <!-- Results Tab -->
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-number"><?php echo count($candidates); ?></div>
                                        <div class="stat-label">Candidates</div>
                                    </div>
                                </div>
                                <div class="stat-card info">
                                    <div class="stat-icon">
                                        <i class="fas fa-user-check"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-number"><?php echo number_format($total_voters); ?></div>
                                        <div class="stat-label">Total Voters</div>
                                    </div>
                                </div>
                                <div class="stat-card success">
                                    <div class="stat-icon">
                                        <i class="fas fa-vote-yea"></i>
                                    </div>
                                    <div class="stat-content">
                                        <?php
                                        $total_votes = array_sum(array_column($candidates, 'vote_count'));
                                        $turnout = $total_voters > 0 ? round(($total_votes / $total_voters) * 100) : 0;
                                        ?>
                                        <div class="stat-number"><?php echo $turnout; ?>%</div>
                                        <div class="stat-label">Voter Turnout</div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($election['status'] === 'completed' && !empty($results)): ?>
                                <!-- Election Results -->
                                <div class="results-grid">
                                    <?php
                                    $grouped_results = [];
                                    foreach ($results as $result) {
                                        $grouped_results[$result['position']][] = $result;
                                    }
                                    
                                    foreach ($grouped_results as $position => $candidates):
                                        $max_votes = max(array_column($candidates, 'votes'));
                                    ?>
                                        <div class="position-results">
                                            <div class="position-title">
                                                <?php echo $rpsu_positions[$position] ?? $position; ?>
                                            </div>
                                            <?php foreach ($candidates as $candidate): ?>
                                                <div class="candidate-result">
                                                    <div class="candidate-info">
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($candidate['student_name']); ?></strong>
                                                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                                <?php echo htmlspecialchars($candidate['reg_number']); ?>
                                                            </div>
                                                        </div>
                                                        <?php if ($candidate['votes'] == $max_votes && $max_votes > 0): ?>
                                                            <span class="winner-badge">WINNER</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="candidate-votes">
                                                        <div class="vote-count"><?php echo $candidate['votes']; ?> votes</div>
                                                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                                            <?php echo number_format($candidate['percentage'], 1); ?>%
                                                        </div>
                                                        <div class="percentage-bar">
                                                            <div class="percentage-fill" style="width: <?php echo $candidate['percentage']; ?>%"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-bar"></i>
                                    <h3>Results Not Available</h3>
                                    <p>Election results will be available after the election is completed.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Election not found or you don't have permission to access it.
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
            if (sidebarToggleBtn) sidebarToggleBtn.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars"></i>';
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            });
        }

        // Close mobile nav on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>