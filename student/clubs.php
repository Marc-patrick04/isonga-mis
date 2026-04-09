<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: student_login.php');
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];
$reg_number = $_SESSION['reg_number'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];
$academic_year = $_SESSION['academic_year'];

// Get theme preference
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $new_theme = $theme === 'light' ? 'dark' : 'light';
    setcookie('theme', $new_theme, time() + (86400 * 30), "/"); // 30 days
    header('Location: clubs.php');
    exit();
}

// Handle club membership
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_club'])) {
    $club_id = $_POST['club_id'];
    
    try {
        // Check if already a member
        $check_stmt = $pdo->prepare("SELECT id FROM club_members WHERE club_id = ? AND reg_number = ?");
        $check_stmt->execute([$club_id, $reg_number]);
        
        if ($check_stmt->fetch()) {
            $_SESSION['error_message'] = "You are already a member of this club.";
        } else {
            // Join club
            $stmt = $pdo->prepare("
                INSERT INTO club_members (club_id, user_id, reg_number, name, email, phone, department_id, program_id, academic_year, role, join_date, status)
                SELECT ?, u.id, u.reg_number, u.full_name, u.email, u.phone, u.department_id, u.program_id, u.academic_year, 'member', CURDATE(), 'active'
                FROM users u 
                WHERE u.id = ?
            ");
            $stmt->execute([$club_id, $student_id]);
            
            // Update member count
            $update_stmt = $pdo->prepare("UPDATE clubs SET members_count = members_count + 1 WHERE id = ?");
            $update_stmt->execute([$club_id]);
            
            $_SESSION['success_message'] = "Successfully joined the club!";
        }
        header('Location: clubs');
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Failed to join club. Please try again.";
    }
}

// Handle leaving club
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_club'])) {
    $club_id = $_POST['club_id'];
    
    try {
        // Check if member
        $check_stmt = $pdo->prepare("SELECT id FROM club_members WHERE club_id = ? AND reg_number = ?");
        $check_stmt->execute([$club_id, $reg_number]);
        
        if ($check_stmt->fetch()) {
            // Leave club
            $stmt = $pdo->prepare("DELETE FROM club_members WHERE club_id = ? AND reg_number = ?");
            $stmt->execute([$club_id, $reg_number]);
            
            // Update member count
            $update_stmt = $pdo->prepare("UPDATE clubs SET members_count = GREATEST(members_count - 1, 0) WHERE id = ?");
            $update_stmt->execute([$club_id]);
            
            $_SESSION['success_message'] = "Successfully left the club.";
        } else {
            $_SESSION['error_message'] = "You are not a member of this club.";
        }
        header('Location: clubs');
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Failed to leave club. Please try again.";
    }
}

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for clubs with filters
$query = "
    SELECT c.*, 
           u.full_name as created_by_name,
           cm.id as membership_id
    FROM clubs c
    LEFT JOIN users u ON c.created_by = u.id
    LEFT JOIN club_members cm ON c.id = cm.club_id AND cm.reg_number = ?
    WHERE c.status = 'active'
";

$params = [$reg_number];

// Add filters
if ($category_filter !== 'all') {
    $query .= " AND c.category = ?";
    $params[] = $category_filter;
}

if (!empty($search_query)) {
    $query .= " AND (c.name LIKE ? OR c.description LIKE ? OR c.department LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY c.members_count DESC, c.name ASC";

// Get filtered clubs
$clubs_stmt = $pdo->prepare($query);
$clubs_stmt->execute($params);
$clubs = $clubs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get student's club memberships
$my_clubs_stmt = $pdo->prepare("
    SELECT c.*, cm.role, cm.join_date
    FROM club_members cm
    JOIN clubs c ON cm.club_id = c.id
    WHERE cm.reg_number = ? AND c.status = 'active'
    ORDER BY cm.join_date DESC
");
$my_clubs_stmt->execute([$reg_number]);
$my_clubs = $my_clubs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get club categories
$categories = [
    'academic' => ['name' => 'Academic', 'icon' => 'graduation-cap', 'color' => '#3B82F6'],
    'cultural' => ['name' => 'Cultural', 'icon' => 'palette', 'color' => '#F59E0B'],
    'sports' => ['name' => 'Sports', 'icon' => 'running', 'color' => '#10B981'],
    'technical' => ['name' => 'Technical', 'icon' => 'laptop-code', 'color' => '#8B5CF6'],
    'entrepreneurship' => ['name' => 'Entrepreneurship', 'icon' => 'lightbulb', 'color' => '#06B6D4'],
    'other' => ['name' => 'Other', 'icon' => 'users', 'color' => '#6B7280']
];

// Count clubs by category
$count_all = $pdo->prepare("SELECT COUNT(*) FROM clubs WHERE status = 'active'");
$count_all->execute();
$all_count = $count_all->fetchColumn();

$count_memberships = $pdo->prepare("SELECT COUNT(*) FROM club_members WHERE reg_number = ?");
$count_memberships->execute([$reg_number]);
$memberships_count = $count_memberships->fetchColumn();

// Get recent club activities
$activities_stmt = $pdo->prepare("
    SELECT ca.*, c.name as club_name, c.category as club_category
    FROM club_activities ca
    JOIN clubs c ON ca.club_id = c.id
    WHERE ca.status = 'completed' AND c.status = 'active'
    ORDER BY ca.activity_date DESC
    LIMIT 5
");
$activities_stmt->execute();
$recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Clubs - Isonga RPSU Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #0056b3;
            --secondary-blue: #1e88e5;
            --accent-blue: #0d47a1;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --text-dark: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --gradient-primary: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary-blue) 0%, var(--primary-blue) 100%);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 8px 30px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --border-radius-lg: 20px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        [data-theme="dark"] {
            --white: #1a1a1a;
            --light-gray: #2d2d2d;
            --medium-gray: #3d3d3d;
            --dark-gray: #a0a0a0;
            --text-dark: #e9ecef;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 8px 30px rgba(0, 0, 0, 0.5);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--text-dark);
            background: var(--white);
            min-height: 100vh;
            transition: var(--transition);
        }

        .dashboard-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            background: var(--gradient-secondary);
            color: white;
            padding: 1.5rem;
            position: fixed;
            width: 280px;
            height: 100vh;
            overflow-y: auto;
            transition: var(--transition);
            z-index: 1000;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .brand-text p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            opacity: 0.7;
            margin-bottom: 0.8rem;
            font-weight: 600;
        }

        .nav-links {
            list-style: none;
        }

        .nav-links li {
            margin-bottom: 0.5rem;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1rem;
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-links a i {
            width: 20px;
            text-align: center;
        }

        /* Main Content Styles */
        .main-content {
            grid-column: 2;
            padding: 2rem;
            background: var(--light-gray);
        }

        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }

        .welcome-section h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            color: var(--dark-gray);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: 50%;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-dark);
        }

        .theme-toggle:hover {
            border-color: var(--secondary-blue);
            color: var(--secondary-blue);
            transform: translateY(-2px);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-card.active {
            border: 2px solid var(--secondary-blue);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }

        .stat-icon.all { background: rgba(30, 136, 229, 0.1); color: var(--secondary-blue); }
        .stat-icon.memberships { background: rgba(40, 167, 69, 0.1); color: var(--success); }
        .stat-icon.members { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
        .stat-icon.categories { background: rgba(255, 107, 107, 0.1); color: #ff6b6b; }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--dark-gray);
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .search-box {
            grid-column: 1 / -1;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
            background: var(--white);
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.1);
        }

        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: var(--gradient-secondary);
            color: white;
            box-shadow: 0 4px 12px rgba(30, 136, 229, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(30, 136, 229, 0.3);
        }

        .btn-secondary {
            background: var(--medium-gray);
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: var(--dark-gray);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        /* Clubs Grid */
        .clubs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .club-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }

        .club-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .club-card.member {
            border: 2px solid var(--success);
        }

        .club-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .club-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .club-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .club-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .club-description {
            color: var(--dark-gray);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .club-details {
            padding: 1.5rem;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 0.8rem;
            font-size: 0.9rem;
        }

        .detail-item i {
            width: 16px;
            color: var(--dark-gray);
        }

        .club-members {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding: 0.8rem;
            background: var(--light-gray);
            border-radius: var(--border-radius);
        }

        .members-count {
            font-weight: 600;
            color: var(--text-dark);
        }

        .club-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-block {
            flex: 1;
            justify-content: center;
        }

        /* My Clubs Section */
        .my-clubs {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .my-club-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .my-club-item:last-child {
            border-bottom: none;
        }

        .my-club-item:hover {
            background: var(--light-gray);
        }

        .my-club-info h4 {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .my-club-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Recent Activities */
        .recent-activities {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--medium-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-content h4 {
            font-weight: 600;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
        }

        .activity-meta {
            display: flex;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: var(--dark-gray);
        }

        /* Section Header */
        .section-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-gray);
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
            border-left-color: var(--success);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left-color: var(--danger);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                grid-column: 1;
            }
            
            .clubs-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .clubs-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .section-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .my-club-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .club-actions {
                flex-direction: column;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="brand">
                <div class="brand-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="brand-text">
                    <h1>Isonga</h1>
                    <p>Student Portal</p>
                </div>
            </div>

            <div class="nav-section">
                <h3 class="nav-title">Main Navigation</h3>
                <ul class="nav-links">
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="tickets.php"><i class="fas fa-ticket-alt"></i> My Tickets</a></li>
                    <li><a href="events.php"><i class="fas fa-calendar-alt"></i> Events</a></li>
                    <li><a href="news.php"><i class="fas fa-newspaper"></i> News</a></li>
                    <li><a href="clubs.php" class="active"><i class="fas fa-users"></i> Clubs</a></li>
                </ul>
            </div>

            <div class="nav-section">
                <h3 class="nav-title">Account</h3>
                <ul class="nav-links">
                    <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="welcome-section">
                    <h1>Student Clubs</h1>
                    <p>Join clubs and participate in campus activities</p>
                </div>
                <div class="header-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="toggle_theme" class="theme-toggle" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
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
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Club Statistics -->
            <div class="stats-grid">
                <div class="stat-card <?php echo $category_filter === 'all' ? 'active' : ''; ?>" onclick="window.location.href='clubs?category=all&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon all">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo $all_count; ?></div>
                    <div class="stat-label">Total Clubs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon memberships">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-number"><?php echo $memberships_count; ?></div>
                    <div class="stat-label">My Memberships</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon members">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-number">
                        <?php
                        $total_members = 0;
                        foreach ($clubs as $club) {
                            $total_members += $club['members_count'];
                        }
                        echo $total_members;
                        ?>
                    </div>
                    <div class="stat-label">Total Members</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon categories">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>

            <!-- My Clubs -->
            <?php if (!empty($my_clubs)): ?>
                <div class="my-clubs">
                    <div class="section-header">
                        <h2 class="section-title">My Clubs</h2>
                    </div>
                    <?php foreach ($my_clubs as $club): ?>
                        <div class="my-club-item">
                            <div class="my-club-info">
                                <h4><?php echo htmlspecialchars($club['name']); ?></h4>
                                <div class="my-club-meta">
                                    <span style="background: <?php echo $categories[$club['category']]['color']; ?>; color: white; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.7rem;">
                                        <i class="fas fa-<?php echo $categories[$club['category']]['icon']; ?>"></i>
                                        <?php echo $categories[$club['category']]['name']; ?>
                                    </span>
                                    <span>Member since: <?php echo date('M j, Y', strtotime($club['join_date'])); ?></span>
                                    <span>Role: <?php echo ucfirst($club['role']); ?></span>
                                </div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="club_id" value="<?php echo $club['id']; ?>">
                                <button type="submit" name="leave_club" class="btn btn-danger">
                                    <i class="fas fa-times"></i> Leave Club
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 style="margin-bottom: 1rem;">Find Clubs</h3>
                <form method="GET" action="clubs">
                    <div class="filter-grid">
                        <div class="form-group search-box">
                            <label for="search">Search Clubs</label>
                            <input type="text" id="search" name="search" class="form-control" 
                                   placeholder="Search by club name, description, or department..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" class="form-control">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $key => $category): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $category_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="clubs" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Recent Activities -->
            <?php if (!empty($recent_activities)): ?>
                <div class="recent-activities">
                    <h3 style="margin-bottom: 1rem;">Recent Club Activities</h3>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon" style="background: <?php echo $categories[$activity['club_category']]['color']; ?>; color: white;">
                                <i class="fas fa-<?php echo $categories[$activity['club_category']]['icon']; ?>"></i>
                            </div>
                            <div class="activity-content">
                                <h4><?php echo htmlspecialchars($activity['title']); ?></h4>
                                <div class="activity-meta">
                                    <span><?php echo $activity['club_name']; ?></span>
                                    <span>•</span>
                                    <span><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></span>
                                    <span>•</span>
                                    <span><?php echo ucfirst($activity['activity_type']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Clubs Grid -->
            <div class="section-header">
                <h2 class="section-title">
                    <?php echo $category_filter === 'all' ? 'All Clubs' : $categories[$category_filter]['name'] . ' Clubs'; ?>
                    <span style="font-size: 1rem; color: var(--dark-gray); margin-left: 0.5rem;">
                        (<?php echo count($clubs); ?> clubs found)
                    </span>
                </h2>
            </div>

            <?php if (empty($clubs)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No clubs found</h3>
                    <p>No clubs match your current filters. Try adjusting your search criteria or check back later for new clubs.</p>
                </div>
            <?php else: ?>
                <div class="clubs-grid">
                    <?php foreach ($clubs as $club): ?>
                        <div class="club-card <?php echo $club['membership_id'] ? 'member' : ''; ?>">
                            <div class="club-header">
                                <div class="club-category" style="background: <?php echo $categories[$club['category']]['color']; ?>; color: white;">
                                    <i class="fas fa-<?php echo $categories[$club['category']]['icon']; ?>"></i>
                                    <?php echo $categories[$club['category']]['name']; ?>
                                </div>
                                <h3 class="club-title"><?php echo htmlspecialchars($club['name']); ?></h3>
                                <div class="club-meta">
                                    <?php if ($club['department']): ?>
                                        <span><i class="fas fa-building"></i> <?php echo $club['department']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($club['established_date']): ?>
                                        <span><i class="fas fa-calendar"></i> Est. <?php echo date('Y', strtotime($club['established_date'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="club-description"><?php echo htmlspecialchars($club['description']); ?></p>
                            </div>
                            <div class="club-details">
                                <?php if ($club['meeting_schedule']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo $club['meeting_schedule']; ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($club['meeting_location']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo $club['meeting_location']; ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($club['faculty_advisor']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-user-tie"></i>
                                        <span>Advisor: <?php echo $club['faculty_advisor']; ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="club-members">
                                    <i class="fas fa-user-friends"></i>
                                    <span class="members-count"><?php echo $club['members_count']; ?> members</span>
                                </div>
                                
                                <div class="club-actions">
                                    <?php if ($club['membership_id']): ?>
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="club_id" value="<?php echo $club['id']; ?>">
                                            <button type="submit" name="leave_club" class="btn btn-danger btn-block">
                                                <i class="fas fa-times"></i> Leave Club
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="club_id" value="<?php echo $club['id']; ?>">
                                            <button type="submit" name="join_club" class="btn btn-success btn-block">
                                                <i class="fas fa-plus"></i> Join Club
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Add hover effects for club cards
        document.addEventListener('DOMContentLoaded', function() {
            const clubCards = document.querySelectorAll('.club-card');
            clubCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Add click handlers for stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                if (card.onclick) {
                    card.style.cursor = 'pointer';
                }
            });
        });
    </script>
</body>
</html>