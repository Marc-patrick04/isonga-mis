<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: committee_budget_requests.php');
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

// Handle PDF generation
if (isset($_POST['generate_letter']) && $request['status'] === 'approved_by_president') {
    require_once '../tcpdf/tcpdf.php';
    
    try {
        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Isonga RPSU');
        $pdf->SetAuthor('Isonga RPSU');
        $pdf->SetTitle('Budget Approval Letter - ' . $request['request_title']);
        $pdf->SetSubject('Budget Approval Letter');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', '', 10);
        
        // Logos and header
        $header = '
        <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td width="20%" align="left">
                    <img src="../assets/images/rp_logo.png" width="80" />
                </td>
                <td width="60%" align="center">
                    <h2 style="margin: 0; color: #2E7D32;">ISONGARPSU - RWANDA POLYTECHNIC STUDENTS\' UNION</h2>
                    <p style="margin: 0; font-size: 12px;">Musanze College</p>
                    <p style="margin: 0; font-size: 12px;">P.O. Box 100 Musanze, Rwanda</p>
                </td>
                <td width="20%" align="right">
                    <img src="../assets/images/isonga_logo.png" width="80" />
                </td>
            </tr>
        </table>
        <hr style="margin: 10px 0; border: 1px solid #2E7D32;">
        ';
        
        $pdf->writeHTML($header, true, false, true, false, '');
        
        // Date
        $date = date('F j, Y');
        $pdf->SetY($pdf->GetY() + 10);
        $pdf->Write(0, $date, '', 0, 'R', true);
        
        // Title
        $pdf->SetY($pdf->GetY() + 10);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Write(0, 'BUDGET APPROVAL LETTER', '', 0, 'C', true);
        $pdf->SetFont('helvetica', '', 10);
        
        // Content
        $content = '
        <br><br>
        <p>TO: <strong>' . htmlspecialchars($request['requester_name']) . '</strong><br>
        Position: <strong>' . htmlspecialchars(str_replace('_', ' ', $request['role'])) . '</strong><br>
        Email: ' . htmlspecialchars($request['requester_email']) . '<br>
        Phone: ' . htmlspecialchars($request['requester_phone']) . '</p>
        
        <br>
        <p>Dear <strong>' . htmlspecialchars($request['requester_name']) . '</strong>,</p>
        
        <p>We are pleased to inform you that your budget request has been approved by both the Finance Committee and the President\'s Office. The details of your approved request are as follows:</p>
        
        <table border="0" cellpadding="5" cellspacing="0" width="100%" style="border: 1px solid #ddd;">
            <tr>
                <td width="30%" style="background-color: #f8f9fa; font-weight: bold;">Request Title:</td>
                <td width="70%">' . htmlspecialchars($request['request_title']) . '</td>
            </tr>
            <tr>
                <td style="background-color: #f8f9fa; font-weight: bold;">Purpose:</td>
                <td>' . htmlspecialchars($request['purpose']) . '</td>
            </tr>
            <tr>
                <td style="background-color: #f8f9fa; font-weight: bold;">Requested Amount:</td>
                <td>RWF ' . number_format($request['requested_amount'], 2) . '</td>
            </tr>
            <tr>
                <td style="background-color: #f8f9fa; font-weight: bold;">Approved Amount:</td>
                <td>RWF ' . number_format($request['approved_amount'] ?: $request['requested_amount'], 2) . '</td>
            </tr>
            <tr>
                <td style="background-color: #f8f9fa; font-weight: bold;">Request Date:</td>
                <td>' . date('F j, Y', strtotime($request['request_date'])) . '</td>
            </tr>
            <tr>
                <td style="background-color: #f8f9fa; font-weight: bold;">Finance Approval:</td>
                <td>' . ($request['finance_approval_date'] ? date('F j, Y', strtotime($request['finance_approval_date'])) : 'Pending') . '</td>
            </tr>
            <tr>
                <td style="background-color: #f8f9fa; font-weight: bold;">President Approval:</td>
                <td>' . ($request['president_approval_date'] ? date('F j, Y', strtotime($request['president_approval_date'])) : 'Pending') . '</td>
            </tr>
        </table>
        
        <br>
        <p><strong>Instructions for Fund Disbursement:</strong></p>
        <ol>
            <li>Present this letter to the Finance Office during working hours (8:00 AM - 5:00 PM, Monday - Friday)</li>
            <li>Provide valid student identification</li>
            <li>Sign the receipt book upon receiving the funds</li>
            <li>Funds must be used strictly for the purpose stated in your request</li>
            <li>Submit a utilization report within 30 days of receiving the funds</li>
        </ol>
        
        <br>
        <p><strong>Important Notes:</strong></p>
        <ul>
            <li>This approval is valid for 30 days from the date of this letter</li>
            <li>Any changes to the budget utilization must be approved in writing</li>
            <li>Failure to submit a utilization report may affect future funding requests</li>
            <li>Misuse of funds will result in disciplinary action</li>
        </ul>
        
        <br>
        <p>We trust that this funding will support the successful implementation of your committee\'s action plan and contribute to the academic excellence of our institution.</p>
        
        <p>Yours sincerely,</p>
        
        <br><br><br>
        
        <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td width="50%">
                    <div style="border-top: 1px solid #000; width: 80%; padding-top: 10px;">
                        <strong>Finance Committee</strong><br>
                        Finance Committee Chairperson<br>
                        Isonga RPSU - Musanze College
                    </div>
                </td>
                <td width="50%">
                    <div style="border-top: 1px solid #000; width: 80%; padding-top: 10px;">
                        <strong>Student Guild President</strong><br>
                        Student Guild President<br>
                        Isonga RPSU - Musanze College
                    </div>
                </td>
            </tr>
        </table>
        
        <br><br>
        
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8f9fa; padding: 10px;">
            <tr>
                <td align="center">
                    <strong>RECEIPT ACKNOWLEDGEMENT</strong><br>
                    <small>To be completed by Finance Office</small>
                </td>
            </tr>
        </table>
        
        <br>
        
        <table border="1" cellpadding="10" cellspacing="0" width="100%" style="border-collapse: collapse;">
            <tr>
                <td width="25%" align="center"><strong>Received By (Signature)</strong></td>
                <td width="25%" align="center"><strong>Date Received</strong></td>
                <td width="25%" align="center"><strong>Finance Officer (Signature)</strong></td>
                <td width="25%" align="center"><strong>Amount Disbursed</strong></td>
            </tr>
            <tr height="40">
                <td></td>
                <td></td>
                <td></td>
                <td align="center">RWF ' . number_format($request['approved_amount'] ?: $request['requested_amount'], 2) . '</td>
            </tr>
        </table>
        ';
        
        $pdf->writeHTML($content, true, false, true, false, '');
        
        // Save PDF file
        $filename = 'approval_letter_' . $request_id . '_' . date('Ymd_His') . '.pdf';
        $filepath = '../uploads/approval_letters/' . $filename;
        
        // Ensure directory exists
        if (!is_dir('../uploads/approval_letters/')) {
            mkdir('../uploads/approval_letters/', 0755, true);
        }
        
        $pdf->Output('../' . $filepath, 'F');
        
        // Update database with generated letter path
        $stmt = $pdo->prepare("UPDATE committee_budget_requests SET generated_letter_path = ? WHERE id = ?");
        $stmt->execute([$filepath, $request_id]);
        
        // Refresh to show updated data
        header('Location: view_budget_request.php?id=' . $request_id);
        exit();
        
    } catch (Exception $e) {
        $error = 'PDF generation error: ' . $e->getMessage();
    }
}

// Handle download
if (isset($_GET['download']) && $request['status'] === 'approved_by_president') {
    if (empty($request['generated_letter_path'])) {
        // Generate the letter first
        header('Location: view_budget_request.php?id=' . $request_id . '&generate=1');
        exit();
    }
    
    $filepath = '../' . $request['generated_letter_path'];
    
    if (!file_exists($filepath)) {
        // Regenerate if file doesn't exist
        header('Location: view_budget_request.php?id=' . $request_id . '&generate=1');
        exit();
    }
    
    // Output PDF for download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="approval_letter_' . $request_id . '.pdf"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Budget Request - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            --academic-primary: #2E7D32;
            --academic-secondary: #4CAF50;
            --academic-accent: #1B5E20;
            --academic-light: #E8F5E8;
            --gradient-primary: linear-gradient(135deg, var(--academic-primary) 0%, var(--academic-accent) 100%);
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
        }

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--academic-primary);
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
            border: 1px solid var(--academic-primary);
            color: var(--academic-primary);
        }

        .btn-outline:hover {
            background: var(--academic-light);
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
        .status-under_review { background: #cce7ff; color: var(--primary-blue); }
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
            background: var(--academic-primary);
            border: 2px solid var(--white);
            box-shadow: 0 0 0 2px var(--academic-primary);
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
            border-color: var(--academic-primary);
        }

        .file-icon {
            width: 40px;
            height: 40px;
            background: var(--academic-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--academic-primary);
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

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Budget Request Details</h1>
                </div>
            </div>
            <a href="committee_budget_requests.php" class="back-btn">
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

        <!-- Alerts -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['generated']) && $_GET['generated'] == '1'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Approval letter generated successfully!
            </div>
        <?php endif; ?>

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
                <li>Print the letter and get physical signatures from both Vice Guild Academic and Vice Guild Finance</li>
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


    <script>
        // Auto-generate letter if parameter is set
        <?php if (isset($_GET['generate']) && $_GET['generate'] == '1' && $request['status'] === 'approved_by_president'): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelector('form button[name="generate_letter"]').click();
            });
        <?php endif; ?>
    </script>
</body>
</html>