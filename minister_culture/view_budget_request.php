<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Culture
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_culture') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: action-funding.php');
    exit();
}

$request_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Get budget request details
try {
    $stmt = $pdo->prepare("
        SELECT cbr.*, cm.name as committee_member_name, cm.role,
               u.full_name as requester_name, u.email as requester_email, u.phone as requester_phone
        FROM committee_budget_requests cbr
        LEFT JOIN committee_members cm ON cbr.committee_id = cm.id
        LEFT JOIN users u ON cbr.requested_by = u.id
        WHERE cbr.id = ? AND cbr.requested_by = ?
    ");
    $stmt->execute([$request_id, $user_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        die('Request not found or access denied');
    }
    
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Budget Request - Minister of Culture & Civic Education - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #a78bfa;
            --accent-purple: #7c3aed;
            --light-purple: #f3f4f6;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .nav-container {
            max-width: 1200px;
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
            position: relative;
        }

        .mobile-back-btn {
            display: none;
            background: var(--light-gray);
            border: none;
            color: var(--text-dark);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1rem;
            transition: var(--transition);
        }

        .mobile-back-btn:hover {
            background: var(--primary-purple);
            color: white;
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-purple);
        }

        .back-btn {
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .page-title p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-outline:hover {
            background: var(--light-purple);
        }

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

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-group {
            margin-bottom: 1rem;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-draft { background: #e9ecef; color: #6c757d; }
        .status-submitted { background: #fff3cd; color: var(--warning); }
        .status-under_review { background: #cce7ff; color: var(--primary-purple); }
        .status-approved_by_finance { background: #d4edda; color: var(--success); }
        .status-approved_by_president { background: #d4edda; color: var(--success); }
        .status-rejected { background: #f8d7da; color: var(--danger); }
        .status-funded { background: #d1ecf1; color: #0c5460; }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--medium-gray);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-purple);
            border: 2px solid var(--white);
            box-shadow: 0 0 0 2px var(--primary-purple);
        }

        .timeline-item.completed::before {
            background: var(--success);
            box-shadow: 0 0 0 2px var(--success);
        }

        .timeline-item.pending::before {
            background: var(--warning);
            box-shadow: 0 0 0 2px var(--warning);
        }

        .timeline-date {
            font-size: 0.75rem;
            color: var(--dark-gray);
            margin-bottom: 0.25rem;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .file-download {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
        }

        .file-download:hover {
            background: var(--light-gray);
            border-color: var(--primary-purple);
        }

        .file-icon {
            width: 40px;
            height: 40px;
            background: var(--light-purple);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-purple);
            font-size: 1.2rem;
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .file-size {
            font-size: 0.75rem;
            color: var(--dark-gray);
        }

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

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left-color: var(--danger);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: var(--warning);
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .nav-container {
                padding: 0 1rem;
                gap: 0.5rem;
            }

            .back-btn {
                display: none;
            }

            .mobile-back-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 1rem;
            }

            .logo {
                height: 32px;
            }

            .brand-text h1 {
                font-size: 0.9rem;
            }

            .page-title h1 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <button class="mobile-back-btn" onclick="window.location.href='action-funding.php'" title="Back to Requests">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Minister of Culture & Civic Education</h1>
                </div>
            </div>
            <a href="action-funding.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Requests
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>Budget Request #<?php echo $request['id']; ?></h1>
                <p>View detailed information about your funding request</p>
            </div>
        </div>

        <!-- Request Details -->
        <div class="card">
            <div class="card-header">
                <h3>Request Information</h3>
                <span class="status-badge status-<?php echo $request['status']; ?>">
                    <?php echo str_replace('_', ' ', $request['status']); ?>
                </span>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div>
                        <div class="info-group">
                            <div class="info-label">Request Title</div>
                            <div class="info-value"><?php echo htmlspecialchars($request['request_title']); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label">Purpose</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($request['purpose'])); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label">Requested Amount</div>
                            <div class="info-value">RWF <?php echo number_format($request['requested_amount'], 2); ?></div>
                        </div>
                        <?php if ($request['approved_amount']): ?>
                        <div class="info-group">
                            <div class="info-label">Approved Amount</div>
                            <div class="info-value">RWF <?php echo number_format($request['approved_amount'], 2); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="info-group">
                            <div class="info-label">Request Date</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($request['request_date'])); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label">Requester</div>
                            <div class="info-value"><?php echo htmlspecialchars($request['requester_name']); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label">Position</div>
                            <div class="info-value"><?php echo str_replace('_', ' ', $request['role']); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label">Contact</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($request['requester_email']); ?><br>
                                <?php echo htmlspecialchars($request['requester_phone']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Plan File -->
                <?php if (!empty($request['action_plan_file_path'])): ?>
                <div class="info-group">
                    <div class="info-label">Action Plan Document</div>
                    <a href="../<?php echo $request['action_plan_file_path']; ?>" class="file-download" target="_blank">
                        <div class="file-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="file-info">
                            <div class="file-name">Download Action Plan</div>
                            <div class="file-size">Click to view uploaded document</div>
                        </div>
                        <i class="fas fa-download"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Approval Timeline -->
        <div class="card">
            <div class="card-header">
                <h3>Approval Timeline</h3>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <!-- Submission -->
                    <div class="timeline-item completed">
                        <div class="timeline-date"><?php echo date('F j, Y', strtotime($request['request_date'])); ?></div>
                        <div class="timeline-title">Request Submitted</div>
                        <div class="timeline-content">Budget request was submitted for review</div>
                    </div>

                    <!-- Finance Approval -->
                    <div class="timeline-item <?php echo $request['finance_approval_date'] ? 'completed' : 'pending'; ?>">
                        <div class="timeline-date">
                            <?php echo $request['finance_approval_date'] ? date('F j, Y', strtotime($request['finance_approval_date'])) : 'Pending'; ?>
                        </div>
                        <div class="timeline-title">Finance Committee Review</div>
                        <div class="timeline-content">
                            <?php if ($request['finance_approval_date']): ?>
                                Approved by Finance Committee
                                <?php if ($request['finance_approval_notes']): ?>
                                    <br><em><?php echo htmlspecialchars($request['finance_approval_notes']); ?></em>
                                <?php endif; ?>
                            <?php else: ?>
                                Awaiting finance committee review
                            <?php endif; ?>
                        </div>
                    </div>

<!-- President Approval -->
<div class="timeline-item <?php echo ($request['status'] === 'approved_by_president' || $request['status'] === 'funded' || $request['president_approval_date']) ? 'completed' : 'pending'; ?>">
    <div class="timeline-date">
        <?php 
        if ($request['status'] === 'approved_by_president' || $request['status'] === 'funded') {
            echo $request['president_approval_date'] 
                ? date('F j, Y', strtotime($request['president_approval_date'])) 
                : date('F j, Y', strtotime($request['updated_at']));
        } else {
            echo 'Pending';
        }
        ?>
    </div>
    <div class="timeline-title">President's Office Review</div>
    <div class="timeline-content">
        <?php if ($request['status'] === 'approved_by_president' || $request['status'] === 'funded'): ?>
            Approved by President's Office
            <?php if ($request['president_approval_notes']): ?>
                <br><em><?php echo htmlspecialchars($request['president_approval_notes']); ?></em>
            <?php endif; ?>
        <?php else: ?>
            Awaiting president's office review
        <?php endif; ?>
    </div>
</div>

                    <!-- Funding -->
                    <div class="timeline-item <?php echo $request['status'] === 'funded' ? 'completed' : 'pending'; ?>">
                        <div class="timeline-date">
                            <?php echo $request['status'] === 'funded' ? 'Completed' : 'Pending'; ?>
                        </div>
                        <div class="timeline-title">Fund Disbursement</div>
                        <div class="timeline-content">
                            <?php if ($request['status'] === 'funded'): ?>
                                Funds have been disbursed
                            <?php else: ?>
                                Funds will be disbursed after full approval
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Letter Section -->
        <?php 
        $is_approved = in_array($request['status'], ['approved_by_finance', 'approved_by_president', 'funded']);
        ?>

        <?php if ($is_approved): ?>
        <div class="card">
            <div class="card-header">
                <h3>Approval Letter</h3>
                <span class="status-badge status-<?php echo $request['status']; ?>">
                    <?php echo str_replace('_', ' ', $request['status']); ?>
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Approval Letter Process</strong><br>
                    <ol style="margin: 0.5rem 0 0 1rem;">
                        <li>Download the approval letter using the button below</li>
                        <li>Print the letter and get physical signatures from both Minister of Culture and Vice Guild Finance</li>
                        <li>Submit the signed letter to the Finance Office for fund disbursement</li>
                        <li>Keep a copy of the signed letter for your records</li>
                    </ol>
                </div>

                <a href="generate_approval_letter.php?id=<?php echo $request_id; ?>" class="btn btn-success">
                    <i class="fas fa-download"></i> Download Approval Letter
                </a>

                <div class="form-text" style="margin-top: 1rem;">
                    <i class="fas fa-lightbulb"></i> 
                    <strong>Note:</strong> This letter includes space for physical signatures. No file upload is required.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>