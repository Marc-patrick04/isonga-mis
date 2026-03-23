<?php

// Add this function to financial_logic.php or create a new file
function getFinancialTransactionsReport($start_date = null, $end_date = null, $category_id = null) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                ft.*,
                bc.category_name,
                bc.category_type,
                u1.full_name as requested_by_name,
                u2.full_name as finance_approver_name,
                u3.full_name as president_approver_name
            FROM financial_transactions ft
            LEFT JOIN budget_categories bc ON ft.category_id = bc.id
            LEFT JOIN users u1 ON ft.requested_by = u1.id
            LEFT JOIN users u2 ON ft.approved_by_finance = u2.id
            LEFT JOIN users u3 ON ft.approved_by_president = u3.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($start_date) {
            $query .= " AND ft.transaction_date >= ?";
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $query .= " AND ft.transaction_date <= ?";
            $params[] = $end_date;
        }
        
        if ($category_id) {
            $query .= " AND ft.category_id = ?";
            $params[] = $category_id;
        }
        
        $query .= " ORDER BY ft.transaction_date DESC, ft.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Financial report error: " . $e->getMessage());
        return [];
    }
}
?>