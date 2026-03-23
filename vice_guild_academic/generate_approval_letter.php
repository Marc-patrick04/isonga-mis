<?php
session_start();
require_once '../config/database.php';
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in and is Vice Guild Academic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_academic') {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    die('Invalid request');
}

$request_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get budget request details
    $stmt = $pdo->prepare("
        SELECT cbr.*, 
               u.full_name as requester_name, 
               u.email as requester_email, 
               u.phone as requester_phone,
               u.reg_number as requester_reg
        FROM committee_budget_requests cbr
        LEFT JOIN users u ON cbr.requested_by = u.id
        WHERE cbr.id = ? AND cbr.requested_by = ?
    ");
    $stmt->execute([$request_id, $user_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        die('Request not found or access denied');
    }
    
    // Check if request is approved
    $allowed_statuses = ['approved_by_finance', 'approved_by_president', 'funded'];
    if (!in_array($request['status'], $allowed_statuses)) {
        die('Request must be approved before generating letter');
    }
    
    // Get Vice Guild Finance details
    $vice_guild_stmt = $pdo->prepare("
        SELECT full_name, email, phone 
        FROM users 
        WHERE role = 'vice_guild_finance' AND status = 'active' 
        LIMIT 1
    ");
    $vice_guild_stmt->execute();
    $vice_guild = $vice_guild_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vice_guild) {
        $vice_guild = [
            'full_name' => '________________________',
            'email' => 'iprcmusanzesu@gmail.com',
            'phone' => '+250 788 000 000'
        ];
    }
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('RPSU - RP Musanze College');
    $pdf->SetAuthor('RPSU - RP Musanze College');
    $pdf->SetTitle('Vice Guild Academic Budget Approval Letter');
    $pdf->SetSubject('Budget Approval');
    
    // Set margins
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();

    // Centered Isonga Logo
    $logo = '../assets/images/logo.png';
    if (file_exists($logo)) {
        $pageWidth = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
        $logoWidth = 40;
        $logoX = ($pageWidth - $logoWidth) / 2 + $pdf->getMargins()['left'];
        
        $pdf->Image($logo, $logoX, 15, $logoWidth, 0, 'PNG');
        $pdf->Ln(25);
        
        // Add a line after the logo
        $pdf->SetLineWidth(0.5);
        $pdf->Line($pdf->getMargins()['left'], $pdf->GetY(), $pdf->getPageWidth() - $pdf->getMargins()['right'], $pdf->GetY());
        $pdf->Ln(15);
    }

    // Header text - Centered
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 51, 102); // Dark blue color
    $pdf->Cell(0, 10, 'RPSU MUSANZE COLLEGE', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(46, 125, 50); // Green color for academics
    $pdf->Cell(0, 10, 'VICE GUILD ACADEMIC PROGRAM', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 0, 0); // Black color
    $pdf->Cell(0, 10, 'BUDGET APPROVAL LETTER', 0, 1, 'C');

    $pdf->Ln(5);

    // Reference Line
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(128, 128, 128); // Gray color
    $pdf->Cell(0, 10, 'Ref: RPSU/MC/VGAC/' . date('Y') . '/' . str_pad($request_id, 4, '0', STR_PAD_LEFT), 0, 1, 'R');

    $pdf->Ln(10);

    // Date
    $pdf->SetFont('helvetica', '', 12);
    $pdf->SetTextColor(0, 0, 0); // Black color
    $pdf->Cell(0, 10, 'Date: ' . date('F j, Y'), 0, 1, 'R');

    $pdf->Ln(15);

    // Letter Body
    $pdf->SetFont('helvetica', '', 12);

    // Introduction
    $intro = "I, " . $request['requester_name'] . ", serving as Vice Guild Academic at RP Musanze College, hereby strongly prove that I requested a budget of RWF " . 
        number_format($request['requested_amount'], 2) . " for " . $request['purpose'] . " and have been approved an amount of RWF " . 
        number_format($request['approved_amount'] ?: $request['requested_amount'], 2) . ".\n\n";

    $pdf->MultiCell(0, 8, $intro, 0, 'J');

    // Purpose and Evidence Section
    $purpose = "This letter serves as official evidence and reference for the Vice Guild Academic budget approved to support: " . 
        $request['purpose'] . ". The funds will be utilized for academic excellence programs, " .
        "learning enhancement activities, and educational development as stated in the application.\n\n";

    $pdf->MultiCell(0, 8, $purpose, 0, 'J');

    // Financial Details Table
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'ACADEMIC BUDGET DETAILS:', 0, 1);
    $pdf->SetFont('helvetica', '', 11);

    $approved_amount = $request['approved_amount'] ?: $request['requested_amount'];
    
    $table = '<table border="0.5" cellpadding="4">
    <tr style="background-color:#f2f2f2;">
        <td width="50%"><b>Item</b></td>
        <td width="50%"><b>Amount (RWF)</b></td>
    </tr>
    <tr>
        <td>Requested Amount</td>
        <td align="right">' . number_format($request['requested_amount'], 2) . '</td>
    </tr>
    <tr>
        <td>Approved Amount</td>
        <td align="right">' . number_format($approved_amount, 2) . '</td>
    </tr>
    <tr style="background-color:#f2f2f2;">
        <td><b>Total Approved for Academic Programs</b></td>
        <td align="right"><b>' . number_format($approved_amount, 2) . '</b></td>
    </tr>
    </table>';

    $pdf->writeHTML($table, true, false, false, false, '');
    $pdf->Ln(10);

    // Guidelines Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 10, 'GUIDELINES FOR ACADEMIC BUDGET UTILIZATION:', 0, 1, 'L', true);
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $guidelines = "1. The approved amount MUST be used exclusively for: " . $request['purpose'] . "\n" .
        "2. Funds allocated for academic programs, learning resources, and educational activities\n" .
        "3. Budget must support activities that enhance student academic performance\n" .
        "4. Keep all original receipts and invoices for verification purposes\n" .
        "5. Funds must be utilized within 45 days from the date of disbursement\n" .
        "6. Any unused funds must be returned to the Students Guild Treasury\n" .
        "7. Budget reallocation requires prior written approval from the Finance Committee\n" .
        "8. Misuse of funds will result in immediate repayment and disciplinary action\n" .
        "9. Submit detailed expenditure report with academic impact assessment within 30 days\n" .
        "10. Maintain transparent financial records for audit and student accountability\n\n";

    $pdf->MultiCell(0, 8, $guidelines, 0, 'L');

    // Disbursement Instructions
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(46, 125, 50); // Green background for academics
    $pdf->SetTextColor(255, 255, 255); // White text
    $pdf->Cell(0, 10, 'IMPORTANT DISBURSEMENT PROCEDURE:', 0, 1, 'L', true);
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0); // Black text
    $disbursement = "To receive the approved Academic budget, you MUST present this printed letter at the Guild Council Office along with:\n\n" .
        "✓ Original Student ID Card\n" .
        "✓ Printed copy of this approval letter\n" .
        "✓ Completed disbursement form (available at the office)\n" .
        "✓ Detailed academic activity implementation plan\n" .
        "✓ Learning enhancement impact assessment plan\n\n" .
        "Office Hours: Monday - Friday (8:00 AM - 5:00 PM)\n" .
        "Location: Guild Council Office, RP Musanze College\n" .
        "Contact: " . $vice_guild['email'] . "\n\n" .
        "Note: This letter must be presented within 14 days from the date of issue.\n";

    $pdf->MultiCell(0, 8, $disbursement, 0, 'L');
    $pdf->Ln(15);

    // Agreement Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'VICE GUILD ACADEMIC DECLARATION AND AGREEMENT:', 0, 1);
    $pdf->SetFont('helvetica', '', 11);

    $agreement = "I hereby declare that I have read and understood all the terms and conditions stated above. " .
        "As Vice Guild Academic, I agree to use the approved budget responsibly for academic excellence, " .
        "learning enhancement activities, and educational development that benefit the entire student body. I acknowledge that " .
        "any violation of these terms may result in disciplinary action and requirement to repay misused funds.\n";

    $pdf->MultiCell(0, 8, $agreement, 0, 'J');
    $pdf->Ln(15);

    // Signature Sections in Table Format
    $signatureTable = '
    <style>
        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }
        .signature-cell {
            border: 1px solid #ccc;
            padding: 10px;
            vertical-align: top;
            height: 150px;
        }
        .signature-header {
            font-weight: bold;
            text-align: center;
            font-size: 13px;
            margin-bottom: 15px;
            color: #333;
        }
    </style>

    <table class="signature-table">
    <tr>
        <!-- LEFT CELL: APPROVED FOR DISBURSEMENT -->
        <td width="50%" class="signature-cell">
            <div class="signature-header">APPROVED FOR DISBURSEMENT</div>
            <div style="margin-bottom: 10px; font-size: 11px;">
                Vice Guild in charge of<br>
                Finance and Administration
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Name: ' . $vice_guild['full_name'] . '
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Signature: ____________________
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Date: ____________________
            </div>
            <div style="margin-top: 20px; font-size: 11px;">
                Official Stamp:
            </div>
        </td>
        
        <!-- RIGHT CELL: VICE GUILD ACADEMIC ACKNOWLEDGEMENT -->
        <td width="50%" class="signature-cell">
            <div class="signature-header">VICE GUILD ACADEMIC ACKNOWLEDGEMENT</div>
            <div style="margin-bottom: 10px; font-size: 11px;">
                I acknowledge receipt of academic budget: RWF <strong>' . number_format($approved_amount, 2) . '</strong>
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Name: ' . $request['requester_name'] . '
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Position: Vice Guild Academic
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Reg. No: ' . ($request['requester_reg'] ?: 'N/A') . '
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Date: ____________________
            </div>
            <div style="margin-top: 10px; font-size: 11px;">
                Signature: ____________________
            </div>
        </td>
    </tr>
    </table>';

    $pdf->writeHTML($signatureTable, true, false, false, false, '');
    $pdf->Ln(10);

    // Academic Excellence Accountability Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(245, 255, 245); // Light green background
    $pdf->Cell(0, 10, 'ACADEMIC EXCELLENCE DECLARATION', 0, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(3);
    
    $accountability_text = "As Vice Guild Academic, I commit to:\n" .
        "✓ Transparent utilization of funds for academic enhancement\n" .
        "✓ Regular reporting to students on academic program utilization\n" .
        "✓ Upholding the highest standards of educational integrity\n" .
        "✓ Ensuring activities align with academic needs and learning outcomes\n" .
        "✓ Promoting inclusive access to educational resources\n\n" .
        "Signature: __________________________ Date: __________________";
    
    $pdf->MultiCell(0, 8, $accountability_text, 0, 'L');
    $pdf->Ln(10);

    // Academic Specific Notes
    $pdf->SetFont('helvetica', 'I', 11);
    $pdf->SetFillColor(245, 245, 255); // Light blue background
    $pdf->MultiCell(0, 8, 
        "Note: This budget is specifically allocated for academic excellence programs. " .
        "As Vice Guild Academic, you are responsible for ensuring these funds promote quality education, " .
        "learning resources, and academic support for all students. All activities funded must align " .
        "with the college's academic standards and student learning objectives.", 
        0, 'L', true
    );
    $pdf->Ln(10);

    // Office use section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'FOR FINANCE OFFICE USE ONLY', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);

    // Office use table
    $office_use = '
    <table border="1" cellpadding="8" cellspacing="0" width="100%" style="border-collapse: collapse; font-size: 10px;">
        <tr style="background-color:#f2f2f2;">
            <td width="25%" align="center"><strong>Processed By</strong></td>
            <td width="25%" align="center"><strong>Date Processed</strong></td>
            <td width="25%" align="center"><strong>Amount Disbursed</strong></td>
            <td width="25%" align="center"><strong>Reference Number</strong></td>
        </tr>
        <tr height="40">
            <td align="center">__________________</td>
            <td align="center">__________________</td>
            <td align="center">RWF ' . number_format($approved_amount, 2) . '</td>
            <td align="center">__________________</td>
        </tr>
    </table>
    ';

    $pdf->writeHTML($office_use, true, false, true, false, '');
    $pdf->Ln(15);

    // Final Notes
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(128, 128, 128); // Gray color
    $pdf->MultiCell(0, 8, 
        "Note: This document serves as official approval for Vice Guild Academic budget disbursement. " .
        "Any alterations to this document render it invalid. As an academic leader, you are accountable to " .
        "the student body for transparent and responsible use of these funds in promoting academic excellence.\n\n" .
        "Generated on: " . date('F j, Y, g:i A') . " | Isonga Management System v1.0", 
        0, 'C'
    );

    // Add watermark
    $pdf->SetAlpha(0.05);
    $pdf->SetFont('helvetica', 'B', 80);
    $pdf->SetTextColor(200, 200, 200);
    $pdf->Text(15, 150, 'ACADEMIC');
    $pdf->Text(15, 230, 'EXCELLENCE');
    $pdf->SetAlpha(1);

    // Output PDF
    $filename = 'Vice_Guild_Academic_Budget_Approval_' . ($request['requester_reg'] ?: 'Vice_Guild_Academic') . '_' . date('Ymd') . '.pdf';
    $pdf->Output($filename, 'D');
    
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
} catch (Exception $e) {
    die('PDF generation error: ' . $e->getMessage());
}
?>