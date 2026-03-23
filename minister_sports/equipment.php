<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Sports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_sports') {
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

// Handle form actions
$action = $_GET['action'] ?? '';
$equipment_id = $_GET['id'] ?? '';

// Get equipment categories from database
try {
    $stmt = $pdo->query("
        SELECT * FROM equipment_categories 
        WHERE parent_id IS NULL 
        ORDER BY sort_order, name
    ");
    $main_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all categories for dropdown
    $stmt = $pdo->query("
        SELECT ec.*, ecp.name as parent_name
        FROM equipment_categories ec
        LEFT JOIN equipment_categories ecp ON ec.parent_id = ecp.id
        ORDER BY ec.sort_order, ec.name
    ");
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $main_categories = [];
    $all_categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// Add new equipment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    try {
        $equipment_name = $_POST['equipment_name'];
        $category_id = $_POST['category_id'];
        $category_custom = $_POST['category_custom'] ?? '';
        $sport_type = $_POST['sport_type'] ?? '';
        $quantity = $_POST['quantity'];
        $equipment_condition = $_POST['equipment_condition'];
        $location = $_POST['location'] ?? '';
        $storage_location = $_POST['storage_location'] ?? '';
        $purchase_date = $_POST['purchase_date'] ?: null;
        $purchase_price = $_POST['purchase_price'] ?: 0;
        $brand = $_POST['brand'] ?? '';
        $model = $_POST['model'] ?? '';
        $serial_number = $_POST['serial_number'] ?? '';
        $warranty_until = $_POST['warranty_until'] ?: null;
        $maintenance_schedule = $_POST['maintenance_schedule'] ?? '';
        $last_maintenance = $_POST['last_maintenance'] ?: null;
        $next_maintenance = $_POST['next_maintenance'] ?: null;
        $notes = $_POST['notes'] ?? '';
        
        // Determine category
        $final_category = '';
        if ($category_id && $category_id !== 'custom') {
            // Get category name from database
            $stmt = $pdo->prepare("SELECT name FROM equipment_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            $final_category = $cat['name'] ?? '';
        } else {
            $final_category = $category_custom;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO sports_equipment 
            (equipment_name, category_id, category_custom, sport_type, quantity, 
             available_quantity, equipment_condition, location, storage_location, purchase_date, 
             purchase_price, brand, model, serial_number, warranty_until, 
             maintenance_schedule, last_maintenance, next_maintenance, notes, 
             created_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
        ");
        $stmt->execute([
            $equipment_name, $category_id ?: null, $final_category, $sport_type, $quantity, $quantity,
            $equipment_condition, $location, $storage_location, $purchase_date, $purchase_price,
            $brand, $model, $serial_number, $warranty_until, $maintenance_schedule,
            $last_maintenance, $next_maintenance, $notes, $user_id
        ]);
        
        $_SESSION['success_message'] = "Equipment added successfully!";
        header('Location: equipment.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding equipment: " . $e->getMessage();
        error_log("Add equipment error: " . $e->getMessage());
    }
}

// Update equipment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    try {
        $equipment_name = $_POST['equipment_name'];
        $category_id = $_POST['category_id'];
        $category_custom = $_POST['category_custom'] ?? '';
        $sport_type = $_POST['sport_type'] ?? '';
        $quantity = $_POST['quantity'];
        $available_quantity = $_POST['available_quantity'];
        $equipment_condition = $_POST['equipment_condition'];
        $location = $_POST['location'] ?? '';
        $storage_location = $_POST['storage_location'] ?? '';
        $purchase_date = $_POST['purchase_date'] ?: null;
        $purchase_price = $_POST['purchase_price'] ?: 0;
        $brand = $_POST['brand'] ?? '';
        $model = $_POST['model'] ?? '';
        $serial_number = $_POST['serial_number'] ?? '';
        $warranty_until = $_POST['warranty_until'] ?: null;
        $maintenance_schedule = $_POST['maintenance_schedule'] ?? '';
        $last_maintenance = $_POST['last_maintenance'] ?: null;
        $next_maintenance = $_POST['next_maintenance'] ?: null;
        $notes = $_POST['notes'] ?? '';
        $status = $_POST['status'];
        
        // Determine category
        $final_category = '';
        if ($category_id && $category_id !== 'custom') {
            // Get category name from database
            $stmt = $pdo->prepare("SELECT name FROM equipment_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            $final_category = $cat['name'] ?? '';
        } else {
            $final_category = $category_custom;
        }
        
        $stmt = $pdo->prepare("
            UPDATE sports_equipment 
            SET equipment_name = ?, category_id = ?, category_custom = ?, sport_type = ?, 
                quantity = ?, available_quantity = ?, equipment_condition = ?, location = ?, 
                storage_location = ?, purchase_date = ?, purchase_price = ?, brand = ?, 
                model = ?, serial_number = ?, warranty_until = ?, maintenance_schedule = ?, 
                last_maintenance = ?, next_maintenance = ?, notes = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $equipment_name, $category_id ?: null, $final_category, $sport_type, $quantity, 
            $available_quantity, $equipment_condition, $location, $storage_location, $purchase_date, 
            $purchase_price, $brand, $model, $serial_number, $warranty_until, 
            $maintenance_schedule, $last_maintenance, $next_maintenance, $notes, $status, 
            $equipment_id
        ]);
        
        $_SESSION['success_message'] = "Equipment updated successfully!";
        header('Location: equipment.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating equipment: " . $e->getMessage();
        error_log("Update equipment error: " . $e->getMessage());
    }
}

// Delete equipment
if ($action === 'delete' && $equipment_id) {
    try {
        // First check if equipment has active assignments
        $stmt = $pdo->prepare("SELECT COUNT(*) as active_assignments FROM equipment_assignments WHERE equipment_id = ? AND status = 'active'");
        $stmt->execute([$equipment_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['active_assignments'] > 0) {
            $_SESSION['error_message'] = "Cannot delete equipment with active assignments. Please return all assigned items first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM sports_equipment WHERE id = ?");
            $stmt->execute([$equipment_id]);
            $_SESSION['success_message'] = "Equipment deleted successfully!";
        }
        
        header('Location: equipment.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting equipment: " . $e->getMessage();
        error_log("Delete equipment error: " . $e->getMessage());
    }
}

// Update equipment status
if ($action === 'update_status' && $equipment_id) {
    try {
        $status = $_GET['status'];
        $stmt = $pdo->prepare("UPDATE sports_equipment SET status = ? WHERE id = ?");
        $stmt->execute([$status, $equipment_id]);
        
        $_SESSION['success_message'] = "Equipment status updated successfully!";
        header('Location: equipment.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating equipment status: " . $e->getMessage();
    }
}

// Check out equipment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'checkout') {
    try {
        $checkout_quantity = $_POST['checkout_quantity'];
        $purpose = $_POST['purpose'];
        $expected_return = $_POST['expected_return'];
        $assigned_to_type = $_POST['assigned_to_type'] ?? 'individual';
        $assigned_to_id = $_POST['assigned_to_id'] ?? null;
        $assigned_to_name = $_POST['assigned_to_name'] ?? '';
        
        // Get current equipment
        $stmt = $pdo->prepare("SELECT * FROM sports_equipment WHERE id = ?");
        $stmt->execute([$equipment_id]);
        $equipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($equipment && $checkout_quantity <= $equipment['available_quantity']) {
            // Update available quantity
            $new_available = $equipment['available_quantity'] - $checkout_quantity;
            $stmt = $pdo->prepare("UPDATE sports_equipment SET available_quantity = ? WHERE id = ?");
            $stmt->execute([$new_available, $equipment_id]);
            
            // Record checkout transaction in assignments table
            $stmt = $pdo->prepare("
                INSERT INTO equipment_assignments 
                (equipment_id, assigned_to_type, assigned_to_id, quantity_assigned, 
                 assigned_by, assigned_date, expected_return_date, condition_when_assigned, 
                 purpose, status) 
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $equipment_id, $assigned_to_type, $assigned_to_id, $checkout_quantity,
                $user_id, $expected_return, $equipment['equipment_condition'], $purpose
            ]);
            
            $_SESSION['success_message'] = "Equipment checked out successfully!";
        } else {
            $_SESSION['error_message'] = "Not enough equipment available for checkout.";
        }
        
        header('Location: equipment.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error checking out equipment: " . $e->getMessage();
        error_log("Checkout equipment error: " . $e->getMessage());
    }
}

// Return equipment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'return') {
    try {
        $assignment_id = $_POST['assignment_id'];
        $condition_returned = $_POST['condition_returned'];
        $return_notes = $_POST['return_notes'] ?? '';
        
        // Get assignment details
        $stmt = $pdo->prepare("SELECT * FROM equipment_assignments WHERE id = ?");
        $stmt->execute([$assignment_id]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($assignment) {
            // Update available quantity
            $stmt = $pdo->prepare("
                UPDATE sports_equipment 
                SET available_quantity = available_quantity + ? 
                WHERE id = ?
            ");
            $stmt->execute([$assignment['quantity_assigned'], $assignment['equipment_id']]);
            
            // Update assignment status
            $stmt = $pdo->prepare("
                UPDATE equipment_assignments 
                SET status = 'returned', actual_return_date = CURDATE(), 
                    condition_when_returned = ?, return_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$condition_returned, $return_notes, $assignment_id]);
            
            $_SESSION['success_message'] = "Equipment returned successfully!";
        }
        
        header("Location: equipment.php?action=view&id=" . $assignment['equipment_id']);
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error returning equipment: " . $e->getMessage();
    }
}

// Get equipment data
try {
    // All equipment with usage stats and category names
    $stmt = $pdo->query("
        SELECT 
            se.*,
            ec.name as category_name,
            u.full_name as created_by_name,
            COUNT(ea.id) as total_assignments,
            SUM(CASE WHEN ea.status = 'active' THEN ea.quantity_assigned ELSE 0 END) as currently_assigned
        FROM sports_equipment se
        LEFT JOIN equipment_categories ec ON se.category_id = ec.id
        LEFT JOIN users u ON se.created_by = u.id
        LEFT JOIN equipment_assignments ea ON se.id = ea.equipment_id
        GROUP BY se.id
        ORDER BY se.equipment_name ASC
    ");
    $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sport types for dropdown
    $sport_types = [
        'Football', 'Basketball', 'Volleyball', 'Rugby', 'Tennis', 'Table Tennis',
        'Athletics', 'Swimming', 'Cricket', 'Hockey', 'Netball', 'Badminton',
        'Boxing', 'Martial Arts', 'General', 'Other'
    ];
    
    // Condition types
    $condition_types = ['excellent', 'good', 'fair', 'poor', 'needs_replacement'];
    
    // Status types
    $status_types = ['available', 'in_use', 'maintenance', 'retired', 'lost', 'damaged'];
    
    // Get equipment for editing
    if ($action === 'edit' && $equipment_id) {
        $stmt = $pdo->prepare("SELECT * FROM sports_equipment WHERE id = ?");
        $stmt->execute([$equipment_id]);
        $edit_equipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get category name if category_id exists
        if ($edit_equipment['category_id']) {
            $stmt = $pdo->prepare("SELECT name FROM equipment_categories WHERE id = ?");
            $stmt->execute([$edit_equipment['category_id']]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            $edit_equipment['category_name'] = $cat['name'] ?? '';
        }
    }
    
    // Get equipment for viewing
    if ($action === 'view' && $equipment_id) {
        $stmt = $pdo->prepare("
            SELECT se.*, ec.name as category_name, u.full_name as created_by_name
            FROM sports_equipment se
            LEFT JOIN equipment_categories ec ON se.category_id = ec.id
            LEFT JOIN users u ON se.created_by = u.id
            WHERE se.id = ?
        ");
        $stmt->execute([$equipment_id]);
        $current_equipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get assignment history
        $stmt = $pdo->prepare("
            SELECT ea.*, u.full_name as assigned_by_name,
                   CASE 
                       WHEN ea.assigned_to_type = 'team' THEN (SELECT team_name FROM sports_teams WHERE id = ea.assigned_to_id)
                       WHEN ea.assigned_to_type = 'individual' THEN (SELECT full_name FROM users WHERE id = ea.assigned_to_id)
                       ELSE 'Unknown'
                   END as assigned_to_name
            FROM equipment_assignments ea
            LEFT JOIN users u ON ea.assigned_by = u.id
            WHERE ea.equipment_id = ?
            ORDER BY ea.assigned_date DESC
            LIMIT 10
        ");
        $stmt->execute([$equipment_id]);
        $assignment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get maintenance history
        $stmt = $pdo->prepare("
            SELECT eml.*, u.full_name as performed_by_name
            FROM equipment_maintenance_log eml
            LEFT JOIN users u ON eml.created_by = u.id
            WHERE eml.equipment_id = ?
            ORDER BY eml.maintenance_date DESC
            LIMIT 10
        ");
        $stmt->execute([$equipment_id]);
        $maintenance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Statistics
    $total_equipment = count($equipment);
    $total_items = array_sum(array_column($equipment, 'quantity'));
    $available_items = array_sum(array_column($equipment, 'available_quantity'));
    $in_use_items = $total_items - $available_items;
    
    // Condition distribution
    $condition_distribution = [];
    foreach ($equipment as $item) {
        $condition = $item['equipment_condition'];
        if (!isset($condition_distribution[$condition])) {
            $condition_distribution[$condition] = 0;
        }
        $condition_distribution[$condition] += $item['quantity'];
    }
    
    // Maintenance alerts
    $maintenance_alerts = array_filter($equipment, function($item) {
        return $item['equipment_condition'] === 'poor' || $item['equipment_condition'] === 'needs_replacement' ||
               ($item['next_maintenance'] && strtotime($item['next_maintenance']) <= strtotime('+7 days'));
    });
    
    // Low stock alerts
    $low_stock_alerts = array_filter($equipment, function($item) {
        return $item['available_quantity'] < ($item['quantity'] * 0.2); // Less than 20% available
    });
    
    // Get teams and users for assignment
    $stmt = $pdo->query("SELECT id, team_name FROM sports_teams WHERE status = 'active' ORDER BY team_name");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT id, full_name, reg_number FROM users WHERE role = 'student' AND status = 'active' ORDER BY full_name");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Equipment data error: " . $e->getMessage());
    $equipment = [];
    $sport_types = $condition_types = $status_types = [];
    $teams = $students = [];
    $total_equipment = $total_items = $available_items = $in_use_items = 0;
    $condition_distribution = $maintenance_alerts = $low_stock_alerts = [];
}

// Unread messages count
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_messages 
        FROM conversation_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id
        WHERE cp.user_id = ? AND (cp.last_read_message_id IS NULL OR cm.id > cp.last_read_message_id)
    ");
    $stmt->execute([$user_id]);
    $unread_messages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_messages'] ?? 0;
} catch (PDOException $e) {
    $unread_messages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sports Equipment Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
/* ===== CSS RESET & BASE STYLES ===== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    /* Primary Colors */
    --primary-blue: #0056b3;
    --secondary-blue: #1e88e5;
    --accent-blue: #0d47a1;
    --light-blue: #e3f2fd;
    
    /* Neutral Colors */
    --white: #ffffff;
    --light-gray: #f8f9fa;
    --medium-gray: #e9ecef;
    --dark-gray: #6c757d;
    --text-dark: #2c3e50;
    
    /* Status Colors */
    --success: #28a745;
    --warning: #ffc107;
    --danger: #dc3545;
    
    /* Gradients & Shadows */
    --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.12);
    --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
    
    /* Border Radius */
    --border-radius: 8px;
    --border-radius-lg: 12px;
    
    /* Transitions */
    --transition: all 0.2s ease;
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

/* ===== LAYOUT COMPONENTS ===== */

/* Header */
.header {
    background: var(--white);
    box-shadow: var(--shadow-sm);
    padding: 1rem 0;
    position: sticky;
    top: 0;
    z-index: 100;
    border-bottom: 1px solid var(--medium-gray);
    height: 80px;
    display: flex;
    align-items: center;
}

.nav-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 1.5rem;
    width: 100%;
}

.logo-section {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.logos {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

.logo {
    height: 40px;
    width: auto;
}

.brand-text h1 {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--primary-blue);
}

.user-menu {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.user-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: var(--gradient-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 1.1rem;
    border: 3px solid var(--medium-gray);
    overflow: hidden;
    position: relative;
    transition: var(--transition);
}

.user-avatar:hover {
    border-color: var(--primary-blue);
    transform: scale(1.05);
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-details {
    text-align: right;
}

.user-name {
    font-weight: 600;
    color: var(--text-dark);
    font-size: 0.95rem;
}

.user-role {
    font-size: 0.8rem;
    color: var(--dark-gray);
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

/* Dashboard Layout */
.dashboard-container {
    display: grid;
    grid-template-columns: 220px 1fr;
    min-height: calc(100vh - 80px);
}

.sidebar {
    background: var(--white);
    border-right: 1px solid var(--medium-gray);
    padding: 1.5rem 0;
    position: sticky;
    top: 80px;
    height: calc(100vh - 80px);
    overflow-y: auto;
}

.main-content {
    padding: 1.5rem;
    overflow-y: auto;
    height: calc(100vh - 80px);
}

/* ===== UI COMPONENTS ===== */

/* Buttons */
.btn {
    padding: 0.6rem 1.2rem;
    border-radius: 6px;
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

.btn-warning {
    background: var(--warning);
    color: var(--text-dark);
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--primary-blue);
    color: var(--primary-blue);
}

.btn-outline:hover {
    background: var(--primary-blue);
    color: white;
}

.icon-btn {
    width: 44px;
    height: 44px;
    border: none;
    background: var(--light-gray);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-dark);
    cursor: pointer;
    transition: var(--transition);
    position: relative;
    font-size: 1.1rem;
}

.icon-btn:hover {
    background: var(--primary-blue);
    color: white;
    transform: translateY(-2px);
}

.logout-btn {
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
}

.logout-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* Cards */
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

.card-header-actions {
    display: flex;
    gap: 0.5rem;
}

.card-header-btn {
    background: none;
    border: none;
    color: var(--dark-gray);
    cursor: pointer;
    padding: 0.25rem;
    border-radius: 4px;
    transition: var(--transition);
}

.card-header-btn:hover {
    background: var(--light-gray);
    color: var(--text-dark);
}

.card-body {
    padding: 1.25rem;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--white);
    padding: 1rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    border-left: 3px solid var(--primary-blue);
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
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.stat-card .stat-icon {
    background: var(--light-blue);
    color: var(--primary-blue);
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
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
    color: var(--text-dark);
}

.stat-label {
    color: var(--dark-gray);
    font-size: 0.8rem;
    font-weight: 500;
}

/* ===== NAVIGATION ===== */

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

.menu-item a:hover, 
.menu-item a.active {
    background: var(--light-blue);
    border-left-color: var(--primary-blue);
    color: var(--primary-blue);
}

.menu-item i {
    width: 16px;
    text-align: center;
    font-size: 0.9rem;
}

.menu-badge,
.notification-badge {
    background: var(--danger);
    color: white;
    border-radius: 10px;
    padding: 0.1rem 0.4rem;
    font-size: 0.7rem;
    font-weight: 600;
    margin-left: auto;
}

.notification-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 20px;
    height: 20px;
    border: 2px solid var(--white);
}

/* ===== CONTENT SECTIONS ===== */

.page-header {
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
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

.page-actions {
    display: flex;
    gap: 0.75rem;
}

/* ===== TABLES ===== */

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.table th, 
.table td {
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

.table tr:hover {
    background: var(--light-gray);
}

/* ===== STATUS BADGES ===== */

.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-available {
    background: #d4edda;
    color: var(--success);
}

.status-maintenance {
    background: #fff3cd;
    color: var(--warning);
}

.status-occupied {
    background: #f8d7da;
    color: var(--danger);
}

.status-closed {
    background: #e9ecef;
    color: var(--dark-gray);
}

/* ===== ACTION BUTTONS ===== */

.action-buttons {
    display: flex;
    gap: 0.25rem;
}

.action-btn {
    padding: 0.4rem 0.75rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.7rem;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-weight: 500;
}

.action-btn.view {
    background: var(--light-blue);
    color: var(--primary-blue);
}

.action-btn.edit {
    background: #fff3cd;
    color: var(--warning);
}

.action-btn.delete {
    background: #f8d7da;
    color: var(--danger);
}

.action-btn.status {
    background: #d4edda;
    color: var(--success);
}

.action-btn.checkout {
    background: var(--success);
    color: white;
}

.action-btn:hover {
    transform: translateY(-1px);
}

/* ===== EQUIPMENT SPECIFIC STYLES ===== */

.equipment-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.equipment-card {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: var(--transition);
    border-left: 4px solid var(--primary-blue);
}

.equipment-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.equipment-card.low-stock {
    border-left-color: var(--warning);
}

.equipment-card.maintenance-needed {
    border-left-color: var(--danger);
}

.equipment-card.excellent {
    border-left-color: var(--success);
}

.equipment-header {
    padding: 1rem;
    border-bottom: 1px solid var(--medium-gray);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.equipment-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 0.25rem;
}

.equipment-category {
    font-size: 0.8rem;
    color: var(--dark-gray);
    background: var(--light-blue);
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    display: inline-block;
}

.equipment-condition {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    text-transform: capitalize;
}

.condition-excellent { background: #d4edda; color: var(--success); }
.condition-good { background: #d1ecf1; color: #0c5460; }
.condition-fair { background: #fff3cd; color: var(--warning); }
.condition-poor { background: #f8d7da; color: var(--danger); }
.condition-needs_replacement { background: #721c24; color: white; }

.equipment-body {
    padding: 1rem;
}

.equipment-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.stat-item {
    text-align: center;
    padding: 0.75rem;
    background: var(--light-gray);
    border-radius: var(--border-radius);
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-blue);
    margin-bottom: 0.25rem;
}

.equipment-info {
    display: grid;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--text-dark);
}

.info-item i {
    width: 16px;
    color: var(--dark-gray);
}

.equipment-footer {
    padding: 1rem;
    border-top: 1px solid var(--medium-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.equipment-actions {
    display: flex;
    gap: 0.5rem;
}

/* ===== FORMS ===== */

.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--text-dark);
    font-size: 0.85rem;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--medium-gray);
    border-radius: var(--border-radius);
    background: var(--white);
    color: var(--text-dark);
    font-size: 0.85rem;
    transition: var(--transition);
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
}

.form-select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--medium-gray);
    border-radius: var(--border-radius);
    background: var(--white);
    color: var(--text-dark);
    font-size: 0.85rem;
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* ===== ALERTS ===== */

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

/* ===== MODALS ===== */

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-lg);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--medium-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-dark);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.2rem;
    color: var(--dark-gray);
    cursor: pointer;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--medium-gray);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

/* ===== TABS COMPONENT ===== */
.tabs {
    margin-bottom: 1.5rem;
}

.tab-buttons {
    display: flex;
    border-bottom: 1px solid var(--medium-gray);
    background: var(--white);
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    overflow-x: auto;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE and Edge */
}

.tab-buttons::-webkit-scrollbar {
    display: none; /* Chrome, Safari and Opera */
}

.tab-button {
    padding: 0.75rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--dark-gray);
    cursor: pointer;
    transition: var(--transition);
    font-weight: 500;
    font-size: 0.85rem;
    white-space: nowrap;
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tab-button:hover {
    color: var(--primary-blue);
    background: var(--light-blue);
}

.tab-button.active {
    color: var(--primary-blue);
    border-bottom-color: var(--primary-blue);
    background: var(--light-blue);
}

.tab-button.active::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--primary-blue);
}

.tab-button i {
    font-size: 0.8rem;
}

.tab-button .tab-badge {
    background: var(--primary-blue);
    color: white;
    border-radius: 10px;
    padding: 0.1rem 0.4rem;
    font-size: 0.7rem;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
}

.tab-button.warning .tab-badge {
    background: var(--warning);
    color: var(--text-dark);
}

.tab-button.danger .tab-badge {
    background: var(--danger);
    color: white;
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease-in-out;
}

.tab-content.active {
    display: block;
}

/* Tab content animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Tab states for different contexts */
.tab-button[data-count]:not([data-count="0"])::after {
    content: attr(data-count);
    background: var(--danger);
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 0.5rem;
}

.tab-button.warning[data-count]:not([data-count="0"])::after {
    background: var(--warning);
    color: var(--text-dark);
}

/* Responsive tabs */
@media (max-width: 768px) {
    .tab-buttons {
        border-radius: 0;
        margin: 0 -1rem;
        padding: 0 1rem;
    }
    
    .tab-button {
        padding: 0.75rem 1rem;
        font-size: 0.8rem;
        flex: 1;
        justify-content: center;
        min-width: auto;
    }
    
    .tab-button i {
        display: none;
    }
}

@media (max-width: 480px) {
    .tab-button {
        padding: 0.6rem 0.75rem;
        font-size: 0.75rem;
    }
    
    .tab-button .tab-badge {
        display: none;
    }
}

/* Alternative tab styles */
.tabs.outline .tab-buttons {
    background: transparent;
    border: 1px solid var(--medium-gray);
    border-radius: var(--border-radius);
    border-bottom: none;
    padding: 0.5rem;
}

.tabs.outline .tab-button {
    border-radius: var(--border-radius);
    border-bottom: none;
    flex: 1;
    justify-content: center;
}

.tabs.outline .tab-button.active {
    background: var(--primary-blue);
    color: white;
    border-bottom: none;
}

.tabs.pills .tab-buttons {
    border: none;
    background: var(--light-gray);
    border-radius: 50px;
    padding: 0.25rem;
    gap: 0.25rem;
}

.tabs.pills .tab-button {
    border-radius: 50px;
    border: none;
    flex: 1;
    justify-content: center;
}

.tabs.pills .tab-button.active {
    background: var(--white);
    color: var(--primary-blue);
    box-shadow: var(--shadow-sm);
    border: none;
}

/* ===== UTILITY CLASSES ===== */

/* Stock Progress */
.stock-progress {
    margin-top: 0.5rem;
}

.progress-bar {
    height: 6px;
    background: var(--medium-gray);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 0.25rem;
}

.progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.progress-low { background: var(--danger); }
.progress-medium { background: var(--warning); }
.progress-high { background: var(--success); }

.progress-text {
    font-size: 0.7rem;
    color: var(--dark-gray);
    display: flex;
    justify-content: space-between;
}

/* Maintenance Info */
.maintenance-info {
    background: #fff3cd;
    border: 1px solid var(--warning);
    border-radius: var(--border-radius);
    padding: 0.75rem;
    margin-bottom: 1rem;
}

.maintenance-info.warning {
    background: #f8d7da;
    border-color: var(--danger);
}

.maintenance-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 0.25rem;
}

.maintenance-date {
    font-size: 0.7rem;
    color: var(--dark-gray);
}

/* Alerts Section */
.alerts-section {
    margin-bottom: 1.5rem;
}

.alert-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.alert-card {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    padding: 1rem;
    border-left: 4px solid;
    transition: var(--transition);
}

.alert-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.alert-card.warning {
    border-left-color: var(--warning);
}

.alert-card.danger {
    border-left-color: var(--danger);
}

.alert-title {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-description {
    font-size: 0.8rem;
    color: var(--dark-gray);
    margin-bottom: 0.75rem;
}

.alert-actions {
    display: flex;
    gap: 0.5rem;
}

/* Condition Distribution */
.condition-distribution {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.condition-item {
    background: var(--white);
    padding: 1rem;
    border-radius: var(--border-radius);
    text-align: center;
    transition: var(--transition);
    border-left: 4px solid;
}

.condition-item:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.condition-item.excellent { border-left-color: var(--success); }
.condition-item.good { border-left-color: #17a2b8; }
.condition-item.fair { border-left-color: var(--warning); }
.condition-item.poor { border-left-color: var(--danger); }
.condition-item.needs_replacement { border-left-color: #721c24; }

.condition-name {
    font-size: 0.8rem;
    color: var(--dark-gray);
    margin-bottom: 0.5rem;
    text-transform: capitalize;
}

.condition-count {
    font-size: 1.5rem;
    font-weight: 700;
}

.condition-item.excellent .condition-count { color: var(--success); }
.condition-item.good .condition-count { color: #17a2b8; }
.condition-item.fair .condition-count { color: var(--warning); }
.condition-item.poor .condition-count { color: var(--danger); }
.condition-item.needs_replacement .condition-count { color: #721c24; }

/* Checkout Form */
.checkout-form .form-group {
    margin-bottom: 1rem;
}

.quantity-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.quantity-btn {
    width: 32px;
    height: 32px;
    border: 1px solid var(--medium-gray);
    background: var(--white);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}

.quantity-btn:hover {
    background: var(--light-gray);
}

.quantity-input {
    width: 60px;
    text-align: center;
    padding: 0.5rem;
    border: 1px solid var(--medium-gray);
    border-radius: 4px;
}

/* ===== RESPONSIVE DESIGN ===== */

@media (max-width: 1024px) {
    .dashboard-container {
        grid-template-columns: 200px 1fr;
    }
    
    .equipment-cards {
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    }
}

@media (max-width: 768px) {
    .dashboard-container {
        grid-template-columns: 1fr;
    }
    
    .sidebar {
        display: none;
    }
    
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .equipment-cards {
        grid-template-columns: 1fr;
    }
    
    .nav-container {
        padding: 0 1rem;
    }
    
    .user-details {
        display: none;
    }
    
    .tabs {
        overflow-x: auto;
    }
    
    .condition-distribution {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }
    
    .equipment-stats {
        grid-template-columns: 1fr;
    }
    
    .alert-cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .main-content {
        padding: 1rem;
    }
    
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
    
    .page-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.5rem;
    }
}
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <div class="brand-text">
                    <h1>Isonga - Minister of Sports</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                    <button class="icon-btn" id="themeToggle" title="Toggle Dark Mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <a href="messages.php" class="icon-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
                        <div class="user-role">Minister of Sports</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        
               <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" >
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="teams.php">
                        <i class="fas fa-users"></i>
                        <span>Sports Teams</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="facilities.php">
                        <i class="fas fa-building"></i>
                        <span>Sports Facilities</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-music"></i>
                        <span>Entertainment Clubs</span>

                    </a>
                </li>
                <li class="menu-item">
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="competitions.php">
                        <i class="fas fa-trophy"></i>
                        <span>Competitions</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="equipment.php" class="active">
                        <i class="fas fa-baseball-ball"></i>
                        <span>Equipment</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php" >
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Funding & Budget</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="training.php">
                        <i class="fas fa-running"></i>
                        <span>Training</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports & Analytics</span>
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
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Sports Equipment Management 🏀</h1>
                    <p>Manage sports equipment inventory, checkouts, and maintenance</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openModal('addEquipmentModal')">
                        <i class="fas fa-plus"></i> Add New Equipment
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_equipment; ?></div>
                        <div class="stat-label">Equipment Types</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_items; ?></div>
                        <div class="stat-label">Total Items</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $available_items; ?></div>
                        <div class="stat-label">Available Items</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-running"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $in_use_items; ?></div>
                        <div class="stat-label">In Use Items</div>
                    </div>
                </div>
            </div>

            <!-- Alerts Section -->
            <?php if (!empty($maintenance_alerts) || !empty($low_stock_alerts)): ?>
                <div class="alerts-section">
                    <h3 style="margin-bottom: 1rem; color: var(--text-dark);">⚠️ Important Alerts</h3>
                    <div class="alert-cards">
                        <?php if (!empty($maintenance_alerts)): ?>
                            <div class="alert-card danger">
                                <div class="alert-title">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Maintenance Required
                                </div>
                                <div class="alert-description">
                                    <?php echo count($maintenance_alerts); ?> equipment items need maintenance or replacement
                                </div>
                                <div class="alert-actions">
                                    <a href="equipment.php?view=maintenance" class="action-btn view">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($low_stock_alerts)): ?>
                            <div class="alert-card warning">
                                <div class="alert-title">
                                    <i class="fas fa-box"></i>
                                    Low Stock Alert
                                </div>
                                <div class="alert-description">
                                    <?php echo count($low_stock_alerts); ?> equipment items are running low on stock
                                </div>
                                <div class="alert-actions">
                                    <a href="equipment.php?view=low_stock" class="action-btn view">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="openTab('all-equipment')">
                        <i class="fas fa-list"></i>
                        All Equipment
                        <span class="tab-badge"><?php echo $total_equipment; ?></span>
                    </button>
                    <button class="tab-button" onclick="openTab('condition-overview')">
                        <i class="fas fa-chart-pie"></i>
                        Condition Overview
                    </button>
                    <button class="tab-button warning" onclick="openTab('maintenance')">
                        <i class="fas fa-tools"></i>
                        Maintenance
                        <?php if (!empty($maintenance_alerts)): ?>
                            <span class="tab-badge"><?php echo count($maintenance_alerts); ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            </div>

            <!-- All Equipment Tab -->
            <div id="all-equipment" class="tab-content active">
                <?php if (empty($equipment)): ?>
                    <div class="card">
                        <div class="card-body" style="text-align: center; padding: 3rem;">
                            <i class="fas fa-baseball-ball" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No Equipment Found</h3>
                            <p>Get started by adding your first sports equipment.</p>
                            <button class="btn btn-primary" onclick="openModal('addEquipmentModal')" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Add First Equipment
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="equipment-cards">
                        <?php foreach ($equipment as $item): ?>
                            <?php
                            $card_class = 'equipment-card';
                            $stock_percentage = $item['quantity'] > 0 ? ($item['available_quantity'] / $item['quantity']) * 100 : 0;
                            
                            if ($stock_percentage < 20) {
                                $card_class .= ' low-stock';
                            }
                            if ($item['equipment_condition'] === 'poor' || $item['equipment_condition'] === 'needs_replacement') {
                                $card_class .= ' maintenance-needed';
                            }
                            if ($item['equipment_condition'] === 'excellent') {
                                $card_class .= ' excellent';
                            }
                            ?>
                            
                            <div class="<?php echo $card_class; ?>">
                                <div class="equipment-header">
                                    <div>
                                        <div class="equipment-title"><?php echo htmlspecialchars($item['equipment_name']); ?></div>
                                        <span class="equipment-category">
                                            <?php echo htmlspecialchars($item['category_name'] ?? $item['category_custom'] ?? 'Uncategorized'); ?>
                                            <?php if (!empty($item['sport_type'])): ?>
                                                <small style="font-size: 0.7rem; color: var(--dark-gray); margin-left: 0.5rem;">
                                                    (<?php echo htmlspecialchars($item['sport_type']); ?>)
                                                </small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <span class="equipment-condition condition-<?php echo $item['equipment_condition']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $item['equipment_condition'])); ?>
                                    </span>
                                </div>
                                
                                <div class="equipment-body">
                                    <div class="equipment-stats">
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $item['quantity']; ?></div>
                                            <div class="stat-label">Total</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value" style="color: <?php echo $item['available_quantity'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                                <?php echo $item['available_quantity']; ?>
                                            </div>
                                            <div class="stat-label">Available</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value" style="color: var(--warning);">
                                                <?php echo $item['currently_assigned'] ?? 0; ?>
                                            </div>
                                            <div class="stat-label">Assigned</div>
                                        </div>
                                    </div>
                                    
                                    <div class="stock-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill 
                                                <?php echo $stock_percentage >= 50 ? 'progress-high' : 
                                                      ($stock_percentage >= 20 ? 'progress-medium' : 'progress-low'); ?>"
                                                style="width: <?php echo $stock_percentage; ?>%">
                                            </div>
                                        </div>
                                        <div class="progress-text">
                                            <span>Stock Level</span>
                                            <span><?php echo round($stock_percentage); ?>%</span>
                                        </div>
                                    </div>
                                    
                                    <div class="equipment-info">
                                        <?php if (!empty($item['brand'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-tag"></i>
                                            <span><?php echo htmlspecialchars($item['brand']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="info-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($item['storage_location'] ?? $item['location'] ?? 'Not specified'); ?></span>
                                        </div>
                                        
                                        <div class="info-item">
                                            <i class="fas fa-calendar"></i>
                                            <span>Added: <?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                                        </div>
                                        
                                        <?php if ($item['purchase_date']): ?>
                                        <div class="info-item">
                                            <i class="fas fa-shopping-cart"></i>
                                            <span>Purchased: <?php echo date('M j, Y', strtotime($item['purchase_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($item['next_maintenance'] && strtotime($item['next_maintenance']) <= strtotime('+30 days')): ?>
                                        <div class="maintenance-info <?php echo strtotime($item['next_maintenance']) <= strtotime('+7 days') ? 'warning' : ''; ?>">
                                            <div class="maintenance-label">
                                                <i class="fas fa-tools"></i>
                                                Next Maintenance
                                            </div>
                                            <div class="maintenance-date">
                                                <?php echo date('M j, Y', strtotime($item['next_maintenance'])); ?>
                                                <?php if (strtotime($item['next_maintenance']) <= strtotime('+7 days')): ?>
                                                    <strong style="color: var(--danger);"> - Due Soon!</strong>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['notes']): ?>
                                        <div style="font-size: 0.8rem; color: var(--dark-gray); margin-top: 0.5rem;">
                                            <strong>Notes:</strong> <?php echo htmlspecialchars(substr($item['notes'], 0, 100)); ?>
                                            <?php if (strlen($item['notes']) > 100): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="equipment-footer">
                                    <div class="equipment-status">
                                        <span class="status-badge status-<?php echo $item['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $item['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="equipment-actions">
                                        <?php if ($item['available_quantity'] > 0 && $item['status'] === 'available'): ?>
                                            <button class="action-btn checkout" onclick="openCheckoutModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['equipment_name']); ?>', <?php echo $item['available_quantity']; ?>)">
                                                <i class="fas fa-sign-out-alt"></i> Checkout
                                            </button>
                                        <?php endif; ?>
                                        <a href="equipment.php?action=view&id=<?php echo $item['id']; ?>" class="action-btn view">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="equipment.php?action=edit&id=<?php echo $item['id']; ?>" class="action-btn edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn delete" onclick="confirmDelete(<?php echo $item['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Condition Overview Tab -->
            <div id="condition-overview" class="tab-content">
                <!-- ... Keep this section the same ... -->
            </div>

            <!-- Maintenance Tab -->
            <div id="maintenance" class="tab-content">
                <!-- ... Keep this section the same ... -->
            </div>
        </main>
    </div>

    <!-- Add Equipment Modal - UPDATED -->
    <div class="modal" id="addEquipmentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Equipment</h3>
                <button class="modal-close" onclick="closeModal('addEquipmentModal')">&times;</button>
            </div>
            <form method="POST" action="equipment.php?action=add">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="equipment_name">Equipment Name *</label>
                            <input type="text" class="form-control" id="equipment_name" name="equipment_name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="category_id">Category *</label>
                            <select class="form-select" id="category_id" name="category_id" required onchange="toggleCustomCategory()">
                                <option value="">Select Category</option>
                                <?php foreach ($all_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php 
                                        echo htmlspecialchars($category['name']);
                                        if ($category['parent_name']) {
                                            echo " (" . htmlspecialchars($category['parent_name']) . ")";
                                        }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom">Custom Category</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" id="customCategoryGroup" style="display: none;">
                        <label class="form-label" for="category_custom">Custom Category Name</label>
                        <input type="text" class="form-control" id="category_custom" name="category_custom" placeholder="Enter custom category name">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="sport_type">Sport Type</label>
                            <select class="form-select" id="sport_type" name="sport_type">
                                <option value="">Select Sport Type</option>
                                <?php foreach ($sport_types as $sport): ?>
                                    <option value="<?php echo $sport; ?>"><?php echo $sport; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="quantity">Quantity *</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="condition">Condition *</label>
                            <select class="form-select" id="equipment_condition" name="equipment_condition" required>
                                <?php foreach ($condition_types as $condition): ?>
                                    <option value="<?php echo $condition; ?>"><?php echo ucfirst(str_replace('_', ' ', $condition)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="status">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <?php foreach ($status_types as $status): ?>
                                    <option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="brand">Brand</label>
                            <input type="text" class="form-control" id="brand" name="brand" placeholder="e.g., Nike, Adidas">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="model">Model</label>
                            <input type="text" class="form-control" id="model" name="model" placeholder="e.g., Pro 2000">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="serial_number">Serial Number</label>
                            <input type="text" class="form-control" id="serial_number" name="serial_number">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="storage_location">Storage Location</label>
                            <input type="text" class="form-control" id="storage_location" name="storage_location" placeholder="e.g., Sports Store Room A">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="purchase_date">Purchase Date</label>
                            <input type="date" class="form-control" id="purchase_date" name="purchase_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="purchase_price">Purchase Price (RWF)</label>
                            <input type="number" class="form-control" id="purchase_price" name="purchase_price" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="warranty_until">Warranty Until</label>
                            <input type="date" class="form-control" id="warranty_until" name="warranty_until">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="maintenance_schedule">Maintenance Schedule</label>
                            <input type="text" class="form-control" id="maintenance_schedule" name="maintenance_schedule" placeholder="e.g., Every 3 months">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="last_maintenance">Last Maintenance Date</label>
                            <input type="date" class="form-control" id="last_maintenance" name="last_maintenance">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="next_maintenance">Next Maintenance Date</label>
                            <input type="date" class="form-control" id="next_maintenance" name="next_maintenance">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control form-textarea" id="notes" name="notes" placeholder="Any additional notes about this equipment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addEquipmentModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Equipment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Checkout Modal - UPDATED -->
    <div class="modal" id="checkoutModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Checkout Equipment</h3>
                <button class="modal-close" onclick="closeModal('checkoutModal')">&times;</button>
            </div>
            <form method="POST" action="equipment.php?action=checkout" id="checkoutForm">
                <input type="hidden" name="equipment_id" id="checkout_equipment_id">
                <div class="modal-body checkout-form">
                    <div class="form-group">
                        <label class="form-label">Equipment</label>
                        <div class="form-control" id="checkout_equipment_name" style="background: var(--light-gray);"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="checkout_quantity">Quantity *</label>
                        <div class="quantity-controls">
                            <button type="button" class="quantity-btn" onclick="adjustQuantity(-1)">-</button>
                            <input type="number" class="quantity-input" id="checkout_quantity" name="checkout_quantity" value="1" min="1" max="1" required>
                            <button type="button" class="quantity-btn" onclick="adjustQuantity(1)">+</button>
                        </div>
                        <small style="color: var(--dark-gray);">Available: <span id="available_quantity">0</span> items</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="assigned_to_type">Assign To *</label>
                        <select class="form-select" id="assigned_to_type" name="assigned_to_type" onchange="toggleAssigneeField()" required>
                            <option value="team">Team</option>
                            <option value="individual">Student/Individual</option>
                            <option value="facility">Facility</option>
                            <option value="department">Department</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="teamField" style="display: block;">
                        <label class="form-label" for="assigned_to_id">Select Team *</label>
                        <select class="form-select" id="assigned_to_id" name="assigned_to_id">
                            <option value="">Select Team</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?php echo $team['id']; ?>"><?php echo htmlspecialchars($team['team_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="individualField" style="display: none;">
                        <label class="form-label" for="assigned_to_id_individual">Select Student *</label>
                        <select class="form-select" id="assigned_to_id_individual" name="assigned_to_id">
                            <option value="">Select Student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['full_name']); ?> 
                                    (<?php echo htmlspecialchars($student['reg_number']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="purpose">Purpose *</label>
                        <input type="text" class="form-control" id="purpose" name="purpose" placeholder="e.g., Team training session" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="expected_return">Expected Return Date *</label>
                        <input type="date" class="form-control" id="expected_return" name="expected_return" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('checkoutModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Checkout Equipment</button>
                </div>
            </form>
        </div>
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

        // Tab functionality
        function openTab(tabName) {
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }

            const tabButtons = document.getElementsByClassName('tab-button');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }

            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
            
            history.pushState(null, null, `#${tabName}`);
        }

        // Initialize tabs on page load
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById(hash)) {
                const tabButton = document.querySelector(`[onclick="openTab('${hash}')"]`);
                if (tabButton) {
                    tabButton.click();
                }
            }
        });

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Toggle custom category field
        function toggleCustomCategory() {
            const categorySelect = document.getElementById('category_id');
            const customCategoryGroup = document.getElementById('customCategoryGroup');
            
            if (categorySelect.value === 'custom') {
                customCategoryGroup.style.display = 'block';
                document.getElementById('category_custom').required = true;
            } else {
                customCategoryGroup.style.display = 'none';
                document.getElementById('category_custom').required = false;
            }
        }

        // Toggle assignee fields based on type
        function toggleAssigneeField() {
            const assignType = document.getElementById('assigned_to_type').value;
            const teamField = document.getElementById('teamField');
            const individualField = document.getElementById('individualField');
            
            teamField.style.display = 'none';
            individualField.style.display = 'none';
            
            if (assignType === 'team') {
                teamField.style.display = 'block';
                document.getElementById('assigned_to_id').required = true;
                document.getElementById('assigned_to_id_individual').required = false;
            } else if (assignType === 'individual') {
                individualField.style.display = 'block';
                document.getElementById('assigned_to_id').required = false;
                document.getElementById('assigned_to_id_individual').required = true;
            } else {
                // For facility or department, we might need different handling
                document.getElementById('assigned_to_id').required = false;
                document.getElementById('assigned_to_id_individual').required = false;
            }
        }

        // Checkout Modal
        let maxQuantity = 1;
        
        function openCheckoutModal(equipmentId, equipmentName, availableQuantity) {
            document.getElementById('checkout_equipment_id').value = equipmentId;
            document.getElementById('checkout_equipment_name').textContent = equipmentName;
            document.getElementById('checkout_quantity').value = 1;
            document.getElementById('checkout_quantity').max = availableQuantity;
            document.getElementById('available_quantity').textContent = availableQuantity;
            maxQuantity = availableQuantity;
            
            // Set minimum return date to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('expected_return').min = tomorrow.toISOString().split('T')[0];
            
            // Reset form fields
            document.getElementById('assigned_to_type').value = 'team';
            toggleAssigneeField();
            
            openModal('checkoutModal');
        }

        function adjustQuantity(change) {
            const quantityInput = document.getElementById('checkout_quantity');
            let newQuantity = parseInt(quantityInput.value) + change;
            
            if (newQuantity >= 1 && newQuantity <= maxQuantity) {
                quantityInput.value = newQuantity;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // Delete Confirmation
        function confirmDelete(equipmentId) {
            if (confirm('Are you sure you want to delete this equipment? This action cannot be undone.')) {
                window.location.href = 'equipment.php?action=delete&id=' + equipmentId;
            }
        }

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Set minimum dates for date fields
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const lastMaintenance = document.getElementById('last_maintenance');
            const nextMaintenance = document.getElementById('next_maintenance');
            const warrantyUntil = document.getElementById('warranty_until');
            
            if (lastMaintenance) {
                lastMaintenance.max = today;
            }
            if (nextMaintenance) {
                nextMaintenance.min = today;
            }
            if (warrantyUntil) {
                warrantyUntil.min = today;
            }
        });
    </script>
</body>
</html>