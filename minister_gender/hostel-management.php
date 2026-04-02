<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Minister of Gender
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'minister_gender') {
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

// Get unread messages count for badge
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

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_hostel':
                $name = $_POST['name'];
                $gender = $_POST['gender'];
                $location = $_POST['location'];
                $capacity = $_POST['capacity'];
                $description = $_POST['description'];
                $warden_name = $_POST['warden_name'];
                $warden_contact = $_POST['warden_contact'];
                $amenities = json_encode($_POST['amenities'] ?? []);
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO hostels 
                        (name, gender, location, capacity, available_beds, description, amenities, warden_name, warden_contact, status, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$name, $gender, $location, $capacity, $capacity, $description, $amenities, $warden_name, $warden_contact, $user_id]);
                    
                    $_SESSION['success_message'] = "Hostel added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding hostel: " . $e->getMessage();
                }
                break;
                
            case 'edit_hostel':
                $hostel_id = $_POST['hostel_id'];
                $name = $_POST['name'];
                $gender = $_POST['gender'];
                $location = $_POST['location'];
                $capacity = $_POST['capacity'];
                $description = $_POST['description'];
                $warden_name = $_POST['warden_name'];
                $warden_contact = $_POST['warden_contact'];
                $status = $_POST['status'];
                $amenities = json_encode($_POST['amenities'] ?? []);
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE hostels 
                        SET name = ?, gender = ?, location = ?, capacity = ?, description = ?, 
                            amenities = ?, warden_name = ?, warden_contact = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $gender, $location, $capacity, $description, $amenities, $warden_name, $warden_contact, $status, $hostel_id]);
                    
                    $_SESSION['success_message'] = "Hostel updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating hostel: " . $e->getMessage();
                }
                break;
                
            case 'delete_hostel':
                $hostel_id = $_POST['hostel_id'];
                
                try {
                    // Check if hostel has active allocations
                    $stmt = $pdo->prepare("SELECT COUNT(*) as active_allocations FROM hostel_allocations WHERE hostel_id = ? AND status IN ('allocated', 'checked_in')");
                    $stmt->execute([$hostel_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result['active_allocations'] > 0) {
                        $_SESSION['error_message'] = "Cannot delete hostel with active student allocations. Please reassign or check out students first.";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM hostels WHERE id = ?");
                        $stmt->execute([$hostel_id]);
                        
                        $_SESSION['success_message'] = "Hostel deleted successfully!";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting hostel: " . $e->getMessage();
                }
                break;
                
            case 'add_room':
                $hostel_id = $_POST['hostel_id'];
                $room_number = $_POST['room_number'];
                $floor = $_POST['floor'];
                $capacity = $_POST['capacity'];
                $room_type = $_POST['room_type'];
                $amenities = json_encode($_POST['amenities'] ?? []);
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO hostel_rooms 
                        (hostel_id, room_number, floor, capacity, available_beds, room_type, amenities, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'available', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$hostel_id, $room_number, $floor, $capacity, $capacity, $room_type, $amenities]);
                    
                    // Update hostel available beds
                    $stmt = $pdo->prepare("UPDATE hostels SET available_beds = available_beds + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$capacity, $hostel_id]);
                    
                    $_SESSION['success_message'] = "Room added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error adding room: " . $e->getMessage();
                }
                break;
                
            case 'edit_room':
                $room_id = $_POST['room_id'];
                $room_number = $_POST['room_number'];
                $floor = $_POST['floor'];
                $capacity = $_POST['capacity'];
                $room_type = $_POST['room_type'];
                $status = $_POST['status'];
                $amenities = json_encode($_POST['amenities'] ?? []);
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE hostel_rooms 
                        SET room_number = ?, floor = ?, capacity = ?, room_type = ?, amenities = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$room_number, $floor, $capacity, $room_type, $amenities, $status, $room_id]);
                    
                    $_SESSION['success_message'] = "Room updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating room: " . $e->getMessage();
                }
                break;
                
            case 'delete_room':
                $room_id = $_POST['room_id'];
                
                try {
                    // Get room info before deletion
                    $stmt = $pdo->prepare("SELECT hostel_id, capacity, available_beds FROM hostel_rooms WHERE id = ?");
                    $stmt->execute([$room_id]);
                    $room = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($room) {
                        // Check if room has active allocations
                        $stmt = $pdo->prepare("SELECT COUNT(*) as active_allocations FROM hostel_allocations WHERE room_id = ? AND status IN ('allocated', 'checked_in')");
                        $stmt->execute([$room_id]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($result['active_allocations'] > 0) {
                            $_SESSION['error_message'] = "Cannot delete room with active student allocations. Please reassign students first.";
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM hostel_rooms WHERE id = ?");
                            $stmt->execute([$room_id]);
                            
                            // Update hostel available beds
                            $stmt = $pdo->prepare("UPDATE hostels SET available_beds = available_beds - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->execute([$room['capacity'], $room['hostel_id']]);
                            
                            $_SESSION['success_message'] = "Room deleted successfully!";
                        }
                    } else {
                        $_SESSION['error_message'] = "Room not found.";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting room: " . $e->getMessage();
                }
                break;
                
            case 'allocate_hostel':
                $student_id = $_POST['student_id'];
                $hostel_id = $_POST['hostel_id'];
                $room_id = $_POST['room_id'];
                $bed_number = $_POST['bed_number'] ?: null;
                $academic_year = $_POST['academic_year'];
                $allocation_reason = $_POST['allocation_reason'];
                
                try {
                    // Check if student already has allocation for this academic year
                    $stmt = $pdo->prepare("SELECT id FROM hostel_allocations WHERE student_id = ? AND academic_year = ? AND status != 'checked_out'");
                    $stmt->execute([$student_id, $academic_year]);
                    
                    if ($stmt->fetch()) {
                        $_SESSION['error_message'] = "Student already has a hostel allocation for this academic year.";
                    } else {
                        // Create allocation
                        $stmt = $pdo->prepare("
                            INSERT INTO hostel_allocations 
                            (student_id, hostel_id, room_id, bed_number, academic_year, allocation_date, allocation_reason, allocated_by, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, ?, 'allocated', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$student_id, $hostel_id, $room_id, $bed_number, $academic_year, $allocation_reason, $user_id]);
                        
                        // Update room available beds
                        $stmt = $pdo->prepare("UPDATE hostel_rooms SET available_beds = available_beds - 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$room_id]);
                        
                        // Update hostel available beds
                        $stmt = $pdo->prepare("UPDATE hostels SET available_beds = available_beds - 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$hostel_id]);
                        
                        $_SESSION['success_message'] = "Hostel allocated successfully!";
                    }
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error allocating hostel: " . $e->getMessage();
                }
                break;
                
            case 'update_allocation_status':
                $allocation_id = $_POST['allocation_id'];
                $status = $_POST['status'];
                
                try {
                    if ($status === 'checked_out') {
                        // Get allocation details before updating
                        $stmt = $pdo->prepare("SELECT room_id, hostel_id FROM hostel_allocations WHERE id = ?");
                        $stmt->execute([$allocation_id]);
                        $allocation = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($allocation) {
                            $stmt = $pdo->prepare("
                                UPDATE hostel_allocations 
                                SET status = ?, check_out_date = CURRENT_DATE, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $stmt->execute([$status, $allocation_id]);
                            
                            // Update room available beds
                            $stmt = $pdo->prepare("UPDATE hostel_rooms SET available_beds = available_beds + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->execute([$allocation['room_id']]);
                            
                            // Update hostel available beds
                            $stmt = $pdo->prepare("UPDATE hostels SET available_beds = available_beds + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->execute([$allocation['hostel_id']]);
                        }
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE hostel_allocations 
                            SET status = ?, 
                                check_in_date = CASE WHEN ? = 'checked_in' THEN CURRENT_DATE ELSE check_in_date END,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$status, $status, $allocation_id]);
                    }
                    
                    $_SESSION['success_message'] = "Allocation status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating allocation: " . $e->getMessage();
                }
                break;
                
            case 'add_maintenance':
                $hostel_id = $_POST['hostel_id'];
                $room_id = $_POST['room_id'] ?? null;
                $title = $_POST['title'];
                $description = $_POST['description'];
                $issue_type = $_POST['issue_type'];
                $priority = $_POST['priority'];
                $due_date = $_POST['due_date'] ?: null;
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO hostel_maintenance 
                        (hostel_id, room_id, title, description, issue_type, priority, reported_by, reporter_contact, due_date, reported_by_user, created_at, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'reported')
                    ");
                    $stmt->execute([
                        $hostel_id, 
                        $room_id, 
                        $title, 
                        $description, 
                        $issue_type, 
                        $priority, 
                        $user['full_name'] ?? $_SESSION['full_name'], 
                        $user['phone'] ?? '', 
                        $due_date, 
                        $user_id
                    ]);
                    
                    $_SESSION['success_message'] = "Maintenance request submitted successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error submitting maintenance request: " . $e->getMessage();
                }
                break;
                
            case 'update_maintenance_status':
                $maintenance_id = $_POST['maintenance_id'];
                $status = $_POST['status'];
                $completion_notes = $_POST['completion_notes'] ?? '';
                $actual_cost = $_POST['actual_cost'] ?? 0;
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE hostel_maintenance 
                        SET status = ?, completion_notes = ?, actual_cost = ?, 
                            completed_at = CASE WHEN ? = 'completed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                            completed_by = CASE WHEN ? = 'completed' THEN ? ELSE NULL END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$status, $completion_notes, $actual_cost, $status, $status, $user_id, $maintenance_id]);
                    
                    $_SESSION['success_message'] = "Maintenance status updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Error updating maintenance request: " . $e->getMessage();
                }
                break;
        }
        
        header("Location: hostel-management.php");
        exit();
    }
}

// Get filter parameters
$hostel_filter = $_GET['hostel'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$gender_filter = $_GET['gender'] ?? 'all';
$tab = $_GET['tab'] ?? 'overview';

// Get hostels data
try {
    // Hostels statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_hostels,
            COALESCE(SUM(capacity), 0) as total_capacity,
            COALESCE(SUM(available_beds), 0) as total_available,
            COALESCE(SUM(capacity - available_beds), 0) as total_occupied
        FROM hostels 
        WHERE status = 'active'
    ");
    $hostel_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Hostels list
    $query = "SELECT * FROM hostels WHERE 1=1";
    $params = [];
    
    if ($gender_filter !== 'all') {
        $query .= " AND gender = ?";
        $params[] = $gender_filter;
    }
    
    if ($status_filter !== 'all') {
        $query .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    $query .= " ORDER BY name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $hostels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all rooms for management
    $stmt = $pdo->prepare("
        SELECT hr.*, h.name as hostel_name, h.gender
        FROM hostel_rooms hr
        JOIN hostels h ON hr.hostel_id = h.id
        ORDER BY h.name, hr.floor, hr.room_number
    ");
    $stmt->execute();
    $all_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Hostel allocations
    $allocations_query = "
        SELECT ha.*, h.name as hostel_name, hr.room_number, 
               u.full_name as student_name, u.reg_number, u.email, u.phone, u.gender as student_gender,
               u_alloc.full_name as allocated_by_name
        FROM hostel_allocations ha
        JOIN hostels h ON ha.hostel_id = h.id
        JOIN hostel_rooms hr ON ha.room_id = hr.id
        JOIN users u ON ha.student_id = u.id
        JOIN users u_alloc ON ha.allocated_by = u_alloc.id
        WHERE 1=1
    ";
    
    $allocations_params = [];
    
    if ($hostel_filter !== 'all' && is_numeric($hostel_filter)) {
        $allocations_query .= " AND ha.hostel_id = ?";
        $allocations_params[] = $hostel_filter;
    }
    
    $allocations_query .= " ORDER BY ha.created_at DESC LIMIT 50";
    $stmt = $pdo->prepare($allocations_query);
    $stmt->execute($allocations_params);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Maintenance requests
    $stmt = $pdo->prepare("
        SELECT hm.*, h.name as hostel_name, hr.room_number,
               u_assigned.full_name as assigned_to_name
        FROM hostel_maintenance hm
        JOIN hostels h ON hm.hostel_id = h.id
        LEFT JOIN hostel_rooms hr ON hm.room_id = hr.id
        LEFT JOIN users u_assigned ON hm.assigned_to = u_assigned.id
        ORDER BY 
            CASE 
                WHEN hm.status = 'reported' THEN 1
                WHEN hm.status = 'in_progress' THEN 2
                ELSE 3
            END,
            CASE 
                WHEN hm.priority = 'urgent' THEN 1
                WHEN hm.priority = 'high' THEN 2
                WHEN hm.priority = 'medium' THEN 3
                ELSE 4
            END,
            hm.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $maintenance_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Available students for allocation (students without current hostel allocation)
    $current_year = date('Y') . '-' . (date('Y') + 1);
    $stmt = $pdo->prepare("
        SELECT u.id, u.reg_number, u.full_name, u.department_id, d.name as department_name, u.gender
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN hostel_allocations ha ON u.id = ha.student_id AND ha.academic_year = ? AND ha.status != 'checked_out'
        WHERE u.role = 'student' 
        AND u.status = 'active'
        AND ha.id IS NULL
        ORDER BY u.full_name
    ");
    $stmt->execute([$current_year]);
    $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Available rooms for allocation
    $stmt = $pdo->query("
        SELECT hr.*, h.name as hostel_name, h.gender
        FROM hostel_rooms hr
        JOIN hostels h ON hr.hostel_id = h.id
        WHERE hr.available_beds > 0 
        AND hr.status = 'available'
        AND h.status = 'active'
        ORDER BY h.name, hr.room_number
    ");
    $available_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Gender-specific statistics
    $stmt = $pdo->query("
        SELECT 
            h.gender,
            COUNT(*) as hostel_count,
            COALESCE(SUM(h.capacity), 0) as total_capacity,
            COALESCE(SUM(h.available_beds), 0) as available_beds,
            COALESCE(SUM(h.capacity - h.available_beds), 0) as occupied_beds
        FROM hostels h
        WHERE h.status = 'active'
        GROUP BY h.gender
    ");
    $gender_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Hostel data error: " . $e->getMessage());
    $hostel_stats = ['total_hostels' => 0, 'total_capacity' => 0, 'total_available' => 0, 'total_occupied' => 0];
    $hostels = $all_rooms = $allocations = $maintenance_requests = $available_students = $available_rooms = $gender_stats = [];
}

// Common amenities for forms
$common_amenities = ['WiFi', 'Laundry', 'Study Room', 'Common Kitchen', 'Gym', 'Library', 'Cafeteria', 'Security', 'AC', 'TV', 'Entertainment', 'Cleaning Service', 'Private Bathroom'];
$room_amenities = ['Desk', 'Wardrobe', 'Fan', 'AC', 'TV', 'Balcony', 'Private Bathroom', 'Locker', 'Common Bathroom'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Hostel Management - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary-purple: #8B5CF6;
            --secondary-purple: #a78bfa;
            --accent-purple: #7c3aed;
            --light-purple: #f3f4f6;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --male-color: #3B82F6;
            --female-color: #EC4899;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            --primary-purple: #a78bfa;
            --secondary-purple: #c4b5fd;
            --accent-purple: #8b5cf6;
            --light-purple: #1f2937;
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #b0b0b0;
            --text-dark: #e0e0e0;
            --success: #4caf50;
            --warning: #ffb74d;
            --danger: #f44336;
            --info: #29b6f6;
            --male-color: #60a5fa;
            --female-color: #f472b6;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
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
            color: var(--primary-purple);
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
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
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
            background: var(--primary-purple);
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
            background: var(--light-purple);
            border-left-color: var(--primary-purple);
            color: var(--primary-purple);
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

        .page-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .page-description {
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-purple);
            color: var(--primary-purple);
        }

        .btn-outline:hover {
            background: var(--primary-purple);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        /* Stats Grid */
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
            border-left: 4px solid var(--primary-purple);
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

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-card.male {
            border-left-color: var(--male-color);
        }

        .stat-card.female {
            border-left-color: var(--female-color);
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
        }

        .stat-card .stat-icon {
            background: var(--light-purple);
            color: var(--primary-purple);
        }

        .stat-card.success .stat-icon {
            background: #d4edda;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.danger .stat-icon {
            background: #f8d7da;
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background: #cce7ff;
            color: var(--info);
        }

        .stat-card.male .stat-icon {
            background: #dbeafe;
            color: var(--male-color);
        }

        .stat-card.female .stat-icon {
            background: #fce7f3;
            color: var(--female-color);
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

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
            text-align: center;
            white-space: nowrap;
        }

        .tab:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .tab.active {
            background: var(--primary-purple);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--light-purple);
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

        /* Tables */
        .table-container {
            overflow-x: auto;
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
            background: var(--light-purple);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-allocated {
            background: #fff3cd;
            color: #856404;
        }

        .status-checked_in {
            background: #d4edda;
            color: #155724;
        }

        .status-checked_out {
            background: #f8d7da;
            color: #721c24;
        }

        .status-cancelled {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-maintenance {
            background: #fff3cd;
            color: #856404;
        }

        .status-full {
            background: #f8d7da;
            color: #721c24;
        }

        .status-closed {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-available {
            background: #d4edda;
            color: #155724;
        }

        .status-occupied {
            background: #fff3cd;
            color: #856404;
        }

        .status-reserved {
            background: #cce7ff;
            color: #004085;
        }

        .status-reported {
            background: #fff3cd;
            color: #856404;
        }

        .status-in_progress {
            background: #cce7ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .priority-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority-urgent {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-high {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }

        .priority-low {
            background: #d4edda;
            color: #155724;
        }

        /* Hostels Grid */
        .hostels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .hostel-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .hostel-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .hostel-header {
            padding: 1rem;
            background: var(--gradient-primary);
            color: white;
        }

        .hostel-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .hostel-location {
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .hostel-body {
            padding: 1rem;
        }

        .hostel-info {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
        }

        .info-label {
            color: var(--dark-gray);
        }

        .info-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        .progress-bar {
            height: 8px;
            background: var(--medium-gray);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.7rem;
            color: var(--dark-gray);
            display: flex;
            justify-content: space-between;
            margin-top: 0.25rem;
        }

        /* Rooms Grid */
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        .room-card {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--border-radius);
            border-left: 3px solid var(--primary-purple);
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .room-number {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .room-type {
            background: var(--primary-purple);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .room-info {
            display: grid;
            gap: 0.4rem;
            font-size: 0.75rem;
        }

        .room-info-item {
            display: flex;
            justify-content: space-between;
        }

        .room-info-label {
            color: var(--dark-gray);
        }

        .room-info-value {
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.8rem;
        }

        .form-select, .form-input, .form-textarea {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text-dark);
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        /* Checkbox Grid */
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .checkbox-item label {
            font-size: 0.8rem;
            color: var(--text-dark);
            cursor: pointer;
        }

        /* Management Actions */
        .management-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--medium-gray);
        }

        .action-small {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--medium-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-purple);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1rem;
            color: var(--text-dark);
        }

        .close {
            color: var(--dark-gray);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
        }

        .close:hover {
            color: var(--text-dark);
        }

        .modal-body {
            padding: 1.25rem;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
                background: var(--primary-purple);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .hostels-grid {
                grid-template-columns: 1fr;
            }

            .rooms-grid {
                grid-template-columns: 1fr;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .checkbox-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
                justify-content: space-between;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab {
                flex: 1;
                min-width: 80px;
                padding: 0.5rem;
                font-size: 0.75rem;
            }

            .stat-number {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
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

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .checkbox-grid {
                grid-template-columns: 1fr;
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
                    <h1>Isonga - Hostel Management</h1>
                </div>
            </div>
            <div class="user-menu">
                <div class="header-actions">
                   
                    <a href="messages.php" class="icon-btn" title="Messages" style="position: relative;">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="notification-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="user-info">
                   
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                        <div class="user-role">Minister of Gender & Protocol</div>
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
                    <a href="tickets.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Gender Issues</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="protocol.php">
                        <i class="fas fa-handshake"></i>
                        <span>Protocol & Visitors</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="clubs.php">
                        <i class="fas fa-users"></i>
                        <span>Gender Clubs</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="hostel-management.php" class="active">
                        <i class="fas fa-building"></i>
                        <span>Hostel Management</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="action-funding.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Action Funding</span>
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
                        <?php if ($unread_messages > 0): ?>
                            <span class="menu-badge"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
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
            <!-- Page Header -->
            <div class="page-header">
               
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="showModal('addHostelModal')">
                        <i class="fas fa-plus-circle"></i> Add Hostel
                    </button>
                    <button class="btn btn-outline" onclick="showModal('addRoomModal')">
                        <i class="fas fa-door-open"></i> Add Room
                    </button>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($hostel_stats['total_hostels'] ?? 0); ?></div>
                        <div class="stat-label">Total Hostels</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-bed"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($hostel_stats['total_capacity'] ?? 0); ?></div>
                        <div class="stat-label">Total Capacity</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($hostel_stats['total_occupied'] ?? 0); ?></div>
                        <div class="stat-label">Occupied Beds</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($hostel_stats['total_available'] ?? 0); ?></div>
                        <div class="stat-label">Available Beds</div>
                    </div>
                </div>
            </div>

            <!-- Gender-specific Stats -->
            <div class="stats-grid">
                <?php foreach ($gender_stats as $stat): ?>
                    <div class="stat-card <?php echo $stat['gender'] === 'male' ? 'male' : 'female'; ?>">
                        <div class="stat-icon">
                            <i class="fas <?php echo $stat['gender'] === 'male' ? 'fa-male' : 'fa-female'; ?>"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $stat['hostel_count']; ?></div>
                            <div class="stat-label"><?php echo ucfirst($stat['gender']); ?> Hostels</div>
                            <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                <?php echo $stat['occupied_beds']; ?> occupied / <?php echo $stat['total_capacity']; ?> capacity
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>" onclick="showTab('overview')">Hostels Overview</button>
                <button class="tab <?php echo $tab === 'rooms' ? 'active' : ''; ?>" onclick="showTab('rooms')">Rooms Management</button>
                <button class="tab <?php echo $tab === 'allocations' ? 'active' : ''; ?>" onclick="showTab('allocations')">Student Allocations</button>
                <button class="tab <?php echo $tab === 'maintenance' ? 'active' : ''; ?>" onclick="showTab('maintenance')">Maintenance</button>
                <button class="tab <?php echo $tab === 'allocation' ? 'active' : ''; ?>" onclick="showTab('allocation')">Allocate Student</button>
            </div>

            <!-- Overview Tab -->
            <div id="overview" class="tab-content <?php echo $tab === 'overview' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>Hostels Management</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Filters -->
                        <div class="filters-card">
                            <form method="GET" class="filter-form" id="filterForm">
                                <input type="hidden" name="tab" value="overview">
                                <div class="form-group">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select" onchange="this.form.submit()">
                                        <option value="all" <?php echo $gender_filter === 'all' ? 'selected' : ''; ?>>All Genders</option>
                                        <option value="male" <?php echo $gender_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $gender_filter === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="mixed" <?php echo $gender_filter === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" onchange="this.form.submit()">
                                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                        <option value="full" <?php echo $status_filter === 'full' ? 'selected' : ''; ?>>Full</option>
                                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Apply
                                    </button>
                                    <a href="hostel-management.php?tab=overview" class="btn btn-outline">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                </div>
                            </form>
                        </div>

                        <div class="hostels-grid">
                            <?php if (empty($hostels)): ?>
                                <div class="empty-state" style="grid-column: 1 / -1;">
                                    <i class="fas fa-building"></i>
                                    <p>No hostels found. <a href="javascript:void(0)" onclick="showModal('addHostelModal')" style="color: var(--primary-purple);">Add the first hostel</a></p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($hostels as $hostel): ?>
                                    <?php 
                                    $occupancy_rate = $hostel['capacity'] > 0 ? (($hostel['capacity'] - $hostel['available_beds']) / $hostel['capacity']) * 100 : 0;
                                    $occupancy_class = $occupancy_rate >= 90 ? 'danger' : ($occupancy_rate >= 75 ? 'warning' : 'success');
                                    ?>
                                    <div class="hostel-card">
                                        <div class="hostel-header">
                                            <div class="hostel-name"><?php echo htmlspecialchars($hostel['name']); ?></div>
                                            <div class="hostel-location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($hostel['location']); ?></div>
                                        </div>
                                        <div class="hostel-body">
                                            <div class="hostel-info">
                                                <div class="info-item">
                                                    <span class="info-label"><i class="fas fa-venus-mars"></i> Gender:</span>
                                                    <span class="info-value"><?php echo ucfirst($hostel['gender']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label"><i class="fas fa-bed"></i> Capacity:</span>
                                                    <span class="info-value"><?php echo $hostel['capacity']; ?> beds</span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label"><i class="fas fa-check-circle"></i> Available:</span>
                                                    <span class="info-value"><?php echo $hostel['available_beds']; ?> beds</span>
                                                </div>
                                                <div class="info-item">
                                                    <span class="info-label"><i class="fas fa-tag"></i> Status:</span>
                                                    <span class="status-badge status-<?php echo $hostel['status']; ?>">
                                                        <?php echo ucfirst($hostel['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo round($occupancy_rate); ?>%; background: var(--<?php echo $occupancy_class; ?>);"></div>
                                            </div>
                                            <div class="progress-text">
                                                <span>Occupancy Rate</span>
                                                <span><?php echo round($occupancy_rate); ?>%</span>
                                            </div>
                                            
                                            <?php if (!empty($hostel['warden_name'])): ?>
                                            <div style="margin-top: 0.75rem; padding-top: 0.5rem; border-top: 1px solid var(--medium-gray); font-size: 0.75rem;">
                                                <div><i class="fas fa-user-tie"></i> <strong>Warden:</strong> <?php echo htmlspecialchars($hostel['warden_name']); ?></div>
                                                <?php if (!empty($hostel['warden_contact'])): ?>
                                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($hostel['warden_contact']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="management-actions">
                                                <button class="btn btn-outline action-small" onclick="editHostel(<?php echo $hostel['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-outline action-small" onclick="viewRooms(<?php echo $hostel['id']; ?>, '<?php echo htmlspecialchars($hostel['name']); ?>')">
                                                    <i class="fas fa-door-open"></i> Rooms
                                                </button>
                                                <button class="btn btn-outline action-small" onclick="showModalWithHostel('addRoomModal', <?php echo $hostel['id']; ?>)">
                                                    <i class="fas fa-plus"></i> Add Room
                                                </button>
                                                <button class="btn btn-danger action-small" onclick="deleteHostel(<?php echo $hostel['id']; ?>, '<?php echo htmlspecialchars($hostel['name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rooms Management Tab -->
            <div id="rooms" class="tab-content <?php echo $tab === 'rooms' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>Rooms Management</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="showModal('addRoomModal')" title="Add New Room">
                                <i class="fas fa-plus"></i>
                            </button>
                            <button class="card-header-btn" title="Refresh" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="rooms-grid">
                            <?php if (empty($all_rooms)): ?>
                                <div class="empty-state" style="grid-column: 1 / -1;">
                                    <i class="fas fa-door-open"></i>
                                    <p>No rooms found. <a href="javascript:void(0)" onclick="showModal('addRoomModal')" style="color: var(--primary-purple);">Add the first room</a></p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($all_rooms as $room): ?>
                                    <div class="room-card">
                                        <div class="room-header">
                                            <div class="room-number"><i class="fas fa-door-closed"></i> Room <?php echo htmlspecialchars($room['room_number']); ?></div>
                                            <span class="room-type"><?php echo ucfirst($room['room_type']); ?></span>
                                        </div>
                                        <div class="room-info">
                                            <div class="room-info-item">
                                                <span class="room-info-label"><i class="fas fa-building"></i> Hostel:</span>
                                                <span class="room-info-value"><?php echo htmlspecialchars($room['hostel_name']); ?></span>
                                            </div>
                                            <div class="room-info-item">
                                                <span class="room-info-label"><i class="fas fa-layer-group"></i> Floor:</span>
                                                <span class="room-info-value"><?php echo $room['floor']; ?></span>
                                            </div>
                                            <div class="room-info-item">
                                                <span class="room-info-label"><i class="fas fa-users"></i> Capacity:</span>
                                                <span class="room-info-value"><?php echo $room['capacity']; ?> beds</span>
                                            </div>
                                            <div class="room-info-item">
                                                <span class="room-info-label"><i class="fas fa-check-circle"></i> Available:</span>
                                                <span class="room-info-value"><?php echo $room['available_beds']; ?> beds</span>
                                            </div>
                                            <div class="room-info-item">
                                                <span class="room-info-label"><i class="fas fa-tag"></i> Status:</span>
                                                <span class="status-badge status-<?php echo $room['status']; ?>">
                                                    <?php echo ucfirst($room['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="management-actions" style="margin-top: 0.75rem;">
                                            <button class="btn btn-outline action-small" onclick="editRoom(<?php echo $room['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-danger action-small" onclick="deleteRoom(<?php echo $room['id']; ?>, 'Room <?php echo htmlspecialchars($room['room_number']); ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Allocations Tab -->
            <div id="allocations" class="tab-content <?php echo $tab === 'allocations' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>Student Allocations</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="showTab('allocation')" title="Allocate New Student">
                                <i class="fas fa-user-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Allocation Filters -->
                        <div class="filters-card">
                            <form method="GET" class="filter-form">
                                <input type="hidden" name="tab" value="allocations">
                                <div class="form-group">
                                    <label class="form-label">Hostel</label>
                                    <select name="hostel" class="form-select" onchange="this.form.submit()">
                                        <option value="all" <?php echo $hostel_filter === 'all' ? 'selected' : ''; ?>>All Hostels</option>
                                        <?php foreach ($hostels as $hostel): ?>
                                            <option value="<?php echo $hostel['id']; ?>" <?php echo $hostel_filter == $hostel['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($hostel['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Apply
                                    </button>
                                    <a href="hostel-management.php?tab=allocations" class="btn btn-outline">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                </div>
                            </form>
                        </div>

                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Gender</th>
                                        <th>Hostel & Room</th>
                                        <th>Bed</th>
                                        <th>Academic Year</th>
                                        <th>Status</th>
                                        <th>Allocated By</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($allocations)): ?>
                                        <tr>
                                            <td colspan="9" class="empty-state">
                                                <i class="fas fa-bed"></i>
                                                <p>No hostel allocations found.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($allocations as $allocation): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($allocation['student_name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                        <?php echo htmlspecialchars($allocation['reg_number']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $allocation['student_gender']; ?>">
                                                        <?php echo ucfirst($allocation['student_gender']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($allocation['hostel_name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">Room <?php echo htmlspecialchars($allocation['room_number']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($allocation['bed_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($allocation['academic_year']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $allocation['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $allocation['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($allocation['allocated_by_name']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($allocation['allocation_date'])); ?></td>
                                                <td>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="update_allocation_status">
                                                        <input type="hidden" name="allocation_id" value="<?php echo $allocation['id']; ?>">
                                                        <select name="status" class="form-select" style="font-size: 0.7rem; padding: 0.25rem;" onchange="this.form.submit()">
                                                            <option value="allocated" <?php echo $allocation['status'] === 'allocated' ? 'selected' : ''; ?>>Allocated</option>
                                                            <option value="checked_in" <?php echo $allocation['status'] === 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                                                            <option value="checked_out" <?php echo $allocation['status'] === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                                                        </select>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Maintenance Tab -->
            <div id="maintenance" class="tab-content <?php echo $tab === 'maintenance' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>Maintenance Requests</h3>
                        <div class="card-header-actions">
                            <button class="card-header-btn" onclick="showModal('maintenanceModal')" title="New Request">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Request</th>
                                        <th>Location</th>
                                        <th>Type</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Reported</th>
                                        <th>Due Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($maintenance_requests)): ?>
                                        <tr>
                                            <td colspan="8" class="empty-state">
                                                <i class="fas fa-check-circle"></i>
                                                <p>No maintenance requests found.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($maintenance_requests as $request): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($request['title']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                                                        <?php echo strlen($request['description'] ?? '') > 50 ? substr($request['description'], 0, 50) . '...' : ($request['description'] ?? ''); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($request['hostel_name']); ?></div>
                                                    <?php if (!empty($request['room_number'])): ?>
                                                        <div style="font-size: 0.7rem; color: var(--dark-gray);">Room <?php echo htmlspecialchars($request['room_number']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo ucfirst($request['issue_type'] ?? 'other'); ?></td>
                                                <td>
                                                    <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                        <?php echo ucfirst($request['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                                <td><?php echo $request['due_date'] ? date('M j, Y', strtotime($request['due_date'])) : 'N/A'; ?></td>
                                                <td>
                                                    <button class="btn btn-outline action-small" onclick="updateMaintenance(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-edit"></i> Update
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Allocation Tab -->
            <div id="allocation" class="tab-content <?php echo $tab === 'allocation' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3>Allocate Student to Hostel</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="allocationForm">
                            <input type="hidden" name="action" value="allocate_hostel">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Student *</label>
                                    <select name="student_id" class="form-select" required>
                                        <option value="">Select Student</option>
                                        <?php foreach ($available_students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['reg_number'] . ') - ' . ($student['department_name'] ?? 'No Department')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($available_students)): ?>
                                        <div style="color: var(--warning); font-size: 0.8rem; margin-top: 0.5rem;">
                                            <i class="fas fa-exclamation-triangle"></i> No students available for allocation (all students may already have hostels)
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Hostel & Room *</label>
                                    <select name="room_id" id="room_select" class="form-select" required onchange="updateBedOptions()">
                                        <option value="">Select Room</option>
                                        <?php foreach ($available_rooms as $room): ?>
                                            <option value="<?php echo $room['id']; ?>" data-hostel="<?php echo $room['hostel_id']; ?>" data-beds="<?php echo $room['available_beds']; ?>">
                                                <?php echo htmlspecialchars($room['hostel_name'] . ' - Room ' . $room['room_number'] . ' (' . $room['gender'] . ') - ' . $room['available_beds'] . ' bed(s) available'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="hostel_id" id="hostel_id">
                                    <?php if (empty($available_rooms)): ?>
                                        <div style="color: var(--danger); font-size: 0.8rem; margin-top: 0.5rem;">
                                            <i class="fas fa-exclamation-circle"></i> No rooms available for allocation
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Bed Number</label>
                                    <select name="bed_number" class="form-select" id="bed_number">
                                        <option value="">Auto-assign</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Academic Year *</label>
                                    <input type="text" name="academic_year" class="form-input" value="2024-2025" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Allocation Reason</label>
                                <textarea name="allocation_reason" class="form-textarea" placeholder="Reason for hostel allocation (optional)"></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" <?php echo (empty($available_students) || empty($available_rooms)) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-user-plus"></i> Allocate Hostel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Hostel Modal -->
    <div id="addHostelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Hostel</h3>
                <button class="close" onclick="closeModal('addHostelModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addHostelForm" method="POST">
                    <input type="hidden" name="action" value="add_hostel">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Hostel Name *</label>
                            <input type="text" name="name" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Gender *</label>
                            <select name="gender" class="form-select" required>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" class="form-input" min="1" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Description of the hostel facilities..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Amenities</label>
                        <div class="checkbox-grid">
                            <?php foreach ($common_amenities as $amenity): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="amenities[]" value="<?php echo $amenity; ?>" id="amenity_<?php echo strtolower(str_replace(' ', '_', $amenity)); ?>">
                                    <label for="amenity_<?php echo strtolower(str_replace(' ', '_', $amenity)); ?>"><?php echo $amenity; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Warden Name</label>
                            <input type="text" name="warden_name" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Warden Contact</label>
                            <input type="text" name="warden_contact" class="form-input">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addHostelModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Hostel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Hostel Modal -->
    <div id="editHostelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Hostel</h3>
                <button class="close" onclick="closeModal('editHostelModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editHostelForm" method="POST">
                    <input type="hidden" name="action" value="edit_hostel">
                    <input type="hidden" name="hostel_id" id="edit_hostel_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Hostel Name *</label>
                            <input type="text" name="name" id="edit_hostel_name" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Gender *</label>
                            <select name="gender" id="edit_hostel_gender" class="form-select" required>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" id="edit_hostel_location" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" id="edit_hostel_capacity" class="form-input" min="1" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_hostel_description" class="form-textarea"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Amenities</label>
                        <div class="checkbox-grid" id="edit_hostel_amenities"></div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Warden Name</label>
                            <input type="text" name="warden_name" id="edit_hostel_warden_name" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Warden Contact</label>
                            <input type="text" name="warden_contact" id="edit_hostel_warden_contact" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_hostel_status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="full">Full</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editHostelModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Hostel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Room Modal -->
    <div id="addRoomModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-door-open"></i> Add New Room</h3>
                <button class="close" onclick="closeModal('addRoomModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addRoomForm" method="POST">
                    <input type="hidden" name="action" value="add_room">
                    <input type="hidden" name="hostel_id" id="add_room_hostel_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Hostel *</label>
                            <select name="hostel_id_select" id="add_room_hostel_select" class="form-select" required onchange="document.getElementById('add_room_hostel_id').value = this.value">
                                <option value="">Select Hostel</option>
                                <?php foreach ($hostels as $hostel): ?>
                                    <option value="<?php echo $hostel['id']; ?>"><?php echo htmlspecialchars($hostel['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Room Number *</label>
                            <input type="text" name="room_number" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Floor *</label>
                            <input type="number" name="floor" class="form-input" min="1" value="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" class="form-input" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Room Type *</label>
                            <select name="room_type" class="form-select" required>
                                <option value="single">Single</option>
                                <option value="double">Double</option>
                                <option value="triple">Triple</option>
                                <option value="dormitory">Dormitory</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Room Amenities</label>
                        <div class="checkbox-grid">
                            <?php foreach ($room_amenities as $amenity): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="amenities[]" value="<?php echo $amenity; ?>" id="room_amenity_<?php echo strtolower(str_replace(' ', '_', $amenity)); ?>">
                                    <label for="room_amenity_<?php echo strtolower(str_replace(' ', '_', $amenity)); ?>"><?php echo $amenity; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addRoomModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div id="editRoomModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Room</h3>
                <button class="close" onclick="closeModal('editRoomModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editRoomForm" method="POST">
                    <input type="hidden" name="action" value="edit_room">
                    <input type="hidden" name="room_id" id="edit_room_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Room Number *</label>
                            <input type="text" name="room_number" id="edit_room_number" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Floor *</label>
                            <input type="number" name="floor" id="edit_room_floor" class="form-input" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" id="edit_room_capacity" class="form-input" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Room Type *</label>
                            <select name="room_type" id="edit_room_type" class="form-select" required>
                                <option value="single">Single</option>
                                <option value="double">Double</option>
                                <option value="triple">Triple</option>
                                <option value="dormitory">Dormitory</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_room_status" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="occupied">Occupied</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="reserved">Reserved</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Room Amenities</label>
                        <div class="checkbox-grid" id="edit_room_amenities"></div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editRoomModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Maintenance Modal -->
    <div id="maintenanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-tools"></i> New Maintenance Request</h3>
                <button class="close" onclick="closeModal('maintenanceModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_maintenance">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Hostel *</label>
                            <select name="hostel_id" id="maintenance_hostel_select" class="form-select" required onchange="updateRoomOptions()">
                                <option value="">Select Hostel</option>
                                <?php foreach ($hostels as $hostel): ?>
                                    <option value="<?php echo $hostel['id']; ?>"><?php echo htmlspecialchars($hostel['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Room (Optional)</label>
                            <select name="room_id" id="maintenance_room_select" class="form-select">
                                <option value="">Select Room</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Issue Type *</label>
                            <select name="issue_type" class="form-select" required>
                                <option value="electrical">Electrical</option>
                                <option value="plumbing">Plumbing</option>
                                <option value="furniture">Furniture</option>
                                <option value="cleaning">Cleaning</option>
                                <option value="safety">Safety</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-input" placeholder="Brief description of the issue" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-textarea" placeholder="Detailed description of the maintenance issue..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-input">
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('maintenanceModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
       

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        const savedSidebarState = localStorage.getItem('sidebarCollapsed');
        if (savedSidebarState === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-chevron-right"></i>';
        }
        
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            const icon = isCollapsed ? '<i class="fas fa-chevron-right"></i>' : '<i class="fas fa-chevron-left"></i>';
            if (sidebarToggle) sidebarToggle.innerHTML = icon;
        }
        
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('mobile-open');
                mobileOverlay.classList.toggle('active', isOpen);
                mobileMenuToggle.innerHTML = isOpen ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
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
                if (mobileOverlay) mobileOverlay.classList.remove('active');
                if (mobileMenuToggle) mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                document.body.style.overflow = '';
            }
        });

        // Tab Management
        function showTab(tabName) {
            // Update URL without reload
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
            // Update tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Find and activate the clicked tab
            const tabs = document.querySelectorAll('.tab');
            const tabMap = {
                'overview': 0,
                'rooms': 1,
                'allocations': 2,
                'maintenance': 3,
                'allocation': 4
            };
            const index = tabMap[tabName];
            if (tabs[index]) {
                tabs[index].classList.add('active');
            }
        }

        // Make showTab work with click events
        document.querySelectorAll('.tab').forEach((tab, index) => {
            tab.addEventListener('click', function(e) {
                const tabNames = ['overview', 'rooms', 'allocations', 'maintenance', 'allocation'];
                showTab(tabNames[index]);
            });
        });

        // Modal Management
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function showModalWithHostel(modalId, hostelId) {
            showModal(modalId);
            if (modalId === 'addRoomModal') {
                document.getElementById('add_room_hostel_id').value = hostelId;
                const select = document.getElementById('add_room_hostel_select');
                if (select) {
                    select.value = hostelId;
                }
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            document.querySelectorAll('.modal').forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            });
        }

        // Hostel Management Functions
        const hostelsData = <?php echo json_encode($hostels); ?>;
        const commonAmenities = <?php echo json_encode($common_amenities); ?>;
        
        function editHostel(hostelId) {
            const hostel = hostelsData.find(h => h.id == hostelId);
            
            if (hostel) {
                document.getElementById('edit_hostel_id').value = hostel.id;
                document.getElementById('edit_hostel_name').value = hostel.name;
                document.getElementById('edit_hostel_gender').value = hostel.gender;
                document.getElementById('edit_hostel_location').value = hostel.location;
                document.getElementById('edit_hostel_capacity').value = hostel.capacity;
                document.getElementById('edit_hostel_description').value = hostel.description || '';
                document.getElementById('edit_hostel_warden_name').value = hostel.warden_name || '';
                document.getElementById('edit_hostel_warden_contact').value = hostel.warden_contact || '';
                document.getElementById('edit_hostel_status').value = hostel.status;
                
                // Set amenities checkboxes
                const amenitiesContainer = document.getElementById('edit_hostel_amenities');
                amenitiesContainer.innerHTML = '';
                
                const hostelAmenities = JSON.parse(hostel.amenities || '[]');
                
                commonAmenities.forEach(amenity => {
                    const checkboxId = `edit_amenity_${amenity.toLowerCase().replace(/ /g, '_')}`;
                    const isChecked = hostelAmenities.includes(amenity);
                    
                    amenitiesContainer.innerHTML += `
                        <div class="checkbox-item">
                            <input type="checkbox" name="amenities[]" value="${amenity}" id="${checkboxId}" ${isChecked ? 'checked' : ''}>
                            <label for="${checkboxId}">${amenity}</label>
                        </div>
                    `;
                });
                
                showModal('editHostelModal');
            }
        }

        function deleteHostel(hostelId, hostelName) {
            if (confirm(`Are you sure you want to delete the hostel "${hostelName}"? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_hostel">
                    <input type="hidden" name="hostel_id" value="${hostelId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewRooms(hostelId, hostelName) {
            showTab('rooms');
            // Scroll to rooms section
            document.getElementById('rooms').scrollIntoView({ behavior: 'smooth' });
        }

        // Room Management Functions
        const allRooms = <?php echo json_encode($all_rooms); ?>;
        const roomAmenities = <?php echo json_encode($room_amenities); ?>;
        
        function editRoom(roomId) {
            const room = allRooms.find(r => r.id == roomId);
            
            if (room) {
                document.getElementById('edit_room_id').value = room.id;
                document.getElementById('edit_room_number').value = room.room_number;
                document.getElementById('edit_room_floor').value = room.floor;
                document.getElementById('edit_room_capacity').value = room.capacity;
                document.getElementById('edit_room_type').value = room.room_type;
                document.getElementById('edit_room_status').value = room.status;
                
                // Set amenities checkboxes
                const amenitiesContainer = document.getElementById('edit_room_amenities');
                amenitiesContainer.innerHTML = '';
                
                const roomAmenitiesList = JSON.parse(room.amenities || '[]');
                
                roomAmenities.forEach(amenity => {
                    const checkboxId = `edit_room_amenity_${amenity.toLowerCase().replace(/ /g, '_')}`;
                    const isChecked = roomAmenitiesList.includes(amenity);
                    
                    amenitiesContainer.innerHTML += `
                        <div class="checkbox-item">
                            <input type="checkbox" name="amenities[]" value="${amenity}" id="${checkboxId}" ${isChecked ? 'checked' : ''}>
                            <label for="${checkboxId}">${amenity}</label>
                        </div>
                    `;
                });
                
                showModal('editRoomModal');
            }
        }

        function deleteRoom(roomId, roomName) {
            if (confirm(`Are you sure you want to delete ${roomName}? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="room_id" value="${roomId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Allocation Functions
        function updateBedOptions() {
            const roomSelect = document.getElementById('room_select');
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            const availableBeds = parseInt(selectedOption.getAttribute('data-beds') || 0);
            const hostelId = selectedOption.getAttribute('data-hostel');
            
            document.getElementById('hostel_id').value = hostelId || '';
            
            const bedSelect = document.getElementById('bed_number');
            bedSelect.innerHTML = '<option value="">Auto-assign</option>';
            
            if (!isNaN(availableBeds) && availableBeds > 0) {
                for (let i = 1; i <= availableBeds; i++) {
                    bedSelect.innerHTML += `<option value="Bed ${i}">Bed ${i}</option>`;
                }
            }
        }

        // Maintenance Functions
        function updateRoomOptions() {
            const hostelSelect = document.getElementById('maintenance_hostel_select');
            const hostelId = hostelSelect.value;
            const roomSelect = document.getElementById('maintenance_room_select');
            
            roomSelect.innerHTML = '<option value="">Select Room</option>';
            
            if (hostelId) {
                const rooms = allRooms.filter(room => room.hostel_id == hostelId);
                rooms.forEach(room => {
                    roomSelect.innerHTML += `<option value="${room.id}">Room ${room.room_number}</option>`;
                });
            }
        }

        function updateMaintenance(maintenanceId) {
            const status = prompt('Enter new status (reported/in_progress/completed):');
            if (status && ['reported', 'in_progress', 'completed'].includes(status.toLowerCase())) {
                const form = document.createElement('form');
                form.method = 'POST';
                
                let formContent = `
                    <input type="hidden" name="action" value="update_maintenance_status">
                    <input type="hidden" name="maintenance_id" value="${maintenanceId}">
                    <input type="hidden" name="status" value="${status.toLowerCase()}">
                `;
                
                if (status.toLowerCase() === 'completed') {
                    const completionNotes = prompt('Enter completion notes:');
                    const actualCost = prompt('Enter actual cost (RWF):');
                    
                    if (completionNotes !== null) {
                        formContent += `<input type="hidden" name="completion_notes" value="${completionNotes.replace(/"/g, '&quot;')}">`;
                    }
                    if (actualCost !== null && !isNaN(actualCost) && actualCost !== '') {
                        formContent += `<input type="hidden" name="actual_cost" value="${actualCost}">`;
                    }
                }
                
                form.innerHTML = formContent;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Form Validation
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
            });
            
            // Auto-close alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(() => {
                        if (alert.parentNode) alert.remove();
                    }, 500);
                });
            }, 5000);
            
            // Initialize room options for allocation
            const roomSelect = document.getElementById('room_select');
            if (roomSelect) {
                updateBedOptions();
            }
        });

        // Auto-refresh data every 2 minutes (only if no modal is open)
        setInterval(() => {
            const openModals = document.querySelectorAll('.modal[style*="display: block"]');
            if (openModals.length === 0) {
                window.location.reload();
            }
        }, 120000);
    </script>
</body>
</html>