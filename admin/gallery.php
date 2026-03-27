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

// Handle Gallery Actions
$message = '';
$error = '';

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_category') {
        try {
            // Handle cover image upload
            $cover_image = null;
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/gallery/categories/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_path)) {
                        $cover_image = 'assets/uploads/gallery/categories/' . $file_name;
                    }
                }
            }
            
            // Check if category name already exists
            $stmt = $pdo->prepare("SELECT id FROM gallery_categories WHERE name = ?");
            $stmt->execute([$_POST['name']]);
            if ($stmt->fetch()) {
                throw new Exception("Category name '{$_POST['name']}' already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO gallery_categories (name, description, cover_image, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $_POST['name'],
                $_POST['description'] ?? null,
                $cover_image,
                $_POST['status'] ?? 'active',
                $user_id
            ]);
            
            $message = "Category added successfully!";
            header("Location: gallery.php?msg=" . urlencode($message));
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
            $cover_image = null;
            
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/gallery/categories/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_path)) {
                        $cover_image = 'assets/uploads/gallery/categories/' . $file_name;
                        
                        // Delete old cover image
                        $stmt = $pdo->prepare("SELECT cover_image FROM gallery_categories WHERE id = ?");
                        $stmt->execute([$category_id]);
                        $old_category = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($old_category['cover_image'])) {
                            $old_path = '../' . $old_category['cover_image'];
                            if (file_exists($old_path)) {
                                unlink($old_path);
                            }
                        }
                    }
                }
            }
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['name', 'description', 'status'];
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field];
                }
            }
            
            if ($cover_image) {
                $updateFields[] = "cover_image = ?";
                $params[] = $cover_image;
            }
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $category_id;
            
            $sql = "UPDATE gallery_categories SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Category updated successfully!";
            header("Location: gallery.php?msg=" . urlencode($message));
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
            
            // Get images in category to delete
            $stmt = $pdo->prepare("SELECT image_path FROM gallery_images WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($images as $image) {
                if (!empty($image['image_path'])) {
                    $img_path = '../' . $image['image_path'];
                    if (file_exists($img_path)) {
                        unlink($img_path);
                    }
                }
            }
            
            // Delete images
            $stmt = $pdo->prepare("DELETE FROM gallery_images WHERE category_id = ?");
            $stmt->execute([$category_id]);
            
            // Get and delete category cover
            $stmt = $pdo->prepare("SELECT cover_image FROM gallery_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($category['cover_image'])) {
                $cover_path = '../' . $category['cover_image'];
                if (file_exists($cover_path)) {
                    unlink($cover_path);
                }
            }
            
            // Delete category
            $stmt = $pdo->prepare("DELETE FROM gallery_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            
            $message = "Category and all associated images deleted successfully!";
            header("Location: gallery.php?msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error deleting category: " . $e->getMessage();
        }
    }
    
    // Handle Add Image
    elseif ($_POST['action'] === 'add_image') {
        try {
            $category_id = $_POST['category_id'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $tags = !empty($_POST['tags']) ? json_encode(array_map('trim', explode(',', $_POST['tags']))) : null;
            $featured = isset($_POST['featured']) ? 1 : 0;
            
            // Handle image upload
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select an image to upload.");
            }
            
            $upload_dir = '../assets/uploads/gallery/images/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Only image files (JPG, PNG, GIF, WEBP) are allowed.");
            }
            
            $file_name = time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            
            // Get image dimensions
            $image_info = getimagesize($_FILES['image']['tmp_name']);
            $dimensions = $image_info ? $image_info[0] . 'x' . $image_info[1] : null;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                $image_path = 'assets/uploads/gallery/images/' . $file_name;
                $file_size = $_FILES['image']['size'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO gallery_images (
                        category_id, title, description, image_path, image_name,
                        file_size, file_type, dimensions, uploaded_by, featured, tags,
                        status, uploaded_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                
                $stmt->execute([
                    $category_id,
                    $title,
                    $description,
                    $image_path,
                    $file_name,
                    $file_size,
                    $file_extension,
                    $dimensions,
                    $user_id,
                    $featured,
                    $tags
                ]);
                
                // Update category image count
                $stmt = $pdo->prepare("UPDATE gallery_categories SET image_count = image_count + 1 WHERE id = ?");
                $stmt->execute([$category_id]);
                
                $message = "Image uploaded successfully!";
                header("Location: gallery.php?tab=images&category_id=" . $category_id . "&msg=" . urlencode($message));
                exit();
            } else {
                throw new Exception("Failed to upload image.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Error adding image: " . $e->getMessage();
            error_log("Image upload error: " . $e->getMessage());
        }
    }
    
    // Handle Edit Image
    elseif ($_POST['action'] === 'edit_image') {
        try {
            $image_id = $_POST['image_id'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $tags = !empty($_POST['tags']) ? json_encode(array_map('trim', explode(',', $_POST['tags']))) : null;
            $featured = isset($_POST['featured']) ? 1 : 0;
            $status = $_POST['status'];
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['title', 'description', 'status'];
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $_POST[$field];
                }
            }
            
            $updateFields[] = "featured = ?";
            $params[] = $featured;
            
            $updateFields[] = "tags = ?";
            $params[] = $tags;
            
            $updateFields[] = "updated_at = NOW()";
            $params[] = $image_id;
            
            $sql = "UPDATE gallery_images SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Image updated successfully!";
            header("Location: gallery.php?tab=images&category_id=" . $_POST['category_id'] . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error updating image: " . $e->getMessage();
            error_log("Image update error: " . $e->getMessage());
        }
    }
    
    // Handle Delete Image
    elseif ($_POST['action'] === 'delete_image') {
        try {
            $image_id = $_POST['image_id'];
            $category_id = $_POST['category_id'];
            
            // Get image path to delete file
            $stmt = $pdo->prepare("SELECT image_path FROM gallery_images WHERE id = ?");
            $stmt->execute([$image_id]);
            $image = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($image['image_path'])) {
                $img_path = '../' . $image['image_path'];
                if (file_exists($img_path)) {
                    unlink($img_path);
                }
            }
            
            // Delete image record
            $stmt = $pdo->prepare("DELETE FROM gallery_images WHERE id = ?");
            $stmt->execute([$image_id]);
            
            // Update category image count
            $stmt = $pdo->prepare("UPDATE gallery_categories SET image_count = image_count - 1 WHERE id = ?");
            $stmt->execute([$category_id]);
            
            $message = "Image deleted successfully!";
            header("Location: gallery.php?tab=images&category_id=" . $category_id . "&msg=" . urlencode($message));
            exit();
        } catch (PDOException $e) {
            $error = "Error deleting image: " . $e->getMessage();
        }
    }
    
    // Handle Bulk Actions for Images
    elseif ($_POST['action'] === 'bulk_images') {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        $category_id = $_POST['category_id'] ?? 0;
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'activate') {
                    $stmt = $pdo->prepare("UPDATE gallery_images SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " images activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE gallery_images SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " images deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get images to delete files
                    $stmt = $pdo->prepare("SELECT image_path FROM gallery_images WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $images_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($images_to_delete as $img) {
                        if (!empty($img['image_path'])) {
                            $img_path = '../' . $img['image_path'];
                            if (file_exists($img_path)) {
                                unlink($img_path);
                            }
                        }
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM gallery_images WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " images deleted.";
                    
                    // Update category image count
                    $stmt = $pdo->prepare("UPDATE gallery_categories SET image_count = (SELECT COUNT(*) FROM gallery_images WHERE category_id = ?) WHERE id = ?");
                    $stmt->execute([$category_id, $category_id]);
                }
                header("Location: gallery.php?tab=images&category_id=" . $category_id . "&msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No images selected.";
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
                    $stmt = $pdo->prepare("UPDATE gallery_categories SET status = 'active' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories activated.";
                } elseif ($bulk_action === 'deactivate') {
                    $stmt = $pdo->prepare("UPDATE gallery_categories SET status = 'inactive' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories deactivated.";
                } elseif ($bulk_action === 'delete') {
                    // Get all images in categories to delete
                    $stmt = $pdo->prepare("SELECT image_path FROM gallery_images WHERE category_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $images_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($images_to_delete as $img) {
                        if (!empty($img['image_path'])) {
                            $img_path = '../' . $img['image_path'];
                            if (file_exists($img_path)) {
                                unlink($img_path);
                            }
                        }
                    }
                    
                    // Delete images
                    $stmt = $pdo->prepare("DELETE FROM gallery_images WHERE category_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    
                    // Get and delete category covers
                    $stmt = $pdo->prepare("SELECT cover_image FROM gallery_categories WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $categories_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($categories_to_delete as $cat) {
                        if (!empty($cat['cover_image'])) {
                            $cover_path = '../' . $cat['cover_image'];
                            if (file_exists($cover_path)) {
                                unlink($cover_path);
                            }
                        }
                    }
                    
                    // Delete categories
                    $stmt = $pdo->prepare("DELETE FROM gallery_categories WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $message = count($selected_ids) . " categories deleted.";
                }
                header("Location: gallery.php?msg=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $error = "Error performing bulk action: " . $e->getMessage();
            }
        } else {
            $error = "No categories selected.";
        }
    }
}

// Handle Status Toggle for Category
if (isset($_GET['toggle_category_status']) && isset($_GET['id'])) {
    $cat_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM gallery_categories WHERE id = ?");
        $stmt->execute([$cat_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE gallery_categories SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $cat_id]);
        
        $message = "Category status updated!";
        header("Location: gallery.php?msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling category status: " . $e->getMessage();
    }
}

// Handle Status Toggle for Image
if (isset($_GET['toggle_image_status']) && isset($_GET['id']) && isset($_GET['category_id'])) {
    $img_id = $_GET['id'];
    $category_id = $_GET['category_id'];
    try {
        $stmt = $pdo->prepare("SELECT status FROM gallery_images WHERE id = ?");
        $stmt->execute([$img_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = $current_status === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE gallery_images SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $img_id]);
        
        $message = "Image status updated!";
        header("Location: gallery.php?tab=images&category_id=" . $category_id . "&msg=" . urlencode($message));
        exit();
    } catch (PDOException $e) {
        $error = "Error toggling image status: " . $e->getMessage();
    }
}

// Get category for editing via AJAX
if (isset($_GET['get_category']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM gallery_categories WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($category);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get image for editing via AJAX
if (isset($_GET['get_image']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM gallery_images WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($image['tags'])) {
            $tags_array = json_decode($image['tags'], true);
            $image['tags_string'] = is_array($tags_array) ? implode(', ', $tags_array) : '';
        }
        echo json_encode($image);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get categories
try {
    $stmt = $pdo->query("
        SELECT c.*, 
               (SELECT COUNT(*) FROM gallery_images WHERE category_id = c.id) as actual_image_count
        FROM gallery_categories c
        ORDER BY c.name ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// Get images for selected category
$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$images = [];
if ($selected_category_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM gallery_images 
            WHERE category_id = ? 
            ORDER BY featured DESC, uploaded_at DESC
        ");
        $stmt->execute([$selected_category_id]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $images = [];
        error_log("Error fetching images: " . $e->getMessage());
    }
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM gallery_categories");
    $total_categories = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM gallery_images");
    $total_images = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM gallery_images WHERE featured = true");
    $featured_images = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    $total_categories = 0;
    $total_images = 0;
    $featured_images = 0;
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'categories';

// Get message from URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Gallery Management - Isonga RPSU Admin</title>
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
            --purple: #8b5cf6;
            
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

        /* Categories Grid */
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .category-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
            cursor: pointer;
        }

        .category-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .category-cover {
            height: 160px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            position: relative;
            overflow: hidden;
        }

        .category-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .category-cover .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .category-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--card-bg);
            color: var(--text-primary);
        }

        .category-status.active {
            background: #d4edda;
            color: #155724;
        }

        .category-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .category-status.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .category-status.inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .category-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 4px;
        }

        .category-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .category-info {
            padding: 1rem;
        }

        .category-name {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .category-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .category-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .category-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .category-actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        /* Category Header for Images Tab */
        .category-header {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .category-header-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .category-header-logo {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .category-header-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .category-header-logo .placeholder {
            font-size: 1.5rem;
            color: white;
        }

        .category-header-text h2 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .category-header-text p {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        /* Images Grid */
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .image-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .image-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .image-card.featured {
            border: 2px solid var(--warning);
        }

        .featured-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--warning);
            color: white;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            z-index: 2;
        }

        .image-preview {
            height: 200px;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .image-card:hover .image-preview img {
            transform: scale(1.05);
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .image-card:hover .image-overlay {
            opacity: 1;
        }

        .image-overlay a {
            color: white;
            font-size: 1.2rem;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.6);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .image-overlay a:hover {
            background: var(--primary);
            transform: scale(1.1);
        }

        .image-checkbox-wrapper {
            position: absolute;
            top: 12px;
            left: 12px;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 4px;
            z-index: 2;
        }

        .image-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .image-status {
            position: absolute;
            bottom: 12px;
            right: 12px;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--card-bg);
            color: var(--text-primary);
            z-index: 2;
        }

        .image-status.active {
            background: #d4edda;
            color: #155724;
        }

        .image-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .image-status.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        body.dark-mode .image-status.inactive {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .image-info {
            padding: 0.75rem;
        }

        .image-title {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .image-meta {
            font-size: 0.65rem;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
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
            min-height: 80px;
        }

        .form-group small {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .image-preview-small {
            margin-top: 0.5rem;
            width: 100px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .image-preview-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
            
            .categories-grid,
            .images-grid {
                grid-template-columns: 1fr;
            }
            
            .category-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Lightbox */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            cursor: pointer;
        }

        .lightbox.active {
            display: flex;
        }

        .lightbox-content {
            max-width: 90%;
            max-height: 90%;
        }

        .lightbox-content img {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            z-index: 2001;
        }

        .lightbox-close:hover {
            color: var(--danger);
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
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
             
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
                <li class="menu-item"><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                  <li class="menu-item"><a href="representative.php" ><i class="fas fa-user-check"></i> Class Representatives</a></li>
                <li class="menu-item"><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                <li class="menu-item"><a href="clubs.php"><i class="fas fa-chess-queen"></i> Clubs</a></li>
                <li class="menu-item"><a href="associations.php"><i class="fas fa-handshake"></i> Associations</a></li>
                <li class="menu-item"><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                <li class="menu-item"><a href="arbitration.php"><i class="fas fa-balance-scale"></i> Arbitration</a></li>
                <li class="menu-item"><a href="tickets.php"><i class="fas fa-ticket-alt"></i> Support Tickets</a></li>
                <li class="menu-item"><a href="gallery.php" class="active"><i class="fas fa-images"></i> Gallery</a></li>
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
                <h1><i class="fas fa-images"></i> Gallery Management</h1>
                <?php if ($active_tab === 'categories'): ?>
                    <button class="btn btn-primary" onclick="openAddCategoryModal()">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                <?php else: ?>
                    <button class="btn btn-primary" onclick="openAddImageModal(<?php echo $selected_category_id; ?>)">
                        <i class="fas fa-plus"></i> Add Image
                    </button>
                <?php endif; ?>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_categories; ?></div>
                    <div class="stat-label">Categories</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_images; ?></div>
                    <div class="stat-label">Total Images</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $featured_images; ?></div>
                    <div class="stat-label">Featured Images</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" onclick="switchTab('categories')">
                    <i class="fas fa-folder"></i> Categories
                </button>
                <button class="tab-btn <?php echo $active_tab === 'images' ? 'active' : ''; ?>" onclick="switchTab('images')">
                    <i class="fas fa-images"></i> Images
                </button>
            </div>

            <!-- Categories Tab -->
            <div id="categoriesTab" class="tab-pane <?php echo $active_tab === 'categories' ? 'active' : ''; ?>">
                <!-- Bulk Actions -->
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

                    <div class="categories-grid">
                        <?php if (empty($categories)): ?>
                            <div class="empty-state" style="grid-column: 1/-1;">
                                <i class="fas fa-folder-open"></i>
                                <h3>No categories found</h3>
                                <p>Click "Add Category" to create one.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <div class="category-card" onclick="viewCategoryImages(<?php echo $cat['id']; ?>)">
                                    <div class="category-cover">
                                        <?php if (!empty($cat['cover_image']) && file_exists('../' . $cat['cover_image'])): ?>
                                            <img src="../<?php echo htmlspecialchars($cat['cover_image']); ?>" alt="<?php echo htmlspecialchars($cat['name']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder">
                                                <i class="fas fa-folder"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="category-status <?php echo $cat['status']; ?>">
                                            <?php echo ucfirst($cat['status']); ?>
                                        </div>
                                        <div class="category-checkbox-wrapper" onclick="event.stopPropagation()">
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo $cat['id']; ?>" class="category-checkbox">
                                        </div>
                                    </div>
                                    <div class="category-info">
                                        <h3 class="category-name"><?php echo htmlspecialchars($cat['name']); ?></h3>
                                        <?php if (!empty($cat['description'])): ?>
                                            <p class="category-description"><?php echo htmlspecialchars(substr($cat['description'], 0, 80)); ?>...</p>
                                        <?php endif; ?>
                                        <div class="category-meta">
                                            <span><i class="fas fa-images"></i> <?php echo $cat['actual_image_count']; ?> images</span>
                                            <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($cat['created_at'])); ?></span>
                                        </div>
                                        <div class="category-actions" onclick="event.stopPropagation()">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="openEditCategoryModal(<?php echo $cat['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <a href="?toggle_category_status=1&id=<?php echo $cat['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle category status?')">
                                                <i class="fas fa-toggle-on"></i>
                                            </a>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteCategory(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Images Tab -->
            <div id="imagesTab" class="tab-pane <?php echo $active_tab === 'images' ? 'active' : ''; ?>">
                <?php if ($selected_category_id > 0 && !empty($categories)): ?>
                    <?php 
                    $selected_category = null;
                    foreach ($categories as $cat) {
                        if ($cat['id'] == $selected_category_id) {
                            $selected_category = $cat;
                            break;
                        }
                    }
                    ?>
                    <?php if ($selected_category): ?>
                        <div class="category-header">
                            <div class="category-header-info">
                                <div class="category-header-logo">
                                    <?php if (!empty($selected_category['cover_image']) && file_exists('../' . $selected_category['cover_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($selected_category['cover_image']); ?>" alt="<?php echo htmlspecialchars($selected_category['name']); ?>">
                                    <?php else: ?>
                                        <div class="placeholder">
                                            <i class="fas fa-folder"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="category-header-text">
                                    <h2><?php echo htmlspecialchars($selected_category['name']); ?></h2>
                                    <p><?php echo htmlspecialchars($selected_category['description'] ?? 'No description'); ?></p>
                                </div>
                            </div>
                            <div>
                                <a href="gallery.php?tab=categories" class="btn btn-sm">← Back to Categories</a>
                            </div>
                        </div>

                        <form method="POST" action="" id="imagesBulkForm">
                            <input type="hidden" name="action" value="bulk_images">
                            <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
                            <div class="bulk-actions-bar">
                                <select name="bulk_action" id="images_bulk_action">
                                    <option value="">Bulk Actions</option>
                                    <option value="activate">Activate</option>
                                    <option value="deactivate">Deactivate</option>
                                    <option value="delete">Delete</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkImages()">Apply</button>
                            </div>

                            <div class="images-grid">
                                <?php if (empty($images)): ?>
                                    <div class="empty-state" style="grid-column: 1/-1;">
                                        <i class="fas fa-image"></i>
                                        <h3>No images in this category</h3>
                                        <p>Click "Add Image" to upload images to this category.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($images as $img): ?>
                                        <div class="image-card <?php echo $img['featured'] ? 'featured' : ''; ?>">
                                            <?php if ($img['featured']): ?>
                                                <div class="featured-badge">
                                                    <i class="fas fa-star"></i> Featured
                                                </div>
                                            <?php endif; ?>
                                            <div class="image-preview" onclick="openLightbox('../<?php echo htmlspecialchars($img['image_path']); ?>')">
                                                <img src="../<?php echo htmlspecialchars($img['image_path']); ?>" alt="<?php echo htmlspecialchars($img['title']); ?>">
                                                <div class="image-overlay">
                                                    <a href="#" onclick="event.stopPropagation(); openLightbox('../<?php echo htmlspecialchars($img['image_path']); ?>')">
                                                        <i class="fas fa-search-plus"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="image-checkbox-wrapper">
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo $img['id']; ?>" class="image-checkbox">
                                            </div>
                                            <div class="image-status <?php echo $img['status']; ?>">
                                                <?php echo ucfirst($img['status']); ?>
                                            </div>
                                            <div class="image-info">
                                                <div class="image-title"><?php echo htmlspecialchars($img['title']); ?></div>
                                                <div class="image-meta">
                                                    <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($img['uploaded_at'])); ?></span>
                                                    <span><i class="fas fa-download"></i> <?php echo $img['downloads_count'] ?? 0; ?></span>
                                                </div>
                                                <div class="image-actions" style="margin-top: 0.5rem; display: flex; gap: 0.3rem;">
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="openEditImageModal(<?php echo $img['id']; ?>, <?php echo $selected_category_id; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?toggle_image_status=1&id=<?php echo $img['id']; ?>&category_id=<?php echo $selected_category_id; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Toggle image status?')">
                                                        <i class="fas fa-toggle-on"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteImage(<?php echo $img['id']; ?>, <?php echo $selected_category_id; ?>, '<?php echo addslashes($img['title']); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h3>Select a category to view images</h3>
                        <p>Please go to Categories tab and click on a category to view its images.</p>
                        <a href="gallery.php?tab=categories" class="btn btn-primary" style="margin-top: 1rem;">View Categories</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add/Edit Category Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="categoryModalTitle">Add Category</h2>
                <button class="close-modal" onclick="closeCategoryModal()">&times;</button>
            </div>
            <form method="POST" action="" id="categoryForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="categoryAction" value="add_category">
                <input type="hidden" name="category_id" id="categoryId" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Category Name *</label>
                        <input type="text" name="name" id="category_name" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" id="category_description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Cover Image</label>
                        <input type="file" name="cover_image" id="cover_image" accept="image/*" onchange="previewCategoryImage(this)">
                        <div id="categoryImagePreview" class="image-preview-small" style="display: none; margin-top: 0.5rem;">
                            <img id="categoryPreviewImg" src="" alt="Preview">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="category_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeCategoryModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Image Modal -->
    <div id="imageModal" class="modal">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="imageModalTitle">Add Image</h2>
                <button class="close-modal" onclick="closeImageModal()">&times;</button>
            </div>
            <form method="POST" action="" id="imageForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="imageAction" value="add_image">
                <input type="hidden" name="image_id" id="imageId" value="">
                <input type="hidden" name="category_id" id="imageCategoryId" value="<?php echo $selected_category_id; ?>">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Image Title *</label>
                        <input type="text" name="title" id="image_title" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" id="image_description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Tags (comma separated)</label>
                        <input type="text" name="tags" id="image_tags" placeholder="e.g., graduation, event, sports">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="image_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="featured" id="image_featured" value="1">
                            Featured Image
                        </label>
                    </div>
                    <div class="form-group full-width" id="imageUploadField">
                        <label>Image File *</label>
                        <input type="file" name="image" id="image_file" accept="image/*" onchange="previewImage(this)">
                        <div id="imagePreviewContainer" class="image-preview-small" style="display: none; margin-top: 0.5rem;">
                            <img id="imagePreviewImg" src="" alt="Preview">
                        </div>
                        <small id="imageFileHint">Upload JPG, PNG, GIF, WEBP (Max 5MB)</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeImageModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Image</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lightbox -->
    <div id="lightbox" class="lightbox" onclick="closeLightbox()">
        <span class="lightbox-close">&times;</span>
        <div class="lightbox-content">
            <img id="lightboxImg" src="" alt="">
        </div>
    </div>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="">
        <input type="hidden" name="category_id" id="delete_category_id" value="">
        <input type="hidden" name="image_id" id="delete_image_id" value="">
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
            if (tab === 'categories') {
                window.location.href = 'gallery.php?tab=categories';
            } else {
                window.location.href = 'gallery.php?tab=images';
            }
        }
        
        // View category images
        function viewCategoryImages(categoryId) {
            window.location.href = 'gallery.php?tab=images&category_id=' + categoryId;
        }
        
        // Category Modal functions
        function openAddCategoryModal() {
            document.getElementById('categoryModalTitle').textContent = 'Add Category';
            document.getElementById('categoryAction').value = 'add_category';
            document.getElementById('categoryId').value = '';
            document.getElementById('category_name').value = '';
            document.getElementById('category_description').value = '';
            document.getElementById('category_status').value = 'active';
            document.getElementById('categoryImagePreview').style.display = 'none';
            document.getElementById('cover_image').value = '';
            document.getElementById('categoryModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditCategoryModal(catId) {
            fetch(`gallery.php?get_category=1&id=${catId}`)
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
                    document.getElementById('category_status').value = cat.status;
                    
                    if (cat.cover_image && cat.cover_image !== 'null') {
                        const preview = document.getElementById('categoryImagePreview');
                        const previewImg = document.getElementById('categoryPreviewImg');
                        previewImg.src = '../' + cat.cover_image;
                        preview.style.display = 'block';
                    } else {
                        document.getElementById('categoryImagePreview').style.display = 'none';
                    }
                    
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
        
        function previewCategoryImage(input) {
            const preview = document.getElementById('categoryImagePreview');
            const previewImg = document.getElementById('categoryPreviewImg');
            
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
        
        function confirmDeleteCategory(catId, catName) {
            if (confirm(`Are you sure you want to delete category "${catName}"? This will also delete all images in this category.`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete_category';
                form.querySelector('[name="category_id"]').value = catId;
                form.submit();
            }
        }
        
        // Image Modal functions
        function openAddImageModal(categoryId) {
            document.getElementById('imageModalTitle').textContent = 'Add Image';
            document.getElementById('imageAction').value = 'add_image';
            document.getElementById('imageId').value = '';
            document.getElementById('image_title').value = '';
            document.getElementById('image_description').value = '';
            document.getElementById('image_tags').value = '';
            document.getElementById('image_status').value = 'active';
            document.getElementById('image_featured').checked = false;
            document.getElementById('imagePreviewContainer').style.display = 'none';
            document.getElementById('image_file').value = '';
            document.getElementById('imageUploadField').style.display = 'block';
            document.getElementById('imageFileHint').textContent = 'Upload JPG, PNG, GIF, WEBP (Max 5MB)';
            document.getElementById('imageModal').classList.add('active');
            document.body.classList.add('modal-open');
        }
        
        function openEditImageModal(imgId, categoryId) {
            fetch(`gallery.php?get_image=1&id=${imgId}`)
                .then(response => response.json())
                .then(img => {
                    if (img.error) {
                        alert('Error loading image data');
                        return;
                    }
                    document.getElementById('imageModalTitle').textContent = 'Edit Image';
                    document.getElementById('imageAction').value = 'edit_image';
                    document.getElementById('imageId').value = img.id;
                    document.getElementById('imageCategoryId').value = categoryId;
                    document.getElementById('image_title').value = img.title;
                    document.getElementById('image_description').value = img.description || '';
                    document.getElementById('image_tags').value = img.tags_string || '';
                    document.getElementById('image_status').value = img.status;
                    document.getElementById('image_featured').checked = img.featured == 1;
                    document.getElementById('imageUploadField').style.display = 'none';
                    document.getElementById('imageModal').classList.add('active');
                    document.body.classList.add('modal-open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading image data');
                });
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').classList.remove('active');
            document.body.classList.remove('modal-open');
        }
        
        function previewImage(input) {
            const preview = document.getElementById('imagePreviewContainer');
            const previewImg = document.getElementById('imagePreviewImg');
            
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
        
        function confirmDeleteImage(imgId, categoryId, imgTitle) {
            if (confirm(`Are you sure you want to delete image "${imgTitle}"?`)) {
                const form = document.getElementById('deleteForm');
                form.querySelector('[name="action"]').value = 'delete_image';
                form.querySelector('[name="image_id"]').value = imgId;
                form.querySelector('[name="category_id"]').value = categoryId;
                form.submit();
            }
        }
        
        // Lightbox
        function openLightbox(imageUrl) {
            const lightbox = document.getElementById('lightbox');
            const lightboxImg = document.getElementById('lightboxImg');
            lightboxImg.src = imageUrl;
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLightbox() {
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Bulk actions
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
        
        function confirmBulkImages() {
            const action = document.getElementById('images_bulk_action').value;
            const checked = document.querySelectorAll('.image-checkbox:checked').length;
            
            if (!action) {
                alert('Please select an action');
                return false;
            }
            
            if (checked === 0) {
                alert('Please select at least one image');
                return false;
            }
            
            return confirm(`Are you sure you want to ${action} ${checked} image(s)?`);
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const categoryModal = document.getElementById('categoryModal');
            const imageModal = document.getElementById('imageModal');
            if (event.target === categoryModal) closeCategoryModal();
            if (event.target === imageModal) closeImageModal();
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