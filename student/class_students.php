<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student and is class rep
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student' || !($_SESSION['is_class_rep'] ?? 0)) {
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
    setcookie('theme', $new_theme, time() + (86400 * 30), "/");
    header('Location: class_students.php');
    exit();
}

// Get class students (same program and academic year)
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Students - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0056b3; --secondary: #1e88e5; --accent: #0d47a1;
            --light: #f8f9fa; --white: #fff; --gray: #e9ecef; 
            --dark-gray: #6c757d; --text: #2c3e50; --success: #28a745;
            --warning: #ffc107; --danger: #dc3545; --info: #17a2b8;
            --shadow: 0 4px 12px rgba(0,0,0,0.1); --radius: 8px; 
            --transition: all 0.3s ease;
        }
        [data-theme="dark"] {
            --white: #1a1a1a; --light: #2d2d2d; --gray: #3d3d3d;
            --dark-gray: #a0a0a0; --text: #e9ecef;
            --shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--light); color: var(--text); transition: var(--transition); }
        .container { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 1.5rem; position: fixed; width: 250px; height: 100vh; z-index: 1000; }
        .brand { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; }
        .nav-links { list-style: none; }
        .nav-links li { margin-bottom: 0.5rem; }
        .nav-links a { display: flex; align-items: center; gap: 0.8rem; padding: 0.8rem 1rem; color: white; text-decoration: none; border-radius: var(--radius); transition: var(--transition); }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.15); }
        
        /* Main Content */
        .main { grid-column: 2; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; background: var(--white); padding: 1.5rem; border-radius: var(--radius); box-shadow: var(--shadow); }
        .welcome h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .actions { display: flex; gap: 1rem; }
        .btn { padding: 0.8rem 1.5rem; border: none; border-radius: 50px; cursor: pointer; transition: var(--transition); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .btn-primary { background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; }
        .btn-secondary { background: var(--gray); color: var(--text); }
        .icon-btn { background: var(--white); border: 2px solid var(--gray); border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; text-align: center; box-shadow: var(--shadow); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.2rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        .stat-label { font-size: 0.9rem; color: var(--dark-gray); }
        
        /* Cards */
        .card { background: var(--white); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-title { font-size: 1.2rem; font-weight: 600; }
        .link { color: var(--secondary); text-decoration: none; font-size: 0.9rem; }
        
        /* Class Rep Badge */
        .class-rep-badge { background: linear-gradient(135deg, var(--success) 0%, #20c997 100%); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; margin-left: 1rem; }
        
        /* Student List */
        .student-list { display: grid; gap: 1rem; }
        .student-card { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--light); border-radius: var(--radius); transition: var(--transition); }
        .student-card:hover { background: var(--gray); transform: translateY(-2px); }
        .student-avatar { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1.2rem; }
        .student-info { flex: 1; }
        .student-name { font-weight: 600; margin-bottom: 0.3rem; display: flex; align-items: center; gap: 0.5rem; }
        .student-details { display: flex; gap: 1rem; font-size: 0.85rem; color: var(--dark-gray); }
        .student-contact { margin-top: 0.5rem; }
        .student-contact a { color: var(--secondary); text-decoration: none; font-size: 0.8rem; }
        .student-contact a:hover { text-decoration: underline; }
        
        /* Search and Filters */
        .search-box { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
        .search-input { flex: 1; padding: 0.8rem 1rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); }
        .search-input:focus { outline: none; border-color: var(--secondary); }
        .filter-select { padding: 0.8rem 1rem; border: 2px solid var(--gray); border-radius: var(--radius); background: var(--white); color: var(--text); }
        
        /* Distribution Chart */
        .distribution { display: grid; gap: 0.5rem; }
        .distribution-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; background: var(--light); border-radius: var(--radius); }
        .distribution-bar { flex: 1; height: 8px; background: var(--gray); border-radius: 4px; margin: 0 1rem; overflow: hidden; }
        .distribution-fill { height: 100%; background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); border-radius: 4px; }
        
        /* Alert */
        .alert { padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; border-left: 4px solid; }
        .alert-info { background: rgba(23,162,184,0.1); color: var(--info); border-left-color: var(--info); }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); }
            .main { grid-column: 1; padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .student-details { flex-direction: column; gap: 0.3rem; }
            .search-box { flex-direction: column; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="brand">
                <div class="brand-logo"><i class="fas fa-user-shield"></i></div>
                <div class="brand-text"><h1>Class Rep Panel</h1></div>
            </div>
            <ul class="nav-links">
                <li><a href="class_rep_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></li>
                <li><a href="class_tickets.php"><i class="fas fa-ticket-alt"></i> Class Tickets</a></li>
                <li><a href="#" class="active"><i class="fas fa-users"></i> Class Students</a></li>
                <li><a href="rep_meetings.php"><i class="fas fa-calendar-alt"></i> Meetings</a></li>
                <li><a href="rep_reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
                    <form method="POST">
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
                    <div class="stat-number"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(40,167,69,0.1); color: var(--success);"><i class="fas fa-user-shield"></i></div>
                    <div class="stat-number"><?php echo $class_reps_count; ?></div>
                    <div class="stat-label">Class Representatives</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255,193,7,0.1); color: var(--warning);"><i class="fas fa-graduation-cap"></i></div>
                    <div class="stat-number"><?php echo count($year_distribution); ?></div>
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
                            <span><?php echo safe_display($year); ?></span>
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
                        <p style="text-align: center; color: var(--dark-gray); padding: 2rem;">No students found in your class</p>
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
                                            <span class="class-rep-badge" style="margin-left: 0; font-size: 0.7rem; padding: 0.2rem 0.6rem;">
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

            searchInput.addEventListener('input', filterStudents);
            yearFilter.addEventListener('change', filterStudents);
            repFilter.addEventListener('change', filterStudents);

            // Initial filter to handle any pre-selected filters
            filterStudents();
        });
    </script>
</body>
</html>