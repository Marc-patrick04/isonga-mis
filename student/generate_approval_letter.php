<?php
session_start();
require_once '../config/database.php';
require_once '../tcpdf/tcpdf.php'; // TCPDF

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: financial_aid.php');
    exit();
}

$request_id = $_GET['id'];
$student_id = $_SESSION['user_id'];

// Get financial aid request details
$stmt = $pdo->prepare("
    SELECT sfa.*, u.full_name as student_name, u.reg_number, 
           d.name as department_name, p.name as program_name, 
           u.academic_year, u.email, u.phone, u.address,
           sfa.amount_requested, sfa.amount_approved
    FROM student_financial_aid sfa
    JOIN users u ON sfa.student_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE sfa.id = ? AND sfa.student_id = ? AND sfa.status = 'approved'
");
$stmt->execute([$request_id, $student_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: financial_aid.php');
    exit();
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
$pdf->SetTitle('Financial Aid Approval Letter');
$pdf->SetSubject('Financial Aid Approval');

// Set margins
$pdf->SetMargins(15, 25, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Logo - Centered
$logo = '../assets/images/logo.png'; // Isonga logo
if (file_exists($logo)) {
    // Get page width to center the logo
    $pageWidth = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
    $logoWidth = 40; // Adjust as needed for your logo size
    $logoX = ($pageWidth - $logoWidth) / 2 + $pdf->getMargins()['left'];
    
    $pdf->Image($logo, $logoX, 15, $logoWidth, 0, 'PNG');
    $pdf->Ln(25); // Space after logo
    
    // Add a line after the logo
    $pdf->SetLineWidth(0.5);
    $pdf->Line($pdf->getMargins()['left'], $pdf->GetY(), $pdf->getPageWidth() - $pdf->getMargins()['right'], $pdf->GetY());
    $pdf->Ln(15); // Space after line
}

// Header text - Centered (ONE LINE ONLY)
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(0, 51, 102); // Dark blue color
$pdf->Cell(0, 10, 'RPSU MUSANZE COLLEGE - STUDENT FINANCIAL AID PROGRAM', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(0, 0, 0); // Black color
$pdf->Cell(0, 10, 'FINANCIAL AID APPROVAL LETTER', 0, 1, 'C');

$pdf->Ln(5);

// Reference Line
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(128, 128, 128); // Gray color
$pdf->Cell(0, 10, 'Ref: RPSU/MC/SFAP/' . date('Y') . '/' . str_pad($request_id, 4, '0', STR_PAD_LEFT), 0, 1, 'R');

$pdf->Ln(10);

// Date
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(0, 0, 0); // Black color
$pdf->Cell(0, 10, 'Date: ' . date('F j, Y'), 0, 1, 'R');

$pdf->Ln(15);

// Letter Body
$pdf->SetFont('helvetica', '', 12);

// Introduction
$intro = "I, " . $request['student_name'] . ", a student at RP Musanze College, pursuing in Department of " . 
    $request['department_name'] . ", program of " . $request['program_name'] . 
    " hereby strongly prove that I requested an amount of RWF " . number_format($request['amount_requested'], 2) . 
    " for " . $request['purpose'] . " and have been approved an amount of RWF " . 
    number_format($request['amount_approved'], 2) . ".\n\n";

$pdf->MultiCell(0, 8, $intro, 0, 'J');

// Purpose and Evidence Section
$purpose = "This letter serves as official evidence and reference for the financial aid approved to support my " . 
    $request['purpose'] . ". The funds will be utilized for the intended purpose as stated in my application.\n\n";

$pdf->MultiCell(0, 8, $purpose, 0, 'J');

// Financial Details Table
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'FINANCIAL DETAILS:', 0, 1);
$pdf->SetFont('helvetica', '', 11);

$table = '<table border="0.5" cellpadding="4">
<tr style="background-color:#f2f2f2;">
    <td width="50%"><b>Item</b></td>
    <td width="50%"><b>Amount (RWF)</b></td>
</tr>
<tr>
    <td>Requested Amount</td>
    <td align="right">' . number_format($request['amount_requested'], 2) . '</td>
</tr>
<tr>
    <td>Approved Amount</td>
    <td align="right">' . number_format($request['amount_approved'], 2) . '</td>
</tr>
<tr style="background-color:#f2f2f2;">
    <td><b>Total Approved</b></td>
    <td align="right"><b>' . number_format($request['amount_approved'], 2) . '</b></td>
</tr>
</table>';

$pdf->writeHTML($table, true, false, false, false, '');
$pdf->Ln(10);

// Guidelines Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 10, 'GUIDELINES FOR FUND UTILIZATION:', 0, 1, 'L', true);
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 11);
$guidelines = "1. The approved amount MUST be used exclusively for: " . $request['purpose'] . "\n" .
    "2. Keep all original receipts and invoices for verification purposes\n" .
    "3. Funds must be utilized within 30 days from the date of disbursement\n" .
    "4. Any unused funds must be returned to the Guild Council Treasury\n" .
    "5. Misuse of funds will result in immediate repayment and disciplinary action\n" .
    "6. Submit expenditure report within 15 days after fund utilization\n" .
    "7. This approval is non-transferable to any other student\n" .
    "8. Maintain proper financial records for audit purposes\n\n";

$pdf->MultiCell(0, 8, $guidelines, 0, 'L');

// Disbursement Instructions
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(220, 53, 69); // Red background
$pdf->SetTextColor(255, 255, 255); // White text
$pdf->Cell(0, 10, 'IMPORTANT DISBURSEMENT PROCEDURE:', 0, 1, 'L', true);
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(0, 0, 0); // Black text
$disbursement = "To receive the approved funds, you MUST present this printed letter at the Guild Council Office along with:\n\n" .
    "✓ Original Student ID Card\n" .
    "✓ Printed copy of this approval letter\n" .
    "✓ Completed disbursement form (available at the office)\n\n" .
    "Office Hours: Monday - Friday (8:00 AM - 5:00 PM)\n" .
    "Location: Guild Council Office, RP Musanze College\n" .
    "Contact: " . $vice_guild['email'] . "\n\n" .
    "Note: This letter must be presented within 14 days from the date of issue.\n";

$pdf->MultiCell(0, 8, $disbursement, 0, 'L');
$pdf->Ln(15);

// Agreement Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'STUDENT DECLARATION AND AGREEMENT:', 0, 1);
$pdf->SetFont('helvetica', '', 11);

$agreement = "I hereby declare that I have read and understood all the terms and conditions stated above. " .
    "I agree to use the approved funds responsibly and for the intended purpose only. " .
    "I acknowledge that any violation of these terms may result in disciplinary action.\n";

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
    .signature-line {
        border-top: 1px solid #000;
        margin-top: 40px;
        padding-top: 5px;
        font-size: 11px;
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
    
    <!-- RIGHT CELL: STUDENT ACKNOWLEDGEMENT -->
    <td width="50%" class="signature-cell">
        <div class="signature-header">STUDENT ACKNOWLEDGEMENT</div>
        <div style="margin-bottom: 10px; font-size: 11px;">
            I received: RWF <strong>' . number_format($request['amount_approved'], 2) . '</strong>
        </div>
        <div style="margin-bottom: 8px; font-size: 11px;">
            Name: ' . $request['student_name'] . '
        </div>
        <div style="margin-bottom: 8px; font-size: 11px;">
            Registration No: ' . $request['reg_number'] . '
        </div>
        <div style="margin-bottom: 8px; font-size: 11px;">
            Date: ____________________
        </div>
        <div style="margin-top: 20px; font-size: 11px;">
            Signature: ____________________
        </div>
    </td>
</tr>
</table>';

$pdf->writeHTML($signatureTable, true, false, false, false, '');
$pdf->Ln(10);

// Final Notes
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(128, 128, 128); // Gray color
$pdf->MultiCell(0, 8, 
    "Note: This document acts as an official record for financial aid disbursement. " .
    "Any alterations to this document render it invalid. Keep this letter for your records " .
    "and present it when required for verification purposes.\n\n" .
    "Generated on: " . date('F j, Y, g:i A') . " | Isonga Management System v1.0", 
    0, 'C'
);


// Add watermark
$pdf->SetAlpha(0.05);
$pdf->SetFont('helvetica', 'B', 80);
$pdf->SetTextColor(200, 200, 200);
$pdf->Text(15, 150, 'OFFICIAL');
$pdf->Text(15, 230, 'DOCUMENT');
$pdf->SetAlpha(1);

// Output PDF
$filename = 'Financial_Aid_Approval_' . $request['reg_number'] . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D');
exit();
?>