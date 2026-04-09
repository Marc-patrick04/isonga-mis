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

// Handle Event Actions
$message = '';
$error = '';

// Get event categories
try {
    $stmt = $pdo->query("SELECT * FROM event_categories WHERE is_active = true ORDER BY name ASC");
    $event_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $event_categories = [];
    error_log("Error fetching event categories: " . $e->getMessage());
}

// Handle Add Event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            // Handle image upload
            $image_url = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/events/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        $image_url = 'assets/uploads/events/' . $file_name;
                    }
                }
            }
            
            // Prepare excerpt from description if not provided
            $excerpt = trim($_POST['excerpt'] ?? '');
            if (empty($excerpt) && !empty($_POST['description'])) {
                $excerpt = substr(strip_tags($_POST['description']), 0, 150);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO events (
                    category_id, title, description, excerpt, image_url,
                    event_date, start_time, end_time, location, organizer,
                    contact_person, contact_email, contact_phone, max_participants,
                    registered_participants, is_featured, status, registration_required,
                    registration_deadline, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                !empty($_POST['category_id']) ? $_POST['category_id'] : null,
                $_POST['title'],
                $_POST['description'],
                $excerpt,
                $image_url,
                $_POST['event_date'],
                $_POST['start_time'],
                $_POST['end_time'] ?? null,
                $_POST['location'],
                $_POST['organizer'] ?? null,
                $_POST['contact_person'] ?? null,
                $_POST['contact_email'] ?? null,
                $_POST['contact_phone'] ?? null,
                !empty($_POST['max_participants']) ? (int)$_POST['max_participants'] : null,
                !empty($_POST['registered_participants']) ? (int)$_POST['registered_participants'] : 0,
                isset($_POST['is_featured']) ? 1 : 0,
                $_POST['status'] ?? 'published',
                isset($_POST['registration_required']) ? 1 : 0,
                !empty($_POST['registration_deadline']) ? $_POST['registration_deadline'] : null,
                $user_id
            ]);
            
            $message = "Event added successfully!";
            header("Location: events.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error adding event: " . $e->getMessage();
            error_log("Event creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Event
    elseif ($_POST['action'] === 'edit') {
        try {
            $event_id = $_POST['event_id'];
            $image_url = null;
            
            // Handle image upload
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/events/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        $image_url = 'assets/uploads/events/' . $file_name;
                        
                        // Delete old image
                        $stmt = $pdo->prepare("SELECT image_url FROM events WHERE id = ?");
                        $stmt->execute([$event_id]);
                        $old_event = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($old_event['image_url'])) {
                            $old_image_path = '../' . $old_event['image_url'];
                            if (file_exists($old_image_path)) {
                                unlink($old_image_path);
                            }
                        }
                    }
                }
            }
            
            // Prepare excerpt from description if not provided
            $excerpt = trim($_POST['excerpt'] ?? '');
            if (empty($excerpt) && !empty($_POST['description'])) {
                $excerpt = substr(strip_tags($_POST['description']), 0, 150);
            }
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'category_id', 'title', 'description', 'excerpt', 'event_date',
                'start_time', 'end_time', 'location', 'organizer', 'contact_person',
                'contact_email', 'contact_phone', 'max_participants', 'registered_participants',
                'status', 'registration_deadline'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $value = $_POST[$field] !== '' ? $_POST[$field] : null;
                    $params[] = $value;
                }
            }
            
            if ($image_url) {
                $updateFields[] = "image_url = ?";
                $params[] = $image_url;
            }
            
            $updateFields[] = "is_featured = ?";
            $params[] = isset($_POST['is_featured']) ? 1 : 0;
            
            $updateFields[] = "registration_required = ?";
            $params[] = isset($_POST['registration_required']) ? 1 : 0;
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $event_id;
            
            $sql = "UPDATE events SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Event updated successfully!";
            header("Location: events.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating event: " . $e->getMessage();
            error_log("Event update error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Event
    elseif ($_POST['action'] === 'delete') {
        try {
            $event_id = $_POST['event_id'];
            
            // Get image to delete
            $stmt = $pdo->prepare("SELECT image_url FROM events WHERE id = ?");
            $stmt->execute([$event_id]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($event['image_url'])) {
                $image_path = '../' . $event['image_url'];
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            // Delete registrations first
            $stmt = $pdo->prepare("DELETE FROM event_registrations WHERE event_id = ?");
            $stmt->execute([$event_id]);
            
            // Delete event
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$event_id]);
            
            $message = "Event deleted successfully!";
            header("Location: events.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error deleting event: " . $e->getMessage();
            error_log("Event delete error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions
    elseif ($_POST['action'] === 'bulk') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'publish') {
                    $stmt = $pdo->prepare("UPDATE events SET status = 'published' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " events published.";
                } elseif ($bulk_action === 'draft') {
                    $stmt = $pdo->prepare("UPDATE events SET status = 'draft' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " events moved to draft.";
                } elseif ($bulk_action === 'cancel') {
                    $stmt = $pdo->prepare("UPDATE events SET status = 'cancelled' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " events cancelled.";
                } elseif ($bulk_action === 'feature') {
                    $stmt = $pdo->prepare("UPDATE events SET is_featured = true WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " events featured.";
                } elseif ($bulk_action === 'unfeature') {
                    $stmt = $pdo->prepare("UPDATE events SET is_featured = false WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " events unfeatured.";
                } elseif ($bulk_action === 'delete') {
                    // Get images to delete
                    $stmt = $pdo->prepare("SELECT image_url FROM events WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $events_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($events_to_delete as $evt) {
                        if (!empty($evt['image_url'])) {
                            $image_path = '../' . $evt['image_url'];
                            if (file_exists($image_path)) {
                                unlink($image_path);
                            }
                        }
                    }
                    
                    // Delete registrations
                    $stmt = $pdo->prepare("DELETE FROM event_registrations WHERE event_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    // Delete events
                    $stmt = $pdo->prepare("DELETE FROM events WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " events deleted.";
                }
                header("Location: events.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No events selected.";
        }
    }
    
    // Handle Add Category
    elseif ($_POST['action'] === 'add_category') {
        try {
            $name = trim($_POST['name']);
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)));
            $description = trim($_POST['description'] ?? '');
            $color = $_POST['color'] ?? '#0056b3';
            $icon = $_POST['icon'] ?? 'calendar';
            
            // Check if category exists
            $stmt = $pdo->prepare("SELECT id FROM event_categories WHERE name = ? OR slug = ?");
            $stmt->execute([$name, $slug]);
            if ($stmt->fetch()) {
                throw new Exception("Category name already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO event_categories (name, slug, description, color, icon, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, true, NOW())
            ");
            $stmt->execute([$name, $slug, $description, $color, $icon]);
            
            $message = "Category added successfully!";
            header("Location: events.php?tab=categories&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding category: " . $e->getMessage();
            error_log("Category creation error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Category
    elseif ($_POST['action'] === 'edit_category') {
        try {
            $category_id = $_POST['category_id'];
            $name = trim($_POST['name']);
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)));
            $description = trim($_POST['description'] ?? '');
            $color = $_POST['color'] ?? '#0056b3';
            $icon = $_POST['icon'] ?? 'calendar';
            $is_active = isset($_POST['is_active']) ? true : false;
            
            $stmt = $pdo->prepare("
                UPDATE event_categories 
                SET name = ?, slug = ?, description = ?, color = ?, icon = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $slug, $description, $color, $icon, $is_active, $category_id]);
            
            $message = "Category updated successfully!";
            header("Location: events.php?tab=categories&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating category: " . $e->getMessage();
            error_log("Category update error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Category
    elseif ($_POST['action'] === 'delete_category') {
        try {
            $category_id = $_POST['category_id'];
            
            // Check if category has events
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $event_count = $stmt->fetchColumn();
            
            if ($event_count > 0) {
                throw new Exception("Cannot delete category with $event_count associated events.");
            }
            
            $stmt = $pdo->prepare("DELETE FROM event_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            
            $message = "Category deleted successfully!";
            header("Location: events.php?tab=categories&msg=" . urlencode($message));
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error deleting category: " . $e->getMessage();
            error_log("Category delete error: " . $e->getMessage());
        }
    }
    
    // Handle Bulk Actions for Categories
    elseif ($_POST['action'] === 'bulk_categories') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE event_categories SET is_active = true WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE event_categories SET is_active = false WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Check if categories have events
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE category_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $event_count = $stmt->fetchColumn();
                    
                    if ($event_count > 0) {
                        throw new Exception("Cannot delete categories with associated events.");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM event_categories WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories deleted.";
                }
                header("Location: events.php?tab=categories&msg=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No categories selected.";
        }
    }
}

// Handle Status Toggle for Event
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $event_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'published' ? 'draft' : 'published';
        $stmt = $pdo->prepare("UPDATE events SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $event_id]);
        
        $message = "Event status updated successfully!";
        header("Location: events.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling event status: " . $e->getMessage();
    }
}

// Handle Featured Toggle for Event
if (isset($_GET['toggle_featured']) && isset($_GET['id'])) {
    $event_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT is_featured FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $current_featured = $stmt->fetchColumn();
        
        $new_featured = $current_featured == true ? false : true;
        $stmt = $pdo->prepare("UPDATE events SET is_featured = ? WHERE id = ?");
        $stmt->execute([$new_featured, $event_id]);
        
        $message = "Event featured status updated!";
        header("Location: events.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling featured status: " . $e->getMessage();
    }
}

// Get event for editing via AJAX
if (isset($_GET['get_event']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($event);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get category for editing via AJAX
if (isset($_GET['get_category']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM event_categories WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($category);
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
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$featured_filter = $_GET['featured'] ?? '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(title ILIKE ? OR description ILIKE ? OR location ILIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($category_filter)) {
    $where_conditions[] = "category_id = ?";
    $params[] = $category_filter;
}

if ($featured_filter === 'yes') {
    $where_conditions[] = "is_featured = true";
} elseif ($featured_filter === 'no') {
    $where_conditions[] = "is_featured = false";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM events WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_events = $stmt->fetchColumn();
    $total_pages = ceil($total_events / $limit);
} catch (PDOException $e) {
    $total_events = 0;
    $total_pages = 0;
}

// Get events with joins
try {
    $sql = "
        SELECT e.*, c.name as category_name, c.color as category_color, c.icon as category_icon
        FROM events e
        LEFT JOIN event_categories c ON e.category_id = c.id
        WHERE $where_clause
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $events = [];
    error_log("Events fetch error: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM events GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE event_date >= CURRENT_DATE AND status = 'published'");
    $upcoming_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE is_featured = true");
    $featured_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM event_categories WHERE is_active = true");
    $categories_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    $status_stats = [];
    $upcoming_count = 0;
    $featured_count = 0;
    $categories_count = 0;
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'events';

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Define icon options for categories
$icon_options = [
    'calendar' => 'Calendar',
    'calendar-alt' => 'Calendar Alt',
    'calendar-check' => 'Calendar Check',
    'calendar-week' => 'Calendar Week',
    'clock' => 'Clock',
    'chalkboard-user' => 'Chalkboard',
    'microphone' => 'Microphone',
    'music' => 'Music',
    'futbol' => 'Sports',
    'book' => 'Book',
    'laptop' => 'Laptop',
    'users' => 'Users',
    'user-graduate' => 'Graduation',
    'party-horn' => 'Party',
    'trophy' => 'Trophy',
    'heart' => 'Heart',
    'star' => 'Star'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Events Management - Isonga RPSU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Light Mode (Default) */
        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --primary-light: #4d8be6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --secondary: #6b7280;
            --purple: #8b5cf6;
            --pink: #ec489a;
            
            /* Light Mode Colors */
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

        /* Dark Mode */
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

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
            position: relative;
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
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

        /* Events Grid */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .event-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
        }

        .event-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .event-card.featured {
            border: 2px solid var(--warning);
        }

        .event-image {
            height: 180px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            position: relative;
            overflow: hidden;
        }

        .event-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .event-image .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .featured-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--warning);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 2;
        }

        .event-status {
            position: absolute;
            bottom: 12px;
            right: 12px;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--card-bg);
            color: var(--text-primary);
            z-index: 2;
        }

        .event-status.published {
            background: #d4edda;
            color: #155724;
        }

        .event-status.draft {
            background: #f8d7da;
            color: #721c24;
        }

        .event-status.cancelled {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .event-status.published {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .event-status.draft {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        body.dark-mode .event-status.cancelled {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        .event-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 4px;
            z-index: 2;
        }

        .event-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .event-info {
            padding: 1rem;
        }

        .event-category {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .event-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .event-date {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .event-date i {
            width: 14px;
            color: var(--primary);
        }

        .event-location {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .event-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin: 0.75rem 0;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .event-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .event-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .event-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        /* Categories Table */
        .categories-table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .categories-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .categories-table th,
        .categories-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .categories-table th {
            background: var(--bg-primary);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
        }

        .category-color {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: inline-block;
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
            max-width: 800px;
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
            min-height: 100px;
        }

        .form-group small {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .image-preview {
            margin-top: 0.5rem;
            width: 120px;
            height: 120px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
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
            
            .events-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .event-actions {
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
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php" class="active"><i class="fas fa-calendar-alt"></i> Events</a></li>
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
                <h1><i class="fas fa-calendar-alt"></i> Events Management</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add Event
                </button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_events; ?></div>
                    <div class="stat-label">Total Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $upcoming_count; ?></div>
                    <div class="stat-label">Upcoming</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $featured_count; ?></div>
                    <div class="stat-label">Featured</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $categories_count; ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn <?php echo $active_tab === 'events' ? 'active' : ''; ?>" onclick="switchTab('events')">
                    <i class="fas fa-list"></i> Events
                </button>
                <button class="tab-btn <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" onclick="switchTab('categories')">
                    <i class="fas fa-tags"></i> Categories
                </button>
            </div>

            <!-- Events Tab -->
            <div id="eventsTab" class="tab-pane <?php echo $active_tab === 'events' ? 'active' : ''; ?>">
                <!-- Filters -->
                <form method="GET" action="" class="filters-bar">
                    <input type="hidden" name="tab" value="events">
                    <div class="filter-group">
                        <label>Status:</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Category:</label>
                        <select name="category" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($event_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Featured:</label>
                        <select name="featured" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="yes" <?php echo $featured_filter === 'yes' ? 'selected' : ''; ?>>Featured</option>
                            <option value="no" <?php echo $featured_filter === 'no' ? 'selected' : ''; ?>>Not Featured</option>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search by title, location..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                        <?php if ($search || $status_filter || $category_filter || $featured_filter): ?>
                            <a href="events.php?tab=events" class="btn btn-sm">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Bulk Actions -->
                <form method="POST" action="" id="bulkForm">
                    <input type="hidden" name="action" value="bulk">
                    <div class="bulk-actions-bar">
                        <select name="bulk_action" id="bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="publish">Publish</option>
                            <option value="draft">Move to Draft</option>
                            <option value="cancel">Cancel</option>
                            <option value="feature">Feature</option>
                            <option value="unfeature">Unfeature</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Apply</button>
                    </div>

                    <div class="events-grid">
                        <?php if (empty($events)): ?>
                            <div class="empty-state" style="grid-column: 1/-1;">
                                <i class="fas fa-calendar-alt"></i>
                                <h3>No events found</h3>
                                <p>Click "Add Event" to create one.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <div class="event-card <?php echo $event['is_featured'] ? 'featured' : ''; ?>">
                                    <div class="event-image">
                                        <?php if (!empty($event['image_url']) && file_exists('../' . $event['image_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($event['image_url']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder">
                                                <i class="fas fa-calendar-alt"></i>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($event['is_featured']): ?>
                                            <div class="featured-badge">
                                                <i class="fas fa-star"></i> Featured
                                            </div>
                                        <?php endif; ?>
                                        <div class="event-status <?php echo $event['status']; ?>">
                                            <?php echo ucfirst($event['status']); ?>
                                        </div>
                                        <div class="event-checkbox-wrapper" onclick="event.stopPropagation()">
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo $event['id']; ?>" class="event-checkbox">
                                        </div>
                                    </div>
                                    <div class="event-info">
                                        <?php if (!empty($event['category_name'])): ?>
                                            <span class="event-category" style="background: <?php echo $event['category_color'] ?? '#0056b3'; ?>20; color: <?php echo $event['category_color'] ?? '#0056b3'; ?>;">
                                                <i class="fas fa-<?php echo $event['category_icon'] ?? 'calendar'; ?>"></i> <?php echo htmlspecialchars($event['category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                        <div class="event-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('l, M j, Y', strtotime($event['event_date'])); ?>
                                            <?php if (!empty($event['start_time'])): ?>
                                                <span><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($event['start_time'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="event-location">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?>
                                        </div>
                                        <div class="event-description">
                                            <?php echo htmlspecialchars(substr($event['excerpt'] ?? strip_tags($event['description']), 0, 100)); ?>...
                                        </div>
                                        <div class="event-meta">
                                            <span><i class="fas fa-users"></i> <?php echo $event['registered_participants'] ?? 0; ?> / <?php echo $event['max_participants'] ?? '∞'; ?></span>
                                            <?php if (!empty($event['organizer'])): ?>
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($event['organizer']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="event-actions" onclick="event.stopPropagation()">
                                            <a href="?toggle_status=1&id=<?php echo $event['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle event status?')">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                            <a href="?toggle_featured=1&id=<?php echo $event['id']; ?>" class="btn btn-info btn-sm" onclick="return confirm('Toggle featured status?')">
                                                <i class="fas fa-star"></i>
                                            </a>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal(<?php echo $event['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteEvent(<?php echo $event['id']; ?>, '<?php echo addslashes($event['title']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page-1; ?>&tab=events&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&category=<?php echo $category_filter; ?>&featured=<?php echo $featured_filter; ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&tab=events&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&category=<?php echo $category_filter; ?>&featured=<?php echo $featured_filter; ?>" 
                               class="<?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page+1; ?>&tab=events&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&category=<?php echo $category_filter; ?>&featured=<?php echo $featured_filter; ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Categories Tab -->
            <div id="categoriesTab" class="tab-pane <?php echo $active_tab === 'categories' ? 'active' : ''; ?>">
                <div class="page-header" style="margin-bottom: 1rem;">
                    <h2><i class="fas fa-tags"></i> Event Categories</h2>
                    <button class="btn btn-primary" onclick="openAddCategoryModal()">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>

                <form method="POST" action="" id="categoriesBulkForm">
                    <input type="hidden" name="action" value="bulk_categories">
                    <div class="bulk-actions-bar">
                        <select name="bulk_action" id="categories_bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkCategories()">Apply</button>
                    </div>

                    <div class="categories-table-container">
                        <table class="categories-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="select-all-cats" onclick="toggleAllCategories(this)"></th>
                                    <th>Icon</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Description</th>
                                    <th>Color</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                 </thead>
                            <tbody>
                                <?php if (empty($event_categories)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="fas fa-tags"></i>
                                                <h3>No categories found</h3>
                                                <p>Click "Add Category" to create one.</p>
                                            </div>
                                         </td>
                                     </tr>
                                <?php else: ?>
                                    <?php foreach ($event_categories as $cat): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $cat['id']; ?>" class="category-checkbox"></td>
                                            <td><i class="fas fa-<?php echo $cat['icon']; ?>"></i></td>
                                            <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                                            <td><code><?php echo htmlspecialchars($cat['slug']); ?></code></td>
                                            <td><?php echo htmlspecialchars(substr($cat['description'] ?? '', 0, 50)); ?></td>
                                            <td><span class="category-color" style="background: <?php echo $cat['color']; ?>;"></span> <?php echo $cat['color']; ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $cat['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="action-buttons">
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openEditCategoryModal(<?php echo $cat['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if (!$cat['is_active']): ?>
                                                    <a href="?toggle_category=1&id=<?php echo $cat['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Activate this category?')">
                                                        <i class="fas fa-check-circle"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteCategory(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- Add/Edit Event Modal -->
    <div id="eventModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="eventModalTitle">Add Event</h2>
                <button class="close-modal" onclick="closeEventModal()">&times;</button>
            </div>
            <form method="POST" action="" id="eventForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="eventAction" value="add">
                <input type="hidden" name="event_id" id="eventId" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Title *</label>
                        <input type="text" name="title" id="event_title" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" id="event_category">
                            <option value="">Select Category</option>
                            <?php foreach ($event_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="event_status">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Event Date *</label>
                        <input type="date" name="event_date" id="event_date" required>
                    </div>
                    <div class="form-group">
                        <label>Start Time</label>
                        <input type="time" name="start_time" id="event_start_time">
                    </div>
                    <div class="form-group">
                        <label>End Time</label>
                        <input type="time" name="end_time" id="event_end_time">
                    </div>
                    <div class="form-group">
                        <label>Location *</label>
                        <input type="text" name="location" id="event_location" required>
                    </div>
                    <div class="form-group">
                        <label>Organizer</label>
                        <input type="text" name="organizer" id="event_organizer">
                    </div>
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="event_contact_person">
                    </div>
                    <div class="form-group">
                        <label>Contact Email</label>
                        <input type="email" name="contact_email" id="event_contact_email">
                    </div>
                    <div class="form-group">
                        <label>Contact Phone</label>
                        <input type="text" name="contact_phone" id="event_contact_phone">
                    </div>
                    <div class="form-group">
                        <label>Max Participants</label>
                        <input type="number" name="max_participants" id="event_max_participants" placeholder="Leave blank for unlimited">
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <input type="file" name="image" id="event_image" accept="image/*" onchange="previewEventImage(this)">
                        <div id="eventImagePreview" class="image-preview" style="display: none;">
                            <img id="eventPreviewImg" src="" alt="Preview">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Registration Deadline</label>
                        <input type="date" name="registration_deadline" id="event_registration_deadline">
                    </div>
                    <div class="form-group full-width">
                        <label>Description *</label>
                        <textarea name="description" id="event_description" rows="4" required></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Excerpt (Short Description)</label>
                        <textarea name="excerpt" id="event_excerpt" rows="2" placeholder="Leave blank to auto-generate from description"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_featured" id="event_is_featured" value="1">
                            Featured Event
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="registration_required" id="event_registration_required" value="1">
                            Registration Required
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeEventModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Event</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Category Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="categoryModalTitle">Add Category</h2>
                <button class="close-modal" onclick="closeCategoryModal()">&times;</button>
            </div>
            <form method="POST" action="" id="categoryForm">
                <input type="hidden" name="action" id="categoryAction" value="add_category">
                <input type="hidden" name="category_id" id="categoryId" value="">
                
                <div class="form-group">
                    <label>Category Name *</label>
                    <input type="text" name="name" id="category_name" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="category_description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="color" name="color" id="category_color" value="#0056b3">
                </div>
                <div class="form-group">
                    <label>Icon</label>
                    <select name="icon" id="category_icon">
                        <?php foreach ($icon_options as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="category_is_active" value="1" checked>
                        Active
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeCategoryModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="">
        <input type="hidden" name="event_id" id="delete_event_id" value="">
        <input type="hidden" name="category_id" id="delete_category_id" value="">
    </form>

    <script>
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
        
        // Tab switching
        function switchTab(tab) {
            if (tab === 'events') {
                window.location.href = 'events.php?tab=events';
            } else {
                window.location.href = 'events.php?tab=categories';
            }
        }
        
        // Event Modal functions
        function openAddModal() {
            document.getElementById('eventModalTitle').textContent = 'Add Event';
            document.getElementById('eventAction').value = 'add';
            document.getElementById('eventId').value = '';
            document.getElementById('eventForm').reset();
            document.getElementById('eventImagePreview').style.display = 'none';
            document.getElementById('eventModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditModal(eventId) {
            fetch(`events.php?get_event=1&id=${eventId}`)
                .then(response => response.json())
                .then(event => {
                    if (event.error) {
                        alert('Error loading event data');
                        return;
                    }
                    document.getElementById('eventModalTitle').textContent = 'Edit Event';
                    document.getElementById('eventAction').value = 'edit';
                    document.getElementById('eventId').value = event.id;
                    document.getElementById('event_title').value = event.title;
                    document.getElementById('event_category').value = event.category_id || '';
                    document.getElementById('event_status').value = event.status;
                    document.getElementById('event_date').value = event.event_date;
                    document.getElementById('event_start_time').value = event.start_time || '';
                    document.getElementById('event_end_time').value = event.end_time || '';
                    document.getElementById('event_location').value = event.location;
                    document.getElementById('event_organizer').value = event.organizer || '';
                    document.getElementById('event_contact_person').value = event.contact_person || '';
                    document.getElementById('event_contact_email').value = event.contact_email || '';
                    document.getElementById('event_contact_phone').value = event.contact_phone || '';
                    document.getElementById('event_max_participants').value = event.max_participants || '';
                    document.getElementById('event_registration_deadline').value = event.registration_deadline || '';
                    document.getElementById('event_description').value = event.description;
                    document.getElementById('event_excerpt').value = event.excerpt || '';
                    document.getElementById('event_is_featured').checked = event.is_featured == 1;
                    document.getElementById('event_registration_required').checked = event.registration_required == 1;
                    
                    if (event.image_url && event.image_url !== 'null') {
                        const preview = document.getElementById('eventImagePreview');
                        const previewImg = document.getElementById('eventPreviewImg');
                        previewImg.src = '../' + event.image_url;
                        preview.style.display = 'block';
                    } else {
                        document.getElementById('eventImagePreview').style.display = 'none';
                    }
                    
                    document.getElementById('eventModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading event data');
                });
        }
        
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function previewEventImage(input) {
            const preview = document.getElementById('eventImagePreview');
            const previewImg = document.getElementById('eventPreviewImg');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }
        
        function confirmDeleteEvent(eventId, eventTitle) {
            if (confirm(`Are you sure you want to delete event "${eventTitle}"? This will also delete all registrations.`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete';
                form.querySelector('[name="event_id"]').value = eventId;
                form.submit();
            }
        }
        
        // Category Modal functions
        function openAddCategoryModal() {
            document.getElementById('categoryModalTitle').textContent = 'Add Category';
            document.getElementById('categoryAction').value = 'add_category';
            document.getElementById('categoryId').value = '';
            document.getElementById('category_name').value = '';
            document.getElementById('category_description').value = '';
            document.getElementById('category_color').value = '#0056b3';
            document.getElementById('category_icon').value = 'calendar';
            document.getElementById('category_is_active').checked = true;
            document.getElementById('categoryModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditCategoryModal(catId) {
            fetch(`events.php?get_category=1&id=${catId}`)
                .then(response => response.json())
                .then(cat => {
                    if (cat.error) {
                        alert('Error loading category data');
                        return;
                    }
                    document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                    document.getElementById('categoryAction').value = 'edit_category';
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('category_name').value = cat.name;
                    document.getElementById('category_description').value = cat.description || '';
                    document.getElementById('category_color').value = cat.color;
                    document.getElementById('category_icon').value = cat.icon;
                    document.getElementById('category_is_active').checked = cat.is_active == true;
                    document.getElementById('categoryModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading category data');
                });
        }
        
        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function confirmDeleteCategory(catId, catName) {
            if (confirm(`Are you sure you want to delete category "${catName}"? This will not delete events, but they will become uncategorized.`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete_category';
                form.querySelector('[name="category_id"]').value = catId;
                form.submit();
            }
        }
        
        // Bulk actions
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.event-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function toggleAllCategories(source) {
            const checkboxes = document.querySelectorAll('.category-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function confirmBulk() {
            const action = document.getElementById('bulk_action').value;
            const checked = document.querySelectorAll('.event-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one event');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} event(s)?`);
        }
        
        function confirmBulkCategories() {
            const action = document.getElementById('categories_bulk_action').value;
            const checked = document.querySelectorAll('.category-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one category');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} category(s)?`);
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const eventModal = document.getElementById('eventModal');
            const categoryModal = document.getElementById('categoryModal');
            if (event.target === eventModal) closeEventModal();
            if (event.target === categoryModal) closeCategoryModal();
        }
        
        // Prevent modal content click from bubbling
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>