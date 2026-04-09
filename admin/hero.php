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
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = [];
    error_log("User profile error: " . $e->getMessage());
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $id = $_POST['id'] ?? 0;
        $is_active = isset($_POST['is_active']) ? true : false;
        $image_url = '';
        
        // Handle file upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/hero/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Get file info
            $file_tmp = $_FILES['image']['tmp_name'];
            $file_name = basename($_FILES['image']['name']);
            $file_size = $_FILES['image']['size'];
            $file_type = $_FILES['image']['type'];
            
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Allow only image files
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_ext, $allowed_ext)) {
                // Generate unique filename
                $new_file_name = 'hero_' . $id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $image_url = 'assets/uploads/hero/' . $new_file_name;
                } else {
                    $message = 'Error uploading file.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Only JPG, PNG, GIF, and WebP allowed.';
                $messageType = 'error';
            }
        }
        
        if (empty($message)) {
            try {
                $stmt = $pdo->prepare("UPDATE hero SET image_url = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$image_url, $is_active, $id]);
                $message = 'Hero item updated successfully!';
                $messageType = 'success';
                
                // Refresh hero items
                $hero_items = $pdo->query("SELECT * FROM hero ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $message = 'Error updating hero item: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get all hero items
try {
    $hero_items = $pdo->query("SELECT * FROM hero ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hero_items = [];
    $message = 'Error fetching hero items: ' . $e->getMessage();
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Manage Hero Section - Isonga RPSU Management System</title>
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
            --secondary: #1e88e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            
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

        /* Header Styles */
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

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 i {
            color: var(--primary);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Hero Grid */
        .hero-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .hero-card {
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s;
        }

        .hero-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .hero-image-preview {
            height: 160px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .hero-image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .hero-image-placeholder {
            color: white;
            font-size: 3rem;
            opacity: 0.8;
        }

        .hero-card-content {
            padding: 1.5rem;
        }

        .hero-card-content h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .hero-card-content p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-header h2 i {
            color: var(--primary);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.2s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        .close-btn:hover {
            color: var(--text-primary);
            background: var(--bg-primary);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .form-group input[type="file"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.85rem;
            background: var(--bg-secondary);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,86,179,0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .modal-footer {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .btn-secondary {
            background: var(--bg-primary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .help-text {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .image-preview-container {
            height: 150px;
            background: var(--bg-primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .image-preview-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .image-preview-container .no-image {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        /* Message Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.85rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                display: none;
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
            
            .hero-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .modal-content {
                width: 95%;
            }
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

        .hero-card {
            animation: fadeInUp 0.3s ease forwards;
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
                        <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Administrator'); ?></div>
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
        <!-- Sidebar -->
        <nav class="sidebar">
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="hero.php" class="active"><i class="fas fa-images"></i> Hero Images</a></li>
                <li class="menu-item"><a href="users.php"><i class="fas fa-users"></i> User Management</a></li>
                <li class="menu-item"><a href="committee.php"><i class="fas fa-user-tie"></i> Committee</a></li>
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

        <!-- Main Content -->
        <main class="main-content">
            <div class="welcome-section">
                <h1>Manage Hero Section Images</h1>
                <p>Upload and manage images for the Quick Links section on the homepage</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>">
                    <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-images"></i> Hero Section Cards</h3>
                </div>
                <div class="card-body">
                    <div class="hero-grid">
                        <?php foreach ($hero_items as $item): ?>
                            <div class="hero-card" 
                                 data-id="<?= htmlspecialchars($item['id']) ?>"
                                 data-title="<?= htmlspecialchars($item['title']) ?>"
                                 data-image-url="<?= htmlspecialchars($item['image_url'] ?? '') ?>"
                                 data-is-active="<?= $item['is_active'] ? '1' : '0' ?>">
                                <div class="hero-image-preview">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="../<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
                                    <?php else: ?>
                                        <div class="hero-image-placeholder">
                                            <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="hero-card-content">
                                    <span class="status-badge status-<?= $item['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                    <h3><?= htmlspecialchars($item['title']) ?></h3>
                                    <p><?= htmlspecialchars($item['description']) ?></p>
                                    <button class="btn btn-primary" onclick="openEditModal(this)">
                                        <i class="fas fa-upload"></i> Upload Image
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-upload"></i> Upload Image</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="form-group">
                        <label>Card Title</label>
                        <input type="text" id="edit_title" readonly style="background: var(--bg-primary); color: var(--text-secondary);">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_image">Upload New Image</label>
                        <input type="file" name="image" id="edit_image" accept="image/*">
                        <p class="help-text">Upload JPG, PNG, GIF, or WebP image</p>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Image Preview</label>
                        <div id="imagePreview" class="image-preview-container">
                            <span class="no-image">No image</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="edit_is_active" checked>
                            <label for="edit_is_active">Active (display on homepage)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(btn) {
            // Get data from parent card
            const card = btn.closest('.hero-card');
            const id = card.dataset.id;
            const title = card.dataset.title;
            let imageUrl = card.dataset.imageUrl;
            const isActive = card.dataset.isActive === '1';
            
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_is_active').checked = isActive;
            
            // Convert relative path to absolute for preview
            if (imageUrl && imageUrl.startsWith('assets/')) {
                imageUrl = '../' + imageUrl;
            }
            
            // Update preview with current image
            updatePreview(imageUrl);
            
            // Show modal
            document.getElementById('editModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        // Update preview when image is selected
        document.getElementById('edit_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    updatePreview(e.target.result);
                };
                reader.readAsDataURL(file);
            }
        });
        
        function updatePreview(src) {
            const preview = document.getElementById('imagePreview');
            if (src) {
                preview.innerHTML = `<img src="${src}" alt="Preview">`;
            } else {
                preview.innerHTML = '<span class="no-image">No image</span>';
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const body = document.body;
        
        // Check for saved theme preference
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        // Toggle theme
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
    </script>
</body>
</html>