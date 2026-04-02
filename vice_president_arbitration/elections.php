<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice President Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_president_arbitration') {
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_candidate'])) {
        // Add candidate to election (vice president can add candidates)
        $election_id = $_POST['election_id'];
        $position = $_POST['position'];
        $student_name = $_POST['student_name'];
        $reg_number = $_POST['reg_number'];
        $manifesto = $_POST['manifesto'];
        
        try {
            // Check if candidate already exists for this position
            $stmt = $pdo->prepare("
                SELECT id FROM election_candidates 
                WHERE election_id = ? AND position = ? AND reg_number = ?
            ");
            $stmt->execute([$election_id, $position, $reg_number]);
            
            if ($stmt->fetch()) {
                $_SESSION['error_message'] = "Candidate already exists for this position!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO election_candidates 
                    (election_id, student_name, reg_number, position, manifesto, status, created_by)
                    VALUES (?, ?, ?, ?, ?, 'nominated', ?)
                ");
                $stmt->execute([$election_id, $student_name, $reg_number, $position, $manifesto, $user_id]);
                
                $_SESSION['success_message'] = "Candidate added successfully!";
            }
            
            header('Location: elections.php?action=manage&id=' . $election_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding candidate: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_candidate_status'])) {
        // Update candidate status (vice president can verify/approve candidates)
        $candidate_id = $_POST['candidate_id'];
        $status = $_POST['status'];
        $election_id = $_POST['election_id'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE election_candidates 
                SET status = ?, verified_by = ?, verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $user_id, $candidate_id]);
            
            $_SESSION['success_message'] = "Candidate status updated!";
            header('Location: elections.php?action=manage&id=' . $election_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating candidate: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_voter'])) {
        // Add voter to election (for special cases)
        $election_id = $_POST['election_id'];
        $reg_number = $_POST['reg_number'];
        $student_name = $_POST['student_name'];
        
        try {
            // Check if voter already exists
            $stmt = $pdo->prepare("
                SELECT id FROM election_voters 
                WHERE election_id = ? AND reg_number = ?
            ");
            $stmt->execute([$election_id, $reg_number]);
            
            if ($stmt->fetch()) {
                $_SESSION['error_message'] = "Voter already exists!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO election_voters 
                    (election_id, reg_number, student_name, added_by)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$election_id, $reg_number, $student_name, $user_id]);
                
                $_SESSION['success_message'] = "Voter added successfully!";
            }
            
            header('Location: elections.php?action=voters&id=' . $election_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding voter: " . $e->getMessage();
        }
    }
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$election_id = $_GET['id'] ?? 0;

// Get elections list - vice president can see all elections
try {
    $stmt = $pdo->query("
        SELECT e.*, 
               (SELECT COUNT(*) FROM election_candidates WHERE election_id = e.id) as candidate_count,
               (SELECT COUNT(*) FROM election_voters WHERE election_id = e.id AND has_voted = 1) as votes_cast,
               (SELECT COUNT(*) FROM election_voters WHERE election_id = e.id) as total_voters
        FROM elections e 
        ORDER BY e.created_at DESC
    ");
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $elections = [];
}

// Get specific election data if managing
$election = null;
$candidates = [];
$voters = [];
$results = [];
$total_voters = 0;
$voters_voted = 0;

if ($election_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
        $stmt->execute([$election_id]);
        $election = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get candidates
        $stmt = $pdo->prepare("
            SELECT ec.*, 
                   (SELECT COUNT(*) FROM election_votes WHERE candidate_id = ec.id) as vote_count,
                   u.full_name as verified_by_name
            FROM election_candidates ec 
            LEFT JOIN users u ON ec.verified_by = u.id
            WHERE ec.election_id = ? 
            ORDER BY ec.position, ec.student_name
        ");
        $stmt->execute([$election_id]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get voters for this election
        $stmt = $pdo->prepare("
            SELECT ev.*, 
                   (SELECT COUNT(*) FROM election_votes WHERE voter_id = ev.id) as has_voted
            FROM election_voters ev 
            WHERE ev.election_id = ? 
            ORDER BY ev.student_name
            LIMIT 50
        ");
        $stmt->execute([$election_id]);
        $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get voter statistics
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN has_voted = 1 THEN 1 ELSE 0 END) as voted
            FROM election_voters 
            WHERE election_id = ?
        ");
        $stmt->execute([$election_id]);
        $voter_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_voters = $voter_stats['total'] ?? 0;
        $voters_voted = $voter_stats['voted'] ?? 0;
        
        // Calculate results if election is completed
        if ($election && $election['status'] === 'completed') {
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
        
    } catch (PDOException $e) {
        error_log("Error fetching election data: " . $e->getMessage());
    }
}

// Get statistics for vice president
try {
    // Elections the vice president is involved in (created or has candidates they verified)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT e.id) as my_elections
        FROM elections e
        LEFT JOIN election_candidates ec ON e.id = ec.election_id
        WHERE e.created_by = ? OR ec.verified_by = ?
    ");
    $stmt->execute([$user_id, $user_id]);
    $my_elections = $stmt->fetch(PDO::FETCH_ASSOC)['my_elections'] ?? 0;
    
    // Candidates verified by vice president
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as verified_candidates 
        FROM election_candidates 
        WHERE verified_by = ?
    ");
    $stmt->execute([$user_id]);
    $verified_candidates = $stmt->fetch(PDO::FETCH_ASSOC)['verified_candidates'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM elections WHERE status IN ('nomination', 'campaign', 'voting')");
    $active_elections = $stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as completed FROM elections WHERE status = 'completed'");
    $completed_elections = $stmt->fetch(PDO::FETCH_ASSOC)['completed'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_candidates FROM election_candidates");
    $total_candidates = $stmt->fetch(PDO::FETCH_ASSOC)['total_candidates'] ?? 0;
} catch (PDOException $e) {
    $my_elections = $verified_candidates = $active_elections = $completed_elections = $total_candidates = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elections Management - Isonga RPSU</title>
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
            width: 100%;
            gap: 0.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
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
            color: var(--text-dark);
            font-size: 0.9rem;
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
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
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
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-outline:hover {
            background: var(--light-gray);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
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
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #d1ecf1;
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .status-nomination {
            background: #fff3cd;
            color: var(--warning);
        }

        .status-campaign {
            background: #cce7ff;
            color: var(--primary-blue);
        }

        .status-voting {
            background: #d4edda;
            color: var(--success);
        }

        .status-results {
            background: #e2e3ff;
            color: #6f42c1;
        }

        .status-completed {
            background: #d4edda;
            color: var(--success);
        }

        .candidate-status-nominated {
            background: #e9ecef;
            color: var(--dark-gray);
        }

        .candidate-status-verified {
            background: #d4edda;
            color: var(--success);
        }

        .candidate-status-rejected {
            background: #f8d7da;
            color: var(--danger);
        }

        .candidate-status-withdrawn {
            background: #6c757d;
            color: white;
        }

        .position-badge {
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            background: #e3f2fd;
            color: var(--primary-blue);
            font-weight: 600;
        }

        .vote-count {
            font-weight: 700;
            color: var(--success);
        }

        .percentage-bar {
            background: var(--light-gray);
            border-radius: 10px;
            height: 8px;
            margin-top: 0.25rem;
            overflow: hidden;
        }

        .percentage-fill {
            background: var(--success);
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .winner-badge {
            background: gold;
            color: black;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: 0.5rem;
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
            font-size: 0.8rem;
            color: var(--text-dark);
        }

        .form-control {
            padding: 0.5rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-full {
            grid-column: 1 / -1;
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
            white-space: nowrap;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .tab:hover {
            color: var(--primary-blue);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Election Timeline */
        .timeline {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            position: relative;
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
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .phase-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            border: 2px solid var(--medium-gray);
        }

        .phase-indicator.active {
            background: var(--primary-blue);
            border-color: var(--primary-blue);
            color: white;
        }

        .phase-indicator.completed {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .phase-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--dark-gray);
        }

        .phase-label.active {
            color: var(--primary-blue);
        }

        .phase-date {
            font-size: 0.65rem;
            color: var(--dark-gray);
            margin-top: 0.25rem;
        }

        /* Results Grid */
        .results-grid {
            display: grid;
            gap: 1.5rem;
        }

        .position-results {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
        }

        .position-title {
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-dark);
            border-bottom: 1px solid var(--medium-gray);
            padding-bottom: 0.5rem;
        }

        .candidate-result {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--white);
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
        }

        .candidate-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .candidate-votes {
            text-align: right;
        }

        /* Alert */
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        /* Overlay for mobile */
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

        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
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
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark-gray);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.25rem;
        }

        /* Progress bar */
        .progress-container {
            margin-top: 0.5rem;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: var(--light-gray);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 5px;
            transition: width 0.3s ease;
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

            .form-row {
                grid-template-columns: 1fr;
            }

            .timeline {
                flex-direction: column;
                gap: 1rem;
            }

            .timeline::before {
                display: none;
            }

            .tabs {
                overflow-x: auto;
            }

            .table {
                display: none;
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

            .page-title {
                font-size: 1.2rem;
            }

            .action-buttons {
                flex-direction: column;
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
                    </a>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        if (!empty($_SESSION['avatar_url'])): 
                            echo '<img src="../' . htmlspecialchars($_SESSION['avatar_url']) . '" alt="Profile">';
                        else:
                            echo strtoupper(substr($_SESSION['full_name'], 0, 1)); 
                        endif;
                        ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration Vice President</div>
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
                        <span>Arbitration Cases</span>
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
                        <?php if ($active_elections > 0): ?>
                            <span class="menu-badge"><?php echo $active_elections; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="election_committee.php">
                        <i class="fas fa-users-cog"></i>
                        <span>Election Committee</span>
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
                <h1 class="page-title">Elections Management</h1>
                <?php if ($action === 'list'): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <a href="election_committee.php" class="btn btn-info">
                            <i class="fas fa-users-cog"></i> Manage Committee
                        </a>
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
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-vote-yea"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($elections); ?></div>
                            <div class="stat-label">Total Elections</div>
                        </div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $active_elections; ?></div>
                            <div class="stat-label">Active Elections</div>
                        </div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $completed_elections; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $verified_candidates; ?></div>
                            <div class="stat-label">My Verified Candidates</div>
                        </div>
                    </div>
                </div>

                <!-- Elections Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>RPSU Elections</h3>
                        <small>Total: <?php echo count($elections); ?> elections</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($elections)): ?>
                            <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                                <i class="fas fa-vote-yea" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No elections found</h3>
                                <p>There are no elections in the system yet.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Academic Year</th>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Candidates</th>
                                        <th>Voters</th>
                                        <th>Turnout</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($elections as $election): 
                                        $turnout = $election['total_voters'] > 0 ? 
                                            round(($election['votes_cast'] / $election['total_voters']) * 100) : 0;
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($election['academic_year']); ?></strong></td>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($election['title']); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($election['description']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $election['status']; ?>">
                                                    <?php echo ucfirst($election['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo $election['candidate_count']; ?></div>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo $election['total_voters']; ?></div>
                                                <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                    <?php echo $election['votes_cast']; ?> voted
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500; color: var(--success);"><?php echo $turnout; ?>%</div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $turnout; ?>%"></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="elections.php?action=manage&id=<?php echo $election['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="Manage Election">
                                                        <i class="fas fa-cog"></i>
                                                    </a>
                                                    <a href="elections.php?action=results&id=<?php echo $election['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="View Results">
                                                        <i class="fas fa-chart-bar"></i>
                                                    </a>
                                                    <a href="elections.php?action=voters&id=<?php echo $election['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="View Voters">
                                                        <i class="fas fa-users"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif (in_array($action, ['manage', 'results', 'voters']) && $election): ?>
                <!-- Election Management View -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3><?php echo htmlspecialchars($election['title']); ?> - <?php echo htmlspecialchars($election['academic_year']); ?></h3>
                            <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                <?php echo htmlspecialchars($election['description']); ?>
                            </div>
                        </div>
                        <span class="status-badge status-<?php echo $election['status']; ?>">
                            <?php echo ucfirst($election['status']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Election Timeline -->
                        <div class="timeline">
                            <?php
                            $phases = [
                                'nomination' => ['icon' => 'user-plus', 'label' => 'Nomination', 'date' => $election['nomination_start_date']],
                                'campaign' => ['icon' => 'bullhorn', 'label' => 'Campaign', 'date' => $election['campaign_start_date']],
                                'voting' => ['icon' => 'vote-yea', 'label' => 'Voting', 'date' => $election['voting_start_date']],
                                'results' => ['icon' => 'chart-bar', 'label' => 'Results', 'date' => $election['results_announcement_date'] ?? $election['voting_end_date']]
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
                                    <?php if ($data['date']): ?>
                                        <div class="phase-date">
                                            <?php echo date('M j, Y', strtotime($data['date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Election Stats -->
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
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo $total_voters; ?></div>
                                    <div class="stat-label">Total Voters</div>
                                </div>
                            </div>
                            <div class="stat-card success">
                                <div class="stat-icon">
                                    <i class="fas fa-vote-yea"></i>
                                </div>
                                <div class="stat-content">
                                    <?php
                                    $turnout = $total_voters > 0 ? round(($voters_voted / $total_voters) * 100) : 0;
                                    ?>
                                    <div class="stat-number"><?php echo $turnout; ?>%</div>
                                    <div class="stat-label">Voter Turnout</div>
                                </div>
                            </div>
                            <div class="stat-card info">
                                <div class="stat-icon">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="stat-content">
                                    <?php
                                    $verified_count = array_filter($candidates, function($c) {
                                        return $c['verified_by'] == $_SESSION['user_id'];
                                    });
                                    ?>
                                    <div class="stat-number"><?php echo count($verified_count); ?></div>
                                    <div class="stat-label">Verified by Me</div>
                                </div>
                            </div>
                        </div>

                        <!-- Tabs -->
                        <div class="tabs">
                            <button class="tab <?php echo $action === 'manage' ? 'active' : ''; ?>" 
                                    onclick="window.location.href='elections.php?action=manage&id=<?php echo $election_id; ?>'">
                                <i class="fas fa-cog"></i> Manage Candidates
                            </button>
                            <button class="tab <?php echo $action === 'results' ? 'active' : ''; ?>" 
                                    onclick="window.location.href='elections.php?action=results&id=<?php echo $election_id; ?>'">
                                <i class="fas fa-chart-bar"></i> Results & Analytics
                            </button>
                            <button class="tab <?php echo $action === 'voters' ? 'active' : ''; ?>" 
                                    onclick="window.location.href='elections.php?action=voters&id=<?php echo $election_id; ?>'">
                                <i class="fas fa-users"></i> Voters
                            </button>
                        </div>

                        <?php if ($action === 'manage'): ?>
                            <!-- Management Tab -->
                            <!-- Add Candidate Form -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Add New Candidate</h3>
                                    <small>Vice President can add and verify candidates</small>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="position">Position *</label>
                                                <select class="form-control" id="position" name="position" required>
                                                    <option value="">Select Position</option>
                                                    <?php foreach ($rpsu_positions as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="student_name">Candidate Name *</label>
                                                <input type="text" class="form-control" id="student_name" name="student_name" required>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="reg_number">Registration Number *</label>
                                                <input type="text" class="form-control" id="reg_number" name="reg_number" required>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group form-full">
                                                <label for="manifesto">Manifesto / Platform</label>
                                                <textarea class="form-control" id="manifesto" name="manifesto" rows="3"></textarea>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group form-full">
                                                <button type="submit" name="add_candidate" class="btn btn-primary">
                                                    <i class="fas fa-plus"></i> Add Candidate
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Candidates List -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Candidates (<?php echo count($candidates); ?>)</h3>
                                    <small>You can verify or reject candidates</small>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($candidates)): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                            <p>No candidates added yet</p>
                                        </div>
                                    <?php else: ?>
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Position</th>
                                                    <th>Candidate</th>
                                                    <th>Registration No.</th>
                                                    <th>Status</th>
                                                    <th>Verified By</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($candidates as $candidate): 
                                                    $is_verified_by_me = $candidate['verified_by'] == $user_id;
                                                ?>
                                                    <tr <?php echo $is_verified_by_me ? 'style="background-color: rgba(227, 242, 253, 0.3);"' : ''; ?>>
                                                        <td>
                                                            <span class="position-badge">
                                                                <?php echo $rpsu_positions[$candidate['position']] ?? $candidate['position']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($candidate['student_name']); ?></div>
                                                            <?php if ($candidate['manifesto']): ?>
                                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                                    <?php echo htmlspecialchars($candidate['manifesto']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($candidate['reg_number']); ?></td>
                                                        <td>
                                                            <span class="status-badge candidate-status-<?php echo $candidate['status']; ?>">
                                                                <?php echo ucfirst($candidate['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($candidate['verified_by_name']): ?>
                                                                <div style="font-size: 0.8rem;">
                                                                    <?php echo htmlspecialchars($candidate['verified_by_name']); ?>
                                                                    <?php if ($is_verified_by_me): ?>
                                                                        <span style="color: var(--success); margin-left: 5px;">(You)</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <span style="color: var(--dark-gray); font-size: 0.8rem;">Not verified</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($candidate['status'] === 'nominated'): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                                                    <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                                                    <input type="hidden" name="status" value="verified">
                                                                    <button type="submit" name="update_candidate_status" class="btn btn-success btn-sm" 
                                                                            onclick="return confirm('Verify this candidate?')" title="Verify Candidate">
                                                                        <i class="fas fa-check"></i>
                                                                    </button>
                                                                </form>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                                                    <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                                                    <input type="hidden" name="status" value="rejected">
                                                                    <button type="submit" name="update_candidate_status" class="btn btn-danger btn-sm" 
                                                                            onclick="return confirm('Reject this candidate?')" title="Reject Candidate">
                                                                        <i class="fas fa-times"></i>
                                                                    </button>
                                                                </form>
                                                            <?php elseif ($candidate['status'] === 'verified' && $is_verified_by_me): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                                                    <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                                                    <input type="hidden" name="status" value="nominated">
                                                                    <button type="submit" name="update_candidate_status" class="btn btn-warning btn-sm" 
                                                                            onclick="return confirm('Revert verification?')" title="Revert Verification">
                                                                        <i class="fas fa-undo"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <?php elseif ($action === 'results'): ?>
                            <!-- Results Tab -->
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
                                                            <?php echo $candidate['percentage']; ?>%
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
                                <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                                    <i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <h3>Results Not Available</h3>
                                    <p>Election results will be available after the election is completed.</p>
                                    <?php if ($election['status'] !== 'completed'): ?>
                                        <p>Current status: <span class="status-badge status-<?php echo $election['status']; ?>">
                                            <?php echo ucfirst($election['status']); ?>
                                        </span></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($action === 'voters'): ?>
                            <!-- Voters Tab -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Voters List</h3>
                                    <small>Total: <?php echo $total_voters; ?> voters | Voted: <?php echo $voters_voted; ?></small>
                                </div>
                                <div class="card-body">
                                    <!-- Add Voter Form (for special cases) -->
                                    <?php if ($election['status'] === 'nomination' || $election['status'] === 'campaign'): ?>
                                        <div class="card" style="margin-bottom: 1.5rem;">
                                            <div class="card-header">
                                                <h4>Add Voter (Special Cases)</h4>
                                            </div>
                                            <div class="card-body">
                                                <form method="POST">
                                                    <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                                    <div class="form-row">
                                                        <div class="form-group">
                                                            <label for="reg_number">Registration Number *</label>
                                                            <input type="text" class="form-control" id="reg_number" name="reg_number" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label for="student_name">Student Name *</label>
                                                            <input type="text" class="form-control" id="student_name" name="student_name" required>
                                                        </div>
                                                    </div>
                                                    <div class="form-row">
                                                        <div class="form-group form-full">
                                                            <button type="submit" name="add_voter" class="btn btn-primary">
                                                                <i class="fas fa-plus"></i> Add Voter
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (empty($voters)): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                            <p>No voters found</p>
                                        </div>
                                    <?php else: ?>
                                        <div style="overflow-x: auto;">
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>Registration No.</th>
                                                        <th>Student Name</th>
                                                        <th>Voting Status</th>
                                                        <th>Voted At</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($voters as $voter): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($voter['reg_number']); ?></td>
                                                            <td><?php echo htmlspecialchars($voter['student_name']); ?></td>
                                                            <td>
                                                                <?php if ($voter['has_voted']): ?>
                                                                    <span class="status-badge status-completed">
                                                                        <i class="fas fa-check"></i> Voted
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="status-badge status-nomination">
                                                                        <i class="fas fa-clock"></i> Not Voted
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($voter['voted_at']): ?>
                                                                    <?php echo date('M j, Y g:i A', strtotime($voter['voted_at'])); ?>
                                                                <?php else: ?>
                                                                    <span style="color: var(--dark-gray);">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if ($total_voters > 50): ?>
                                            <div style="text-align: center; margin-top: 1rem; padding: 1rem; background: var(--light-gray); border-radius: var(--border-radius);">
                                                <p style="color: var(--dark-gray);">
                                                    Showing 50 of <?php echo $total_voters; ?> voters. 
                                                    <a href="#" style="color: var(--primary-blue);">View all voters</a>
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
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

        // Auto-refresh for active elections
        <?php if ($action !== 'list' && $election && in_array($election['status'], ['nomination', 'campaign', 'voting'])): ?>
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                console.log('Refreshing election data...');
                window.location.reload();
            }
        }, 30000); // Refresh every 30 seconds for active elections
        <?php endif; ?>
    </script>
</body>
</html>