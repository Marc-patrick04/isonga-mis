<?php
session_start();
require_once '../config/database.php';

// Redirect if not logged in or not a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/student_login');
    exit();
}

$user_id = $_SESSION['user_id'];
$reg_number = $_SESSION['reg_number'];
$full_name = $_SESSION['full_name'];
$department = $_SESSION['department'];
$program = $_SESSION['program'];

$page_title = "Elections - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --white: #ffffff;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-600: #475569;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.2s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--gray-800);
            background: var(--light);
            font-size: 14px;
        }

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles - Matching System Colors */
        .sidebar {
            width: 240px;
            background: var(--white);
            color: var(--gray-800);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            border-right: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--white);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            object-fit: cover;
        }

        .sidebar-brand h1 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        .student-info {
            text-align: center;
        }

        .student-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin: 0 auto 0.5rem auto;
            font-size: 1.1rem;
        }

        .student-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
            color: var(--gray-900);
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--gray-600);
        }

        .sidebar-nav {
            padding: 0.75rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--white);
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            color: var(--gray-600);
            text-decoration: none;
            border-radius: var(--border-radius);
            margin-bottom: 0.25rem;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9rem;
            border: 1px solid transparent;
        }

        .nav-item:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: var(--primary-light);
        }

        .nav-item.active {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-sm);
            border-color: var(--primary);
        }

        .nav-item.active:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .nav-item i {
            margin-right: 0.75rem;
            width: 18px;
            text-align: center;
            font-size: 0.95rem;
        }

        .nav-item.logout-item {
            margin-top: auto;
            background: rgba(239, 68, 68, 0.05);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .nav-item.logout-item:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        /* Main Content */
        .main-content {
            margin-left: 240px;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Top Header */
        .top-header {
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .logout-btn {
            padding: 0.5rem 1rem;
            background: var(--danger);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: #dc2626;
        }

        /* Dashboard Content */
        .dashboard-content {
            padding: 1.5rem;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        /* Attention Alert */
        .attention-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fef7cd 100%);
            border: 2px solid #f59e0b;
            border-radius: var(--border-radius-lg);
            padding: 2.5rem;
            text-align: center;
            margin: 2rem 0;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .attention-alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #f59e0b, #fbbf24, #f59e0b);
            background-size: 200% 100%;
            animation: shimmer 2s infinite linear;
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .alert-icon {
            font-size: 4rem;
            color: #f59e0b;
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .alert-title {
            font-size: 2rem;
            font-weight: 800;
            color: #92400e;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .alert-subtitle {
            font-size: 1.2rem;
            color: #b45309;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .alert-message {
            font-size: 1rem;
            color: #78350f;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto 2rem auto;
        }

        .alert-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .info-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            text-align: center;
            transition: var(--transition);
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .info-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .info-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .info-description {
            font-size: 0.9rem;
            color: var(--gray-600);
            line-height: 1.5;
        }

        /* Countdown Timer */
        .countdown {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            text-align: center;
            margin: 2rem 0;
        }

        .countdown-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .countdown-timer {
            font-size: 2.5rem;
            font-weight: 800;
            font-family: 'Courier New', monospace;
            margin-bottom: 1rem;
        }

        .countdown-message {
            font-size: 1rem;
            opacity: 0.9;
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .top-header {
                padding: 0.9rem 1rem;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 1.2rem;
            }
            
            .attention-alert {
                padding: 1.5rem;
                margin: 1rem 0;
            }
            
            .alert-title {
                font-size: 1.5rem;
            }
            
            .alert-subtitle {
                font-size: 1rem;
            }
            
            .alert-actions {
                flex-direction: column;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .header-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .alert-icon {
                font-size: 3rem;
            }
            
            .countdown-timer {
                font-size: 2rem;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
        }

        @media (max-width: 1024px) {
            .mobile-menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Dashboard Layout -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <img src="../assets/images/rpsu_logo.jpeg" alt="RPSU" style="width: 36px; height: 36px; border-radius: 6px;">
                    <h1>Isonga</h1>
                </div>
                <div class="student-info">
                    <div class="student-avatar">
                        <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                    </div>
                    <div class="student-name"><?php echo htmlspecialchars($full_name); ?></div>
                    <div class="student-id"><?php echo htmlspecialchars($reg_number); ?></div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
                <a href="tickets" class="nav-item">
                    <i class="fas fa-ticket-alt"></i>
                    My Tickets
                </a>
                <a href="elections" class="nav-item active">
                    <i class="fas fa-vote-yea"></i>
                    Elections
                </a>
                <a href="profile" class="nav-item">
                    <i class="fas fa-user"></i>
                    My Profile
                </a>
                <a href="../auth/logout" class="nav-item logout-item">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <button class="mobile-menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title">Student Elections</h1>
                <div class="header-actions">
                    <div class="user-menu">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($full_name); ?></span>
                    </div>
                    <a href="../auth/logout" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <h2 style="font-size: 1.1rem; color: var(--gray-600); font-weight: 500;">
                        Participate in student leadership elections
                    </h2>
                </div>

                <!-- Attention Alert -->
                <div class="attention-alert">
                    <div class="alert-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h2 class="alert-title">Attention!</h2>
                    <h3 class="alert-subtitle">Elections Portal Currently Unavailable</h3>
                    <p class="alert-message">
                        The student elections portal is currently undergoing maintenance and updates. 
                        We are working diligently to enhance the voting experience and ensure a fair, 
                        transparent, and secure election process for all students.
                    </p>
                    <div class="alert-actions">
                        <a href="dashboard" class="btn btn-primary">
                            <i class="fas fa-home"></i> Return to Dashboard
                        </a>
                        <a href="tickets" class="btn btn-outline">
                            <i class="fas fa-ticket-alt"></i> View My Tickets
                        </a>
                        <button class="btn btn-warning" onclick="showNotifications()">
                            <i class="fas fa-bell"></i> Notify Me When Available
                        </button>
                    </div>
                </div>

                <!-- Countdown Timer (Optional - for when elections will be available) -->
                <div class="countdown">
                    <div class="countdown-title">Next Elections Starting In</div>
                    <div class="countdown-timer" id="electionCountdown">
                        Coming Soon
                    </div>
                    <div class="countdown-message">
                        Stay tuned for announcements about the upcoming student elections
                    </div>
                </div>

                <!-- Information Cards -->
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-vote-yea"></i>
                        </div>
                        <h3 class="info-title">Democratic Process</h3>
                        <p class="info-description">
                            Participate in electing your student representatives through a fair and transparent voting process.
                        </p>
                    </div>

                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="info-title">Leadership Opportunities</h3>
                        <p class="info-description">
                            Various positions available including Guild President, Committee Members, and Class Representatives.
                        </p>
                    </div>

                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3 class="info-title">Secure Voting</h3>
                        <p class="info-description">
                            Your vote is confidential and secure. We use advanced systems to protect election integrity.
                        </p>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="card" style="margin-top: 2rem;">
                    <div class="card-header">
                        <h3 class="card-title">About Student Elections</h3>
                    </div>
                    <div class="card-body">
                        <p style="margin-bottom: 1rem; color: var(--gray-700);">
                            Student elections are a crucial part of campus democracy at RP Musanze College. They provide students 
                            with the opportunity to elect their representatives who will voice their concerns, organize activities, 
                            and work towards improving the student experience.
                        </p>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1.5rem;">
                            <div>
                                <h4 style="color: var(--primary); margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 600;">
                                    <i class="fas fa-calendar-check"></i> Election Timeline
                                </h4>
                                <p style="font-size: 0.85rem; color: var(--gray-600);">
                                    Nominations → Campaigning → Voting → Results Announcement
                                </p>
                            </div>
                            <div>
                                <h4 style="color: var(--primary); margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 600;">
                                    <i class="fas fa-user-check"></i> Eligibility
                                </h4>
                                <p style="font-size: 0.85rem; color: var(--gray-600);">
                                    All registered students in good academic standing can vote and run for positions.
                                </p>
                            </div>
                            <div>
                                <h4 style="color: var(--primary); margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 600;">
                                    <i class="fas fa-question-circle"></i> Need Help?
                                </h4>
                                <p style="font-size: 0.85rem; color: var(--gray-600);">
                                    Contact the Electoral Commission or raise a ticket for election-related queries.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Show notification confirmation
        function showNotifications() {
            alert('You will be notified when the elections portal becomes available. Check your email and dashboard for updates.');
        }

        // Countdown timer (example - you can set actual date)
        function updateCountdown() {
            // Set your target date here when elections will be available
            const targetDate = new Date('2025-12-01T00:00:00').getTime();
            const now = new Date().getTime();
            const distance = targetDate - now;

            if (distance > 0) {
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                document.getElementById('electionCountdown').innerHTML = 
                    `${days}d ${hours}h ${minutes}m ${seconds}s`;
            } else {
                document.getElementById('electionCountdown').innerHTML = 'Starting Soon!';
            }
        }

        // Update countdown every second
        setInterval(updateCountdown, 1000);
        updateCountdown(); // Initial call
    </script>
</body>
</html>