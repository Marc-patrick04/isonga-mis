<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user profile data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_admin = [];
}

$message = '';
$error = '';

// Get departments for dropdowns
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Get committee roles for display (these are the actual roles stored in users.role)
$committee_roles = [
    'guild_president' => 'Guild President',
    'vice_guild_academic' => 'Vice Guild President - Academic',
    'vice_guild_finance' => 'Vice Guild President - Finance',
    'general_secretary' => 'General Secretary',
    'minister_sports' => 'Minister of Sports',
    'minister_environment' => 'Minister of Environment',
    'minister_public_relations' => 'Minister of Public Relations',
    'minister_health' => 'Minister of Health',
    'minister_culture' => 'Minister of Culture',
    'minister_gender' => 'Minister of Gender',
    'president_representative_board' => 'President - Rep Board',
    'vice_president_representative_board' => 'Vice President - Rep Board',
    'secretary_representative_board' => 'Secretary - Rep Board',
    'president_arbitration' => 'President - Arbitration',
    'vice_president_arbitration' => 'Vice President - Arbitration',
    'advisor_arbitration' => 'Advisor - Arbitration',
    'secretary_arbitration' => 'Secretary - Arbitration'
];

// Helper function to check duplicates
function checkDuplicateUser($pdo, $username, $email, $reg_number = null, $exclude_id = null) {
    $errors = [];
    
    $sql = "SELECT id FROM users WHERE username = ? AND deleted_at IS NULL";
    $params = [$username];
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        $errors[] = "Username '$username' is already taken.";
    }
    
    $sql = "SELECT id FROM users WHERE email = ? AND deleted_at IS NULL";
    $params = [$email];
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        $errors[] = "Email '$email' is already registered.";
    }
    
    if (!empty($reg_number)) {
        $sql = "SELECT id FROM users WHERE reg_number = ? AND deleted_at IS NULL";
        $params = [$reg_number];
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            $errors[] = "Registration number '$reg_number' is already assigned.";
        }
    }
    
    return $errors;
}

// Handle Add User
// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $username = !empty($_POST['username']) ? trim($_POST['username']) : explode('@', $_POST['email'])[0];
            $email = trim($_POST['email']);
            $reg_number = !empty($_POST['reg_number']) ? trim($_POST['reg_number']) : null;
            
            // Check for duplicates with friendly messages
            $duplicate_errors = checkDuplicateUser($pdo, $username, $email, $reg_number);
            if (!empty($duplicate_errors)) {
                throw new Exception(implode(" ", $duplicate_errors));
            }
            
            // Determine the actual role to store in users table
            $user_role = $_POST['role'];
            $committee_role = null;
            
            if ($_POST['role'] === 'committee') {
                $committee_role = $_POST['committee_role'] ?? 'general_secretary';
                $user_role = $committee_role; // Store specific role in users table
            }
            
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Only start transaction if we're going to do multiple inserts
            $transactionStarted = false;
            
            try {
                // First, insert the user
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        reg_number, username, password, role, full_name, email, phone, 
                        date_of_birth, gender, bio, address, emergency_contact_name, 
                        emergency_contact_phone, email_notifications, sms_notifications, 
                        preferred_language, theme_preference, two_factor_enabled, 
                        academic_year, is_class_rep, department_id, program_id, status, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW()
                    )
                ");
                
                $stmt->execute([
                    $reg_number,
                    $username,
                    $password,
                    $user_role,
                    $_POST['full_name'],
                    $email,
                    $_POST['phone'] ?? null,
                    $_POST['date_of_birth'] ?? null,
                    $_POST['gender'] ?? null,
                    $_POST['bio'] ?? null,
                    $_POST['address'] ?? null,
                    $_POST['emergency_contact_name'] ?? null,
                    $_POST['emergency_contact_phone'] ?? null,
                    isset($_POST['email_notifications']) ? 1 : 0,
                    isset($_POST['sms_notifications']) ? 1 : 0,
                    $_POST['preferred_language'] ?? 'en',
                    $_POST['theme_preference'] ?? 'light',
                    isset($_POST['two_factor_enabled']) ? 1 : 0,
                    $_POST['academic_year'] ?? null,
                    isset($_POST['is_class_rep']) ? 1 : 0,
                    !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                    !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                    $_SESSION['user_id']
                ]);
                
                $new_user_id = $pdo->lastInsertId();
                
                // If role is committee, add to committee_members table
                if ($_POST['role'] === 'committee') {
                    // Start transaction only if we need to do multiple operations
                    $pdo->beginTransaction();
                    $transactionStarted = true;
                    
                    $committee_stmt = $pdo->prepare("
                        INSERT INTO committee_members (
                            user_id, reg_number, name, role, email, phone, 
                            department_id, program_id, academic_year, 
                            status, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
                    ");
                    $committee_stmt->execute([
                        $new_user_id,
                        $reg_number,
                        $_POST['full_name'],
                        $committee_role,
                        $email,
                        $_POST['phone'] ?? null,
                        !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                        !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                        $_POST['academic_year'] ?? null,
                        $_SESSION['user_id']
                    ]);
                    
                    // Commit the transaction
                    $pdo->commit();
                    $transactionStarted = false;
                }
                
                $message = "User created successfully!";
                header("Location: users.php?msg=" . urlencode($message));
                exit();
            } catch (Exception | PDOException $e) {
                // Only rollback if we started a transaction
                if ($transactionStarted) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            // Handle database constraint violations with friendly messages
            if (strpos($e->getMessage(), 'users_username_key') !== false) {
                $error = "Username already exists. Please choose a different username.";
            } elseif (strpos($e->getMessage(), 'users_email_key') !== false) {
                $error = "Email already exists. Please use a different email address.";
            } elseif (strpos($e->getMessage(), 'users_reg_number_key') !== false) {
                $error = "Registration number already exists. Please use a different registration number.";
            } else {
                $error = "Error creating user: " . $e->getMessage();
            }
            error_log("User creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit User
    elseif ($_POST['action'] === 'edit') {
        try {
            $user_id_edit = $_POST['user_id'];
            $username = !empty($_POST['username']) ? trim($_POST['username']) : explode('@', $_POST['email'])[0];
            $email = trim($_POST['email']);
            $reg_number = !empty($_POST['reg_number']) ? trim($_POST['reg_number']) : null;
            
            // Get current user role before update
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id_edit]);
            $current_role = $stmt->fetchColumn();
            
            $duplicate_errors = checkDuplicateUser($pdo, $username, $email, $reg_number, $user_id_edit);
            if (!empty($duplicate_errors)) {
                throw new Exception(implode(" ", $duplicate_errors));
            }
            
            // Determine the actual role to store in users table
            $new_role = $_POST['role'];
            $committee_role = null;
            
            if ($_POST['role'] === 'committee') {
                $committee_role = $_POST['committee_role'] ?? 'general_secretary';
                $new_role = $committee_role; // Store specific role in users table
            }
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'reg_number', 'username', 'full_name', 'email', 'phone',
                'date_of_birth', 'gender', 'bio', 'address', 'emergency_contact_name',
                'emergency_contact_phone', 'preferred_language', 'theme_preference',
                'academic_year', 'department_id', 'program_id'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field] !== '' ? $_POST[$field] : null;
                }
            }
            
            // Add role field
            $updateFields[] = "role = ?";
            $params[] = $new_role;
            
            $updateFields[] = "email_notifications = ?";
            $params[] = isset($_POST['email_notifications']) ? 1 : 0;
            
            $updateFields[] = "sms_notifications = ?";
            $params[] = isset($_POST['sms_notifications']) ? 1 : 0;
            
            $updateFields[] = "two_factor_enabled = ?";
            $params[] = isset($_POST['two_factor_enabled']) ? 1 : 0;
            
            $updateFields[] = "is_class_rep = ?";
            $params[] = isset($_POST['is_class_rep']) ? 1 : 0;
            
            if (!empty($_POST['password'])) {
                $updateFields[] = "password = ?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $user_id_edit;
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Handle committee member records
            $is_committee = in_array($new_role, array_keys($committee_roles));
            
            if ($is_committee) {
                // Check if committee member record exists
                $check_stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ?");
                $check_stmt->execute([$user_id_edit]);
                $exists = $check_stmt->fetch();
                
                $committee_data = [
                    'reg_number' => $reg_number,
                    'name' => $_POST['full_name'],
                    'role' => $committee_role ?? $new_role,
                    'email' => $email,
                    'phone' => $_POST['phone'] ?? null,
                    'department_id' => !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                    'program_id' => !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                    'academic_year' => $_POST['academic_year'] ?? null
                ];
                
                if ($exists) {
                    // Update existing record
                    $committee_update = [];
                    $committee_params = [];
                    
                    foreach ($committee_data as $field => $value) {
                        $committee_update[] = "$field = ?";
                        $committee_params[] = $value;
                    }
                    
                    $committee_update[] = "updated_at = NOW()";
                    $committee_params[] = $user_id_edit;
                    
                    $committee_sql = "UPDATE committee_members SET " . implode(", ", $committee_update) . " WHERE user_id = ?";
                    $committee_stmt = $pdo->prepare($committee_sql);
                    $committee_stmt->execute($committee_params);
                } else {
                    // Insert new record
                    $committee_stmt = $pdo->prepare("
                        INSERT INTO committee_members (
                            user_id, reg_number, name, role, email, phone, 
                            department_id, program_id, academic_year, 
                            status, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
                    ");
                    $committee_stmt->execute([
                        $user_id_edit,
                        $committee_data['reg_number'],
                        $committee_data['name'],
                        $committee_data['role'],
                        $committee_data['email'],
                        $committee_data['phone'],
                        $committee_data['department_id'],
                        $committee_data['program_id'],
                        $committee_data['academic_year'],
                        $_SESSION['user_id']
                    ]);
                }
            } else {
                // If not a committee member, delete from committee_members if exists
                $delete_stmt = $pdo->prepare("DELETE FROM committee_members WHERE user_id = ?");
                $delete_stmt->execute([$user_id_edit]);
            }
            
            $message = "User updated successfully!";
            header("Location: users.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    }
    
    // Handle Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " users activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " users deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // First get committee members to delete photos
                    $photo_stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE user_id IN ($placeholders)");
                    $photo_stmt->execute($selected_ids);
                    $members = $photo_stmt->fetchAll();
                    foreach ($members as $member) {
                        if (!empty($member['photo_url'])) {
                            $photo_path = '../' . $member['photo_url'];
                            if (file_exists($photo_path)) unlink($photo_path);
                        }
                    }
                    
                    $pdo->prepare("DELETE FROM committee_members WHERE user_id IN ($placeholders)")->execute($selected_ids);
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', deleted_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " users deleted.";
                }
                header("Location: users.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action.";
            }
        } else {
            $error = "No users selected.";
        }
    }
}

// Handle status toggle via GET
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $user_id_toggle = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$user_id_toggle]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $user_id_toggle]);
        
        // Also update committee_members status if exists
        $cm_stmt = $pdo->prepare("UPDATE committee_members SET status = ? WHERE user_id = ?");
        $cm_stmt->execute([$new_status, $user_id_toggle]);
        
        $message = "User status updated successfully!";
        header("Location: users.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling user status.";
    }
}

// Handle delete via GET - PERMANENT DELETE
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $user_id_delete = $_GET['id'];
    
    if ($user_id_delete == $user_id) {
        $error = "You cannot delete your own account.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Delete photo if exists
            $photo_stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE user_id = ?");
            $photo_stmt->execute([$user_id_delete]);
            $member = $photo_stmt->fetch();
            if (!empty($member['photo_url'])) {
                $photo_path = '../' . $member['photo_url'];
                if (file_exists($photo_path)) unlink($photo_path);
            }
            
            // Delete from committee_members if exists
            $cm_stmt = $pdo->prepare("DELETE FROM committee_members WHERE user_id = ?");
            $cm_stmt->execute([$user_id_delete]);
            
            // Delete the user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id_delete]);
            
            $pdo->commit();
            
            $message = "User permanently deleted successfully!";
            header("Location: users.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error deleting user.";
        }
    }
}

// Get user for editing via AJAX
if (isset($_GET['get_user']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, cm.role as committee_role 
            FROM users u
            LEFT JOIN committee_members cm ON u.id = cm.user_id
            WHERE u.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($user_data);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get programs by department via AJAX
if (isset($_GET['get_programs']) && isset($_GET['department_id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND is_active = true ORDER BY name");
        $stmt->execute([$_GET['department_id']]);
        $programs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($programs_list);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';

$where_conditions = ["deleted_at IS NULL"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name ILIKE ? OR email ILIKE ? OR username ILIKE ? OR reg_number ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($role_filter)) {
    if ($role_filter === 'committee') {
        // Filter for any committee role (all specific roles)
        $committee_roles_list = array_keys($committee_roles);
        $placeholders = implode(',', array_fill(0, count($committee_roles_list), '?'));
        $where_conditions[] = "role IN ($placeholders)";
        $params = array_merge($params, $committee_roles_list);
    } else {
        $where_conditions[] = "role = ?";
        $params[] = $role_filter;
    }
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($department_filter)) {
    $where_conditions[] = "department_id = ?";
    $params[] = $department_filter;
}

$where_clause = implode(" AND ", $where_conditions);

try {
    $count_sql = "SELECT COUNT(*) FROM users WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_users = $stmt->fetchColumn();
    $total_pages = ceil($total_users / $limit);
} catch (PDOException $e) {
    $total_users = 0;
    $total_pages = 0;
}

try {
    $sql = "
        SELECT u.*, d.name as department_name, p.name as program_name,
               cm.role as committee_role, cm.id as committee_id
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN programs p ON u.program_id = p.id
        LEFT JOIN committee_members cm ON u.id = cm.user_id
        WHERE $where_clause
        ORDER BY u.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Get role stats (combine all committee roles into one "Committee" count)
try {
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY role");
    $role_stats_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine committee roles
    $role_stats = [];
    $committee_count = 0;
    foreach ($role_stats_raw as $stat) {
        if (array_key_exists($stat['role'], $committee_roles)) {
            $committee_count += $stat['count'];
        } else {
            $role_stats[] = $stat;
        }
    }
    if ($committee_count > 0) {
        $role_stats[] = ['role' => 'committee', 'count' => $committee_count];
    }
} catch (PDOException $e) {
    $role_stats = [];
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}
?>

<!-- The HTML remains the same as your existing file -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>User Management - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Your existing CSS styles remain exactly the same */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --sidebar-bg: #1f2937;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Header */
        .header {
            background: var(--header-bg);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text p {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border-color);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
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
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem 0;
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
            overflow-y: auto;
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
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover {
            background: var(--bg-primary);
            border-left-color: var(--primary);
        }

        .menu-item a.active {
            background: var(--bg-primary);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
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

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* Filters Bar */
        .filters-bar {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.8rem;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-box input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            width: 250px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Bulk Actions */
        .bulk-actions-bar {
            background: var(--card-bg);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .bulk-actions-bar select {
            padding: 0.4rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.75rem;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Users Table Container */
        .users-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .users-table th,
        .users-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .users-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .users-table tr:hover {
            background: var(--bg-primary);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .status-inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .role-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .role-admin {
            background: #cce5ff;
            color: #004085;
        }

        .role-student {
            background: #d4edda;
            color: #155724;
        }

        .role-committee {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .role-admin {
            background: rgba(59, 130, 246, 0.2);
            color: var(--info);
        }

        body.dark-mode .role-student {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .role-committee {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        .committee-role {
            display: block;
            font-size: 0.65rem;
            color: var(--text-secondary);
            margin-top: 0.2rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.2s;
            padding: 0.5rem;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.85rem;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group small {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-label input {
            width: auto;
            margin-right: 0;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        body.dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }

        body.dark-mode .alert-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary);
            background: var(--card-bg);
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Checkbox */
        .select-all, .user-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                margin-left: 0;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .header-container {
                padding: 0.75rem 1rem;
            }
            
            .user-details {
                display: none;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="../assets/images/rp_logo.png" alt="RP Musanze College" class="logo-img">
                <div class="logo-text">
                    <h1>Isonga Admin</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <div class="user-area">
                <button class="theme-toggle" id="themeToggle" title="Toggle Dark/Light Mode">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_admin['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($current_admin['full_name'] ?? 'Admin'); ?></div>
                        <div class="user-role">System Administrator</div>
                    </div>
                </div>
                <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="hero.php"><i class="fas fa-images"></i> Hero Images</a></li>
                <li class="menu-item"><a href="users.php" class="active"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="representative.php"><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="page-header">
                <h1><i class="fas fa-users"></i> User Management</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New User
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <?php foreach ($role_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['role']); ?>s</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form method="GET" action="" class="filters-bar" id="filterForm">
                <div class="filter-group">
                    <label>Role:</label>
                    <select name="role" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="committee" <?php echo $role_filter === 'committee' ? 'selected' : ''; ?>>Committee</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status:</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Department:</label>
                    <select name="department" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-box">
                    <input type="text" 
                           name="search" 
                           id="searchInput"
                           placeholder="Search by name, email, username..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search || $role_filter || $status_filter || $department_filter): ?>
                        <a href="users.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Bulk Actions -->
            <form method="POST" action="" id="bulkForm">
                <input type="hidden" name="action" value="bulk">
                <div class="bulk-actions-bar">
                    <select name="bulk_action" id="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                </div>

                <!-- Users Table -->
                <div class="users-table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="select-all" onclick="toggleAll(this)"></th>
                                <th>Reg Number</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Program</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <h3>No users found</h3>
                                            <p>Click "Add New User" to create one.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $user['id']; ?>" class="user-checkbox"></td>
                                        <td><?php echo htmlspecialchars($user['reg_number'] ?? '-'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($user['username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <?php if (array_key_exists($user['role'], $committee_roles)): ?>
                                                <span class="role-badge role-committee">
                                                    <?php echo htmlspecialchars($committee_roles[$user['role']] ?? $user['role']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($user['is_class_rep']): ?>
                                                <br><small style="color: var(--warning);">Class Rep</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['department_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($user['program_name'] ?? '-'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td class="action-buttons">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $user['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle_status=1&id=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle user status?')">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $user['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>" 
                           class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&department=<?php echo $department_filter; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle">Add New User</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="userForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="user_id" id="userId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" id="reg_number" placeholder="e.g., 2024-001">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" id="username" placeholder="Leave blank to auto-generate">
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" id="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="phone">
                    </div>
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" id="role" required onchange="toggleCommitteeRoleField()">
                            <option value="">Select Role</option>
                            <option value="student">Student</option>
                            <option value="committee">Committee Member</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="form-group" id="committeeRoleGroup" style="display: none;">
                        <label>Committee Role *</label>
                        <select name="committee_role" id="committee_role">
                            <option value="">Select Committee Role</option>
                            <?php foreach ($committee_roles as $key => $name): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" id="department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" id="program_id">
                            <option value="">Select Program</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" id="date_of_birth">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" id="gender">
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="academic_year" id="academic_year">
                            <option value="">Select Academic Year</option>
                            <option value="Year 1">Year 1</option>
                            <option value="Year 2">Year 2</option>
                            <option value="Year 3">Year 3</option>
                            <option value="Year 4">Year 4</option>
                            <option value="B-Tech">B-Tech</option>
                            <option value="M-Tech">M-Tech</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" id="password">
                        <small id="passwordHint">Password is required for new users</small>
                    </div>
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address" id="address" rows="2"></textarea>
                    </div>
                    <div class="form-group full-width">
                        <textarea name="bio" id="bio" rows="3" hidden></textarea>
                    </div>
                    <input type="text" name="emergency_contact_name" id="emergency_contact_name" hidden>
                    <input type="text" name="emergency_contact_phone" id="emergency_contact_phone" hidden>
                    <select name="preferred_language" id="preferred_language" hidden>
                        <option value="en">English</option>
                    </select>
                    <select name="theme_preference" id="theme_preference" hidden>
                        <option value="light">Light</option>
                    </select>
                    <input type="checkbox" name="email_notifications" id="email_notifications" value="1" hidden>
                    <input type="checkbox" name="sms_notifications" id="sms_notifications" value="1" hidden>
                    <input type="checkbox" name="two_factor_enabled" id="two_factor_enabled" value="1" hidden>
                    <input type="checkbox" name="is_class_rep" id="is_class_rep" value="1" hidden>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle committee role field based on selected role
        function toggleCommitteeRoleField() {
            const roleSelect = document.getElementById('role');
            const committeeRoleGroup = document.getElementById('committeeRoleGroup');
            if (roleSelect.value === 'committee') {
                committeeRoleGroup.style.display = 'block';
                document.getElementById('committee_role').required = true;
            } else {
                committeeRoleGroup.style.display = 'none';
                document.getElementById('committee_role').required = false;
            }
        }
        
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
        
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('formAction').value = 'add';
            document.getElementById('userId').value = '';
            document.getElementById('userForm').reset();
            document.getElementById('password').required = true;
            document.getElementById('passwordHint').textContent = 'Password is required for new users';
            document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
            document.getElementById('committeeRoleGroup').style.display = 'none';
            document.getElementById('userModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditModal(userId) {
    fetch(`users.php?get_user=1&id=${userId}`)
        .then(response => response.json())
        .then(user => {
            if (user.error) {
                alert('Error loading user data');
                return;
            }
            
            console.log('User data:', user); // Debug log
            
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('userId').value = user.id;
            document.getElementById('reg_number').value = user.reg_number || '';
            document.getElementById('username').value = user.username || '';
            document.getElementById('full_name').value = user.full_name || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('phone').value = user.phone || '';
            
            // Determine if user is a committee member (role is one of the committee roles)
            const committeeRoles = <?php echo json_encode(array_keys($committee_roles)); ?>;
            const isCommittee = committeeRoles.includes(user.role);
            
            if (isCommittee) {
                // User is a committee member
                document.getElementById('role').value = 'committee';
                document.getElementById('committeeRoleGroup').style.display = 'block';
                document.getElementById('committee_role').value = user.role;
                document.getElementById('committee_role').required = true;
            } else {
                // User is student or admin
                document.getElementById('role').value = user.role;
                document.getElementById('committeeRoleGroup').style.display = 'none';
                document.getElementById('committee_role').required = false;
            }
            
            document.getElementById('department_id').value = user.department_id || '';
            document.getElementById('date_of_birth').value = user.date_of_birth || '';
            document.getElementById('gender').value = user.gender || '';
            document.getElementById('academic_year').value = user.academic_year || '';
            document.getElementById('address').value = user.address || '';
            document.getElementById('bio').value = user.bio || '';
            document.getElementById('emergency_contact_name').value = user.emergency_contact_name || '';
            document.getElementById('emergency_contact_phone').value = user.emergency_contact_phone || '';
            document.getElementById('preferred_language').value = user.preferred_language || 'en';
            document.getElementById('theme_preference').value = user.theme_preference || 'light';
            document.getElementById('email_notifications').checked = user.email_notifications == 1;
            document.getElementById('sms_notifications').checked = user.sms_notifications == 1;
            document.getElementById('two_factor_enabled').checked = user.two_factor_enabled == 1;
            document.getElementById('is_class_rep').checked = user.is_class_rep == 1;
            document.getElementById('password').required = false;
            document.getElementById('password').value = '';
            document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
            
            if (user.department_id) {
                loadPrograms(user.department_id, user.program_id);
            } else {
                document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
            }
            
            document.getElementById('userModal').classList.add('active');
            document.body.classList.add('modal-open');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading user data: ' + error.message);
        });
}
        
        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function loadPrograms(departmentId, selectedProgramId = null) {
            if (!departmentId) {
                document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
                return;
            }
            
            fetch(`users.php?get_programs=1&department_id=${departmentId}`)
                .then(response => response.json())
                .then(programs => {
                    let options = '<option value="">Select Program</option>';
                    if (!programs.error && programs.length > 0) {
                        programs.forEach(program => {
                            const selected = selectedProgramId == program.id ? 'selected' : '';
                            options += `<option value="${program.id}" ${selected}>${escapeHtml(program.name)}</option>`;
                        });
                    }
                    document.getElementById('program_id').innerHTML = options;
                })
                .catch(error => {
                    console.error('Error loading programs:', error);
                });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        document.getElementById('department_id').addEventListener('change', function() {
            loadPrograms(this.value);
        });
        
        document.getElementById('role').addEventListener('change', toggleCommitteeRoleField);
        
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.user-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one user');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} user(s)?`);
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('userModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        // Handle search on Enter key
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.getElementById('filterForm').submit();
                    }
                });
            }
        });
        // Add this to your JavaScript section
document.getElementById('userForm').addEventListener('submit', function(e) {
    const action = document.getElementById('formAction').value;
    const role = document.getElementById('role').value;
    const committeeRole = document.getElementById('committee_role').value;
    
    // For committee members, ensure committee role is set
    if (role === 'committee' && !committeeRole) {
        e.preventDefault();
        alert('Please select a committee role');
        return false;
    }
    
    // Log the form data for debugging
    console.log('Form submitted with action:', action);
    console.log('Role:', role);
    console.log('Committee Role:', committeeRole);
    
    return true;
});
    </script>
</body>
</html>