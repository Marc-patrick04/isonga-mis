<?php
session_start();
require_once '../config/database.php';
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in and is Guild President
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_POST['action'] === 'generate_certificate') {
    handleCertificateGeneration();
} elseif ($_POST['action'] === 'create_receipt') {
    handleReceiptCreation();
} elseif ($_POST['action'] === 'create_mission') {
    handleMissionPaperCreation();
} elseif ($_POST['action'] === 'upload_document') {
    handleDocumentUpload();
}

function handleCertificateGeneration() {
    global $pdo, $user_id;
    
    try {
        $template_id = $_POST['template_id'];
        $recipient_name = $_POST['recipient_name'];
        $recipient_reg_number = $_POST['recipient_reg_number'] ?? '';
        $event_name = $_POST['event_name'];
        $event_date = $_POST['event_date'];
        $issue_date = $_POST['issue_date'];
        $description = $_POST['description'] ?? '';
        $position = $_POST['position'] ?? '';
        
        // Determine certificate type based on template
        $certificate_types = [
            1 => 'Participation',
            2 => 'Achievement', 
            3 => 'Appreciation',
            4 => 'Leadership'
        ];
        
        $certificate_type = $certificate_types[$template_id] ?? 'Certificate';
        
        // Generate PDF
        $pdf_content = generateCertificatePDF([
            'type' => $certificate_type,
            'recipient_name' => $recipient_name,
            'recipient_reg_number' => $recipient_reg_number,
            'event_name' => $event_name,
            'event_date' => $event_date,
            'issue_date' => $issue_date,
            'description' => $description,
            'position' => $position
        ]);
        
        // Save to documents table
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                title, template_id, document_type, content, generated_file, 
                metadata, status, generated_by, recipient_name, 
                recipient_reg_number, purpose, issue_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $title = "$certificate_type Certificate - $recipient_name";
        $document_type = 'certificate';
        $metadata = json_encode([
            'certificate_type' => $certificate_type,
            'event_name' => $event_name,
            'event_date' => $event_date,
            'position' => $position,
            'description' => $description
        ]);
        
        $filename = "certificate_" . time() . "_" . $recipient_reg_number . ".pdf";
        $filepath = "assets/documents/certificates/" . $filename;
        
        // Ensure directory exists
        if (!is_dir('assets/documents/certificates')) {
            mkdir('assets/documents/certificates', 0777, true);
        }
        
        file_put_contents($filepath, $pdf_content);
        
        $stmt->execute([
            $title, $template_id, $document_type, $description, $filepath,
            $metadata, 'generated', $user_id, $recipient_name,
            $recipient_reg_number, "Certificate for $event_name", $issue_date
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Certificate generated successfully!',
            'file_path' => $filepath
        ]);
        
    } catch (Exception $e) {
        error_log("Certificate generation error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Error generating certificate: ' . $e->getMessage()
        ]);
    }
}

function handleReceiptCreation() {
    global $pdo, $user_id;
    
    try {
        $receipt_for = $_POST['receipt_for'];
        $student_name = $_POST['student_name'] ?? '';
        $student_reg_number = $_POST['student_reg_number'] ?? '';
        $external_name = $_POST['external_name'] ?? '';
        $payment_purpose = $_POST['payment_purpose'];
        $custom_purpose = $_POST['custom_purpose'] ?? '';
        $amount_paid = $_POST['amount_paid'];
        $payment_method = $_POST['payment_method'];
        $payment_date = $_POST['payment_date'];
        $notes = $_POST['notes'] ?? '';
        
        $final_purpose = $payment_purpose === 'other' ? $custom_purpose : $payment_purpose;
        $recipient_name = $receipt_for === 'student' ? $student_name : $external_name;
        
        // Generate PDF receipt
        $pdf_content = generateReceiptPDF([
            'recipient_name' => $recipient_name,
            'reg_number' => $student_reg_number,
            'amount_paid' => $amount_paid,
            'payment_purpose' => $final_purpose,
            'payment_method' => $payment_method,
            'payment_date' => $payment_date,
            'notes' => $notes
        ]);
        
        // Save to documents table
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                title, document_type, content, generated_file, 
                metadata, status, generated_by, recipient_name, 
                recipient_reg_number, purpose, issue_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $title = "Receipt - $recipient_name";
        $metadata = json_encode([
            'amount_paid' => $amount_paid,
            'payment_purpose' => $final_purpose,
            'payment_method' => $payment_method,
            'payment_date' => $payment_date,
            'notes' => $notes
        ]);
        
        $filename = "receipt_" . time() . "_" . ($student_reg_number ?: 'external') . ".pdf";
        $filepath = "assets/documents/receipts/" . $filename;
        
        // Ensure directory exists
        if (!is_dir('assets/documents/receipts')) {
            mkdir('assets/documents/receipts', 0777, true);
        }
        
        file_put_contents($filepath, $pdf_content);
        
        $stmt->execute([
            $title, 'receipt', $notes, $filepath,
            $metadata, 'generated', $user_id, $recipient_name,
            $student_reg_number, "Payment for: $final_purpose", $payment_date
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Receipt generated successfully!',
            'file_path' => $filepath
        ]);
        
    } catch (Exception $e) {
        error_log("Receipt creation error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Error creating receipt: ' . $e->getMessage()
        ]);
    }
}

function handleMissionPaperCreation() {
    global $pdo, $user_id;
    
    try {
        $assignee_id = $_POST['assignee_id'];
        $purpose = $_POST['purpose'];
        $destination = $_POST['destination'];
        $contact_person = $_POST['contact_person'] ?? '';
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $transport_mode = $_POST['transport_mode'];
        $budget = $_POST['budget'] ?? 0;
        $requirements = $_POST['requirements'] ?? '';
        $requires_accommodation = isset($_POST['requires_accommodation']) ? 1 : 0;
        $requires_advance = isset($_POST['requires_advance']) ? 1 : 0;
        
        // Get assignee details
        $stmt = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
        $stmt->execute([$assignee_id]);
        $assignee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignee) {
            throw new Exception("Assignee not found");
        }
        
        // Generate mission paper PDF
        $pdf_content = generateMissionPaperPDF([
            'assignee_name' => $assignee['full_name'],
            'assignee_role' => $assignee['role'],
            'purpose' => $purpose,
            'destination' => $destination,
            'contact_person' => $contact_person,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'transport_mode' => $transport_mode,
            'budget' => $budget,
            'requirements' => $requirements,
            'requires_accommodation' => $requires_accommodation,
            'requires_advance' => $requires_advance
        ]);
        
        // Save to documents table
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                title, template_id, document_type, content, generated_file, 
                metadata, status, generated_by, purpose, issue_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $title = "Mission Paper - " . $assignee['full_name'] . " - " . date('Y-m-d');
        $metadata = json_encode([
            'assignee_name' => $assignee['full_name'],
            'assignee_role' => $assignee['role'],
            'destination' => $destination,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'budget' => $budget,
            'transport_mode' => $transport_mode
        ]);
        
        $filename = "mission_" . time() . "_" . $assignee_id . ".pdf";
        $filepath = "assets/documents/missions/" . $filename;
        
        // Ensure directory exists
        if (!is_dir('assets/documents/missions')) {
            mkdir('assets/documents/missions', 0777, true);
        }
        
        file_put_contents($filepath, $pdf_content);
        
        $stmt->execute([
            $title, 3, 'mission', $purpose, $filepath,
            $metadata, 'generated', $user_id, $purpose, date('Y-m-d')
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Mission paper generated successfully!',
            'file_path' => $filepath
        ]);
        
    } catch (Exception $e) {
        error_log("Mission paper creation error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Error creating mission paper: ' . $e->getMessage()
        ]);
    }
}

function handleDocumentUpload() {
    global $pdo, $user_id;
    
    try {
        $title = $_POST['title'];
        $description = $_POST['description'] ?? '';
        $category_id = $_POST['category_id'];
        $access_level = $_POST['access_level'];
        $notify_committee = isset($_POST['notify_committee']) ? 1 : 0;
        
        // Handle file upload
        if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed");
        }
        
        $file = $_FILES['document_file'];
        $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_types)) {
            throw new Exception("File type not allowed. Allowed types: " . implode(', ', $allowed_types));
        }
        
        if ($file['size'] > 10 * 1024 * 1024) { // 10MB
            throw new Exception("File size too large. Maximum size is 10MB");
        }
        
        $filename = time() . "_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filepath = "assets/documents/uploads/" . $filename;
        
        // Ensure directory exists
        if (!is_dir('assets/documents/uploads')) {
            mkdir('assets/documents/uploads', 0777, true);
        }
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception("Failed to save uploaded file");
        }
        
        // Save to documents table
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                title, category_id, document_type, content, generated_file, 
                metadata, status, generated_by, purpose
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $metadata = json_encode([
            'access_level' => $access_level,
            'file_size' => $file['size'],
            'file_type' => $file_ext,
            'original_name' => $file['name'],
            'notify_committee' => $notify_committee
        ]);
        
        $stmt->execute([
            $title, $category_id, 'other', $description, $filepath,
            $metadata, 'generated', $user_id, "Uploaded document: $title"
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Document uploaded successfully!',
            'file_path' => $filepath
        ]);
        
    } catch (Exception $e) {
        error_log("Document upload error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Error uploading document: ' . $e->getMessage()
        ]);
    }
}

// PDF Generation Functions with Modern Designs
function generateCertificatePDF($data) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('RPSU Portal');
    $pdf->SetAuthor('Rwanda Polytechnic - Musanze College');
    $pdf->SetTitle($data['type'] . ' Certificate');
    $pdf->SetSubject('Certificate of ' . $data['type']);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Modern gradient background
    $pdf->SetFillColor(245, 248, 255);
    $pdf->Rect(0, 0, 210, 297, 'F');
    
    // Decorative elements
    $pdf->SetFillColor(0, 86, 179);
    $pdf->Rect(0, 0, 210, 15, 'F');
    $pdf->Rect(0, 282, 210, 15, 'F');
    
    // Corner decorations
    $pdf->SetLineWidth(1);
    $pdf->SetDrawColor(0, 86, 179);
    $pdf->Line(15, 15, 30, 15);
    $pdf->Line(15, 15, 15, 30);
    $pdf->Line(195, 15, 180, 15);
    $pdf->Line(195, 15, 195, 30);
    $pdf->Line(15, 282, 30, 282);
    $pdf->Line(15, 282, 15, 267);
    $pdf->Line(195, 282, 180, 282);
    $pdf->Line(195, 282, 195, 267);
    
    // Main certificate border
    $pdf->SetLineWidth(2);
    $pdf->SetDrawColor(0, 86, 179);
    $pdf->Rect(20, 20, 170, 257);
    
    // Add decorative pattern
    $pdf->SetFillColor(230, 240, 255);
    for ($i = 0; $i < 8; $i++) {
        $pdf->Circle(25 + ($i * 20), 40, 2, 0, 360, 'F', array(), array(230, 240, 255));
        $pdf->Circle(25 + ($i * 20), 262, 2, 0, 360, 'F', array(), array(230, 240, 255));
    }
    
    // Logos with better positioning
    try {
        $pdf->Image('../assets/images/rp_logo.png', 30, 25, 25, 25, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        $pdf->Image('../assets/images/rpsu_logo.png', 155, 25, 25, 25, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    } catch (Exception $e) {
        // Fallback if logos not found
        error_log("Logo not found: " . $e->getMessage());
    }
    
    // Organization header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(0, 35);
    $pdf->Cell(0, 0, 'RWANDA POLYTECHNIC - MUSANZE COLLEGE', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY(0, 42);
    $pdf->Cell(0, 0, 'STUDENT UNION', 0, 1, 'C');
    
    // Certificate type with modern styling
    $pdf->SetFont('helvetica', 'B', 28);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(0, 65);
    $pdf->Cell(0, 0, 'CERTIFICATE OF ' . strtoupper($data['type']), 0, 1, 'C');
    
    // Decorative separator
    $pdf->SetLineWidth(1);
    $pdf->SetDrawColor(0, 86, 179);
    $pdf->Line(60, 80, 150, 80);
    
    // Introductory text
    $pdf->SetFont('helvetica', 'I', 14);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(0, 90);
    $pdf->Cell(0, 0, 'This Certificate is Proudly Presented to', 0, 1, 'C');
    
    // Recipient name with modern styling
    $pdf->SetFont('helvetica', 'B', 32);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(0, 110);
    $pdf->Cell(0, 0, strtoupper($data['recipient_name']), 0, 1, 'C');
    
    if ($data['recipient_reg_number']) {
        $pdf->SetFont('helvetica', '', 12);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY(0, 120);
        $pdf->Cell(0, 0, 'Registration Number: ' . $data['recipient_reg_number'], 0, 1, 'C');
    }
    
    // Main content with modern layout
    $pdf->SetFont('helvetica', '', 16);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(25, 140);
    
    $content = "in recognition of ";
    
    switch ($data['type']) {
        case 'Participation':
            $content .= "active participation and valuable contribution to";
            break;
        case 'Achievement':
            $content .= "outstanding achievement and exceptional performance in";
            break;
        case 'Appreciation':
            $content .= "dedicated service and remarkable contribution to";
            break;
        case 'Leadership':
            $content .= "exemplary leadership and inspirational guidance in";
            break;
        default:
            $content .= "successful completion of";
    }
    
    $pdf->MultiCell(160, 10, $content, 0, 'C');
    
    // Event name with emphasis
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(25, $pdf->GetY() + 5);
    $pdf->MultiCell(160, 10, '"' . $data['event_name'] . '"', 0, 'C');
    
    if ($data['position']) {
        $pdf->SetFont('helvetica', 'I', 14);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY(25, $pdf->GetY() + 5);
        $pdf->MultiCell(160, 10, 'serving as ' . $data['position'], 0, 'C');
    }
    
    if ($data['description']) {
        $pdf->SetFont('helvetica', '', 12);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(25, $pdf->GetY() + 10);
        $pdf->MultiCell(160, 8, $data['description'], 0, 'C');
    }
    
    // Dates section
    $y_position = $pdf->GetY() + 15;
    $pdf->SetFont('helvetica', '', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(25, $y_position);
    $pdf->Cell(75, 0, 'Event Date:', 0, 0, 'R');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(100, $y_position);
    $pdf->Cell(75, 0, date('F j, Y', strtotime($data['event_date'])), 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->SetXY(25, $y_position + 8);
    $pdf->Cell(75, 0, 'Date of Issue:', 0, 0, 'R');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(100, $y_position + 8);
    $pdf->Cell(75, 0, date('F j, Y', strtotime($data['issue_date'])), 0, 1, 'L');
    
    // Signatures with modern layout
    $y_position = 220;
    
    // Left signature
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(0, 86, 179);
    $pdf->Line(40, $y_position, 80, $y_position);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(40, $y_position + 5);
    $pdf->Cell(40, 0, 'Jean de Dieu Niyonzima', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetXY(40, $y_position + 10);
    $pdf->Cell(40, 0, 'Guild President', 0, 1, 'C');
    $pdf->SetXY(40, $y_position + 15);
    $pdf->Cell(40, 0, 'RPSU - RP Musanze', 0, 1, 'C');
    
    // Right signature
    $pdf->Line(130, $y_position, 170, $y_position);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(130, $y_position + 5);
    $pdf->Cell(40, 0, 'College Director', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetXY(130, $y_position + 10);
    $pdf->Cell(40, 0, 'RP Musanze College', 0, 1, 'C');
    $pdf->SetXY(130, $y_position + 15);
    $pdf->Cell(40, 0, 'Musanze, Rwanda', 0, 1, 'C');
    
    // Certificate ID
    $cert_id = 'RPSU/CERT/' . date('Y') . '/' . strtoupper(substr($data['type'], 0, 3)) . '/' . rand(1000, 9999);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetXY(0, 265);
    $pdf->Cell(0, 0, 'Certificate ID: ' . $cert_id, 0, 1, 'C');
    
    return $pdf->Output('', 'S');
}

function generateReceiptPDF($data) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('RPSU Portal');
    $pdf->SetAuthor('Rwanda Polytechnic - Musanze College');
    $pdf->SetTitle('Payment Receipt');
    $pdf->SetSubject('Official Receipt');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Modern background
    $pdf->SetFillColor(250, 252, 255);
    $pdf->Rect(0, 0, 210, 297, 'F');
    
    // Header with gradient effect
    $pdf->SetFillColor(0, 86, 179);
    $pdf->Rect(0, 0, 210, 40, 'F');
    
    // Add logos in header
    try {
        $pdf->Image('../assets/images/rp_logo.png', 15, 8, 20, 20, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        $pdf->Image('../assets/images/rpsu_logo.png', 175, 8, 20, 20, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    } catch (Exception $e) {
        error_log("Logo not found: " . $e->getMessage());
    }
    
    // Header text
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(0, 12);
    $pdf->Cell(0, 0, 'OFFICIAL RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY(0, 20);
    $pdf->Cell(0, 0, 'Rwanda Polytechnic Student Union', 0, 1, 'C');
    $pdf->SetXY(0, 26);
    $pdf->Cell(0, 0, 'Musanze College', 0, 1, 'C');
    
    // Main receipt container
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->SetLineWidth(1);
    $pdf->RoundedRect(15, 50, 180, 190, 5, '1111', 'DF');
    
    // Receipt header
    $receipt_number = 'RCP' . date('Ymd') . rand(1000, 9999);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(15, 55);
    $pdf->Cell(180, 0, 'PAYMENT RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(15, 62);
    $pdf->Cell(180, 0, 'Receipt No: ' . $receipt_number, 0, 1, 'C');
    
    // Receipt details
    $y = 75;
    
    // Two-column layout for receipt details
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(25, $y);
    $pdf->Cell(40, 0, 'Date:');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetXY(65, $y);
    $pdf->Cell(0, 0, date('F j, Y', strtotime($data['payment_date'])));
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY(120, $y);
    $pdf->Cell(40, 0, 'Time:');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetXY(160, $y);
    $pdf->Cell(0, 0, date('g:i A'));
    
    $y += 12;
    
    // Received from section
    $pdf->SetLineWidth(0.3);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(25, $y, 185, $y);
    
    $y += 8;
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(25, $y);
    $pdf->Cell(0, 0, 'RECEIVED FROM');
    
    $y += 8;
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(25, $y);
    $pdf->Cell(0, 0, $data['recipient_name']);
    
    $y += 8;
    
    if ($data['reg_number']) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY(25, $y);
        $pdf->Cell(0, 0, 'Registration Number: ' . $data['reg_number']);
        $y += 6;
    }
    
    $y += 10;
    
    // Payment details section
    $pdf->SetFillColor(245, 248, 255);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->RoundedRect(25, $y, 160, 60, 3, '1111', 'DF');
    
    $y += 8;
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(35, $y);
    $pdf->Cell(0, 0, 'PAYMENT DETAILS');
    
    $y += 12;
    
    // Amount in large, prominent display
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(35, $y);
    $pdf->Cell(0, 0, number_format($data['amount_paid'], 0) . ' RWF');
    
    $y += 15;
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(35, $y);
    $pdf->Cell(30, 0, 'Purpose:');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetXY(65, $y);
    $pdf->MultiCell(110, 8, ucfirst($data['payment_purpose']), 0, 'L');
    
    $y = $pdf->GetY() + 5;
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(35, $y);
    $pdf->Cell(30, 0, 'Method:');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetXY(65, $y);
    $pdf->Cell(0, 0, ucfirst(str_replace('_', ' ', $data['payment_method'])));
    
    $y += 8;
    
    if ($data['notes']) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(35, $y);
        $pdf->Cell(30, 0, 'Notes:');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY(65, $y);
        $pdf->MultiCell(110, 8, $data['notes'], 0, 'L');
        $y = $pdf->GetY() + 5;
    }
    
    // Signature section
    $y = 180;
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(150, 150, 150);
    
    // Treasurer signature
    $pdf->Line(40, $y, 90, $y);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(40, $y + 5);
    $pdf->Cell(50, 0, 'Authorized Signature', 0, 1, 'C');
    $pdf->SetXY(40, $y + 10);
    $pdf->Cell(50, 0, 'RPSU Treasurer', 0, 1, 'C');
    
    // Payer signature
    $pdf->Line(120, $y, 170, $y);
    $pdf->SetXY(120, $y + 5);
    $pdf->Cell(50, 0, 'Payer Signature', 0, 1, 'C');
    $pdf->SetXY(120, $y + 10);
    $pdf->Cell(50, 0, $data['recipient_name'], 0, 1, 'C');
    
    // Footer with contact information
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetXY(0, 250);
    $pdf->Cell(0, 0, 'This is an official receipt. Please keep it for your records.', 0, 1, 'C');
    $pdf->SetXY(0, 255);
    $pdf->Cell(0, 0, 'RPSU Office, RP Musanze College • P.O. Box 123 Musanze • Tel: +250 788 123 456', 0, 1, 'C');
    $pdf->SetXY(0, 260);
    $pdf->Cell(0, 0, 'Email: rpsu@rpmusanze.ac.rw • Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    
    return $pdf->Output('', 'S');
}

function generateMissionPaperPDF($data) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('RPSU Portal');
    $pdf->SetAuthor('Rwanda Polytechnic - Musanze College');
    $pdf->SetTitle('Mission Authorization');
    $pdf->SetSubject('Official Mission Paper');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Modern background with subtle pattern
    $pdf->SetFillColor(250, 252, 255);
    $pdf->Rect(0, 0, 210, 297, 'F');
    
    // Header with professional design
    $pdf->SetFillColor(0, 86, 179);
    $pdf->Rect(0, 0, 210, 35, 'F');
    
    // Add logos
    try {
        $pdf->Image('../assets/images/rp_logo.png', 15, 5, 20, 20, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        $pdf->Image('../assets/images/rpsu_logo.png', 175, 5, 20, 20, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    } catch (Exception $e) {
        error_log("Logo not found: " . $e->getMessage());
    }
    
    // Header text
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(0, 8);
    $pdf->Cell(0, 0, 'OFFICIAL MISSION AUTHORIZATION', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(0, 16);
    $pdf->Cell(0, 0, 'Rwanda Polytechnic Student Union', 0, 1, 'C');
    $pdf->SetXY(0, 22);
    $pdf->Cell(0, 0, 'Musanze College', 0, 1, 'C');
    
    // Mission reference badge
    $mission_ref = 'MIS' . date('Ymd') . rand(100, 999);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->RoundedRect(80, 30, 50, 12, 3, '1111', 'DF');
    $pdf->SetXY(80, 32);
    $pdf->Cell(50, 0, 'Ref: ' . $mission_ref, 0, 1, 'C');
    
    // Main content container
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->SetLineWidth(1);
    $pdf->RoundedRect(15, 45, 180, 230, 5, '1111', 'DF');
    
    $y = 50;
    
    // Mission Details Section
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(25, $y);
    $pdf->Cell(0, 0, 'MISSION DETAILS');
    
    $y += 12;
    
    // Personnel information in modern card layout
    $pdf->SetFillColor(245, 248, 255);
    $pdf->RoundedRect(25, $y, 160, 30, 3, '1111', 'DF');
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(30, $y + 5);
    $pdf->Cell(30, 0, 'Assignee:');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(60, $y + 5);
    $pdf->Cell(0, 0, $data['assignee_name']);
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(30, $y + 15);
    $pdf->Cell(30, 0, 'Position:');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(60, $y + 15);
    $pdf->Cell(0, 0, $data['assignee_role']);
    
    $y += 40;
    
    // Purpose section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(25, $y);
    $pdf->Cell(0, 0, 'MISSION PURPOSE');
    
    $y += 8;
    
    $pdf->SetFillColor(250, 252, 255);
    $pdf->RoundedRect(25, $y, 160, 40, 3, '1111', 'DF');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(30, $y + 5);
    $pdf->MultiCell(150, 8, $data['purpose'], 0, 'L');
    
    $y += 50;
    
    // Logistics details in two-column layout
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(25, $y);
    $pdf->Cell(0, 0, 'LOGISTICS & TIMELINE');
    
    $y += 12;
    
    // Left column
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(30, $y);
    $pdf->Cell(30, 0, 'Destination:');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(60, $y);
    $pdf->Cell(0, 0, $data['destination']);
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(30, $y + 8);
    $pdf->Cell(30, 0, 'Start Date:');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(60, $y + 8);
    $pdf->Cell(0, 0, date('M j, Y g:i A', strtotime($data['start_date'])));
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(30, $y + 16);
    $pdf->Cell(30, 0, 'Transport:');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(60, $y + 16);
    $pdf->Cell(0, 0, ucfirst($data['transport_mode']));
    
    // Right column
    if ($data['contact_person']) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(110, $y);
        $pdf->Cell(30, 0, 'Contact:');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(140, $y);
        $pdf->Cell(0, 0, $data['contact_person']);
    }
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(110, $y + 8);
    $pdf->Cell(30, 0, 'End Date:');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(140, $y + 8);
    $pdf->Cell(0, 0, date('M j, Y g:i A', strtotime($data['end_date'])));
    
    if ($data['budget'] > 0) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(110, $y + 16);
        $pdf->Cell(30, 0, 'Budget:');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(140, $y + 16);
        $pdf->Cell(0, 0, number_format($data['budget'], 0) . ' RWF');
    }
    
    $y += 30;
    
    // Additional requirements
    if ($data['requirements'] || $data['requires_accommodation'] || $data['requires_advance']) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(0, 86, 179);
        $pdf->SetXY(25, $y);
        $pdf->Cell(0, 0, 'ADDITIONAL REQUIREMENTS');
        
        $y += 8;
        
        $requirements_text = '';
        if ($data['requirements']) {
            $requirements_text .= $data['requirements'];
        }
        
        $conditions = [];
        if ($data['requires_accommodation']) $conditions[] = 'Overnight accommodation';
        if ($data['requires_advance']) $conditions[] = 'Travel advance';
        
        if (!empty($conditions)) {
            if ($requirements_text) $requirements_text .= "\n";
            $requirements_text .= 'Special conditions: ' . implode(', ', $conditions);
        }
        
        if ($requirements_text) {
            $pdf->SetFillColor(250, 252, 255);
            $pdf->RoundedRect(25, $y, 160, 30, 3, '1111', 'DF');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY(30, $y + 5);
            $pdf->MultiCell(150, 8, $requirements_text, 0, 'L');
            $y += 35;
        }
    }
    
    // Authorization section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 86, 179);
    $pdf->SetXY(25, $y);
    $pdf->Cell(0, 0, 'AUTHORIZATION');
    
    $y += 8;
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(25, $y);
    $pdf->MultiCell(160, 8, 
        'This mission is officially authorized by the Rwanda Polytechnic Student Union. ' .
        'The assigned personnel is authorized to represent RPSU and undertake the specified activities ' .
        'within the stated duration and budget constraints. All expenses must be properly documented ' .
        'and submitted for reimbursement within 7 days of mission completion.'
    );
    
    // Signatures
    $y += 25;
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(150, 150, 150);
    
    // Left signature - President
    $pdf->Line(40, $y, 90, $y);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(40, $y + 5);
    $pdf->Cell(50, 0, 'Jean de Dieu Niyonzima', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(40, $y + 10);
    $pdf->Cell(50, 0, 'Guild President', 0, 1, 'C');
    $pdf->SetXY(40, $y + 15);
    $pdf->Cell(50, 0, 'RPSU - RP Musanze', 0, 1, 'C');
    
    // Right signature - Assignee
    $pdf->Line(120, $y, 170, $y);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(120, $y + 5);
    $pdf->Cell(50, 0, $data['assignee_name'], 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(120, $y + 10);
    $pdf->Cell(50, 0, 'Mission Assignee', 0, 1, 'C');
    $pdf->SetXY(120, $y + 15);
    $pdf->Cell(50, 0, date('M j, Y'), 0, 1, 'C');
    
    // Footer
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetXY(0, 280);
    $pdf->Cell(0, 0, 'Mission Reference: ' . $mission_ref . ' • Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    $pdf->SetXY(0, 285);
    $pdf->Cell(0, 0, 'RPSU Mission Authorization Document • Valid only with official signatures', 0, 1, 'C');
    
    return $pdf->Output('', 'S');
}
?>