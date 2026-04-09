<?php
session_start();
require_once 'config/database.php';

// Get event ID from URL
$event_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($event_id <= 0) {
    header('Location: events.php');
    exit();
}

// Get the specific event
try {
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name, ec.color as category_color, ec.icon as category_icon
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.id = ? AND e.status = 'published'
    ");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        header('Location: events.php');
        exit();
    }
    
} catch (PDOException $e) {
    header('Location: events.php');
    exit();
}

// Get related events (same category)
try {
    $related_stmt = $pdo->prepare("
        SELECT e.*, ec.name as category_name, ec.color as category_color
        FROM events e 
        LEFT JOIN event_categories ec ON e.category_id = ec.id 
        WHERE e.category_id = ? AND e.id != ? AND e.status = 'published'
        ORDER BY e.event_date DESC 
        LIMIT 3
    ");
    $related_stmt->execute([$event['category_id'], $event_id]);
    $related_events = $related_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $related_events = [];
}

$page_title = $event['title'] . " - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="icon" href="assets/images/logo.png">
    <style>
        :root {
            --primary: #0056b3;
            --primary-dark: #003d82;
            --primary-light: #4d8be6;
            --secondary: #1e88e5;
            --accent: #0d47a1;
            --light: #f8fafc;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #343a40;
            --gray-900: #212529;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --border-radius-xl: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--gray-900);
            background: var(--light);
            overflow-x: hidden;
            font-size: 14px;
        }

        /* Header & Navigation */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: var(--transition);
        }

        .header.scrolled {
            box-shadow: var(--shadow-md);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
            transition: var(--transition);
        }

        .logo-rp {
            max-width: 100px;
        }

        .logo-rpsu {
            max-width: 60px;
        }

        .brand-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.025em;
        }

        .brand-text p {
            font-size: 0.75rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            position: relative;
            padding: 0.5rem 0;
        }

        .nav-links a:after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 0;
            background: var(--gradient-primary);
            transition: var(--transition);
            border-radius: 1px;
        }

        .nav-links a:hover:after {
            width: 100%;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a.active {
            color: var(--primary);
        }

        .nav-links a.active:after {
            width: 100%;
        }

        .login-buttons {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .login-btn {
            padding: 0.6rem 1.25rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .btn-student {
            background: var(--gradient-secondary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-committee {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .login-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Main Content */
        .main-container {
            max-width: 1200px;
            margin: 80px auto 0;
            padding: 2rem 1.5rem;
        }

        /* Event Details */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .back-link:hover {
            gap: 0.75rem;
        }

        .event-details {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: 3rem;
        }

        .event-hero {
            position: relative;
            height: 400px;
            overflow: hidden;
        }

        .event-hero img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .event-hero-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: <?php echo $event['category_color']; ?>;
            color: white;
            font-size: 4rem;
        }

        .event-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            padding: 2rem;
            color: white;
        }

        .event-category {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1rem;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: white;
        }

        .event-title {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1rem;
            color: white;
        }

        .event-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        .meta-item i {
            width: 20px;
            color: rgba(255,255,255,0.8);
        }

        .event-content {
            padding: 3rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 3rem;
        }

        .event-description {
            font-size: 1.1rem;
            line-height: 1.7;
            color: var(--gray-800);
        }

        .event-description p {
            margin-bottom: 1.5rem;
        }

        .event-description h2, .event-description h3 {
            margin: 2rem 0 1rem;
            color: var(--gray-900);
        }

        .event-info-sidebar {
            background: var(--gray-100);
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            height: fit-content;
        }

        .info-section {
            margin-bottom: 2rem;
        }

        .info-section:last-child {
            margin-bottom: 0;
        }

        .info-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-title i {
            color: var(--primary);
        }

        .info-content {
            color: var(--gray-700);
            line-height: 1.6;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--gray-700);
        }

        .info-value {
            color: var(--gray-600);
            text-align: right;
        }

        .register-section {
            background: var(--primary);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            text-align: center;
            margin-top: 2rem;
        }

        .register-btn {
            background: white;
            color: var(--primary);
            border: none;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .register-btn:disabled {
            background: var(--gray-400);
            color: var(--gray-600);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Related Events */
        .related-events {
            margin-top: 3rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary);
        }

        .related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .event-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .event-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .event-image {
            height: 160px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-200);
        }

        .event-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .event-card:hover .event-image img {
            transform: scale(1.05);
        }

        .event-date {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            text-align: center;
            min-width: 60px;
        }

        .event-day {
            font-size: 1.25rem;
            font-weight: 800;
            display: block;
            line-height: 1;
        }

        .event-month {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .event-content-small {
            padding: 1.25rem;
        }

        .event-category-small {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .event-title-small {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .event-meta-small {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        .meta-item-small {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Footer */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 3rem 1.5rem 1.5rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1000px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 2rem;
        }

        .footer-logo {
            margin-bottom: 1rem;
        }

        .footer-logo .logo {
            height: 35px;
            filter: brightness(0) invert(1);
        }

        .footer-description {
            color: #9ca3af;
            line-height: 1.5;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .social-links {
            display: flex;
            gap: 0.75rem;
        }

        .social-links a {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }

        .footer-heading {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--warning);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .footer-links a:hover {
            color: var(--warning);
            padding-left: 3px;
        }

        .footer-bottom {
            max-width: 1000px;
            margin: 0 auto;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            margin-top: 2rem;
            font-size: 0.75rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .event-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                gap: 1rem;
                padding: 0 1rem;
            }

            .nav-links {
                gap: 1rem;
                flex-wrap: wrap;
                justify-content: center;
            }

            .login-buttons {
                width: 100%;
                justify-content: center;
            }

            .event-title {
                font-size: 2rem;
            }

            .event-hero {
                height: 300px;
            }

            .event-content {
                padding: 2rem;
            }

            .event-meta-grid {
                grid-template-columns: 1fr;
            }

            .related-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        @media (max-width: 480px) {
            .nav-links {
                flex-direction: column;
                gap: 0.5rem;
            }

            .event-overlay {
                padding: 1.5rem;
            }

            .event-title {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header" id="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/logo.png" alt="RPSU" class="logo logo-rpsu">
                </div>
                <div class="brand-text">
                    <h1>Isonga</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <nav class="nav-links">
                <a href="index.php">Home</a>
                <a href="announcements.php">Announcements</a>
                <a href="news.php">News</a>
                <a href="events.php" class="active">Events</a>
                <a href="committee.php">Committee</a>
                <a href="gallery.php">Gallery</a>
            </nav>
            <div class="login-buttons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Back Link -->
        <a href="events.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to All Events
        </a>

        <!-- Event Details -->
        <article class="event-details">
            <div class="event-hero">
                <?php if (!empty($event['image_url'])): ?>
                    <img src="<?php echo htmlspecialchars($event['image_url']); ?>" 
                         alt="<?php echo htmlspecialchars($event['title']); ?>"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <?php endif; ?>
                <div class="event-hero-placeholder" style="<?php echo empty($event['image_url']) ? 'display: flex;' : 'display: none;'; ?>">
                    <i class="fas fa-<?php echo $event['category_icon']; ?>"></i>
                </div>
                <div class="event-overlay">
                    <div class="event-category">
                        <i class="fas fa-<?php echo $event['category_icon']; ?>"></i>
                        <?php echo htmlspecialchars($event['category_name']); ?>
                    </div>
                    <h1 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h1>
                    <div class="event-meta-grid">
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span><?php echo date('l, F j, Y', strtotime($event['event_date'])); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-clock"></i>
                            <span>
                                <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                <?php if ($event['end_time']): ?>
                                    - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo htmlspecialchars($event['location']); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-users"></i>
                            <span>Organized by: <?php echo htmlspecialchars($event['organizer']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="event-content">
                <div class="event-description">
                    <?php 
                    // Format and display the description with proper HTML
                    $description = $event['description'];
                    
                    // Convert line breaks to paragraphs
                    $paragraphs = explode("\n", $description);
                    foreach ($paragraphs as $paragraph) {
                        $paragraph = trim($paragraph);
                        if (!empty($paragraph)) {
                            echo '<p>' . htmlspecialchars($paragraph) . '</p>';
                        }
                    }
                    ?>
                </div>
                
                <div class="event-info-sidebar">
                    <div class="info-section">
                        <h3 class="info-title">
                            <i class="fas fa-info-circle"></i>
                            Event Details
                        </h3>
                        <div class="info-content">
                            <div class="info-item">
                                <span class="info-label">Date:</span>
                                <span class="info-value"><?php echo date('F j, Y', strtotime($event['event_date'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Time:</span>
                                <span class="info-value">
                                    <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                    <?php if ($event['end_time']): ?>
                                        - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Location:</span>
                                <span class="info-value"><?php echo htmlspecialchars($event['location']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Category:</span>
                                <span class="info-value"><?php echo htmlspecialchars($event['category_name']); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($event['organizer'] || $event['contact_person']): ?>
                    <div class="info-section">
                        <h3 class="info-title">
                            <i class="fas fa-user-friends"></i>
                            Organizer Info
                        </h3>
                        <div class="info-content">
                            <?php if ($event['organizer']): ?>
                            <div class="info-item">
                                <span class="info-label">Organizer:</span>
                                <span class="info-value"><?php echo htmlspecialchars($event['organizer']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($event['contact_person']): ?>
                            <div class="info-item">
                                <span class="info-label">Contact Person:</span>
                                <span class="info-value"><?php echo htmlspecialchars($event['contact_person']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($event['contact_email']): ?>
                            <div class="info-item">
                                <span class="info-label">Email:</span>
                                <span class="info-value"><?php echo htmlspecialchars($event['contact_email']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($event['contact_phone']): ?>
                            <div class="info-item">
                                <span class="info-label">Phone:</span>
                                <span class="info-value"><?php echo htmlspecialchars($event['contact_phone']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($event['registration_required']): ?>
                    <div class="register-section">
                        <h3>Registration Required</h3>
                        <p>Don't miss out on this amazing event! Register now to secure your spot.</p>
                        
                        <div class="info-item" style="color: white; border-color: rgba(255,255,255,0.3);">
                            <span>Registered:</span>
                            <span>
                                <?php echo $event['registered_participants']; ?>
                                <?php if ($event['max_participants']): ?>
                                    / <?php echo $event['max_participants']; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <button class="register-btn" 
                                <?php echo ($event['max_participants'] && $event['registered_participants'] >= $event['max_participants']) ? 'disabled' : ''; ?>>
                            <i class="fas fa-user-plus"></i>
                            <?php echo ($event['max_participants'] && $event['registered_participants'] >= $event['max_participants']) ? 'Event Full' : 'Register Now'; ?>
                        </button>
                        
                        <?php if ($event['registration_deadline']): ?>
                        <p style="margin-top: 1rem; font-size: 0.9rem; opacity: 0.9;">
                            Registration closes: <?php echo date('M j, Y', strtotime($event['registration_deadline'])); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <!-- Related Events -->
        <?php if (!empty($related_events)): ?>
        <section class="related-events">
            <h2 class="section-title">
                <i class="fas fa-calendar-alt"></i>
                Related Events
            </h2>
            <div class="related-grid">
                <?php foreach ($related_events as $related): ?>
                    <article class="event-card">
                        <div class="event-image">
                            <?php if (!empty($related['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($related['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($related['title']); ?>"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            <div class="event-hero-placeholder" style="<?php echo empty($related['image_url']) ? 'display: flex;' : 'display: none;'; ?> width: 100%; height: 100%; font-size: 2rem;">
                                <i class="fas fa-<?php echo $related['category_icon']; ?>"></i>
                            </div>
                            <div class="event-date">
                                <span class="event-day"><?php echo date('j', strtotime($related['event_date'])); ?></span>
                                <span class="event-month"><?php echo date('M', strtotime($related['event_date'])); ?></span>
                            </div>
                        </div>
                        <div class="event-content-small">
                            <div class="event-category-small" style="background: <?php echo $related['category_color']; ?>20; color: <?php echo $related['category_color']; ?>;">
                                <i class="fas fa-<?php echo $related['category_icon']; ?>"></i>
                                <?php echo htmlspecialchars($related['category_name']); ?>
                            </div>
                            <h3 class="event-title-small">
                                <a href="event_single.php?id=<?php echo $related['id']; ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($related['title']); ?>
                                </a>
                            </h3>
                            <div class="event-meta-small">
                                <div class="meta-item-small">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($related['location']); ?></span>
                                </div>
                                <div class="meta-item-small">
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo date('g:i A', strtotime($related['start_time'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <footer class="footer">
    <div class="footer-content">
        <div class="footer-info">
            <div class="footer-logo">
                <img src="assets/images/rp_logo.png" alt="RP Musanze" class="logo">
            </div>
            <p class="footer-description">
                Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
            </p>
            <div class="social-links">
                <a href="https://twitter.com/MusanzecollegSU" target="_blank"><i class="fab fa-twitter"></i></a>
                <a href="https://www.facebook.com/RP-Musanze-College" target="_blank"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.linkedin.com/in/rp-musanze-college-3963b0203" target="_blank"><i class="fab fa-linkedin-in"></i></a>
                <a href="https://www.instagram.com/rpmusanzecollege_su" target="_blank"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Quick Links</h4>
            <ul class="footer-links">
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="news.php">Campus News</a></li>
                <li><a href="events.php">Events</a></li>
                <li><a href="committee.php">Committee</a></li>
            </ul>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Student Resources</h4>
            <ul class="footer-links">
                <li><a href="https://www.rp.ac.rw/announcement" target="_blank">Academic Calendar</a></li>
                <li><a href="https://www.google.com/maps/search/rp+musanze+college" target="_blank">Campus Map</a></li>
                <li><a href="../assets/rp_handbook.pdf">Student Handbook</a></li>
                <li><a href="gallery.php">Gallery</a></li>
            </ul>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Contact Info</h4>
            <ul class="footer-links">
                <li><i class="fas fa-map-marker-alt"></i> Rwanda Polytechnic Musanze College Student Union</li>
                <li><i class="fas fa-phone"></i> +250 788 123 456</li>
                <li><i class="fas fa-envelope"></i>rpmusanzesu@gmail.com</li>
                <li><i class="fas fa-clock"></i> Mon - Fri: 8:00 - 17:00</li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; 2025 Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
    </div>
</footer>


    <script>
        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Registration button functionality
        document.querySelector('.register-btn')?.addEventListener('click', function() {
            if (!this.disabled) {
                alert('Registration system will be implemented soon! Students will need to login to register for events.');
                // In a real implementation, this would open a registration modal or redirect to registration page
            }
        });

        // Related event cards click functionality
        document.querySelectorAll('.event-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't navigate if clicking on a link inside the card
                if (e.target.tagName === 'A' || e.target.closest('a')) {
                    return;
                }
                
                // Find the event link in this card
                const eventLink = this.querySelector('a[href*="event_single.php"]');
                if (eventLink) {
                    window.location.href                    eventLink.href;
                }
            });
        });

        // Event status checking
        function checkEventStatus() {
            const eventDate = new Date('<?php echo $event['event_date'] . ' ' . $event['start_time']; ?>');
            const now = new Date();
            
            if (now > eventDate) {
                // Event has passed
                const registerBtn = document.querySelector('.register-btn');
                if (registerBtn) {
                    registerBtn.innerHTML = '<i class="fas fa-calendar-check"></i> Event Completed';
                    registerBtn.disabled = true;
                    registerBtn.style.background = 'var(--gray-400)';
                    registerBtn.style.color = 'var(--gray-600)';
                }
                
                // Add completed badge
                const eventOverlay = document.querySelector('.event-overlay');
                if (eventOverlay) {
                    const completedBadge = document.createElement('div');
                    completedBadge.className = 'event-category';
                    completedBadge.style.background = 'var(--success)';
                    completedBadge.style.marginLeft = '1rem';
                    completedBadge.innerHTML = '<i class="fas fa-check-circle"></i> Event Completed';
                    eventOverlay.insertBefore(completedBadge, eventOverlay.querySelector('.event-meta-grid'));
                }
            } else if (now > new Date(eventDate.getTime() - (24 * 60 * 60 * 1000))) {
                // Event is within 24 hours
                const registerSection = document.querySelector('.register-section');
                if (registerSection) {
                    const urgencyNote = document.createElement('p');
                    urgencyNote.style.color = 'var(--warning)';
                    urgencyNote.style.fontWeight = '600';
                    urgencyNote.style.marginTop = '1rem';
                    urgencyNote.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Last chance to register!';
                    registerSection.appendChild(urgencyNote);
                }
            }
        }

        // Check event status on page load
        document.addEventListener('DOMContentLoaded', function() {
            checkEventStatus();
            
            // Add calendar integration
            const addToCalendarBtn = document.createElement('button');
            addToCalendarBtn.className = 'register-btn';
            addToCalendarBtn.style.background = 'transparent';
            addToCalendarBtn.style.border = '2px solid var(--primary)';
            addToCalendarBtn.style.color = 'var(--primary)';
            addToCalendarBtn.style.marginTop = '1rem';
            addToCalendarBtn.innerHTML = '<i class="fas fa-calendar-plus"></i> Add to Calendar';
            addToCalendarBtn.addEventListener('click', addToCalendar);
            
            const registerSection = document.querySelector('.register-section');
            if (registerSection) {
                registerSection.appendChild(addToCalendarBtn);
            } else {
                // Add to info sidebar if no register section
                const infoSidebar = document.querySelector('.event-info-sidebar');
                if (infoSidebar) {
                    infoSidebar.appendChild(addToCalendarBtn);
                }
            }
        });

        // Add to calendar functionality
        function addToCalendar() {
            const event = {
                title: '<?php echo addslashes($event['title']); ?>',
                start: '<?php echo $event['event_date'] . 'T' . $event['start_time']; ?>',
                end: '<?php echo $event['event_date'] . 'T' . $event['end_time']; ?>',
                location: '<?php echo addslashes($event['location']); ?>',
                description: '<?php echo addslashes($event['description']); ?>'
            };

            // Create Google Calendar URL
            const googleCalendarUrl = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(event.title)}&dates=${formatDateForGoogle(event.start)}/${formatDateForGoogle(event.end)}&details=${encodeURIComponent(event.description)}&location=${encodeURIComponent(event.location)}`;
            
            // Create downloadable .ics file
            const icsContent = createICSFile(event);
            const blob = new Blob([icsContent], { type: 'text/calendar' });
            const url = window.URL.createObjectURL(blob);
            
            // Show download options
            const modal = document.createElement('div');
            modal.style.position = 'fixed';
            modal.style.top = '0';
            modal.style.left = '0';
            modal.style.width = '100%';
            modal.style.height = '100%';
            modal.style.background = 'rgba(0,0,0,0.5)';
            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
            modal.style.zIndex = '10000';
            
            modal.innerHTML = `
                <div style="background: white; padding: 2rem; border-radius: var(--border-radius-lg); max-width: 400px; text-align: center;">
                    <h3 style="margin-bottom: 1rem;">Add to Calendar</h3>
                    <p style="margin-bottom: 1.5rem; color: var(--gray-600);">Choose your preferred calendar:</p>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <a href="${googleCalendarUrl}" target="_blank" style="padding: 0.75rem; background: var(--primary); color: white; text-decoration: none; border-radius: var(--border-radius);">
                            <i class="fab fa-google"></i> Google Calendar
                        </a>
                        <a href="${url}" download="event-<?php echo $event['id']; ?>.ics" style="padding: 0.75rem; background: var(--success); color: white; text-decoration: none; border-radius: var(--border-radius);">
                            <i class="fas fa-download"></i> Download .ics File
                        </a>
                        <button onclick="this.closest('div').parentElement.remove()" style="padding: 0.75rem; background: var(--gray-400); color: var(--gray-700); border: none; border-radius: var(--border-radius); cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
        }

        function formatDateForGoogle(dateString) {
            const date = new Date(dateString);
            return date.toISOString().replace(/-|:|\.\d+/g, '');
        }

        function createICSFile(event) {
            return `BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
SUMMARY:${event.title}
DTSTART:${formatDateForGoogle(event.start)}
DTEND:${formatDateForGoogle(event.end)}
LOCATION:${event.location}
DESCRIPTION:${event.description}
END:VEVENT
END:VCALENDAR`;
        }

        // Share functionality
        function shareEvent() {
            if (navigator.share) {
                navigator.share({
                    title: '<?php echo addslashes($event['title']); ?>',
                    text: '<?php echo addslashes($event['description']); ?>',
                    url: window.location.href
                });
            } else {
                // Fallback for browsers that don't support Web Share API
                const shareUrl = window.location.href;
                navigator.clipboard.writeText(shareUrl).then(() => {
                    alert('Event link copied to clipboard!');
                });
            }
        }

        // Add share button
        document.addEventListener('DOMContentLoaded', function() {
            const shareBtn = document.createElement('button');
            shareBtn.className = 'register-btn';
            shareBtn.style.background = 'var(--secondary)';
            shareBtn.style.marginTop = '1rem';
            shareBtn.innerHTML = '<i class="fas fa-share-alt"></i> Share Event';
            shareBtn.addEventListener('click', shareEvent);
            
            const registerSection = document.querySelector('.register-section');
            if (registerSection) {
                registerSection.appendChild(shareBtn);
            }
        });

        // Image gallery functionality (if multiple images exist)
        function initImageGallery() {
            const heroImage = document.querySelector('.event-hero img');
            if (heroImage) {
                heroImage.style.cursor = 'zoom-in';
                heroImage.addEventListener('click', function() {
                    const modal = document.createElement('div');
                    modal.style.position = 'fixed';
                    modal.style.top = '0';
                    modal.style.left = '0';
                    modal.style.width = '100%';
                    modal.style.height = '100%';
                    modal.style.background = 'rgba(0,0,0,0.9)';
                    modal.style.display = 'flex';
                    modal.style.alignItems = 'center';
                    modal.style.justifyContent = 'center';
                    modal.style.zIndex = '10000';
                    modal.style.cursor = 'zoom-out';
                    
                    const img = document.createElement('img');
                    img.src = this.src;
                    img.style.maxWidth = '90%';
                    img.style.maxHeight = '90%';
                    img.style.objectFit = 'contain';
                    img.style.borderRadius = 'var(--border-radius)';
                    
                    modal.appendChild(img);
                    modal.addEventListener('click', function() {
                        document.body.removeChild(modal);
                    });
                    
                    document.body.appendChild(modal);
                });
            }
        }

        // Initialize image gallery
        document.addEventListener('DOMContentLoaded', initImageGallery);
    </script>
</body>
</html>