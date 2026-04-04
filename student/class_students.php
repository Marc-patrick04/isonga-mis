<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep (PostgreSQL uses true for boolean)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? false)) {
    header('Location: student_login');
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
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: class_students');
    exit();
}

// Get class students (same program and academic year) (PostgreSQL compatible)
$class_students_stmt = $pdo->prepare("
    SELECT 
        u.reg_number,
        u.full_name,
        u.email,
        u.phone,
        u.academic_year,
        u.is_class_rep,
        d.name as department_name,
        p.name as program_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE u.program_id = (SELECT program_id FROM users WHERE id = ?)
    AND u.academic_year = (SELECT academic_year FROM users WHERE id = ?)
    AND u.role = 'student'
    AND u.status = 'active'
    ORDER BY u.full_name
");
$class_students_stmt->execute([$student_id, $student_id]);
$class_students = $class_students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get class statistics
$total_students = count($class_students);
$class_reps_count = array_reduce($class_students, function($count, $student) {
    return $count + ($student['is_class_rep'] ? 1 : 0);
}, 0);

// Get academic year distribution
$year_distribution = [];
foreach ($class_students as $student) {
    $year = $student['academic_year'] ?: 'Not Specified';
    if (!isset($year_distribution[$year])) {
        $year_distribution[$year] = 0;
    }
    $year_distribution[$year]++;
}

// Helper function
function safe_display($data) {
    return $data ? htmlspecialchars($data) : '';
}

// Format phone number
function formatPhone($phone) {
    if (!$phone) return 'Not provided';
    // Basic formatting for Rwandan numbers
    if (preg_match('/^(\+250|250)?(\d{3})(\d{3})(\d{3})$/', $phone, $matches)) {
        return '+250 ' . $matches[2] . ' ' . $matches[3] . ' ' . $matches[4];
    }
    return $phone;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Class Students - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
    <style>
        :root {
            --primary: #0056b3;
            --secondary: #1e88e5;
            --accent: #0d47a1;
            --light: #f8f9fa;
            --white: #fff;
            --gray: #e9ecef;
            --dark-gray: #6c757d;
            --text: #2c3e50;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --radius: 8px;
            --transition: all 0.3s ease;
        }
        [data-theme="dark"] {
            --white: #1a1a1a;
            --light: #2d2d2d;
            --gray: #3d3d3d;
            --dark-gray: #a0a0a0;
            --text: #e9ecef;
            --shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); transition: var(--transition); font-size: 0.875rem; }
        .container { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 260px; height: 100vh; z-index: 1000; overflow-y: auto; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .brand-logo img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.25rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.75rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); font-size: 0.85rem; }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        .nav-links i { width: 20px; text-align: center; }
        
        /* Main Content */
        .main { grid-column: 2; padding: 1.5rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; background: var(--white); padding: 1.25rem 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.3rem; margin-bottom: 0.25rem; }
        .welcome p { font-size: 0.85rem; color: var(--dark-gray); }
        .actions { display: flex; gap: 1rem; flex-wrap: wrap; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.85rem; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); }
        .icon-btn:hover { background: var(--gray); }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.8rem; color: var(--dark-gray); }
        
        /* Cards */
        .card { background: var(--white); border-radius: var(--radius); padding: 1.25rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .card-title { font-size: 1rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.8rem; }
        .link:hover { text-decoration: underline; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 0.75rem; }
        
        /* Student List */
        .student-list { display: grid; gap: 1rem; }
        .student-card { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--light); border-radius: var(--radius); transition: var(--transition); }
        .student-card:hover { background: var(--gray); transform: translateY(-2px); }
        .student-avatar { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1.2rem; flex-shrink: 0; }
        .student-info { flex: 1; }
        .student-name { font-weight: 600; margin-bottom: 0.3rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .student-details { display: flex; gap: 1rem; font-size: 0.75rem; color: var(--dark-gray); flex-wrap: wrap; }
        .student-contact { margin-top: 0.5rem; }
        .student-contact a { color: var(--secondary); text-decoration: none; font-size: 0.75rem; }
        .student-contact a:hover { text-decoration: underline; }
        .student-contact span { font-size: 0.75rem; color: var(--dark-gray); }
        
        /* Search and Filters */
        .search-box { display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .search-input { flex: 1; padding: 0.75rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); font-size: 0.85rem; }
        .search-input:focus { outline: none; border-color: var(--secondary); }
        .filter-select { padding: 0.75rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); font-size: 0.85rem; cursor: pointer; }
        
        /* Distribution Chart */
        .distribution { display: grid; gap: 0.5rem; }
        .distribution-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; background: var(--light); border-radius: var(--radius); font-size: 0.8rem; flex-wrap: wrap; gap: 0.5rem; }
        .distribution-bar { flex: 1; height: 6px; background: var(--gray); border-radius: 3px; margin: 0 1rem; overflow: hidden; min-width: 100px; }
        .distribution-fill { height: 100%; background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); border-radius: 3px; }
        
        /* Alert */
        .alert { padding: 0.75rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        
        /* Table Container */
        .table-container { overflow-x: auto; }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .student-details { flex-direction: column; gap: 0.3rem; }
            .search-box { flex-direction: column; }
            .distribution-item { flex-direction: column; align-items: flex-start; }
            .distribution-bar { width: 100%; margin: 0.5rem 0; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .student-card { flex-direction: column; text-align: center; }
            .student-name { justify-content: center; }
            .student-details { justify-content: center; }
            .student-contact { text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="brand">
                <div class="brand-logo">
                    <img src="../assets/images/rp_logo.png" alt="RP Musanze College">
                </div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="class_tickets"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="class_students" class="active"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="rep_meetings"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main">
            <!-- Header -->
            <div class="header">
                <div class="welcome">
                    <h1>Class Students Directory
                        <span class="class-rep-badge"><i class="fas fa-user-shield"></i> Class Representative</span>
                    </h1>
                    <p><?php echo safe_display($program); ?> - <?php echo safe_display($academic_year); ?></p>
                </div>
                <div class="actions">
                    <button class="icon-btn" id="mobileMenuToggle" title="Menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="toggle_theme" class="icon-btn" title="Toggle Theme">
                            <i class="fas fa-<?php echo $theme === 'light' ? 'moon' : 'sun'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Class Directory:</strong> This page shows all students in your class. Use this information to coordinate with your classmates and represent their interests effectively.
            </div>

            <!-- Class Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(30,136,229,0.1); color: var(--secondary);"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-user-shield"></i></div>
                    <div class="stat-number"><?php echo number_format($class_reps_count); ?></div>
                    <div class="stat-label">Class Representatives</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-graduation-cap"></i></div>
                    <div class="stat-number"><?php echo number_format(count($year_distribution)); ?></div>
                    <div class="stat-label">Year Groups</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(108,117,125,0.1); color: var(--dark-gray);"><i class="fas fa-book"></i></div>
                    <div class="stat-number">1</div>
                    <div class="stat-label">Program</div>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Search Students</h3>
                </div>
                <div class="search-box">
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by name or registration number...">
                    <select id="yearFilter" class="filter-select">
                        <option value="">All Years</option>
                        <?php foreach ($year_distribution as $year => $count): ?>
                            <option value="<?php echo safe_display($year); ?>"><?php echo safe_display($year); ?> (<?php echo $count; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select id="repFilter" class="filter-select">
                        <option value="">All Students</option>
                        <option value="rep">Class Representatives</option>
                        <option value="student">Regular Students</option>
                    </select>
                </div>
            </div>

            <!-- Year Distribution -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Academic Year Distribution</h3>
                </div>
                <div class="distribution">
                    <?php foreach ($year_distribution as $year => $count): ?>
                        <div class="distribution-item">
                            <span><strong><?php echo safe_display($year); ?></strong></span>
                            <div class="distribution-bar">
                                <div class="distribution-fill" style="width: <?php echo ($count / $total_students) * 100; ?>%"></div>
                            </div>
                            <span><?php echo $count; ?> students (<?php echo round(($count / $total_students) * 100, 1); ?>%)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Student List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Student Directory</h3>
                    <span class="link" id="studentCount"><?php echo $total_students; ?> students</span>
                </div>
                <div class="student-list" id="studentList">
                    <?php if (empty($class_students)): ?>
                        <div style="text-align: center; color: var(--dark-gray); padding: 2rem;">
                            <i class="fas fa-users-slash" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                            <p>No students found in your class</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($class_students as $student): ?>
                            <div class="student-card" 
                                 data-name="<?php echo strtolower(safe_display($student['full_name'])); ?>"
                                 data-reg="<?php echo strtolower(safe_display($student['reg_number'])); ?>"
                                 data-year="<?php echo safe_display($student['academic_year']); ?>"
                                 data-type="<?php echo $student['is_class_rep'] ? 'rep' : 'student'; ?>">
                                <div class="student-avatar">
                                    <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                </div>
                                <div class="student-info">
                                    <div class="student-name">
                                        <?php echo safe_display($student['full_name']); ?>
                                        <?php if ($student['is_class_rep']): ?>
                                            <span class="class-rep-badge" style="margin-left: 0; font-size: 0.65rem; padding: 0.2rem 0.6rem;">
                                                <i class="fas fa-user-shield"></i> Class Rep
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="student-details">
                                        <span><i class="fas fa-id-card"></i> <?php echo safe_display($student['reg_number']); ?></span>
                                        <span><i class="fas fa-graduation-cap"></i> <?php echo safe_display($student['academic_year']); ?></span>
                                        <span><i class="fas fa-book"></i> <?php echo safe_display($student['program_name']); ?></span>
                                    </div>
                                    <div class="student-contact">
                                        <?php if ($student['email']): ?>
                                            <a href="mailto:<?php echo safe_display($student['email']); ?>">
                                                <i class="fas fa-envelope"></i> <?php echo safe_display($student['email']); ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($student['phone']): ?>
                                            <span style="margin-left: 1rem;">
                                                <i class="fas fa-phone"></i> <?php echo formatPhone($student['phone']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile Menu Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
            });
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Search and filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const yearFilter = document.getElementById('yearFilter');
            const repFilter = document.getElementById('repFilter');
            const studentCards = document.querySelectorAll('.student-card');
            const studentCount = document.getElementById('studentCount');

            function filterStudents() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedYear = yearFilter.value;
                const selectedType = repFilter.value;
                
                let visibleCount = 0;

                studentCards.forEach(card => {
                    const name = card.getAttribute('data-name');
                    const reg = card.getAttribute('data-reg');
                    const year = card.getAttribute('data-year');
                    const type = card.getAttribute('data-type');
                    
                    const matchesSearch = name.includes(searchTerm) || reg.includes(searchTerm);
                    const matchesYear = !selectedYear || year === selectedYear;
                    const matchesType = !selectedType || type === selectedType;
                    
                    if (matchesSearch && matchesYear && matchesType) {
                        card.style.display = 'flex';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                studentCount.textContent = visibleCount + ' students';
            }

            if (searchInput) searchInput.addEventListener('input', filterStudents);
            if (yearFilter) yearFilter.addEventListener('change', filterStudents);
            if (repFilter) repFilter.addEventListener('change', filterStudents);

            // Initial filter to handle any pre-selected filters
            filterStudents();

            // Add loading animations
            const cards = document.querySelectorAll('.stat-card, .card');
            cards.forEach((card, index) => {
                card.style.animation = 'fadeInUp 0.4s ease forwards';
                card.style.animationDelay = `${index * 0.05}s`;
                card.style.opacity = '0';
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
            }, 500);
        });
    </script>
</body>
</html>