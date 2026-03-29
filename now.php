<?php
// samplee.php - Committee Sample Data Collection Tool
// No login required - accessible to everyone

// error_reporting(E_ALL);
// ini_set('display_errors', 1);

require_once 'config/database.php';

// Define available roles
$available_roles = [
    'guild_president' => 'Guild President',
    'vice_guild_academic' => 'Vice Guild President - Academic',
    'vice_guild_finance' => 'Vice Guild President - Finance',
    'general_secretary' => 'General Secretary',
    'minister_sports' => 'Minister of Sports',
    'minister_environment' => 'Minister of Environment',
    'minister_public_relations' => 'Minister of Public Relations',
    'minister_health' => 'Minister of Health',
    'minister_culture' => 'Minister of Culture',
    'minister_gender' => 'Minister of Gender',
    'president_representative_board' => 'President - Representative Board',
    'vice_president_representative_board' => 'Vice President - Representative Board',
    'secretary_representative_board' => 'Secretary - Representative Board',
    'president_arbitration' => 'President - Arbitration Committee',
    'vice_president_arbitration' => 'Vice President - Arbitration Committee',
    'advisor_arbitration' => 'Advisor - Arbitration Committee',
    'secretary_arbitration' => 'Secretary - Arbitration Committee'
];

// Get departments for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Get programs for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM programs WHERE is_active = true ORDER BY name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
}

// Handle form submission for adding sample data
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_sample'])) {
        try {
            $photo_url = null;
            
            // Handle photo upload
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/committee/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = time() . '_' . uniqid() . '.' . $file_extension;
                    $upload_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo_url = 'assets/uploads/committee/' . $file_name;
                    }
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO committee_members (
                    name, reg_number, role, role_order, 
                    department_id, program_id, academic_year, 
                    email, phone, date_of_birth, bio, portfolio_description,
                    photo_url, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            // Get date of birth
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            
            $stmt->execute([
                $_POST['name'] ?? 'N/A',
                $_POST['reg_number'] ?? 'N/A',
                $_POST['role'] ?? 'N/A',
                $_POST['role_order'] ?? 0,
                !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                !empty($_POST['program_id']) ? $_POST['program_id'] : null,
                $_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1),
                $_POST['email'] ?? 'N/A',
                $_POST['phone'] ?? 'N/A',
                $date_of_birth,
                $_POST['bio'] ?? 'N/A',
                $_POST['portfolio_description'] ?? 'N/A',
                $photo_url,
                $_POST['status'] ?? 'active'
            ]);
            
            $message = "Thank you! for submitting your information.";
        } catch (PDOException $e) {
            $error = "Error adding committee member: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['generate_sample'])) {
        $sample_data = [];
        $names = [
            ['John', 'Mwiza'], ['Alice', 'Uwase'], ['Peter', 'Ndayisaba'], 
            ['Grace', 'Mukamana'], ['Jean Claude', 'Habimana'], ['Marie', 'Uwimana'],
            ['Emmanuel', 'Niyonkuru'], ['Claudine', 'Uwase'], ['Olivier', 'Mugisha'],
            ['Diane', 'Uwineza'], ['David', 'Nzeyimana'], ['Rebecca', 'Mukarwego'],
            ['Emmy', 'Ishimwe'], ['James', 'Gakwerere'], ['Marie', 'Chantal']
        ];
        
        $phones = ['0788123456', '0788234567', '0788345678', '0788456789', '0788567890', 
                   '0788678901', '0788789012', '0788890123', '0788901234', '0789012345',
                   '0789123456', '0789234567', '0789345678', '0789456789', '0789567890'];
        
        $birth_dates = [
            '1998-03-15', '1999-07-22', '1998-11-05', '2000-01-18', '1999-05-30',
            '1998-09-12', '2000-02-28', '1999-12-10', '1998-06-25', '2000-08-14',
            '1999-04-03', '1998-10-19', '2000-03-07', '1999-11-21', '1998-07-09'
        ];
        
        $bios = [
            'Experienced student leader passionate about student welfare. Former class representative with 2 years of leadership experience.',
            'Academic excellence award winner. Strong advocate for quality education and student academic rights.',
            'Bachelor of Commerce student with strong financial management skills. Completed internship at Bank of Kigali.',
            'Excellent organizational and communication skills. Previously served as class secretary.',
            'Former captain of college football team. Organized inter-class sports competitions.',
            'Environmental science student passionate about sustainability. Led campus clean-up campaigns.',
            'Social media manager with 3 years experience. Skilled in content creation and public speaking.',
            'Nursing student passionate about health awareness. Certified first aid trainer.',
            'Cultural events organizer. Member of college traditional dance troupe for 2 years.',
            'Gender studies advocate. Participated in women\'s leadership programs.',
            'Former class representative with excellent communication skills. Respected by peers.',
            'Dedicated student leader. Organized successful student forums.',
            'Detail-oriented with strong organizational skills. Excellent record-keeping abilities.',
            'Law student with interest in dispute resolution. Completed mediation training.',
            'Experienced mentor with strong ethical values. Former student leader.'
        ];
        
        $portfolio_descriptions = [
            'Oversee all student union activities and represent students at college management level.',
            'Coordinate academic affairs and innovation programs. Monitor academic performance.',
            'Manage all financial transactions and budget allocations. Prepare financial reports.',
            'Maintain all official records and minutes. Handle correspondence.',
            'Organize sports activities and tournaments. Manage sports facilities.',
            'Coordinate environmental protection initiatives. Organize tree planting programs.',
            'Manage all public communications and media relations. Handle social media platforms.',
            'Coordinate health awareness campaigns. Work with college health services.',
            'Promote cultural activities and organize cultural events. Preserve Rwandan culture.',
            'Address gender-related issues and promote equality. Handle discrimination cases.',
            'Lead class representatives board. Coordinate student feedback from all departments.',
            'Support the president in board activities. Manage class representative meetings.',
            'Maintain records for the representative board. Document meeting minutes.',
            'Lead conflict resolution and disciplinary matters. Ensure fair hearing processes.',
            'Provide guidance on complex arbitration cases. Bring expertise to dispute resolution.'
        ];
        
        $role_keys = array_keys($available_roles);
        
        for ($i = 0; $i < 15 && $i < count($role_keys); $i++) {
            $sample_data[] = [
                'name' => $names[$i][0] . ' ' . $names[$i][1],
                'role' => $role_keys[$i],
                'role_name' => $available_roles[$role_keys[$i]],
                'reg_number' => '2024-' . strtoupper(substr($role_keys[$i], 0, 2)) . sprintf('%03d', $i + 1),
                'email' => strtolower($names[$i][0] . '.' . $names[$i][1] . '@rpsu.rw'),
                'phone' => $phones[$i],
                'date_of_birth' => $birth_dates[$i],
                'academic_year' => date('Y') . '-' . (date('Y') + 1),
                'bio' => $bios[$i],
                'portfolio_description' => $portfolio_descriptions[$i],
                'role_order' => $i + 1,
                'status' => 'active'
            ];
        }
        
        $_SESSION['sample_data'] = $sample_data;
        $message = "Sample data generated successfully! You can now review and add them to the database.";
    }
    
    if (isset($_POST['add_all_samples']) && isset($_SESSION['sample_data'])) {
        $added_count = 0;
        $duplicate_count = 0;
        
        foreach ($_SESSION['sample_data'] as $sample) {
            // Check if member already exists by email
            $check_stmt = $pdo->prepare("SELECT id FROM committee_members WHERE email = ?");
            $check_stmt->execute([$sample['email']]);
            if ($check_stmt->fetch()) {
                $duplicate_count++;
                continue;
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO committee_members (
                        name, reg_number, role, role_order, 
                        email, phone, date_of_birth, academic_year, 
                        bio, portfolio_description, 
                        status, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                    )
                ");
                
                $stmt->execute([
                    $sample['name'],
                    $sample['reg_number'],
                    $sample['role'],
                    $sample['role_order'],
                    $sample['email'],
                    $sample['phone'],
                    $sample['date_of_birth'],
                    $sample['academic_year'],
                    $sample['bio'],
                    $sample['portfolio_description'],
                    $sample['status']
                ]);
                $added_count++;
            } catch (PDOException $e) {
                // Skip on error
            }
        }
        
        unset($_SESSION['sample_data']);
        $message = "Added $added_count new committee members! Duplicates skipped: $duplicate_count";
    }
    
    if (isset($_POST['clear_samples'])) {
        unset($_SESSION['sample_data']);
        $message = "Preview cleared!";
    }
}

// Get existing committee members count
$existing_count = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM committee_members");
    $existing_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    $existing_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Sample Data Collector - Isonga</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0056b3;
            --primary-dark: #004080;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
            --header-bg: #ffffff;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }

        body.dark-mode {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #374151;
            --card-bg: #1f2937;
            --header-bg: #1f2937;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
        }

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
            color: var(--primary);
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
            font-size: 1.1rem;
            color: var(--text-primary);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--text-secondary);
        }

        .stats-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-secondary { background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-color); }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
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

        .alert-info {
            background: #cce5ff;
            color: #004085;
            border: 1px solid #b8daff;
        }

        body.dark-mode .alert-success { background: rgba(16,185,129,0.2); color: var(--success); }
        body.dark-mode .alert-danger { background: rgba(239,68,68,0.2); color: var(--danger); }
        body.dark-mode .alert-info { background: rgba(59,130,246,0.2); color: var(--info); }

        .form-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .form-card h3 {
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.25rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .sample-table-container {
            background: var(--bg-primary);
            border-radius: 8px;
            overflow-x: auto;
            margin: 1rem 0;
        }

        .sample-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .sample-table th,
        .sample-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .sample-table th {
            background: var(--card-bg);
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
            .stats-card { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-area">
                <img src="assets/images/rp_logo.png" alt="RP Musanze" class="logo-img">
                <div class="logo-text">
                    <h1>Committee Data Collector</h1>
                    <p>Sample Data Collection Tool</p>
                    <h1><a href="index.php">back to isonga MIS</a></h1>
                </div>
            </div>
            <!-- <div class="user-area">
                <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>
            </div> -->
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1> Committee Sample Data Collection</h1>
            <!-- <p>Add, generate, and manage committee member data</p> -->
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-card">
            <div>
                <strong>Current Committee Members</strong>
                <div class="stats-number"><?php echo $existing_count; ?></div>
            </div>
            <!-- <div>
                <strong>Recommended Committee Size</strong>
                <div class="stats-number">17</div>
            </div> -->
            <div>
                <strong>Missing Positions</strong>
                <div class="stats-number"><?php echo max(0, 17 - $existing_count); ?></div>
            </div>
        </div>

        <!-- Add Single Member Form -->
        <div class="form-card">
            <h3><i class="fas fa-user-plus"></i> Committee Member</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" placeholder="Enter full name" required>
                    </div>
                    <div class="form-group">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" placeholder="e.g., 20RP00000" required>
                    </div>
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" required>
                            <option value="">Select Role</option>
                            <?php foreach ($available_roles as $key => $name): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <!-- <label>Role Order</label> -->
                        <input type="number" name="role_order" value="0" placeholder="Display order" hidden>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="email@example.com" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" placeholder="Phone number"  required>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" placeholder="YYYY-MM-DD" required>
                    </div>
                    <div class="form-group">
                        <!-- <label>Academic Year</label> -->
                        <input type="text" name="academic_year" placeholder="2025-2026" value="2025-2026" hidden>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" required>
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['id']; ?>"><?php echo htmlspecialchars($prog['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <!-- <label>Bio</label> -->
                        <textarea name="bio" rows="2" value="N/A" hidden></textarea>
                    </div>
                    <div class="form-group">
                        <!-- <label>Portfolio Description</label> -->
                        <textarea name="portfolio_description" rows="2"  value="N/A" hidden></textarea>
                    </div>
                    <div class="form-group">
                        <!-- <label>Profile Photo</label> -->
                        <input type="file" name="photo" accept="image/*" value="N/A" hidden> 
                    </div>
                    <div class="form-group">
                        <!-- <label>Status</label> -->
                        <select name="status" value="N/A" hidden>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="submit" name="add_sample" class="btn btn-primary">
                        <i class="fas fa-save"></i> ADD 
                    </button>
                    <a href="download.php" class="btn btn-success">
    <i class="fas fa-download"></i> Download Excel
</a>
                </div>
            </form>
        </div>

       

        

       
</body>
</html>