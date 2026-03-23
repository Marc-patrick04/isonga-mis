<?php
session_start();
require_once '../config/database.php';
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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
    $pdf->SetTitle('Gender Affairs Budget Approval Letter');
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
    $pdf->SetTextColor(220, 53, 69); // Red color
    $pdf->Cell(0, 10, 'GENDER AFFAIRS BUDGET PROGRAM', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 0, 0); // Black color
    $pdf->Cell(0, 10, 'BUDGET APPROVAL LETTER', 0, 1, 'C');

    $pdf->Ln(5);

    // Reference Line
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(128, 128, 128); // Gray color
    $pdf->Cell(0, 10, 'Ref: RPSU/MC/GABP/' . date('Y') . '/' . str_pad($request_id, 4, '0', STR_PAD_LEFT), 0, 1, 'R');

    $pdf->Ln(10);

    // Date
    $pdf->SetFont('helvetica', '', 12);
    $pdf->SetTextColor(0, 0, 0); // Black color
    $pdf->Cell(0, 10, 'Date: ' . date('F j, Y'), 0, 1, 'R');

    $pdf->Ln(15);

    // Letter Body
    $pdf->SetFont('helvetica', '', 12);

    // Introduction
    $intro = "I, " . $request['requester_name'] . ", serving as Minister of Gender Affairs at RP Musanze College, hereby strongly prove that I requested a budget of RWF " . 
        number_format($request['requested_amount'], 2) . " for " . $request['purpose'] . " and have been approved an amount of RWF " . 
        number_format($request['approved_amount'] ?: $request['requested_amount'], 2) . ".\n\n";

    $pdf->MultiCell(0, 8, $intro, 0, 'J');

    // Purpose and Evidence Section
    $purpose = "This letter serves as official evidence and reference for the gender affairs budget approved to support: " . 
        $request['purpose'] . ". The funds will be utilized for gender equality initiatives, awareness campaigns, empowerment programs, and support services as stated in the application.\n\n";

    $pdf->MultiCell(0, 8, $purpose, 0, 'J');

    // Financial Details Table
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'BUDGET DETAILS:', 0, 1);
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
        <td><b>Total Approved</b></td>
        <td align="right"><b>' . number_format($approved_amount, 2) . '</b></td>
    </tr>
    </table>';

    $pdf->writeHTML($table, true, false, false, false, '');
    $pdf->Ln(10);

    // Guidelines Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 10, 'GUIDELINES FOR GENDER AFFAIRS BUDGET UTILIZATION:', 0, 1, 'L', true);
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $guidelines = "1. The approved amount MUST be used exclusively for: " . $request['purpose'] . "\n" .
        "2. Funds allocated for gender equality awareness, empowerment programs, and support services\n" .
        "3. Keep all original receipts, invoices, and program participation records for verification\n" .
        "4. Funds must be utilized within 45 days from the date of disbursement\n" .
        "5. Any unused funds must be returned to the Students Guild Treasury\n" .
        "6. Budget reallocation requires prior written approval from the Finance Committee\n" .
        "7. Misuse of funds will result in immediate repayment and disciplinary action\n" .
        "8. Submit a detailed expenditure report with program impact assessment within 30 days\n" .
        "9. This approval is non-transferable to any other committee or purpose\n" .
        "10. Maintain proper financial and program records for audit purposes\n" .
        "11. Ensure inclusive participation across all genders in funded activities\n" .
        "12. Follow ethical guidelines in all gender-related programs and initiatives\n\n";

    $pdf->MultiCell(0, 8, $guidelines, 0, 'L');

    // Disbursement Instructions
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(220, 53, 69); // Red background
    $pdf->SetTextColor(255, 255, 255); // White text
    $pdf->Cell(0, 10, 'IMPORTANT DISBURSEMENT PROCEDURE:', 0, 1, 'L', true);
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0); // Black text
    $disbursement = "To receive the approved gender affairs budget, you MUST present this printed letter at the Guild Council Office along with:\n\n" .
        "✓ Original Student ID Card\n" .
        "✓ Printed copy of this approval letter\n" .
        "✓ Completed disbursement form (available at the office)\n" .
        "✓ Detailed gender affairs implementation plan\n" .
        "✓ Program schedule and expected outcomes\n\n" .
        "Office Hours: Monday - Friday (8:00 AM - 5:00 PM)\n" .
        "Location: Guild Council Office, RP Musanze College\n" .
        "Contact: " . $vice_guild['email'] . "\n\n" .
        "Note: This letter must be presented within 14 days from the date of issue.\n";

    $pdf->MultiCell(0, 8, $disbursement, 0, 'L');
    $pdf->Ln(15);

    // Agreement Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'MINISTER DECLARATION AND AGREEMENT:', 0, 1);
    $pdf->SetFont('helvetica', '', 11);

    $agreement = "I hereby declare that I have read and understood all the terms and conditions stated above. " .
        "I agree to use the approved gender affairs budget responsibly for promoting gender equality, " .
        "empowerment programs, and support services as specified. I acknowledge that any violation of " .
        "these terms may result in disciplinary action and requirement to repay misused funds. I commit " .
        "to upholding ethical standards in all gender-related activities.\n";

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
        
        <!-- RIGHT CELL: MINISTER ACKNOWLEDGEMENT -->
        <td width="50%" class="signature-cell">
            <div class="signature-header">MINISTER ACKNOWLEDGEMENT</div>
            <div style="margin-bottom: 10px; font-size: 11px;">
                I acknowledge receipt of budget: RWF <strong>' . number_format($approved_amount, 2) . '</strong>
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Name: ' . $request['requester_name'] . '
            </div>
            <div style="margin-bottom: 8px; font-size: 11px;">
                Position: Minister of Gender Affairs
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

    // Office use section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'FOR OFFICE USE ONLY', 0, 1, 'C');
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
        "Note: This document serves as official approval for gender affairs budget disbursement. " .
        "Any alterations to this document render it invalid. Please keep this letter for your records " .
        "and present it when required for verification purposes.\n\n" .
        "Generated on: " . date('F j, Y, g:i A') . " | Isonga Budget Management System v1.0", 
        0, 'C'
    );

    // Footer
    $pdf->SetY(-40);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'RPSU - RP Musanze College - Isonga Students Guild', 0, 1, 'C');
    $pdf->Cell(0, 6, 'P.O. Box 260 Musanze, Rwanda | Email: ' . $vice_guild['email'], 0, 1, 'C');
    $pdf->Cell(0, 6, 'Official Document - Do Not Duplicate Without Authorization', 0, 1, 'C');

    // Add watermark
    $pdf->SetAlpha(0.05);
    $pdf->SetFont('helvetica', 'B', 80);
    $pdf->SetTextColor(200, 200, 200);
    $pdf->Text(15, 150, 'OFFICIAL');
    $pdf->Text(15, 230, 'DOCUMENT');
    $pdf->SetAlpha(1);

    // Output PDF
    $filename = 'Gender_Affairs_Budget_Approval_' . ($request['requester_reg'] ?: 'Minister_Gender') . '_' . date('Ymd') . '.pdf';
    $pdf->Output($filename, 'D');
    
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
} catch (Exception $e) {
    die('PDF generation error: ' . $e->getMessage());
}
?>