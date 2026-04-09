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

// Define available roles
$available_roles = [
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

// Handle Excel Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['excel_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        $allowed_extensions = ['csv'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            try {
                $upload_dir = '../assets/uploads/excel_imports/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = 'committee_import_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    $success_count = 0;
                    $duplicate_count = 0;
                    $invalid_count = 0;
                    $not_student_count = 0;
                    $errors = [];
                    
                    if (($handle = fopen($file_path, "r")) !== FALSE) {
                        $first_line = fgets($handle);
                        $first_line = preg_replace('/^\xEF\xBB\xBF/', '', $first_line);
                        $headers = str_getcsv($first_line);
                        $headers = array_map('trim', $headers);
                        $headers_lower = array_map('strtolower', $headers);
                        
                        $required_headers = ['email', 'role'];
                        $missing_headers = [];
                        foreach ($required_headers as $required) {
                            if (!in_array($required, $headers_lower)) {
                                $missing_headers[] = $required;
                            }
                        }
                        
                        if (empty($missing_headers)) {
                            $row_count = 0;
                            while (($line = fgets($handle)) !== FALSE) {
                                $row_count++;
                                $data = str_getcsv($line);
                                
                                if (empty(array_filter($data)) || count($data) < count($headers)) {
                                    continue;
                                }
                                
                                $row_lower = [];
                                foreach ($headers as $index => $header) {
                                    $row_lower[strtolower($header)] = isset($data[$index]) ? trim($data[$index]) : '';
                                }
                                
                                $email = $row_lower['email'] ?? '';
                                $role = $row_lower['role'] ?? '';
                                
                                if (empty($email) || empty($role)) {
                                    $invalid_count++;
                                    $errors[] = "Row $row_count: Missing email or role";
                                    continue;
                                }
                                
                                // Check if user exists (can be student or already has any role)
                                $user_stmt = $pdo->prepare("SELECT id, full_name, reg_number, phone, department_id, program_id, academic_year, role FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL");
                                $user_stmt->execute([$email]);
                                $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$user) {
                                    $not_student_count++;
                                    $errors[] = "Row $row_count: Email '$email' is not registered. Please add the user first.";
                                    continue;
                                }
                                
                                // Check if already a committee member
                                $check_stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ?");
                                $check_stmt->execute([$user['id']]);
                                if ($check_stmt->fetch()) {
                                    $duplicate_count++;
                                    continue;
                                }
                                
                                try {
                                    // Start transaction
                                    $pdo->beginTransaction();
                                    
                                    // Update user role
                                    $update_user_stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                                    $update_user_stmt->execute([$role, $user['id']]);
                                    
                                    // Insert into committee_members
                                    $stmt = $pdo->prepare("
                                        INSERT INTO committee_members (
                                            user_id, name, reg_number, role, role_order, 
                                            department_id, program_id, academic_year, 
                                            email, phone, bio, portfolio_description, 
                                            status, created_by, created_at
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                    ");
                                    
                                    $stmt->execute([
                                        $user['id'],
                                        $user['full_name'],
                                        $user['reg_number'],
                                        $role,
                                        isset($row_lower['role_order']) && is_numeric($row_lower['role_order']) ? (int)$row_lower['role_order'] : 0,
                                        $user['department_id'],
                                        $user['program_id'],
                                        $user['academic_year'],
                                        $email,
                                        $user['phone'],
                                        !empty($row_lower['bio']) ? $row_lower['bio'] : null,
                                        !empty($row_lower['portfolio_description']) ? $row_lower['portfolio_description'] : null,
                                        isset($row_lower['status']) && in_array(strtolower($row_lower['status']), ['active', 'inactive']) ? strtolower($row_lower['status']) : 'active',
                                        $user_id
                                    ]);
                                    
                                    $pdo->commit();
                                    $success_count++;
                                } catch (PDOException $e) {
                                    $pdo->rollBack();
                                    $invalid_count++;
                                    $errors[] = "Row $row_count: " . $e->getMessage();
                                }
                            }
                            
                            $message = "Import completed! Success: $success_count, Duplicates: $duplicate_count, Failed: $invalid_count, Not Found: $not_student_count";
                            if (!empty($errors) && count($errors) <= 5) {
                                $message .= "<br>Errors: " . implode('<br>', $errors);
                            } elseif (!empty($errors)) {
                                $message .= "<br>First few errors: " . implode('<br>', array_slice($errors, 0, 5));
                            }
                        } else {
                            $error = "Missing required columns: " . implode(', ', $missing_headers) . ". Your file headers: " . implode(', ', $headers);
                        }
                        fclose($handle);
                    } else {
                        $error = "Could not open the CSV file.";
                    }
                    
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                } else {
                    $error = "Failed to upload file.";
                }
            } catch (Exception $e) {
                $error = "Error processing file: " . $e->getMessage();
            }
        } else {
            $error = "Invalid file type. Please upload CSV files.";
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// Handle Add Committee Member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $student_id = $_POST['student_id'] ?? null;
            $student = null;
            
            // If student_id is provided, get student details
            if ($student_id) {
                $student_stmt = $pdo->prepare("SELECT id, full_name, reg_number, email, phone, department_id, program_id, academic_year FROM users WHERE id = ? AND role = 'student' AND status = 'active' AND deleted_at IS NULL");
                $student_stmt->execute([$student_id]);
                $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception("Student not found or not active.");
                }
            }
            
            if (!$student) {
                throw new Exception("Please select a valid student from the search results. Only existing students can be added to the committee.");
            }
            
            // Check if already a committee member
            $check_stmt = $pdo->prepare("SELECT id FROM committee_members WHERE user_id = ?");
            $check_stmt->execute([$student['id']]);
            if ($check_stmt->fetch()) {
                throw new Exception("This student is already a committee member.");
            }
            
            $photo_url = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/committee/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo_url = 'assets/uploads/committee/' . $file_name;
                    }
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update user role from 'student' to committee role
            $committee_role = $_POST['role'];
            $update_user_stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $update_user_stmt->execute([$committee_role, $student['id']]);
            
            $stmt = $pdo->prepare("
                INSERT INTO committee_members (
                    user_id, name, reg_number, role, role_order, 
                    department_id, program_id, academic_year, 
                    email, phone, bio, portfolio_description,
                    photo_url, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                $student['id'],
                $student['full_name'],
                $student['reg_number'],
                $committee_role,
                $_POST['role_order'] ?? 0,
                $student['department_id'],
                $student['program_id'],
                $student['academic_year'],
                $student['email'],
                $student['phone'],
                $_POST['bio'] ?? null,
                $_POST['portfolio_description'] ?? null,
                $photo_url,
                $_POST['status'] ?? 'active',
                $user_id
            ]);
            
            $pdo->commit();
            
            $message = "Student added to committee successfully! User role has been updated.";
            header("Location: committee.php?msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error adding committee member: " . $e->getMessage();
        }
    }
    
    // Handle Edit Committee Member
    elseif ($_POST['action'] === 'edit') {
        try {
            $member_id = $_POST['member_id'];
            $photo_url = null;
            
            // Get current committee member data
            $current_member_stmt = $pdo->prepare("SELECT user_id, role FROM committee_members WHERE id = ?");
            $current_member_stmt->execute([$member_id]);
            $current_member = $current_member_stmt->fetch(PDO::FETCH_ASSOC);
            $user_id_to_update = $current_member['user_id'];
            $old_role = $current_member['role'];
            
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/committee/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo_url = 'assets/uploads/committee/' . $file_name;
                        
                        // Delete old photo
                        $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id = ?");
                        $stmt->execute([$member_id]);
                        $old = $stmt->fetch();
                        if (!empty($old['photo_url'])) {
                            $old_path = '../' . $old['photo_url'];
                            if (file_exists($old_path)) {
                                unlink($old_path);
                            }
                        }
                    }
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['role', 'role_order', 'bio', 'portfolio_description', 'status'];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field] !== '' ? $_POST[$field] : null;
                }
            }
            
            if ($photo_url) {
                $updateFields[] = "photo_url = ?";
                $params[] = $photo_url;
            }
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $member_id;
            
            $sql = "UPDATE committee_members SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Update user role if changed
            $new_role = $_POST['role'];
            if ($new_role !== $old_role && $user_id_to_update) {
                $update_user_stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $update_user_stmt->execute([$new_role, $user_id_to_update]);
            }
            
            $pdo->commit();
            
            $message = "Committee member updated successfully!";
            header("Location: committee.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error updating committee member: " . $e->getMessage();
        }
    }
    
    // Handle Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                $pdo->beginTransaction();
                
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE committee_members SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    // Also activate users
                    $user_ids_stmt = $pdo->prepare("SELECT user_id FROM committee_members WHERE id IN ($placeholders)");
                    $user_ids_stmt->execute($selected_ids);
                    $user_ids = $user_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($user_ids)) {
                        $user_placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $user_stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($user_placeholders)");
                        $user_stmt->execute($user_ids);
                    }
                    
                    $message = count($selected_ids) . " members activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE committee_members SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    // Also deactivate users
                    $user_ids_stmt = $pdo->prepare("SELECT user_id FROM committee_members WHERE id IN ($placeholders)");
                    $user_ids_stmt->execute($selected_ids);
                    $user_ids = $user_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($user_ids)) {
                        $user_placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $user_stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id IN ($user_placeholders)");
                        $user_stmt->execute($user_ids);
                    }
                    
                    $message = count($selected_ids) . " members deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get user_ids to revert their roles back to 'student'
                    $user_ids_stmt = $pdo->prepare("SELECT user_id FROM committee_members WHERE id IN ($placeholders)");
                    $user_ids_stmt->execute($selected_ids);
                    $user_ids = $user_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Delete photos first
                    $stmt = $pdo->prepare("SELECT photo_url FROM committee_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $members = $stmt->fetchAll();
                    foreach ($members as $member) {
                        if (!empty($member['photo_url'])) {
                            $photo_path = '../' . $member['photo_url'];
                            if (file_exists($photo_path)) {
                                unlink($photo_path);
                            }
                        }
                    }
                    
                    // Delete from committee_members
                    $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    // Revert user roles back to 'student' (only if they were committee members, not admin)
                    if (!empty($user_ids)) {
                        $user_placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $revert_stmt = $pdo->prepare("UPDATE users SET role = 'student' WHERE id IN ($user_placeholders) AND role != 'admin'");
                        $revert_stmt->execute($user_ids);
                    }
                    
                    $message = count($selected_ids) . " members removed from committee and roles reverted to student.";
                }
                
                $pdo->commit();
                header("Location: committee.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No members selected.";
        }
    }
}

// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT status, user_id FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_status = $current['status'] === 'active' ? 'inactive' : 'active';
        
        $stmt = $pdo->prepare("UPDATE committee_members SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $member_id]);
        
        // Sync user status
        if ($current['user_id']) {
            $user_stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $user_stmt->execute([$new_status, $current['user_id']]);
        }
        
        $pdo->commit();
        $message = "Member status updated!";
        header("Location: committee.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error toggling status";
    }
}

// Handle Delete - Remove from committee only, revert role to student
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    try {
        $pdo->beginTransaction();
        
        // Get user_id and role before deleting
        $stmt = $pdo->prepare("SELECT user_id, role, photo_url FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($member['photo_url'])) {
            $photo_path = '../' . $member['photo_url'];
            if (file_exists($photo_path)) {
                unlink($photo_path);
            }
        }
        
        // Delete from committee_members
        $stmt = $pdo->prepare("DELETE FROM committee_members WHERE id = ?");
        $stmt->execute([$member_id]);
        
        // Revert user role back to 'student' (only if not admin)
        if ($member['user_id']) {
            $user_stmt = $pdo->prepare("UPDATE users SET role = 'student' WHERE id = ? AND role != 'admin'");
            $user_stmt->execute([$member['user_id']]);
        }
        
        $pdo->commit();
        $message = "Member removed from committee successfully! Role reverted to student.";
        header("Location: committee.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error deleting member";
    }
}

// Get member for editing via AJAX
if (isset($_GET['get_member']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM committee_members WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($member);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Search student via AJAX - Only returns students (role = 'student') not already in committee
if (isset($_GET['search_student']) && isset($_GET['query'])) {
    header('Content-Type: application/json');
    $query = trim($_GET['query']);
    
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.reg_number, u.email, u.phone, u.department_id, u.program_id, u.academic_year,
                   d.name as department_name, p.name as program_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN programs p ON u.program_id = p.id
            WHERE u.role = 'student' 
            AND u.status = 'active' 
            AND u.deleted_at IS NULL
            AND u.id NOT IN (SELECT user_id FROM committee_members WHERE user_id IS NOT NULL)
            AND (u.reg_number ILIKE ? OR u.full_name ILIKE ? OR u.email ILIKE ?)
            LIMIT 10
        ");
        $search_term = "%$query%";
        $stmt->execute([$search_term, $search_term, $search_term]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($students);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get student details by ID
if (isset($_GET['get_student']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, reg_number, email, phone, department_id, program_id, academic_year
            FROM users 
            WHERE id = ? AND role = 'student' AND status = 'active' AND deleted_at IS NULL
        ");
        $stmt->execute([$_GET['id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($student);
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
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($programs);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Pagination and Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(name ILIKE ? OR email ILIKE ? OR reg_number ILIKE ? OR role ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($role_filter)) {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
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

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM committee_members WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_members = $stmt->fetchColumn();
    $total_pages = ceil($total_members / $limit);
} catch (PDOException $e) {
    $total_members = 0;
    $total_pages = 0;
}

// Get committee members with joins
try {
    $sql = "
        SELECT cm.*, 
               d.name as department_name, 
               p.name as program_name
        FROM committee_members cm
        LEFT JOIN departments d ON cm.department_id = d.id
        LEFT JOIN programs p ON cm.program_id = p.id
        WHERE $where_clause
        ORDER BY cm.role_order ASC, cm.name ASC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $committee_members = [];
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM committee_members GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_stats = [];
}

// Get departments for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Get logo path
$logo_path = '../assets/images/rp_logo.png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Committee Management - Isonga RPSU Admin</title>
    <link rel="icon" type="image/png" href="<?php echo $logo_path; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.1rem;
            color: var(--text-primary);
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

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 65px);
        }

        .sidebar {
            width: 260px;
            background: var(--card-bg);
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

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-primary);
            text-decoration: none;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .menu-item a:hover, .menu-item a.active {
            background: var(--bg-primary);
            border-left-color: var(--primary);
            color: var(--primary);
        }

        .main-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-secondary { background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.75rem; }

        /* Student Search Results */
        .student-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-md);
        }

        .student-search-result {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .student-search-result:hover {
            background: var(--bg-primary);
        }

        .student-search-result:last-child {
            border-bottom: none;
        }

        .student-result-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .student-result-details {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .search-container {
            position: relative;
            margin-bottom: 1rem;
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
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        /* Filters */
        .filters-bar {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--border-color);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .filter-group select, .search-box input {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-box input {
            width: 250px;
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .view-btn {
            padding: 0.4rem 0.8rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            color: var(--text-primary);
        }

        .view-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .bulk-actions-bar {
            background: var(--card-bg);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            border: 1px solid var(--border-color);
        }

        /* Table View */
        .members-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .members-table th,
        .members-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .members-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
        }

        .members-table tr:hover {
            background: var(--bg-primary);
        }

        .member-avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-badge.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .status-badge.inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        /* Grid View */
        .committee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .member-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: transform 0.2s;
        }

        .member-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .member-image {
            width: 100%;
            aspect-ratio: 1 / 1;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        .member-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
        }

        .member-image .placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            font-size: 4rem;
            color: rgba(255,255,255,0.8);
        }

        .member-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--card-bg);
            z-index: 2;
        }

        .member-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 4px;
            z-index: 2;
        }

        .member-info {
            padding: 1rem;
        }

        .member-name {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .member-role {
            color: var(--primary);
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .member-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.25rem;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .alert-info {
            background: #e3f2fd;
            color: #0056b3;
        }

        .image-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            margin-top: 0.5rem;
            border: 2px solid var(--border-color);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .sample-table-container {
            background: var(--bg-primary);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            overflow-x: auto;
        }

        .sample-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .sample-table th,
        .sample-table td {
            padding: 0.5rem;
            text-align: left;
            border: 1px solid var(--border-color);
        }

        .sample-table th {
            background: var(--card-bg);
            font-weight: 600;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 6px;
        }

        body.dark-mode .info-box {
            background: rgba(0, 86, 179, 0.2);
        }

        .role-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.7rem;
        }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .search-box { margin-left: 0; width: 100%; }
            .search-box input { width: 100%; }
            .members-table { font-size: 0.7rem; }
            .members-table th, .members-table td { padding: 0.5rem; }
            .role-list { grid-template-columns: 1fr; }
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
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php" class="active"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="representative.php"><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="programs.php"><i class="fas fa-graduation-cap"></i> Programs</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="content.php"><i class="fas fa-newspaper"></i> Content</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="page-header">
                <h1><i class="fas fa-user-tie"></i> Committee Management</h1>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Member</button>
                    <button class="btn btn-success" onclick="openImportModal()"><i class="fas fa-file-excel"></i> Import Excel</button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_members; ?></div>
                    <div class="stat-label">Total Members</div>
                </div>
                <?php foreach ($status_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stat['count']; ?></div>
                        <div class="stat-label"><?php echo ucfirst($stat['status']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form method="GET" class="filters-bar">
                <div class="filter-group">
                    <label>Role:</label>
                    <select name="role" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <?php foreach ($available_roles as $key => $name): ?>
                            <option value="<?php echo $key; ?>" <?php echo $role_filter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
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
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, email..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                    <?php if ($search || $role_filter || $status_filter): ?>
                        <a href="committee.php" class="btn btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
                <div class="view-toggle">
                    <button type="button" class="view-btn" id="tableViewBtn" onclick="toggleView('table')"><i class="fas fa-table"></i> Table</button>
                    <button type="button" class="view-btn" id="gridViewBtn" onclick="toggleView('grid')"><i class="fas fa-th-large"></i> Grid</button>
                </div>
            </form>

            <!-- Bulk Actions Form -->
            <form method="POST" id="bulkForm">
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

                <!-- Table View -->
                <div id="tableView" class="members-table-container">
                    <table class="members-table">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                                <th width="60">Photo</th>
                                <th>Name</th>
                                <th>Reg Number</th>
                                <th>Role</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th width="120">Actions</th>
                            </thead>
                        <tbody>
                            <?php if (empty($committee_members)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i class="fas fa-user-tie" style="font-size: 3rem; opacity: 0.5;"></i>
                                            <h3>No committee members found</h3>
                                            <p>Click "Add Member" to add a student to the committee.</p>
                                        </div>
                                                                        </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($committee_members as $member): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox"></td>
                                        <td>
                                            <?php if (!empty($member['photo_url']) && file_exists('../' . $member['photo_url'])): ?>
                                                <img src="../<?php echo htmlspecialchars($member['photo_url']); ?>" class="member-avatar-sm" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="member-avatar-sm" style="background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; border-radius: 50%; width: 40px; height: 40px;">
                                                    <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($member['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($member['reg_number'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($available_roles[$member['role']] ?? str_replace('_', ' ', $member['role'])); ?></td>
                                        <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($member['phone'] ?? '-'); ?></td>
                                        <td><span class="status-badge <?php echo $member['status']; ?>"><?php echo ucfirst($member['status']); ?></span></td>
                                        <td class="action-buttons">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $member['id']; ?>)"><i class="fas fa-edit"></i></button>
                                            <a href="?toggle_status=1&id=<?php echo $member['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle status?')"><i class="fas fa-toggle-on"></i></a>
                                            <a href="?delete=1&id=<?php echo $member['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Remove from committee? This will revert the user role to student.')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Grid View -->
                <div id="gridView" class="committee-grid" style="display: none;">
                    <?php if (!empty($committee_members)): ?>
                        <?php foreach ($committee_members as $member): ?>
                            <div class="member-card">
                                <div class="member-image">
                                    <?php if (!empty($member['photo_url']) && file_exists('../' . $member['photo_url'])): ?>
                                        <img src="../<?php echo htmlspecialchars($member['photo_url']); ?>" alt="<?php echo htmlspecialchars($member['name']); ?>">
                                    <?php else: ?>
                                        <div class="placeholder"><i class="fas fa-user-circle"></i></div>
                                    <?php endif; ?>
                                    <div class="member-status <?php echo $member['status']; ?>"><?php echo ucfirst($member['status']); ?></div>
                                    <div class="member-checkbox-wrapper">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $member['id']; ?>" class="member-checkbox">
                                    </div>
                                </div>
                                <div class="member-info">
                                    <div class="member-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                    <div class="member-role"><?php echo htmlspecialchars($available_roles[$member['role']] ?? str_replace('_', ' ', $member['role'])); ?></div>
                                    <div class="member-actions">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $member['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                                        <a href="?toggle_status=1&id=<?php echo $member['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle status?')"><i class="fas fa-toggle-on"></i></a>
                                        <a href="?delete=1&id=<?php echo $member['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Remove from committee? This will revert the user role to student.')"><i class="fas fa-trash"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <i class="fas fa-user-tie" style="font-size: 3rem; opacity: 0.5;"></i>
                            <h3>No committee members found</h3>
                            <p>Click "Add Member" to add a student to the committee.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>">Next <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Modal with Student Search -->
    <div id="memberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Committee Member</h2>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="memberForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="member_id" id="memberId" value="">
                <input type="hidden" name="student_id" id="studentId" value="">
                
                <!-- Student Search Section -->
                <div class="alert alert-info" style="margin-bottom: 1rem;">
                    <i class="fas fa-info-circle"></i> <strong>Important:</strong> Only existing students can be added to the committee. 
                    If a student is not found, please add them first in <a href="students.php" style="color: var(--primary);">Student Management</a>.
                </div>
                
                <div class="form-group search-container">
                    <label>Search Student *</label>
                    <input type="text" id="studentSearchInput" class="form-control" placeholder="Enter registration number or name..." autocomplete="off" required>
                    <div id="studentSearchResults" class="student-search-results"></div>
                </div>
                
                <div id="selectedStudentInfo" style="display: none;" class="info-box">
                    <strong><i class="fas fa-user-check"></i> Selected Student:</strong>
                    <div id="studentDetails"></div>
                </div>
                
                <div class="form-group">
                    <label>Committee Role *</label>
                    <select name="role" id="role" required>
                        <option value="">Select Role</option>
                        <?php foreach ($available_roles as $key => $name): ?>
                            <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Role Order (for display sorting)</label>
                    <input type="number" name="role_order" id="role_order" value="0" placeholder="Lower numbers appear first">
                    <small>Optional: Use to control display order (1=President, 2=Vice President, etc.)</small>
                </div>
                
                <div class="form-group">
                    <label>Bio / Profile Description</label>
                    <textarea name="bio" id="bio" rows="3" placeholder="Brief description of the member's background and responsibilities..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Portfolio Description</label>
                    <textarea name="portfolio_description" id="portfolio_description" rows="3" placeholder="Detailed description of their portfolio and duties..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Profile Photo</label>
                    <input type="file" name="photo" id="photo" accept="image/*" onchange="previewImage(this)">
                    <div id="imagePreview" class="image-preview" style="display: none;"></div>
                    <small>Recommended: Square image, at least 300x300 pixels. Max size: 5MB</small>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Add to Committee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-excel"></i> Import Committee Members</h2>
                <button type="button" class="close-modal" onclick="closeImportModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="form-group">
                    <label>Select CSV File</label>
                    <input type="file" name="excel_file" accept=".csv" required>
                    <small>Supported format: CSV only (UTF-8 encoding recommended)</small>
                </div>

                <div class="info-box">
                    <strong><i class="fas fa-info-circle"></i> Important:</strong>
                    <p>Only existing students can be added to the committee. The email must match an existing student record.</p>
                </div>

                <div class="info-box">
                    <strong><i class="fas fa-info-circle"></i> Required Columns:</strong>
                    <p><strong>email</strong> (required - must match existing student), <strong>role</strong> (required)</p>
                </div>

                <div class="sample-table-container">
                    <h4 style="margin-bottom: 0.5rem;"><i class="fas fa-table"></i> Sample Format</h4>
                    <table class="sample-table">
                        <thead>
                            <tr><th>email</th><th>role</th><th>role_order</th><th>status</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>student.email@rpsu.rw</td><td>guild_president</td><td>1</td><td>active</td></tr>
                            <tr><td>another.student@rpsu.rw</td><td>general_secretary</td><td>2</td><td>active</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="info-box">
                    <strong><i class="fas fa-download"></i> Download Sample:</strong>
                    <button type="button" class="btn btn-primary btn-sm" onclick="downloadSampleCSV()" style="margin-left: 0.5rem;">
                        <i class="fas fa-download"></i> Download Sample CSV
                    </button>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeImportModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Members</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let searchTimeout;
        let selectedStudent = null;
        let currentView = 'table';
        
        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            localStorage.setItem('theme', body.classList.contains('dark-mode') ? 'dark' : 'light');
            themeToggle.innerHTML = body.classList.contains('dark-mode') ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });

        // View Toggle
        function toggleView(view) {
            const tableView = document.getElementById('tableView');
            const gridView = document.getElementById('gridView');
            const tableBtn = document.getElementById('tableViewBtn');
            const gridBtn = document.getElementById('gridViewBtn');
            
            if (view === 'table') {
                tableView.style.display = 'block';
                gridView.style.display = 'none';
                tableBtn.classList.add('active');
                gridBtn.classList.remove('active');
                currentView = 'table';
            } else {
                tableView.style.display = 'none';
                gridView.style.display = 'grid';
                tableBtn.classList.remove('active');
                gridBtn.classList.add('active');
                currentView = 'grid';
            }
            localStorage.setItem('committee_view', view);
        }

        const savedView = localStorage.getItem('committee_view');
        if (savedView === 'grid') {
            toggleView('grid');
        } else {
            toggleView('table');
        }

        // Student Search Functionality
        const studentSearchInput = document.getElementById('studentSearchInput');
        const searchResults = document.getElementById('studentSearchResults');
        const selectedStudentInfo = document.getElementById('selectedStudentInfo');
        const studentDetails = document.getElementById('studentDetails');
        const submitBtn = document.getElementById('submitBtn');
        
        if (studentSearchInput) {
            studentSearchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    if (searchResults) searchResults.style.display = 'none';
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    fetch(`committee.php?search_student=1&query=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                console.error(data.error);
                                return;
                            }
                            
                            if (data.length === 0) {
                                if (searchResults) {
                                    searchResults.innerHTML = '<div class="student-search-result">No students found. <a href="students.php" style="color: var(--primary);">Add a student first</a></div>';
                                    searchResults.style.display = 'block';
                                }
                                return;
                            }
                            
                            if (searchResults) {
                                searchResults.innerHTML = data.map(student => `
                                    <div class="student-search-result" onclick="selectStudent(${student.id}, '${escapeHtml(student.full_name)}', '${escapeHtml(student.reg_number)}', '${escapeHtml(student.email)}', '${escapeHtml(student.phone)}', '${escapeHtml(student.department_name || '-')}', '${escapeHtml(student.program_name || '-')}', '${escapeHtml(student.academic_year || '-')}')">
                                        <div class="student-result-name">${escapeHtml(student.full_name)}</div>
                                        <div class="student-result-details">Reg: ${escapeHtml(student.reg_number)} | Email: ${escapeHtml(student.email)}</div>
                                    </div>
                                `).join('');
                                searchResults.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                        });
                }, 300);
            });
        }
        
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (studentSearchInput && searchResults && !studentSearchInput.contains(e.target) && !searchResults.contains(e.target)) {
                if (searchResults) searchResults.style.display = 'none';
            }
        });
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function selectStudent(id, name, regNumber, email, phone, dept, program, year) {
            selectedStudent = { id, name, regNumber, email, phone, dept, program, year };
            
            // Display selected student info
            if (studentDetails) {
                studentDetails.innerHTML = `
                    <strong>${escapeHtml(name)}</strong><br>
                    Reg: ${escapeHtml(regNumber)}<br>
                    Email: ${escapeHtml(email)}<br>
                    Phone: ${escapeHtml(phone || 'N/A')}<br>
                    Department: ${escapeHtml(dept)}<br>
                    Program: ${escapeHtml(program)}<br>
                    Academic Year: ${escapeHtml(year)}
                `;
            }
            if (selectedStudentInfo) selectedStudentInfo.style.display = 'block';
            
            // Set student_id field
            const studentIdField = document.getElementById('studentId');
            if (studentIdField) studentIdField.value = id;
            
            // Clear search and hide results
            if (studentSearchInput) studentSearchInput.value = name;
            if (searchResults) searchResults.style.display = 'none';
            
            // Enable submit button
            if (submitBtn) submitBtn.disabled = false;
            
            // Clear any previous error messages
            const existingAlert = document.querySelector('#memberForm .alert-danger');
            if (existingAlert) existingAlert.remove();
        }
        
        // Edit Modal functions
        function openEditModal(id) {
            event.stopPropagation();
            
            fetch(`committee.php?get_member=1&id=${id}`)
                .then(res => res.json())
                .then(member => {
                    if (member.error) {
                        console.error('Error:', member.error);
                        alert('Error loading member data');
                        return;
                    }
                    
                    document.getElementById('modalTitle').textContent = 'Edit Committee Member';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('memberId').value = member.id;
                    document.getElementById('role').value = member.role;
                    document.getElementById('role_order').value = member.role_order || 0;
                    document.getElementById('bio').value = member.bio || '';
                    document.getElementById('portfolio_description').value = member.portfolio_description || '';
                    document.getElementById('status').value = member.status;
                    
                    // Hide student search section for edit (can't change student)
                    const searchContainer = document.querySelector('.search-container');
                    if (searchContainer) searchContainer.style.display = 'none';
                    
                    const selectedInfo = document.getElementById('selectedStudentInfo');
                    const studentDetailsDiv = document.getElementById('studentDetails');
                    if (selectedInfo) selectedInfo.style.display = 'block';
                    if (studentDetailsDiv) {
                        studentDetailsDiv.innerHTML = `
                            <strong>${escapeHtml(member.name)}</strong><br>
                            Reg: ${escapeHtml(member.reg_number || '-')}<br>
                            Email: ${escapeHtml(member.email || '-')}<br>
                            Phone: ${escapeHtml(member.phone || '-')}
                        `;
                    }
                    
                    const preview = document.getElementById('imagePreview');
                    if (preview) {
                        if (member.photo_url && member.photo_url.trim() !== '') {
                            preview.innerHTML = `<img src="../${member.photo_url}" alt="Preview" style="width: 100%; height: 100%; object-fit: cover;">`;
                            preview.style.display = 'block';
                        } else {
                            preview.innerHTML = '';
                            preview.style.display = 'none';
                        }
                    }
                    
                    const editSubmitBtn = document.getElementById('submitBtn');
                    if (editSubmitBtn) editSubmitBtn.disabled = false;
                    document.getElementById('memberModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading member data');
                });
        }
        
        // Add Modal functions
        function openAddModal() {
            // Reset form for add
            document.getElementById('modalTitle').textContent = 'Add Committee Member';
            document.getElementById('formAction').value = 'add';
            document.getElementById('memberId').value = '';
            
            // Reset all form fields
            const form = document.getElementById('memberForm');
            if (form) form.reset();
            
            // Reset image preview
            const imagePreview = document.getElementById('imagePreview');
            if (imagePreview) {
                imagePreview.style.display = 'none';
                imagePreview.innerHTML = '';
            }
            
            // Reset student search section
            const studentSearch = document.getElementById('studentSearchInput');
            if (studentSearch) studentSearch.value = '';
            
            const studentIdField = document.getElementById('studentId');
            if (studentIdField) studentIdField.value = '';
            
            const selectedInfo = document.getElementById('selectedStudentInfo');
            if (selectedInfo) selectedInfo.style.display = 'none';
            
            const studentDetailsDiv = document.getElementById('studentDetails');
            if (studentDetailsDiv) studentDetailsDiv.innerHTML = '';
            
            // Make sure search container is visible
            const searchContainer = document.querySelector('.search-container');
            if (searchContainer) searchContainer.style.display = 'block';
            
            // Remove any hidden user_id field if exists
            const existingUserId = document.querySelector('input[name="user_id"]');
            if (existingUserId) existingUserId.remove();
            
            // Reset selected student
            selectedStudent = null;
            
            // Disable submit button until student is selected
            const addSubmitBtn = document.getElementById('submitBtn');
            if (addSubmitBtn) addSubmitBtn.disabled = true;
            
            // Remove any existing error/success messages
            const existingAlert = document.querySelector('#memberForm .alert-danger, #memberForm .alert-success');
            if (existingAlert) existingAlert.remove();
            
            // Show the modal
            document.getElementById('memberModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('memberModal').classList.remove('active');
            // Reset search container visibility for next add
            const searchContainer = document.querySelector('.search-container');
            if (searchContainer) searchContainer.style.display = 'block';
        }
        
        function openImportModal() {
            document.getElementById('importModal').classList.add('active');
        }
        
        function closeImportModal() {
            document.getElementById('importModal').classList.remove('active');
        }
        
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="width: 100%; height: 100%; object-fit: cover;">`;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.innerHTML = '';
                preview.style.display = 'none';
            }
        }
        
        function toggleAll(source) {
            document.querySelectorAll('.member-checkbox').forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.member-checkbox:checked').length;
            if (!action) { alert('Select an action'); return false; }
            if (checked === 0) { alert('Select members'); return false; }
            let message = '';
            if (action === 'activate') message = `Activate ${checked} member(s)?`;
            else if (action === 'deactivate') message = `Deactivate ${checked} member(s)?`;
            else if (action === 'delete') message = `Remove ${checked} member(s) from committee? This will revert their roles to student.`;
            return confirm(message);
        }
        
        function downloadSampleCSV() {
            const sampleData = [
                ['email', 'role', 'role_order', 'status'],
                ['student.email@rpsu.rw', 'guild_president', '1', 'active'],
                ['another.student@rpsu.rw', 'general_secretary', '2', 'active'],
            ];
            
            const csvContent = sampleData.map(row => {
                return row.map(cell => {
                    if (typeof cell === 'string' && (cell.includes(',') || cell.includes('"') || cell.includes('\n'))) {
                        return '"' + cell.replace(/"/g, '""') + '"';
                    }
                    return cell;
                }).join(',');
            }).join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'committee_import_sample.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
        
        // Close modals when clicking outside
        window.onclick = function(e) {
            const memberModal = document.getElementById('memberModal');
            const importModal = document.getElementById('importModal');
            if (e.target === memberModal) closeModal();
            if (e.target === importModal) closeImportModal();
        };
        
        // Prevent modal close when clicking inside modal content
        document.querySelectorAll('.modal-content').forEach(modalContent => {
            modalContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        // Add animation to cards
        const cards = document.querySelectorAll('.stat-card, .member-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.animation = `fadeInUp 0.3s ease forwards`;
            card.style.animationDelay = `${index * 0.05}s`;
        });
        
        const style = document.createElement('style');
        style.textContent = `
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
        `;
        document.head.appendChild(style);
        
        setTimeout(() => {
            cards.forEach(card => {
                card.style.opacity = '1';
            });
        }, 100);
        
        // Form validation before submit
        document.getElementById('memberForm').addEventListener('submit', function(e) {
            const action = document.getElementById('formAction').value;
            const role = document.getElementById('role').value;
            
            if (action === 'add' && !selectedStudent) {
                e.preventDefault();
                alert('Please select a student to add to the committee.');
                return false;
            }
            
            if (!role) {
                e.preventDefault();
                alert('Please select a committee role.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>