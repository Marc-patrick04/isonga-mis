<?php
function convertNumberToWords($number) {
    $whole = floor($number);
    $fraction = round(($number - $whole) * 100);
    
    // Simple conversion - you can enhance this with a proper number to words function
    $words = number_format($whole) . ($fraction > 0 ? " and {$fraction}/100" : "");
    return $words;
}

function generateReceiptContent($pdf, $allowance, $type) {
    $current_date = date('F j, Y');
    $current_academic_year = getCurrentAcademicYear();
    $purpose = $type === 'communication' 
        ? "Communication Allowance for " . date('F Y', strtotime($allowance['month_year'] . '-01'))
        : "Mission Allowance - " . $allowance['destination'];
    
    $amount_words = convertNumberToWords($allowance['amount']);
    
    // Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'REPUBLIC OF RWANDA', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'ISONGA RPSU - RP MUSANZE COLLEGE', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'OFFICIAL PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Receipt Number
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Receipt No: RCP-' . date('Ymd') . '-' . $allowance['id'], 0, 1, 'R');
    $pdf->Ln(5);
    
    // Create table
    $table = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
    
    // Table rows
    $table .= '<tr><td width="30%" style="font-weight: bold; background-color: #f0f0f0;">Receipt Date</td><td width="70%">' . $current_date . '</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Recipient Name</td><td>' . $allowance['member_name'] . '</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Role/Position</td><td>' . $allowance['role'] . '</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Department</td><td>' . $allowance['department_name'] . '</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Purpose of Payment</td><td>' . $purpose . '</td></tr>';
    
    if ($type === 'mission') {
        $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Mission Date</td><td>' . date('F j, Y', strtotime($allowance['mission_date'])) . '</td></tr>';
        $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Destination</td><td>' . $allowance['destination'] . '</td></tr>';
    }
    
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Amount (RWF)</td><td style="font-weight: bold; font-size: 12px;">RWF ' . number_format($allowance['amount'], 2) . '</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Payment Method</td><td>CASH</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Processed By</td><td>' . $allowance['paid_by_name'] . ' (Vice Guild Finance)</td></tr>';
    $table .= '<tr><td style="font-weight: bold; background-color: #f0f0f0;">Academic Year</td><td>' . $current_academic_year . '</td></tr>';
    
    $table .= '</table>';
    
    // Output table
    $pdf->writeHTML($table, true, false, true, false, '');
    $pdf->Ln(5);
    
    // Amount in words
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->MultiCell(0, 6, 'Amount in Words: ' . $amount_words . ' Rwandan Francs only', 0, 'L');
    $pdf->Ln(10);
    
    // Notice
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'N/B:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(0, 5, 'This receipt serves as official confirmation of payment received. Please sign this receipt with a pen and return the signed copy to the finance office.', 0, 'L');
    $pdf->Ln(15);
    
    // Signature section
    $pdf->SetFont('helvetica', '', 10);
    
    // Recipient signature
    $pdf->Cell(90, 6, '_________________________', 0, 0, 'C');
    $pdf->Cell(50, 6, '', 0, 0, 'C'); // Space between
    $pdf->Cell(90, 6, '_________________________', 0, 1, 'C');
    
    $pdf->Cell(90, 6, "Recipient's Signature", 0, 0, 'C');
    $pdf->Cell(50, 6, '', 0, 0, 'C');
    $pdf->Cell(90, 6, 'Vice Guild Finance Signature', 0, 1, 'C');
    
    $pdf->Ln(5);
    
    $pdf->Cell(90, 6, 'Date: ___________________', 0, 0, 'C');
    $pdf->Cell(50, 6, '', 0, 0, 'C');
    $pdf->Cell(90, 6, 'Date: ___________________', 0, 1, 'C');
    
    $pdf->Ln(10);
    
    // Footer
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 4, 'ISONGA RPSU - RP Musanze College | P.O. Box 123 Musanze | Tel: +250 788 123 456', 0, 1, 'C');
    $pdf->Cell(0, 4, 'Email: finance@isonga.rp.ac.rw', 0, 1, 'C');
}
?>