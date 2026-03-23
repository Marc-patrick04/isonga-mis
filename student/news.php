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
    header('Location: news.php');
    exit();
}

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for news with filters
$query = "
    SELECT n.*, 
           nc.name as category_name,
           nc.color as category_color,
           nc.icon as category_icon,
           u.full_name as author_name,
           u.role as author_role
    FROM news n
    LEFT JOIN news_categories nc ON n.category_id = nc.id
    LEFT JOIN users u ON n.author_id = u.id
    WHERE n.status = 'published'
";

$params = [];

// Add filters
if ($category_filter !== 'all') {
    $query .= " AND n.category_id = ?";
    $params[] = $category_filter;
}

if (!empty($search_query)) {
    $query .= " AND (n.title LIKE ? OR n.content LIKE ? OR n.excerpt LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY n.is_featured DESC, n.created_at DESC";

// Get filtered news
$news_stmt = $pdo->prepare($query);
$news_stmt->execute($params);
$news = $news_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get news categories for filters
$categories_stmt = $pdo->prepare("SELECT * FROM news_categories WHERE is_active = 1 ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count news by category
$count_all = $pdo->prepare("SELECT COUNT(*) FROM news WHERE status = 'published'");
$count_all->execute();
$all_count = $count_all->fetchColumn();

$count_featured = $pdo->prepare("SELECT COUNT(*) FROM news WHERE is_featured = 1 AND status = 'published'");
$count_featured->execute();
$featured_count = $count_featured->fetchColumn();

// Get featured news
$featured_news_stmt = $pdo->prepare("
    SELECT n.*, nc.name as category_name, nc.color as category_color, nc.icon as category_icon
    FROM news n
    LEFT JOIN news_categories nc ON n.category_id = nc.id
    WHERE n.is_featured = 1 AND n.status = 'published'
    ORDER BY n.created_at DESC
    LIMIT 3
");
$featured_news_stmt->execute();
$featured_news = $featured_news_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get most viewed news
$popular_news_stmt = $pdo->prepare("
    SELECT n.*, nc.name as category_name, nc.color as category_color
    FROM news n
    LEFT JOIN news_categories nc ON n.category_id = nc.id
    WHERE n.status = 'published'
    ORDER BY n.views_count DESC
    LIMIT 5
");
$popular_news_stmt->execute();
$popular_news = $popular_news_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus News - Isonga RPSU Management System</title>
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
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
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
        .stat-icon.featured { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
        .stat-icon.views { background: rgba(40, 167, 69, 0.1); color: var(--success); }
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

        /* News Grid */
        .news-grid {
            display: grid;
            gap: 2rem;
        }

        .news-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }

        .news-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .news-card.featured {
            border: 2px solid var(--warning);
        }

        .news-image {
            width: 100%;
            height: 300px;
            background: var(--gradient-secondary);
            position: relative;
            overflow: hidden;
        }

        .news-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .news-image .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            opacity: 0.7;
        }

        .news-category {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: rgba(255, 255, 255, 0.9);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .news-content {
            padding: 2rem;
        }

        .news-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .news-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.3;
            color: var(--text-dark);
        }

        .news-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--dark-gray);
        }

        .news-excerpt {
            color: var(--dark-gray);
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .news-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--medium-gray);
        }

        .news-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--dark-gray);
        }

        .news-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        /* Popular News Sidebar */
        .popular-news {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .popular-news-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--medium-gray);
        }

        .popular-news-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--medium-gray);
            transition: var(--transition);
        }

        .popular-news-item:last-child {
            border-bottom: none;
        }

        .popular-news-item:hover {
            background: var(--light-gray);
            margin: 0 -1rem;
            padding: 1rem;
        }

        .popular-news-image {
            width: 80px;
            height: 60px;
            border-radius: var(--border-radius);
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .popular-news-content {
            flex: 1;
        }

        .popular-news-content h4 {
            font-weight: 600;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            line-height: 1.3;
        }

        .popular-news-meta {
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
            
            .news-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .news-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .news-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        /* Two Column Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .content-layout {
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
                    <li><a href="#" class="active"><i class="fas fa-newspaper"></i> News</a></li>
                    <li><a href="clubs.php"><i class="fas fa-users"></i> Clubs</a></li>
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
                    <h1>Campus News</h1>
                    <p>Stay updated with the latest news and announcements</p>
                </div>
                <div class="header-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="toggle_theme" class="theme-toggle" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- News Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon all">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-number"><?php echo $all_count; ?></div>
                    <div class="stat-label">Total News</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon featured">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-number"><?php echo $featured_count; ?></div>
                    <div class="stat-label">Featured News</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon views">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-number">
                        <?php
                        $total_views = 0;
                        foreach ($news as $news_item) {
                            $total_views += $news_item['views_count'];
                        }
                        echo $total_views;
                        ?>
                    </div>
                    <div class="stat-label">Total Views</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon categories">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 style="margin-bottom: 1rem;">Find News</h3>
                <form method="GET" action="news.php">
                    <div class="filter-grid">
                        <div class="form-group search-box">
                            <label for="search">Search News</label>
                            <input type="text" id="search" name="search" class="form-control" 
                                   placeholder="Search by title, content, or excerpt..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" class="form-control">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
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
                        <a href="news.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <div class="content-layout">
                <!-- Main News Content -->
                <div>
                    <!-- Featured News -->
                    <?php if (!empty($featured_news)): ?>
                        <div class="section-header">
                            <h2 class="section-title">Featured News</h2>
                        </div>
                        
                        <div class="news-grid">
                            <?php foreach ($featured_news as $news_item): ?>
                                <div class="news-card featured">
                                    <div class="news-image">
                                        <?php if ($news_item['image_url']): ?>
                                            <img src="<?php echo htmlspecialchars($news_item['image_url']); ?>" alt="<?php echo htmlspecialchars($news_item['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder">
                                                <i class="fas fa-<?php echo $news_item['category_icon'] ?? 'newspaper'; ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="news-category" style="color: <?php echo $news_item['category_color']; ?>">
                                            <i class="fas fa-<?php echo $news_item['category_icon']; ?>"></i>
                                            <?php echo $news_item['category_name']; ?>
                                        </div>
                                    </div>
                                    <div class="news-content">
                                        <div class="news-header">
                                            <h2 class="news-title"><?php echo htmlspecialchars($news_item['title']); ?></h2>
                                        </div>
                                        <div class="news-meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y', strtotime($news_item['created_at'])); ?></span>
                                            <span><i class="fas fa-eye"></i> <?php echo $news_item['views_count']; ?> views</span>
                                            <?php if ($news_item['author_name']): ?>
                                                <span><i class="fas fa-user"></i> <?php echo $news_item['author_name']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="news-excerpt"><?php echo htmlspecialchars($news_item['excerpt'] ?? substr($news_item['content'], 0, 200) . '...'); ?></p>
                                        <div class="news-footer">
                                            <div class="news-author">
                                                <i class="fas fa-feather"></i>
                                                Published by <?php echo $news_item['author_name'] ?: 'College Administration'; ?>
                                            </div>
                                            <div class="news-stats">
                                                <span><i class="fas fa-star"></i> Featured</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- All News -->
                    <div class="section-header">
                        <h2 class="section-title">
                            <?php echo $category_filter === 'all' ? 'All News' : 'Filtered News'; ?>
                            <span style="font-size: 1rem; color: var(--dark-gray); margin-left: 0.5rem;">
                                (<?php echo count($news); ?> articles found)
                            </span>
                        </h2>
                    </div>

                    <?php if (empty($news)): ?>
                        <div class="empty-state">
                            <i class="fas fa-newspaper"></i>
                            <h3>No news found</h3>
                            <p>No news articles match your current filters. Try adjusting your search criteria or check back later for new updates.</p>
                        </div>
                    <?php else: ?>
                        <div class="news-grid">
                            <?php foreach ($news as $news_item): ?>
                                <?php if (!$news_item['is_featured']): // Don't show featured news twice ?>
                                    <div class="news-card">
                                        <div class="news-image">
                                            <?php if ($news_item['image_url']): ?>
                                                <img src="<?php echo htmlspecialchars($news_item['image_url']); ?>" alt="<?php echo htmlspecialchars($news_item['title']); ?>">
                                            <?php else: ?>
                                                <div class="placeholder">
                                                    <i class="fas fa-<?php echo $news_item['category_icon'] ?? 'newspaper'; ?>"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="news-category" style="color: <?php echo $news_item['category_color']; ?>">
                                                <i class="fas fa-<?php echo $news_item['category_icon']; ?>"></i>
                                                <?php echo $news_item['category_name']; ?>
                                            </div>
                                        </div>
                                        <div class="news-content">
                                            <div class="news-header">
                                                <h2 class="news-title"><?php echo htmlspecialchars($news_item['title']); ?></h2>
                                            </div>
                                            <div class="news-meta">
                                                <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y', strtotime($news_item['created_at'])); ?></span>
                                                <span><i class="fas fa-eye"></i> <?php echo $news_item['views_count']; ?> views</span>
                                                <?php if ($news_item['author_name']): ?>
                                                    <span><i class="fas fa-user"></i> <?php echo $news_item['author_name']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="news-excerpt"><?php echo htmlspecialchars($news_item['excerpt'] ?? substr($news_item['content'], 0, 200) . '...'); ?></p>
                                            <div class="news-footer">
                                                <div class="news-author">
                                                    <i class="fas fa-feather"></i>
                                                    Published by <?php echo $news_item['author_name'] ?: 'College Administration'; ?>
                                                </div>
                                                <div class="news-stats">
                                                    <?php if ($news_item['is_featured']): ?>
                                                        <span><i class="fas fa-star"></i> Featured</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- Popular News -->
                    <?php if (!empty($popular_news)): ?>
                        <div class="popular-news">
                            <h3 class="popular-news-title">Most Popular</h3>
                            <?php foreach ($popular_news as $news_item): ?>
                                <div class="popular-news-item">
                                    <div class="popular-news-image">
                                        <i class="fas fa-<?php echo $news_item['category_icon'] ?? 'newspaper'; ?>"></i>
                                    </div>
                                    <div class="popular-news-content">
                                        <h4><?php echo htmlspecialchars($news_item['title']); ?></h4>
                                        <div class="popular-news-meta">
                                            <span><?php echo date('M j', strtotime($news_item['created_at'])); ?></span>
                                            <span>•</span>
                                            <span><?php echo $news_item['views_count']; ?> views</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Categories -->
                    <div class="popular-news">
                        <h3 class="popular-news-title">Categories</h3>
                        <?php foreach ($categories as $category): ?>
                            <div class="popular-news-item" style="cursor: pointer;" 
                                 onclick="window.location.href='news.php?category=<?php echo $category['id']; ?>&search=<?php echo urlencode($search_query); ?>'">
                                <div class="popular-news-image" style="background: <?php echo $category['color']; ?>;">
                                    <i class="fas fa-<?php echo $category['icon']; ?>"></i>
                                </div>
                                <div class="popular-news-content">
                                    <h4><?php echo htmlspecialchars($category['name']); ?></h4>
                                    <div class="popular-news-meta">
                                        <span>Click to view</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add hover effects for news cards
        document.addEventListener('DOMContentLoaded', function() {
            const newsCards = document.querySelectorAll('.news-card');
            newsCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Add click handlers for popular news items
            const popularItems = document.querySelectorAll('.popular-news-item');
            popularItems.forEach(item => {
                if (item.onclick) {
                    item.style.cursor = 'pointer';
                }
            });
        });
    </script>
</body>
</html>