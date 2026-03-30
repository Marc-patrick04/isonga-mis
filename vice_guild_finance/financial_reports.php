<?php
session_start();
require_once '../config/database.php';
require_once '../config/academic_year.php';
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in and is Vice Guild Finance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vice_guild_finance') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

// Get current academic year dynamically
$current_academic_year = getCurrentAcademicYear();
$academic_year_options = getAcademicYearOptions();

// Handle report generation
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$report_type = $_POST['report_type'] ?? 'financial_summary';
$academic_year = $_POST['academic_year'] ?? $current_academic_year;
$start_date = $_POST['start_date'] ?? date('Y-m-01');
$end_date = $_POST['end_date'] ?? date('Y-m-t');
$format = $_POST['format'] ?? 'html';

// Handle PDF/Excel export
if ($action === 'generate_report' && in_array($format, ['pdf', 'excel'])) {
    generateReportExport($report_type, $academic_year, $start_date, $end_date, $format);
    exit;
}

// Get report data based on type
$report_data = getReportData($report_type, $academic_year, $start_date, $end_date);

// Function to generate report exports
function generateReportExport($report_type, $academic_year, $start_date, $end_date, $format) {
    global $pdo;
    
    $report_data = getReportData($report_type, $academic_year, $start_date, $end_date);
    $report_title = getReportTitle($report_type);
    
    if ($format === 'pdf') {
        generatePDFReport($report_title, $report_data, $academic_year, $start_date, $end_date);
    } elseif ($format === 'excel') {
        generateExcelReport($report_title, $report_data, $academic_year, $start_date, $end_date);
    }
}

// Function to get report data
function getReportData($report_type, $academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    switch ($report_type) {
        case 'financial_summary':
            $data = getFinancialSummaryReport($academic_year, $start_date, $end_date);
            break;
            
        case 'income_statement':
            $data = getIncomeStatementReport($academic_year, $start_date, $end_date);
            break;
            
        case 'budget_vs_actual':
            $data = getBudgetVsActualReport($academic_year, $start_date, $end_date);
            break;
            
        case 'expense_analysis':
            $data = getExpenseAnalysisReport($academic_year, $start_date, $end_date);
            break;
            
        case 'student_aid_report':
            $data = getStudentAidReport($academic_year, $start_date, $end_date);
            break;
            
        case 'allowances_report':
            $data = getAllowancesReport($academic_year, $start_date, $end_date);
            break;
            
        case 'rental_income_report':
            $data = getRentalIncomeReport($academic_year, $start_date, $end_date);
            break;
    }
    
    return $data;
}

// Individual report data functions
function getFinancialSummaryReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        // Total Income
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_income 
            FROM financial_transactions 
            WHERE transaction_type = 'income' 
            AND status = 'completed'
            AND transaction_date BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['total_income'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_income'] ?? 0;
        
        // Total Expenses
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_expenses 
            FROM financial_transactions 
            WHERE transaction_type = 'expense' 
            AND status = 'completed'
            AND transaction_date BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['total_expenses'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_expenses'] ?? 0;
        
        // Net Cash Flow
        $data['net_cash_flow'] = $data['total_income'] - $data['total_expenses'];
        
        // Bank Balance
        $stmt = $pdo->query("SELECT current_balance FROM rpsu_account WHERE is_active = 1 LIMIT 1");
        $data['bank_balance'] = $stmt->fetch(PDO::FETCH_ASSOC)['current_balance'] ?? 0;
        
        // Budget Utilization
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(approved_amount), 0) as total_budget
            FROM committee_budget_requests 
            WHERE academic_year = ?
            AND status IN ('approved_by_president', 'funded')
        ");
        $stmt->execute([$academic_year]);
        $budget_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $data['total_budget'] = $budget_data['total_budget'] ?? 0;
        $data['budget_utilization'] = $data['total_budget'] > 0 ? ($data['total_expenses'] / $data['total_budget']) * 100 : 0;
        
        // Transaction counts
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                COUNT(CASE WHEN transaction_type = 'income' THEN 1 END) as income_transactions,
                COUNT(CASE WHEN transaction_type = 'expense' THEN 1 END) as expense_transactions
            FROM financial_transactions 
            WHERE status = 'completed'
            AND transaction_date BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        $transaction_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $data = array_merge($data, $transaction_data);
        
    } catch (PDOException $e) {
        error_log("Financial summary report error: " . $e->getMessage());
    }
    
    return $data;
}

function getIncomeStatementReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        // Income by category
        $stmt = $pdo->prepare("
            SELECT 
                bc.category_name,
                COALESCE(SUM(ft.amount), 0) as amount,
                COUNT(ft.id) as transaction_count
            FROM financial_transactions ft
            LEFT JOIN budget_categories bc ON ft.category_id = bc.id
            WHERE ft.transaction_type = 'income'
            AND ft.status = 'completed'
            AND ft.transaction_date BETWEEN ? AND ?
            AND bc.category_type = 'income'
            GROUP BY bc.id, bc.category_name
            ORDER BY amount DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['income_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Expenses by category
        $stmt = $pdo->prepare("
            SELECT 
                bc.category_name,
                COALESCE(SUM(ft.amount), 0) as amount,
                COUNT(ft.id) as transaction_count
            FROM financial_transactions ft
            LEFT JOIN budget_categories bc ON ft.category_id = bc.id
            WHERE ft.transaction_type = 'expense'
            AND ft.status = 'completed'
            AND ft.transaction_date BETWEEN ? AND ?
            AND bc.category_type = 'expense'
            GROUP BY bc.id, bc.category_name
            ORDER BY amount DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['expense_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Total calculations
        $data['total_income'] = array_sum(array_column($data['income_categories'], 'amount'));
        $data['total_expenses'] = array_sum(array_column($data['expense_categories'], 'amount'));
        $data['net_income'] = $data['total_income'] - $data['total_expenses'];
        
    } catch (PDOException $e) {
        error_log("Income statement report error: " . $e->getMessage());
    }
    
    return $data;
}

function getBudgetVsActualReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                cbr.request_title as category_name,
                cbr.approved_amount as budgeted_amount,
                COALESCE((
                    SELECT SUM(ft.amount) 
                    FROM financial_transactions ft 
                    WHERE ft.description LIKE CONCAT('%', cbr.request_title, '%')
                    AND ft.transaction_type = 'expense'
                    AND ft.status = 'completed'
                    AND ft.transaction_date BETWEEN ? AND ?
                ), 0) as actual_amount,
                cbr.approved_amount - COALESCE((
                    SELECT SUM(ft.amount) 
                    FROM financial_transactions ft 
                    WHERE ft.description LIKE CONCAT('%', cbr.request_title, '%')
                    AND ft.transaction_type = 'expense'
                    AND ft.status = 'completed'
                    AND ft.transaction_date BETWEEN ? AND ?
                ), 0) as variance,
                CASE 
                    WHEN cbr.approved_amount > 0 THEN 
                        (COALESCE((
                            SELECT SUM(ft.amount) 
                            FROM financial_transactions ft 
                            WHERE ft.description LIKE CONCAT('%', cbr.request_title, '%')
                            AND ft.transaction_type = 'expense'
                            AND ft.status = 'completed'
                            AND ft.transaction_date BETWEEN ? AND ?
                        ), 0) / cbr.approved_amount) * 100
                    ELSE 0 
                END as utilization_percentage
            FROM committee_budget_requests cbr
            WHERE cbr.academic_year = ?
            AND cbr.status IN ('approved_by_president', 'funded')
            ORDER BY cbr.approved_amount DESC
        ");
        $stmt->execute([$start_date, $end_date, $start_date, $end_date, $start_date, $end_date, $academic_year]);
        $data['budget_comparison'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Budget vs actual report error: " . $e->getMessage());
    }
    
    return $data;
}

function getExpenseAnalysisReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        // Monthly expense trend (PostgreSQL compatible)
        $stmt = $pdo->prepare("
            SELECT 
                TO_CHAR(transaction_date, 'YYYY-MM') as month,
                COALESCE(SUM(amount), 0) as monthly_expenses,
                COUNT(*) as transaction_count
            FROM financial_transactions 
            WHERE transaction_type = 'expense' 
            AND status = 'completed'
            AND transaction_date BETWEEN ? AND ?
            GROUP BY TO_CHAR(transaction_date, 'YYYY-MM')
            ORDER BY month
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['monthly_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top expenses
        $stmt = $pdo->prepare("
            SELECT 
                ft.description,
                ft.amount,
                ft.transaction_date,
                ft.payee_payer,
                ft.payment_method,
                bc.category_name
            FROM financial_transactions ft
            LEFT JOIN budget_categories bc ON ft.category_id = bc.id
            WHERE ft.transaction_type = 'expense' 
            AND ft.status = 'completed'
            AND ft.transaction_date BETWEEN ? AND ?
            ORDER BY ft.amount DESC
            LIMIT 20
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['top_expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Expense analysis report error: " . $e->getMessage());
    }
    
    return $data;
}

function getStudentAidReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        // Student aid summary
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as request_count,
                COALESCE(SUM(amount_requested), 0) as total_requested,
                COALESCE(SUM(amount_approved), 0) as total_approved,
                AVG(amount_requested) as avg_requested,
                AVG(amount_approved) as avg_approved
            FROM student_financial_aid 
            WHERE created_at BETWEEN ? AND ?
            GROUP BY status
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['aid_summary'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent aid requests
        $stmt = $pdo->prepare("
            SELECT 
                sfa.*,
                u.full_name as student_name,
                u.registration_number
            FROM student_financial_aid sfa
            LEFT JOIN users u ON sfa.student_id = u.id
            WHERE sfa.created_at BETWEEN ? AND ?
            ORDER BY sfa.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['recent_requests'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Student aid report error: " . $e->getMessage());
    }
    
    return $data;
}

function getAllowancesReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        // Mission allowances
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount,
                AVG(amount) as avg_amount
            FROM mission_allowances 
            WHERE created_at BETWEEN ? AND ?
            GROUP BY status
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['mission_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Communication allowances
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount,
                AVG(amount) as avg_amount
            FROM committee_communication_allowances 
            WHERE created_at BETWEEN ? AND ?
            GROUP BY status
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['communication_allowances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Detailed allowances data
        $stmt = $pdo->prepare("
            (SELECT 
                'mission' as allowance_type,
                ma.id,
                ma.mission_purpose as purpose,
                ma.amount,
                ma.status,
                ma.mission_date as date,
                u.full_name as recipient_name
            FROM mission_allowances ma
            LEFT JOIN users u ON ma.committee_member_id = u.id
            WHERE ma.created_at BETWEEN ? AND ?)
            
            UNION ALL
            
            (SELECT 
                'communication' as allowance_type,
                cca.id,
                CONCAT('Communication - ', cca.month_year) as purpose,
                cca.amount,
                cca.status,
                cca.payment_date as date,
                u.full_name as recipient_name
            FROM committee_communication_allowances cca
            LEFT JOIN users u ON cca.committee_member_id = u.id
            WHERE cca.created_at BETWEEN ? AND ?)
            
            ORDER BY date DESC
            LIMIT 100
        ");
        $stmt->execute([$start_date, $end_date, $start_date, $end_date]);
        $data['allowance_details'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Allowances report error: " . $e->getMessage());
    }
    
    return $data;
}

function getRentalIncomeReport($academic_year, $start_date, $end_date) {
    global $pdo;
    
    $data = [];
    
    try {
        // Rental income summary
        $stmt = $pdo->prepare("
            SELECT 
                rp.property_name,
                COUNT(rpm.id) as payment_count,
                COALESCE(SUM(rpm.amount), 0) as total_collected,
                AVG(rpm.amount) as avg_payment
            FROM rental_payments rpm
            LEFT JOIN rental_properties rp ON rpm.property_id = rp.id
            WHERE rpm.payment_date BETWEEN ? AND ?
            AND rpm.status = 'verified'
            GROUP BY rp.id, rp.property_name
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['rental_income'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Monthly rental income (PostgreSQL compatible)
        $stmt = $pdo->prepare("
            SELECT 
                TO_CHAR(payment_date, 'YYYY-MM') as month,
                COALESCE(SUM(amount), 0) as monthly_income,
                COUNT(*) as payment_count
            FROM rental_payments 
            WHERE payment_date BETWEEN ? AND ?
            AND status = 'verified'
            GROUP BY TO_CHAR(payment_date, 'YYYY-MM')
            ORDER BY month
        ");
        $stmt->execute([$start_date, $end_date]);
        $data['monthly_rental_income'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Rental income report error: " . $e->getMessage());
    }
    
    return $data;
}

// Function to get report title
function getReportTitle($report_type) {
    $titles = [
        'financial_summary' => 'Financial Summary Report',
        'income_statement' => 'Income Statement Report',
        'budget_vs_actual' => 'Budget vs Actual Report',
        'expense_analysis' => 'Expense Analysis Report',
        'student_aid_report' => 'Student Financial Aid Report',
        'allowances_report' => 'Allowances Report',
        'rental_income_report' => 'Rental Income Report'
    ];
    
    return $titles[$report_type] ?? 'Financial Report';
}

// PDF Generation Function
function generatePDFReport($title, $data, $academic_year, $start_date, $end_date) {
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('RPSU Finance System');
    $pdf->SetAuthor('RPSU Finance');
    $pdf->SetTitle($title);
    $pdf->SetSubject($title);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Add logo and header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'RPSU - ISONGA', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, "Academic Year: $academic_year", 0, 1, 'C');
    $pdf->Cell(0, 5, "Period: " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 5, "Generated on: " . date('F j, Y g:i A'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Add report content based on type
    $report_type = '';
    foreach ([
        'Financial Summary Report' => 'financial_summary',
        'Income Statement Report' => 'income_statement',
        'Budget vs Actual Report' => 'budget_vs_actual',
        'Expense Analysis Report' => 'expense_analysis',
        'Student Financial Aid Report' => 'student_aid_report',
        'Allowances Report' => 'allowances_report',
        'Rental Income Report' => 'rental_income_report'
    ] as $report_title => $type) {
        if ($title === $report_title) {
            $report_type = $type;
            break;
        }
    }
    
    switch ($report_type) {
        case 'financial_summary':
            generateFinancialSummaryPDF($pdf, $data);
            break;
        case 'income_statement':
            generateIncomeStatementPDF($pdf, $data);
            break;
        case 'budget_vs_actual':
            generateBudgetVsActualPDF($pdf, $data);
            break;
        case 'allowances_report':
            generateAllowancesPDF($pdf, $data);
            break;
        default:
            generateFinancialSummaryPDF($pdf, $data);
    }
    
    // Output PDF
    $filename = str_replace(' ', '_', $title) . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
}

// Excel Generation Function
function generateExcelReport($title, $data, $academic_year, $start_date, $end_date) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="' . str_replace(' ', '_', $title) . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "td { border: 1px solid #000; padding: 5px; }";
    echo "th { border: 1px solid #000; padding: 5px; background-color: #f0f0f0; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>RPSU - ISONGA</h2>";
    echo "<h3>$title</h3>";
    echo "<p><strong>Academic Year:</strong> $academic_year</p>";
    echo "<p><strong>Period:</strong> " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date)) . "</p>";
    echo "<p><strong>Generated on:</strong> " . date('F j, Y g:i A') . "</p>";
    echo "<br>";
    
    // Add report content based on type
    $report_type = '';
    foreach ([
        'Financial Summary Report' => 'financial_summary',
        'Income Statement Report' => 'income_statement',
        'Budget vs Actual Report' => 'budget_vs_actual',
        'Expense Analysis Report' => 'expense_analysis',
        'Student Financial Aid Report' => 'student_aid_report',
        'Allowances Report' => 'allowances_report',
        'Rental Income Report' => 'rental_income_report'
    ] as $report_title => $type) {
        if ($title === $report_title) {
            $report_type = $type;
            break;
        }
    }
    
    switch ($report_type) {
        case 'financial_summary':
            generateFinancialSummaryExcel($data);
            break;
        case 'income_statement':
            generateIncomeStatementExcel($data);
            break;
        case 'budget_vs_actual':
            generateBudgetVsActualExcel($data);
            break;
        case 'allowances_report':
            generateAllowancesExcel($data);
            break;
        default:
            generateFinancialSummaryExcel($data);
    }
    
    echo "</body>";
    echo "</html>";
    exit;
}

// PDF content generators
function generateFinancialSummaryPDF($pdf, $data) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Financial Overview', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(100, 7, 'Total Income:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['total_income'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Total Expenses:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['total_expenses'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Net Cash Flow:', 0, 0);
    $net_color = ($data['net_cash_flow'] ?? 0) >= 0 ? '' : '';
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['net_cash_flow'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Bank Balance:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['bank_balance'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Total Budget:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['total_budget'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Budget Utilization:', 0, 0);
    $pdf->Cell(0, 7, number_format($data['budget_utilization'] ?? 0, 1) . '%', 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Transaction Summary', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(100, 7, 'Total Transactions:', 0, 0);
    $pdf->Cell(0, 7, number_format($data['total_transactions'] ?? 0), 0, 1);
    
    $pdf->Cell(100, 7, 'Income Transactions:', 0, 0);
    $pdf->Cell(0, 7, number_format($data['income_transactions'] ?? 0), 0, 1);
    
    $pdf->Cell(100, 7, 'Expense Transactions:', 0, 0);
    $pdf->Cell(0, 7, number_format($data['expense_transactions'] ?? 0), 0, 1);
}

function generateIncomeStatementPDF($pdf, $data) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Income Statement Summary', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(100, 7, 'Total Income:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['total_income'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Total Expenses:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['total_expenses'] ?? 0, 2), 0, 1);
    
    $pdf->Cell(100, 7, 'Net Income:', 0, 0);
    $pdf->Cell(0, 7, 'RWF ' . number_format($data['net_income'] ?? 0, 2), 0, 1);
    
    $pdf->Ln(10);
    
    // Income Categories
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'Income by Category', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    foreach ($data['income_categories'] ?? [] as $category) {
        $pdf->Cell(80, 6, $category['category_name'], 0, 0);
        $pdf->Cell(40, 6, 'RWF ' . number_format($category['amount'], 2), 0, 0);
        $pdf->Cell(30, 6, $category['transaction_count'] . ' trans', 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Expense Categories
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'Expenses by Category', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    foreach ($data['expense_categories'] ?? [] as $category) {
        $pdf->Cell(80, 6, $category['category_name'], 0, 0);
        $pdf->Cell(40, 6, 'RWF ' . number_format($category['amount'], 2), 0, 0);
        $pdf->Cell(30, 6, $category['transaction_count'] . ' trans', 0, 1);
    }
}

function generateBudgetVsActualPDF($pdf, $data) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Budget vs Actual Comparison', 0, 1);
    $pdf->SetFont('helvetica', 'B', 9);
    
    // Header
    $pdf->Cell(60, 6, 'Category', 1, 0);
    $pdf->Cell(30, 6, 'Budgeted', 1, 0);
    $pdf->Cell(30, 6, 'Actual', 1, 0);
    $pdf->Cell(25, 6, 'Variance', 1, 0);
    $pdf->Cell(25, 6, 'Utilization', 1, 1);
    
    $pdf->SetFont('helvetica', '', 9);
    
    foreach ($data['budget_comparison'] ?? [] as $comparison) {
        $pdf->Cell(60, 6, substr($comparison['category_name'], 0, 30), 1, 0);
        $pdf->Cell(30, 6, 'RWF ' . number_format($comparison['budgeted_amount'], 0), 1, 0);
        $pdf->Cell(30, 6, 'RWF ' . number_format($comparison['actual_amount'], 0), 1, 0);
        $pdf->Cell(25, 6, 'RWF ' . number_format($comparison['variance'], 0), 1, 0);
        $pdf->Cell(25, 6, number_format($comparison['utilization_percentage'], 1) . '%', 1, 1);
    }
}

function generateAllowancesPDF($pdf, $data) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Allowances Summary', 0, 1);
    
    // Mission Allowances
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'Mission Allowances', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    $pdf->Cell(60, 6, 'Status', 1, 0);
    $pdf->Cell(40, 6, 'Count', 1, 0);
    $pdf->Cell(50, 6, 'Total Amount', 1, 1);
    
    foreach ($data['mission_allowances'] ?? [] as $allowance) {
        $pdf->Cell(60, 6, ucfirst($allowance['status']), 1, 0);
        $pdf->Cell(40, 6, $allowance['count'] . ' records', 1, 0);
        $pdf->Cell(50, 6, 'RWF ' . number_format($allowance['total_amount'], 2), 1, 1);
    }
    
    $pdf->Ln(5);
    
    // Communication Allowances
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'Communication Allowances', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    
    $pdf->Cell(60, 6, 'Status', 1, 0);
    $pdf->Cell(40, 6, 'Count', 1, 0);
    $pdf->Cell(50, 6, 'Total Amount', 1, 1);
    
    foreach ($data['communication_allowances'] ?? [] as $allowance) {
        $pdf->Cell(60, 6, ucfirst($allowance['status']), 1, 0);
        $pdf->Cell(40, 6, $allowance['count'] . ' records', 1, 0);
        $pdf->Cell(50, 6, 'RWF ' . number_format($allowance['total_amount'], 2), 1, 1);
    }
}

// Excel content generators
function generateFinancialSummaryExcel($data) {
    echo "<table>";
    echo "<tr><th colspan='2'>Financial Overview</th></tr>";
    echo "<tr><td>Total Income:</td><td>RWF " . number_format($data['total_income'] ?? 0, 2) . "</td></tr>";
    echo "<tr><td>Total Expenses:</td><td>RWF " . number_format($data['total_expenses'] ?? 0, 2) . "</td></tr>";
    echo "<tr><td>Net Cash Flow:</td><td>RWF " . number_format($data['net_cash_flow'] ?? 0, 2) . "</td></tr>";
    echo "<tr><td>Bank Balance:</td><td>RWF " . number_format($data['bank_balance'] ?? 0, 2) . "</td></tr>";
    echo "<tr><td>Total Budget:</td><td>RWF " . number_format($data['total_budget'] ?? 0, 2) . "</td></tr>";
    echo "<tr><td>Budget Utilization:</td><td>" . number_format($data['budget_utilization'] ?? 0, 1) . "%</td></tr>";
    echo "</table><br>";
    
    echo "<table>";
    echo "<tr><th colspan='2'>Transaction Summary</th></tr>";
    echo "irs<th>Total Transactions:</th><td>" . number_format($data['total_transactions'] ?? 0) . "</td></tr>";
    echo "<tr><th>Income Transactions:</th><td>" . number_format($data['income_transactions'] ?? 0) . "</td></tr>";
    echo "<tr><th>Expense Transactions:</th><td>" . number_format($data['expense_transactions'] ?? 0) . "</td></tr>";
    echo "</table>";
}

function generateIncomeStatementExcel($data) {
    echo "<table>";
    echo "<tr><th colspan='2'>Income Statement Summary</th></tr>";
    echo "<tr><th>Total Income:</th><td>RWF " . number_format($data['total_income'] ?? 0, 2) . "</td></tr>";
    echo "<tr><th>Total Expenses:</th><td>RWF " . number_format($data['total_expenses'] ?? 0, 2) . "</td></tr>";
    echo "<tr><th>Net Income:</th><td>RWF " . number_format($data['net_income'] ?? 0, 2) . "</td></tr>";
    echo "</table><br>";
    
    echo "<table>";
    echo "<tr><th colspan='3'>Income by Category</th></tr>";
    echo "<tr><th>Category</th><th>Amount</th><th>Transactions</th></tr>";
    foreach ($data['income_categories'] ?? [] as $category) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($category['category_name']) . "</td>";
        echo "<td>RWF " . number_format($category['amount'], 2) . "</td>";
        echo "<td>" . $category['transaction_count'] . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    echo "<table>";
    echo "<tr><th colspan='3'>Expenses by Category</th></tr>";
    echo "<tr><th>Category</th><th>Amount</th><th>Transactions</th></tr>";
    foreach ($data['expense_categories'] ?? [] as $category) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($category['category_name']) . "</td>";
        echo "<td>RWF " . number_format($category['amount'], 2) . "</td>";
        echo "<td>" . $category['transaction_count'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

function generateBudgetVsActualExcel($data) {
    echo "<table>";
    echo "<tr><th colspan='5'>Budget vs Actual Comparison</th></tr>";
    echo "<tr><th>Category</th><th>Budgeted</th><th>Actual</th><th>Variance</th><th>Utilization</th></tr>";
    
    foreach ($data['budget_comparison'] ?? [] as $comparison) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($comparison['category_name']) . "</td>";
        echo "<td>RWF " . number_format($comparison['budgeted_amount'], 2) . "</td>";
        echo "<td>RWF " . number_format($comparison['actual_amount'], 2) . "</td>";
        echo "<td>RWF " . number_format($comparison['variance'], 2) . "</td>";
        echo "<td>" . number_format($comparison['utilization_percentage'], 1) . "%</td>";
        echo "</tr>";
    }
    echo "</table>";
}

function generateAllowancesExcel($data) {
    echo "<table>";
    echo "<tr><th colspan='4'>Mission Allowances Summary</th></tr>";
    echo "<tr><th>Status</th><th>Count</th><th>Total Amount</th><th>Average</th></tr>";
    
    foreach ($data['mission_allowances'] ?? [] as $allowance) {
        echo "<tr>";
        echo "<td>" . ucfirst($allowance['status']) . "</td>";
        echo "<td>" . $allowance['count'] . "</td>";
        echo "<td>RWF " . number_format($allowance['total_amount'], 2) . "</td>";
        echo "<td>RWF " . number_format($allowance['avg_amount'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    echo "<table>";
    echo "<tr><th colspan='4'>Communication Allowances Summary</th></tr>";
    echo "<tr><th>Status</th><th>Count</th><th>Total Amount</th><th>Average</th></tr>";
    
    foreach ($data['communication_allowances'] ?? [] as $allowance) {
        echo "<tr>";
        echo "<td>" . ucfirst($allowance['status']) . "</td>";
        echo "<td>" . $allowance['count'] . "</td>";
        echo "<td>RWF " . number_format($allowance['total_amount'], 2) . "</td>";
        echo "<td>RWF " . number_format($allowance['avg_amount'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Financial Reports - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --finance-primary: #1976D2;
            --finance-secondary: #2196F3;
            --finance-accent: #0D47A1;
            --finance-light: #E3F2FD;
            --gradient-primary: linear-gradient(135deg, var(--finance-primary) 0%, var(--finance-accent) 100%);
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
            --info: #4dd0e1;
            --finance-primary: #2196F3;
            --finance-secondary: #64B5F6;
            --finance-accent: #1976D2;
            --finance-light: #0D1B2A;
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

        .logo {
            height: 40px;
            width: auto;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--finance-primary);
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
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--dark-gray);
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
        }

        .icon-btn:hover {
            background: var(--finance-primary);
            color: white;
            border-color: var(--finance-primary);
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
            background: var(--finance-primary);
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
            background: var(--finance-light);
            border-left-color: var(--finance-primary);
            color: var(--finance-primary);
        }

        .menu-item i {
            width: 20px;
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

        /* Dashboard Header */
        .dashboard-header {
            margin-bottom: 1.5rem;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        /* Report Controls */
        .report-controls {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .control-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--finance-primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--finance-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--finance-accent);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .export-options {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Report Display */
        .report-display {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .report-header {
            text-align: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--finance-light);
        }

        .report-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .report-meta {
            color: var(--dark-gray);
            font-size: 0.8rem;
        }

        .report-body {
            padding: 1.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--finance-primary);
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
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            background: var(--finance-light);
            color: var(--finance-primary);
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

        /* Tables */
        .table-container {
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

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

        .table tbody tr:hover {
            background: var(--finance-light);
        }

        .amount {
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        .amount.positive {
            color: var(--success);
        }

        .amount.negative {
            color: var(--danger);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed, .status-verified, .status-approved_by_president {
            background: #d4edda;
            color: var(--success);
        }

        .status-pending, .status-submitted, .status-under_review {
            background: #fff3cd;
            color: #856404;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1.5rem 0;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--dark-gray);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
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
                background: var(--finance-primary);
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

            #sidebarToggleBtn {
                display: none;
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

            .control-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .export-options {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }

            .stat-number {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 200px;
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

            .welcome-section h1 {
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
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                <div class="brand-text">
                    <h1>Isonga - Financial Reports</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="icon-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
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
                        <div class="user-role">Vice Guild Finance</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
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
                    <a href="budget_management.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Budget Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="transactions.php">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transactions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="committee_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Committee Requests</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student_aid.php">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Student Financial Aid</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="rental_management.php">
                        <i class="fas fa-home"></i>
                        <span>Rental Properties</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="allowances.php">
                        <i class="fas fa-money-check"></i>
                        <span>Allowances</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="bank_reconciliation.php">
                        <i class="fas fa-university"></i>
                        <span>Bank Reconciliation</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="financial_reports.php" class="active">
                        <i class="fas fa-chart-bar"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="documents.php">
                        <i class="fas fa-file-contract"></i>
                        <span>Official Documents</span>
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
        <main class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="welcome-section">
                    <h1>Financial Reports & Analytics 📊</h1>
                    <p>Generate comprehensive financial reports and export them in multiple formats</p>
                </div>
            </div>

            <!-- Report Controls -->
            <div class="report-controls">
                <form method="POST" action="" id="reportForm">
                    <input type="hidden" name="action" value="generate_report">
                    
                    <div class="control-grid">
                        <div class="form-group">
                            <label class="form-label">Report Type</label>
                            <select class="form-select" name="report_type" id="reportType">
                                <option value="financial_summary" <?php echo $report_type === 'financial_summary' ? 'selected' : ''; ?>>Financial Summary</option>
                                <option value="income_statement" <?php echo $report_type === 'income_statement' ? 'selected' : ''; ?>>Income Statement</option>
                                <option value="budget_vs_actual" <?php echo $report_type === 'budget_vs_actual' ? 'selected' : ''; ?>>Budget vs Actual</option>
                                <option value="expense_analysis" <?php echo $report_type === 'expense_analysis' ? 'selected' : ''; ?>>Expense Analysis</option>
                                <option value="student_aid_report" <?php echo $report_type === 'student_aid_report' ? 'selected' : ''; ?>>Student Aid Report</option>
                                <option value="allowances_report" <?php echo $report_type === 'allowances_report' ? 'selected' : ''; ?>>Allowances Report</option>
                                <option value="rental_income_report" <?php echo $report_type === 'rental_income_report' ? 'selected' : ''; ?>>Rental Income Report</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Academic Year</label>
                            <select class="form-select" name="academic_year">
                                <?php foreach ($academic_year_options as $option): ?>
                                    <option value="<?php echo $option; ?>" <?php echo $option === $academic_year ? 'selected' : ''; ?>>
                                        <?php echo $option; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Export Options</label>
                        <div class="export-options">
                            <button type="submit" name="format" value="html" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View Report
                            </button>
                            <button type="submit" name="format" value="pdf" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            <button type="submit" name="format" value="excel" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Report Display -->
            <?php if ($action === 'generate_report' && $format === 'html'): ?>
                <div class="report-display">
                    <div class="report-header">
                        <h2 class="report-title"><?php echo getReportTitle($report_type); ?></h2>
                        <div class="report-meta">
                            Academic Year: <?php echo htmlspecialchars($academic_year); ?> | 
                            Period: <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?> | 
                            Generated on: <?php echo date('F j, Y g:i A'); ?>
                        </div>
                    </div>
                    <div class="report-body">
                        <?php switch ($report_type):
                            case 'financial_summary': ?>
                                <!-- Financial Summary Report -->
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['total_income'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Total Income</div>
                                        </div>
                                    </div>
                                    <div class="stat-card danger">
                                        <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['total_expenses'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Total Expenses</div>
                                        </div>
                                    </div>
                                    <div class="stat-card <?php echo ($report_data['net_cash_flow'] ?? 0) >= 0 ? 'success' : 'danger'; ?>">
                                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['net_cash_flow'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Net Cash Flow</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-university"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['bank_balance'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Bank Balance</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['total_budget'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Total Budget</div>
                                        </div>
                                    </div>
                                    <div class="stat-card <?php echo ($report_data['budget_utilization'] ?? 0) <= 80 ? 'success' : 'warning'; ?>">
                                        <div class="stat-icon"><i class="fas fa-chart-pie"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number"><?php echo number_format($report_data['budget_utilization'] ?? 0, 1); ?>%</div>
                                            <div class="stat-label">Budget Utilization</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number"><?php echo number_format($report_data['total_transactions'] ?? 0); ?></div>
                                            <div class="stat-label">Total Transactions</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Metric</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Income Transactions</td>
                                                <td><?php echo number_format($report_data['income_transactions'] ?? 0); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Expense Transactions</td>
                                                <td><?php echo number_format($report_data['expense_transactions'] ?? 0); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <?php break; ?>
                                
                            <?php case 'income_statement': ?>
                                <!-- Income Statement Report -->
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['total_income'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Total Income</div>
                                        </div>
                                    </div>
                                    <div class="stat-card danger">
                                        <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['total_expenses'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Total Expenses</div>
                                        </div>
                                    </div>
                                    <div class="stat-card <?php echo ($report_data['net_income'] ?? 0) >= 0 ? 'success' : 'danger'; ?>">
                                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($report_data['net_income'] ?? 0, 0); ?></div>
                                            <div class="stat-label">Net Income</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <h3 style="margin: 1rem 0 0.5rem;">Income by Category</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Amount</th>
                                                <th>Transactions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['income_categories'] ?? [] as $category): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                                    <td class="amount positive">RWF <?php echo number_format($category['amount'], 0); ?></td>
                                                    <td><?php echo $category['transaction_count']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['income_categories'])): ?>
                                                <tr><td colspan="3" class="empty-state">No income data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <h3 style="margin: 1rem 0 0.5rem;">Expenses by Category</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Amount</th>
                                                <th>Transactions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['expense_categories'] ?? [] as $category): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                                    <td class="amount negative">RWF <?php echo number_format($category['amount'], 0); ?></td>
                                                    <td><?php echo $category['transaction_count']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['expense_categories'])): ?>
                                                <tr><td colspan="3" class="empty-state">No expense data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php break; ?>
                                
                            <?php case 'budget_vs_actual': ?>
                                <!-- Budget vs Actual Report -->
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Budgeted</th>
                                                <th>Actual</th>
                                                <th>Variance</th>
                                                <th>Utilization</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['budget_comparison'] ?? [] as $comparison): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($comparison['category_name']); ?></td>
                                                    <td>RWF <?php echo number_format($comparison['budgeted_amount'], 0); ?></td>
                                                    <td>RWF <?php echo number_format($comparison['actual_amount'], 0); ?></td>
                                                    <td class="amount <?php echo $comparison['variance'] >= 0 ? 'positive' : 'negative'; ?>">
                                                        RWF <?php echo number_format($comparison['variance'], 0); ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $comparison['utilization_percentage'] <= 80 ? 'status-completed' : 'status-pending'; ?>">
                                                            <?php echo number_format($comparison['utilization_percentage'], 1); ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['budget_comparison'])): ?>
                                                <tr><td colspan="5" class="empty-state">No budget data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php break; ?>
                                
                            <?php case 'expense_analysis': ?>
                                <!-- Expense Analysis Report -->
                                <h3 style="margin: 0 0 1rem;">Top 20 Expenses</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Description</th>
                                                <th>Category</th>
                                                <th>Payee</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['top_expenses'] ?? [] as $expense): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y', strtotime($expense['transaction_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($expense['description'], 0, 40)); ?></td>
                                                    <td><?php echo htmlspecialchars($expense['category_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($expense['payee_payer'] ?? 'N/A'); ?></td>
                                                    <td class="amount negative">RWF <?php echo number_format($expense['amount'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['top_expenses'])): ?>
                                                <tr><td colspan="5" class="empty-state">No expense data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (!empty($report_data['monthly_trends'])): ?>
                                    <h3 style="margin: 1rem 0 0.5rem;">Monthly Expense Trends</h3>
                                    <div class="chart-container">
                                        <canvas id="expenseTrendsChart"></canvas>
                                    </div>
                                    <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                            const ctx = document.getElementById('expenseTrendsChart');
                                            if (ctx) {
                                                new Chart(ctx, {
                                                    type: 'line',
                                                    data: {
                                                        labels: <?php echo json_encode(array_map(function($trend) {
                                                            return date('M Y', strtotime($trend['month'] . '-01'));
                                                        }, $report_data['monthly_trends'])); ?>,
                                                        datasets: [{
                                                            label: 'Monthly Expenses',
                                                            data: <?php echo json_encode(array_column($report_data['monthly_trends'], 'monthly_expenses')); ?>,
                                                            borderColor: '#dc3545',
                                                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                                            fill: true,
                                                            tension: 0.4
                                                        }]
                                                    },
                                                    options: {
                                                        responsive: true,
                                                        maintainAspectRatio: false,
                                                        scales: {
                                                            y: {
                                                                beginAtZero: true,
                                                                ticks: {
                                                                    callback: function(value) {
                                                                        return 'RWF ' + value.toLocaleString();
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                });
                                            }
                                        });
                                    </script>
                                <?php endif; ?>
                                <?php break; ?>
                                
                            <?php case 'student_aid_report': ?>
                                <!-- Student Aid Report -->
                                <div class="stats-grid">
                                    <?php 
                                    $total_requested = array_sum(array_column($report_data['aid_summary'] ?? [], 'total_requested'));
                                    $total_approved = array_sum(array_column($report_data['aid_summary'] ?? [], 'total_approved'));
                                    ?>
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($total_requested, 0); ?></div>
                                            <div class="stat-label">Total Requested</div>
                                        </div>
                                    </div>
                                    <div class="stat-card success">
                                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format($total_approved, 0); ?></div>
                                            <div class="stat-label">Total Approved</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <h3 style="margin: 1rem 0 0.5rem;">Aid Summary by Status</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th>Requests</th>
                                                <th>Total Requested</th>
                                                <th>Total Approved</th>
                                                <th>Average Requested</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['aid_summary'] ?? [] as $summary): ?>
                                                <tr>
                                                    <td><span class="status-badge status-<?php echo str_replace('_', '-', $summary['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $summary['status'])); ?></span></td>
                                                    <td><?php echo $summary['request_count']; ?></td>
                                                    <td>RWF <?php echo number_format($summary['total_requested'], 0); ?></td>
                                                    <td>RWF <?php echo number_format($summary['total_approved'], 0); ?></td>
                                                    <td>RWF <?php echo number_format($summary['avg_requested'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['aid_summary'])): ?>
                                                <tr><td colspan="5" class="empty-state">No aid data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <h3 style="margin: 1rem 0 0.5rem;">Recent Aid Requests</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Registration #</th>
                                                <th>Purpose</th>
                                                <th>Amount Requested</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['recent_requests'] ?? [] as $request): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($request['student_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['registration_number'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($request['purpose'], 0, 40)); ?></td>
                                                    <td>RWF <?php echo number_format($request['amount_requested'], 0); ?></td>
                                                    <td><span class="status-badge status-<?php echo str_replace('_', '-', $request['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['recent_requests'])): ?>
                                                <tr><td colspan="5" class="empty-state">No recent aid requests</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php break; ?>
                                
                            <?php case 'allowances_report': ?>
                                <!-- Allowances Report -->
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-plane"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format(array_sum(array_column($report_data['mission_allowances'] ?? [], 'total_amount')), 0); ?></div>
                                            <div class="stat-label">Mission Allowances</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon"><i class="fas fa-phone-alt"></i></div>
                                        <div class="stat-content">
                                            <div class="stat-number">RWF <?php echo number_format(array_sum(array_column($report_data['communication_allowances'] ?? [], 'total_amount')), 0); ?></div>
                                            <div class="stat-label">Communication Allowances</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <h3 style="margin: 1rem 0 0.5rem;">Mission Allowances Summary</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th>Count</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['mission_allowances'] ?? [] as $allowance): ?>
                                                <tr>
                                                    <td><span class="status-badge status-<?php echo str_replace('_', '-', $allowance['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $allowance['status'])); ?></span></td>
                                                    <td><?php echo $allowance['count']; ?></td>
                                                    <td>RWF <?php echo number_format($allowance['total_amount'], 0); ?></td>
                                                    <td>RWF <?php echo number_format($allowance['avg_amount'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['mission_allowances'])): ?>
                                                <tr><td colspan="4" class="empty-state">No mission allowance data</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <h3 style="margin: 1rem 0 0.5rem;">Communication Allowances Summary</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th>Count</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['communication_allowances'] ?? [] as $allowance): ?>
                                                <tr>
                                                    <td><span class="status-badge status-<?php echo str_replace('_', '-', $allowance['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $allowance['status'])); ?></span></td>
                                                    <td><?php echo $allowance['count']; ?></td>
                                                    <td>RWF <?php echo number_format($allowance['total_amount'], 0); ?></td>
                                                    <td>RWF <?php echo number_format($allowance['avg_amount'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['communication_allowances'])): ?>
                                                <tr><td colspan="4" class="empty-state">No communication allowance data</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php break; ?>
                                
                            <?php case 'rental_income_report': ?>
                                <!-- Rental Income Report -->
                                <h3 style="margin: 0 0 1rem;">Rental Income Summary</h3>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Property</th>
                                                <th>Payments</th>
                                                <th>Total Collected</th>
                                                <th>Average Payment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report_data['rental_income'] ?? [] as $rental): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($rental['property_name']); ?></td>
                                                    <td><?php echo $rental['payment_count']; ?></td>
                                                    <td class="amount positive">RWF <?php echo number_format($rental['total_collected'], 0); ?></td>
                                                    <td>RWF <?php echo number_format($rental['avg_payment'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($report_data['rental_income'])): ?>
                                                <tr><td colspan="4" class="empty-state">No rental income data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (!empty($report_data['monthly_rental_income'])): ?>
                                    <h3 style="margin: 1rem 0 0.5rem;">Monthly Rental Income</h3>
                                    <div class="chart-container">
                                        <canvas id="rentalIncomeChart"></canvas>
                                    </div>
                                    <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                            const ctx = document.getElementById('rentalIncomeChart');
                                            if (ctx) {
                                                new Chart(ctx, {
                                                    type: 'bar',
                                                    data: {
                                                        labels: <?php echo json_encode(array_map(function($item) {
                                                            return date('M Y', strtotime($item['month'] . '-01'));
                                                        }, $report_data['monthly_rental_income'])); ?>,
                                                        datasets: [{
                                                            label: 'Rental Income',
                                                            data: <?php echo json_encode(array_column($report_data['monthly_rental_income'], 'monthly_income')); ?>,
                                                            backgroundColor: '#28a745',
                                                            borderRadius: 8
                                                        }]
                                                    },
                                                    options: {
                                                        responsive: true,
                                                        maintainAspectRatio: false,
                                                        scales: {
                                                            y: {
                                                                beginAtZero: true,
                                                                ticks: {
                                                                    callback: function(value) {
                                                                        return 'RWF ' + value.toLocaleString();
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                });
                                            }
                                        });
                                    </script>
                                <?php endif; ?>
                                <?php break; ?>
                        <?php endswitch; ?>
                    </div>
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