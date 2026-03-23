<?php
require_once 'database.php';

function getCurrentAcademicYear() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT academic_year FROM academic_years WHERE is_current = TRUE LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['academic_year'];
        } else {
            // Fallback to dynamic calculation
            return calculateCurrentAcademicYear();
        }
    } catch (PDOException $e) {
        error_log("Academic year error: " . $e->getMessage());
        return calculateCurrentAcademicYear();
    }
}

function calculateCurrentAcademicYear() {
    $current_month = date('n');
    $current_year = date('Y');
    
    if ($current_month >= 8) { // August to December
        return $current_year . '-' . ($current_year + 1);
    } else { // January to June
        return ($current_year - 1) . '-' . $current_year;
    }
}

function getAcademicYearByDate($date) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT academic_year FROM academic_years WHERE ? BETWEEN start_date AND end_date LIMIT 1");
        $stmt->execute([$date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['academic_year'];
        }
    } catch (PDOException $e) {
        error_log("Academic year by date error: " . $e->getMessage());
    }
    
    // Fallback to calculation
    return calculateAcademicYearByDate($date);
}

function calculateAcademicYearByDate($date) {
    $timestamp = strtotime($date);
    $month = date('n', $timestamp);
    $year = date('Y', $timestamp);
    
    if ($month >= 8) { // August to December
        return $year . '-' . ($year + 1);
    } else { // January to June
        return ($year - 1) . '-' . $year;
    }
}

function getAcademicYearOptions() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT academic_year FROM academic_years ORDER BY start_date DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Academic year options error: " . $e->getMessage());
        return getDefaultAcademicYearOptions();
    }
}

function getDefaultAcademicYearOptions() {
    $current = calculateCurrentAcademicYear();
    $current_start = intval(explode('-', $current)[0]);
    
    $options = [];
    for ($i = 2; $i >= 0; $i--) {
        $year = $current_start - $i;
        $options[] = $year . '-' . ($year + 1);
    }
    
    for ($i = 1; $i <= 2; $i++) {
        $year = $current_start + $i;
        $options[] = $year . '-' . ($year + 1);
    }
    
    return $options;
}

function setCurrentAcademicYear($academic_year) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Reset all to not current
        $pdo->exec("UPDATE academic_years SET is_current = FALSE");
        
        // Set the selected one as current
        $stmt = $pdo->prepare("UPDATE academic_years SET is_current = TRUE WHERE academic_year = ?");
        $stmt->execute([$academic_year]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Set academic year error: " . $e->getMessage());
        return false;
    }
}
?>