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
    header('Location: events.php');
    exit();
}

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$status_filter = $_GET['status'] ?? 'upcoming';
$search_query = $_GET['search'] ?? '';

// Build query for events with filters
$query = "
    SELECT e.*, 
           ec.name as category_name,
           ec.color as category_color,
           ec.icon as category_icon,
           CASE 
               WHEN e.event_date < CURDATE() THEN 'past'
               WHEN e.event_date = CURDATE() THEN 'today'
               ELSE 'upcoming'
           END as event_status
    FROM events e
    LEFT JOIN event_categories ec ON e.category_id = ec.id
    WHERE e.status = 'published'
";

$params = [];

// Add filters
if ($category_filter !== 'all') {
    $query .= " AND e.category_id = ?";
    $params[] = $category_filter;
}

if ($status_filter === 'upcoming') {
    $query .= " AND e.event_date >= CURDATE()";
} elseif ($status_filter === 'past') {
    $query .= " AND e.event_date < CURDATE()";
} elseif ($status_filter === 'today') {
    $query .= " AND e.event_date = CURDATE()";
}

if (!empty($search_query)) {
    $query .= " AND (e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY 
    CASE 
        WHEN e.event_date = CURDATE() THEN 0
        WHEN e.event_date > CURDATE() THEN 1
        ELSE 2
    END,
    e.event_date ASC,
    e.start_time ASC";

// Get filtered events
$events_stmt = $pdo->prepare($query);
$events_stmt->execute($params);
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get event categories for filters
$categories_stmt = $pdo->prepare("SELECT * FROM event_categories WHERE is_active = 1 ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count events by status (dynamic counts)
$count_upcoming = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date >= CURDATE()");
$count_upcoming->execute();
$upcoming_count = $count_upcoming->fetchColumn();

$count_today = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date = CURDATE()");
$count_today->execute();
$today_count = $count_today->fetchColumn();

$count_past = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date < CURDATE()");
$count_past->execute();
$past_count = $count_past->fetchColumn();

$count_featured = $pdo->prepare("SELECT COUNT(*) FROM events WHERE is_featured = 1 AND status = 'published' AND event_date >= CURDATE()");
$count_featured->execute();
$featured_count = $count_featured->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Events - Isonga RPSU Management System</title>
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

        .stat-icon.upcoming { background: rgba(30, 136, 229, 0.1); color: var(--secondary-blue); }
        .stat-icon.today { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
        .stat-icon.past { background: rgba(108, 117, 125, 0.1); color: var(--dark-gray); }
        .stat-icon.featured { background: rgba(255, 107, 107, 0.1); color: #ff6b6b; }

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

        /* Events Grid */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .event-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }

        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .event-card.featured {
            border: 2px solid var(--warning);
        }

        .event-card.featured::before {
            content: 'Featured';
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--warning);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 2;
        }

        .event-image {
            width: 100%;
            height: 200px;
            background: var(--gradient-secondary);
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
            color: white;
            font-size: 3rem;
            opacity: 0.7;
        }

        .event-category {
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

        .event-content {
            padding: 1.5rem;
        }

        .event-date {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--medium-gray);
        }

        .date-badge {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 60px;
            height: 60px;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            text-align: center;
        }

        .date-day {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }

        .date-month {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .event-time {
            flex: 1;
        }

        .event-time div {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            color: var(--dark-gray);
        }

        .event-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.8rem;
            line-height: 1.3;
        }

        .event-description {
            color: var(--dark-gray);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1.2rem;
        }

        .event-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            color: var(--dark-gray);
        }

        .event-location, .event-organizer {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .event-status {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-upcoming { background: rgba(40, 167, 69, 0.1); color: var(--success); }
        .status-today { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
        .status-past { background: rgba(108, 117, 125, 0.1); color: var(--dark-gray); }

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
            
            .events-grid {
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
            
            .events-grid {
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
                    <li><a href="#" class="active"><i class="fas fa-calendar-alt"></i> Events</a></li>
                    <li><a href="news.php"><i class="fas fa-newspaper"></i> News</a></li>
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
                    <h1>Campus Events</h1>
                    <p>Discover upcoming activities and events on campus</p>
                </div>
                <div class="header-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="toggle_theme" class="theme-toggle" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Event Statistics -->
            <div class="stats-grid">
                <div class="stat-card <?php echo $status_filter === 'upcoming' ? 'active' : ''; ?>" onclick="window.location.href='events.php?status=upcoming&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon upcoming">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="stat-number"><?php echo $upcoming_count; ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card <?php echo $status_filter === 'today' ? 'active' : ''; ?>" onclick="window.location.href='events.php?status=today&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon today">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-number"><?php echo $today_count; ?></div>
                    <div class="stat-label">Today's Events</div>
                </div>
                <div class="stat-card <?php echo $status_filter === 'past' ? 'active' : ''; ?>" onclick="window.location.href='events.php?status=past&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>'">
                    <div class="stat-icon past">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-number"><?php echo $past_count; ?></div>
                    <div class="stat-label">Past Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon featured">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-number"><?php echo $featured_count; ?></div>
                    <div class="stat-label">Featured Events</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 style="margin-bottom: 1rem;">Find Events</h3>
                <form method="GET" action="events.php">
                    <div class="filter-grid">
                        <div class="form-group search-box">
                            <label for="search">Search Events</label>
                            <input type="text" id="search" name="search" class="form-control" 
                                   placeholder="Search by title, description, or location..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="form-group">
                            <label for="status">Event Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming Events</option>
                                <option value="today" <?php echo $status_filter === 'today' ? 'selected' : ''; ?>>Today's Events</option>
                                <option value="past" <?php echo $status_filter === 'past' ? 'selected' : ''; ?>>Past Events</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Events</option>
                            </select>
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
                        <a href="events.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Events Grid -->
            <div class="section-header">
                <h2 class="section-title">
                    <?php
                    if ($status_filter === 'upcoming') echo 'Upcoming Events';
                    elseif ($status_filter === 'today') echo 'Today\'s Events';
                    elseif ($status_filter === 'past') echo 'Past Events';
                    else echo 'All Events';
                    ?>
                    <span style="font-size: 1rem; color: var(--dark-gray); margin-left: 0.5rem;">
                        (<?php echo count($events); ?> events found)
                    </span>
                </h2>
            </div>

            <?php if (empty($events)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No events found</h3>
                    <p>No events match your current filters. Try adjusting your search criteria or check back later for new events.</p>
                </div>
            <?php else: ?>
                <div class="events-grid">
                    <?php foreach ($events as $event): ?>
                        <div class="event-card <?php echo $event['is_featured'] ? 'featured' : ''; ?>">
                            <div class="event-image">
                                <?php if ($event['image_url']): ?>
                                    <img src="<?php echo htmlspecialchars($event['image_url']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
                                <?php else: ?>
                                    <div class="placeholder">
                                        <i class="fas fa-<?php echo $event['category_icon'] ?? 'calendar-alt'; ?>"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="event-category" style="color: <?php echo $event['category_color']; ?>">
                                    <i class="fas fa-<?php echo $event['category_icon']; ?>"></i>
                                    <?php echo $event['category_name']; ?>
                                </div>
                            </div>
                            <div class="event-content">
                                <div class="event-date">
                                    <div class="date-badge">
                                        <div class="date-day"><?php echo date('j', strtotime($event['event_date'])); ?></div>
                                        <div class="date-month"><?php echo date('M', strtotime($event['event_date'])); ?></div>
                                    </div>
                                    <div class="event-time">
                                        <div><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                            <?php if ($event['end_time']): ?> - <?php echo date('g:i A', strtotime($event['end_time'])); ?><?php endif; ?>
                                        </div>
                                        <div><i class="fas fa-map-marker-alt"></i> <?php echo $event['location']; ?></div>
                                    </div>
                                </div>
                                <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                <p class="event-description"><?php echo htmlspecialchars($event['excerpt'] ?? $event['description']); ?></p>
                                
                                <div class="event-meta">
                                    <div class="event-organizer">
                                        <i class="fas fa-user"></i>
                                        <?php echo $event['organizer'] ?: 'College Administration'; ?>
                                    </div>
                                    <div class="event-status status-<?php echo $event['event_status']; ?>">
                                        <?php echo $event['event_status']; ?>
                                    </div>
                                </div>
                                
                                <?php if ($event['contact_person'] || $event['contact_email'] || $event['contact_phone']): ?>
                                    <div class="event-meta" style="margin-top: 0.5rem;">
                                        <div style="font-size: 0.8rem; color: var(--dark-gray);">
                                            <?php if ($event['contact_person']): ?>
                                                Contact: <?php echo $event['contact_person']; ?>
                                                <?php if ($event['contact_email']): ?> | <?php echo $event['contact_email']; ?><?php endif; ?>
                                                <?php if ($event['contact_phone']): ?> | <?php echo $event['contact_phone']; ?><?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Add hover effects for event cards
        document.addEventListener('DOMContentLoaded', function() {
            const eventCards = document.querySelectorAll('.event-card');
            eventCards.forEach(card => {
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