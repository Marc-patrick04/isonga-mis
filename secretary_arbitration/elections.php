<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Secretary Arbitration
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'secretary_arbitration') {
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

// Handle form submissions for Secretary-specific actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_voter'])) {
        // Add voter to election
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
                $_SESSION['error_message'] = "Voter already exists in this election!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO election_voters 
                    (election_id, reg_number, student_name, has_voted)
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->execute([$election_id, $reg_number, $student_name]);
                
                $_SESSION['success_message'] = "Voter added successfully!";
            }
            
            header('Location: elections.php?action=manage&id=' . $election_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding voter: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['bulk_upload_voters'])) {
        // Handle bulk voter upload
        $election_id = $_POST['election_id'];
        
        if (isset($_FILES['voter_file']) && $_FILES['voter_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['voter_file']['tmp_name'];
            $handle = fopen($file, 'r');
            $added = 0;
            $skipped = 0;
            
            // Skip header row if CSV
            fgetcsv($handle);
            
            try {
                $pdo->beginTransaction();
                
                while (($data = fgetcsv($handle)) !== FALSE) {
                    if (count($data) >= 2) {
                        $reg_number = trim($data[0]);
                        $student_name = trim($data[1]);
                        
                        if (!empty($reg_number) && !empty($student_name)) {
                            // Check if voter exists
                            $stmt = $pdo->prepare("
                                SELECT id FROM election_voters 
                                WHERE election_id = ? AND reg_number = ?
                            ");
                            $stmt->execute([$election_id, $reg_number]);
                            
                            if (!$stmt->fetch()) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO election_voters 
                                    (election_id, reg_number, student_name, has_voted)
                                    VALUES (?, ?, ?, 0)
                                ");
                                $stmt->execute([$election_id, $reg_number, $student_name]);
                                $added++;
                            } else {
                                $skipped++;
                            }
                        }
                    }
                }
                
                $pdo->commit();
                fclose($handle);
                
                $_SESSION['success_message'] = "Bulk upload completed: $added voters added, $skipped skipped (already exists).";
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error_message'] = "Error during bulk upload: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "Please select a valid CSV file.";
        }
        
        header('Location: elections.php?action=manage&id=' . $election_id);
        exit();
    }
    
    if (isset($_POST['generate_report'])) {
        // Generate election report
        $election_id = $_POST['election_id'];
        $report_type = $_POST['report_type'];
        
        try {
            // This would typically generate a PDF or Excel file
            // For now, we'll just set a success message
            $_SESSION['success_message'] = ucfirst($report_type) . " report generated successfully!";
            
            header('Location: elections.php?action=results&id=' . $election_id);
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error generating report: " . $e->getMessage();
        }
    }
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$election_id = $_GET['id'] ?? 0;

// Get elections list - Secretary can see all elections for documentation purposes
try {
    $stmt = $pdo->query("
        SELECT e.*, 
               (SELECT COUNT(*) FROM election_candidates WHERE election_id = e.id) as candidate_count,
               (SELECT COUNT(*) FROM election_voters WHERE election_id = e.id AND has_voted = 1) as votes_cast,
               (SELECT COUNT(*) FROM election_voters WHERE election_id = e.id) as total_voters,
               ec.committee_name
        FROM elections e 
        LEFT JOIN election_committees ec ON e.election_committee_id = ec.id
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

if ($election_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
        $stmt->execute([$election_id]);
        $election = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($election) {
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
            
            // Get voters
            $stmt = $pdo->prepare("
                SELECT * FROM election_voters 
                WHERE election_id = ? 
                ORDER BY student_name
            ");
            $stmt->execute([$election_id]);
            $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total voters
            $total_voters = count($voters);
            
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

// Get statistics for all elections
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM elections");
    $total_elections = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM elections WHERE status IN ('nomination', 'campaign', 'voting')");
    $active_elections = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as completed FROM elections WHERE status = 'completed'");
    $completed_elections = $stmt->fetch(PDO::FETCH_ASSOC)['completed'];
    
    // Get total candidates across all elections
    $stmt = $pdo->query("SELECT COUNT(*) as total_candidates FROM election_candidates");
    $total_candidates = $stmt->fetch(PDO::FETCH_ASSOC)['total_candidates'];
    
    // Get total voters across all elections
    $stmt = $pdo->query("SELECT COUNT(*) as total_voters FROM election_voters");
    $total_voters_all = $stmt->fetch(PDO::FETCH_ASSOC)['total_voters'];
} catch (PDOException $e) {
    $total_elections = $active_elections = $completed_elections = $total_candidates = $total_voters_all = 0;
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

        .main-content {
            padding: 1.5rem;
            overflow-y: auto;
            height: calc(100vh - 80px);
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
            border-left: 3px solid var(--primary-blue);
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

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
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

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.8rem;
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

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
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

        .alert-info {
            background: #cce7ff;
            color: #0c5460;
            border-left-color: var(--primary-blue);
        }

        /* Voter Status */
        .voter-status {
            padding: 0.2rem 0.4rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .voter-voted {
            background: #d4edda;
            color: var(--success);
        }

        .voter-not-voted {
            background: #fff3cd;
            color: var(--warning);
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
        }

        .file-upload:hover {
            border-color: var(--primary-blue);
        }

        .file-upload i {
            font-size: 2rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        /* Report Options */
        .report-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .report-option {
            background: var(--light-gray);
            border-radius: var(--border-radius);
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .report-option:hover {
            background: var(--light-blue);
            transform: translateY(-2px);
        }

        .report-option i {
            font-size: 1.5rem;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
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
        }

        @media (max-width: 480px) {
            .stats-grid {
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
                    <h1>Isonga - Arbitration Secretary</h1>
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
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Arbitration Secretary</div>
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
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="cases.php">
                        <i class="fas fa-balance-scale"></i>
                        <span>All Cases</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="case-notes.php">
                        <i class="fas fa-sticky-note"></i>
                        <span>Case Notes</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Documents</span>
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
                    <a href="elections.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Elections
                    </a>

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
                    <strong>Secretary Access:</strong> You can manage voter lists, generate reports, and maintain election documentation.
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-vote-yea"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $total_elections; ?></div>
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
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $total_voters_all; ?></div>
                            <div class="stat-label">Total Voters</div>
                        </div>
                    </div>
                </div>

                <!-- Elections Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>All Elections</h3>
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
                                        <th>Type</th>
                                        <th>Committee</th>
                                        <th>Status</th>
                                        <th>Candidates</th>
                                        <th>Voters</th>
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
                                                <div style="font-size: 0.7rem; color: var(--dark-gray); margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars($election['description']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="position-badge">
                                                    <?php echo ucfirst(str_replace('_', ' ', $election['election_type'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $election['committee_name'] ? htmlspecialchars($election['committee_name']) : '<span style="color: var(--dark-gray);">N/A</span>'; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $election['status']; ?>">
                                                    <?php echo ucfirst($election['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $election['candidate_count']; ?></td>
                                            <td><?php echo $election['total_voters']; ?></td>
                                            <td><?php echo $election['votes_cast']; ?></td>
                                            <td>
                                                <div style="display: flex; gap: 0.25rem;">
                                                    <a href="elections.php?action=manage&id=<?php echo $election['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="Manage Voters">
                                                        <i class="fas fa-users"></i>
                                                    </a>
                                                    <a href="elections.php?action=results&id=<?php echo $election['id']; ?>" 
                                                       class="btn btn-outline btn-sm" title="Results & Reports">
                                                        <i class="fas fa-chart-bar"></i>
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

            <?php elseif (in_array($action, ['manage', 'results']) && $election): ?>
                <!-- Election Management View -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3><?php echo htmlspecialchars($election['title']); ?> - <?php echo htmlspecialchars($election['academic_year']); ?></h3>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                                <span class="status-badge status-<?php echo $election['status']; ?>">
                                    <?php echo ucfirst($election['status']); ?>
                                </span>
                                <span style="font-size: 0.8rem; color: var(--dark-gray);">
                                    Secretary: Voter Management & Documentation
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
                                    <?php if ($data['date']): ?>
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
                                <i class="fas fa-users"></i> Voter Management
                            </button>
                            <button class="tab <?php echo $action === 'results' ? 'active' : ''; ?>" 
                                    onclick="window.location.href='elections.php?action=results&id=<?php echo $election_id; ?>'">
                                <i class="fas fa-chart-bar"></i> Results & Reports
                            </button>
                        </div>

                        <?php if ($action === 'manage'): ?>
                            <!-- Voter Management Tab -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Add Voters</h3>
                                </div>
                                <div class="card-body">
                                    <!-- Single Voter Form -->
                                    <form method="POST" style="margin-bottom: 2rem;">
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

                                    <!-- Bulk Upload -->
                                    <div class="file-upload">
                                        <i class="fas fa-file-csv"></i>
                                        <h4>Bulk Upload Voters</h4>
                                        <p style="color: var(--dark-gray); margin-bottom: 1rem;">
                                            Upload a CSV file with columns: Registration Number, Student Name
                                        </p>
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                            <div class="form-group">
                                                <input type="file" name="voter_file" accept=".csv" required>
                                            </div>
                                            <button type="submit" name="bulk_upload_voters" class="btn btn-outline">
                                                <i class="fas fa-upload"></i> Upload CSV
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Voters List -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Voter List (<?php echo count($voters); ?>)</h3>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($voters)): ?>
                                        <div style="text-align: center; padding: 2rem; color: var(--dark-gray);">
                                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                            <p>No voters added yet</p>
                                        </div>
                                    <?php else: ?>
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
                                                        <td><strong><?php echo htmlspecialchars($voter['reg_number']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($voter['student_name']); ?></td>
                                                        <td>
                                                            <span class="voter-status <?php echo $voter['has_voted'] ? 'voter-voted' : 'voter-not-voted'; ?>">
                                                                <?php echo $voter['has_voted'] ? 'Voted' : 'Not Voted'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo $voter['voted_at'] ? date('M j, Y g:i A', strtotime($voter['voted_at'])) : 'N/A'; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <?php elseif ($action === 'results'): ?>
                            <!-- Results & Reports Tab -->
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
                                        $total_votes = array_sum(array_column($candidates, 'vote_count'));
                                        $turnout = $total_voters > 0 ? round(($total_votes / $total_voters) * 100) : 0;
                                        ?>
                                        <div class="stat-number"><?php echo $turnout; ?>%</div>
                                        <div class="stat-label">Voter Turnout</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Report Generation -->
                            <div class="card">
                                <div class="card-header">
                                    <h3>Generate Reports</h3>
                                </div>
                                <div class="card-body">
                                    <div class="report-options">
                                        <div class="report-option" onclick="generateReport('voter_list')">
                                            <i class="fas fa-list"></i>
                                            <h4>Voter List</h4>
                                            <p>Complete list of eligible voters</p>
                                        </div>
                                        <div class="report-option" onclick="generateReport('results')">
                                            <i class="fas fa-chart-bar"></i>
                                            <h4>Election Results</h4>
                                            <p>Detailed voting results</p>
                                        </div>
                                        <div class="report-option" onclick="generateReport('turnout')">
                                            <i class="fas fa-chart-pie"></i>
                                            <h4>Turnout Analysis</h4>
                                            <p>Voter participation statistics</p>
                                        </div>
                                        <div class="report-option" onclick="generateReport('candidate_list')">
                                            <i class="fas fa-user-tie"></i>
                                            <h4>Candidate List</h4>
                                            <p>All nominated candidates</p>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" id="reportForm" style="display: none;">
                                        <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">
                                        <input type="hidden" name="report_type" id="reportType">
                                        <button type="submit" name="generate_report" class="btn btn-primary">
                                            <i class="fas fa-download"></i> Generate Selected Report
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <?php if ($election['status'] === 'completed' && !empty($results)): ?>
                                <!-- Election Results -->
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Election Results</h3>
                                    </div>
                                    <div class="card-body">
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
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 3rem; color: var(--dark-gray);">
                                    <i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
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
                    Election not found.
                </div>
            <?php endif; ?>
        </main>
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

        // Report Generation
        function generateReport(type) {
            document.getElementById('reportType').value = type;
            document.getElementById('reportForm').style.display = 'block';
            
            // Scroll to form
            document.getElementById('reportForm').scrollIntoView({ behavior: 'smooth' });
        }
    </script>
</body>
</html>