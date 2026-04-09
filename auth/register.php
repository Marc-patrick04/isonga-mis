<?php
session_start();
require_once '../config/database.php';

// Initialize variables
$error = '';
$success = '';
$form_data = [];
$redirect_to_login = false;

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // If already logged in, redirect to appropriate dashboard
    if ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } elseif ($_SESSION['role'] === 'student') {
        header('Location: ../student/dashboard.php');
    }
    exit();
}

// Academic year options
$academic_years = ['Year 1', 'Year 2', 'Year 3', 'B-Tech', 'M-Tech'];

// Get departments for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM departments WHERE is_active = true ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    error_log("Error fetching departments: " . $e->getMessage());
}

// Handle Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $reg_number = trim($_POST['reg_number'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
    $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
    $academic_year = trim($_POST['academic_year'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Store form data for repopulation
    $form_data = [
        'reg_number' => $reg_number,
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'department_id' => $department_id,
        'program_id' => $program_id,
        'academic_year' => $academic_year,
        'date_of_birth' => $date_of_birth,
        'gender' => $gender
    ];
    
    // Validation
    $errors = [];
    
    // Required fields
    if (empty($reg_number)) {
        $errors[] = "Registration number is required.";
    }
    if (empty($full_name)) {
        $errors[] = "Full name is required.";
    }
    if (empty($email)) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check if registration number already exists
    if (empty($errors) && !empty($reg_number)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE reg_number = ? AND deleted_at IS NULL");
            $stmt->execute([$reg_number]);
            if ($stmt->fetch()) {
                $errors[] = "Registration number '$reg_number' already exists. Please contact admin if this is an error.";
            }
        } catch (PDOException $e) {
            error_log("Reg number check error: " . $e->getMessage());
        }
    }
    
    // Check if email already exists
    if (empty($errors) && !empty($email)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Email address '$email' is already registered. Please use a different email or login.";
            }
        } catch (PDOException $e) {
            error_log("Email check error: " . $e->getMessage());
        }
    }
    
    // If no errors, create the student account
    if (empty($errors)) {
        try {
            // Generate username from email
            $username = explode('@', $email)[0];
            // Ensure username is unique
            $base_username = $username;
            $counter = 1;
            while (true) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL");
                $stmt->execute([$username]);
                if (!$stmt->fetch()) {
                    break;
                }
                $username = $base_username . $counter;
                $counter++;
            }
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new student
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    reg_number, username, password, role, full_name, email, phone, 
                    date_of_birth, gender, academic_year, department_id, program_id, 
                    status, created_at, email_notifications, sms_notifications, 
                    preferred_language, theme_preference, two_factor_enabled
                ) VALUES (
                    ?, ?, ?, 'student', ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), true, false, 'en', 'light', false
                )
            ");
            
            $stmt->execute([
                $reg_number,
                $username,
                $hashed_password,
                $full_name,
                $email,
                $phone ?: null,
                $date_of_birth ?: null,
                $gender ?: null,
                $academic_year ?: null,
                $department_id,
                $program_id
            ]);
            
            $new_student_id = $pdo->lastInsertId();
            
            // Set success message and flag for redirect
            $success = "Registration successful! Welcome, " . htmlspecialchars($full_name) . "! You will be redirected to the login page in a few seconds.";
            $redirect_to_login = true;
            
            // Note: We don't auto-login anymore - we show success message then redirect to login
            
        } catch (PDOException $e) {
            $error = "Registration failed: " . $e->getMessage();
            error_log("Student registration error: " . $e->getMessage());
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get programs via AJAX endpoint
if (isset($_GET['get_programs']) && isset($_GET['department_id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND is_active = true ORDER BY name");
        $stmt->execute([$_GET['department_id']]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($programs);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Student Registration - Isonga RPSU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--primary-blue) 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
            position: relative;
        }

        .container {
            display: flex;
            width: 100%;
            max-width: 1100px;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            position: relative;
            z-index: 1;
        }

        /* Graphic Side */
        .graphic-side {
            flex: 1;
            background: var(--gradient-secondary);
            color: var(--white);
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .graphic-side::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -100px;
            left: -100px;
        }

        .graphic-side::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            bottom: -50px;
            right: -50px;
        }

        .logo {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            z-index: 2;
        }

        .logo img {
            height: 60px;
            margin-right: 15px;
        }

        .logo h1 {
            font-weight: 700;
            font-size: 28px;
            color: var(--white);
        }

        .graphic-content h2 {
            font-size: 32px;
            margin-bottom: 20px;
            z-index: 2;
            position: relative;
            line-height: 1.2;
        }

        .graphic-content p {
            margin-bottom: 30px;
            line-height: 1.6;
            z-index: 2;
            position: relative;
            font-size: 16px;
        }

        .features {
            list-style: none;
            z-index: 2;
            position: relative;
        }

        .features li {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .features i {
            margin-right: 10px;
            color: var(--warning);
            font-size: 18px;
            min-width: 24px;
        }

        /* Form Side */
        .form-side {
            flex: 2.5;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            max-height: 100vh;
            overflow-y: auto;
        }

        .form-side::-webkit-scrollbar {
            width: 10px;
        }

        .form-side::-webkit-scrollbar-track {
            background: var(--medium-gray);
            border-radius: 3px;
        }

        .form-side::-webkit-scrollbar-thumb {
            background: var(--secondary-blue);
            border-radius: 3px;
        }

        .register-header {
            margin-top: 40px;
        }

        .form-title {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--text-dark);
            font-weight: 700;
        }

        .form-subtitle {
            color: var(--dark-gray);
            margin-bottom: 25px;
            font-size: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .input-group {
            margin-bottom: 0;
            position: relative;
        }

        .input-group.full-width {
            grid-column: span 2;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .input-group label .required {
            color: var(--danger);
        }

        .input-group input,
        .input-group select {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 2px solid var(--medium-gray);
            border-radius: 10px;
            font-size: 14px;
            transition: var(--transition);
            background: var(--white);
        }

        .input-group input:focus,
        .input-group select:focus {
            border-color: var(--secondary-blue);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.2);
        }

        .input-group i.input-icon {
            position: absolute;
            left: 15px;
            top: 42px;
            transform: translateY(-50%);
            color: var(--dark-gray);
            font-size: 14px;
        }

        .input-group .toggle-password {
            position: absolute;
            right: 15px;
            top: 42px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .input-group .toggle-password:hover {
            color: var(--secondary-blue);
        }

        .password-requirements {
            font-size: 11px;
            color: var(--dark-gray);
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .password-requirements i {
            font-size: 10px;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary {
            background: var(--gradient-secondary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--gradient-primary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 136, 229, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
            font-size: 14px;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }

        .help-links {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--medium-gray);
            color: var(--dark-gray);
            font-size: 14px;
        }

        .help-links a {
            color: var(--secondary-blue);
            text-decoration: none;
            font-weight: 600;
        }

        .help-links a:hover {
            text-decoration: underline;
        }

        /* Popup Notification - Enhanced for Success Message */
        .popup-notification {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 1000;
            min-width: 320px;
            max-width: 450px;
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: slideInRight 0.5s ease forwards;
            display: none;
        }

        .popup-notification.show {
            display: block;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }

        .popup-header {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--medium-gray);
        }

        .popup-content {
            padding: 20px;
        }

        .popup-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 16px;
        }

        .popup-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--dark-gray);
            transition: var(--transition);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .popup-close:hover {
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .popup-message {
            line-height: 1.6;
            font-size: 14px;
            color: var(--text-dark);
        }

        .countdown-timer {
            margin-top: 12px;
            font-size: 13px;
            color: var(--secondary-blue);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .countdown-timer i {
            font-size: 14px;
        }

        /* Overlay for success state */
        .overlay-message {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .success-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 400px;
            margin: 20px;
            box-shadow: var(--shadow-lg);
            animation: scaleIn 0.4s ease;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .success-card i {
            font-size: 70px;
            color: var(--success);
            margin-bottom: 20px;
        }

        .success-card h3 {
            font-size: 28px;
            color: var(--text-dark);
            margin-bottom: 15px;
        }

        .success-card p {
            color: var(--dark-gray);
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .redirect-info {
            background: var(--light-gray);
            padding: 12px;
            border-radius: 10px;
            font-size: 14px;
            color: var(--primary-blue);
        }

        .manual-link {
            margin-top: 20px;
        }

        .manual-link a {
            color: var(--secondary-blue);
            text-decoration: none;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .container {
                flex-direction: column;
                max-width: 550px;
            }
            
            .graphic-side {
                padding: 30px;
                min-height: 280px;
            }
            
            .form-side {
                padding: 30px;
                max-height: none;
            }
            
            .graphic-content h2 {
                font-size: 28px;
            }
            
            .logo h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 600px) {
            body {
                padding: 20px;
            }
            
            .container {
                border-radius: 15px;
            }
            
            .graphic-side, .form-side {
                padding: 25px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .input-group.full-width {
                grid-column: span 1;
            }
            
            .form-title {
                font-size: 24px;
            }
            
            .btn {
                padding: 12px;
                font-size: 15px;
            }
            
            .success-card {
                padding: 30px 20px;
                margin: 15px;
            }
            
            .success-card i {
                font-size: 50px;
            }
            
            .success-card h3 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

<?php if ($success && $redirect_to_login): ?>
    <!-- Full screen success overlay with redirect countdown -->
    <div id="successOverlay" class="overlay-message">
        <div class="success-card">
            <i class="fas fa-check-circle"></i>
            <h3>Registration Successful!</h3>
            <p><?php echo htmlspecialchars($success); ?></p>
            <div class="redirect-info">
                <i class="fas fa-clock"></i> 
                Redirecting to <strong>Login Page</strong> in <span id="countdown">5</span> seconds...
            </div>
            <div class="manual-link">
                <a href="student_login.php"><i class="fas fa-sign-in-alt"></i> Click here to login now</a>
            </div>
        </div>
    </div>
    
    <script>
        // Countdown and redirect logic
        let seconds = 5;
        const countdownElement = document.getElementById('countdown');
        
        const countdownInterval = setInterval(function() {
            seconds--;
            if (countdownElement) {
                countdownElement.textContent = seconds;
            }
            if (seconds <= 0) {
                clearInterval(countdownInterval);
                window.location.href = 'student_login.php';
            }
        }, 1000);
        
        // Also allow manual click to override
        setTimeout(function() {
            const manualLink = document.querySelector('.manual-link a');
            if (manualLink) {
                manualLink.addEventListener('click', function(e) {
                    clearInterval(countdownInterval);
                });
            }
        }, 100);
    </script>
<?php elseif ($error): ?>
    <!-- Error Popup -->
    <div id="errorPopup" class="popup-notification show" style="background: rgba(220, 53, 69, 0.1); border-left: 4px solid var(--danger);">
        <div class="popup-header">
            <div class="popup-title" style="color: var(--danger);">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Registration Error</span>
            </div>
            <button class="popup-close" onclick="hidePopup('errorPopup')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="popup-content">
            <div class="popup-message"><?php echo $error; ?></div>
        </div>
    </div>
<?php endif; ?>

<div class="container">
    <!-- Graphic Side -->
    <div class="graphic-side">
        <div class="logo">
            <img src="../assets/images/logo.png" alt="Isonga Platform Logo" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎓</text></svg>'">
            <h1>Isonga</h1>
        </div>
        <div class="graphic-content">
            <h2>Join Isonga RPSU</h2>
            <p>Create your student account to access all the features of the Rwanda Polytechnic Students' Union platform.</p>
            <ul class="features">
                <li><i class="fas fa-tachometer-alt"></i> Personalized student dashboard</li>
                <li><i class="fas fa-ticket-alt"></i> Submit and track your concerns</li>
                <li><i class="fas fa-calendar-alt"></i> View campus events and activities</li>
                <li><i class="fas fa-users"></i> Connect with student representatives</li>
            </ul>
        </div>
    </div>

    <!-- Form Side -->
    <div class="form-side">
        <div class="register-header">
            <h2 class="form-title">Student Registration</h2>
            <p class="form-subtitle">Fill in your details to create an account</p>
        </div>

        <form method="POST" action="" id="registerForm">
            <div class="form-grid">
                <div class="input-group">
                    <label>Registration Number <span class="required">*</span></label>
                    <i class="fas fa-id-card input-icon"></i>
                    <input type="text" name="reg_number" id="reg_number" 
                           value="<?php echo htmlspecialchars($form_data['reg_number'] ?? ''); ?>"
                           placeholder="e.g., 20RP12345" required>
                </div>

                <div class="input-group">
                    <label>Full Name <span class="required">*</span></label>
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" name="full_name" id="full_name" 
                           value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>"
                           placeholder="Full Name" required>
                </div>

                <div class="input-group">
                    <label>Email Address <span class="required">*</span></label>
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" name="email" id="email" 
                           value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                           placeholder="student@example.com" required>
                </div>

                <div class="input-group">
                    <label>Phone Number</label>
                    <i class="fas fa-phone input-icon"></i>
                    <input type="tel" name="phone" id="phone" 
                           value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                           placeholder="e.g., 0788000000">
                </div>

                <div class="input-group">
                    <label>Department</label>
                    <i class="fas fa-building input-icon"></i>
                    <select name="department_id" id="department_id">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" 
                                <?php echo (($form_data['department_id'] ?? '') == $dept['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group">
                    <label>Program of Study</label>
                    <i class="fas fa-graduation-cap input-icon"></i>
                    <select name="program_id" id="program_id">
                        <option value="">Select Program</option>
                    </select>
                </div>

                <div class="input-group">
                    <label>Academic Year</label>
                    <i class="fas fa-calendar-alt input-icon"></i>
                    <select name="academic_year" id="academic_year">
                        <option value="">Select Academic Year</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year; ?>" 
                                <?php echo (($form_data['academic_year'] ?? '') == $year) ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group">
                    <label>Date of Birth</label>
                    <i class="fas fa-birthday-cake input-icon"></i>
                    <input type="date" name="date_of_birth" id="date_of_birth" 
                           value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>">
                </div>

                <div class="input-group">
                    <label>Gender</label>
                    <i class="fas fa-venus-mars input-icon"></i>
                    <select name="gender" id="gender">
                        <option value="">Select Gender</option>
                        <option value="male" <?php echo (($form_data['gender'] ?? '') == 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo (($form_data['gender'] ?? '') == 'female') ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div class="input-group">
                    <label>Password <span class="required">*</span></label>
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="password" id="password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('password')">
                        <i class="fas fa-eye"></i>
                    </button>
                    <div class="password-requirements">
                        <i class="fas fa-info-circle"></i> Minimum 6 characters
                    </div>
                </div>

                <div class="input-group">
                    <label>Confirm Password <span class="required">*</span></label>
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="confirm_password" id="confirm_password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" id="registerBtn">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>

        <div class="help-links">
            <p>Already have an account? <a href="student_login.php">Sign in here</a></p>
            <p>Return to <a href="../index.php">Home Page</a></p>
        </div>
    </div>
</div>

<script>
    // Toggle password visibility
    function togglePassword(fieldId) {
        const passwordInput = document.getElementById(fieldId);
        const icon = passwordInput.parentElement.querySelector('.toggle-password i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // Hide popup notification
    function hidePopup(popupId) {
        const popup = document.getElementById(popupId);
        if (popup) {
            popup.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(() => {
                if (popup && popup.remove) popup.remove();
            }, 300);
        }
    }

    // Auto-hide error popups after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const errorPopup = document.getElementById('errorPopup');
        if (errorPopup) {
            setTimeout(() => {
                hidePopup('errorPopup');
            }, 5000);
        }
    });

    // Load programs based on department selection
    const departmentSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    
    function loadPrograms(departmentId, selectedProgramId = null) {
        if (!departmentId) {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            return;
        }
        
        fetch(`register.php?get_programs=1&department_id=${departmentId}`)
            .then(response => response.json())
            .then(programs => {
                let options = '<option value="">Select Program</option>';
                if (!programs.error && programs.length > 0) {
                    programs.forEach(program => {
                        const selected = selectedProgramId == program.id ? 'selected' : '';
                        options += `<option value="${program.id}" ${selected}>${escapeHtml(program.name)}</option>`;
                    });
                }
                programSelect.innerHTML = options;
            })
            .catch(error => {
                console.error('Error loading programs:', error);
                programSelect.innerHTML = '<option value="">Error loading programs</option>';
            });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Initial load if department is pre-selected
    if (departmentSelect.value) {
        const preselectedProgram = '<?php echo $form_data['program_id'] ?? ''; ?>';
        loadPrograms(departmentSelect.value, preselectedProgram);
    }
    
    departmentSelect.addEventListener('change', function() {
        loadPrograms(this.value);
    });
    
    // Real-time password validation
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    
    function validatePasswords() {
        const password = passwordInput.value;
        const confirm = confirmInput.value;
        
        if (password !== confirm) {
            confirmInput.setCustomValidity('Passwords do not match');
            confirmInput.style.borderColor = '#dc3545';
        } else {
            confirmInput.setCustomValidity('');
            confirmInput.style.borderColor = '';
        }
        
        if (password.length > 0 && password.length < 6) {
            passwordInput.setCustomValidity('Password must be at least 6 characters');
            passwordInput.style.borderColor = '#dc3545';
        } else {
            passwordInput.setCustomValidity('');
            if (passwordInput.style.borderColor === '#dc3545') {
                passwordInput.style.borderColor = '';
            }
        }
    }
    
    passwordInput.addEventListener('input', validatePasswords);
    confirmInput.addEventListener('input', validatePasswords);
    
    // Form submission validation and loading state
    const registerForm = document.getElementById('registerForm');
    const registerBtn = document.getElementById('registerBtn');
    
    registerForm.addEventListener('submit', function(e) {
        const password = passwordInput.value;
        const confirm = confirmInput.value;
        
        if (password !== confirm) {
            e.preventDefault();
            alert('Passwords do not match!');
            return false;
        }
        
        if (password.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long!');
            return false;
        }
        
        // Show loading state
        registerBtn.disabled = true;
        registerBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating account...';
    });
    
    // Real-time email validation
    const emailInput = document.getElementById('email');
    const regNumberInput = document.getElementById('reg_number');
    
    emailInput.addEventListener('blur', function() {
        const email = this.value;
        if (email && !email.includes('@')) {
            this.style.borderColor = '#dc3545';
        } else {
            this.style.borderColor = '';
        }
    });
    
    regNumberInput.addEventListener('blur', function() {
        const reg = this.value;
        if (reg && reg.trim() === '') {
            this.style.borderColor = '#dc3545';
        } else {
            this.style.borderColor = '';
        }
    });

    // Clear error styling when typing
    const inputs = document.querySelectorAll('input, select');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            this.style.borderColor = '';
        });
    });
</script>
</body>
</html>