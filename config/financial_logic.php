<?php
require_once 'database.php';

class FinancialLogic {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Update account balance when transaction is completed
     */
    public function updateAccountBalance($transaction_id) {
        try {
            // Get transaction details
            $stmt = $this->pdo->prepare("
                SELECT account_id, transaction_type, amount, status 
                FROM financial_transactions 
                WHERE id = ? AND status = 'completed'
            ");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                return false;
            }
            
            // Update account balance based on transaction type
            if ($transaction['transaction_type'] === 'expense') {
                $sql = "UPDATE financial_accounts SET current_balance = current_balance - ? WHERE id = ?";
            } else if ($transaction['transaction_type'] === 'income') {
                $sql = "UPDATE financial_accounts SET current_balance = current_balance + ? WHERE id = ?";
            } else {
                // For transfers, you'd need additional logic
                return true;
            }
            
            $updateStmt = $this->pdo->prepare($sql);
            $updateStmt->execute([$transaction['amount'], $transaction['account_id']]);
            
            // Log the balance update
            $this->logBalanceUpdate($transaction['account_id'], $transaction['amount'], 
                                  $transaction['transaction_type'], $transaction_id);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Balance update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update budget tracking when expense is completed
     */
    public function updateBudgetTracking($transaction_id) {
        try {
            // Get transaction details
            $stmt = $this->pdo->prepare("
                SELECT ft.category_id, ft.amount, ft.transaction_date, ba.academic_year, ba.id as allocation_id
                FROM financial_transactions ft
                LEFT JOIN budget_allocations ba ON ft.category_id = ba.category_id 
                    AND ba.academic_year = ?
                WHERE ft.id = ? AND ft.transaction_type = 'expense' AND ft.status = 'completed'
            ");
            
            $current_year = '2024-2025'; // You might want to make this dynamic
            $stmt->execute([$current_year, $transaction_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data || !$data['allocation_id']) {
                return false;
            }
            
            // Check if tracking record exists for this period
            $tracking_month = date('Y-m-01', strtotime($data['transaction_date']));
            
            $checkStmt = $this->pdo->prepare("
                SELECT id, spent_amount FROM budget_tracking 
                WHERE budget_allocation_id = ? AND tracking_period = ?
            ");
            $checkStmt->execute([$data['allocation_id'], $tracking_month]);
            $tracking = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tracking) {
                // Update existing tracking record
                $updateStmt = $this->pdo->prepare("
                    UPDATE budget_tracking 
                    SET spent_amount = spent_amount + ?,
                        remaining_amount = allocated_amount - (spent_amount + ?),
                        utilization_rate = ROUND(((spent_amount + ?) / allocated_amount) * 100, 2),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $data['amount'], 
                    $data['amount'], 
                    $data['amount'],
                    $tracking['id']
                ]);
            } else {
                // Create new tracking record
                $allocStmt = $this->pdo->prepare("
                    SELECT allocated_amount FROM budget_allocations WHERE id = ?
                ");
                $allocStmt->execute([$data['allocation_id']]);
                $allocation = $allocStmt->fetch(PDO::FETCH_ASSOC);
                
                $spent = $data['amount'];
                $remaining = $allocation['allocated_amount'] - $spent;
                $utilization = ($spent / $allocation['allocated_amount']) * 100;
                
                $insertStmt = $this->pdo->prepare("
                    INSERT INTO budget_tracking 
                    (budget_allocation_id, category_id, allocated_amount, spent_amount, 
                     remaining_amount, utilization_rate, tracking_period)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $data['allocation_id'],
                    $data['category_id'],
                    $allocation['allocated_amount'],
                    $spent,
                    $remaining,
                    $utilization,
                    $tracking_month
                ]);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Budget tracking error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process payment request and create transaction
     */
    public function processPaymentRequest($payment_request_id, $approved_by) {
        try {
            $this->pdo->beginTransaction();
            
            // Get payment request details
            $stmt = $this->pdo->prepare("
                SELECT * FROM payment_requests WHERE id = ? AND status = 'approved'
            ");
            $stmt->execute([$payment_request_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception("Payment request not found or not approved");
            }
            
            // Create financial transaction
            $transactionStmt = $this->pdo->prepare("
                INSERT INTO financial_transactions 
                (transaction_type, account_id, category_id, amount, description, 
                 transaction_date, payee_payer, payment_method, status, requested_by, 
                 approved_by, approved_at, requires_authorization)
                VALUES ('expense', ?, ?, ?, ?, CURDATE(), ?, 'bank_transfer', 
                       'completed', ?, ?, NOW(), 0)
            ");
            
            $transactionStmt->execute([
                $payment['account_id'],
                $payment['category_id'],
                $payment['amount'],
                $payment['title'] . ' - ' . $payment['description'],
                $payment['payee_name'],
                $payment['requested_by'],
                $approved_by
            ]);
            
            $transaction_id = $this->pdo->lastInsertId();
            
            // Update payment request status
            $updateStmt = $this->pdo->prepare("
                UPDATE payment_requests 
                SET status = 'paid', payment_date = CURDATE() 
                WHERE id = ?
            ");
            $updateStmt->execute([$payment_request_id]);
            
            // Update account balance
            $this->updateAccountBalance($transaction_id);
            
            // Update budget tracking
            $this->updateBudgetTracking($transaction_id);
            
            $this->pdo->commit();
            return $transaction_id;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Payment processing error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Approve and process financial transaction
     */
    public function approveTransaction($transaction_id, $approver_id) {
        try {
            $this->pdo->beginTransaction();
            
            // Update transaction status
            $stmt = $this->pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'completed', approved_by = ?, approved_at = NOW()
                WHERE id = ? AND status = 'pending_approval'
            ");
            $stmt->execute([$approver_id, $transaction_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception("Transaction not found or already processed");
            }
            
            // Update account balance
            $this->updateAccountBalance($transaction_id);
            
            // Update budget tracking for expenses
            $typeStmt = $this->pdo->prepare("
                SELECT transaction_type FROM financial_transactions WHERE id = ?
            ");
            $typeStmt->execute([$transaction_id]);
            $transaction_type = $typeStmt->fetchColumn();
            
            if ($transaction_type === 'expense') {
                $this->updateBudgetTracking($transaction_id);
            }
            
            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Transaction approval error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reject transaction
     */
    public function rejectTransaction($transaction_id, $approver_id, $reason) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE financial_transactions 
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
                WHERE id = ? AND status = 'pending_approval'
            ");
            return $stmt->execute([$approver_id, $reason, $transaction_id]);
            
        } catch (PDOException $e) {
            error_log("Transaction rejection error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log balance updates for audit trail
     */
    private function logBalanceUpdate($account_id, $amount, $transaction_type, $transaction_id) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO account_balance_logs 
                (account_id, transaction_id, amount, transaction_type, previous_balance, new_balance, created_at)
                SELECT ?, ?, ?, ?, current_balance, 
                       current_balance + CASE WHEN ? = 'income' THEN ? ELSE -? END,
                       NOW()
                FROM financial_accounts 
                WHERE id = ?
            ");
            $stmt->execute([
                $account_id, 
                $transaction_id, 
                $amount,
                $transaction_type,
                $transaction_type,
                $amount,
                $amount,
                $account_id
            ]);
        } catch (PDOException $e) {
            error_log("Balance log error: " . $e->getMessage());
        }
    }
    
    /**
     * Get real-time financial summary
     */
    public function getFinancialSummary($academic_year = '2024-2025') {
        try {
            $summary = [];
            
            // Total allocated budget
            $stmt = $this->pdo->prepare("
                SELECT SUM(allocated_amount) as total_budget 
                FROM budget_allocations 
                WHERE academic_year = ?
            ");
            $stmt->execute([$academic_year]);
            $summary['total_budget'] = $stmt->fetchColumn() ?? 0;
            
            // Total expenses (completed transactions only)
            $stmt = $this->pdo->prepare("
                SELECT SUM(amount) as total_expenses 
                FROM financial_transactions 
                WHERE transaction_type = 'expense' 
                AND status = 'completed'
                AND YEAR(transaction_date) = YEAR(CURDATE())
            ");
            $stmt->execute();
            $summary['total_expenses'] = $stmt->fetchColumn() ?? 0;
            
            // Total income (completed transactions only)
            $stmt = $this->pdo->prepare("
                SELECT SUM(amount) as total_income 
                FROM financial_transactions 
                WHERE transaction_type = 'income' 
                AND status = 'completed'
                AND YEAR(transaction_date) = YEAR(CURDATE())
            ");
            $stmt->execute();
            $summary['total_income'] = $stmt->fetchColumn() ?? 0;
            
            // Available balance
            $summary['available_balance'] = $summary['total_budget'] - $summary['total_expenses'];
            
            // Utilization percentage
            $summary['utilization_percentage'] = $summary['total_budget'] > 0 ? 
                round(($summary['total_expenses'] / $summary['total_budget']) * 100, 1) : 0;
            
            return $summary;
            
        } catch (PDOException $e) {
            error_log("Financial summary error: " . $e->getMessage());
            return [];
        }
    }
}

// Create global instance
$financialLogic = new FinancialLogic($pdo);
?>